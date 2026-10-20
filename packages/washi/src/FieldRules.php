<?php

namespace Washi;

/**
 * The rules of one form field, built by `Validator::rule('email', 'メールアドレス')->required()->email()`.
 * Every rule method takes an optional message as its last argument (`:field` = the label).
 * Also used by `Validator::make()` / `$request->validate()` to turn 'max:100' into ->max(100).
 */
class FieldRules
{
    /** @var array<string,array{0:mixed,1:?string}> rule => [param, message]。同じルールは後勝ち */
    private array $rules = [];
    /** @var list<array{0:callable,1:?string}> */
    private array $customs = [];

    public function __construct(private readonly string $key, private readonly string $label) {}

    public function required(?string $m = null): static  { return $this->add('required', true, $m); }
    public function email(?string $m = null): static     { return $this->add('email', true, $m); }
    public function url(?string $m = null): static       { return $this->add('url', true, $m); }
    public function numeric(?string $m = null): static   { return $this->add('numeric', true, $m); }
    public function integer(?string $m = null): static   { return $this->add('integer', true, $m); }
    public function date(?string $m = null): static      { return $this->add('date', true, $m); }
    /** `{field}_confirmation` と一致すること（空でも検査する） */
    public function confirmed(?string $m = null): static { return $this->add('confirmed', true, $m); }
    /** 文字数（配列は要素数） */
    public function min(int $n, ?string $m = null): static { return $this->add('min', $n, $m); }
    public function max(int $n, ?string $m = null): static { return $this->add('max', $n, $m); }
    public function minValue(int|float $n, ?string $m = null): static { return $this->add('minValue', $n, $m); }
    public function maxValue(int|float $n, ?string $m = null): static { return $this->add('maxValue', $n, $m); }
    public function between(int|float $min, int|float $max, ?string $m = null): static { return $this->add('between', [$min, $max], $m); }
    public function regex(string $pattern, ?string $m = null): static { return $this->add('regex', $pattern, $m); }
    /** @param list<string|int> $values 値は文字列として比較する */
    public function in(array $values, ?string $m = null): static { return $this->add('in', array_map('strval', $values), $m); }

    /**
     * Custom check `fn(mixed $value, array $data): true|string|false` — skipped when the value is empty.
     * true = OK、文字列 = そのエラー文（`:field` 可）、それ以外 = $message か既定文。
     */
    public function with(callable $fn, ?string $message = null): static
    {
        $this->customs[] = [$fn, $message];
        return $this;
    }

    /** @internal Validator::make() の文字列ルール（'between:1,10'）をメソッド呼び出しにする */
    public function apply(string $name, ?string $arg): static
    {
        $num = static fn(string $s) => is_numeric($s) ? $s + 0 : throw new \LogicException("Rule '$name' needs a number, got '$s'");
        return match ($name) {
            'required', 'email', 'url', 'numeric', 'integer', 'date', 'confirmed' => $this->$name(),
            'min', 'max'           => $this->$name((int)$num((string)$arg)),
            'minValue', 'maxValue' => $this->$name($num((string)$arg)),
            'between'              => $this->between(...array_map($num, array_pad(explode(',', (string)$arg, 2), 2, ''))),
            'regex'                => $this->regex((string)$arg),
            'in'                   => $this->in(explode(',', (string)$arg)),
            default                => throw new \LogicException("Unknown validation rule '$name' for field '{$this->key}'"),
        };
    }

    private function add(string $rule, mixed $param, ?string $message): static
    {
        $this->rules[$rule] = [$param, $message];
        return $this;
    }

    /** @internal 最初に失敗したルールの文言。合格なら null */
    public function check(mixed $v, array $data): ?string
    {
        $empty = $v === null || $v === '' || $v === [];
        // 配列は min / max / required / in 以外では不合格（name="x[]" を送り付けて検査をすり抜けさせない）
        $scalar = is_scalar($v);
        foreach ($this->rules as $rule => [$p, $m]) {
            if ($empty && $rule !== 'required' && $rule !== 'confirmed') continue;
            $bad = match ($rule) {
                'required'  => $empty,
                'confirmed' => !is_scalar($data[$this->key . '_confirmation'] ?? '') || (string)($v ?? '') !== (string)($data[$this->key . '_confirmation'] ?? ''),
                'min'       => is_array($v) ? count($v) < $p : (!$scalar || mb_strlen((string)$v) < $p),
                'max'       => is_array($v) ? count($v) > $p : (!$scalar || mb_strlen((string)$v) > $p),
                'email'     => !$scalar || filter_var($v, FILTER_VALIDATE_EMAIL) === false,
                'url'       => !$scalar || filter_var($v, FILTER_VALIDATE_URL) === false,
                'numeric'   => !is_numeric($v),
                'integer'   => !$scalar || !preg_match('/^-?[0-9]+$/', (string)$v),
                'date'      => !$scalar || strtotime((string)$v) === false,
                'minValue'  => !is_numeric($v) || $v + 0 < $p,
                'maxValue'  => !is_numeric($v) || $v + 0 > $p,
                'between'   => !is_numeric($v) || $v + 0 < $p[0] || $v + 0 > $p[1],
                'regex'     => !$scalar || !preg_match($p, (string)$v),
                'in'        => (bool)array_filter(is_array($v) ? $v : [$v], static fn($x) => !is_scalar($x) || !in_array((string)$x, $p, true)),
            };
            if ($bad) {
                $vars = match ($rule) {
                    'min', 'minValue' => ['min' => $p],
                    'max', 'maxValue' => ['max' => $p],
                    'between'         => ['min' => $p[0], 'max' => $p[1]],
                    default           => [],
                };
                return $this->format($m ?? Validator::message($rule), $vars);
            }
        }
        if ($empty) return null;
        foreach ($this->customs as [$fn, $m]) {
            $r = $fn($v, $data);
            if ($r === true) continue;
            return $this->format(is_string($r) && $r !== '' ? $r : ($m ?? Validator::message('custom')));
        }
        return null;
    }

    private function format(string $text, array $vars = []): string
    {
        $vars['field'] = $this->label;
        foreach ($vars as $k => $v) $text = str_replace(':' . $k, (string)$v, $text);
        return $text;
    }
}
