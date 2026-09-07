<?php

namespace Washi;

use Washi\Middleware\Middleware;
use Washi\Middleware\RequireAuth;
use Washi\Middleware\RequireGuest;
use Washi\Middleware\RequireRole;
use Washi\Middleware\VerifyCsrf;

/**
 * A handler = middleware chain + the action closure. This is the unit both routing styles produce:
 *
 *   $app->get('/x', fn() => ...)->auth()->csrf();            // explicit route
 *   return auth()->csrf()->then(fn(int $id) => ...);          // pages/x.php
 *   return #[RequireAuth] fn() => ...;                        // attribute style (collected automatically)
 *
 * Action arguments are resolved from the route params: by name when the closure parameter name matches a
 * `{name}` placeholder, otherwise positionally. `int`/`float` params are validated (404 on mismatch);
 * a `Request` typed parameter receives the current request.
 */
class Handler
{
    /** @var list<callable> */
    private array $middleware = [];
    private ?\Closure $action = null;

    public static function make(?callable $action = null): self
    {
        $h = new self();
        if ($action !== null) $h->then($action);
        return $h;
    }

    public function then(callable $action): static
    {
        $this->action = $action instanceof \Closure ? $action : \Closure::fromCallable($action);
        foreach ((new \ReflectionFunction($this->action))->getAttributes(Middleware::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $this->middleware[] = $attr->newInstance();
        }
        return $this;
    }

    public function hasAction(): bool { return $this->action !== null; }

    /** Add any `fn(Request, Closure $next)` callable */
    public function use(callable ...$middleware): static
    {
        foreach ($middleware as $m) $this->middleware[] = $m;
        return $this;
    }

    public function auth(?string $redirect = null): static { return $this->use(new RequireAuth($redirect)); }
    public function guest(string $redirect = '/'): static  { return $this->use(new RequireGuest($redirect)); }
    public function roles(string ...$roles): static        { return $this->use(new RequireRole(...$roles)); }
    public function csrf(): static                          { return $this->use(new VerifyCsrf()); }

    /** Run middleware then the action. Returns the raw (un-normalized) result. */
    public function run(Request $request, array $params = [], array $globalMiddleware = []): mixed
    {
        if ($this->action === null) throw new \LogicException('Handler has no action: call ->then(fn() => ...)');
        $core = fn(Request $req) => $this->invoke($req, $params);
        $chain = array_merge($globalMiddleware, $this->middleware);
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $mw = $chain[$i];
            $next = $core;
            $core = fn(Request $req) => $mw($req, $next);
        }
        return $core($request);
    }

    private function invoke(Request $request, array $params): mixed
    {
        $ref = new \ReflectionFunction($this->action);
        $positional = array_values($params);
        $pos = 0;
        $args = [];
        foreach ($ref->getParameters() as $p) {
            $type = $p->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($typeName === Request::class) { $args[] = $request; continue; }

            if (array_key_exists($p->getName(), $params) && !is_int($p->getName())) {
                $val = $params[$p->getName()];
            } elseif ($pos < count($positional)) {
                $val = $positional[$pos++];
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            } elseif ($type === null || $type->allowsNull()) {
                $args[] = null;
                continue;
            } else {
                throw new HttpException(404);
            }

            if ($typeName === 'int') {
                if (!is_int($val) && !ctype_digit(ltrim((string)$val, '-'))) throw new HttpException(404);
                $val = (int)$val;
            } elseif ($typeName === 'float') {
                if (!is_numeric($val)) throw new HttpException(404);
                $val = (float)$val;
            } elseif ($typeName === 'bool') {
                $val = Cast::asBool($val);
            }
            $args[] = $val;
        }
        return $ref->invokeArgs($args);
    }
}
