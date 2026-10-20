<?php

namespace Washi;

/**
 * Form validation. One engine, two ways in:
 *
 *   // 1. 宣言（失敗したら ValidationException → App が back() 相当の応答にする。if は要らない）
 *   $data = $r->validate([
 *       'email' => 'required|email|max:255',
 *       'age'   => ['integer', 'between:0,150'],
 *       'code'  => ['required', 'regex:/^[A-Z]{3}|[0-9]{3}$/', fn($v) => Code::exists($v) ?: 'コードが見つかりません'],
 *   ], ['email' => 'メールアドレス', 'age' => '年齢']);
 *
 *   // 2. チェーン（戻り先を選ぶ、エラーを足す、など手で制御したいとき）
 *   $v = Validator::from($r);
 *   $v->rule('email', 'メールアドレス')->required()->email();
 *   if (!$v->validate()) return $v->back('/form');
 *
 * Rules: required email url numeric integer date confirmed min max minValue maxValue between regex in, plus with(fn).
 * - required と confirmed 以外は「空（null / ''）なら検査しない」。必須にするなら required を併記する
 * - with(fn($value, $data)) も空なら呼ばない。true で合格、文字列はそのままエラー文、false は既定文
 * - min / max は文字数（配列なら要素数）、minValue / maxValue / between は数値
 * - フィールドごとに最初に失敗したルールの文言だけを持つ
 * Messages are Japanese; `Validator::messages(['required' => ':fieldは必須です'])` replaces them app-wide,
 * and every rule method takes its own message as the last argument.
 * Views show errors with err('email') / has_err('email') and the input with old('email').
 */
class Validator
{
    /** フィールドに紐づかないエラーのキー。back() では flash('error', …) になる */
    public const GLOBAL = '_global';

    private static array $messages = [
        'required'  => ':fieldを入力してください。',
        'email'     => ':fieldの形式が正しくありません。',
        'url'       => ':fieldは正しいURL形式で入力してください。',
        'numeric'   => ':fieldは数値で入力してください。',
        'integer'   => ':fieldは整数で入力してください。',
        'date'      => ':fieldは正しい日付形式で入力してください。',
        'confirmed' => ':fieldが一致しません。',
        'min'       => ':fieldは:min文字以上で入力してください。',
        'max'       => ':fieldは:max文字以内で入力してください。',
        'minValue'  => ':fieldは:min以上で入力してください。',
        'maxValue'  => ':fieldは:max以下で入力してください。',
        'between'   => ':fieldは:minから:maxの間で入力してください。',
        'regex'     => ':fieldの形式が正しくありません。',
        'in'        => ':fieldの値が正しくありません。',
        'custom'    => ':fieldが正しくありません。',
    ];

    private array $data;
    /** @var array<string,FieldRules> */
    private array $fields = [];
    /** @var array<string,list<string>> */
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = self::trim($data);
    }

    /** Validate the request input (GET + POST + JSON), or a plain array */
    public static function from(Request|array $input): static
    {
        return new static($input instanceof Request ? $input->all() : $input);
    }

    /**
     * Build from a rule spec: ['field' => 'required|max:10'] or ['field' => ['required', 'regex:/a|b/', fn($v) => …]].
     * 配列で書いた要素は `|` で分けない（正規表現に `|` を含めるときはこちら）。
     * @param array<string,string|array> $rules
     * @param array<string,string> $labels
     */
    public static function make(Request|array $input, array $rules, array $labels = []): static
    {
        $v = static::from($input);
        foreach ($rules as $key => $spec) {
            $field = $v->rule($key, $labels[$key] ?? null);
            foreach (is_string($spec) ? explode('|', $spec) : $spec as $rule) {
                if (is_callable($rule) && !is_string($rule)) { $field->with($rule); continue; }
                [$name, $arg] = array_pad(explode(':', (string)$rule, 2), 2, null);
                if ($name === '') continue;
                $field->apply($name, $arg);
            }
        }
        return $v;
    }

    /** Replace default messages app-wide (merged). Placeholders: :field :min :max */
    public static function messages(array $messages): void
    {
        self::$messages = $messages + self::$messages;
    }

    /** @internal FieldRules が文言を引く */
    public static function message(string $rule): string
    {
        return self::$messages[$rule] ?? self::$messages['custom'];
    }

    private static function trim(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) $data[$k] = trim($v);
            elseif (is_array($v)) $data[$k] = self::trim($v);
        }
        return $data;
    }

    public function rule(string $key, ?string $label = null): FieldRules
    {
        return $this->fields[$key] ??= new FieldRules($key, $label ?? $key);
    }

    public function validate(): bool
    {
        $this->errors = [];
        foreach ($this->fields as $key => $field) {
            $msg = $field->check($this->data[$key] ?? null, $this->data);
            if ($msg !== null) $this->addError($msg, $key);
        }
        return !$this->errors;
    }

    /** validate() して、失敗なら ValidationException（App が back() 相当にする）。成功なら validated() */
    public function orFail(): array
    {
        if (!$this->validate()) throw new ValidationException($this);
        return $this->validated();
    }

    /** ルールを付けたフィールドのうち、送られてきたものだけ（trim 済み）。無いキーを null で埋めない（Entity の string 型に null を入れない） */
    public function validated(): array
    {
        return array_intersect_key($this->data, $this->fields);
    }

    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function has(string $key): bool { return isset($this->data[$key]); }
    /** 入力全体（trim 済み） */
    public function all(): array { return $this->data; }

    /** Add an error by hand (e.g. after a DB check). Without $key it is a form-level error. */
    public function addError(string $text, ?string $key = null): static
    {
        if ($text !== '') $this->errors[$key ?? self::GLOBAL][] = $text;
        return $this;
    }

    public function hasError(?string $key = null): bool
    {
        return $key === null ? (bool)$this->errors : !empty($this->errors[$key]);
    }

    /** @return array<string,list<string>> */
    public function errors(): array { return $this->errors; }

    public function firstError(?string $key = null): ?string
    {
        if ($key !== null) return $this->errors[$key][0] ?? null;
        foreach ($this->errors as $msgs) return $msgs[0] ?? null;
        return null;
    }

    /**
     * Flash errors (+ input) and redirect: to $to, else the referer, else '/'.
     * フィールドのエラーは err('field')、フォーム全体のエラーは flash('error', …) で表示される。
     */
    public function back(?string $to = null, bool $withInput = true): Response
    {
        $fields = [];
        foreach ($this->errors as $key => $msgs) {
            if ($key === self::GLOBAL) {
                foreach ($msgs as $m) Session::flashPush('_messages', ['type' => 'error', 'text' => $m]);
            } else {
                $fields[$key] = $msgs[0];
            }
        }
        Session::flash('_errors', $fields);
        if ($withInput) Session::flash('_old', $this->data);
        $app = App::current();
        return Response::redirect($to !== null ? $app->url($to) : ($app->request()->referer() ?: $app->url('/')));
    }
}
