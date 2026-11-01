<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\DefaultCategoryAcceptanceRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0036DefaultCategoryAcceptance as Table;

/**
 * `tmc_product_default_category`, and nothing else.
 *
 * ⛔ **This class touches no WooCommerce table and no other table of ours.**
 * Not `wp_term_relationships`, not `wp_term_taxonomy`, not `tmc_products`,
 * not `tmc_product_categories` — the two columns the owner's ruling put out
 * of bounds. `DefaultCategoryIsNeverAutomaticTest` asserts that from this
 * file's bytes with the comments stripped first (`alpha.22`), because a
 * docblock that promises it is the thing that breaks the test otherwise.
 */
final class DbDefaultCategoryAcceptanceRepository implements DefaultCategoryAcceptanceRepositoryInterface
{
    private ?bool $present = null;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function supported(): ?bool
    {
        if ($this->present === null) {
            $answer = Table::carriedBy($this->db);
            if ($answer === null) {
                // NOT memoised. A failed read is a reason to ask again, not an
                // answer to remember (`alpha.49`).
                return null;
            }
            $this->present = $answer;
        }
        return $this->present;
    }

    public function liveFor(array $productIds): ?array
    {
        $ids = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        if ($this->supported() !== true) {
            // Absent or unreadable. `null` for both, because the caller must
            // tell «nobody accepted anything» from «I could not ask», and on
            // schema 35 the honest answer to «what was accepted» is also «I
            // cannot ask» — the feature is not there.
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT live_product_id AS pid, term_id, decided_by, decided_at
               FROM `' . Table::table($this->db) . '`
              WHERE live_product_id IN (' . $placeholders . ')',
            $ids
        );
        if ($rows === null) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['pid']] = [
                'term_id' => (int) $row['term_id'],
                'decided_by' => (int) $row['decided_by'],
                'decided_at' => (string) $row['decided_at'],
            ];
        }
        return $out;
    }

    public function accept(int $productId, int $termId, int $actorId): string
    {
        if ($productId <= 0 || $termId <= 0) {
            return 'storage_failed';
        }
        $supported = $this->supported();
        if ($supported === null) {
            return 'acceptance_table_unreadable';
        }
        if ($supported === false) {
            return 'acceptance_unsupported';
        }
        // THE INDEX DECIDES, not a check before the write. `UNIQUE
        // (live_product_id)` is what makes «one live acceptance per product»
        // true for two simultaneous requests; a `SELECT` first is a check two
        // of them both pass (`alpha.54`).
        $written = $this->db->execute(
            'INSERT INTO `' . Table::table($this->db) . '`
                (product_id, live_product_id, term_id, decided_by, decided_at)
             VALUES (%d, %d, %d, %d, %s)',
            [$productId, $productId, $termId, max(0, $actorId), $this->now()]
        );
        if ($written !== null && $written > 0) {
            return '';
        }
        // ⛔ The write failed, and «the key was taken» and «the statement
        // failed» are different answers the driver gives identically. So the
        // row is READ rather than inferred (`alpha.28`): reporting
        // `already_accepted` for a storage failure would tell a manager a
        // decision exists that does not.
        $live = $this->liveFor([$productId]);
        if ($live === null) {
            return 'acceptance_table_unreadable';
        }
        return array_key_exists($productId, $live) ? 'already_accepted' : 'storage_failed';
    }

    public function revoke(int $productId, int $actorId): string
    {
        if ($productId <= 0) {
            return 'not_accepted';
        }
        $supported = $this->supported();
        if ($supported === null) {
            return 'acceptance_table_unreadable';
        }
        if ($supported === false) {
            return 'acceptance_unsupported';
        }
        // ⛔ Zero rows here is unambiguous, and that is a property of the
        // statement rather than a hope: `live_product_id` is set to NULL
        // while the `WHERE` requires it to equal the id, so any row that
        // matched necessarily CHANGED. There is no «matched but nothing to
        // update» half for this one (`alpha.8`).
        $moved = $this->db->execute(
            'UPDATE `' . Table::table($this->db) . '`
                SET live_product_id = NULL, revoked_by = %d, revoked_at = %s
              WHERE live_product_id = %d',
            [max(0, $actorId), $this->now(), $productId]
        );
        if ($moved === null) {
            return 'storage_failed';
        }
        return $moved > 0 ? '' : 'not_accepted';
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
