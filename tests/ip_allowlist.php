<?php
/**
 * IpAllowlist（whitelist.txt の照合）。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 * ミドルウェア（->ipAllowlist() の 403 / 素通し）は smoke.sh 側で実サーバー越しに見る。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\App;
use Washi\IpAllowlist;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

// matches: 単一 IP・CIDR・IPv6・境界・不正値
$m = fn(string $ip, string $entry) => IpAllowlist::matches($ip, $entry);
$check('ip: exact match',                   $m('203.0.113.10', '203.0.113.10'));
$check('ip: exact mismatch',                !$m('203.0.113.11', '203.0.113.10'));
$check('ip: /24 inside',                    $m('198.51.100.200', '198.51.100.0/24'));
$check('ip: /24 outside',                   !$m('198.51.101.1', '198.51.100.0/24'));
$check('ip: /20 non-byte boundary in',      $m('10.1.15.255', '10.1.0.0/20'));
$check('ip: /20 non-byte boundary out',     !$m('10.1.16.0', '10.1.0.0/20'));
$check('ip: /0 matches any v4',             $m('8.8.8.8', '0.0.0.0/0'));
$check('ip: IPv6 /32',                      $m('2001:db8:1::5', '2001:db8::/32'));
$check('ip: IPv6 outside',                  !$m('2001:db9::1', '2001:db8::/32'));
$check('ip: v4 never matches v6 entry',     !$m('127.0.0.1', '::1'));
$check('ip: garbage ip → false',            !$m('not-an-ip', '0.0.0.0/0'));
$check('ip: garbage entry → false',         !$m('1.2.3.4', 'example.com'));
$check('ip: prefix too long → false',       !$m('1.2.3.4', '1.2.3.4/33'));
$check('ip: non-numeric prefix → false',    !$m('1.2.3.4', '1.2.3.0/x'));

// fromFile: コメント・空行・行末コメント、無いファイルと空ファイルは制限なし
$dir = sys_get_temp_dir() . '/washi-ip-' . getmypid();
@mkdir($dir);
file_put_contents("$dir/whitelist.txt", "# 拠点\n203.0.113.10\n\n  198.51.100.0/24   # 本社\n#203.0.113.99\n");
$list = IpAllowlist::fromFile("$dir/whitelist.txt");
$check('file: comments and blanks skipped', $list->entries() === ['203.0.113.10', '198.51.100.0/24']);
$check('file: listed ip allowed',           $list->allows('198.51.100.7'));
$check('file: commented-out ip denied',     !$list->allows('203.0.113.99'));
$check('file: missing → allow all',         IpAllowlist::fromFile("$dir/none.txt")->allows('1.2.3.4'));
file_put_contents("$dir/empty.txt", "# まだ誰も登録していない\n\n");
$check('file: comments only → allow all',   IpAllowlist::fromFile("$dir/empty.txt")->allows('1.2.3.4'));

// forApp: 既定は root/whitelist.txt、引数で別ファイル、ip_allowed() ヘルパー
$app = new App($dir);
$check('app: default root/whitelist.txt',   IpAllowlist::forApp()->entries() === $list->entries());
$check('app: explicit file',                IpAllowlist::forApp('empty.txt')->isEmpty());
$check('helper: ip_allowed(listed)',        ip_allowed('203.0.113.10'));
$check('helper: ip_allowed(unlisted)',      !ip_allowed('203.0.113.11'));

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);
exit($fail ? 1 : 0);
