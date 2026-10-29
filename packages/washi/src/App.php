<?php

namespace Washi;

use Washi\Db\Connection;
use Washi\Db\Db;
use Washi\Db\NotFoundException;

/**
 * The front controller. One instance per request; `App::current()` gives it to helpers.
 *
 *   $app = new App(__DIR__ . '/..');      // loads .env, wires views/, pages/, lib/, storage/, default SQLite
 *   $app->get('/hello/{name}', fn(string $name) => "Hello $name");   // optional explicit routes
 *   $app->run();                          // explicit routes first, then pages/ files, else 404
 *
 * Request lifecycle: Request::fromGlobals() → resolve (routes → pages) → Handler::run (global middleware, pages/…/_access.php,
 * handler middleware, action) → normalize(result) → Response::send(). Exceptions become error responses here.
 *
 * pages/ resolution for "/a/b/c" with method M (longest match first, `_`-prefixed files are never routable):
 *   pages/a/b/c.{m}.php, pages/a/b/c.php, pages/a/b/c/index.{m}.php, pages/a/b/c/index.php
 *   pages/a/b.{m}.php,   pages/a/b.php   (params: ["c"])
 *   pages/a.{m}.php,     pages/a.php     (params: ["b","c"])
 *   pages/index.{m}.php, pages/index.php (params: ["a","b","c"])
 * A page file `return`s a Closure, a Handler (`page()->auth()->then(...)`), a Response/string/array,
 * or simply echoes HTML like classic PHP.
 *
 * Folder middleware: `pages/admin/_access.php` returning `page()->auth()->roles('admin')` guards every page
 * (and every unknown URL) under pages/admin/, nested folders accumulate outer → inner. Chain order is
 * global → _access.php → the page's own ->use(). The page file is included only after the guards pass.
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
    private LibAutoloader $libAutoloader;

    public function __construct(string $root, array $config = [])
    {
        $this->startedAt = microtime(true);
        $this->root = rtrim(realpath($root) ?: $root, '/\\');
        self::$current = $this;

        // 先に入った値が勝つので優先度の高い順に読む: 実環境変数 > .env（環境ごと） > .env.defaults（コミットする既定値）
        Env::load($this->root . '/.env');
        Env::load($this->root . '/.env.defaults');
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
            'session_idle'   => Env::int('SESSION_IDLE', 0),       // 秒。無操作がこれを超えたらセッション破棄。0 で無効
            'session_lifetime' => Env::int('SESSION_LIFETIME', 0), // 秒。ログイン（ID 再生成）からこれを超えたら破棄。0 で無効
            'csrf_token'     => '_token',
            'log'            => Env::get('LOG_TARGET', 'storage'),   // 'stderr' | 'storage' | absolute dir
            'access_log'     => Env::bool('ACCESS_LOG', false),           // true で 1 リクエスト 1 行を <日付>-access.log へ（Log::access）
            'access_ignore'  => Env::get('ACCESS_LOG_IGNORE', ''),         // カンマ区切り。URI に含まれていたら記録しない
            'trusted_proxies'=> Env::get('TRUSTED_PROXIES', ''),
            'ip_allowlist'   => Env::get('IP_ALLOWLIST', 'whitelist.txt'),   // IpAllowlist の一覧（root 相対か絶対）。無い・空なら制限なし
            'url'            => Env::get('APP_URL', ''),     // 外に出す絶対 URL の起点（https://example.com/app）。空ならリクエストの Host から作る
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
        Log::accessEnable((bool)$this->config['access_log'], explode(',', (string)$this->config['access_ignore']));
        Session::configure($this->config['session_name'], $this->config['session_idle'], $this->config['session_lifetime']);
        Security::configure($this->config['csrf_token']);
        View::configure($this->path($this->config['views']), $this->config['layout']);
        // lib/ はサブフォルダも探す（名前空間なしでも可）。本番は対応表を storage/cache にキャッシュ
        $this->libAutoloader = new LibAutoloader($this->path($this->config['lib']),
            $this->config['debug'] ? null : $this->path($this->config['storage'], 'cache', 'lib-classmap.php'));
        $this->libAutoloader->register();
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

    /**
     * 同じサイト内を指す URL か（redirect() / back() のオープンリダイレクト対策）。
     * '/x' や 'x' と、Host が今のリクエストと同じ http(s) の絶対 URL だけ true。
     * '//evil' '/\evil' のようにブラウザが別ホストと解釈する形や、制御文字入り（ブラウザが除去して '//' になる）は false
     */
    public function isLocalUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url)) return false;
        if (preg_match('#^[/\\\\]{2}|^\\\\#', $url)) return false;   // '//x' '/\x' '\\x' '\x' はブラウザが別ホストと解釈
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) return true;   // スキームなし＝サイト内の相対/絶対パス
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || !isset($p['host'])) return false;
        $host = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
        return $host === strtolower($this->request()->host());
    }

    /**
     * Absolute URL (scheme + host) for mail bodies, QR codes, OAuth callbacks…
     * APP_URL があればそれを起点にする（basePath も含めて書く）。無ければ現在のリクエストの Host から作るが、
     * Host ヘッダは送信側が自由に書けるので、本番でメールに載せるなら APP_URL を設定すること。
     */
    public function absoluteUrl(string $path = '/', array $query = []): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) || str_starts_with($path, '//')) return $path;
        $base = rtrim((string)$this->config['url'], '/');
        if ($base === '') {
            $r = $this->request();
            return ($r->isHttps() ? 'https' : 'http') . '://' . $r->host() . $this->url($path, $query);
        }
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

    public function run(): bool
    {
        // 組み込みサーバーが配信すべき実ファイルなら、ここでは何もせず false を返す。
        // `return (new App(...))->run();` と書いておけば、その false がルータスクリプトの
        // 戻り値になり、組み込みサーバーが自分でファイルを配信する。
        if ($this->isStaticFileRequest()) return false;

        $response = $this->handle($request = $this->request());
        $this->logAccess($request, $response);
        $response->send();
        return true;
    }

    /**
     * アクセスログ 1 行。最終のステータスを知っているのはここだけ（404・405・500 はミドルウェアの外で決まる）。
     * 処理時間は App 生成から応答確定まで（送信は含まない）。セッションは新たに開始せず、既に始まっていれば使う。
     */
    private function logAccess(Request $request, Response $response): void
    {
        if (!Log::accessEnabled()) return;
        $clean = fn(string $s) => preg_replace('/[\x00-\x1f\x7f]/', ' ', $s);   // 1 行を保つ（ログ偽造対策）
        $user = Session::isActive() && Session::has('_auth') ? Auth::id() : null;
        Log::access([
            'status'  => $response->getStatus(),
            'ms'      => (int)round((microtime(true) - $this->startedAt) * 1000),
            'ip'      => $request->ip($this->config['trusted_proxies']),
            'user'    => $user === null ? null : $clean((string)$user),
            'sid'     => Session::isActive() && session_id() !== '' ? substr(md5(session_id()), 0, 8) : null,
            'method'  => $request->method,
            'uri'     => $clean((string)($request->server['REQUEST_URI'] ?? $request->path)),
            'ua'      => $clean($request->userAgent()),
            'referer' => $clean((string)$request->referer()),
        ]);
    }

    /**
     * PHP 組み込みサーバー（`php -S ... public/index.php`）では、ルータスクリプトが全リクエストを
     * 受け取ってしまい public/ の css・js・画像まで 404 になる。実在するファイルは組み込み
     * サーバー自身に配信させる（false を返すのがその合図）。
     *
     * 本番の Apache/nginx はファイルが存在すれば PHP に渡さないので、ここは cli-server 限定。
     *
     * @return bool true なら呼び出し側は何もしない（＝サーバーに任せた）
     */
    private function isStaticFileRequest(): bool
    {
        if (PHP_SAPI !== 'cli-server') return false;

        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $path    = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($docRoot === '' || !is_string($path)) return false;

        $path = rawurldecode($path);
        if ($path === '' || str_contains($path, "\0")) return false;

        $real = realpath($docRoot . '/' . ltrim($path, '/'));
        $root = realpath($docRoot);
        if ($real === false || $root === false || !is_file($real)) return false;
        // ドキュメントルートの外は配信しない（../ での脱出を防ぐ）
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return false;
        // PHP ファイルは配信せずアプリとして動かす（ソースを晒さない）
        if (preg_match('/\.(?:php|phtml|phar)$/i', $real)) return false;

        return true;
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        Debug::enable($this->config['debug'] && $this->config['debug_panel']);   // パネル用の記録をリクエストごとに空にする
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
        if ($this->isDebug() && $this->config['debug_panel'] && str_starts_with((string)$response->getHeader('Content-Type'), 'text/html')
            && self::isNavigation($request)
            // 完全な HTML 文書にだけ入れる。断片（部分ロード用）は Sec-Fetch-Mode が来ない http://*.test などでも混ざらない
            && ($at = strripos($body = $response->getBody(), '</body>')) !== false) {
            $panel = Debug::panel($request, $response, microtime(true) - $this->startedAt, $this->queries);
            $response->body(substr_replace($body, $panel, $at, 0));   // 最後の </body> の直前だけ（JS 文字列内の </body> に入れない）
        }
        return $response;
    }

    /**
     * ページ遷移（アドレスバー・リンク・フォーム送信・iframe）か。fetch/XHR/htmx の部分ロードに
     * デバッグパネルを混ぜると、差し込んだ断片の中にパネルが一瞬現れるため注入しない。
     * Sec-Fetch-Mode はブラウザが自動で付ける（navigate 以外 = スクリプトからの取得）。無い（curl・古いブラウザ）なら遷移扱い。
     */
    private static function isNavigation(Request $request): bool
    {
        if ($request->isAjax() || $request->header('HX-Request') !== null) return false;
        $mode = $request->header('Sec-Fetch-Mode');
        return $mode === null || $mode === 'navigate';
    }

    /** lib/ の「クラス名 → ファイル」対応表のキャッシュを消す（通常は自動で作り直すので不要） */
    public function clearClassMap(): void
    {
        $this->libAutoloader->clear();
    }

    /** Run pending SQL migrations from the configured directory. @return string[] executed files */
    public function migrate(?string $dir = null): array
    {
        return Db::migrate($this->path($dir ?? $this->config['migrations']));
    }

    /** Convert whatever a handler returned into a Response. */
    public function normalize(mixed $result): Response
    {
        return Response::from($result);
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
            Debug::record('route', ['resolved' => 'route ' . implode('|', $route['methods']) . ' ' . $route['pattern'], 'params' => $params]);
            return [$route['handler'], $params];
        }
        [$file, $params, $dirs] = $this->findPage($request);
        $guards = $this->accessGuards($dirs, $request);
        Debug::record('route', ['resolved' => $file === null ? '(no page)' : 'page ' . $this->relative($file), 'params' => $params]);
        if ($file === null) {
            $miss = $allowed ? new HttpException(405, 'Allowed: ' . implode(', ', array_unique($allowed))) : new HttpException(404);
            if (!$guards) throw $miss;
            // フォルダ内の未知 URL も _access.php を通してから 404 にする（未ログインで /admin/xxx を叩いても構成が漏れない）
            return [Handler::make(fn() => throw $miss)->use(...$guards), []];
        }
        // ページ本体はチェーンの中で include する。_access.php が止めれば、echo 型ページも先頭のコードも 1 行も動かない
        return [Handler::make(fn(Request $r) => $this->loadPage($file, $r)->run($r, $params))->use(...$guards), []];
    }

    /**
     * pages/ の解決。include はしない（_access.php より先にページのコードを動かさないため）。
     * dirs は _access.php を探すディレクトリ（pages/ から外→内）で、解決したファイルの位置ではなく URL で決める：
     * URL の先頭から、pages/ に実在するディレクトリが続く限り（不正なセグメントで止まる）。
     * こうしないと /admin/xxx が pages/index.php に余りセグメントで落ちたとき admin の guard が外れる。
     * 解決したファイルのディレクトリは必ずこの dirs の先頭部分に含まれる。
     * @return array{0:?string,1:list<string>,2:list<string>} [file, 余りセグメント, dirs]
     */
    private function findPage(Request $request): array
    {
        $dir = $this->path($this->config['pages']);
        if (!is_dir($dir)) return [null, [], []];
        $segments = $request->segments();
        $valid = static fn(string $s) => $s !== '' && $s[0] !== '_' && $s[0] !== '.' && preg_match('/^[A-Za-z0-9_.\-]+$/', $s);
        $dirs = [$dir];
        foreach ($segments as $s) {
            if (!$valid($s) || !is_dir(end($dirs) . "/$s")) break;
            $dirs[] = end($dirs) . "/$s";
        }
        foreach ($segments as $s) {
            if (!$valid($s)) return [null, [], $dirs];
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
                if (is_file($file)) return [$file, $params, $dirs];
            }
        }
        return [null, [], $dirs];
    }

    /**
     * 各ディレクトリの _access.php が返したミドルウェアを外→内の順で集める。
     * return できるもの: `page()->auth()...`（then() なしの Handler）、`fn(Request, Closure $next)`、その配列、
     * または何も返さない（`View::layout(...)` だけ書くなど）。
     * @param list<string> $dirs
     * @return list<callable>
     */
    private function accessGuards(array $dirs, Request $request): array
    {
        $app = $this;
        $guards = [];
        foreach ($dirs as $d) {
            $file = "$d/_access.php";
            if (!is_file($file)) continue;
            Debug::record('access', ['file' => $this->relative($file)]);
            $ret = (static function () use ($file, $request, $app) { return include $file; })();
            if ($ret instanceof Handler) {
                if ($ret->hasAction()) throw new \LogicException("{$file} must not call ->then(): it guards a folder, not a page");
                $list = $ret->middleware();
            } elseif ($ret === 1 || $ret === null) {
                $list = [];
            } else {
                $list = is_array($ret) ? $ret : [$ret];
            }
            foreach ($list as $mw) {
                if (!is_callable($mw) || is_string($mw)) throw new \LogicException("{$file} must return middleware `fn(Request, Closure \$next)`, a Handler or an array of them");
                $guards[] = $mw;
            }
        }
        return $guards;
    }

    /** root からの相対パス（デバッグ表示用） */
    private function relative(string $file): string
    {
        return str_starts_with($file, $this->root . '/') ? substr($file, strlen($this->root) + 1) : $file;
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
        $res = $this->renderError($e, $request);
        if ($e instanceof HttpException) foreach ($e->headers as $k => $v) $res->header($k, $v);
        return $res;
    }

    private function renderError(\Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) return $e->toResponse($request);
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
