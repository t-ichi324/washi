<?php

namespace Washi;

/**
 * Built-in HTML for the fallback error page and the debug footer panel. No app views involved.
 *
 * Panel tabs: Route (page file / explicit route, params, _access.php, response headers), Request, Views,
 * Queries (×N marks the same SQL repeated = N+1 suspect), Session. Data comes from App (queries) and from
 * `Debug::record($kind, $row)` calls in App/View, which are no-ops unless debug + debug_panel are on.
 * Keys that look secret (password, token, _csrf, cookie …) are shown as ***.
 */
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

    // ── debug panel ──────────────────────────────────────────────────────

    /** パネルに出さない値のキー（部分一致）。画面共有やスクショでの漏れ防止 */
    private const SECRET = '/pass|token|secret|api_?key|_csrf|authorization|cookie/i';
    private static bool $on = false;
    /** @var array<string, list<array>> */
    private static array $records = [];

    /** App が debug かつ debug_panel のときだけ有効にする。無効なら record() は何もしない（本番コストなし） */
    public static function enable(bool $on): void
    {
        self::$on = $on;
        self::$records = [];
    }

    /** Collect a row for the panel (`route`, `views`, …). No-op unless the panel is enabled. */
    public static function record(string $kind, array $row): void
    {
        if (self::$on) self::$records[$kind][] = $row;
    }

    /**
     * The footer panel: a summary line, then CSS-only tabs (no JS, so an app CSP cannot break it).
     * @param array<int, array{sql:string,params:array,ms:float,connection:string,kind:string}> $queries
     */
    public static function panel(Request $request, Response $response, float $elapsed, array $queries): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $totalMs = array_sum(array_column($queries, 'ms'));
        $seen = array_count_values(array_column($queries, 'sql'));
        $dups = array_sum(array_filter($seen, fn($n) => $n > 1));
        $views = self::$records['views'] ?? [];
        $route = (self::$records['route'] ?? [])[0] ?? null;

        $tabs = [
            'route'   => ['Route', self::routeTab($route, $response)],
            'request' => ['Request', self::requestTab($request)],
            'views'   => ['Views (' . count($views) . ')', self::viewsTab($views)],
            'queries' => ['Queries (' . count($queries) . ($dups ? ", {$dups} dup" : '') . ')', self::queriesTab($queries, $seen)],
            'session' => ['Session', session_status() === PHP_SESSION_ACTIVE ? self::table(self::mask($_SESSION)) : '<p class="wd-dim">(not started)</p>'],
        ];
        $default = $queries ? 'queries' : 'route';

        $css = '#washi-debug{position:fixed;left:0;right:0;bottom:0;z-index:99999;font:12px/1.5 ui-monospace,monospace;background:#0f172a;color:#e2e8f0;border-top:2px solid #38bdf8}'
            . '#washi-debug summary{padding:4px 12px;cursor:pointer;user-select:none}'
            . '#washi-debug>div>input{display:none}#washi-debug nav{display:flex;flex-wrap:wrap;border-bottom:1px solid #1e293b}'
            . '#washi-debug nav label{padding:4px 12px;cursor:pointer;color:#94a3b8}#washi-debug section{display:none;height:35vh;overflow:auto;padding:4px 0}'
            . '#washi-debug table{width:100%;border-collapse:collapse}#washi-debug td{padding:2px 12px;vertical-align:top;border-top:1px solid #1e293b;word-break:break-all}'
            . '#washi-debug td.k{color:#38bdf8;white-space:nowrap;width:1%}#washi-debug .wd-dim{color:#94a3b8;padding:2px 12px;margin:0}#washi-debug .wd-warn{color:#fbbf24}'
            . '#washi-debug pre{margin:0;white-space:pre-wrap;font:inherit}';
        foreach (array_keys($tabs) as $id) {
            $css .= "#wd-{$id}:checked~nav label[for=wd-{$id}]{color:#e2e8f0;box-shadow:inset 0 -2px #38bdf8}#wd-{$id}:checked~.wd-{$id}{display:block}";
        }

        $html = '<details id="washi-debug"><style>' . $css . '</style>'
            . '<summary>washi · ' . sprintf('%.1f ms', $elapsed * 1000) . ' · ' . sprintf('%.1f MB', memory_get_peak_usage() / 1048576)
            . ' · ' . $h($request->method . ' ' . $request->path) . ' → ' . $response->getStatus()
            . ' · ' . count($queries) . ' queries (' . sprintf('%.1f ms', $totalMs) . ')' . ($dups ? ' <span class="wd-warn">' . $dups . ' dup</span>' : '')
            . '</summary><div>';
        foreach (array_keys($tabs) as $id) $html .= '<input type="radio" name="washi-debug-tab" id="wd-' . $id . '"' . ($id === $default ? ' checked' : '') . '>';
        $html .= '<nav>';
        foreach ($tabs as $id => [$label]) $html .= '<label for="wd-' . $id . '">' . $h($label) . '</label>';
        $html .= '</nav>';
        foreach ($tabs as $id => [, $body]) $html .= '<section class="wd-' . $id . '">' . $body . '</section>';
        return $html . '</div></details>';
    }

    private static function routeTab(?array $route, Response $response): string
    {
        $rows = $route ?? ['resolved' => '(none)'];
        $rows['_access.php'] = array_column(self::$records['access'] ?? [], 'file');
        $rows['status'] = $response->getStatus();
        $rows['response headers'] = $response->getHeaders();
        return self::table($rows);
    }

    private static function requestTab(Request $request): string
    {
        $headers = [];
        foreach ($request->server as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $k = substr($k, 5);
            elseif (!in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) continue;
            $headers[ucwords(strtolower(str_replace('_', '-', $k)), '-')] = $v;
        }
        return self::table([
            'method'   => $request->method,
            'url'      => $request->url(false),   // クエリは下でマスクして出す
            'path'     => $request->path,
            'basePath' => $request->basePath,
            'query'    => self::mask($request->query),
            'input'    => self::mask(array_diff_key($request->all(), $request->query)),
            'files'    => array_map(fn($f) => is_array($f) ? ($f['name'] ?? '') . ' (' . ($f['size'] ?? 0) . ' bytes)' : $f, $request->files),
            'cookies'  => array_map(fn($v) => '(' . strlen((string)$v) . ' bytes)', $request->cookies),
            'headers'  => self::mask($headers),
        ]);
    }

    private static function viewsTab(array $views): string
    {
        if (!$views) return '<p class="wd-dim">(no views rendered)</p>';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '<table>';
        foreach ($views as $v) $html .= '<tr><td class="k">' . $h($v['ms']) . ' ms</td><td>' . $h($v['view']) . '</td></tr>';
        return $html . '</table>';
    }

    /** @param array<string,int> $seen SQL → 出現回数（2 回以上は N+1 の疑い） */
    private static function queriesTab(array $queries, array $seen): string
    {
        if (!$queries) return '<p class="wd-dim">(no queries)</p>';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '<table>';
        foreach ($queries as $q) {
            $n = $seen[$q['sql']];
            $html .= '<tr><td class="k">' . $h($q['ms']) . ' ms</td><td>'
                . ($n > 1 ? '<span class="wd-warn">×' . $n . '</span> ' : '') . $h($q['sql'])
                . ($q['params'] ? ' <span class="wd-dim" style="padding:0">' . $h(json_encode($q['params'], JSON_UNESCAPED_UNICODE)) . '</span>' : '')
                . (($q['connection'] ?? 'default') !== 'default' ? ' <span class="wd-dim" style="padding:0">@' . $h($q['connection']) . '</span>' : '')
                . '</td></tr>';
        }
        return $html . '</table>';
    }

    /** key => value の表。配列やオブジェクトは JSON で出す */
    private static function table(array $rows): string
    {
        if (!$rows) return '<p class="wd-dim">(empty)</p>';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '<table>';
        foreach ($rows as $k => $v) {
            if ($v === [] || $v === null) $out = '<span class="wd-dim" style="padding:0">' . ($v === null ? 'null' : '[]') . '</span>';
            elseif (is_string($v) || is_int($v) || is_float($v)) $out = $h($v);
            else $out = '<pre>' . $h(json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) . '</pre>';
            $html .= '<tr><td class="k">' . $h($k) . '</td><td>' . $out . '</td></tr>';
        }
        return $html . '</table>';
    }

    /** 秘密っぽいキーの値を *** にする。オブジェクト（Auth のユーザー等）は公開プロパティの配列にしてから */
    private static function mask(mixed $v): mixed
    {
        if (is_object($v)) $v = json_decode((string)json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [];
        if (!is_array($v)) return $v;
        foreach ($v as $k => $x) $v[$k] = is_string($k) && preg_match(self::SECRET, $k) ? '***' : self::mask($x);
        return $v;
    }
}
