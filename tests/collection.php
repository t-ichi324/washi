<?php
/**
 * Collection の keyBy / groupBy / sum: 文字列は常に列名。PHP 関数名（date など）と同じ列名でも関数として呼ばない。
 * smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\Collection;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

$c = new Collection([
    ['date' => '2026-01-01', 'count' => 1],
    ['date' => '2026-01-01', 'count' => 2],
    ['date' => '2026-01-02', 'count' => 4],
]);

$check('keyBy: 関数名と同じ列名（date）', array_keys($c->keyBy('date')->toArray()) === ['2026-01-01', '2026-01-02']);
$check('groupBy: 関数名と同じ列名（date）', array_map('count', $c->groupBy('date')->toArray()) === ['2026-01-01' => 2, '2026-01-02' => 1]);
$check('sum: 関数名と同じ列名（count）', $c->sum('count') === 7);
$check('keyBy: クロージャ', array_keys($c->keyBy(fn($r) => $r['count'])->toArray()) === [1, 2, 4]);
$check('groupBy: クロージャ', array_keys($c->groupBy(fn($r) => $r['count'] % 2)->toArray()) === [1, 0]);
$check('sum: クロージャ', $c->sum(fn($r) => $r['count'] * 2) === 14);
$check('keyBy: オブジェクトの列', array_keys((new Collection([(object)['date' => 'a'], (object)['date' => 'b']]))->keyBy('date')->toArray()) === ['a', 'b']);

exit($fail === 0 ? 0 : 1);
