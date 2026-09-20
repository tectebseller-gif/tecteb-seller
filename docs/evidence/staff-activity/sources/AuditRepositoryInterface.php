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

    /**
     * How many matching lines each actor wrote — counted in SQL, exactly.
     *
     * `search()` caps its page at 200 rows, so counting by fetching and
     * tallying in PHP is counting the page, not the trail. A caller that
     * asked for 2000 got 200 and no way to tell: the cap is applied silently
     * and the short array looks exactly like a quiet month.
     *
     * `GROUP BY` has no page to be capped to. It returns one row per actor
     * however many lines there are, so the number is the number.
     *
     * @param array{event?:string, actor?:int, actors?:list<int>, object_type?:string, object_id?:string, from?:string, to?:string, search?:string} $filters
     * @return array<int,int> actor id => how many lines they wrote
     */
    public function countByActor(array $filters): array;

    /**
     * The most recent matching line for each actor.
     *
     * The companion to `countByActor()`: together they answer «how much, and
     * what last» without reading a single row that is not needed. Keyed on
     * the greatest `id` rather than the greatest `created_at`, because
     * `DATETIME` has second precision and two lines in one second would make
     * «the latest» ambiguous — the same trap the product form's timestamp
     * conflict check fell into in `alpha.14`.
     *
     * @param array{event?:string, actor?:int, actors?:list<int>, object_type?:string, object_id?:string, from?:string, to?:string, search?:string} $filters
     * @return array<int, array{event_type:string, created_at:string}>
     */
    public function latestByActor(array $filters): array;

    /** Distinct event types present, so the filter offers what exists. */
    public function eventTypes(int $limit = 100): array;

    public function lastError(): string;
}
