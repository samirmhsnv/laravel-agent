<?php

use SamirMhsnv\LaravelAgent\Exceptions\UnsafeQueryException;
use SamirMhsnv\LaravelAgent\Sql\SqlValidator;

function sqlValidator(): SqlValidator
{
    return new SqlValidator(['orders', 'customers', 'analytics.events']);
}

it('accepts read-only queries over allowed tables', function (string $sql) {
    expect(sqlValidator()->validate($sql))->toBeString();
})->with([
    'simple' => 'SELECT id, total FROM orders',
    'lowercase' => 'select * from orders where status = \'paid\'',
    'trailing semicolon' => 'SELECT COUNT(*) FROM orders;',
    'join with aliases' => 'SELECT c.name, SUM(o.total) AS revenue FROM orders o JOIN customers AS c ON c.id = o.customer_id GROUP BY c.name ORDER BY revenue DESC',
    'left join' => 'SELECT * FROM customers c LEFT OUTER JOIN orders o ON o.customer_id = c.id',
    'comma join' => 'SELECT * FROM orders o, customers c WHERE o.customer_id = c.id',
    'subquery' => 'SELECT * FROM customers WHERE id IN (SELECT customer_id FROM orders WHERE total > 100)',
    'derived table' => 'SELECT t.n FROM (SELECT COUNT(*) AS n FROM orders) t',
    'cte' => 'WITH big AS (SELECT * FROM orders WHERE total > 100) SELECT COUNT(*) FROM big',
    'recursive cte with columns' => 'WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 5) SELECT n FROM seq',
    'schema qualified' => 'SELECT * FROM analytics.events',
    'quoted identifiers' => 'SELECT "id" FROM "orders"',
    'keywords inside strings' => "SELECT * FROM orders WHERE note = 'please DELETE this; DROP TABLE x -- later'",
    'escaped quote' => "SELECT * FROM customers WHERE name = 'O''Brien'",
    'extract from' => 'SELECT EXTRACT(YEAR FROM created_at) FROM orders',
    'is distinct from' => 'SELECT * FROM orders WHERE status IS DISTINCT FROM previous_status',
    'replace function' => "SELECT REPLACE(name, 'a', 'b') FROM customers",
    'no table' => 'SELECT 1',
    'column named like a keyword prefix' => 'SELECT updated_at, created_by, deleted_at FROM orders',
]);

it('rejects anything that is not a single read-only SELECT', function (string $sql, string $reason) {
    expect(fn () => sqlValidator()->validate($sql))->toThrow(UnsafeQueryException::class, $reason);
})->with([
    'empty' => ['   ', 'empty'],
    'insert' => ['INSERT INTO orders (id) VALUES (1)', 'only SELECT'],
    'update' => ['UPDATE orders SET total = 0', 'only SELECT'],
    'delete' => ['DELETE FROM orders', 'only SELECT'],
    'drop' => ['DROP TABLE orders', 'only SELECT'],
    'stacked statements' => ['SELECT * FROM orders; DROP TABLE orders', 'single statement'],
    'stacked after semicolon' => ['SELECT 1;;', 'single statement'],
    'line comment' => ['SELECT * FROM orders -- WHERE tenant = 1', 'comments'],
    'block comment' => ['SELECT * FROM orders /* hi */', 'comments'],
    'mysql executable comment' => ['SELECT 1 /*!50000 , SLEEP(5) */', 'comments'],
    'hash comment' => ['SELECT * FROM orders # x', 'comments'],
    'data-modifying cte' => ['WITH gone AS (DELETE FROM orders RETURNING *) SELECT * FROM gone', 'DELETE'],
    'select into' => ['SELECT * INTO backup FROM orders', 'INTO'],
    'into outfile' => ["SELECT * FROM orders INTO OUTFILE '/tmp/x'", 'INTO'],
    'for update lock' => ['SELECT * FROM orders FOR UPDATE', 'UPDATE'],
    'pragma' => ['PRAGMA table_info(orders)', 'only SELECT'],
    'attach' => ["SELECT 1 FROM orders WHERE 1 = (ATTACH 'x.db' AS x)", 'ATTACH'],
    'sleep' => ['SELECT SLEEP(10) FROM orders', 'SLEEP()'],
    'pg_sleep' => ['SELECT pg_sleep(10)', 'PG_SLEEP()'],
    'load_extension' => ["SELECT load_extension('evil')", 'LOAD_EXTENSION()'],
    'file read' => ["SELECT pg_read_file('/etc/passwd')", 'PG_READ_FILE()'],
    'table not allowed' => ['SELECT * FROM users', 'table [users] is not allowed'],
    'join to disallowed table' => ['SELECT * FROM orders JOIN users ON users.id = orders.user_id', '[users]'],
    'comma join to disallowed table' => ['SELECT * FROM orders o, users u', '[users]'],
    'subquery on disallowed table' => ['SELECT * FROM orders WHERE customer_id IN (SELECT id FROM users)', '[users]'],
    'union with disallowed table' => ['SELECT id FROM orders UNION SELECT password FROM users', '[users]'],
    'scalar subquery in function' => ['SELECT COALESCE((SELECT password FROM users LIMIT 1), 1) FROM orders', '[users]'],
    'array subquery' => ['SELECT ARRAY(SELECT password FROM users)', '[users]'],
    'quoted disallowed table' => ['SELECT * FROM "users"', '[users]'],
    'system catalog' => ['SELECT sql FROM sqlite_master', '[sqlite_master]'],
    'information schema' => ['SELECT * FROM information_schema.tables', '[information_schema.tables]'],
    'schema-qualified bypass' => ['SELECT * FROM other.orders', '[other.orders]'],
    'table-valued function' => ["SELECT * FROM pragma_table_info('orders')", 'table-valued functions'],
    'generate_series' => ['SELECT * FROM generate_series(1, 1000000000)', 'table-valued functions'],
    'backslash escape trick' => ["SELECT * FROM orders WHERE note = 'a\\' OR 1=1 --'", 'backslashes'],
    'unterminated string' => ["SELECT * FROM orders WHERE note = 'abc", 'unterminated'],
    'transaction control' => ['BEGIN; SELECT 1', 'single statement'],
    'locking read' => ['SELECT * FROM orders LOCK IN SHARE MODE', 'LOCK'],
    'unicode lookalike semicolon' => ["SELECT * FROM orders\u{037E} DROP TABLE orders", 'non-ASCII'],
]);

it('reads table names out of a query', function () {
    expect(sqlValidator()->tables('SELECT * FROM orders o JOIN customers c ON c.id = o.customer_id'))
        ->toBe(['orders', 'customers']);
});

it('rejects every table when no tables are allowlisted', function () {
    (new SqlValidator([]))->validate('SELECT * FROM orders');
})->throws(UnsafeQueryException::class, 'none are configured');

it('enforces a maximum query length', function () {
    (new SqlValidator(['orders'], 50))->validate('SELECT * FROM orders WHERE id IN ('.implode(',', range(1, 50)).')');
})->throws(UnsafeQueryException::class, 'longer than 50');
