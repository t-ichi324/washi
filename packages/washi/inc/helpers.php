<?php

/**
 * Washi global helpers — the everyday vocabulary of page files and views.
 * All are guarded with function_exists so an app may override any of them before autoload.
 *
 * Handler / flow : page() redirect() back() abort() json()
 * Views          : view() render() partial() title() h() url() csrf_field() csrf_token()
 * Input & state  : request() input() user() flash() flashes() old() err() has_err() env() app()
 * HTML bits      : checked() selected() disabled() options()
 * Debug          : dd() dump()
 */

use Washi\App;
use Washi\Auth;
use Washi\Handler;
use Washi\HttpException;
use Washi\Request;
use Washi\Response;
use Washi\Security;
use Washi\Session;
use Washi\View;

if (!function_exists('app')) {
    function app(): App { return App::current(); }
}

if (!function_exists('request')) {
    function request(): Request { return App::current()->request(); }
}

if (!function_exists('input')) {
    /** GET + POST + JSON body の値 */
    function input(string $key, mixed $default = null): mixed { return request()->input($key, $default); }
}

if (!function_exists('page')) {
    /**
     * Page handler builder: `return page()->auth()->csrf()->then(fn() => ...)` or `page(fn() => ...)`.
     */
    function page(?callable $action = null): Handler { return Handler::make($action); }
}

if (!function_exists('view')) {
    /** Render views/{template}.php inside the layout → HTML Response */
    function view(string $template, array $data = [], string|false|null $layout = null): Response
    {
        return Response::html(View::page($template, $data, $layout));
    }
}

if (!function_exists('render')) {
    /** Render a template to string, no layout */
    function render(string $template, array $data = []): string { return View::render($template, $data); }
}

if (!function_exists('partial')) {
    /** Echo a template in place (for use inside other templates) */
    function partial(string $template, array $data = []): void { echo View::render($template, $data); }
}

if (!function_exists('title')) {
    /** Set `$title` for the layout (and return it for inline use) */
    function title(string $title): string { View::share('title', $title); return $title; }
}

if (!function_exists('h')) {
    function h(string|int|float|bool|null $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/', array $query = []): string { return App::current()->url($path, $query); }
}

if (!function_exists('redirect')) {
    function redirect(string $to, int $status = 302): Response { return Response::redirect(url($to), $status); }
}

if (!function_exists('back')) {
    /** Redirect to the referer, optionally flashing validation errors and the submitted input. */
    function back(array $errors = [], bool $withInput = true): Response
    {
        if ($errors) Session::flash('_errors', $errors);
        if ($withInput) Session::flash('_old', request()->all());
        return Response::redirect(request()->referer() ?: url('/'));
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200): Response { return Response::json($data, $status); }
}

if (!function_exists('abort')) {
    function abort(int $status, string $message = ''): never { throw new HttpException($status, $message); }
}

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string { return \Washi\Env::get($key, $default); }
}

if (!function_exists('user')) {
    /** Current authenticated user (whatever was passed to Auth::login), or null */
    function user(): object|array|null { return Auth::user(); }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string { return Security::csrfToken(); }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string { return Security::csrfField(); }
}

if (!function_exists('flash')) {
    /** Queue a one-shot message for the next request: flash('success', '保存しました') */
    function flash(string $type, string $text): void { Session::flashPush('_messages', ['type' => $type, 'text' => $text]); }
}

if (!function_exists('flashes')) {
    /** @return list<array{type:string,text:string}> messages flashed by the previous request */
    function flashes(): array { return Session::pull('_messages', []); }
}

if (!function_exists('old')) {
    /** Previously submitted value (after back()), HTML-escaped */
    function old(string $key, string $default = ''): string
    {
        $v = \Washi\Cast::ensureScalar(Session::pull('_old', [])[$key] ?? $default);
        return h(is_scalar($v) ? (string)$v : $default);
    }
}

if (!function_exists('err')) {
    /** Validation error for a field as `<p class="form-error">…</p>`, or '' */
    function err(string $key, string $class = 'form-error'): string
    {
        $m = Session::pull('_errors', [])[$key] ?? null;
        if (is_array($m)) $m = reset($m);
        return $m ? '<p class="' . h($class) . '">' . h($m) . '</p>' : '';
    }
}

if (!function_exists('has_err')) {
    function has_err(string $key, string $class = ' has-error'): string
    {
        return isset(Session::pull('_errors', [])[$key]) ? $class : '';
    }
}

if (!function_exists('checked')) {
    function checked(bool $cond): string { return $cond ? ' checked' : ''; }
}
if (!function_exists('selected')) {
    function selected(bool $cond): string { return $cond ? ' selected' : ''; }
}
if (!function_exists('disabled')) {
    function disabled(bool $cond): string { return $cond ? ' disabled' : ''; }
}
if (!function_exists('options')) {
    /** `<option>` list from [value => label]; both escaped */
    function options(iterable $list, mixed $selected = null): string
    {
        $html = '';
        foreach ($list as $value => $label) {
            $html .= '<option value="' . h($value) . '"' . selected((string)$value === (string)$selected) . '>' . h($label) . '</option>';
        }
        return $html;
    }
}

if (!function_exists('dump')) {
    function dump(mixed ...$vars): void
    {
        foreach ($vars as $v) {
            if (PHP_SAPI === 'cli') var_dump($v);
            else echo '<pre style="background:#111;color:#eee;padding:1rem;overflow:auto">' . h(print_r($v, true)) . '</pre>';
        }
    }
}
if (!function_exists('dd')) {
    function dd(mixed ...$vars): never { dump(...$vars); exit(1); }
}
