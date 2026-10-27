<?php

namespace Washi;

/**
 * RateLimiter の保存先。既定は FileRateLimitStore（storage/cache/throttle/、サーバー 1 台向け）。
 * 複数台で共有したいなどで DB や Redis に置くなら、これを実装して `RateLimiter::store(new MyStore)` で差し替える。
 *
 * 期間（window）は最初の hit から $decaySeconds。期限を過ぎたキーは 0 回からやり直す。
 */
interface RateLimitStore
{
    /**
     * 1 回数えて [今の回数, 期限の UNIX 時刻] を返す。期限切れか未登録なら 1 回目として期限を now + $decaySeconds にする。
     * 同時アクセスで取りこぼさないよう、読み書きはまとめて排他すること
     * @return array{0: int, 1: int}
     */
    public function increment(string $key, int $decaySeconds): array;

    /**
     * 数えずに [今の回数, 期限の UNIX 時刻] を返す。未登録か期限切れなら [0, 0]
     * @return array{0: int, 1: int}
     */
    public function peek(string $key): array;

    public function forget(string $key): void;
}
