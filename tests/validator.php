<?php
/**
 * バリデータ（Validator / FieldRules / $request->validate）、old() の既定値、abs_url()。
 * smoke.sh から呼ばれ、"ok <desc>" / "FAIL <desc>" を 1 行ずつ出す。
 *
 * 入口は 2 つ（宣言の make() とチェーンの rule()）だが判定は FieldRules 1 か所。両方から同じ結果になることも見る。
 */
require __DIR__ . '/../vendor/autoload.php';

use Washi\App;
use Washi\Request;
use Washi\ValidationException;
use Washi\Validator;

$fail = 0;
$check = function (string $desc, bool $cond) use (&$fail) {
    if ($cond) { echo "ok $desc\n"; } else { echo "FAIL $desc\n"; $fail++; }
};
$err = fn(array $input, array $rules, array $labels = []) => (($v = Validator::make($input, $rules, $labels))->validate() ? null : $v->firstError());

$root = sys_get_temp_dir() . '/washi-val-' . getmypid();
@mkdir($root);
$app = new App($root, ['log' => 'stderr', 'url' => '']);

// ── ルール ────────────────────────────────────────────────────────────
$check('required: empty → message with label', $err(['name' => '  '], ['name' => 'required'], ['name' => '氏名']) === '氏名を入力してください。');
$check('required: "0" is not empty',           $err(['n' => '0'], ['n' => 'required']) === null);
$check('optional field skips other rules',      $err(['mail' => ''], ['mail' => 'email|min:5']) === null);
$check('email',                                 $err(['m' => 'x@'], ['m' => 'email']) !== null && $err(['m' => 'a@example.com'], ['m' => 'email']) === null);
$check('max counts characters (utf-8)',         $err(['t' => 'あいう'], ['t' => 'max:3']) === null && $err(['t' => 'あいうえ'], ['t' => 'max:3'], ['t' => '件名']) === '件名は3文字以内で入力してください。');
$check('between is numeric',                    $err(['a' => '15'], ['a' => 'between:1,10']) !== null && $err(['a' => '10'], ['a' => 'between:1,10']) === null);
$check('integer rejects 1.5',                   $err(['i' => '1.5'], ['i' => 'integer']) !== null && $err(['i' => '-3'], ['i' => 'integer']) === null);
$check('minValue / maxValue',                   $err(['p' => '0'], ['p' => 'minValue:1']) !== null && $err(['p' => '101'], ['p' => 'maxValue:100']) !== null);
$check('in',                                    $err(['c' => 'b'], ['c' => 'in:a,b']) === null && $err(['c' => 'z'], ['c' => 'in:a,b']) !== null);
$check('regex in array spec keeps |',           $err(['z' => '123'], ['z' => ['regex:/^(abc|123)$/']]) === null);
$check('date',                                  $err(['d' => '2026-13-45x'], ['d' => 'date']) !== null && $err(['d' => '2026-10-20'], ['d' => 'date']) === null);
$check('confirmed: mismatch',                   $err(['pw' => 'a', 'pw_confirmation' => 'b'], ['pw' => 'confirmed']) !== null);
$check('confirmed: runs even when empty',       $err(['pw' => '', 'pw_confirmation' => 'x'], ['pw' => 'confirmed']) !== null);
$check('array input cannot pass email',         $err(['m' => ['a@example.com']], ['m' => 'email']) !== null);
$check('unknown rule is a LogicException',      (function () { try { Validator::make([], ['a' => 'requird']); return false; } catch (LogicException) { return true; } })());

// ── with()（独自検証） ───────────────────────────────────────────────
$check('with: string = message',                $err(['u' => 'taken'], ['u' => [fn($v) => $v !== 'taken' ?: ':fieldは使用済みです']], ['u' => 'ID']) === 'IDは使用済みです');
$check('with: skipped when empty',              $err(['u' => ''], ['u' => [fn() => 'never']]) === null);
$check('with: gets the whole input',            $err(['a' => '1', 'b' => '2'], ['a' => [fn($v, $d) => $v === $d['b'] ?: 'ne']]) === 'ne');

// ── チェーン形式（同じエンジン） ─────────────────────────────────────
$v = Validator::from([' name ' => 'x', 'name' => '  太郎  ', 'age' => 'abc']);
$v->rule('name', '氏名')->required()->max(10);
$v->rule('age', '年齢')->integer('数字で');
$check('chain: per-rule message',               !$v->validate() && $v->firstError('age') === '数字で');
$check('chain: input is trimmed',               $v->get('name') === '太郎');
$v->addError('もう一度お試しください');
$check('chain: addError() without key = form-level', $v->errors()[Validator::GLOBAL] === ['もう一度お試しください']);
$check('validated(): ruled + sent keys only',   Validator::make(['a' => '1', 'x' => 'y'], ['a' => 'required', 'b' => ''])->validated() === ['a' => '1']);

// ── 文言の差し替え ────────────────────────────────────────────────────
Validator::messages(['required' => ':field is required']);
$check('messages(): app-wide override',         $err([], ['t' => 'required'], ['t' => 'Title']) === 'Title is required');
Validator::messages(['required' => ':fieldを入力してください。']);

// ── $request->validate() → ValidationException → App の応答 ──────────
$app->post('/f', fn(Request $r) => $r->validate(['title' => 'required']) + ['ok' => true]);
$res = $app->handle(Request::create('POST', '/f', post: ['title' => ''], server: ['HTTP_REFERER' => 'http://h/form']));
$check('validate(): failure → back() to referer', $res->getStatus() === 302 && $res->getHeader('Location') === 'http://h/form');
$res = $app->handle(Request::create('POST', '/f', post: ['title' => ''], server: ['HTTP_ACCEPT' => 'application/json']));
$body = json_decode($res->getBody(), true);
$check('validate(): JSON client → 422 + errors', $res->getStatus() === 422 && ($body['errors']['title'][0] ?? '') === 'titleを入力してください。');
$res = $app->handle(Request::create('POST', '/f', post: ['title' => ' t ', 'evil' => 1]));
$check('validate(): success returns validated data', $res->getBody() === '{"title":"t","ok":true}');
$check('ValidationException is an HttpException(422)', (new ValidationException(Validator::make([], [])))->status === 422);

// ── old() の既定値は DB の値をそのまま渡せる ───────────────────────────
$check('old(): null default',   old('none', null) === '');
$check('old(): int default',    old('none', 42) === '42');
$check('old(): default escaped', old('none', '<b>') === '&lt;b&gt;');

// ── abs_url() ─────────────────────────────────────────────────────────
$app->get('/u', fn() => abs_url('/r/1', ['k' => 'v']));
$res = $app->handle(Request::create('GET', '/u', server: ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on']));
$check('abs_url(): from request host', $res->getBody() === 'https://example.test/r/1?k=v');
$app2 = new App($root, ['log' => 'stderr', 'url' => 'https://reserv.example.jp/app/']);
$check('abs_url(): APP_URL wins over Host', $app2->absoluteUrl('mail/confirm') === 'https://reserv.example.jp/app/mail/confirm');
$check('abs_url(): absolute passes through', $app2->absoluteUrl('https://other/x') === 'https://other/x');

@rmdir($root);
exit($fail ? 1 : 0);
