<?php
/**
 * where(fn) のグループ条件の検証。smoke.sh から呼ばれ、1 行 1 件で "ok <desc>" / "FAIL <desc>" を出す。
 *
 * ここだけ HTTP を使わず Query を直接叩く。検証したいのは SQL 文字列とプレースホルダ名で、
 * example アプリ越しでは観測しにくいため。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\Db\{Db, Connection, Query};

$db = tempnam(sys_get_temp_dir(), 'washi_q') . '.db';
register_shutdown_function(fn() => @unlink($db));
Db::addConnection('default', Connection::sqlite($db));
Db::execute('CREATE TABLE t (id INTEGER PRIMARY KEY, grp TEXT, pub INTEGER)');
foreach ([[1, 'a', 1], [2, 'b', 1], [3, 'c', 0]] as [$id, $g, $p]) {
    Db::table('t')->insert(['id' => $id, 'grp' => $g, 'pub' => $p]);
}

$sqlOf = function (Query $q): string {
    $m = (new ReflectionObject($q))->getMethod('buildSelect');
    $m->setAccessible(true);
    return $m->invoke($q);
};

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

// 外側の where と、グループ内の where でプレースホルダ名が衝突していた（:w0 が上書きされ 0 件になる）
$q = Db::table('t')->where('pub', 1)->where(fn($s) => $s->where('grp', 'a')->orWhere('grp', 'b'));
$check('group: outer placeholder not clobbered', $q->count() === 2);
$check('group: no duplicate :w0 binding', substr_count($sqlOf($q), ':w0') === 1);

// 順序を入れ替えても同じ
$check('group first, then outer where', Db::table('t')->where(fn($s) => $s->where('grp', 'a')->orWhere('grp', 'b'))->where('pub', 1)->count() === 2);

// 入れ子のグループは 'sql' キーを持たないため、以前は SQL に落ちず壊れた SQL になっていた
$nested = Db::table('t')->where('pub', 1)->where(fn($s) => $s->where('grp', 'a')->orWhere(fn($t) => $t->where('grp', 'b')->where('pub', 1)));
$check('nested group compiles', str_contains($sqlOf($nested), '(grp = :w1 OR (grp = :w2 AND pub = :w3))'));
$check('nested group result', $nested->count() === 2);

// 他の where 系と混在しても名前が衝突しない
$mixed = Db::table('t')->whereIn('grp', ['a', 'b'])->whereBetween('id', 1, 3)->whereLike('grp', '%a%')
    ->where(fn($s) => $s->where('pub', 1))->whereRaw('(id > ? OR id < ?)', [0, 99]);
$check('mixed where-types keep unique names', $mixed->count() === 1);

// 空のグループは WHERE を汚さない
$check('empty group ignored', !str_contains($sqlOf(Db::table('t')->where('pub', 1)->where(fn($s) => null)), '()'));

exit($fail === 0 ? 0 : 1);
