<?php

namespace Washi\Db;


/**
 * Database Facade — high-level entry point for all database operations.
 *
 * Use to perform raw SQL queries, manage connections, and start transactions.
 * Typical uses: one-off queries, direct record counts, transaction management.
 *
 * - Manages multiple database connections via {@see Connection}.
 * - Provides helper methods for common operations (fetch, execute, count).
 * - Query execution is observable via `Db::listen()` (logging / tracing is the caller's job).
 * - Supports automatic pagination logic via `page()`.
 */
class Db
{
    public const KIND_READ  = 'read';
    public const KIND_WRITE = 'write';

    /** @var Connection[] */
    protected static array $connections = [];

    /** @var array<int, callable(string $sql, array $params, float $elapsed, string $connectionKey, string $kind): void> */
    protected static array $listeners = [];

    /**
     * クエリ実行リスナーを登録（ログ・トレース用）
     *
     * fn(string $sql, array $params, float $elapsedSec, string $connectionKey, string $kind)
     */
    public static function listen(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    /** 指定 Connection 上で prepare → execute → リスナー通知 */
    public static function runOn(Connection $conn, string $sql, array $params, string $kind): \PDOStatement
    {
        $stmt  = $conn->getPdo()->prepare($sql);
        $start = microtime(true);
        $stmt->execute($params);
        $elapsed = microtime(true) - $start;
        foreach (self::$listeners as $l) $l($sql, $params, $elapsed, $conn->getKey(), $kind);
        return $stmt;
    }

    /** 接続登録 */
    public static function addConnection(string $key, Connection $connection): void
    {
        self::$connections[$key] = $connection;
    }

    /** 接続取得（未登録なら環境変数 DB_* から自動生成。それも無ければ例外） */
    public static function connection(string $key = 'default'): Connection
    {
        if (!isset(self::$connections[$key])) {
            $conn = Connection::fromEnv($key === 'default' ? 'DB' : strtoupper($key), $key);
            if ($conn === null) {
                throw new \RuntimeException("Db: connection '{$key}' is not registered. Call Db::addConnection('{$key}', ...) or set DB_DRIVER.");
            }
            self::$connections[$key] = $conn;
        }
        return self::$connections[$key];
    }

    /** 接続が登録済みか（環境変数からの自動生成は試みない） */
    public static function hasConnection(string $key = 'default'): bool
    {
        return isset(self::$connections[$key]);
    }

    /** PDO 取得 */
    public static function pdo(string $connectionKey = 'default'): \PDO
    {
        return self::connection($connectionKey)->getPdo();
    }

    /**
     * テーブルクエリ開始（クエリビルダ）
     *
     * @return Query<object>
     */
    public static function table(string $table, string $connectionKey = 'default'): Query
    {
        return new Query(self::connection($connectionKey), $table);
    }

    /**
     * クエリ開始（クエリビルダ）
     *
     * @return Query<object>
     */
    public static function query(string $connectionKey = 'default'): Query
    {
        return new Query(self::connection($connectionKey));
    }

    /**
     * RAW SQL 実行（SELECT）→ Collection を返す
     *
     * @template T of object
     * @param  string      $sql           SELECT 文
     * @param  array       $params        バインドパラメータ
     * @param  string      $connectionKey 接続キー
     * @param  class-string<T>|null $fetchClass 結果を詰めるクラス名（null で stdClass）
     * @return \Washi\Collection<int, T|\stdClass>
     */
    public static function select(
        string $sql,
        array $params = [],
        string $connectionKey = 'default',
        ?string $fetchClass = null
    ): \Washi\Collection {
        $stmt = self::runStmt($sql, $params, self::KIND_READ, $connectionKey);
        if ($fetchClass) {
            $rows = $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $fetchClass);
            foreach ($rows as $row) {
                if (method_exists($row, 'syncOriginal')) $row->syncOriginal();
            }
        } else {
            $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
        }
        return \Washi\Collection::make($rows);
    }

