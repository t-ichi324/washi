<?php

namespace Washi;

/**
 * Native PHP session, started lazily on first access, plus flash storage.
 *
 * Flash data lives for exactly one following request. Conventions used by the helpers:
 *   _flash.messages : list of ['type' => 'success|error|info|warning', 'text' => '...']
 *   _flash.errors   : field => message (validation errors, see `err()` helper)
 *   _flash.old      : previous input (see `old()` helper)
 */
class Session
{
    private static bool $started = false;
    private static string $name = 'washi_sid';
    private static ?array $pulled = null;

    public static function configure(string $name): void
    {
        self::$name = $name;
    }

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') return;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name(self::$name);
            session_set_cookie_params([
                'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            ]);
            session_start();
        }
        self::$started = true;
        // flash: 前回リクエストで積まれた分を今回用に取り出し、次回には消える状態にする
        self::$pulled = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
    }

    public static function isActive(): bool
    {
        return self::$started || session_status() === PHP_SESSION_ACTIVE;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove(string ...$keys): void
    {
        self::start();
        foreach ($keys as $k) unset($_SESSION[$k]);
    }

    public static function regenerate(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        self::$started = false;
    }

    /** Called by App before sending headers so the session lock is released early. */
    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }

    // ── flash ────────────────────────────────────────────────────────────

    /** 次のリクエストで一度だけ読める値を積む */
    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }

    /** 次のリクエスト向けの flash 配列に要素を追加（メッセージの積み上げ用） */
    public static function flashPush(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash'][$key][] = $value;
    }

    /** 前回リクエストで積まれた flash 値を読む（今回限り） */
    public static function pull(string $key, mixed $default = null): mixed
    {
        self::start();
        return self::$pulled[$key] ?? $default;
    }

    /** 今回読める flash を次回にも持ち越す（リダイレクトを挟むとき用） */
    public static function keepFlash(): void
    {
        self::start();
        foreach (self::$pulled ?? [] as $k => $v) $_SESSION['_flash'][$k] = $v;
    }
}
