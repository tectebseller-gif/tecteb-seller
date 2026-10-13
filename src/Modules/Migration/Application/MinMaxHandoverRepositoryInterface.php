<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * The handover ledger: one row per product the Min Max migration has decided
 * about, and what the destination held before it did.
 *
 * Append-and-amend, never append-only: a product can be migrated, rolled back
 * and migrated again, and the UNIQUE KEY on the post id is what makes a second
 * run of the same batch a no-op instead of a duplicate.
 */
interface MinMaxHandoverRepositoryInterface
{
    /** Migrated: the three rows were written and this plugin owns them. */
    public const MIGRATED = 'migrated';

    /** Refused: the rule has no equivalent here. Nothing was written. */
    public const REFUSED = 'refused';

    /** A rule the manager already owns at the destination. Nothing written. */
    public const HELD = 'held_by_manager';

    /**
     * Written, but onto a product the marketplace does not own — so nothing
     * enforces it. Recorded as its own state rather than as a success.
     */
    public const UNENFORCED = 'written_unenforced';

    /** Rolled back: the destination was put back to what it held before. */
    public const ROLLED_BACK = 'rolled_back';

    /**
     * Record a decision, or leave an existing one alone.
     *
     * @param array<string,string|null> $row
     * @return string one of the class constants, or `''` when a row already
     *                existed and was left untouched
     */
    public function record(array $row): string;

    /** @return array<string,mixed>|null */
    public function find(int $postId): ?array;

    /** @return array<string,int> status => count */
    public function tally(): array;

    /**
     * @param list<string> $statuses
     * @return list<array<string,mixed>>
     */
    public function withStatus(array $statuses, int $limit = 200, int $afterId = 0): array;

    /** Mark one row rolled back, and only from `migrated`/`written_unenforced`. */
    public function markRolledBack(int $postId): bool;
}
