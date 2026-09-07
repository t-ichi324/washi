<?php

namespace Washi\Middleware;

use Washi\Auth;
use Washi\HttpException;
use Washi\Request;

/** Requires an authenticated user holding at least one of the given roles; otherwise 403 (401 if guest). */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class RequireRole implements Middleware
{
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = $roles;
    }

    public function __invoke(Request $request, \Closure $next): mixed
    {
        if (!Auth::check()) return (new RequireAuth())($request, $next);
        if (!Auth::hasRole($this->roles)) throw new HttpException(403);
        return $next($request);
    }
}
