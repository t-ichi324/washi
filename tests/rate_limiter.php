<?php
/**
 * RateLimiter / FileRateLimitStore。smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 * ミドルウェア（->throttle() の 429 と Retry-After）は smoke.sh 側で実サーバー越しに見る。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\FileRateLimitStore;
use Washi\RateLimiter;
use Washi\RateLimitStore;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};

$dir = sys_get_temp_dir() . '/washi-rl-' . getmypid();
RateLimiter::store(new FileRateLimitStore($dir));

// 数える・判定・残り・解除
$check('hit: counts up',              RateLimiter::hit('a', 60) === 1 && RateLimiter::hit('a', 60) === 2);
$check('tooMany: below max',          !RateLimiter::tooMany('a', 3));
RateLimiter::hit('a', 60);
$check('tooMany: at max',             RateLimiter::tooMany('a', 3));
$check('remaining: never negative',   RateLimiter::remaining('a', 2) === 0 && RateLimiter::remaining('a', 5) === 2);
$check('availableIn: within window',  ($s = RateLimiter::availableIn('a')) > 55 && $s <= 60);
$check('keys are independent',        RateLimiter::remaining('b', 3) === 3 && RateLimiter::availableIn('b') === 0);
RateLimiter::clear('a');
$check('clear: back to zero',         !RateLimiter::tooMany('a', 1) && RateLimiter::hit('a', 60) === 1);
$check('odd key is safe as filename', RateLimiter::hit("../x/\0y|ユーザー", 60) === 1);

// 期間は最初の hit から。過ぎたら 0 回からやり直し、掃除で消える
RateLimiter::hit('short', 1);
RateLimiter::hit('short', 1);
sleep(2);
$check('expired: peek is zero',       !RateLimiter::tooMany('short', 1) && RateLimiter::availableIn('short') === 0);
$check('expired: restarts at 1',      RateLimiter::hit('short', 1) === 1);
sleep(2);
$before = count(glob("$dir/*.rl"));
(new FileRateLimitStore($dir))->gc();
$check('gc: removes only expired',    count(glob("$dir/*.rl")) === $before - 1);

// 同時アクセス: 別プロセス 8 本 × 25 回 = 200 回を取りこぼさない（flock）
$code = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';'
      . '$s = new Washi\FileRateLimitStore(' . var_export($dir, true) . '); for ($i = 0; $i < 25; $i++) $s->increment("race", 60);';
$procs = [];
for ($i = 0; $i < 8; $i++) $procs[] = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
foreach ($procs as $p) proc_close($p);
$check('concurrent hits all counted', (new FileRateLimitStore($dir))->peek('race')[0] === 200);

// 保存先の差し替え（DB 版などはこの形で書く）
$mem = new class implements RateLimitStore {
    public array $rows = [];
    public function increment(string $key, int $decaySeconds): array
    {
        [$n, $at] = $this->peek($key);
        $this->rows[$key] = $at ? [$n + 1, $at] : [1, time() + $decaySeconds];
        return $this->rows[$key];
    }
    public function peek(string $key): array { $r = $this->rows[$key] ?? [0, 0]; return $r[1] > time() ? $r : [0, 0]; }
    public function forget(string $key): void { unset($this->rows[$key]); }
};
RateLimiter::store($mem);
RateLimiter::hit('m', 60);
$check('custom store: used by facade', ($mem->rows['m'][0] ?? 0) === 1 && RateLimiter::store() === $mem);

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);
exit($fail ? 1 : 0);
