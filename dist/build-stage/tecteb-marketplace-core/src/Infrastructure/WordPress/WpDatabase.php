<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\DatabaseInterface;

/** $wpdb adapter. All parameters go through $wpdb->prepare(). */
final class WpDatabase implements DatabaseInterface
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function prefix(): string
    {
        return (string) $this->wpdb->prefix;
    }

    public function charsetCollate(): string
    {
        return (string) $this->wpdb->get_charset_collate();
    }

    public function execute(string $sql, array $params = []): ?int
    {
        $result = $this->wpdb->query($this->prepare($sql, $params));
        if ($result === false) {
            return null;
        }
        return $result === true ? 0 : (int) $result;
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        return $this->wpdb->get_var($this->prepare($sql, $params));
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        $row = $this->wpdb->get_row($this->prepare($sql, $params), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function getResults(string $sql, array $params = []): array
    {
        $rows = $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? array_values($rows) : [];
    }

    public function lastError(): string
    {
        return (string) $this->wpdb->last_error;
    }

    private function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }
        return (string) $this->wpdb->prepare($sql, ...$params);
    }
}
