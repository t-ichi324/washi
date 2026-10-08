<?php
/**
 * サブディレクトリ設置時のパス解決。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 *
 * .htaccess で public/ へ内部転送していると SCRIPT_NAME は /app/public/index.php なのに
 * URL は /app/... になる。この食い違いで basePath が剥がせず、全 URL が index に落ちていた。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\Request;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

/** @return array{0:string,1:string} [path, basePath] */
$resolve = function (string $uri, string $scriptName): array {
    $_SERVER = ['REQUEST_URI' => $uri, 'SCRIPT_NAME' => $scriptName, 'REQUEST_METHOD' => 'GET'];
    $r = Request::fromGlobals();
    return [$r->path, $r->basePath];
};

// ドキュメントルート = public/（理想形）
[$p, $b] = $resolve('/salon/abc', '/index.php');
$check('docroot=public: path', $p === '/salon/abc');

// サブディレクトリ + public/ が docroot
[$p, $b] = $resolve('/app/salon/abc', '/app/index.php');
$check('subdir: path', $p === '/salon/abc');
$check('subdir: basePath', $b === '/app');

// サブディレクトリ + .htaccess で public/ へ内部転送（今回直した形）
[$p, $b] = $resolve('/app/salon/abc', '/app/public/index.php');
$check('subdir+rewrite: path', $p === '/salon/abc');
$check('subdir+rewrite: basePath', $b === '/app');

[$p, $b] = $resolve('/app/', '/app/public/index.php');
$check('subdir+rewrite: top page', $p === '/');

[$p, $b] = $resolve('/app/login', '/app/public/index.php');
$check('subdir+rewrite: /login', $p === '/login');

// クエリ文字列は path に混ざらない
[$p, $b] = $resolve('/app/?q=x&category=lash', '/app/public/index.php');
$check('query string stripped', $p === '/');

// public/ を直接叩いた場合も壊さない
[$p, $b] = $resolve('/app/public/salon/abc', '/app/public/index.php');
$check('direct public/ still works', $p === '/salon/abc');

exit($fail === 0 ? 0 : 1);
