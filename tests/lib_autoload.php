<?php
/**
 * lib/ の自動読み込み（LibAutoloader）。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 *
 * サブフォルダの名前空間なしクラス、名前空間つきクラス（パス優先・ファイル名で再探索）、同名クラスの重複、
 * 本番時の対応表キャッシュ（作成・ファイル移動後の自己修復・clearClassMap）。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\App;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};
$put = function (string $file, string $code) {
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, "<?php\n$code\n");
};
$rm = function (string $dir) use (&$rm) {
    foreach (glob("$dir/{,.}[!.]*", GLOB_BRACE) ?: [] as $f) is_dir($f) ? $rm($f) : unlink($f);
    @rmdir($dir);
};

// ── debug（キャッシュなし） ──────────────────────────────────────────
$root = sys_get_temp_dir() . '/washi-lib-' . getmypid();
$put("$root/lib/Models/LtShisetsu.php", 'class LtShisetsu {}');
$put("$root/lib/Services/Deep/LtMailer.php", 'class LtMailer {}');
$put("$root/lib/LtRoot.php", 'class LtRoot {}');
$put("$root/lib/Vendor/LtAcme/LtClient.php", 'namespace LtAcme; class LtClient {}');
$put("$root/lib/Models/LtNote.php", 'namespace App\Models; class LtNote {}');
$put("$root/lib/A/LtDup.php", 'class LtDup {}');
$put("$root/lib/B/LtDup.php", 'class LtDup {}');
$put("$root/lib/.hidden/LtHidden.php", 'class LtHidden {}');
$app = new App($root, ['debug' => true, 'log' => 'stderr']);

$check('no namespace, subfolder',          class_exists('LtShisetsu'));
$check('no namespace, nested subfolder',   class_exists('LtMailer'));
$check('no namespace, lib/ root',          class_exists('LtRoot'));
$check('namespace: found by file name',    class_exists('LtAcme\LtClient'));
$check('namespace: first segment stripped', class_exists('App\Models\LtNote'));
$check('unknown class → false, no error',  !class_exists('LtNope'));
$check('hidden folders are skipped',       !class_exists('LtHidden'));
try {
    class_exists('LtDup');
    $check('duplicate name → LogicException', false);
} catch (LogicException $e) {
    $check('duplicate name → LogicException', str_contains($e->getMessage(), 'A/LtDup.php') && str_contains($e->getMessage(), 'B/LtDup.php'));
}
$check('debug: no cache file',             !is_file("$root/storage/cache/lib-classmap.php"));
$rm($root);

// ── 本番（キャッシュあり） ───────────────────────────────────────────
$root = sys_get_temp_dir() . '/washi-lib-prod-' . getmypid();
$cache = "$root/storage/cache/lib-classmap.php";
$put("$root/lib/Models/LtProdA.php", 'class LtProdA {}');
$app = new App($root, ['debug' => false, 'log' => 'stderr']);
$check('prod: class loads',                class_exists('LtProdA'));
$check('prod: map cached',                 is_file($cache) && isset((include $cache)['LtProdA']));
$put("$root/lib/Services/LtProdB.php", 'class LtProdB {}');
$app = new App($root, ['debug' => false, 'log' => 'stderr']);   // 次のリクエスト（作り直しは 1 リクエスト 1 回まで）
$check('prod: new file after cache → rebuilt', class_exists('LtProdB') && isset((include $cache)['LtProdB']));
$app->clearClassMap();
$check('clearClassMap() deletes the cache', !is_file($cache));

// 別リクエスト相当: キャッシュに載っているパスが消えた（ファイルを移動した）ら作り直す
$put("$root/lib/Old/LtMoved.php", 'class LtMoved {}');
$app = new App($root, ['debug' => false, 'log' => 'stderr']);
class_exists('LtNope2');                                   // 対応表を作らせる（LtMoved は Old/ にある）
rename("$root/lib/Old/LtMoved.php", "$root/lib/LtMoved.php");
$app = new App($root, ['debug' => false, 'log' => 'stderr']);
$check('prod: stale cached path → rebuilt', class_exists('LtMoved') && (include $cache)['LtMoved'] === ["$root/lib/LtMoved.php"]);
$rm($root);

exit($fail ? 1 : 0);
