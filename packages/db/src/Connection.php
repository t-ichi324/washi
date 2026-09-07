<?php

namespace Washi\Db;


/**
 * Database Connection Wrapper — manages PDO instances and transaction state.
 *
 * Use to access the underlying PDO object or handle low-level transaction logic.
 * Typical uses: manual transaction management (savepoints), PDO-specific attribute setting.
 *
 * - Supports multiple drivers (MySQL, PostgreSQL, SQLite).
 * - Implements nested transactions using `SAVEPOINT` where supported.
 * - Provides lazy-loading for PDO connections to save resources.
 */
class Connection
{
    protected string $key;
    protected ?string $driver = null;
    protected ?string $host = null;
    protected ?int $port = null;
    protected ?string $database = null;
    protected ?string $username = null;
    protected ?string $password = null;
    protected ?string $charset = 'utf8mb4';
    protected ?string $timezone = null;
    /** MySQL: 設定時のみ SET SESSION sql_mode を発行（未設定ならサーバ設定を尊重） */
    protected ?string $sqlMode = null;
    protected ?string $schema = null;
    protected ?string $sqlitePath = null;
    protected ?string $socket = null;
    protected ?\PDO $pdo = null;
    private ?float $lastUsed = null;

    protected int $transactions = 0;

    /** FPMワーカーがPDOを使い回す間に接続が切れていた場合の再接続猶予秒数 */
    private const STALE_THRESHOLD = 60.0;

