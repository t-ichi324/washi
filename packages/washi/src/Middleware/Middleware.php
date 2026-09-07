<?php

namespace Washi\Middleware;

use Washi\Request;

/**
 * A middleware is any callable `fn(Request $request, Closure $next): mixed`.
 * Return `$next($request)` to continue, or return a value (Response, string, array…) to short-circuit.
 *
 * Classes implementing this interface may also be declared as PHP attributes on a handler closure:
 *     return #[RequireAuth] fn() => view('secret');
 * Handler collects such attributes automatically, so both styles are equivalent to `->use(new RequireAuth)`.
 */
interface Middleware
{
    public function __invoke(Request $request, \Closure $next): mixed;
}
