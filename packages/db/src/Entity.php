<?php

namespace Washi\Db;

use Washi\Cast;
use Washi\Collection;

/**
 * Entity — a typed row of a database table (Active Record, minimal).
 *
 * Declare public typed properties that mirror the table columns; that is the whole "model".
 * No base folder, no registration: `class User extends Entity {}` anywhere autoloadable is enough.
 *
 * - Table name defaults to snake_case(ClassName) + 's'; override `protected static ?string $table`.
 * - Static finders (`find`, `where`, `all`, ...) return typed instances / `Collection<static>`.
 * - Instance methods `save()` / `delete()` / `fresh()` use dirty-tracking (only changed columns are UPDATEd).
 * - Extra SELECT columns (joins, aggregates) land in dynamic properties and are included in `toArray()`.
 * - Relations loaded via `Query::rel()` / `relMany()` live separately and never leak into UPDATE.
 * - `findOrFail()` throws {@see NotFoundException}; the HTTP layer maps it to 404.
 */
#[\AllowDynamicProperties]
abstract class Entity implements \JsonSerializable
{
    protected static ?string $connectionKey = null;
    protected static ?string $table = null;
    protected static ?string $primaryKey = 'id';

    private ?array $_original = null;
    /** @var array 宣言されていない列（JOIN 結果など） */
    private array $_dynamicProperties = [];
    /** @var array Eager Load されたリレーション（toArray()/getDirty() には含めない） */
    private array $_relations = [];

    public function __construct(mixed $data = null)
    {
        if ($data !== null) $this->merge($data);
    }

    /** 配列・オブジェクト・JSON文字列から生成 */
    public static function from(mixed $source): static
    {
        return new static($source);
    }

