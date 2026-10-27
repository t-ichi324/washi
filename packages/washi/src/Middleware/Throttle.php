<?php

namespace Washi\Middleware;

use Washi\App;
use Washi\HttpException;
use Washi\RateLimiter;
use Washi\Request;

/**
 * 同じ送信元から同じ URL（メソッド + パス）へのリクエストを、$decaySeconds 秒に $max 回までにする。超えたら 429 + Retry-After。
 * `by` に入力名を渡すとその値もキーに含める（`by: 'user'` でアカウント + IP ごと）。成否に関係なく 1 リクエスト 1 回と数える。
 * 失敗だけ数えたいなら RateLimiter を直接使う。
 *
 *     return page()->guest()->csrf()->throttle(5, 900, by: 'user')->then(...);
 *     return #[Throttle(60, 60)] fn() => [...];
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class Throttle implements Middleware
{
    public function __construct(public int $max = 60, public int $decaySeconds = 60, public ?string $by = null) {}

    public function __invoke(Request $request, \Closure $next): mixed
    {
        $key = 'throttle|' . $request->method . ' ' . $request->path
            . '|' . $request->ip((string)App::current()->config('trusted_proxies', ''));
        if ($this->by !== null) $key .= '|' . mb_strtolower($request->str($this->by));
        if (RateLimiter::hit($key, $this->decaySeconds) > $this->max) {
            throw new HttpException(429, '', headers: ['Retry-After' => (string)max(1, RateLimiter::availableIn($key))]);
        }
        return $next($request);
    }
}
