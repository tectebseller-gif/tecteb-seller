<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * One immutable line (§4.4: «مبلغ هر خط دفترکل تغییرناپذیر است و اصلاح فقط با
 * تراکنش جبرانی انجام می‌شود»).
 *
 * There is no setter and no update path anywhere in this module. A mistake is
 * corrected by writing an opposite line, which leaves both the mistake and
 * the correction visible — the only way a ledger can be audited at all.
 */
final class LedgerEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $eventKey,
        public readonly int $vendorUserId,
        public readonly LedgerAccount $account,
        public readonly Money $amount,
        public readonly string $orderRef,
        public readonly string $itemRef,
        public readonly string $reason,
        public readonly ?int $reversesEntryId,
        public readonly string $snapshotJson,
        public readonly string $createdAt
    ) {
    }

    public function isReversal(): bool
    {
        return $this->reversesEntryId !== null;
    }
}
