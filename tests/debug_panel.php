<?php
/**
 * デバッグパネルの注入条件。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 *
 * パネルは「ページ遷移」かつ「完全な HTML 文書（</body> あり）」にだけ入る。部分ロード用の断片に入れると、
 * 差し込んだ先で #washi-debug が二重になる（Sec-Fetch-Mode は http://*.test などでは送られないので構造でも判定する）。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\App;
use Washi\Request;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

$root = sys_get_temp_dir() . '/washi-dbg-' . getmypid();
@mkdir($root);
$app = new App($root, ['debug' => true, 'log' => 'stderr']);
$app->get('/doc/{id}', fn() => '<html><body><script>var s = "</body>";</script>doc</body></html>');
$app->get('/frag', fn() => '<li>item</li>');

$body = $app->handle(Request::create('GET', '/doc/5'))->getBody();
$check('panel: full document → injected',   str_contains($body, 'id="washi-debug"'));
$check('panel: explicit route shown',       str_contains($body, 'route GET|HEAD /doc/{id}'));
$check('panel: only before last </body>',   substr_count($body, 'id="washi-debug"') === 1 && str_ends_with($body, '</details></body></html>'));
$check('panel: fragment → not injected',    $app->handle(Request::create('GET', '/frag'))->getBody() === '<li>item</li>');
$check('panel: fetch() → not injected',     !str_contains($app->handle(Request::create('GET', '/doc/5', server: ['HTTP_SEC_FETCH_MODE' => 'cors']))->getBody(), 'washi-debug'));

@rmdir($root);
exit($fail ? 1 : 0);
