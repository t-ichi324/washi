<?php
/**
 * Log::access（アクセスログ）。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 * App::run() からの呼び出し（404 / 405 の記録・ON/OFF）は smoke.sh 側で実サーバー越しに見る。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\Log;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

$dir = sys_get_temp_dir() . '/washi-access-' . bin2hex(random_bytes(4));
$file = $dir . '/' . date('Y-m-d') . '-access.log';
$read = fn() => is_file($file) ? file_get_contents($file) : '';
$reset = function () use ($file) { if (is_file($file)) unlink($file); };
$entry = ['status' => 404, 'ms' => 12, 'ip' => '203.0.113.5', 'user' => 'u7', 'method' => 'GET', 'uri' => '/x?a=1'];

Log::to($dir);

// 既定は OFF
Log::access($entry);
$check('access: off by default (no file)', !is_file($file));

// 既定の書式
Log::accessEnable();
Log::access($entry);
$lines = explode("\n", rtrim($read()));
$cols = explode("\t", $lines[0]);
$check('access: one line per call',            count($lines) === 1);
$check('access: default has 7 tab columns',    count($cols) === 7);
$check('access: time column',                  (bool)preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $cols[0]));
$check('access: status/ms/ip/user/method/uri', array_slice($cols, 1) === ['404', '12ms', '203.0.113.5', 'u7', 'GET', '/x?a=1']);
$check('access: separate from app log',        !is_file($dir . '/' . date('Y-m-d') . '.log'));

// 未ログインは '-'、欠けたキーは補う
$reset();
Log::access(['status' => 200, 'method' => 'GET', 'uri' => '/']);
$cols = explode("\t", rtrim($read()));
$check('access: anonymous user is "-"',        $cols[4] === '-');
$check('access: missing keys are filled',      $cols[3] === '-' && $cols[2] === '0ms');

// 除外（URI に含まれるか）
$reset();
Log::accessEnable(true, ['logview.php', ' ', '/health']);
Log::access(['uri' => '/kanri/logview.php?f=1'] + $entry);
Log::access(['uri' => '/health'] + $entry);
$check('access: ignored URIs are skipped',     $read() === '');
Log::access(['uri' => '/kanri/index.php'] + $entry);
$check('access: other URIs are kept',          str_contains($read(), '/kanri/index.php'));

// 書式の差し替え
$reset();
Log::accessEnable();
Log::accessFormat(fn(array $e) => "{$e['status']}|{$e['ms']}|" . ($e['sid'] ?? 'nosid') . "\n\n");
Log::access($entry + ['sid' => 'abcd1234']);
$check('access: custom format used',           $read() === "404|12|abcd1234\n");
Log::access($entry);
$check('access: custom format sees defaults',  str_contains($read(), "404|12|nosid\n"));

// 書式が例外を投げてもリクエストは壊さず、既定の書式で残す
$reset();
Log::accessFormat(function (array $e) { throw new RuntimeException('boom'); });
Log::access($entry);
$check('access: broken format falls back',     str_contains($read(), "\t/x?a=1"));
$check('access: broken format is reported',    str_contains((string)@file_get_contents($dir . '/' . date('Y-m-d') . '.log'), 'Access log format failed: boom'));

// null で既定に戻る
$reset();
Log::accessFormat(null);
Log::access($entry);
$check('access: format reset to default',      count(explode("\t", rtrim($read()))) === 7);

// 最低ログレベルの影響を受けない（error だけ記録する設定でもアクセスは残る）
$reset();
Log::to($dir, 'error');
Log::access($entry);
$check('access: ignores min log level',        $read() !== '');

Log::accessEnable(false);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);
exit($fail ? 1 : 0);
