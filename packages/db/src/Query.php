<?php

namespace Washi\Db;

use Washi\Collection;
use Washi\Db\Paginated;

/**
 * Query Builder — fluent interface for building SQL queries programmatically.
 *
 * Use to build complex SELECT, INSERT, UPDATE, or DELETE queries without writing raw SQL.
 * Typical uses: dynamic searching, filtering, bulk updates, paginated listings.
 *
 * - Supports method chaining for `where`, `join`, `order`, `group`, and `limit`.
 * - Handles parameter binding automatically to prevent SQL injection.
 * - Can return results as raw objects, arrays, or mapped {@see Entity} objects.
 * - Supports nested `WHERE` conditions and complex subqueries.
 *
 * @template T of object
 */
class Query
{
    /** where() の省略引数とユーザー値 null を区別するためのセンチネル */
    public const OMITTED = "\0washi.omitted";

    protected Connection $connection;
    protected ?string $table = null;
    /** @var class-string<T>|null */
    protected ?string $fetchClass = null;

    protected array $select = ['*'];
    protected array $where = [];
    protected array $params = [];
    protected ?string $orderBy = null;
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected ?string $groupBy = null;
    protected ?string $having = null;
    protected array $joins = [];
    protected bool $distinct = false;
    protected array $eagerLoads = [];

    /**
     * @param Connection $connection
     * @param string $table
     * @param class-string<T>|null $fetchClass
     */
    public function __construct(Connection $connection, ?string $table = null, ?string $fetchClass = null)
    {
        $this->connection = $connection;
        $this->table      = $table;
        $this->fetchClass = $fetchClass;
    }
    /**
     * テーブル指定
     *
     * @return self<T>
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * 取得結果に適用するクラス名(Entity)を設定
     *
     * @template T2 of object
     * @param  class-string<T2>|T2 $entity_or_className
     * @return self<T2>
     */
    public function entity(mixed $entity_or_className): self
    {
        $this->fetchClass = is_string($entity_or_className) ? $entity_or_className : get_class($entity_or_className);
        /** @var self<T2> $this */
        return $this;
    }

    /**
     * SELECT 列指定
     *
     * @return self<T>
     */
    public function select(string ...$columns): self
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * 結果を stdClass のコレクションで返す（fetchClass を無視）
     *
     * GROUP BY + 集計カラムなど、Entity にマップできない結果に使う。
     *
     * @return self<\stdClass>
     */
    public function asRaw(): self
    {
        $this->fetchClass = null;
        /** @var self<\stdClass> $this */
        return $this;
    }

    /**
     * 結果を任意クラスにマップして返す（Entity 以外のDTO用）
     *
     * stdClass の代わりに指定クラスを FETCH_CLASS でキャストする。
     * コンストラクタは呼ばれない（FETCH_PROPS_LATE）。
     *
     * @template T2 of object
     * @param  class-string<T2> $class
     * @return self<T2>
     */
    public function asClass(string $class): self
    {
        $this->fetchClass = $class;
        /** @var self<T2> $this */
        return $this;
    }
    
