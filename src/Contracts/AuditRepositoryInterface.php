<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

interface AuditRepositoryInterface
{
    /** Prepared insert. Returns false on failure; the caller MUST surface it. */
    public function insert(AuditRecord $record): bool;

    /**
     * Read the trail back.
     *
     * Until this release the trail was write-only: every manager action was
     * recorded and none of it could be looked at from inside wp-admin, which
     * made «ممیزی» a table rather than a feature. The filters match the indexes
     * the table already had — event type, actor, object, and a date range on
     * `created_at` — so a filtered page is an index seek and not a scan.
     *
     * @param array{event?:string, actor?:int, actors?:list<int>, object_type?:string, object_id?:string, from?:string, to?:string, search?:string} $filters
     * @return list<AuditRecord>
     */
    public function search(array $filters, int $limit, int $offset): array;

    /** How many rows the same filters match, for the pager. */
    public function count(array $filters): int;

    /** Distinct event types present, so the filter offers what exists. */
    public function eventTypes(int $limit = 100): array;

    public function lastError(): string;
}
