<?php

namespace Washi;

/**
 * .env loader + typed access to environment variables.
 *
 * - `Env::load($file)` parses KEY=VALUE lines (quotes and `#` comments supported) into $_ENV and putenv().
 *   Existing real environment variables win over the file (12-factor friendly).
 * - `Env::get('APP_DEBUG', default)` reads $_ENV then getenv().
 * There is no ini/config layer on purpose: configuration is env vars plus the array passed to `new App()`.
 */
class Env
{
    public static function load(string $file): void
    {
        if (!is_readable($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            if (str_starts_with($line, 'export ')) $line = substr($line, 7);
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '' || getenv($key) !== false) continue;
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $q = $value[0];
                $end = strrpos($value, $q);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                if ($q === '"') $value = str_replace(['\\n', '\\t', '\\"'], ["\n", "\t", '"'], $value);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === null) ? $default : (string)$v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : Cast::asBool($v, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return Cast::asInt(self::get($key), $default);
    }
}
