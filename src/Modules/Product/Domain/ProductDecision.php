<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One thing that was decided about a product, and when.
 *
 * `review_note` was a single column and every decision overwrote it. So a
 * manager who asked for a correction, changed their mind, and then rejected
 * left one sentence behind and no trail — and the vendor never saw any of it
 * on the page where they were supposed to act on it. Rows of this kind are
 * APPEND-ONLY: «رد» and «ارسال مجدد» add lines, they never remove one.
 */
final class ProductDecision
{
    /** The verbs, so a reader of the table does not have to guess. */
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'published';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const REJECTED = 'archived';
    public const SUSPENDED = 'suspended';
    public const RESTORED = 'draft';
    public const CORRECTED = 'corrected';
    public const FIELD_KEPT = 'field_kept';
    public const FIELD_ACCEPTED = 'field_accepted';
    /** Derived by migration 19 from the old single column; the date is not recorded, it is inferred. */
    public const IMPORTED = 'imported';

    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly int $vendorUserId,
        public readonly int $actorId,
        public readonly string $decision,
        public readonly string $note,
        public readonly string $createdAt
    ) {
    }

    /**
     * Is this a decision the VENDOR is waiting on an explanation for?
     *
     * The vendor's edit page shows the latest one of these above the form.
     * «تأیید شد» needs no explanation and «ارسال شد» is their own action, so
     * neither belongs at the top of a page whose job is «چه چیزی را اصلاح
     * کنم؟».
     */
    public function isForVendor(): bool
    {
        return in_array(
            $this->decision,
            [self::CHANGES_REQUESTED, self::REJECTED, self::SUSPENDED, self::CORRECTED, self::IMPORTED],
            true
        ) && trim($this->note) !== '';
    }
}
