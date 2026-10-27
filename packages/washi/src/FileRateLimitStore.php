<?php

namespace Washi;

/**
 * RateLimitStore のファイル版（RateLimiter の既定）。1 キー 1 ファイル、中身は "回数 期限"。
 * flock で排他するので同じサーバー内の同時アクセスは正しく数える。複数台・再起動で消えるコンテナでは共有されない。
 * ファイルの mtime を期限にしておき、ときどき期限切れのファイルを掃除する。
 */
final class FileRateLimitStore implements RateLimitStore
{
    public function __construct(private string $dir) {}

    public function increment(string $key, int $decaySeconds): array
    {
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
        $path = $this->path($key);
        $fp = fopen($path, 'c+');
        if ($fp === false) throw new \RuntimeException("RateLimiter: cannot open {$path}");
        try {
            flock($fp, LOCK_EX);
            $now = time();
            [$count, $resetAt] = self::parse((string)stream_get_contents($fp));
            if ($resetAt <= $now) [$count, $resetAt] = [0, $now + max(1, $decaySeconds)];
            $count++;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, "$count $resetAt");
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
        @touch($path, $resetAt);
        if (random_int(1, 100) === 1) $this->gc();
        return [$count, $resetAt];
    }

    public function peek(string $key): array
    {
        $raw = @file_get_contents($this->path($key));
        [$count, $resetAt] = self::parse($raw === false ? '' : $raw);
        return $resetAt > time() ? [$count, $resetAt] : [0, 0];
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    /** 期限切れのファイルを消す（mtime = 期限） */
    public function gc(): void
    {
        $now = time();
        foreach (glob($this->dir . '/*.rl') ?: [] as $f) {
            if (@filemtime($f) < $now) @unlink($f);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.rl';
    }

    /** @return array{0: int, 1: int} */
    private static function parse(string $raw): array
    {
        $p = explode(' ', trim($raw));
        return count($p) === 2 ? [(int)$p[0], (int)$p[1]] : [0, 0];
    }
}
