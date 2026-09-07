<?php

namespace Washi;

/**
 * CSRF token (session-bound, one per session) and password/random helpers.
 */
class Security
{
    private static string $tokenName = '_token';
    private static string $headerName = 'X-CSRF-TOKEN';

    public static function configure(string $tokenName, string $headerName = 'X-CSRF-TOKEN'): void
    {
        self::$tokenName = $tokenName;
        self::$headerName = $headerName;
    }

    public static function csrfTokenName(): string { return self::$tokenName; }

    public static function csrfToken(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . htmlspecialchars(self::$tokenName, ENT_QUOTES) . '" value="' . self::csrfToken() . '">';
    }

    /** @throws HttpException 419 when the token is missing or wrong */
    public static function verifyCsrf(Request $request): void
    {
        $sent = $request->str(self::$tokenName) ?: (string)($request->header(self::$headerName) ?? '');
        $real = Session::get('_csrf');
        if ($sent === '' || !is_string($real) || !hash_equals($real, $sent)) {
            throw new HttpException(419, 'CSRF token mismatch.');
        }
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
