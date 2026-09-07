<?php
declare(strict_types=1);

/*
 * PDO-backed $wpdb stub. Real SQL against a DISPOSABLE MariaDB/MySQL named
 * tmc_test. Without TMC_TEST_DB_DSN every query throws, so a test that
 * silently depends on the database cannot pass by accident.
 */
class wpdb
{
    public string $prefix = 'wptest_';
    public string $options;
    public string $last_error = '';
    public int $num_queries = 0;
    /** @var list<string> */
    public array $queries = [];
    private ?\PDO $pdo = null;

    public function __construct()
    {
        $this->options = $this->prefix . 'options';
        $this->reconnect();
    }

    /**
     * Drops the PDO handle. Call this BEFORE pcntl_fork(): children inherit
     * the parent's socket and close it when they exit, which would leave the
     * parent with "MySQL server has gone away".
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function reconnect(): void
    {
        $dsn = getenv('TMC_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            $this->pdo = null;
            return;
        }
        $this->pdo = new \PDO($dsn, (string) getenv('TMC_TEST_DB_USER'), (string) getenv('TMC_TEST_DB_PASS'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function hasDatabase(): bool
    {
        return $this->pdo !== null;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('wpdb stub: no database configured (TMC_TEST_DB_DSN). This test belongs in the database suite.');
        }
        return $this->pdo;
    }

    public function ensureOptionsTable(): void
    {
        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->options}` (
            option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            option_name VARCHAR(191) NOT NULL DEFAULT '',
            option_value LONGTEXT NOT NULL,
            autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
            PRIMARY KEY (option_id),
            UNIQUE KEY option_name (option_name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci");
    }

    public function dropTable(string $table): void
    {
        $this->pdo()->exec("DROP TABLE IF EXISTS `{$table}`");
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    /** Mirrors wpdb::prepare(): %s quoted+escaped, %d int, %f float, %% literal. */
    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $query = str_replace(["'%s'", '"%s"'], '%s', $query);
        $i = 0;
        $out = preg_replace_callback('/%(%|s|d|f|F)/', function (array $m) use (&$i, $args): string {
            if ($m[1] === '%') {
                return '%';
            }
            $arg = $args[$i++] ?? null;
            return match ($m[1]) {
                'd' => (string) (int) $arg,
                'f', 'F' => (string) (float) $arg,
                default => "'" . $this->escape((string) $arg) . "'",
            };
        }, $query);
        return (string) $out;
    }

    private function escape(string $s): string
    {
        $quoted = $this->pdo()->quote($s);
        return substr($quoted, 1, -1);
    }

    public function query(string $sql): int|bool
    {
        $this->num_queries++;
        $this->queries[] = $sql;
        try {
            if (preg_match('/^\s*(select|show|describe|explain)\b/i', $sql) === 1) {
                $stmt = $this->pdo()->query($sql);
                return $stmt ? count($stmt->fetchAll(\PDO::FETCH_ASSOC)) : 0;
            }
            $affected = $this->pdo()->exec($sql);
            if (preg_match('/^\s*(create|alter|truncate|drop)\s/i', $sql) === 1) {
                return true;
            }
            return (int) $affected;
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_var(string $sql): mixed
    {
        $this->num_queries++;
        $this->queries[] = $sql;
        try {
            $stmt = $this->pdo()->query($sql);
            $value = $stmt ? $stmt->fetchColumn() : false;
            return $value === false ? null : $value;
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_row(string $sql, string $output = OBJECT): mixed
    {
        $this->num_queries++;
        $this->queries[] = $sql;
        try {
            $stmt = $this->pdo()->query($sql);
            $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            if ($row === false) {
                return null;
            }
            return $output === ARRAY_A ? $row : (object) $row;
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_results(string $sql, string $output = OBJECT): array
    {
        $this->num_queries++;
        $this->queries[] = $sql;
        try {
            $stmt = $this->pdo()->query($sql);
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            return $output === ARRAY_A ? $rows : array_map(static fn ($r) => (object) $r, $rows);
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data, array|string|null $format = null): int|false
    {
        $formats = is_array($format) ? array_values($format) : [];
        $cols = [];
        $vals = [];
        $i = 0;
        foreach ($data as $col => $value) {
            $cols[] = '`' . $col . '`';
            $f = $formats[$i++] ?? '%s';
            if ($value === null) {
                $vals[] = 'NULL';
            } elseif ($f === '%d') {
                $vals[] = (string) (int) $value;
            } elseif ($f === '%f') {
                $vals[] = (string) (float) $value;
            } else {
                $vals[] = "'" . $this->escape((string) $value) . "'";
            }
        }
        $sql = "INSERT INTO `{$table}` (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
        $result = $this->query($sql);
        return $result === false ? false : (int) $result;
    }
}

$GLOBALS['wpdb'] = new wpdb();
