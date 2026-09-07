# AI.md — Washi を初めて見る AI エージェントへ

> **English summary.** Washi (和紙) is a small PHP 8.1+ web framework with zero external dependencies.
> Shape: one front controller (`Washi\App`) → files under `pages/` are the URLs and each `return`s a closure →
> plain-PHP templates under `views/` → an optional DB layer (`washi/db`: query builder + typed `Entity`).
> Whatever a handler returns becomes the response (string → HTML, array/object → JSON, `redirect()` → 302).
> Auth / CSRF / roles are declared as a chain: `page()->auth()->csrf()->then(fn() => ...)`.
> Guiding rule: **the framework owns the HTTP boundary and the dangerous parts (escaping, CSRF, SQL binding,
> session, auth, error pages); the application owns its own organization** — no required folders, base
> classes or config files. Verify any change with `bash tests/smoke.sh`.

---

## 1. Washi とは何か

**「page + view」で Web アプリを書くための PHP フレームワーク**です。

- PHP 8.1 以上、Composer、外部ライブラリ依存なし
- 1 つのフロントコントローラ（`public/index.php`）が全リクエストを受ける
- `pages/` に置いたファイルが URL になる。ファイルは `return fn() => ...` を返すだけ
- `views/` は素の PHP テンプレート。`_layout.php` があれば自動で包まれる
- ハンドラの**戻り値がそのまま応答**になる（文字列は HTML、配列や Entity は JSON）
- 認証・CSRF・ロールは `page()->auth()->csrf()->then(...)` のチェーンで宣言
- DB は任意。未設定なら SQLite（`storage/app.db`）が自動で使われる
- クラス、継承、コントローラ、モデル層、設定ファイルは**一切要求しない**。必要なら使える

### 設計原則（これだけ覚えればよい）

> **フレームワークは「境界」と「危険物」だけを所有し、整理はアプリに任せる。**

境界 = Request を受けて Response を返す場所。危険物 = HTML エスケープ、CSRF、SQL バインド、セッション、認証、エラー画面。
ここは Washi が握る。フォルダ構成、クラス分割、Model の有無は事故に直結しないので Washi は決めない。

設計で迷ったときは次の 2 問が両方 Yes になる案を選ぶ：
1. Hello World は 1 ファイル 10 行以内で書けるか（→ `examples/hello/index.php`）
2. 50 画面のアプリを、フレームワークが何も要求しない状態で読みやすく置けるか（→ `examples/notes/`）

---

## 2. サポートしている機能

| 領域 | できること | 担当 |
|---|---|---|
| ルーティング | `pages/` ファイル解決（最長一致、HTTP メソッド別ファイル `edit.post.php`）、明示ルート `$app->get('/x/{id}', fn)`、`{id:\d+}` 正規表現、405 判定 | `App` |
| ハンドラ | クロージャ引数の自動解決（URL パラメータを名前→位置で束縛、`int`/`float` 検証、`Request` 型注入） | `Handler` |
| ミドルウェア | `fn(Request, Closure $next)` 形式。グローバル `$app->use()`、ハンドラ単位 `->use()`、PHP Attribute `#[RequireAuth]` も可 | `Handler`, `Middleware/*` |
| 組み込みミドルウェア | `->auth()` 未ログインをログイン画面へ、`->guest()`、`->roles('admin')`、`->csrf()` | `Middleware/*` |
| リクエスト | GET + POST + JSON body を統合した型付き取得 `str/int/float/bool/arr/all/only/file`、`wantsJson`、`isAjax`、`_method` オーバーライド、信頼プロキシ考慮の `ip()` | `Request` |
| レスポンス | `html/json/redirect/text/file/download/empty`、`->status()->header()->cookie()`。戻り値の自動変換 | `Response`, `App::normalize` |
| ビュー | 素の PHP、`extract` された変数、レイアウト自動包み、`partial()`、`View::share()`、セクション `View::start()/stop()/section()` | `View` |
| セッション | 遅延開始、HttpOnly/SameSite=Lax/Secure 自動、flash（1 回だけ読める値） | `Session` |
| 認証 | `Auth::login($user)` に任意のオブジェクト/配列、`check/user/id/roles/hasRole`、Remember-me（opt-in） | `Auth` |
| セキュリティ | CSRF トークン（セッション単位、`csrf_field()`、ヘッダ `X-CSRF-TOKEN` も可）、`password_hash` ラッパ、既定のセキュリティヘッダ、pages のパストラバーサル防止、外部 URL への `url()` 素通し防止なし（注: `redirect()` は与えた URL をそのまま使う） | `Security`, `App` |
| フォーム補助 | `back($errors)` でエラーと入力値を flash → `err()` `old()` `has_err()` で再表示 | helpers |
| DB | PDO クエリビルダ（where/join/rel/relMany/page/upsert/chunk など）、型付き `Entity`（find/where/save/delete、dirty tracking、リレーション格納）、`Paginated`、SQL ファイルマイグレーション、SQLite/MySQL/PostgreSQL、ネストトランザクション、クエリ観測フック | `washi/db` |
| エラー処理 | `abort(404)`、`HttpException`、`Db\NotFoundException`→404、`views/_error.{status}.php`→`_error.php`→組み込み画面、`Accept: application/json` なら JSON、デバッグ時はコードプレビュー付き | `App`, `Debug` |
| デバッグ | `APP_DEBUG=true` で HTML 応答の末尾に処理時間・メモリ・SQL 一覧のパネルを注入 | `Debug` |
| 設定 | `.env`（実環境変数が優先）+ `new App($root, [...])` の配列。ini などは無い | `Env`, `App` |
| ログ | 日別ファイル（`storage/log/`）または stderr | `Log` |
| 配置 | サブディレクトリ設置（`basePath` 自動検出）、PHP 組み込みサーバー、Apache/nginx の index.php フォールバック | `Request`, `App` |

