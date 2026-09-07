<?php

namespace Washi\Middleware;

use Washi\App;
use Washi\Auth;
use Washi\Request;
use Washi\Response;

/** Redirects guests to the login page (or 401 JSON for API clients). */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class RequireAuth implements Middleware
{
    public function __construct(public ?string $redirect = null) {}

    public function __invoke(Request $request, \Closure $next): mixed
    {
        if (Auth::check()) return $next($request);
        if ($request->wantsJson()) return Response::json(['error' => 'Unauthorized'], 401);
        $to = $this->redirect ?? App::current()->config('login_page');
        return Response::redirect(App::current()->url($to));
    }
}