    public function __construct(string $key, array $config = [])
    {
        $this->key = $key;
        foreach ($config as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }

    /** 接続取得 / 初期化（FPMワーカー内での stale 接続を自動再接続） */
    public function getPdo(): \PDO
    {
        $now = microtime(true);
        if ($this->pdo !== null && $this->lastUsed !== null && ($now - $this->lastUsed) > self::STALE_THRESHOLD) {
            try {
                $this->pdo->query('SELECT 1');
            } catch (\PDOException) {
                $this->pdo = null;
            }
        }
        if ($this->pdo === null) {
            $this->pdo = $this->createPdo();
        }
        $this->lastUsed = $now;
        return $this->pdo;
    }

    protected function createPdo(): \PDO
    {
        $driver = $this->driver ?? 'mysql';
        {
            if ($driver === 'sqlite') {
                $path = $this->sqlitePath ?: $this->database;
                // :memory: はそのまま。ファイルパスは呼び出し側で絶対パスにしておくこと（親ディレクトリは自動作成）
                if ($path !== ':memory:') {
                    $dir = dirname($path);
                    if (!is_dir($dir)) mkdir($dir, 0777, true);
                }
                $dsn = 'sqlite:' . $path;
                $pdo = new \PDO($dsn);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA journal_mode=WAL;');
                $pdo->exec('PRAGMA busy_timeout=5000;');
                return $pdo;
            }

            // MySQL / PostgreSQL DSN構築
            // Cloud SQL Proxy の Unix ソケット接続を優先（TCP より低レイテンシ・接続数節約）
            if ($driver === 'mysql') {
                if ($this->socket) {
                    $dsn = "mysql:unix_socket={$this->socket};dbname={$this->database}";
                } else {
                    $dsn = "mysql:host={$this->host}";
                    if ($this->port) $dsn .= ";port={$this->port}";
                    $dsn .= ";dbname={$this->database}";
                }
                if ($this->charset) $dsn .= ";charset={$this->charset}";
            } else {
                $dsn = "{$driver}:host={$this->host}";
                if ($this->port) $dsn .= ";port={$this->port}";
                $dsn .= ";dbname={$this->database}";
            }

            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            ];

            $pdo = new \PDO($dsn, $this->username, $this->password, $options);

            // ドライバ固有の初期化
            if ($driver === 'mysql') {
                if (!empty($this->timezone)) {
                    $stmt = $pdo->prepare("SET time_zone = ?");
                    $stmt->execute([$this->timezone]);
                }
                // sql_mode はサーバ設定を黙って上書きしない（db.sql_mode で明示した場合のみ変更）
                if (!empty($this->sqlMode)) {
                    $stmt = $pdo->prepare("SET SESSION sql_mode = ?");
                    $stmt->execute([$this->sqlMode]);
                }
            } elseif ($driver === 'pgsql') {
                if ($this->charset) {
                    $encoding = $this->charset === 'utf8mb4' ? 'UTF8' : $this->charset;
                    // PostgreSQL の SET は prepared statement 非対応のため許容値のみ通す
                    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $encoding)) {
                        throw new \InvalidArgumentException("Invalid charset value: {$encoding}");
                    }
                    $pdo->exec("SET client_encoding TO '{$encoding}'");
                }
                if (!empty($this->timezone)) {
                    $stmt = $pdo->prepare("SET timezone = ?");
                    $stmt->execute([$this->timezone]);
                }
                if (!empty($this->schema)) {
                    // スキーマ名は識別子のため prepared statement 非対応 - 英数字とアンダースコアのみ許可
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_,\s]*$/', $this->schema)) {
                        throw new \InvalidArgumentException("Invalid schema value: {$this->schema}");
                    }
                    $pdo->exec("SET search_path TO {$this->schema}");
                }
            }

            return $pdo;
        }
    }

    /** 再接続 */
    public function reconnect(): void
    {
        $this->pdo = null;
        $this->getPdo();
    }

    /** 切断 */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /** 接続キー取得 */
    public function getKey(): string
    {
        return $this->key;
    }

    /** ドライバ名取得 */
    public function getDriver(): string
    {
        return $this->driver ?? 'mysql';
    }

    /** PostgreSQL判定 */
    public function isPostgres(): bool
    {
        return $this->getDriver() === 'pgsql';
    }

    /** MySQL判定 */
    public function isMysql(): bool
    {
        return $this->getDriver() === 'mysql';
    }

    /** SQLite判定 */
    public function isSqlite(): bool
    {
        return $this->getDriver() === 'sqlite';
    }

    /** SQLite 接続を生成（$path は絶対パスか ':memory:'） */
    public static function sqlite(string $path, string $key = 'default'): self
    {
        return new self($key, ['driver' => 'sqlite', 'sqlitePath' => $path]);
    }

    /**
     * 環境変数から Connection を生成
     *
     * 読む変数（$prefix='DB' のとき）: DB_DRIVER, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME,
     * DB_PASSWORD, DB_CHARSET, DB_TIMEZONE, DB_SQL_MODE, DB_SCHEMA, DB_SQLITE_PATH, DB_SOCKET
     *
     * @return self|null DB_DRIVER が未設定なら null
     */
    public static function fromEnv(string $prefix = 'DB', string $key = 'default'): ?self
    {
        $e = static fn(string $name, ?string $default = null): ?string => (
            ($v = $_ENV["{$prefix}_{$name}"] ?? getenv("{$prefix}_{$name}")) !== false && $v !== null && $v !== ''
        ) ? (string)$v : $default;

        $driver = $e('DRIVER');
        if ($driver === null) return null;
        $defaultPort = match ($driver) {
            'pgsql' => 5432,
            'mysql' => 3306,
            default => 0,
        };

        return new self($key, [
            'driver'     => $driver,
            'host'       => $e('HOST', 'localhost'),
            'port'       => (int)$e('PORT', (string)$defaultPort),
            'database'   => $e('DATABASE', ''),
            'username'   => $e('USERNAME', ''),
            'password'   => $e('PASSWORD', ''),
            'charset'    => $e('CHARSET', 'utf8mb4'),
            'timezone'   => $e('TIMEZONE'),
            'sqlMode'    => $e('SQL_MODE'),
            'schema'     => $e('SCHEMA'),
            'sqlitePath' => $e('SQLITE_PATH'),
            'socket'     => $e('SOCKET'),
        ]);
    }

    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->getPdo()->beginTransaction();
        } else {
            $this->getPdo()->exec("SAVEPOINT trans_{$this->transactions}");
        }
        $this->transactions++;
    }

    public function commit(): void
    {
        $this->transactions = max(0, $this->transactions - 1);
        if ($this->transactions === 0) {
            $this->getPdo()->commit();
        } else {
            $this->getPdo()->exec("RELEASE SAVEPOINT trans_{$this->transactions}");
        }
    }

    public function rollBack(): void
    {
        $this->transactions = max(0, $this->transactions - 1);
        if ($this->transactions === 0) {
            $this->getPdo()->rollBack();
        } else {
            $this->getPdo()->exec("ROLLBACK TO SAVEPOINT trans_{$this->transactions}");
        }
    }
}
