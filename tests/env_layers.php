<?php
/**
 * .env の重ね合わせ。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 *
 * 優先順位: 実環境変数 > 先に Env::load() したファイル > .env > .env.defaults > コード内の既定値。
 * Env::load は「既にあるキーは上書きしない」ので、App は .env → .env.defaults の順に読む。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\App;
use Washi\Env;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

$root = sys_get_temp_dir() . '/washi-env-' . getmypid();
@mkdir($root);
file_put_contents("$root/.env.defaults", implode("\n", [
    'WENV_ONLY_DEFAULT=d',
    'WENV_BOTH=d',
    'WENV_EMPTIED=d',
    'WENV_REAL=d',
    'WENV_PRELOADED=d',
    'APP_TIMEZONE=UTC',
]) . "\n");
file_put_contents("$root/.env", "WENV_BOTH=e\nWENV_EMPTIED=\nWENV_PRELOADED=e\n");
file_put_contents("$root/extra.env", "WENV_PRELOADED=x\n");
putenv('WENV_REAL=r');

Env::load("$root/extra.env");   // new App() より前に読めば .env より強い
$app = new App($root);

$check('defaults: key only in .env.defaults',  Env::get('WENV_ONLY_DEFAULT') === 'd');
$check('defaults: .env overrides',             Env::get('WENV_BOTH') === 'e');
$check('defaults: empty in .env still wins',   Env::get('WENV_EMPTIED') === '');
$check('defaults: real env var wins',          Env::get('WENV_REAL') === 'r');
$check('defaults: Env::load before App wins',  Env::get('WENV_PRELOADED') === 'x');
$check('defaults: feeds App config',           $app->config('timezone') === 'UTC');

// .env.defaults が無くても何も起きない
@mkdir("$root/bare");
$check('defaults: missing file is fine',       (new App("$root/bare")) instanceof App);

foreach (['.env', '.env.defaults', 'extra.env'] as $f) @unlink("$root/$f");
@rmdir("$root/bare");
@rmdir($root);
exit($fail ? 1 : 0);
