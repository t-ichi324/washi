<?php
/**
 * Washi — 1 ファイルで動く最小構成。
 *   php -S localhost:8080 index.php
 */
require __DIR__ . '/../../vendor/autoload.php';

use Washi\App;
use Washi\Db\Db;

$app = new App(__DIR__, ['debug' => true, 'pages' => 'none', 'views' => 'none']);

// 戻り値規約: 文字列 → HTML、配列/オブジェクト → JSON、Response → そのまま、null → 204
$app->get('/', fn() => '<h1>Hello, Washi</h1><p><a href="/hello/世界">/hello/世界</a> · <a href="/api/time">/api/time</a> · <a href="/db">/db</a></p>');
$app->get('/hello/{name}', fn(string $name) => '<h1>Hello, ' . h($name) . '</h1>');
$app->get('/api/time', fn() => ['now' => date('c'), 'tz' => date_default_timezone_get()]);
$app->post('/api/echo', fn(Washi\Request $r) => $r->all());

// DB は未設定なら storage/app.db の SQLite が自動で使われる
$app->get('/db', function () {
    Db::execute('CREATE TABLE IF NOT EXISTS hits (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT)');
    Db::table('hits')->insert(['at' => date('c')]);
    return ['hits' => Db::table('hits')->count()];
});

$app->run();