### 提供していないもの（意図的）
テンプレートエンジン（素の PHP で足りる）、DI コンテナ、ORM のリレーション定義 DSL（`rel()/relMany()` の Eager Load のみ）、
バリデータ（未実装、§9 参照）、キュー、メール、キャッシュ、ファイルストレージ抽象、CLI ツール（未実装）。

---

## 3. アプリの構造（例: examples/notes）

```
myapp/
├── public/index.php        ← 3 行。 $app = new Washi\App(dirname(__DIR__)); $app->run();
├── pages/                  ← URL = ファイル。return で Closure / page()->…->then() / Response / 文字列 / 配列
│   ├── index.php           GET /
│   ├── about.php           GET /about（return せず echo だけでも動く。ただしミドルウェアは掛からない）
│   ├── notes/new.php       GET  /notes/new
│   ├── notes/new.post.php  POST /notes/new
│   ├── notes/edit.php      GET  /notes/edit/{id}   ← 余ったセグメントが引数 fn(int $id)
│   └── api/notes.php       GET  /api/notes         ← Paginated を返すと JSON
├── views/                  ← 素の PHP。view('notes/form') = views/notes/form.php
│   ├── _layout.php         $content と $title を受ける。`_` 始まりは pages から到達不可
│   ├── _error.php          $status $message $exception を受ける（任意）
│   └── notes/{index,form}.php
├── lib/                    ← 任意。クラス名 = ファイル名で自動ロード（名前空間は先頭セグメントを剥がして探す）
│   └── Note.php            class Note extends Washi\Db\Entity { public ?int $id = null; public string $title = ''; }
├── db/migrations/*.sql     ← 名前順に 1 回だけ実行。DB_AUTO_MIGRATE=true か $app->migrate()
├── storage/                ← app.db（既定 SQLite）、log/
└── .env                    ← APP_DEBUG, DB_* など。無くても動く
```

**必須なのは `public/index.php` だけ。** 他のフォルダは「存在すれば使われる」。名前は `new App($root, ['pages' => 'src/pages'])` のように変更可。

---

## 4. リクエストのライフサイクル

```
public/index.php   $app = new App($root); $app->run();
App::__construct   .env 読込 → config 配列 → Log/Session/Security/View の configure → lib/ autoload 登録 → DB 接続登録（遅延）
run()              Request::fromGlobals() → handle() → Response::send()
handle()           auto_migrate? → resolve() → Handler::run(グローバル MW → ハンドラ MW → アクション) → normalize() → セキュリティヘッダ → デバッグパネル
resolve()          1) 明示ルート（$app->get() 等）  2) pages/ 解決  3) 405 か 404
例外               HttpException → その status / Db\NotFoundException → 404 / その他 → 500（Log に記録）
                   wantsJson → JSON / views/_error.{status}.php → views/_error.php → Debug::errorPage
```

