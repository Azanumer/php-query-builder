<?php
/**
 * QueryBuilder
 *
 * A fluent, dependency-free query builder on top of PDO.
 *
 * - PHP 7.4+ compatible
 * - Every value is bound as a named parameter (:w0, :w1, ...) — no SQL injection
 * - Identifiers are quoted with backticks (backticks inside names are escaped)
 * - update()/delete() refuse to run without a WHERE clause (accident guard)
 *
 * The builder state resets after every execution (get/first/count/insert/...),
 * so one instance can be reused: $qb->table('users')->...->get();
 */

class QueryBuilder
{
    /** @var PDO */
    private $pdo;

    /** @var string|null */
    private $table;

    /** @var array */
    private $columns = array('*');

    /** @var array Each entry: array($column, $operator, $paramNameOrList|null) */
    private $wheres = array();

    /** @var array Each entry: array($type, $table, $first, $operator, $second) */
    private $joins = array();

    /** @var array Each entry: array($column, $direction) */
    private $orderBys = array();

    /** @var int|null */
    private $limit;

    /** @var int|null */
    private $offset;

    /** @var array Named parameter values (:w0 => value) */
    private $params = array();

    /** @var int */
    private $paramCount = 0;

    /** @var string SQL of the last built query */
    private $lastSql = '';

    /** Operators allowed in where() — anything else throws. */
    private static $allowedOperators = array(
        '=', '<>', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE',
    );

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Set the working table. Starts a fresh query (resets previous state).
     */
    public function table($name)
    {
        $this->reset();
        $this->table = $name;
        return $this;
    }

    /**
     * Columns to select. Omit for "*".
     */
    public function select(...$cols)
    {
        if (!empty($cols)) {
            $this->columns = $cols;
        }
        return $this;
    }

