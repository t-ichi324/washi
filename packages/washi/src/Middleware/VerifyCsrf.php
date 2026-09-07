<?php

namespace Washi\Middleware;

use Washi\Request;
use Washi\Security;

/** Verifies the CSRF token on POST/PUT/PATCH/DELETE (field `_token` or header X-CSRF-TOKEN). GET passes through. */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class VerifyCsrf implements Middleware
{
    public function __invoke(Request $request, \Closure $next): mixed
    {
        if ($request->isWrite()) Security::verifyCsrf($request);
        return $next($request);
    }
}
