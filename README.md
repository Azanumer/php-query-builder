# php-query-builder

A tiny, fluent, dependency-free query builder on top of PDO. PHP 7.4+.

```php
$qb = new QueryBuilder($pdo);

// SELECT
$users = $qb->table('users')
    ->select('id', 'name', 'email')
    ->where('age', '>=', 18)
    ->where('status', 'active')
    ->whereIn('country', ['PK', 'AE'])
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->get();

// JOIN
$rows = $qb->table('orders')
    ->select('orders.id', 'users.name AS buyer')
    ->join('users', 'orders.user_id', '=', 'users.id')
    ->where('orders.total', '>', 100)
    ->get();

// INSERT → last insert id
$id = $qb->table('users')->insert(['name' => 'Ayesha', 'age' => 28]);

// UPDATE / DELETE → affected rows (throw without a where())
$qb->table('users')->where('id', $id)->update(['age' => 29]);
$qb->table('users')->where('status', 'banned')->delete();

// COUNT / EXISTS / FIRST
$n = $qb->table('users')->where('age', '>=', 18)->count();
if ($qb->table('users')->where('email', $email)->exists()) { /* ... */ }
$user = $qb->table('users')->where('id', $id)->first(); // row or null

// Inspect the SQL (placeholders :w0, :w1…)
echo $qb->table('users')->where('age', '>', 20)->getSql();
```

## Design

- **Every value is bound** as a named parameter (`:w0`, `:w1`, …) — never
  interpolated into the SQL.
- **Identifiers are backtick-quoted** (`table.column`, `column AS alias`
  supported; embedded backticks escaped by doubling).
- `update()` and `delete()` **refuse to run without a `where()`** —
  a `RuntimeException` instead of a wiped table.
- `where()` with two arguments means `=`; allowed operators are whitelisted
  (`=`, `<>`, `!=`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`).
- The builder **resets after every execution**, so one instance is reusable.
- `getSql()` returns the SQL of the current/last query with `:wN`
  placeholders (values stay bound — check them separately if debugging).

## Files

- `src/QueryBuilder.php` — the whole library (one class)
- `examples/query-builder-example.php` — SQLite in-memory demo
  (`php examples/query-builder-example.php`)

## Requirements

PHP 7.4+, any PDO driver (MySQL, SQLite, PostgreSQL, …).

## License

MIT — see `LICENSE`.
