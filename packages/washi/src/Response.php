<?php

namespace Washi;

/**
 * HTTP response value object. Handlers may return one of these, or any plain value
 * that App::normalize() converts: string → HTML, array/object → JSON, null → 204.
 *
 *   Response::html('<p>hi</p>')            Response::json($data, 201)
 *   Response::redirect('/path')            Response::text('plain')
 *   Response::file('/abs/path', 'name.pdf') Response::download($content, 'name.csv')
 *   Response::empty(204)
 * Chain `->status(404)->header('X-Foo', 'bar')`.
 */
class Response
{
    /** @var array<string,string> */
    private array $headers = [];
    private ?string $filePath = null;
    private bool $deleteFileAfterSend = false;
    /** @var list<array{0:string,1:string,2:array}> */
    private array $pendingCookies = [];

    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $k => $v) $this->header($k, $v);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200, int $flags = 0): self
    {
        $json = json_encode($data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        return new self($json, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public static function empty(int $status = 204): self
    {
        return new self('', $status);
    }

    /** Stream an existing file (Content-Type sniffed; attachment when $downloadName given) */
    public static function file(string $path, ?string $downloadName = null, bool $deleteAfterSend = false): self
    {
        if (!is_file($path)) throw new HttpException(404, 'File not found');
        $r = new self('', 200, ['Content-Type' => self::mimeOf($path), 'Content-Length' => (string)filesize($path)]);
        $r->filePath = $path;
        $r->deleteFileAfterSend = $deleteAfterSend;
        if ($downloadName !== null) $r->attachment($downloadName);
        return $r;
    }

    /** Send in-memory content as a download */
    public static function download(string $content, string $filename, ?string $mime = null): self
    {
        return (new self($content, 200, ['Content-Type' => $mime ?? self::mimeByExt($filename)]))->attachment($filename);
    }

    // ── fluent ───────────────────────────────────────────────────────────

    public function status(int $status): static { $this->status = $status; return $this; }
    public function header(string $name, string $value): static { $this->headers[$name] = $value; return $this; }
    public function withoutHeader(string $name): static { unset($this->headers[$name]); return $this; }
    public function body(string $body): static { $this->body = $body; return $this; }
    public function attachment(string $filename): static
    {
        return $this->header('Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode($filename));
    }
    public function cookie(string $name, string $value, int $ttlSeconds = 0, array $options = []): static
    {
        $opts = ['path' => '/', 'httponly' => true, 'samesite' => 'Lax'] + $options;
        if ($ttlSeconds > 0) $opts['expires'] = time() + $ttlSeconds;
        $this->pendingCookies[] = [$name, $value, $opts];
        return $this;
    }

    public function getStatus(): int { return $this->status; }
    public function getBody(): string { return $this->body; }
    public function getHeaders(): array { return $this->headers; }
    public function getHeader(string $name): ?string { return $this->headers[$name] ?? null; }
    public function isRedirect(): bool { return isset($this->headers['Location']); }

    /** Write status, headers and body to the SAPI. */
    public function send(): void
    {
        Session::close();
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) header("$k: $v");
            foreach ($this->pendingCookies as [$n, $v, $o]) setcookie($n, $v, $o);
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            if ($this->deleteFileAfterSend) @unlink($this->filePath);
            return;
        }
        echo $this->body;
    }

    public static function mimeOf(string $path): string
    {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $m = $f ? finfo_file($f, $path) : false;
            if ($f) finfo_close($f);
            if ($m) return $m;
        }
        return self::mimeByExt($path);
    }

    public static function mimeByExt(string $filename): string
    {
        static $map = [
            'txt' => 'text/plain', 'csv' => 'text/csv', 'pdf' => 'application/pdf', 'html' => 'text/html', 'htm' => 'text/html',
            'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json', 'xml' => 'application/xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
            'webp' => 'image/webp', 'ico' => 'image/x-icon', 'zip' => 'application/zip', 'gz' => 'application/gzip',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        ];
        return $map[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
