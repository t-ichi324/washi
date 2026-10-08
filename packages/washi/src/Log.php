<?php

namespace Washi;

/**
 * Minimal logger: one line per entry, to a daily file under storage/log or to stderr.
 * `Log::to('stderr')` for containers. Levels: debug < info < warning < error.
 *
 * `Log::exception($e)` records the stack trace (and any `previous` chain) in the context JSON,
 * so a 500 stays diagnosable in production where the error page shows nothing. Depth is capped by
 * `Log::traceLimit()`; 0 restores the old message-only line.
 */
class Log
{
    private static string $target = 'stderr';
    private static string $minLevel = 'debug';
    private static int $traceLimit = 20;
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
        $line = sprintf("[%s] %s: %s%s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        if (self::$target === 'stderr') {
            if (PHP_SAPI === 'cli') fwrite(STDERR, $line);
            else error_log(rtrim($line));
            return;
        }
        if (!is_dir(self::$target)) @mkdir(self::$target, 0777, true);
        @file_put_contents(self::$target . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