    /** 宣言済みプロパティにだけ値を流し込む（不明なキーは無視） */
    public function merge(mixed $data): static
    {
        foreach (Cast::extract($data) as $key => $value) {
            if (property_exists($this, $key) && !str_starts_with($key, '_')) $this->$key = $value;
        }
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (property_exists($this, $key) && !str_starts_with($key, '_')) return $this->$key;
        return $this->_relations[$key] ?? $this->_dynamicProperties[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        if (property_exists($this, $key) && !str_starts_with($key, '_')) $this->$key = $value;
        else $this->_dynamicProperties[$key] = $value;
        return $this;
    }

    public function has(string $key): bool
    {
        return (property_exists($this, $key) && !str_starts_with($key, '_'))
            || array_key_exists($key, $this->_dynamicProperties)
            || array_key_exists($key, $this->_relations);
    }

    /** 宣言済み public プロパティ + 動的列。リレーションは含まない */
    public function toArray(): array
    {
        $res = [];
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $name = $prop->getName();
            $res[$name] = $prop->isInitialized($this) ? $this->$name : null;
        }
        return array_merge($res, $this->_dynamicProperties);
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->_relations)) return $this->_relations[$name];
        return $this->_dynamicProperties[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->_dynamicProperties[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->_relations[$name]) || isset($this->_dynamicProperties[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->_relations[$name], $this->_dynamicProperties[$name]);
    }

    // =============================
    // 型付き取得（Cast 委譲）
    // =============================
    public function str(string $key, ?string $default = null): ?string { return Cast::asString($this->get($key), $default); }
    public function int(string $key, int $default = 0): int             { return Cast::asInt($this->get($key), $default); }
    public function float(string $key, float $default = 0.0): float     { return Cast::asFloat($this->get($key), $default); }
    public function bool(string $key, bool $default = false): bool      { return Cast::asBool($this->get($key), $default); }
    public function arr(string $key, array $default = []): array        { return Cast::asArray($this->get($key), $default); }
    public function date(string $key, mixed $default = null): ?\DateTime { return Cast::asDateTime($this->get($key), $default); }

    // =============================
    // テーブル / 接続
    // =============================

    /** テーブル名取得 */
    public static function tableName(): string
    {
        if (static::$table !== null) return static::$table;
        $class = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class)) . 's';
    }

    /** プライマリキー名取得 */
    public static function primaryKeyName(): string
    {
        return static::$primaryKey ?? 'id';
    }

    /** 接続取得 */
    protected static function connection(): Connection
    {
        $key = static::$connectionKey ?: 'default';
        return Db::connection($key);
    }

    /**
     * デフォルトスコープ（サブクラスでオーバーライドして定義する）
     *
     * query() 起点のすべてのクエリ（find/where/all/count、Eager Load の
     * リレーション先を含む）に自動適用される絞り込み。論理削除などに使う。
     *
     * 例:
     * ```php
     * protected static function defaultScope(Query $q): void {
     *     // JOIN 時の列名衝突を避けるためテーブル修飾を推奨
     *     $q->where(static::tableName() . '.is_deleted', 0);
     * }
     * ```
     *
     * 注意: スコープは UPDATE/DELETE にも効く。復元処理
     * （is_deleted を 0 に戻す等）や削除済み一覧は必ず
     * `query(scoped: false)` 経由で行うこと。`Db::table()` には適用されない。
     */
    protected static function defaultScope(Query $q): void {}

    /**
     * クエリビルダ取得
     *
     * @param  bool $scoped false でデフォルトスコープを適用しない
     * @return Query<static>
     */
    public static function query(bool $scoped = true): Query
    {
        $q = (new Query(static::connection(), static::tableName()))->entity(static::class);
        if ($scoped) static::defaultScope($q);
        return $q;
    }

    /**
     * 全取得
     *
     * @return \Washi\Collection<int, static>
     */
    public static function all(): \Washi\Collection
    {
        return static::query()->all();
    }

    /**
     * ID 指定で一件取得（見つからなければ 404 例外）
     *
     * @return static
     * @throws NotFoundException
     */
    public static function findOrFail(int|string $id): static
    {
        $entity = static::find($id);
        if ($entity === null) throw new NotFoundException(static::class . " not found: id={$id}");
        return $entity;
    }

    /**
     * ID 指定で一件取得
     *
     * @return static|null
     */
    public static function find(int|string $id): ?static
    {
        /** @var static|null */
        return static::query()->where(static::primaryKeyName(), $id)->first();
    }

    /**
     * 条件に一致する最初の1件を取得
     *
     * @return static|null
     */
    public static function first(string|array|\Closure $field, mixed $op = Query::OMITTED, mixed $value = Query::OMITTED): ?static
    {
        /** @var static|null */
        return static::where($field, $op, $value)->first();
    }

    /**
     * WHERE 検索（クエリビルダ継続）
     *
     * @return Query<static>
     */
    public static function where(string|array|\Closure $field, mixed $op = Query::OMITTED, mixed $value = Query::OMITTED): Query
    {
        return static::query()->where($field, $op, $value);
    }

    /** 件数取得 */
    public static function count(): int
    {
        return static::query()->count();
    }

    /** 存在確認（ID 指定） */
    public static function exists(int|string $id): bool
    {
        return static::query()->where(static::primaryKeyName(), $id)->exists();
    }

    /**
     * 条件に一致する最初のレコードを取得、なければ作成
     *
     * @param  array<string, mixed> $attributes 検索条件
     * @param  array<string, mixed> $values     新規作成時に追加するデータ
     * @return static
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $q = static::query();
        foreach ($attributes as $k => $v) {
            $q->where($k, $v);
        }
        /** @var static|null $existing */
        $existing = $q->first();
        if ($existing !== null) return $existing;

        $data = array_merge($attributes, $values);
        $id   = static::query()->insert($data);

        /** @var static */
        return static::find($id);
    }

    /**
     * INSERT（新規作成）
     *
     * @return int|string lastInsertId
     */
    public static function create(array $data): int|string
    {
        return static::query()->insert($data);
    }

    /**
     * UPDATE（ID 指定）
     *
     * @return int 更新行数
     */
    public static function updateById(int|string $id, array $data): int
    {
        return static::query()->where(static::primaryKeyName(), $id)->update($data);
    }

    /**
     * DELETE（ID 指定）
     *
     * @return int 削除行数
     */
    public static function deleteById(int|string $id): int
    {
        return static::query()->where(static::primaryKeyName(), $id)->delete();
    }

    // =============================
    // インスタンスメソッド（ActiveRecord 風）
    // =============================

    /** プライマリキー値取得 */
    public function pkValue(): mixed
    {
        $pk = static::primaryKeyName();
        return $this->$pk ?? null;
    }

    /** 現在の状態を初期状態として同期する */
    public function syncOriginal(): static
    {
        $this->_original = $this->toArray();
        return $this;
    }

    /** 変更されたデータ（Dirty Data）のみを取得 */
    public function getDirty(): array
    {
        $current = $this->toArray();
        // まだ初期状態がない（新規）場合は null 以外をすべて対象にする
        if ($this->_original === null) {
            return array_filter($current, fn($v) => $v !== null);
        }

        $dirty = [];
        foreach ($current as $key => $value) {
            // 変更がある場合のみ対象にする
            if (!array_key_exists($key, $this->_original) || $value !== $this->_original[$key]) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * 保存（PK があれば UPDATE、なければ INSERT）
     *
     * @return bool
     */
    public function save(): bool
    {
        $pk    = static::primaryKeyName();
        $pkVal = $this->pkValue();
        $isUpdate = ($pkVal !== null && $pkVal !== '' && $pkVal !== 0);

        if ($isUpdate) {
            // UPDATE: 変更があったデータのみを更新
            $dirty = $this->getDirty();
            if (empty($dirty)) return true; // 変更なし

            unset($dirty[$pk]);
            $ok = static::query()->where($pk, $pkVal)->update($dirty) >= 0;
            if ($ok) $this->syncOriginal();
            return $ok;
        }

        // INSERT: null 以外を対象（DB デフォルト値を活かす）
        $data = $this->toArray();
        if (isset($data[$pk]) && ($data[$pk] === null || $data[$pk] === '' || $data[$pk] === 0)) {
            unset($data[$pk]);
        }
        $insertData = array_filter($data, fn($v) => $v !== null);

        $newId = static::query()->insert($insertData);
        if ($newId) {
            $this->$pk = $newId;
            $this->syncOriginal();
        }
        return (bool)$newId;
    }

    /**
     * 削除（インスタンスの PK 値を使って DELETE）
     *
     * @return bool
     */
    public function delete(): bool
    {
        $pkVal = $this->pkValue();
        if ($pkVal === null || $pkVal === '' || $pkVal === 0) return false;
        return static::query()->where(static::primaryKeyName(), $pkVal)->delete() > 0;
    }

    /**
     * リロード（DB から最新値を再取得）
     *
     * @return static|null
     */
    public function fresh(): ?static
    {
        $pkVal = $this->pkValue();
        if ($pkVal === null) return null;
        return static::find($pkVal);
    }

    // =============================
    // リレーション（Query::rel() / relMany() の紐づけ先）
    // =============================

    /**
     * N:1 リレーション取得（rel() でロード済みのもの）
     *
     * クラスを渡すことで戻り値の型が確定し、IDE 補完と null セーフ演算子が使える:
     * `$kengaku->rel(Shisetsu::class)?->name`
     *
     * @template TRel of Entity
     * @param  class-string<TRel> $class
     * @param  string|null        $as rel()/relMany() で as を指定した場合は同じ名前を渡す
     * @return TRel|null 未ロード・該当なしは null
     */
    public function rel(string $class, ?string $as = null): ?Entity
    {
        $v = $this->_relations[$as ?? $class::tableName()] ?? null;
        return $v instanceof $class ? $v : null;
    }

    /**
     * 1:N リレーション取得（relMany() でロード済みのもの）
     *
     * `foreach ($kengaku->relMany(KengakuDay::class) as $day)` のように使う。
     *
     * @template TRel of Entity
     * @param  class-string<TRel> $class
     * @param  string|null        $as relMany() で as を指定した場合は同じ名前を渡す
     * @return \Washi\Collection<int, TRel> 未ロード時は空の Collection
     */
    public function relMany(string $class, ?string $as = null): \Washi\Collection
    {
        $v = $this->_relations[$as ?? $class::tableName()] ?? null;
        return $v instanceof \Washi\Collection ? $v : \Washi\Collection::make();
    }

    /** リレーション値を設定 */
    public function setRelation(string $name, mixed $value): static
    {
        $this->_relations[$name] = $value;
        return $this;
    }

    /** リレーション値を取得 */
    public function getRelation(string $name): mixed
    {
        return $this->_relations[$name] ?? null;
    }

    /** リレーションがロード済みか */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->_relations);
    }




    /**
     * JSON 化にはロード済みリレーションも含める（Collection は配列へ展開）
     */
    public function jsonSerialize(): mixed
    {
        $relations = array_map(
            fn($v) => $v instanceof \Washi\Collection ? $v->toArray() : $v,
            $this->_relations
        );
        return array_merge($this->toArray(), $relations);
    }
}
