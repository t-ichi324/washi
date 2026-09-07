<?php

namespace Washi;

/**
 * Session-based authentication. Stores whatever object/array you pass to `login()`.
 *
 * - `Auth::login($user)` / `logout()` / `check()` / `user()` / `id()`
 * - Roles: read from `$user->roles` (array) or `$user->role` (string). `hasRole('admin')`.
 * - Remember-me is opt-in: `Auth::remember(fn(string $token) => User|null)` + `login($user, remember: true)`.
 *   Storing the token on the user is the app's job (use `Auth::onLogin`).
 */
class Auth
{
    private static mixed $user = null;
    private static bool $loaded = false;
    /** @var null|callable(string): (object|array|null) */
    private static $rememberResolver = null;
    /** @var null|callable(mixed $user, ?string $token): void */
    private static $onLogin = null;
    private static string $cookie = 'washi_remember';

    public static function remember(callable $resolver, string $cookieName = 'washi_remember'): void
    {
        self::$rememberResolver = $resolver;
        self::$cookie = $cookieName;
    }

    public static function onLogin(callable $cb): void
    {
        self::$onLogin = $cb;
    }

    public static function login(object|array $user, bool $remember = false): void
    {
        self::$user = $user;
        self::$loaded = true;
        Session::set('_auth', $user);
        Session::regenerate();
        $token = null;
        if ($remember) {
            $token = Security::randomToken(32);
            setcookie(self::$cookie, $token, ['expires' => time() + 86400 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        }
        if (self::$onLogin) (self::$onLogin)($user, $token);
    }

    public static function logout(): void
    {
        self::$user = null;
        self::$loaded = true;
        Session::remove('_auth');
        if (isset($_COOKIE[self::$cookie])) setcookie(self::$cookie, '', ['expires' => 1, 'path' => '/']);
        Session::regenerate();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): object|array|null
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        self::$user = Session::get('_auth');
        if (self::$user === null && self::$rememberResolver && !empty($_COOKIE[self::$cookie])) {
            $restored = (self::$rememberResolver)($_COOKIE[self::$cookie]);
            if ($restored) {
                self::$user = $restored;
                Session::set('_auth', $restored);
            } else {
                setcookie(self::$cookie, '', ['expires' => 1, 'path' => '/']);
            }
        }
        return self::$user;
    }

    public static function id(): int|string|null
    {
        $u = self::user();
        return is_array($u) ? ($u['id'] ?? null) : ($u?->id ?? null);
    }

    /** @return string[] */
    public static function roles(): array
    {
        $u = self::user();
        if ($u === null) return [];
        $roles = is_array($u) ? ($u['roles'] ?? $u['role'] ?? []) : ($u->roles ?? $u->role ?? []);
        if (is_string($roles)) $roles = preg_split('/[,\s]+/', $roles, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_map('strval', (array)$roles));
    }

    /** いずれかのロールを持っていれば true */
    public static function hasRole(string|array $roles): bool
    {
        $mine = self::roles();
        foreach ((array)$roles as $r) if (in_array((string)$r, $mine, true)) return true;
        return false;
    }
}
