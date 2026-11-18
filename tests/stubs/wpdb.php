<?php

/**
 * A wpdb stand-in on mysqli for the database tests, and for the unit tests
 * with no connection at all (then every query fails like a dead database).
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps
class wpdb
{
    public string $prefix;
    public string $posts;
    public string $options;
    public string $last_error = '';
    public int $rows_affected = 0;
    public int $insert_id = 0;
    /** @var list<string> */
    public array $log = [];
    /** @var list<array<string, mixed>> */
    private array $lastResult = [];

    public function __construct(private ?mysqli $conn = null, string $prefix = 'wp_')
    {
        $this->prefix = $prefix;
        $this->posts = $prefix . 'posts';
        $this->options = $prefix . 'options';
    }

    public function prepare(string $query, mixed ...$args): ?string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $out = preg_replace_callback('/%%|%[sdf]/', function (array $m) use (&$i, $args): string {
            if ($m[0] === '%%') {
                return '%';
            }
            $v = $args[$i++] ?? null;
            return match ($m[0]) {
                '%d' => (string) (int) $v,
                '%f' => (string) (float) $v,
                default => "'" . $this->escape((string) $v) . "'",
            };
        }, $query);
        return $out;
    }

    private function escape(string $s): string
    {
        return $this->conn ? $this->conn->real_escape_string($s) : addslashes($s);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function query(string $query): int|bool
    {
        $this->log[] = $query;
        $this->last_error = '';
        $this->lastResult = [];
        if ($this->conn === null) {
            $this->last_error = 'No database connection';
            return false;
        }
        try {
            $result = $this->conn->query($query);
        } catch (mysqli_sql_exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
        if ($result === false) {
            $this->last_error = $this->conn->error;
            return false;
        }
        if ($result instanceof mysqli_result) {
            $this->lastResult = $result->fetch_all(MYSQLI_ASSOC);
            return count($this->lastResult);
        }
        $this->rows_affected = (int) $this->conn->affected_rows;
        $this->insert_id = (int) $this->conn->insert_id;
        return preg_match('/^\s*(insert|update|delete|replace)/i', $query) === 1 ? $this->rows_affected : true;
    }

    public function get_var(?string $query = null, int $x = 0, int $y = 0): ?string
    {
        if ($query !== null && $this->query($query) === false) {
            return null;
        }
        $row = $this->lastResult[$y] ?? null;
        if ($row === null) {
            return null;
        }
        $values = array_values($row);
        return isset($values[$x]) ? (string) $values[$x] : null;
    }

    /** @return list<string> */
    public function get_col(?string $query = null, int $x = 0): array
    {
        if ($query !== null && $this->query($query) === false) {
            return [];
        }
        return array_map(static fn (array $r): string => (string) array_values($r)[$x], $this->lastResult);
    }

    public function get_row(?string $query = null, string $output = OBJECT, int $y = 0): mixed
    {
        if ($query !== null && $this->query($query) === false) {
            return null;
        }
        $row = $this->lastResult[$y] ?? null;
        if ($row === null) {
            return null;
        }
        return $output === ARRAY_A ? $row : (object) $row;
    }

    public function get_results(?string $query = null, string $output = OBJECT): array
    {
        if ($query !== null && $this->query($query) === false) {
            return [];
        }
        return $output === ARRAY_A ? $this->lastResult : array_map(static fn (array $r): object => (object) $r, $this->lastResult);
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int|false
    {
        $cols = [];
        $vals = [];
        foreach ($data as $k => $v) {
            $cols[] = '`' . $k . '`';
            $vals[] = $v === null ? 'NULL' : (is_int($v) ? (string) $v : "'" . $this->escape((string) $v) . "'");
        }
        $r = $this->query("INSERT INTO `$table` (" . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
        return $r === false ? false : (int) $r;
    }
}
// phpcs:enable

function dbDelta(string|array $queries): array
{
    global $wpdb;
    foreach ((array) $queries as $q) {
        $wpdb->query((string) preg_replace('/^\s*CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $q));
    }
    return [];
}