### pages/ の解決規則（`/a/b/c`、m = get|post|put|patch|delete、HEAD は get 扱い）
最長一致で探す。セグメントは `[A-Za-z0-9_.-]+` のみ、`_` か `.` 始まりは拒否（`_layout` などの私有ファイル保護とトラバーサル防止）。
```
pages/a/b/c.{m}.php → pages/a/b/c.php → pages/a/b/c/index.{m}.php → pages/a/b/c/index.php   params = []
pages/a/b.{m}.php   → pages/a/b.php                                                        params = ["c"]
pages/a.{m}.php     → pages/a.php                                                          params = ["b","c"]
pages/index.{m}.php → pages/index.php                                                      params = ["a","b","c"]
```
ページファイルの `return` の解釈（`App::loadPage`）:

| return したもの | 扱い |
|---|---|
| `Closure` | アクション。`Handler::make($closure)` |
| `Handler`（`page()->auth()->then(fn)`） | そのまま。`then()` 未呼び出しは LogicException |
| `Response` / string / array / object | 即値。ミドルウェアなしで normalize |
| 何も return しない | include 中の echo 出力を HTML 応答にする（素の PHP モード。ミドルウェアは掛からない） |

ページファイルのスコープには `$request`（Request）と `$app`（App）が見える。

### アクション引数の解決（`Handler::invoke`）
1. `Request` 型の引数 → 現在のリクエスト
2. 明示ルートの `{name}` と同名の引数 → 名前で束縛
3. それ以外 → 位置順（pages の余りセグメントはここに入る）
4. `int`/`float` は形式検証し、不正なら 404。`bool` は Cast。デフォルト値と nullable は許容。足りなければ 404

### 戻り値の正規化（`App::normalize`）
| 戻り値 | 応答 |
|---|---|
| `Response` | そのまま |
| `null` | 204 |
| string / Stringable | 200 text/html |
| int | そのステータスで空ボディ |
| `Paginated` | JSON `{data, total, page, per_page, last_page}` |
| `toArray()` を持つオブジェクト（Collection 等） | JSON（toArray の結果） |
| array / JsonSerializable（Entity 含む） / その他オブジェクト | JSON |

---

## 5. よくある作業のレシピ

**ページを追加する**
```php
// pages/reports/monthly.php  → GET /reports/monthly/{year}/{month}
return fn(int $year, int $month = 1) => view('reports/monthly', ['title' => "{$year}年{$month}月", 'rows' => Sales::where('ym', "$year-$month")->all()]);
```

**ログイン必須 + POST 保存**
```php
// pages/items/save.post.php
return page()->auth()->csrf()->then(function (Washi\Request $r) {
    if ($r->str('name') === '') return back(['name' => '名前は必須です']);
    Item::create($r->only(['name', 'price']));
    flash('success', '保存しました');
    return redirect('/items');
});
```

**ログイン画面**
```php
// pages/login.php
return page()->guest()->then(fn() => view('login', ['title' => 'ログイン']));
// pages/login.post.php
return page()->guest()->csrf()->then(function (Washi\Request $r) {
    $u = User::first('email', $r->str('email'));
    if (!$u || !Washi\Security::verifyPassword($r->str('password'), $u->password_hash)) return back(['email' => 'メールかパスワードが違います']);
    Washi\Auth::login($u);                 // $u->roles か $u->role があれば ->roles('admin') で使える
    return redirect('/');
});
// pages/logout.post.php
return page()->csrf()->then(function () { Washi\Auth::logout(); return redirect('/login'); });
```

**JSON API**
```php
// pages/api/items.php            GET  /api/items?page=2  → Paginated envelope
return fn(Washi\Request $r) => Item::query()->orderBy('id', 'desc')->page($r->int('page', 1), 20);
// pages/api/items.post.php       POST /api/items  (JSON body OK)
return fn(Washi\Request $r) => json(Item::find(Item::create($r->only(['name']))), 201);
```
API クライアントには `Accept: application/json` を付けさせると、エラーも JSON で返る。CSRF はセッション Cookie を使わない API なら不要。

