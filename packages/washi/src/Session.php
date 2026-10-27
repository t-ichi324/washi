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
    private static int $idle = 0;
    private static int $lifetime = 0;
    private static bool $expired = false;

    /** $idle / $lifetime は秒（0 で無効）。idle = 最後の操作から、lifetime = ログイン（ID 再生成）から */
    public static function configure(string $name, int $idle = 0, int $lifetime = 0): void
    {
        self::$name = $name;
        self::$idle = max(0, $idle);
        self::$lifetime = max(0, $lifetime);
    }

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') return;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name(self::$name);
            session_set_cookie_params(['lifetime' => 0] + self::cookieOptions());
            // strict: 未知の ID（攻撃者が自分で決めた値など）は受け入れず新しい ID を発行する＝セッション固定対策
            $opts = ['use_strict_mode' => 1];
            // PHP の GC が期限より先にファイルを消さないよう、保持期間を期限以上にしておく
            if ($keep = max(self::$idle, self::$lifetime)) $opts['gc_maxlifetime'] = max($keep, (int)ini_get('session.gc_maxlifetime'));
            session_start($opts);
        }
        self::$started = true;
        self::enforceTimeouts();
        // flash: 前回リクエストで積まれた分を今回用に取り出し、次回には消える状態にする
        self::$pulled = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
    }

    /**
     * Washi が発行する cookie 共通の属性。サブディレクトリ設置なら basePath に限定し、HTTPS なら Secure を付ける
     * （同じホストの別アプリへ送らない・平文で流さない）。
     */
    public static function cookieOptions(): array
    {
        try {
            $r = App::current()->request();
            return ['path' => $r->basePath . '/', 'secure' => $r->isHttps(), 'httponly' => true, 'samesite' => 'Lax'];
        } catch (\LogicException) {
            return ['path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
        }
    }

    /** 期限切れなら中身を捨てて新しい ID にする（＝ログアウト扱い）。期限内なら最終操作時刻を更新 */
    private static function enforceTimeouts(): void
    {
        if (!self::$idle && !self::$lifetime) return;
        $now = time();
        $last = $_SESSION['_last'] ?? null;
        $born = $_SESSION['_born'] ?? null;
        if ((self::$idle && $last !== null && $now - $last > self::$idle)
            || (self::$lifetime && $born !== null && $now - $born > self::$lifetime)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            self::$expired = true;
        }
        $_SESSION['_born'] ??= $now;
        $_SESSION['_last'] = $now;
    }

    /** このリクエストで期限切れによりセッションが破棄されたか（「時間切れのため再ログインしてください」の表示用） */
    public static function expired(): bool
    {
        self::start();
        return self::$expired;
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
        if (self::$lifetime) $_SESSION['_born'] = time();   // 絶対期限はログイン・ログアウトの時点から数え直す
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
