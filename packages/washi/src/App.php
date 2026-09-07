<?php

namespace Washi;

use Washi\Db\Connection;
use Washi\Db\Db;
use Washi\Db\NotFoundException;
use Washi\Db\Paginated;

/**
 * The front controller. One instance per request; `App::current()` gives it to helpers.
 *
 *   $app = new App(__DIR__ . '/..');      // loads .env, wires views/, pages/, lib/, storage/, default SQLite
 *   $app->get('/hello/{name}', fn(string $name) => "Hello $name");   // optional explicit routes
 *   $app->run();                          // explicit routes first, then pages/ files, else 404
 *
 * Request lifecycle: Request::fromGlobals() → resolve (routes → pages) → Handler::run (global middleware,
 * handler middleware, action) → normalize(result) → Response::send(). Exceptions become error responses here.
 *
 * pages/ resolution for "/a/b/c" with method M (longest match first, `_`-prefixed files are never routable):
 *   pages/a/b/c.{m}.php, pages/a/b/c.php, pages/a/b/c/index.{m}.php, pages/a/b/c/index.php
 *   pages/a/b.{m}.php,   pages/a/b.php   (params: ["c"])
 *   pages/a.{m}.php,     pages/a.php     (params: ["b","c"])
 *   pages/index.{m}.php, pages/index.php (params: ["a","b","c"])
 * A page file `return`s a Closure, a Handler (`page()->auth()->then(...)`), a Response/string/array,
 * or simply echoes HTML like classic PHP.
 */
class App
{
    private static ?App $current = null;

    private readonly string $root;
    private array $config;
    /** @var list<array{methods:string[],pattern:string,regex:string,handler:Handler}> */
    private array $routes = [];
    /** @var list<callable> */
    private array $middleware = [];
    private ?Request $request = null;
    private array $queries = [];
    private float $startedAt;

