<?php
/**
 * Entity の保存対象は宣言済みプロパティ（＝列）だけ。JOIN で入った列や打ち間違いのプロパティは
 * toArray() には出るが INSERT / UPDATE には混ざらない。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を出す。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\Db\{Db, Connection, Entity};

class EcNote extends Entity
{
    protected static ?string $table = 'notes';
    public ?int $id = null;
    public string $title = '';
    public ?int $user_id = null;
}

$db = tempnam(sys_get_temp_dir(), 'washi_ec') . '.db';
register_shutdown_function(fn() => @unlink($db));
Db::addConnection('default', Connection::sqlite($db));
Db::execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER)');
Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
Db::table('users')->insert(['id' => 1, 'name' => 'Alice']);

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};
$saves = function (callable $fn): bool {
    try { return $fn() !== false; } catch (Throwable) { return false; }
};

// INSERT: 宣言していないプロパティは混ざらない（以前は "no such column" で失敗した）
$n = new EcNote(['title' => 'a', 'user_id' => 1]);
$n->titel = 'typo';
$check('insert: undeclared prop ignored', $saves(fn() => $n->save()) && $n->id !== null);
$check('insert: declared columns saved',  Db::table('notes')->where('id', $n->id)->asRaw()->first()?->title === 'a');

// JOIN の列は読めて toArray() に出るが、書き換えても UPDATE しない
$row = EcNote::query()->select('notes.*', 'users.name AS user_name')->join('users', 'users.id = notes.user_id')->first();
$check('join: extra column readable',     $row->user_name === 'Alice' && ($row->toArray()['user_name'] ?? null) === 'Alice');
$row->user_name = 'changed';
$row->title = 'b';
$check('getDirty: only declared columns', $row->getDirty() === ['title' => 'b']);
$check('update: succeeds with join cols', $saves(fn() => $row->save()));
$check('update: declared column saved',   Db::table('notes')->where('id', $n->id)->asRaw()->first()?->title === 'b');
$check('update: no change → no-op',       $row->getDirty() === []);

exit($fail === 0 ? 0 : 1);