    /**
     * RAW SQL 実行（SELECT + ページネーション）→ Paginated を返す
     *
     * @template T of object
     * @param  class-string<T>|null $fetchClass 結果を詰めるクラス名
     * @return Paginated<int, T|\stdClass>
     */
    public static function page(
        string $sql,
        array $params = [],
        int $page = 1,
        int $perPage = 20,
        string $connectionKey = 'default',
        ?string $fetchClass = null
    ): Paginated {
        $page = max(1, $page);

        $countSql = "SELECT COUNT(*) FROM ({$sql}) AS __cnt_q";
        $total = (int)self::runStmt($countSql, $params, self::KIND_READ, $connectionKey)->fetchColumn();

        $offset   = ($page - 1) * $perPage;
        $pagedSql = $sql . " LIMIT {$perPage} OFFSET {$offset}";
        $stmt     = self::runStmt($pagedSql, $params, self::KIND_READ, $connectionKey);

        if ($fetchClass) {
            $rows = $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $fetchClass);
            foreach ($rows as $row) {
                if (method_exists($row, 'syncOriginal')) $row->syncOriginal();
            }
        } else {
            $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
        }
        return new Paginated($rows, $total, $page, $perPage);
    }

    /**
     * RAW SQL 実行（INSERT / UPDATE / DELETE 等）
     *
     * @return int 影響行数
     */
    public static function execute(string $sql, array $params = [], string $connectionKey = 'default'): int
    {
        return self::runStmt($sql, $params, self::KIND_WRITE, $connectionKey)->rowCount();
    }

    private static function runStmt(string $sql, array $params, string $kind, string $connectionKey): \PDOStatement
    {
        return self::runOn(self::connection($connectionKey), $sql, $params, $kind);
    }

    /**
     * トランザクション（callable を渡す形式）
     *
     * callable が例外を投げた場合は自動 ROLLBACK し、例外を再スロー。
     *
     * @param  callable $callback function(\PDO $pdo): mixed
     * @return mixed callback の戻り値
     */
    public static function transaction(callable $callback, string $connectionKey = 'default'): mixed
    {
        $conn = self::connection($connectionKey);
        $conn->beginTransaction(); // Connectionクラスのメソッドを呼ぶ
        try {
            $result = $callback($conn->getPdo());
            $conn->commit();
            return $result;
        } catch (\Throwable $ex) {
            $conn->rollBack();
            throw $ex;
        }
    }

    /** トランザクション開始 */
    public static function beginTransaction(string $connectionKey = 'default'): void
    {
        self::connection($connectionKey)->beginTransaction();
    }

    /** コミット */
    public static function commit(string $connectionKey = 'default'): void
    {
        self::connection($connectionKey)->commit();
    }

    /** ロールバック */
    public static function rollback(string $connectionKey = 'default'): void
    {
        self::connection($connectionKey)->rollBack();
    }

    /**
     * マイグレーション実行（$dir 内の *.sql を名前順に、未実行分だけ流す）
     *
     * @return string[] 今回実行したファイル名
     */
    public static function migrate(string $dir, string $connectionKey = 'default'): array
    {
        return (new Migration(self::connection($connectionKey)))->run($dir);
    }

    /** 全接続切断 */
    public static function disconnectAll(): void
    {
        foreach (self::$connections as $conn) {
            $conn->disconnect();
        }
    }

    // ── Schema Introspection ──────────────────────────────────────────────

    /** テーブル一覧取得 */
    public static function tables(string $connectionKey = 'default'): array
    {
        $pdo    = self::pdo($connectionKey);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        } elseif ($driver === 'pgsql') {
            $stmt = $pdo->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname != 'pg_catalog' AND schemaname != 'information_schema'");
        } else {
            $stmt = $pdo->query("SHOW TABLES");
        }
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * テーブルのカラム定義を取得
     *
     * 戻り値の各要素: ['name', 'type', 'notnull', 'pk', 'comment', 'length']
     */
    public static function schema(string $table, string $connectionKey = 'default'): array
    {
        $pdo    = self::pdo($connectionKey);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $columns = [];

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(`$table`)");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $columns[] = [
                    'name'    => $row['name'],
                    'type'    => strtolower($row['type']),
                    'notnull' => (bool)$row['notnull'],
                    'pk'      => (bool)$row['pk'],
                    'comment' => '',
                    'length'  => null,
                ];
            }
        } elseif ($driver === 'pgsql') {
            $stmt = $pdo->prepare("
                SELECT column_name, data_type, is_nullable, column_default, character_maximum_length,
                    (SELECT description FROM pg_description WHERE objoid = :table::regclass AND objsubid = ordinal_position) as comment
                FROM information_schema.columns
                WHERE table_name = :table_name
                ORDER BY ordinal_position
            ");
            $stmt->execute(['table' => $table, 'table_name' => $table]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $columns[] = [
                    'name'    => $row['column_name'],
                    'type'    => strtolower($row['data_type']),
                    'notnull' => $row['is_nullable'] === 'NO',
                    'pk'      => strpos($row['column_default'] ?? '', 'nextval') !== false,
                    'comment' => $row['comment'] ?? '',
                    'length'  => $row['character_maximum_length'],
                ];
            }
        } else {
            $stmt = $pdo->query("SHOW FULL COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                preg_match('/\((\d+)\)/', $row['Type'], $matches);
                $columns[] = [
                    'name'    => $row['Field'],
                    'type'    => strtolower(preg_replace('/\(.*\)/', '', $row['Type'])),
                    'notnull' => $row['Null'] === 'NO',
                    'pk'      => $row['Key'] === 'PRI',
                    'comment' => $row['Comment'] ?? '',
                    'length'  => isset($matches[1]) ? (int)$matches[1] : null,
                ];
            }
        }
        return $columns;
    }
}
