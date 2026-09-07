# washi/db

PDO query builder + typed Entity + pagination + SQL migrations. No HTTP dependency; usable in any PHP project.

```php
use Washi\Db\{Db, Connection, Entity};

Db::addConnection('default', Connection::sqlite(__DIR__ . '/app.db'));   // or Connection::fromEnv('DB')
Db::listen(fn($sql, $params, $sec) => error_log($sql));                // optional query observer

class User extends Entity { public ?int $id = null; public string $name = ''; }

User::where('name', 'like', 'a%')->orderBy('id')->page(1, 20);
$u = User::findOrFail(3); $u->name = 'b'; $u->save();
Db::table('logs')->insert(['msg' => 'x']);
Db::migrate(__DIR__ . '/db/migrations');
```
See ../../AI.md for the full map.
