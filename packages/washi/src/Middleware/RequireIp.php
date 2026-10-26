<?php

namespace Washi\Middleware;

use Washi\App;
use Washi\HttpException;
use Washi\IpAllowlist;
use Washi\Request;

/**
 * Allows only client IPs listed in the allowlist file (see IpAllowlist); otherwise 403.
 * `$file` は root 相対か絶対。省略時は config `ip_allowlist`（既定 whitelist.txt）。一覧が空なら素通し。
 *
 *     // pages/admin/_access.php
 *     return page()->ipAllowlist()->auth()->roles('admin');
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class RequireIp implements Middleware
{
    public function __construct(public ?string $file = null) {}

    public function __invoke(Request $request, \Closure $next): mixed
    {
        $ip = $request->ip((string)App::current()->config('trusted_proxies', ''));
        if (!IpAllowlist::forApp($this->file)->allows($ip)) throw new HttpException(403);
        return $next($request);
    }
}
