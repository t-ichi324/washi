<?php

namespace Washi;

/** Built-in HTML for the fallback error page and the debug footer panel. No app views involved. */
class Debug
{
    public static function errorPage(int $status, string $message, ?\Throwable $e): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $title = "$status " . HttpException::title($status);
        $html = '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($title) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:2rem}.c{max-width:960px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:2rem}'
            . 'h1{margin:0 0 .5rem;font-size:1.6rem}p.m{color:#b91c1c;font-size:1.05rem;word-break:break-all}.dbg{margin-top:1.5rem;font-family:ui-monospace,monospace;font-size:.8rem;white-space:pre-wrap;word-break:break-all;background:#f1f5f9;border-radius:8px;padding:1rem;max-height:40vh;overflow:auto}'
            . '.code{background:#1e1e1e;color:#d4d4d4;font-family:ui-monospace,monospace;font-size:.82rem;border-radius:8px;padding:1rem;overflow:auto;margin-top:1rem}.code .ln{color:#858585;display:inline-block;width:3rem;text-align:right;margin-right:1rem;user-select:none}.code .hit{background:#4a151b;color:#fff;display:block;margin:0 -1rem;padding:0 1rem}.code div{white-space:pre}</style></head><body><div class="c">'
            . '<h1>' . $h($title) . '</h1>';
        if ($message !== '' && $message !== HttpException::title($status)) $html .= '<p class="m">' . $h($message) . '</p>';
        if ($e !== null) {
            $html .= self::codePreview($e->getFile(), $e->getLine());
            $html .= '<div class="dbg">' . $h(get_class($e) . ': ' . $e->getMessage()) . "\n" . $h($e->getFile() . ':' . $e->getLine()) . "\n\n" . $h($e->getTraceAsString()) . '</div>';
        }
        return $html . '</div></body></html>';
    }

    public static function codePreview(string $file, int $line, int $radius = 6): string
    {
        if (!is_readable($file)) return '';
        $lines = file($file);
        $start = max(0, $line - $radius - 1);
        $end = min(count($lines), $line + $radius);
        $out = '<div class="code"><div style="color:#858585;border-bottom:1px solid #3c3c3c;padding-bottom:6px;margin-bottom:8px">' . htmlspecialchars($file, ENT_QUOTES) . ':' . $line . '</div>';
        for ($i = $start; $i < $end; $i++) {
            $n = $i + 1;
            $code = htmlspecialchars(rtrim($lines[$i], "\r\n"), ENT_QUOTES, 'UTF-8');
            $out .= '<div' . ($n === $line ? ' class="hit"' : '') . '><span class="ln">' . $n . '</span>' . $code . '</div>';
        }
        return $out . '</div>';
    }

    /** @param array<int, array{sql:string,params:array,ms:float}> $queries */
    public static function panel(float $elapsed, array $queries): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $totalMs = array_sum(array_column($queries, 'ms'));
        $html = '<details id="washi-debug" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;font:12px/1.5 ui-monospace,monospace;background:#0f172a;color:#e2e8f0;border-top:2px solid #38bdf8;max-height:45vh;overflow:auto">'
            . '<summary style="padding:4px 12px;cursor:pointer;user-select:none">washi · ' . sprintf('%.1f ms', $elapsed * 1000) . ' · ' . sprintf('%.1f MB', memory_get_peak_usage() / 1048576)
            . ' · ' . count($queries) . ' queries (' . sprintf('%.1f ms', $totalMs) . ')</summary>';
        if ($queries) {
            $html .= '<table style="width:100%;border-collapse:collapse"><tbody>';
            foreach ($queries as $q) {
                $html .= '<tr style="border-top:1px solid #1e293b"><td style="padding:2px 12px;color:#38bdf8;white-space:nowrap;vertical-align:top">' . $h($q['ms']) . ' ms</td>'
                    . '<td style="padding:2px 12px;word-break:break-all">' . $h($q['sql'])
                    . ($q['params'] ? ' <span style="color:#94a3b8">' . $h(json_encode($q['params'], JSON_UNESCAPED_UNICODE)) . '</span>' : '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html . '</details>';
    }
}
