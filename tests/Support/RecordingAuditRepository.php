<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\AuditRecord;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;

final class RecordingAuditRepository implements AuditRepositoryInterface
{
    /** @var list<AuditRecord> */
    public array $records = [];
    public bool $fail = false;

    public function insert(AuditRecord $record): bool
    {
        if ($this->fail) {
            return false;
        }
        $this->records[] = $record;
        return true;
    }

    /**
     * The same filters the real repository applies, in PHP, so a service test
     * that reads the trail back is testing the filter semantics rather than a
     * fake that always says yes.
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $matched = array_values(array_filter($this->records, fn (AuditRecord $r): bool => $this->matches($r, $filters)));
        $matched = array_reverse($matched);                 // newest first, as the real one orders
        return array_slice($matched, max(0, $offset), max(1, $limit));
    }

    public function count(array $filters): int
    {
        return count(array_filter($this->records, fn (AuditRecord $r): bool => $this->matches($r, $filters)));
    }

    public function countByActor(array $filters): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($record->actorId === null || !$this->matches($record, $filters)) {
                continue;
            }
            $out[$record->actorId] = ($out[$record->actorId] ?? 0) + 1;
        }
        return $out;
    }

    public function latestByActor(array $filters): array
    {
        // Insertion order is id order here, so the LAST match per actor is
        // the newest — the same row `MAX(id)` picks in SQL.
        $out = [];
        foreach ($this->records as $record) {
            if ($record->actorId === null || !$this->matches($record, $filters)) {
                continue;
            }
            $out[$record->actorId] = [
                'event_type' => $record->eventType,
                'created_at' => $record->createdAtUtc->format('Y-m-d H:i:s'),
            ];
        }
        return $out;
    }

    public function eventTypes(int $limit = 100): array
    {
        $types = array_values(array_unique(array_map(static fn (AuditRecord $r): string => $r->eventType, $this->records)));
        sort($types);
        return array_slice($types, 0, $limit);
    }

    /** @param array<string,mixed> $filters */
    private function matches(AuditRecord $record, array $filters): bool
    {
        if (($filters['event'] ?? '') !== '' && $record->eventType !== $filters['event']) {
            return false;
        }
        if ((int) ($filters['actor'] ?? 0) > 0 && $record->actorId !== (int) $filters['actor']) {
            return false;
        }
        // The `actors` list, with the SAME semantics as the SQL: an empty or
        // absent list is «no actor restriction», a non-empty one is
        // `actor_id IN (...)`. A fake that ignored this key would return the
        // whole site's trail and let a scoping test pass against a query that
        // does not scope.
        $actors = array_values(array_filter(
            array_map('intval', (array) ($filters['actors'] ?? [])),
            static fn (int $id): bool => $id > 0
        ));
        if ($actors !== [] && !in_array((int) $record->actorId, $actors, true)) {
            return false;
        }
        if (($filters['object_type'] ?? '') !== '' && $record->objectType !== $filters['object_type']) {
            return false;
        }
        if (($filters['object_id'] ?? '') !== '' && $record->objectId !== $filters['object_id']) {
            return false;
        }
        $day = $record->createdAtUtc->format('Y-m-d');
        if (($filters['from'] ?? '') !== '' && $day < (string) $filters['from']) {
            return false;
        }
        if (($filters['to'] ?? '') !== '' && $day > (string) $filters['to']) {
            return false;
        }
        if (($filters['search'] ?? '') !== '') {
            $haystack = (string) json_encode($record->payload, JSON_UNESCAPED_UNICODE);
            if (!str_contains($haystack, (string) $filters['search'])) {
                return false;
            }
        }
        return true;
    }

    public function lastError(): string
    {
        return $this->fail ? 'simulated insert failure (Duplicate entry for key ...)' : '';
    }
}
