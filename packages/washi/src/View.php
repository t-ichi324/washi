<?php

namespace Washi;

/**
 * Plain-PHP template renderer with an optional layout.
 *
 * - `View::render('user/edit', $data)` includes views/user/edit.php with $data extracted as locals.
 * - `View::page('user/edit', $data)` renders the template, then wraps it in views/_layout.php
 *   (if it exists) where the template output is `$content`. Pass `layout: 'admin'` for views/_layout.admin.php
 *   … or any template name; `layout: false` skips wrapping.
 * - Inside templates: `$this` is not used; call helpers (`h()`, `url()`, `partial()`, `csrf_field()`).
 * - Templates can set `$title` (or any variable) via `View::share('title', ...)` for the layout; the
 *   `title()` helper does exactly that.
 * - Sections: `View::start('scripts')` … `View::stop()` in a template, `View::section('scripts')` in the layout.
 */
class View
{
    private static string $dir = '';
    private static string $defaultLayout = '_layout';
    private static array $shared = [];
    private static array $sections = [];
    private static array $sectionStack = [];

    public static function configure(string $dir, string $defaultLayout = '_layout'): void
    {
        self::$dir = rtrim($dir, '/\\');
        self::$defaultLayout = $defaultLayout;
    }

    public static function dir(): string { return self::$dir; }

    /** Variables visible to every template and layout */
    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) self::$shared = array_merge(self::$shared, $key);
        else self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    public static function exists(string $template): bool
    {
        return is_file(self::path($template));
    }

    public static function path(string $template): string
    {
        $template = str_replace(['\\', '..'], ['/', ''], $template);
        return self::$dir . '/' . ltrim($template, '/') . '.php';
    }

    /** Render a template to a string (no layout). */
    public static function render(string $template, array $data = []): string
    {
        $file = self::path($template);
        if (!is_file($file)) throw new \RuntimeException("View not found: {$template} ({$file})");
        return self::capture($file, array_merge(self::$shared, $data));
    }

    /** Render a template, then wrap it in a layout. */
    public static function page(string $template, array $data = [], string|false|null $layout = null): string
    {
        $content = self::render($template, $data);
        $layout ??= self::$defaultLayout;
        if ($layout === false || $layout === '' || !self::exists($layout)) return $content;
        return self::capture(self::path($layout), array_merge(self::$shared, $data, ['content' => $content]));
    }

    // ── sections (template → layout hand-off) ────────────────────────────

    public static function start(string $name): void
    {
        self::$sectionStack[] = $name;
        ob_start();
    }

    public static function stop(): void
    {
        $name = array_pop(self::$sectionStack);
        if ($name === null) throw new \LogicException('View::stop() without start()');
        self::$sections[$name] = (self::$sections[$name] ?? '') . ob_get_clean();
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    private static function capture(string $__file, array $__data): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (static function () use ($__file, $__data) {
                extract($__data, EXTR_SKIP);
                include $__file;
            })();
            return ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
    }
}
