<?php

namespace Washi\Middleware;

use Washi\App;
use Washi\Auth;
use Washi\Request;
use Washi\Response;

/** Only for guests (login form etc.). Authenticated users are redirected. */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class RequireGuest implements Middleware
{
    public function __construct(public string $redirect = '/') {}

    public function __invoke(Request $request, \Closure $next): mixed
    {
        return Auth::check() ? Response::redirect(App::current()->url($this->redirect)) : $next($request);
    }
}