**独自ミドルウェア**
```php
// lib/RequireCan.php
#[\Attribute(\Attribute::TARGET_FUNCTION)]
class RequireCan implements Washi\Middleware\Middleware {
    public function __construct(private string $perm) {}
    public function __invoke(Washi\Request $r, Closure $next): mixed {
        if (!can($this->perm)) abort(403);
        return $next($r);
    }
}
// 使う側: return page()->auth()->use(new RequireCan('items.edit'))->then(fn() => ...);
// または:  return #[RequireCan('items.edit')] fn() => ...;   （Attribute は Handler が自動収集）
// 全ページ共通: $app->use(fn($r, $next) => ... ) を public/index.php で
```

**Entity を定義する（これが「モデル」のすべて）**
```php
// lib/Item.php
class Item extends Washi\Db\Entity {
    protected static ?string $table = 'items';     // 省略時は snake_case(クラス名)+'s'
    public ?int $id = null; public string $name = ''; public int $price = 0; public ?string $created_at = null;
    protected static function defaultScope(Washi\Db\Query $q): void { $q->where('items.deleted_at', null); } // 任意
}
Item::find(1); Item::findOrFail(1); Item::where('price', '>', 100)->orderBy('price')->all();
$i = new Item(['name' => 'a']); $i->save();  $i->price = 5; $i->save();  $i->delete();
Item::query()->rel(Category::class)->all();   // N:1 Eager Load → $item->rel(Category::class)?->name
Washi\Db\Db::table('logs')->insert([...]);    // Entity を作らず素のテーブル操作
Washi\Db\Db::transaction(fn() => ...);
```

**レイアウトとセクション**
```php
// views/_layout.php
<title><?= h($title ?? 'App') ?></title> ... <main><?= $content ?></main> <?= Washi\View::section('scripts') ?>
// views/some.php
<?php Washi\View::start('scripts'); ?><script>...</script><?php Washi\View::stop(); ?>
// レイアウトを変える: view('x', $data, layout: '_layout.admin')   無し: layout: false
```

**マイグレーション**: `db/migrations/002_add_price.sql` を置く → `.env` に `DB_AUTO_MIGRATE=true` か `php -r '(new Washi\App(__DIR__))->migrate();'`。

**本番配置**: DocumentRoot を `public/` に。Apache は `.htaccess` で `FallbackResource /index.php`、nginx は `try_files $uri /index.php?$query_string`。サブディレクトリでも `url()` がプレフィックスを付ける。`APP_DEBUG=false`、`LOG_TARGET=stderr`（コンテナ）。

---

## 6. ヘルパー一覧（`packages/washi/inc/helpers.php`、全て `function_exists` ガード付き）

| 関数 | 返り値 | 用途 |
|---|---|---|
| `page(?callable)` | `Handler` | `return page()->auth()->csrf()->then(fn() => ...)` |
| `view($tpl, $data=[], $layout=null)` | `Response` | `views/$tpl.php` をレイアウトで包んで HTML |
| `render($tpl, $data)` | string | レイアウトなしの文字列 |
| `partial($tpl, $data)` | void | テンプレート内で別テンプレートを echo |
| `title($t)` | string | レイアウトの `$title` を設定 |
| `redirect($to, 302)` | `Response` | `url()` を通す |
| `back($errors=[], $withInput=true)` | `Response` | Referer へ。`_errors` `_old` を flash |
| `json($data, 200)` | `Response` | |
| `abort($status, $msg='')` | never | `HttpException` |
| `request()` / `input($k, $d=null)` | | 現在の Request / 統合入力 |
| `user()` | object\|array\|null | `Auth::user()` |
| `flash($type, $text)` / `flashes()` | | 次のリクエストで 1 回だけ表示するメッセージ |
| `old($k, $d='')` / `err($k)` / `has_err($k)` | string | フォーム再表示（エスケープ済み） |
| `csrf_field()` / `csrf_token()` | string | `<input type=hidden name=_token>` |
| `url($path, $query=[])` | string | サブディレクトリのプレフィックス付与。絶対 URL は素通し |
| `h($v)` | string | HTML エスケープ（ENT_QUOTES, UTF-8） |
| `checked/selected/disabled($bool)` / `options($list, $sel)` | string | HTML 属性の出力 |
| `env($k, $d)` / `app()` | | |
| `dump(...)` / `dd(...)` | | デバッグ |

---

## 7. 設定（`new App($root, $config)` と環境変数）

