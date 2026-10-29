<?php

namespace Washi;

/**
 * Minimal logger: one line per entry, to a daily file under storage/log or to stderr.
 * `Log::to('stderr')` for containers. Levels: debug < info < warning < error.
 *
 * `Log::exception($e)` records the stack trace (and any `previous` chain) in the context JSON,
 * so a 500 stays diagnosable in production where the error page shows nothing. Depth is capped by
 * `Log::traceLimit()`; 0 restores the old message-only line.
 *
 * `Log::access($entry)` is the access log: one line per request, written by `App::run()` once the
 * final response (404 / 500 included) is known. Off by default (`ACCESS_LOG=true`); goes to
 * `<date>-access.log` next to the app log, or stderr. `Log::accessFormat()` swaps the line format.
 */
class Log
{
    private static string $target = 'stderr';
    private static string $minLevel = 'debug';
    private static int $traceLimit = 20;
    private static bool $accessOn = false;
    /** @var string[] URI にこの文字列を含むリクエストは記録しない */
    private static array $accessIgnore = [];
    private static ?\Closure $accessFormat = null;
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /** @param string $target directory path (daily files) or 'stderr' */
    public static function to(string $target, string $minLevel = 'debug'): void
    {
        self::$target = $target;
        self::$minLevel = $minLevel;
    }

    /** Max stack frames stored by exception(); 0 disables the trace. */
    public static function traceLimit(int $frames): void
    {
        self::$traceLimit = max(0, $frames);
    }

    public static function debug(string $msg, array $ctx = []): void   { self::write('debug', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void    { self::write('info', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::write('warning', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void   { self::write('error', $msg, $ctx); }

    public static function exception(\Throwable $e, string $prefix = ''): void
    {
        $ctx = [];
        if (self::$traceLimit > 0) {
            // 1 エントリ 1 行を保つため、トレースは改行ではなく ctx の JSON 配列に入れる
            $frames = array_slice(explode("\n", $e->getTraceAsString()), 0, self::$traceLimit);
            $ctx['trace'] = $frames;
            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
                $ctx['previous'][] = get_class($p) . ': ' . $p->getMessage() . ' @ ' . $p->getFile() . ':' . $p->getLine();
            }
        }
        self::write('error', ($prefix !== '' ? "$prefix: " : '') . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine(), $ctx);
    }

    public static function write(string $level, string $msg, array $ctx = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[self::$minLevel] ?? 0)) return;
        self::emit(sprintf("[%s] %s: %s%s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''));
    }

    /** @param string $suffix ファイル名の接尾辞（'' → 日付.log、'-access' → 日付-access.log） */
    private static function emit(string $line, string $suffix = ''): void
    {
        if (self::$target === 'stderr') {
            if (PHP_SAPI === 'cli') fwrite(STDERR, $line);
            else error_log(rtrim($line));
            return;
        }
        if (!is_dir(self::$target)) @mkdir(self::$target, 0777, true);
        @file_put_contents(self::$target . '/' . date('Y-m-d') . $suffix . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** アクセスログの ON/OFF。$ignore は URI に含まれていたら記録しない文字列（死活監視・ログ閲覧画面など） */
    public static function accessEnable(bool $on = true, array $ignore = []): void
    {
        self::$accessOn = $on;
        self::$accessIgnore = array_values(array_filter(array_map('trim', $ignore), fn($s) => $s !== ''));
    }

    public static function accessEnabled(): bool { return self::$accessOn; }

    /**
     * 行の書式を差し替える。fn(array $entry): string（改行は付けない）。null で既定に戻す。
     * $entry: time(Y-m-d H:i:s) status ms ip user sid method uri ua referer。user は未ログインなら null、
     * sid はセッション ID の短縮ハッシュ（セッション未開始なら null）。
     */
    public static function accessFormat(?callable $format): void
    {
        self::$accessFormat = $format === null ? null : \Closure::fromCallable($format);
    }

    /**
     * アクセスログ 1 行を書く（`App::run()` が応答確定後に呼ぶ。無効なら何もしない）。
     * 書式の例外でリクエストを壊さない: 失敗したらエラーログに残して既定の書式で書く。
     */
    public static function access(array $entry): void
    {
        if (!self::$accessOn) return;
        foreach (self::$accessIgnore as $skip) {
            if (str_contains((string)($entry['uri'] ?? ''), $skip)) return;
        }
        $entry += ['time' => date('Y-m-d H:i:s'), 'status' => 0, 'ms' => 0, 'ip' => '-', 'user' => null, 'sid' => null,
            'method' => '-', 'uri' => '-', 'ua' => '', 'referer' => ''];
        try {
            $line = self::$accessFormat ? (string)(self::$accessFormat)($entry) : self::defaultAccessLine($entry);
        } catch (\Throwable $e) {
            self::write('error', 'Access log format failed: ' . $e->getMessage());
            $line = self::defaultAccessLine($entry);
        }
        self::emit(rtrim($line, "\r\n") . "\n", '-access');
    }

    /** 時刻 / ステータス / 処理時間 / IP / ユーザー / メソッド / URI をタブ区切りで（grep・cut・表計算で扱える） */
    private static function defaultAccessLine(array $e): string
    {
        return implode("\t", [$e['time'], $e['status'], $e['ms'] . 'ms', $e['ip'], $e['user'] ?? '-', $e['method'], $e['uri']]);
    }
}
