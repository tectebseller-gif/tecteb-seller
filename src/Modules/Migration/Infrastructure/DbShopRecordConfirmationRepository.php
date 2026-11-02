<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordConfirmationRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\Migrations\M0037ShopRecordConfirmation as Table;

/**
 * `tmc_dokan_record_confirmation`, and nothing else.
 *
 * ⛔ **This class touches no other table of ours and none of Dokan's.** Not
 * `tmc_dokan_handover` (the financial decision), not `tmc_vendor_profiles`
 * (`can_sell`), not `tmc_products` (ownership) — the three the owner's
 * instruction put out of bounds. `ShopRecordConfirmationIsNeverAutomaticTest`
 * asserts that from this file's bytes with the comments stripped first
 * (`alpha.22`), because a docblock that promises it is otherwise the thing
 * that breaks the test.
 */
final class DbShopRecordConfirmationRepository implements ShopRecordConfirmationRepositoryInterface
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

    public function liveFor(int $vendorUserId): ?array
    {
        if ($vendorUserId <= 0 || $this->supported() !== true) {
            return null;
        }
        $row = $this->db->getRow(
            'SELECT records_version, reconciliation, confirmed_by, confirmed_at
               FROM `' . Table::table($this->db) . '`
              WHERE live_vendor_user_id = %d',
            [$vendorUserId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'records_version' => (int) $row['records_version'],
            'reconciliation' => (string) $row['reconciliation'],
            'confirmed_by' => (int) $row['confirmed_by'],
            'confirmed_at' => (string) $row['confirmed_at'],
        ];
    }

    public function confirm(int $vendorUserId, int $recordsVersion, string $reconciliation, int $actorId): string
    {
        if ($vendorUserId <= 0) {
            return 'storage_failed';
        }
        $supported = $this->supported();
        if ($supported === null) {
            return 'confirmation_table_unreadable';
        }
        if ($supported === false) {
            return 'confirmation_unsupported';
        }
        // THE INDEX DECIDES, not a check before the write. `UNIQUE
        // (live_vendor_user_id)` is what makes «one live confirmation per
        // shop» true for two simultaneous requests; a `SELECT` first is a
        // check two of them both pass (`alpha.54`).
        $written = $this->db->execute(
            'INSERT INTO `' . Table::table($this->db) . '`
                (vendor_user_id, live_vendor_user_id, records_version, reconciliation,
                 confirmed_by, confirmed_at)
             VALUES (%d, %d, %d, %s, %d, %s)',
            [
                $vendorUserId,
                $vendorUserId,
                max(0, $recordsVersion),
                mb_substr(trim($reconciliation), 0, 190),
                max(0, $actorId),
                $this->now(),
            ]
        );
        if ($written !== null && $written > 0) {
            return '';
        }
        // ⛔ The write failed, and «the key was taken» and «the statement
        // failed» are answers the driver gives identically. So the row is READ
        // rather than inferred (`alpha.28`): reporting `already_confirmed` for
        // a storage failure would tell a manager a decision exists that does
        // not.
        if ($this->supported() !== true) {
            return 'confirmation_table_unreadable';
        }
        return $this->liveFor($vendorUserId) === null ? 'storage_failed' : 'already_confirmed';
    }

    public function revoke(int $vendorUserId, int $actorId): string
    {
        if ($vendorUserId <= 0) {
            return 'not_confirmed';
        }
        $supported = $this->supported();
        if ($supported === null) {
            return 'confirmation_table_unreadable';
        }
        if ($supported === false) {
            return 'confirmation_unsupported';
        }
        // ⛔ Zero rows here is unambiguous, and that is a property of the
        // statement rather than a hope: `live_vendor_user_id` is set to NULL
        // while the `WHERE` requires it to equal the id, so any row that
        // matched necessarily CHANGED. There is no «matched but nothing to
        // update» half for this one (`alpha.8`).
        $moved = $this->db->execute(
            'UPDATE `' . Table::table($this->db) . '`
                SET live_vendor_user_id = NULL, revoked_by = %d, revoked_at = %s
              WHERE live_vendor_user_id = %d',
            [max(0, $actorId), $this->now(), $vendorUserId]
        );
        if ($moved === null) {
            return 'storage_failed';
        }
        return $moved > 0 ? '' : 'not_confirmed';
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