    /**
     * DISTINCT
     *
     * @return self<T>
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * WHERE 条件追加（AND）
     *
     * サポートされている演算子:
     * `=`, `!=`, `<>`, `>`, `<`, `>=`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `IS`, `IS NOT`
     *
     * 使用例:
     * - `where('col', $val)`           → `col = val`
     * - `where('col', '!=', $val)`     → `col != val`
     * - `where('col', null)`           → `col IS NULL`
     * - `where('col', '!=', null)`     → `col IS NOT NULL`
     * - `where('col', [1,2,3])`        → `col IN (1,2,3)` (配列を自動検出)
     * - `where(['col1' => $v1, ...])`  → `col1 = v1 AND ...`
     * - `where(function($q){...})`     → グループ条件 `(cond1 AND cond2)`
     *
     * @param  string|array|\Closure $field
     * @return self<T>
     */
    public function where(string|array|\Closure $field, mixed $op = self::OMITTED, mixed $value = self::OMITTED): self
    {
        if ($field instanceof \Closure) {
            $sub = new self($this->connection, $this->table);
            $field($sub);
            if (!empty($sub->where)) {
                $this->where[]  = ['type' => 'group', 'conditions' => $sub->where, 'connector' => 'AND'];
                $this->params   = array_merge($this->params, $sub->params);
            }
            return $this;
        }

        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $this->where($k, '=', $v);
            }
            return $this;
        }

        // 引数形式の正規化（センチネルで「省略」と「null 値」を区別）
        if ($value === self::OMITTED) {
            // where('col', $val) / where('col')
            $value = ($op === self::OMITTED) ? null : $op;
            $op    = '=';
        } elseif ($op === self::OMITTED) {
            $op = '=';
        }

        $op = strtoupper(trim((string)$op));

        // 配列 → IN / NOT IN へ自動変換
        if (is_array($value)) {
            if ($op === '!=' || $op === '<>') {
                return $this->whereNotIn($field, $value);
            }
            return $this->whereIn($field, $value);
        }

        if ($value === null) {
            $sql = ($op === '!=' || $op === '<>') ? "{$field} IS NOT NULL" : "{$field} IS NULL";
            $this->where[] = ['type' => 'raw', 'sql' => $sql];
        } else {
            $placeholder = ':w' . count($this->params);
            $this->where[] = ['type' => 'condition', 'sql' => "{$field} {$op} {$placeholder}", 'connector' => 'AND'];
            $this->params[$placeholder] = $value;
        }

        return $this;
    }

    /**
     * OR WHERE
     *
     * @return self<T>
     */
    public function orWhere(string|array|\Closure $field, mixed $op = self::OMITTED, mixed $value = self::OMITTED): self
    {
        $prevCount = count($this->where);
        $this->where($field, $op, $value);
        if (count($this->where) > $prevCount) {
            $last = array_pop($this->where);
            $last['connector'] = 'OR';
            $this->where[] = $last;
        }
        return $this;
    }

    /**
     * WHERE IN
     *
     * @return self<T>
     */
    public function whereIn(string $field, array $values): self
    {
        if (empty($values)) {
            $this->where[] = ['type' => 'raw', 'sql' => '0=1'];
            return $this;
        }
        $placeholders = [];
        foreach ($values as $v) {
            $k = ':wi' . count($this->params);
            $placeholders[] = $k;
            $this->params[$k] = $v;
        }
        $this->where[] = ['type' => 'raw', 'sql' => "{$field} IN (" . implode(',', $placeholders) . ")", 'connector' => 'AND'];
        return $this;
    }

    /**
     * WHERE NOT IN
     *
     * @return self<T>
     */
    public function whereNotIn(string $field, array $values): self
    {
        if (empty($values)) return $this;
        $placeholders = [];
        foreach ($values as $v) {
            $k = ':wni' . count($this->params);
            $placeholders[] = $k;
            $this->params[$k] = $v;
        }
        $this->where[] = ['type' => 'raw', 'sql' => "{$field} NOT IN (" . implode(',', $placeholders) . ")", 'connector' => 'AND'];
        return $this;
    }

    /**
     * WHERE BETWEEN
     *
     * @return self<T>
     */
    public function whereBetween(string $field, mixed $min, mixed $max): self
    {
        $k1 = ':wb' . count($this->params);
        $this->params[$k1] = $min;
        $k2 = ':wb' . count($this->params);
        $this->params[$k2] = $max;
        $this->where[] = ['type' => 'raw', 'sql' => "{$field} BETWEEN {$k1} AND {$k2}"];
        return $this;
    }

    /**
     * WHERE LIKE
     *
     * @return self<T>
     */
    public function whereLike(string $field, string $pattern): self
    {
        $k = ':wl' . count($this->params);
        $this->params[$k] = $pattern;
        $this->where[] = ['type' => 'condition', 'sql' => "{$field} LIKE {$k}", 'connector' => 'AND'];
        return $this;
    }

    /**
     * WHERE RAW
     *
     * @return self<T>
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        if (empty($bindings)) {
            $this->where[] = ['type' => 'raw', 'sql' => $sql];
            return $this;
        }

        if (!array_is_list($bindings)) {
            $this->where[] = ['type' => 'raw', 'sql' => $sql];
            $this->params  = array_merge($this->params, $bindings);
            return $this;
        }

        // ? を名前付きパラメータへ置換
        $mapped        = [];
        $bindIndex     = 0;
        $newSql        = '';
        $inString      = false;
        $stringChar    = '';
        $escaped       = false;
        $baseParamIndex = count($this->params);

        for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
            $char = $sql[$i];
            if ($escaped) {
                $newSql .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                $newSql .= $char;
                continue;
            }
            if ($inString) {
                if ($char === $stringChar) $inString = false;
                $newSql .= $char;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $inString = true;
                $stringChar = $char;
                $newSql .= $char;
                continue;
            }
            if ($char === '?') {
                if (array_key_exists($bindIndex, $bindings)) {
                    $k = ':wr' . ($baseParamIndex + $bindIndex);
                    $mapped[$k] = $bindings[$bindIndex];
                    $newSql .= $k;
                    $bindIndex++;
                } else {
                    $newSql .= $char;
                }
                continue;
            }
            $newSql .= $char;
        }

        $this->where[] = ['type' => 'raw', 'sql' => $newSql];
        $this->params  = array_merge($this->params, $mapped);
        return $this;
    }

    /**
     * JOIN
     *
     * @return self<T>
     */
    public function join(string $table, string $on, string $type = 'INNER'): self
    {
        $this->joins[] = "{$type} JOIN {$table} ON {$on}";
        return $this;
    }

    /**
     * LEFT JOIN
     *
     * @return self<T>
     */
    public function leftJoin(string $table, string $on): self
    {
        return $this->join($table, $on, 'LEFT');
    }

    /**
     * RIGHT JOIN
     *
     * @return self<T>
     */
    public function rightJoin(string $table, string $on): self
    {
        // 注意: SQLite 3.39.0 未満は RIGHT JOIN 非対応（LEFT JOIN への書き換えを推奨）
        return $this->join($table, $on, 'RIGHT');
    }

    /**
     * リレーション一括ロード: N:1（belongsTo 相当 / Eager Loading で N+1 回避）
     *
     * JOIN は使わず、メインクエリ実行後に関連テーブルを WHERE IN の
     * 1 クエリでまとめて取得し、各行へ紐づける。
     * `all()` / `first()` / `page()` のいずれでも適用される。
     * 取得側は `$entity->rel(Shisetsu::class)?->name` または
     * `$entity->{紐づけ名}`（紐づけ名は省略時、相手のテーブル名）。
     *
     * 使用例:
     * - `Kengaku::query()->rel(Shisetsu::class)->all()`
     *     （kengaku.shisetsu_id → shisetsu.id をキー名から自動推測）
     * - `->rel(Shisetsu::class, 'id', 'shisetsu_id')`（キーを明示する場合）
     * - クロージャだけ渡すときは名前付き引数が読みやすい:
     *     `->rel(Shisetsu::class, query: fn($q) => $q->where('is_deleted', 0))`
     * - 同一クラス複数紐づけは as で名前を分ける:
     *     `->rel(KengakuDay::class, localKey: 'moved_day_id', as: 'movedDay')`
     *
     * @template TRel of Entity
     * @param  class-string<TRel> $class      関連エンティティクラス
     * @param  string|null        $foreignKey 関連テーブル側のキー列（省略時: 相手の PK）
     * @param  string|null        $localKey   メインテーブル側のキー列（省略時: 相手テーブル名_id）
     * @param  \Closure|null      $query      関連クエリの変形 function(Query $q): void
     * @param  string|null        $as         紐づけ名（省略時: 相手テーブル名）
     * @return self<T>
     */
    public function rel(string $class, ?string $foreignKey = null, ?string $localKey = null, ?\Closure $query = null, ?string $as = null, bool $scoped = true): self
    {
        if (!is_subclass_of($class, Entity::class)) {
            throw new \InvalidArgumentException("rel(): {$class} は " . Entity::class . " のサブクラスではありません");
        }
        $this->eagerLoads[] = [
            'name'       => $as ?? $class::tableName(),
            'class'      => $class,
            'localKey'   => $localKey ?? $class::tableName() . '_id',
            'foreignKey' => $foreignKey ?? $class::primaryKeyName(),
            'many'       => false,
            'query'      => $query,
            'scoped'     => $scoped,
        ];
        return $this;
    }

    /**
     * リレーション一括ロード: 1:N（hasMany 相当）
     *
     * 各行に関連レコードの Collection を紐づける。
     * 取得側は `$entity->relMany(KengakuDay::class)` または `$entity->{紐づけ名}`。
     *
     * 使用例:
     * - `Kengaku::query()->relMany(KengakuDay::class)->all()`
     *     （kengaku.id ← kengaku_day.kengaku_id をテーブル名から自動推測）
     * - `->relMany(KengakuDay::class, query: fn($q) => $q->orderBy('event_date'))`
     *
     * @template TRel of Entity
     * @param  class-string<TRel> $class      関連エンティティクラス
     * @param  string|null        $foreignKey 関連テーブル側のキー列（省略時: 自テーブル名_id）
     * @param  string|null        $localKey   メインテーブル側のキー列（省略時: 自分の PK）
     * @param  \Closure|null      $query      関連クエリの変形 function(Query $q): void
     * @param  string|null        $as         紐づけ名（省略時: 相手テーブル名）
     * @param  bool               $scoped     false で関連取得の defaultScope を適用しない
     * @return self<T>
     */
    public function relMany(string $class, ?string $foreignKey = null, ?string $localKey = null, ?\Closure $query = null, ?string $as = null, bool $scoped = true): self
    {
        if (!is_subclass_of($class, Entity::class)) {
            throw new \InvalidArgumentException("relMany(): {$class} は " . Entity::class . " のサブクラスではありません");
        }
        if ($foreignKey === null && $this->table === null) {
            throw new \InvalidArgumentException('relMany(): テーブル未指定のため foreignKey を推測できません。foreignKey を明示してください');
        }
        $ownPk = ($this->fetchClass !== null && is_subclass_of($this->fetchClass, Entity::class))
            ? $this->fetchClass::primaryKeyName()
            : 'id';
        $this->eagerLoads[] = [
            'name'       => $as ?? $class::tableName(),
            'class'      => $class,
            'localKey'   => $localKey ?? $ownPk,
            'foreignKey' => $foreignKey ?? $this->table . '_id',
            'many'       => true,
            'query'      => $query,
        ];
        return $this;
    }

    /**
     * ORDER BY
     *
     * @return self<T>
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        // 'name DESC' のように方向まで書かれた生指定はそのまま使う（二重に方向を付けない）
        if (preg_match('/\s+(ASC|DESC)\s*$/i', $column)) {
            $expr = $column;
        } else {
            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $expr = $this->quoteIdentifier($column) . " {$direction}";
        }
        $this->orderBy = ($this->orderBy ? $this->orderBy . ', ' : '') . $expr;
        return $this;
    }

    /**
     * GROUP BY
     *
     * @return self<T>
     */
    public function groupBy(string $column): self
    {
        $this->groupBy = $column;
        return $this;
    }

    /**
     * HAVING
     *
     * @return self<T>
     */
    public function having(string $sql): self
    {
        $this->having = $sql;
        return $this;
    }

    /**
     * LIMIT
     *
     * @return self<T>
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * OFFSET
     *
     * @return self<T>
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * ページネーション
     *
     * @return Paginated<int, T|\stdClass>
     */
    public function page(int $page, int $perPage = 20): Paginated
    {
        $page         = max(1, $page);
        $this->limit  = $perPage;
        $this->offset = ($page - 1) * $perPage;

        $stmt  = $this->run($this->buildCountSql(), $this->params, Db::KIND_READ);
        $total = (int)$stmt->fetchColumn();

        $rows = $this->all();
        return new Paginated($rows, $total, $page, $perPage);
    }

    // =============================
    // 実行系
    // =============================

    /**
     * 全行取得
     *
     * @param  string|null $keyBy 指定したカラムをコレクションのキーにする
     * @return \Washi\Collection<int|string, T|\stdClass>
     */
    public function all(?string $keyBy = null): \Washi\Collection
    {
        $result = $this->executeSelect($this->buildSelect());
        return $keyBy !== null ? $result->keyBy($keyBy) : $result;
    }

    /**
     * 1行取得（clone で実行するため LIMIT 等の副作用なし）
     *
     * @return T|\stdClass|null
     */
    public function first(?object $default = null): ?object
    {
        $q = clone $this;
        $q->limit = 1;
        $rows = $q->executeSelect($q->buildSelect());
        return $rows[0] ?? $default;
    }

    /**
     * 1値取得（SELECT/LIMIT を clone で副作用なし）
     */
    public function getValue(string $column): mixed
    {
        $q = clone $this;
        $q->select = [$column];
        $q->limit  = 1;
        return $this->run($q->buildSelect(), $q->params, Db::KIND_READ)->fetchColumn();
    }

    /** 件数取得 */
    public function count(): int
    {
        return (int)$this->run($this->buildCountSql(), $this->params, Db::KIND_READ)->fetchColumn();
    }

    /** SUM */
    public function sum(string $column): float|int
    {
        return $this->getValue("SUM({$column})") ?? 0;
    }

    /** MAX */
    public function max(string $column): mixed
    {
        return $this->getValue("MAX({$column})");
    }

    /** MIN */
    public function min(string $column): mixed
    {
        return $this->getValue("MIN({$column})");
    }

    /** 平均値取得 */
    public function average(string $column): float|int
    {
        return $this->getValue("AVG({$column})") ?? 0;
    }

    /** 存在確認 */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /** 不存在確認 */
    public function notExists(): bool
    {
        return $this->count() === 0;
    }

    /**
     * 連想配列（Key-Value）形式で取得（clone で副作用なし）
     *
     * @return array<string, mixed>
     */
    public function getKeyValues(string $keyColumn, string $valueColumn): array
    {
        $q = clone $this;
        $q->select = ["{$keyColumn} AS _kv_k", "{$valueColumn} AS _kv_v"];
        $rows = $this->run($q->buildSelect(), $q->params, Db::KIND_READ)->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['_kv_k']] = $row['_kv_v'];
        }
        return $result;
    }

    /**
     * 単一カラム値をリストで取得（clone で副作用なし）
     *
     * @param  mixed $default NULL 時の代替値
     * @return array<int, mixed>
     */
    public function getValues(string $column, mixed $default = null): array
    {
        $q = clone $this;
        $q->select = ["{$column} AS _col_v"];
        $rows = $this->run($q->buildSelect(), $q->params, Db::KIND_READ)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($r) => $r['_col_v'] ?? $default, $rows);
    }

    /**
     * 条件付きでクエリを変形する（値が truthy のときだけ callback を適用）
     *
     * @param  mixed    $value    評価する値
     * @param  callable $callback function(self $q, mixed $value): void
     * @return self<T>
     */
    public function when(mixed $value, callable $callback): self
    {
        if ($value !== null && $value !== '' && $value !== false) {
            $callback($this, $value);
        }
        return $this;
    }

    /**
     * チェーンを切らずにコールバックを挿入する（デバッグ・ログ用）
     *
     * @param  callable $callback function(self $q): void
     * @return self<T>
     */
    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    /**
     * デバッグ用: SQL 文とパラメータを配列で返す
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function toSql(): array
    {
        return [$this->buildSelect(), $this->params];
    }

    /**
     * デバッグ用: SQL 文を画面出力してチェーンを継続
     *
     * @return static
     */
    public function dump(): static
    {
        [$sql, $params] = $this->toSql();
        echo "<pre style='background:#f4f4f4;border-left:5px solid #333;padding:10px;margin:10px 0'>";
        echo "<b>[SQL]</b> " . htmlspecialchars($sql, ENT_QUOTES) . "\n";
        echo "<b>[Params]</b> " . htmlspecialchars(json_encode($params, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        echo "</pre>";
        return $this;
    }

    // =============================
    // 更新系
    // =============================

    /**
     * INSERT（挿入 ID を返す）
     *
     * @return int|string lastInsertId
     */
    public function insert(array $data): int|string
    {
        $quotedColumns = array_map(fn($c) => $this->quoteIdentifier($c), array_keys($data));
        $columns       = implode(', ', $quotedColumns);
        $placeholders  = [];
        $params        = [];
        foreach ($data as $k => $v) {
            $ph = ':i_' . preg_replace('/[^A-Za-z0-9_]/', '_', $k);
            $placeholders[] = $ph;
            $params[$ph]    = $v;
        }
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->table) . " ({$columns}) VALUES (" . implode(', ', $placeholders) . ')';
        $this->run($sql, $params, Db::KIND_WRITE);
        return $this->connection->getPdo()->lastInsertId();
    }

    /**
     * 一括 INSERT（複数行）
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return int 挿入行数
     */
    public function insertMany(array $rows): int
    {
        if (empty($rows)) return 0;

        $columns       = array_keys($rows[0]);
        $quotedColumns = array_map(fn($c) => $this->quoteIdentifier($c), $columns);
        $allPlaceholders = [];
        $params        = [];

        foreach ($rows as $i => $row) {
            $rowPh = [];
            foreach ($columns as $col) {
                $ph = ':im_' . $i . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $col);
                $rowPh[]   = $ph;
                $params[$ph] = $row[$col] ?? null;
            }
            $allPlaceholders[] = '(' . implode(', ', $rowPh) . ')';
        }

        $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->table) . ' (' . implode(', ', $quotedColumns) . ') VALUES ' . implode(', ', $allPlaceholders);
        return $this->run($sql, $params, Db::KIND_WRITE)->rowCount();
    }

    /**
     * UPSERT（INSERT or UPDATE ON DUPLICATE KEY）
     *
     * MySQL / SQLite 3.24+ に対応。PostgreSQL は ON CONFLICT を使用。
     *
     * @param  array<string, mixed> $data       挿入データ
     * @param  array<string>        $uniqueKeys 重複判定キー（PK やユニークキー）
     * @return int 影響行数
     */
    public function upsert(array $data, array $uniqueKeys): int
    {
        $driver        = $this->connection->getDriver();
        $columns       = array_keys($data);
        $quotedColumns = array_map(fn($c) => $this->quoteIdentifier($c), $columns);
        $params        = [];
        $placeholders  = [];

        foreach ($data as $k => $v) {
            $ph = ':us_' . preg_replace('/[^A-Za-z0-9_]/', '_', $k);
            $placeholders[] = $ph;
            $params[$ph]    = $v;
        }

        if ($driver === 'mysql') {
            $updateSets = [];
            foreach ($columns as $col) {
                if (!in_array($col, $uniqueKeys, true)) {
                    $q = $this->quoteIdentifier($col);
                    $updateSets[] = "{$q} = VALUES({$q})";
                }
            }
            $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->table) . ' (' . implode(', ', $quotedColumns) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateSets);
        } else {
            // SQLite / PostgreSQL
            $quotedKeys  = array_map(fn($k) => $this->quoteIdentifier($k), $uniqueKeys);
            $updateSets  = [];
            foreach ($columns as $col) {
                if (!in_array($col, $uniqueKeys, true)) {
                    $q = $this->quoteIdentifier($col);
                    $updateSets[] = "{$q} = excluded.{$q}";
                }
            }
            $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->table) . ' (' . implode(', ', $quotedColumns) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')'
                . ' ON CONFLICT (' . implode(', ', $quotedKeys) . ')'
                . ' DO UPDATE SET ' . implode(', ', $updateSets);
        }

        return $this->run($sql, $params, Db::KIND_WRITE)->rowCount();
    }

    /**
     * UPDATE（更新行数を返す）
     *
     * @return int 更新行数
     */
    public function update(array $data): int
    {
        $sets   = [];
        $params = [];
        foreach ($data as $k => $v) {
            $ph = ':u_' . preg_replace('/[^A-Za-z0-9_]/', '_', $k);
            $sets[]     = $this->quoteIdentifier($k) . " = {$ph}";
            $params[$ph] = $v;
        }
        $params = array_merge($params, $this->params);
        $sql    = 'UPDATE ' . $this->quoteIdentifier($this->table) . ' SET ' . implode(', ', $sets) . $this->buildWhere();
        return $this->run($sql, $params, Db::KIND_WRITE)->rowCount();
    }

    /**
     * DELETE（削除行数を返す）
     *
     * @return int 削除行数
     */
    public function delete(): int
    {
        $sql = 'DELETE FROM ' . $this->quoteIdentifier($this->table) . $this->buildWhere();
        return $this->run($sql, $this->params, Db::KIND_WRITE)->rowCount();
    }

    /**
     * 大量データを chunk 件ずつ分割処理
     *
     * 注意: OFFSET ベースのページングのため、コールバック内で処理対象の行を
     * 更新・削除して WHERE 条件から外れると、後続チャンクで行がスキップされる。
     * そのような用途では PK 範囲で絞り込む（keyset 方式）こと。
     *
     * @param  int      $size     1回あたりの取得件数
     * @param  callable $callback function(array<T> $rows): bool|void  false を返すと中断
     */
    public function chunk(int $size, callable $callback): void
    {
        $offset = 0;
        do {
            $q = clone $this;
            $q->limit  = $size;
            $q->offset = $offset;
            $rows = $q->all();
            // 修正箇所: empty($rows) ではなく isEmpty() を使う
            if ($rows->isEmpty()) break;
            $result = $callback($rows);
            if ($result === false) break;
            $offset += $size;
            // 取得件数が指定サイズより少なければ、そこで終了
        } while ($rows->count() === $size);
    }

    // =============================
    // ビルダー（内部）
    // =============================

    /**
     * prepare → execute → リスナー通知（Db::listen）を一括で行う実行ヘルパ
     *
     * @param int $kind Db::KIND_READ / Db::KIND_WRITE
     */
    protected function run(string $sql, array $params, string $kind): \PDOStatement
    {
        return Db::runOn($this->connection, $sql, $params, $kind);
    }

    /**
     * 件数取得用 SQL を構築
     *
     * groupBy / having / distinct がある場合はサブクエリに包んで
     * 「グループ数」を数える（そのまま COUNT(*) すると全行数になるため）。
     */
    protected function buildCountSql(): string
    {
        $base = 'FROM ' . $this->quoteIdentifier($this->table) . $this->buildJoins() . $this->buildWhere();
        if ($this->groupBy !== null || $this->having !== null || $this->distinct) {
            $dist  = $this->distinct ? 'DISTINCT ' : '';
            $inner = "SELECT {$dist}" . implode(', ', $this->select) . ' ' . $base;
            if ($this->groupBy) $inner .= " GROUP BY {$this->groupBy}";
            if ($this->having)  $inner .= " HAVING {$this->having}";
            return "SELECT COUNT(*) FROM ({$inner}) AS __cnt_q";
        }
        return 'SELECT COUNT(*) ' . $base;
    }

    protected function buildSelect(): string
    {
        $dist = $this->distinct ? 'DISTINCT ' : '';
        $sql  = "SELECT {$dist}" . implode(', ', $this->select) . ' FROM ' . $this->quoteIdentifier($this->table);
        $sql .= $this->buildJoins();
        $sql .= $this->buildWhere();
        if ($this->groupBy) $sql .= " GROUP BY {$this->groupBy}";
        if ($this->having)  $sql .= " HAVING {$this->having}";
        if ($this->orderBy) $sql .= " ORDER BY {$this->orderBy}";
        if ($this->limit  !== null) $sql .= " LIMIT {$this->limit}";
        if ($this->offset !== null) $sql .= " OFFSET {$this->offset}";
        return $sql;
    }

    protected function buildWhere(): string
    {
        if (empty($this->where)) return '';
        $parts = [];
        foreach ($this->where as $i => $w) {
            $connector = ($i === 0) ? '' : (' ' . ($w['connector'] ?? 'AND') . ' ');
            if ($w['type'] === 'group') {
                $groupParts = [];
                foreach ($w['conditions'] as $j => $c) {
                    $gc = ($j === 0) ? '' : (' ' . ($c['connector'] ?? 'AND') . ' ');
                    $groupParts[] = $gc . $c['sql'];
                }
                $parts[] = $connector . '(' . implode('', $groupParts) . ')';
            } else {
                $parts[] = $connector . $w['sql'];
            }
        }
        return ' WHERE ' . implode('', $parts);
    }

    protected function buildJoins(): string
    {
        return empty($this->joins) ? '' : ' ' . implode(' ', $this->joins);
    }

    /**
     * @return \Washi\Collection<int, T|\stdClass>
     */
    protected function executeSelect(string $sql): \Washi\Collection
    {
        $stmt = $this->run($sql, $this->params, Db::KIND_READ);

        $rows = [];
        if ($this->fetchClass !== null) {
            $rows = $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $this->fetchClass);
            foreach ($rows as $row) {
                if (method_exists($row, 'syncOriginal')) $row->syncOriginal();
            }
        } else {
            $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
        }

        $collection = \Washi\Collection::make($rows);
        if (!empty($this->eagerLoads) && $collection->isNotEmpty()) {
            $this->applyEagerLoads($collection);
        }
        return $collection;
    }

    /**
     * with() で登録されたリレーションを WHERE IN の一括クエリで取得し、各行へ紐づける
     *
     * @param \Washi\Collection<int, T|\stdClass> $rows
     */
    protected function applyEagerLoads(\Washi\Collection $rows): void
    {
        foreach ($this->eagerLoads as $load) {
            $localKey = $load['localKey'];
            $many     = $load['many'];

            $keys = [];
            foreach ($rows as $row) {
                $v = $row->{$localKey} ?? null;
                if ($v !== null && $v !== '') $keys[] = $v;
            }
            $keys = array_values(array_unique($keys));

            $related = \Washi\Collection::make();
            if (!empty($keys)) {
                /** @var Query<Entity> $q */
                $q = $load['class']::query($load['scoped'] ?? true)->whereIn($load['foreignKey'], $keys);
                if ($load['query'] !== null) ($load['query'])($q);
                $related = $q->all();
            }
            $map = $many ? $related->groupBy($load['foreignKey']) : $related->keyBy($load['foreignKey']);

            foreach ($rows as $row) {
                $key   = $row->{$localKey} ?? null;
                $value = $key !== null ? ($map[$key] ?? null) : null;
                if ($many) $value = \Washi\Collection::make($value ?? []);
                if ($row instanceof Entity) {
                    $row->setRelation($load['name'], $value);
                } else {
                    // stdClass（asRaw）行は動的プロパティで紐づけ
                    $row->{$load['name']} = $value;
                }
            }
        }
    }

    protected function quoteIdentifier(string $identifier): string
    {
        $driver = $this->connection->getDriver();
        $q      = $driver === 'mysql' ? '`' : '"';

        // 関数式（括弧含む）・スペース含む複合式はそのまま返す（クォート不可な生SQL式）
        if (str_contains($identifier, '(') || str_contains($identifier, ' ')) {
            return $identifier;
        }

        return implode('.', array_map(
            function (string $part) use ($q): string {
                // * はクォートしない
                if ($part === '*') return $part;
                // すでに同じ引用符でクォート済みならそのまま返す
                if (strlen($part) >= 2 && $part[0] === $q && $part[-1] === $q) return $part;
                return $q . str_replace($q, $q . $q, $part) . $q;
            },
            explode('.', $identifier)
        ));
    }
}
