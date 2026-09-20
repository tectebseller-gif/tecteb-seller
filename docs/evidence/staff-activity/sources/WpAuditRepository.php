<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\AuditRecord;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;

/** Prepared insert via $wpdb->insert() with explicit formats; NULL actor allowed. */
final class WpAuditRepository implements AuditRepositoryInterface
{
    private string $error = '';

    public function __construct(private \wpdb $wpdb)
    {
    }

    public function insert(AuditRecord $record): bool
    {
        $table = $this->table();
        $payload = json_encode($record->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $this->error = 'payload_encoding_failed';
            return false;
        }
        $result = $this->wpdb->insert(
            $table,
            [
                'event_type' => $record->eventType,
                'actor_id' => $record->actorId,
                'object_type' => $record->objectType,
                'object_id' => $record->objectId,
                'payload' => $payload,
                'correlation_id' => $record->correlationId,
                'created_at' => $record->createdAtUtc->format('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        if ($result === false || $result === 0) {
            $this->error = (string) $this->wpdb->last_error ?: 'insert_failed';
            return false;
        }
        $this->error = '';
        return true;
    }

    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM `' . $this->table() . '`' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, array_merge($params, [$limit, $offset])),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_map(fn (array $row): AuditRecord => $this->hydrate($row), $rows);
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);
        $sql = 'SELECT COUNT(*) FROM `' . $this->table() . '`' . $where;
        return (int) $this->wpdb->get_var($params === [] ? $sql : $this->wpdb->prepare($sql, $params));
    }

    public function countByActor(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        // No LIMIT, on purpose. One row per actor however busy the shop is:
        // a cap here would put the report back where it started, reporting a
        // page as if it were a total.
        $sql = 'SELECT actor_id, COUNT(*) AS lines_written FROM `' . $this->table() . '`'
            . $where . ' GROUP BY actor_id';
        $rows = $this->wpdb->get_results(
            $params === [] ? $sql : $this->wpdb->prepare($sql, $params),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            // A system-written line has no actor. `null` is not somebody's
            // activity, and casting it to 0 would invent a person.
            if ($row['actor_id'] === null) {
                continue;
            }
            $out[(int) $row['actor_id']] = (int) $row['lines_written'];
        }
        return $out;
    }

    public function latestByActor(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        $table = $this->table();
        // Join each actor's greatest id back to its own row. `MAX(id)` rather
        // than `MAX(created_at)`: the column is a DATETIME with second
        // precision, so two lines in one second would tie and the join would
        // return both.
        $sql = 'SELECT a.actor_id, a.event_type, a.created_at
                  FROM `' . $table . '` a
                  JOIN (SELECT actor_id, MAX(id) AS newest FROM `' . $table . '`'
            . $where . ' GROUP BY actor_id) m ON m.newest = a.id';
        $rows = $this->wpdb->get_results(
            $params === [] ? $sql : $this->wpdb->prepare($sql, $params),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if ($row['actor_id'] === null) {
                continue;
            }
            $out[(int) $row['actor_id']] = [
                'event_type' => (string) $row['event_type'],
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    public function eventTypes(int $limit = 100): array
    {
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT DISTINCT event_type FROM `' . $this->table() . '` ORDER BY event_type ASC LIMIT %d',
                max(1, min(500, $limit))
            )
        );
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    public function lastError(): string
    {
        return $this->error;
    }

    // ------------------------------------------------------------- internals

    /**
     * Build the WHERE from the filters that were actually given.
     *
     * Every value is a placeholder, none is interpolated, and an absent filter
     * adds no clause at all — so the unfiltered page is a plain ordered read of
     * the primary key rather than a `WHERE 1=1` the optimiser has to think
     * about. The date bounds are inclusive at both ends because a manager
     * looking for «what happened on Tuesday» means all of Tuesday.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function where(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (($filters['event'] ?? '') !== '') {
            $clauses[] = 'event_type = %s';
            $params[] = (string) $filters['event'];
        }
        if ((int) ($filters['actor'] ?? 0) > 0) {
            $clauses[] = 'actor_id = %d';
            $params[] = (int) $filters['actor'];
        }
        // Several actors at once, so «what has this shop's staff been doing»
        // is one indexed seek rather than one query per person. Ids are cast
        // to int and inlined because the number of placeholders varies and
        // wpdb::prepare() takes a fixed list; a cast int cannot carry SQL.
        $actors = array_values(array_filter(
            array_map('intval', (array) ($filters['actors'] ?? [])),
            static fn (int $id): bool => $id > 0
        ));
        if ($actors !== []) {
            $clauses[] = 'actor_id IN (' . implode(',', $actors) . ')';
        }
        if (($filters['object_type'] ?? '') !== '') {
            $clauses[] = 'object_type = %s';
            $params[] = (string) $filters['object_type'];
        }
        if (($filters['object_id'] ?? '') !== '') {
            $clauses[] = 'object_id = %s';
            $params[] = (string) $filters['object_id'];
        }
        if (($filters['from'] ?? '') !== '') {
            $clauses[] = 'created_at >= %s';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '') {
            $clauses[] = 'created_at <= %s';
            $params[] = (string) $filters['to'] . ' 23:59:59';
        }
        if (($filters['search'] ?? '') !== '') {
            // Against the payload only. It is a LIKE and therefore a scan, which
            // is why it is offered last and alongside the indexed filters rather
            // than instead of them: «this order id, in June» stays an index seek
            // with a small scan on top.
            $clauses[] = 'payload LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like((string) $filters['search']) . '%';
        }
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AuditRecord
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        return new AuditRecord(
            (string) $row['event_type'],
            $row['actor_id'] === null ? null : (int) $row['actor_id'],
            $row['object_type'] === null ? null : (string) $row['object_type'],
            $row['object_id'] === null ? null : (string) $row['object_id'],
            is_array($payload) ? $payload : [],
            $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'))
        );
    }

    private function table(): string
    {
        return $this->wpdb->prefix . M0001CreateAuditTable::TABLE_SUFFIX;
    }
}
