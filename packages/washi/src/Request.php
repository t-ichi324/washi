<?php

namespace Washi;

/**
 * The current HTTP request as a value object (built once from PHP globals by App).
 *
 * Input access is unified: GET + POST + JSON body, in that precedence, via typed getters:
 *   $r->input('name')  mixed          $r->str('name')   string (trimmed, arrays collapsed)
 *   $r->int('id')      int            $r->float('x')    float
 *   $r->bool('flag')   bool           $r->arr('tags')   array
 *   $r->all()          array          $r->only([...])   array   $r->file('upload') ?array
 * Handlers receive it as the first argument when they declare a `Request` parameter;
 * page code can also call the `request()` helper.
 */
class Request
{
    private ?array $inputs = null;
    private array $attributes = [];

    public function __construct(
        public readonly string $method,
        /** Path relative to the front controller, always starting with '/', no query string. */
        public readonly string $path,
        /** URL prefix when the app lives in a sub-directory ('' at web root). */
        public readonly string $basePath,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $files,
        public readonly array $server,
        private readonly ?string $rawBody = null,
    ) {}

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $scriptName = $server['SCRIPT_NAME'] ?? '/index.php';
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $uri = $server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        if ($basePath !== '' && str_starts_with($path, $basePath)) $path = substr($path, strlen($basePath));
        if ($path !== '' && str_starts_with($path, '/index.php')) $path = substr($path, 10);
        $path = '/' . trim(rawurldecode($path), '/');

        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string)$_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) $method = $override;
        }
        return new self($method, $path, $basePath, $_GET, $_POST, $_COOKIE, $_FILES, $server);
    }

    /** For tests / CLI: build a request by hand. */
    public static function create(string $method, string $path, array $query = [], array $post = [], array $server = [], ?string $body = null): self
    {
        $url = parse_url($path);
        if (!empty($url['query'])) { parse_str($url['query'], $q); $query = $q + $query; }
        return new self(strtoupper($method), '/' . trim($url['path'] ?? '/', '/'), '', $query, $post, [], [], $server + ['REQUEST_METHOD' => $method], $body);
    }

    // ── path ─────────────────────────────────────────────────────────────

    /** @return string[] path split on '/', empty for the root */
    public function segments(): array
    {
        return $this->path === '/' ? [] : explode('/', ltrim($this->path, '/'));
    }

    public function is(string $method): bool { return $this->method === strtoupper($method); }
    public function isGet(): bool  { return $this->method === 'GET' || $this->method === 'HEAD'; }
    public function isPost(): bool { return $this->method === 'POST'; }
    public function isWrite(): bool { return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true); }

    // ── inputs ───────────────────────────────────────────────────────────

    public function all(): array
    {
        if ($this->inputs === null) {
            $json = [];
            if ($this->isJson()) {
                $raw = $this->rawBody ?? (@file_get_contents('php://input') ?: '');
                $decoded = $raw !== '' ? json_decode($raw, true) : null;
                if (is_array($decoded)) $json = $decoded;
            }
            $this->inputs = array_merge($this->query, $this->post, $json);
        }
        return $this->inputs;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function str(string $key, string $default = ''): string
    {
        $v = Cast::ensureScalar($this->input($key));
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public function int(string $key, int $default = 0): int     { return Cast::asInt($this->input($key), $default); }
    public function float(string $key, float $default = 0.0): float { return Cast::asFloat($this->input($key), $default); }
    public function bool(string $key, bool $default = false): bool  { return Cast::asBool($this->input($key), $default); }
    public function arr(string $key, array $default = []): array    { return Cast::asArray($this->input($key), $default); }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) ? $f : null;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        return isset($this->cookies[$key]) ? (string)$this->cookies[$key] : $default;
    }

    // ── headers / meta ───────────────────────────────────────────────────

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) return (string)$this->server[$key];
        $plain = strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$plain]) ? (string)$this->server[$plain] : $default;
    }

    public function isJson(): bool     { return str_contains((string)($this->server['CONTENT_TYPE'] ?? ''), 'application/json'); }
    public function isAjax(): bool     { return strtolower((string)($this->server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'; }
    public function wantsJson(): bool  { return str_contains((string)($this->server['HTTP_ACCEPT'] ?? ''), 'application/json') || $this->isJson(); }
    public function isHttps(): bool
    {
        $h = $this->server['HTTPS'] ?? '';
        return ($h !== '' && $h !== 'off') || strtolower((string)($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function host(): string { return (string)($this->server['HTTP_HOST'] ?? 'localhost'); }
    public function referer(): ?string { return $this->server['HTTP_REFERER'] ?? null; }
    public function userAgent(): string { return (string)($this->server['HTTP_USER_AGENT'] ?? ''); }

    /**
     * Client IP. X-Forwarded-For is honoured only when REMOTE_ADDR is in $trustedProxies
     * (comma list, or '*' to trust any). Defaults to REMOTE_ADDR.
     */
    public function ip(string $trustedProxies = ''): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '');
        if ($trustedProxies === '') return $remote;
        $list = array_map('trim', explode(',', $trustedProxies));
        if ($trustedProxies !== '*' && !in_array($remote, $list, true)) return $remote;
        if (!empty($this->server['HTTP_CF_CONNECTING_IP'])) return (string)$this->server['HTTP_CF_CONNECTING_IP'];
        $xff = (string)($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') return $remote;
        foreach (array_reverse(array_map('trim', explode(',', $xff))) as $ip) {
            if ($trustedProxies === '*' || !in_array($ip, $list, true)) return $ip;
        }
        return $remote;
    }

    /** Absolute URL of the current request (scheme + host + basePath + path + query) */
    public function url(bool $withQuery = true): string
    {
        $u = ($this->isHttps() ? 'https' : 'http') . '://' . $this->host() . $this->basePath . $this->path;
        $qs = (string)($this->server['QUERY_STRING'] ?? '');
        return ($withQuery && $qs !== '') ? "$u?$qs" : $u;
    }

    // ── per-request attributes (middleware → handler hand-off) ──────────

    public function with(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
