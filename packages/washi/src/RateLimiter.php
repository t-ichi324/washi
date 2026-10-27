<?php

namespace Washi;

/**
 * 回数制限（ログイン試行・問い合わせフォーム・API など）。キーごとに「期間内に何回」を数える。
 *
 *     return page()->throttle(5, 900, by: 'user')->then(...);   // 15 分に 5 回を超えたら 429（Middleware\Throttle）
 *
 * 失敗だけ数えたいときは直接使う:
 *     $key = 'login:' . $r->str('user') . '|' . client_ip();
 *     if (RateLimiter::tooMany($key, 5)) return back(['user' => '試行回数が多すぎます。' . ceil(RateLimiter::availableIn($key) / 60) . '分後にお試しください。']);
 *     if (!$ok) { RateLimiter::hit($key, 900); return back([...]); }
 *     RateLimiter::clear($key);
 *
 * 保存先は既定で storage/cache/throttle/ のファイル。DB などに置くなら RateLimitStore を実装して store() で差し替える。
 */
final class RateLimiter
{
    private static ?RateLimitStore $store = null;

    /** 保存先を差し替える（bootstrap で 1 回）。引数なしなら今の保存先を返す */
    public static function store(?RateLimitStore $store = null): RateLimitStore
    {
        if ($store !== null) return self::$store = $store;
        if (self::$store === null) {
            $app = App::current();
            self::$store = new FileRateLimitStore($app->path((string)$app->config('storage', 'storage'), 'cache', 'throttle'));
        }
        return self::$store;
    }

    /** 1 回数えて、期間内の回数を返す。期間は最初の hit から $decaySeconds */
    public static function hit(string $key, int $decaySeconds = 60): int
    {
        return self::store()->increment($key, $decaySeconds)[0];
    }

    /** 期間内にすでに $max 回以上か（数えない） */
    public static function tooMany(string $key, int $max): bool
    {
        return self::store()->peek($key)[0] >= $max;
    }

    /** あと何回できるか */
    public static function remaining(string $key, int $max): int
    {
        return max(0, $max - self::store()->peek($key)[0]);
    }

    /** 期間が終わる（回数が 0 に戻る）までの秒数。数えていなければ 0 */
    public static function availableIn(string $key): int
    {
        $resetAt = self::store()->peek($key)[1];
        return $resetAt > 0 ? max(0, $resetAt - time()) : 0;
    }

    public static function clear(string $key): void
    {
        self::store()->forget($key);
    }
}
