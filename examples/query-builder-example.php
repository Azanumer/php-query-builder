<?php
/**
 * QueryBuilder demo — runs against an in-memory SQLite database.
 *
 * php examples/query-builder-example.php
 */

require __DIR__ . '/../src/QueryBuilder.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE users (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    name   TEXT NOT NULL,
    email  TEXT NOT NULL,
    age    INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");

$qb = new QueryBuilder($pdo);

// --- INSERT (returns last insert id) ---
$id1 = $qb->table('users')->insert(array(
    'name'   => 'Ayesha Khan',
    'email'  => 'ayesha@example.com',
    'age'    => 28,
    'status' => 'active',
));
$id2 = $qb->table('users')->insert(array(
    'name'   => 'Bilal Ahmed',
    'email'  => 'bilal@example.com',
    'age'    => 17,
    'status' => 'active',
));
$id3 = $qb->table('users')->insert(array(
    'name'   => 'Sana Malik',
    'email'  => 'sana@example.com',
    'age'    => 34,
    'status' => 'active',
));
echo "Inserted ids: $id1, $id2, $id3\n\n";

// --- SELECT with WHERE + ORDER BY ---
$adults = $qb->table('users')
    ->select('name', 'age')
    ->where('age', '>=', 18)
    ->where('status', 'active')
    ->orderBy('age', 'DESC')
    ->get();
echo "Adults (desc by age):\n";
print_r($adults);

// --- WHERE IN + LIMIT/OFFSET ---
$page = $qb->table('users')
    ->whereIn('id', array($id1, $id2, $id3))
    ->orderBy('id')
    ->limit(2)
    ->offset(1)
    ->get();
echo "Page 2 (limit 2, offset 1):\n";
print_r($page);

// --- FIRST (row or null) ---
$user = $qb->table('users')->where('email', 'ayesha@example.com')->first();
echo 'First match: ' . $user['name'] . ' (' . $user['age'] . ")\n";

$missing = $qb->table('users')->where('email', 'nope@example.com')->first();
echo 'Missing user: ' . var_export($missing, true) . "\n\n";

// --- UPDATE (returns affected rows; throws without where) ---
$updated = $qb->table('users')
    ->where('id', $id2)
    ->update(array('status' => 'inactive', 'age' => 18));
echo "Updated rows: $updated\n";

// --- COUNT + EXISTS ---
$count  = $qb->table('users')->where('status', 'active')->count();
$exists = $qb->table('users')->where('email', 'nope@example.com')->exists();
echo "Active users: $count | nope@example.com exists: " . var_export($exists, true) . "\n\n";

// --- getSql() — inspect the generated SQL (with :wN placeholders) ---
$sql = $qb->table('users')
    ->select('name AS username', 'age')
    ->where('age', '>', 20)
    ->orderBy('name')
    ->getSql();
echo "Generated SQL:\n$sql\n\n";

// --- DELETE (returns affected rows; throws without where) ---
$deleted = $qb->table('users')->where('status', 'inactive')->delete();
echo "Deleted rows: $deleted\n";
echo 'Remaining users: ' . $qb->table('users')->count() . "\n";
