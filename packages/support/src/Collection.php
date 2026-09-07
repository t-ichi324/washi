<?php

namespace Washi;

/**
 * Collection Wrapper — provides a fluent, object-oriented interface for arrays.
 *
 * Use to manipulate lists of data with functional methods like map, filter, and pluck.
 * Typical uses: processing DB results, filtering lists of objects, aggregating data.
 *
 * - Implements `ArrayAccess`, `IteratorAggregate`, and `Countable` for native array-like feel.
 * - Provides chainable methods for common transformations.
 * - Supports Generics for type safety with static analyzers.
 * - Used as the base for {@see \Washi\Db\Paginated}.
 *
 * @template TKey of array-key
 * @template T
 * @implements \IteratorAggregate<TKey, T>
 * @implements \ArrayAccess<TKey, T>
 */
class Collection implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** @var array<TKey, T> */
    protected array $items;

    /**
     * コンストラクタ
     * @param array<TKey, T>|self<TKey, T>|mixed $items
     */
    public function __construct($items = [])
    {
        if ($items instanceof self) {
            $this->items = $items->toArray();
        } elseif (is_array($items)) {
            $this->items = $items;
        } else {
            $this->items = (array)$items;
        }
    }

    /**
     * 要素追加
     *
     * @param T $item
     * @param TKey|null $key 指定した場合はそのキーで追加、省略時は末尾追加
     */
    public function add($item, mixed $key = null): void
    {
        if ($key !== null) {
            $this->items[$key] = $item;
        } else {
            $this->items[] = $item;
        }
    }

    /**
     * インスタンス作成
     * @param array<TKey, T>|self<TKey, T>|mixed $items
     * @return static<TKey, T>
     */
    public static function make($items = []): self
    {
        return new static($items);
    }

    /**
     * 配列形式取得
     * @return array<TKey, T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * 内部ヘルパー：配列またはオブジェクトから安全に値を取得する
     * @param T $item
     * @param string $key
     * @return mixed
     */
    protected function _dataGet($item, string $key): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }
        if (is_object($item)) {
            return $item->$key ?? null;
        }
        return null;
    }

    /**
     * 全要素へコールバック適用
     * @template TNext
     * @param callable(T, TKey): TNext $callback
     * @return Collection<TKey, TNext>
     */
    public function map(callable $callback): self
    {
        // array_map に複数配列を渡すとキーが連番に振り直されるため foreach で保持する
        $results = [];
        foreach ($this->items as $key => $item) {
            $results[$key] = $callback($item, $key);
        }
        return new Collection($results);
    }

    /**
     * 要素フィルタリング
     * @param null|callable(T, TKey): bool $callback
     * @return static<TKey, T>
     */
    public function filter(null|callable $callback = null): self
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }
        return new static(array_filter($this->items));
    }

    /**
     * 条件一致要素フィルタリング
     * @param string $key
     * @param mixed $value
     * @return static<TKey, T>
     */
    public function where(string $key, mixed $value): self
    {
        return $this->filter(function ($item) use ($key, $value) {
            return $this->_dataGet($item, $key) == $value;
        });
    }

    /**
     * 特定カラムの値リスト取得
     * @template TNewKey of array-key
     * @template TNewValue
     * @param string $value
     * @param string|null $key
     * @return Collection<TNewKey, TNewValue|mixed>
     */
    public function pluck(string $value, ?string $key = null): self
    {
        $results = [];
        foreach ($this->items as $item) {
            $v = $this->_dataGet($item, $value);
            if (is_null($key)) {
                $results[] = $v;
            } else {
                $k = $this->_dataGet($item, $key);
                /** @var TNewKey $k */
                $results[$k] = $v;
            }
        }
        return new Collection($results);
    }

    /**
     * 特定カラムをキーとして再配置
     * @template TNewKey of array-key
     * @param string|callable(T): TNewKey $keyBy
     * @return static<TNewKey, T>
     */
    public function keyBy(string|callable $keyBy): self
    {
        $results = [];
        foreach ($this->items as $item) {
            $key = is_callable($keyBy) ? $keyBy($item) : $this->_dataGet($item, $keyBy);
            /** @var TNewKey $key */
            $results[$key] = $item;
        }
        return new static($results);
    }

    /**
     * 特定カラムでグループ化
     * @template TGroupKey of array-key
     * @param string|callable(T): TGroupKey $groupBy
     * @return Collection<TGroupKey, array<int, T>>
     */
    public function groupBy(string|callable $groupBy): self
    {
        $results = [];
        foreach ($this->items as $item) {
            $key = is_callable($groupBy) ? $groupBy($item) : $this->_dataGet($item, $groupBy);
            /** @var TGroupKey $key */
            $results[$key][] = $item;
        }
        return new Collection($results);
    }

    /**
     * 最初の一件取得
     * @template TDefault
     * @param null|callable(T, TKey): bool $callback
     * @param TDefault $default
     * @return T|TDefault
     */
    public function first(null|callable $callback = null, $default = null)
    {
        if ($callback === null) {
            if (empty($this->items)) return $default;
            return reset($this->items);
        }
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * 最後の一件取得
     * @template TDefault
     * @param null|callable(T, TKey): bool $callback
     * @param TDefault $default
     * @return T|TDefault
     */
    public function last(null|callable $callback = null, $default = null)
    {
        if ($callback === null) {
            if (empty($this->items)) return $default;
            return end($this->items);
        }
        return (new static(array_reverse($this->items, true)))->first($callback, $default);
    }

    /** 合計算出 */
    public function sum(null|string|callable $callback = null): float|int
    {
        if ($callback === null) {
            return array_sum($this->items);
        }
        $sum = 0;
        foreach ($this->items as $item) {
            $sum += is_callable($callback) ? $callback($item) : $this->_dataGet($item, $callback);
        }
        return $sum;
    }

    /** 平均算出 */
    public function avg(null|string|callable $callback = null): float|int
    {
        $count = $this->count();
        if ($count === 0) return 0;
        return $this->sum($callback) / $count;
    }

    /** 要素数取得 */
    public function count(): int
    {
        return count($this->items);
    }

    /** 空判定 */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /** 非空判定 */
    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }

    /**
     * 値リスト取得
     * @return static<int, T>
     */
    public function values(): self
    {
        return new static(array_values($this->items));
    }

    /**
     * キーリスト取得
     * @return Collection<int, TKey>
     */
    public function keys(): self
    {
        return new Collection(array_keys($this->items));
    }

    /**
     * イテレータ取得
     * @return \ArrayIterator<TKey, T>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /** ArrayAccess: 存在確認 */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * ArrayAccess: 取得
     * @param TKey $offset
     * @return T
     */
    public function offsetGet($offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * ArrayAccess: 設定
     * @param TKey|null $offset
     * @param T $value
     */
    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /** ArrayAccess: 削除 */
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }
}
