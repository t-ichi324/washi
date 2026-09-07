# Washi（和紙）

**page + view で書く、軽くて薄い PHP フレームワーク。** PHP 8.1+、外部依存なし。

- `pages/` のファイルが URL。`return fn() => ...` を書くだけ。クラスも継承も要らない
- `views/` は素の PHP テンプレート。`_layout.php` があれば自動で包む
- 戻り値が応答: 文字列は HTML、配列や Entity は JSON、`redirect()` はリダイレクト
- 認証・CSRF はチェーンで宣言: `page()->auth()->csrf()->then(...)`
- DB は未設定なら SQLite が自動。`washi/db` のクエリビルダと型付き `Entity` は単体でも使える

## 最小

```php
<?php // index.php
require 'vendor/autoload.php';
$app = new Washi\App(__DIR__);
$app->get('/', fn() => '<h1>Hello</h1>');
$app->get('/api/time', fn() => ['now' => date('c')]);
$app->run();
```

## 育てる

```
public/index.php          $app = new Washi\App(dirname(__DIR__)); $app->run();
pages/index.php           return fn() => view('home', ['notes' => Note::all()]);
pages/notes/edit.php      return fn(int $id) => view('notes/form', ['note' => Note::findOrFail($id)]);
pages/notes/edit.post.php return page()->csrf()->then(fn(int $id, Washi\Request $r) => ...);
views/_layout.php         <?= $content ?> を置く
views/notes/form.php
lib/Note.php              class Note extends Washi\Db\Entity { public ?int $id = null; public string $title = ''; }
db/migrations/001.sql
.env                      APP_DEBUG=true / DB_* （無ければ storage/app.db）
```

サンプル: [examples/hello](examples/hello/index.php)（1 ファイル）、[examples/notes](examples/notes/)（CRUD 一式）。

```bash
composer install
php -S localhost:8080 examples/hello/index.php
# or
cd examples/notes && php -S localhost:8080 -t public public/index.php
bash tests/smoke.sh     # E2E スモークテスト
```

## パッケージ

| パッケージ | 内容 |
|---|---|
| `washi/washi` | App, Request, Response, View, Handler, Middleware, Session, Auth, Security, helpers |
| `washi/db` | Query ビルダ, Connection, Entity, Paginated, Migration（HTTP 層に依存しない） |
| `washi/support` | Collection, Cast |

設計方針・内部構造・作業ルールは [AI.md](AI.md) に。前身の fzr からの移植状況も同ファイルに記載。

MIT License