| config キー | 既定 | 環境変数 | 意味 |
|---|---|---|---|
| `debug` | false | `APP_DEBUG` | 例外詳細・デバッグパネル・E_ALL |
| `debug_panel` | true | | debug 時に HTML 末尾へパネル注入 |
| `timezone` | Asia/Tokyo | `APP_TIMEZONE` | |
| `pages` `views` `lib` `storage` | 同名 | | ディレクトリ名（root 相対か絶対） |
| `migrations` | db/migrations | | |
| `auto_migrate` | false | `DB_AUTO_MIGRATE` | 毎リクエスト先頭で未実行 SQL を流す（開発用） |
| `layout` | _layout | | `view()` の既定レイアウト |
| `login_page` | /login | `APP_LOGIN_PAGE` | `->auth()` の遷移先 |
| `session_name` | washi_sid | `SESSION_NAME` | |
| `csrf_token` | _token | | フィールド名 |
| `log` | storage | `LOG_TARGET` | `storage` → storage/log/日付.log、`stderr`、または絶対パス |
| `trusted_proxies` | '' | `TRUSTED_PROXIES` | `Request::ip()` で X-Forwarded-For を信頼する送信元（`*` 可） |
| `security_headers` | true | | X-Frame-Options / nosniff / Referrer-Policy |

DB 環境変数: `DB_DRIVER`（sqlite / mysql / pgsql。未設定なら SQLite `storage/app.db`）、`DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_CHARSET DB_TIMEZONE DB_SQL_MODE DB_SCHEMA DB_SQLITE_PATH DB_SOCKET`、`DB_LOG=true` で全クエリを Log へ。
コードからは `Washi\Db\Db::addConnection('default', Washi\Db\Connection::sqlite($path))` などで上書き可（`new App()` の前に呼ぶ）。

---

## 8. リポジトリ地図（開発者・エージェント向け）

```
washi/
├── AI.md  CLAUDE.md  README.md
├── composer.json            開発用 monorepo。path repository で packages/* を symlink。利用者は各パッケージを require する
├── packages/
│   ├── support/  washi/support   Washi\Collection（配列ラッパ）, Washi\Cast（安全な型変換）          依存なし
│   ├── db/       washi/db        Washi\Db\{Db, Connection, Query, Entity, Paginated, Migration, NotFoundException}   support のみ
│   └── washi/    washi/washi     Washi\{App, Handler, Request, Response, View, Session, Auth, Security, Env, Log, Debug, HttpException}
│                                 Washi\Middleware\{Middleware, RequireAuth, RequireGuest, RequireRole, VerifyCsrf}   inc/helpers.php
├── examples/hello/index.php     1 ファイル版（明示ルートのみ、DB 自動 SQLite）
├── examples/notes/              pages + views + lib/Note.php + db/migrations の CRUD
└── tests/smoke.sh               両サンプルを PHP 組み込みサーバーで起動し curl で 27 項目を検証
```

### 依存の向き（守ること）
`support ← db ← washi`。`washi/db` から `Washi\App` `Washi\Request` などを参照してはいけない。
DB 層がログやトレースを必要とする場合は `Db::listen(fn($sql, $params, $sec, $connKey, $kind))` に外から注入する。

### 各ファイルの責務（HTTP 層）
| ファイル | 責務 |
|---|---|
| `App.php` | 起動、設定、ルート登録 API、pages 解決、`normalize()`、例外→応答、デバッグパネル、`App::current()` |
| `Handler.php` | ミドルウェアチェーン + アクション。引数解決。`->auth()/guest()/roles()/csrf()/use()/then()` |
| `Request.php` / `Response.php` | 値オブジェクト。`Request::fromGlobals()` / `Response::send()` だけが SAPI に触る |
| `View.php` | テンプレート include とレイアウト。`ob_start` で捕捉、例外時にバッファを片付ける |
| `Session.php` | `$_SESSION` の遅延開始と flash（`_flash` キー配下、1 リクエスト後に消える） |
| `Auth.php` / `Security.php` | セッションキー `_auth` / `_csrf` |
| `Debug.php` | 組み込みエラー HTML（コードプレビュー）とパネル。アプリの views には依存しない |
| `Middleware/*` | それぞれ 1 クラス 1 責務。`#[\Attribute]` 付きなので closure に付けられる |

---

## 9. 未実装・次の候補（優先順）

