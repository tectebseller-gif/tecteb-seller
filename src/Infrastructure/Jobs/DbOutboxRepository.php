<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\Jobs;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\OutboxRepositoryInterface;
use Tecteb\Marketplace\Core\Migration\Migrations\M0013JobsAndOutbox as T;

/**
 * The outbox on the plugin's own schema.
 *
 * `INSERT IGNORE` on the unique `event_id`, for the same reason ratings use it:
 * «record this once» decided by an index is a rule two concurrent requests
 * cannot both pass, and a SELECT-then-INSERT is exactly how they both do.
 *
 * `markDelivery()` is the only write after the insert, and it touches three
 * columns — state, reason and the attempt counter. The payload and the
 * signature are never in an UPDATE anywhere in this class, because a signature
 * over bytes that have since changed is worse than no signature at all.
 */
final class DbOutboxRepository implements OutboxRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(
        string $eventType,
        string $eventId,
        ?int $vendorUserId,
        string $objectType,
        string $objectId,
        array $payload,
        string $signature
    ): int {
        $now = $this->now();
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->t() . '`
             (event_type, event_id, vendor_user_id, object_type, object_id, payload,
              signature, signed_at, delivery_state, delivery_reason, attempts, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 0, %s)',
            [
                $eventType,
                $eventId,
                $vendorUserId === null ? null : (string) $vendorUserId,
                $objectType,
                $objectId,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $signature,
                $now,
                'recorded',
                '',
                $now,
            ]
        );
        if ($written === null || $written === 0) {
            return 0;
        }
        return (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function pending(int $limit, int $afterId = 0): array
    {
        return $this->db->getResults(
            'SELECT * FROM `' . $this->t() . '`
             WHERE delivery_state = %s AND id > %d ORDER BY id ASC LIMIT %d',
            ['recorded', max(0, $afterId), max(1, min(200, $limit))]
        );
    }

    public function recent(array $states, int $limit, int $offset = 0): array
    {
        $sql = 'SELECT * FROM `' . $this->t() . '`';
        $params = [];
        if ($states !== []) {
            $sql .= ' WHERE delivery_state IN (' . implode(', ', array_fill(0, count($states), '%s')) . ')';
            $params = $states;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = max(1, min(200, $limit));
        $params[] = max(0, $offset);
        return $this->db->getResults($sql, $params);
    }

    public function markDelivery(int $id, string $state, string $reason): bool
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->t() . '`
             SET delivery_state = %s, delivery_reason = %s, attempts = attempts + 1, last_attempt_at = %s
             WHERE id = %d',
            [$state, mb_substr($reason, 0, 180), $this->now(), $id]
        );
        return $written !== null && $written > 0;
    }

    public function census(): array
    {
        $out = ['recorded' => 0, 'blocked' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->db->getResults('SELECT delivery_state, COUNT(*) AS n FROM `' . $this->t() . '` GROUP BY delivery_state') as $row) {
            $out[(string) $row['delivery_state']] = (int) $row['n'];
        }
        return $out;
    }

    public function count(array $states = []): int
    {
        if ($states === []) {
            return (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->t() . '`');
        }
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->t() . '`
             WHERE delivery_state IN (' . implode(', ', array_fill(0, count($states), '%s')) . ')',
            $states
        );
    }

    public function lastError(): string
    {
        return $this->db->lastError();
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function t(): string
    {
        return T::table($this->db, T::OUTBOX);
    }
}