    /**
     * Add a WHERE condition. where('age', 18) means age = 18;
     * where('age', '>=', 18) uses the given operator.
     */
    public function where($col, $opOrValue, $value = null)
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value    = $opOrValue;
        } else {
            $operator = strtoupper($opOrValue);
        }
        if (!in_array($operator, self::$allowedOperators, true)) {
            throw new InvalidArgumentException('Unsupported where() operator: ' . $opOrValue);
        }
        $name = ':w' . $this->paramCount++;
        $this->wheres[] = array($col, $operator, $name);
        $this->params[$name] = $value;
        return $this;
    }

    /**
     * WHERE col IN (...). Empty list throws — "IN ()" is invalid SQL.
     */
    public function whereIn($col, array $values)
    {
        if (empty($values)) {
            throw new InvalidArgumentException('whereIn() requires at least one value.');
        }
        $names = array();
        foreach ($values as $value) {
            $name = ':w' . $this->paramCount++;
            $names[] = $name;
            $this->params[$name] = $value;
        }
        $this->wheres[] = array($col, 'IN', $names);
        return $this;
    }

    /**
     * WHERE col IS NULL.
     */
    public function whereNull($col)
    {
        $this->wheres[] = array($col, 'IS NULL', null);
        return $this;
    }

    /**
     * INNER JOIN table ON first = second.
     */
    public function join($table, $first, $op, $second)
    {
        $this->joins[] = array('INNER', $table, $first, $op, $second);
        return $this;
    }

    /**
     * LEFT JOIN table ON first = second.
     */
    public function leftJoin($table, $first, $op, $second)
    {
        $this->joins[] = array('LEFT', $table, $first, $op, $second);
        return $this;
    }

    /**
     * ORDER BY column ASC|DESC. Anything but DESC falls back to ASC.
     */
    public function orderBy($col, $dir = 'ASC')
    {
        $dir = (strtoupper($dir) === 'DESC') ? 'DESC' : 'ASC';
        $this->orderBys[] = array($col, $dir);
        return $this;
    }

    public function limit($n)
    {
        $this->limit = max(0, (int) $n);
        return $this;
    }

    public function offset($n)
    {
        $this->offset = max(0, (int) $n);
        return $this;
    }

    /**
     * Run the SELECT and return all rows as associative arrays.
     */
    public function get()
    {
        $stmt = $this->run($this->buildSelect());
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * First matching row, or null.
     */
    public function first()
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows ? $rows[0] : null;
    }

    /**
     * COUNT(*) of the current query (keeps joins and wheres).
     */
    public function count()
    {
        $this->requireTable();
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->quote($this->table)
             . $this->buildJoins()
             . $this->buildWhere();
        $stmt = $this->run($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $row['total'];
    }

    /**
     * True when at least one row matches.
     */
    public function exists()
    {
        return $this->count() > 0;
    }

    /**
     * INSERT a row. Returns the last insert id.
     */
    public function insert(array $data)
    {
        $this->requireTable();
        if (empty($data)) {
            throw new InvalidArgumentException('insert() requires at least one column.');
        }
        $cols = array();
        $placeholders = array();
        foreach ($data as $col => $value) {
            $name = ':w' . $this->paramCount++;
            $cols[] = $this->quote($col);
            $placeholders[] = $name;
            $this->params[$name] = $value;
        }
        $sql = 'INSERT INTO ' . $this->quote($this->table)
             . ' (' . implode(', ', $cols) . ')'
             . ' VALUES (' . implode(', ', $placeholders) . ')';
        $this->run($sql);
        return $this->pdo->lastInsertId();
    }

    /**
     * UPDATE matching rows. Requires a WHERE — throws without one.
     * Returns the number of affected rows.
     */
    public function update(array $data)
    {
        $this->requireTable();
        $this->requireWhere('update');
        if (empty($data)) {
            throw new InvalidArgumentException('update() requires at least one column.');
        }
        $sets = array();
        foreach ($data as $col => $value) {
            $name = ':w' . $this->paramCount++;
            $sets[] = $this->quote($col) . ' = ' . $name;
            $this->params[$name] = $value;
        }
        $sql = 'UPDATE ' . $this->quote($this->table)
             . ' SET ' . implode(', ', $sets)
             . $this->buildWhere();
        $stmt = $this->run($sql);
        return $stmt->rowCount();
    }

    /**
     * DELETE matching rows. Requires a WHERE — throws without one.
     * Returns the number of affected rows.
     */
    public function delete()
    {
        $this->requireTable();
        $this->requireWhere('delete');
        $sql = 'DELETE FROM ' . $this->quote($this->table) . $this->buildWhere();
        $stmt = $this->run($sql);
        return $stmt->rowCount();
    }

    /**
     * The SQL of the current/last query (with :wN placeholders, not values).
     * Builds from current state when a table is set.
     */
    public function getSql()
    {
        if ($this->table !== null) {
            $this->lastSql = $this->buildSelect();
        }
        return $this->lastSql;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function requireTable()
    {
        if ($this->table === null) {
            throw new RuntimeException('No table set. Call table() first.');
        }
    }

    private function requireWhere($action)
    {
        if (empty($this->wheres)) {
            throw new RuntimeException(
                $action . '() without a where() would hit every row — refused.'
            );
        }
    }

    /**
     * Reset the builder for the next query. Keeps $lastSql.
     */
    private function reset()
    {
        $this->table      = null;
        $this->columns    = array('*');
        $this->wheres     = array();
        $this->joins      = array();
        $this->orderBys   = array();
        $this->limit      = null;
        $this->offset     = null;
        $this->params     = array();
        $this->paramCount = 0;
    }

    /**
     * Quote an identifier with backticks. Handles table.column and
     * "column AS alias". Embedded backticks are escaped by doubling.
     */
    private function quote($identifier)
    {
        if ($identifier === '*') {
            return '*';
        }
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', trim($identifier), $m)) {
            return $this->quoteDotted($m[1]) . ' AS ' . $this->quoteDotted($m[2]);
        }
        return $this->quoteDotted($identifier);
    }

    private function quoteDotted($identifier)
    {
        $parts = explode('.', trim($identifier));
        foreach ($parts as $i => $part) {
            $parts[$i] = ($part === '*')
                ? '*'
                : '`' . str_replace('`', '``', $part) . '`';
        }
        return implode('.', $parts);
    }

    private function buildJoins()
    {
        $sql = '';
        foreach ($this->joins as $join) {
            list($type, $table, $first, $op, $second) = $join;
            $sql .= ' ' . $type . ' JOIN ' . $this->quote($table)
                  . ' ON ' . $this->quote($first) . ' ' . $op . ' ' . $this->quote($second);
        }
        return $sql;
    }

    private function buildWhere()
    {
        if (empty($this->wheres)) {
            return '';
        }
        $parts = array();
        foreach ($this->wheres as $where) {
            list($col, $op, $param) = $where;
            if ($op === 'IS NULL') {
                $parts[] = $this->quote($col) . ' IS NULL';
            } elseif ($op === 'IN') {
                $parts[] = $this->quote($col) . ' IN (' . implode(', ', $param) . ')';
            } else {
                $parts[] = $this->quote($col) . ' ' . $op . ' ' . $param;
            }
        }
        return ' WHERE ' . implode(' AND ', $parts);
    }

    private function buildSelect()
    {
        $this->requireTable();
        $cols = array();
        foreach ($this->columns as $col) {
            $cols[] = $this->quote($col);
        }
        $sql = 'SELECT ' . implode(', ', $cols)
             . ' FROM ' . $this->quote($this->table)
             . $this->buildJoins()
             . $this->buildWhere();
        if (!empty($this->orderBys)) {
            $orders = array();
            foreach ($this->orderBys as $order) {
                list($col, $dir) = $order;
                $orders[] = $this->quote($col) . ' ' . $dir;
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return $sql;
    }

    /**
     * Prepare, execute, record SQL, reset builder, return the statement.
     */
    private function run($sql)
    {
        $this->lastSql = $sql;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        $this->reset();
        return $stmt;
    }
}