1. **バリデーション**: `$r->validate(['title' => 'required|max:100'])` が失敗時に `back($errors)` 相当を投げる最小実装
2. **CLI `bin/washi`**: `serve`、`make:page`、`make:entity`（DB スキーマから Entity 生成）、`migrate`
3. **`Entity::sync()`**: 型付きプロパティから SQLite の CREATE TABLE を生成（試作用）
4. **`RequireRole` の判定フック**: ロール文字列以外の権限モデル（`can()` 相当）を差し込めるようにする
5. **Packagist 公開**: `washi/support` `washi/db` `washi/washi`（ベンダー名は 2026-09 時点で空き）
6. `Response` のストリーミング、Cookie API の整理

---

## 10. 作業ルール

1. 変更したら **`bash tests/smoke.sh`**（`failed: 0` を確認）。新しい挙動には check を 1 行足す
2. 「要求」を増やす変更（必須フォルダ、必須基底クラス、必須設定）は原則しない。まず helpers か `Handler` のメソッドで「提供」できないか考える
3. 依存の向きを守る（§8）。`washi/db` に HTTP 層の参照を入れない
4. 新しいファイルは先頭 docblock に「何をするか・どう使うか」を書く（全ファイルがそうなっている）
5. 日本語コメント可、識別子は英語
6. 設計判断を変えるときは §11 の表の「理由」も書き換える

---

## 11. 履歴と設計判断の記録（読み飛ばしてよい）

Washi の前身は同じ作者の **fzr**（Feather、`../fzr/fzr-fw`）。fzr は軽量で良い部品を持っていたが、
Controller 継承・`app/controllers` 規約・`app.ini`+`.env`+`bootstrap.php`・`***Controller` 命名など
「フレームワークが要求する構造」が多く、Hello World に 6 ファイル必要だった。
Washi は fzr の部品を流用し、構造の要求をゼロにした別プロジェクト。**fzr との互換性はなく、fzr は既存案件用にそのまま残す。**
fzr を知らなくても Washi を扱う上で困ることはない。

### fzr からの移植状況
- そのまま: `Db/Query` `Db/Connection` `Db/Paginated` `Db/Migration` `Db/Db`（Logger/Tracer 呼び出しを `Db::listen` に置換）、`Collection`、`DataHelper`→`Cast`、Request の型付き入力、CSRF 方式、エラー画面のコードプレビュー、HTML 属性ヘルパー
- 形を変えて: `Model`+`Entity`→`Entity` に統合、`Attr\Http\*`→`Middleware\*`、配列ディスクリプタの `Response`→値オブジェクト、`Render`→`View`、`Message`→`flash()`
- 持ち込まなかった: 規約ルーティングの `Engine`、`Controller`、`Bag`/`Store`、`Config`/`Context`、`Form`/`FormValidator`/`Attr\Field`、Session の cookie/redis ドライバ、`Cache`、`Storage`、`Cookie`、`Url`/`Path`、UA 判定、`ModelGenerator`（CLI 実装時に再移植予定）

### 設計判断と理由
| 判断 | 理由 |
|---|---|
| Model 層を廃止し `Entity` を「型付きの 1 行」という部品としてだけ残す | Web MVC の M は曖昧で、前身では Model/Bag/Store/Entity に分裂した。層として要求する価値がない |
| `pages/` のファイル = URL、`return fn() => ...` | クラス名・メソッド名・ルート登録の三重記述をなくし、素の PHP の手軽さに戻す |
| フロントコントローラは残す（純粋な file=URL にしない） | 認証・CSRF・エラー処理を全ファイル先頭に書く世界に戻らないため。ミドルウェアをここで通す |
| 戻り値規約 | `Response::view()` のような包む作業を消し、1 行ハンドラを可能にする |
| ミドルウェアは `fn(Request, Closure $next)`、Attribute 記法も可 | 前身は Attribute を if 文で列挙しておりアプリが追加できなかった |
| 設定は `.env` + `new App()` の配列のみ | 設定ファイルの種類を 1 つに |
| DB 未設定なら SQLite 自動 | 「サクっと」の核。DB 設計を後回しにできる |
| `washi/db` は HTTP 層に依存しない | 単体ライブラリとして他プロジェクトにも `require` できる状態を保つ |
| 静的ファサードは `Auth` `Session` `Security` `View` `Log` `Db` に限定し、`Request`/`Response` はインスタンス | ミドルウェアが Request を受け Response を返す形にするため。`request()` ヘルパーで手軽さは維持 |
| 名前 Washi | 「薄くて軽いのに強い」紙。page = 1 枚の紙。Packagist の `washi/*` が空いていた |