    public function __construct(string $root, array $config = [])
    {
        $this->startedAt = microtime(true);
        $this->root = rtrim(realpath($root) ?: $root, '/\\');
        self::$current = $this;

        Env::load($this->root . '/.env');
        $this->config = $config + [
            'debug'          => Env::bool('APP_DEBUG', false),
            'debug_panel'    => true,
            'timezone'       => Env::get('APP_TIMEZONE', 'Asia/Tokyo'),
            'pages'          => 'pages',
            'views'          => 'views',
            'lib'            => 'lib',
            'storage'        => 'storage',
            'migrations'     => 'db/migrations',
            'auto_migrate'   => Env::bool('DB_AUTO_MIGRATE', false),
            'layout'         => '_layout',
            'login_page'     => Env::get('APP_LOGIN_PAGE', '/login'),
            'session_name'   => Env::get('SESSION_NAME', 'washi_sid'),
            'csrf_token'     => '_token',
            'log'            => Env::get('LOG_TARGET', 'storage'),   // 'stderr' | 'storage' | absolute dir
            'trusted_proxies'=> Env::get('TRUSTED_PROXIES', ''),
            'security_headers' => true,
        ];

        if ($this->config['debug']) {
            error_reporting(E_ALL);
            ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
        }
        ini_set('log_errors', '1');
        date_default_timezone_set($this->config['timezone']);
        mb_internal_encoding('UTF-8');

        $logTarget = $this->config['log'];
        Log::to($logTarget === 'storage' ? $this->path($this->config['storage'], 'log') : $logTarget,
            $this->config['debug'] ? 'debug' : 'info');
        Session::configure($this->config['session_name']);
        Security::configure($this->config['csrf_token']);
        View::configure($this->path($this->config['views']), $this->config['layout']);
        $this->registerLibAutoload($this->path($this->config['lib']));
        $this->wireDatabase();

        // 例外を Response に変換するのは handle() だが、PHP エラーは例外化しておく
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) return false;
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
    }

    public static function current(): App
    {
        if (self::$current === null) throw new \LogicException('No App instance. Create `new App($root)` first.');
        return self::$current;
    }

    // ── config / paths ───────────────────────────────────────────────────

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function isDebug(): bool { return (bool)$this->config['debug']; }
    public function root(): string { return $this->root; }

    /** Absolute filesystem path under the project root. */
    public function path(string ...$parts): string
    {
        $p = implode('/', array_filter($parts, fn($s) => $s !== ''));
        return str_starts_with($p, '/') ? $p : $this->root . '/' . $p;
    }

    /** Public URL for an app path (prefixes the sub-directory base path). Absolute URLs pass through. */
    public function url(string $path = '/', array $query = []): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) || str_starts_with($path, '//')) return $path;
        $base = $this->request?->basePath ?? '';
        $url = $base . '/' . ltrim($path, '/');
        if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        return $url;
    }

    public function request(): Request
    {
        return $this->request ??= Request::fromGlobals();
    }

    // ── routing API ──────────────────────────────────────────────────────

    public function get(string $pattern, callable $action): Handler    { return $this->map(['GET', 'HEAD'], $pattern, $action); }
    public function post(string $pattern, callable $action): Handler   { return $this->map(['POST'], $pattern, $action); }
    public function put(string $pattern, callable $action): Handler    { return $this->map(['PUT'], $pattern, $action); }
    public function patch(string $pattern, callable $action): Handler  { return $this->map(['PATCH'], $pattern, $action); }
    public function delete(string $pattern, callable $action): Handler { return $this->map(['DELETE'], $pattern, $action); }
    public function any(string $pattern, callable $action): Handler    { return $this->map(['*'], $pattern, $action); }

    /** @param string[] $methods */
    public function map(array $methods, string $pattern, callable $action): Handler
    {
        $handler = Handler::make($action);
        $pattern = '/' . trim($pattern, '/');
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#', function ($m) {
            $sub = $m[2] ?? '[^/]+';
            return '(?P<' . $m[1] . '>' . $sub . ')';
        }, $pattern);
        $this->routes[] = ['methods' => array_map('strtoupper', $methods), 'pattern' => $pattern, 'regex' => '#^' . $regex . '/?$#u', 'handler' => $handler];
        return $handler;
    }

    /** Global middleware `fn(Request, Closure $next)`, runs for every matched route/page. */
    public function use(callable ...$middleware): static
    {
        foreach ($middleware as $m) $this->middleware[] = $m;
        return $this;
    }

    // ── lifecycle ────────────────────────────────────────────────────────

    public function run(): void
    {
        $this->handle($this->request())->send();
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        try {
            if ($this->config['auto_migrate']) $this->migrate();
            [$handler, $params] = $this->resolve($request);
            $result = $handler->run($request, $params, $this->middleware);
            $response = $this->normalize($result);
        } catch (\Throwable $e) {
            $response = $this->errorResponse($e, $request);
        }
        if ($this->config['security_headers']) {
            foreach (['X-Frame-Options' => 'SAMEORIGIN', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin'] as $k => $v) {
                if ($response->getHeader($k) === null) $response->header($k, $v);
            }
        }
        if ($this->isDebug() && $this->config['debug_panel'] && str_starts_with((string)$response->getHeader('Content-Type'), 'text/html')) {
            $body = $response->getBody();
            $panel = Debug::panel(microtime(true) - $this->startedAt, $this->queries);
            $response->body(str_contains($body, '</body>') ? str_replace('</body>', $panel . '</body>', $body) : $body . $panel);
        }
        return $response;
    }

    /** Run pending SQL migrations from the configured directory. @return string[] executed files */
    public function migrate(?string $dir = null): array
    {
        return Db::migrate($this->path($dir ?? $this->config['migrations']));
    }

    /** Convert whatever a handler returned into a Response. */
    public function normalize(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response      => $result,
            $result === null                 => Response::empty(204),
            is_string($result)               => Response::html($result),
            $result instanceof \Stringable   => Response::html((string)$result),
            is_int($result)                  => Response::empty($result),
            $result instanceof Paginated     => Response::json([
                'data' => $result->toArray(), 'total' => $result->total, 'page' => $result->currentPage,
                'per_page' => $result->perPage, 'last_page' => $result->lastPage,
            ]),
            is_object($result) && !($result instanceof \JsonSerializable) && method_exists($result, 'toArray')
                                             => Response::json($result->toArray()),
            default                          => Response::json($result),
        };
    }

    /** @return array<int, array{sql:string,params:array,ms:float,connection:string,kind:string}> queries seen this request (debug only) */
    public function queries(): array { return $this->queries; }

    // ── resolution ───────────────────────────────────────────────────────

    /** @return array{0:Handler,1:array} */
    private function resolve(Request $request): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) continue;
            if (!in_array('*', $route['methods'], true) && !in_array($request->method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return [$route['handler'], $params];
        }
        if ($page = $this->resolvePage($request)) return $page;
        if ($allowed) throw new HttpException(405, 'Allowed: ' . implode(', ', array_unique($allowed)));
        throw new HttpException(404);
    }

    /** @return array{0:Handler,1:array}|null */
    private function resolvePage(Request $request): ?array
    {
        $dir = $this->path($this->config['pages']);
        if (!is_dir($dir)) return null;
        $segments = $request->segments();
        foreach ($segments as $s) {
            if ($s === '' || $s[0] === '_' || $s[0] === '.' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $s)) return null;
        }
        $m = strtolower($request->method === 'HEAD' ? 'GET' : $request->method);
        for ($i = count($segments); $i >= 0; $i--) {
            $base = $dir . ($i > 0 ? '/' . implode('/', array_slice($segments, 0, $i)) : '');
            $params = array_slice($segments, $i);
            $candidates = $i === count($segments)
                ? ["$base.$m.php", "$base.php", "$base/index.$m.php", "$base/index.php"]
                : ["$base.$m.php", "$base.php"];
            if ($i === 0) $candidates = ["$dir/index.$m.php", "$dir/index.php"];
            foreach ($candidates as $file) {
                if (is_file($file)) return [$this->loadPage($file, $request), $params];
            }
        }
        return null;
    }

    private function loadPage(string $file, Request $request): Handler
    {
        $app = $this;
        $level = ob_get_level();
        ob_start();
        try {
            $ret = (static function () use ($file, $request, $app) { return include $file; })();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
        $echoed = ob_get_clean();

        if ($ret instanceof Handler) {
            if (!$ret->hasAction()) throw new \LogicException("Page {$file} returned a Handler without ->then()");
            return $ret;
        }
        if ($ret instanceof \Closure || (is_callable($ret) && !is_string($ret))) return Handler::make($ret);
        if ($ret === 1 || $ret === null) {
            // 素の PHP として echo したページ。出力をそのまま HTML 応答にする
            return Handler::make(fn() => Response::html($echoed));
        }
        return Handler::make(fn() => $ret);
    }

    // ── errors ───────────────────────────────────────────────────────────

    private function errorResponse(\Throwable $e, Request $request): Response
    {
        $status = match (true) {
            $e instanceof HttpException     => $e->status,
            $e instanceof NotFoundException => 404,
            default                         => 500,
        };
        $message = $status >= 500 && !$this->isDebug() ? HttpException::title($status) : $e->getMessage();
        if ($status >= 500) Log::exception($e, 'Unhandled');
        elseif ($status !== 404) Log::warning("HTTP $status: " . $e->getMessage(), ['path' => $request->path]);

        if ($request->wantsJson()) {
            $payload = ['error' => $message, 'status' => $status];
            if ($this->isDebug()) $payload['debug'] = ['exception' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => explode("\n", $e->getTraceAsString())];
            return Response::json($payload, $status);
        }
        foreach (["_error.$status", '_error'] as $tpl) {
            if (View::exists($tpl)) {
                try {
                    return Response::html(View::page($tpl, ['status' => $status, 'message' => $message, 'exception' => $this->isDebug() ? $e : null, 'title' => "$status " . HttpException::title($status)]), $status);
                } catch (\Throwable $inner) {
                    Log::exception($inner, 'Error view failed');
                    break;
                }
            }
        }
        return Response::html(Debug::errorPage($status, $message, $this->isDebug() ? $e : null), $status);
    }

    // ── wiring ───────────────────────────────────────────────────────────

    private function registerLibAutoload(string $libDir): void
    {
        if (!is_dir($libDir)) return;
        spl_autoload_register(static function (string $class) use ($libDir): void {
            $rel = str_replace('\\', '/', $class);
            $candidates = ["$libDir/$rel.php"];
            // 先頭の名前空間セグメントを剥がしたパスも試す（App\Models\User → lib/Models/User.php）
            if (($pos = strpos($rel, '/')) !== false) $candidates[] = "$libDir/" . substr($rel, $pos + 1) . '.php';
            $candidates[] = "$libDir/" . basename($rel) . '.php';
            foreach ($candidates as $f) {
                if (is_file($f)) { require_once $f; return; }
            }
        });
    }

    private function wireDatabase(): void
    {
        if (!Db::hasConnection('default')) {
            $conn = Connection::fromEnv('DB');
            if ($conn === null) {
                $conn = Connection::sqlite($this->path($this->config['storage'], 'app.db'));
            } elseif ($conn->isSqlite()) {
                $p = Env::get('DB_SQLITE_PATH') ?: Env::get('DB_DATABASE') ?: 'storage/app.db';
                if ($p !== ':memory:' && !str_starts_with($p, '/')) $p = $this->path($p);
                $conn = Connection::sqlite($p);
            }
            Db::addConnection('default', $conn);
        }
        if ($this->isDebug()) {
            Db::listen(function (string $sql, array $params, float $elapsed, string $key, string $kind): void {
                $this->queries[] = ['sql' => $sql, 'params' => $params, 'ms' => round($elapsed * 1000, 2), 'connection' => $key, 'kind' => $kind];
            });
        }
        if (Env::bool('DB_LOG', false)) {
            Db::listen(fn(string $sql, array $params, float $elapsed) => Log::debug(sprintf('[db %.1fms] %s', $elapsed * 1000, $sql), $params));
        }
    }
}
