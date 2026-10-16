<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * ONE PAYMENT TECTEB HAS ALREADY MADE TO A SUPPLIER.
 *
 * Past tense throughout, and that is the whole shape of it: there is no
 * «pending», no «approved» and no «in progress», because this object is not a
 * request anybody decides on. A person paid a supplier and is writing it down.
 * «فقط پرداختی را ثبت می‌کند که انجام شده؛ نباید انتقال واقعی وجه انجام دهد.»
 *
 * **It is NOT a withdrawal, and the difference is the reason it exists.** A
 * withdrawal is the vendor asking for money the marketplace owes them, and its
 * amount comes out of the ledger. This is Tecteb buying stock, and its amount
 * is whatever Tecteb agreed to pay. `$amount` therefore comes from the person
 * and `$share` — what the commission model computed for the same items — is
 * carried BESIDE it rather than instead of it, so a reader can see both and
 * nobody has to decide they are the same thing.
 */
final class SupplierPayment
{
    public function __construct(
        public readonly int $id,
        public readonly int $supplierUserId,
        /** What was actually paid. Stated by a person; never derived. */
        public readonly Money $amount,
        /**
         * What the recorded sales of these items came to for this supplier, at
         * the moment the payment was written down.
         *
         * Snapshotted rather than recomputed on read, for the same reason a
         * commission is: a later refund, a later rate change or a later edit
         * would otherwise rewrite the comparison that a person already made.
         */
        public readonly Money $share,
        /** The date on the bank receipt, not the date the row was typed. */
        public readonly string $paidOn,
        public readonly string $reference,
        public readonly string $note,
        /**
         * Where the receipt image sits, if one was attached — a PRIVATE path,
         * outside every web root, served only through the plugin's own
         * authorised route (ADR-007). Never a URL, because a URL is a thing
         * somebody can paste.
         */
        public readonly string $receiptPath,
        public readonly int $recordedBy,
        /** The key that makes a refreshed page unable to write a second payment. */
        public readonly string $idempotencyKey,
        /** The ledger event this payment's money line was written under; `''` when none was. */
        public readonly string $ledgerEvent,
        public readonly string $posting,
        public readonly string $createdAt,
        /** @var list<int> the order items this payment says it covers */
        public readonly array $orderItemIds = [],
        /**
         * @var list<int> the items whose vendor share was NOT on record when
         *      this payment was written down.
         *
         * Snapshotted rather than re-derived, for the same reason `$share` is:
         * a later repair can fill such a share in, and a record that then
         * stopped saying «this was indeterminate when the money was handed
         * over» would be rewriting what a person actually decided.
         *
         * An unknown share is not a share of nought — `alpha.60` added them as
         * zeros and then declared the payment matched (measured: a payment
         * equal to the first item's share alone was posted for two items).
         */
        public readonly array $unknownShareItemIds = []
    ) {
    }

    /** Whether every item's share was on record, so the amounts were comparable. */
    public function shareIsKnown(): bool
    {
        return $this->unknownShareItemIds === [];
    }

    /**
     * Whether the amount paid and the computed share are the same money.
     *
     * `false` when a share is unknown, because there is nothing to agree with
     * — asked first, so the arithmetic of the known items cannot stand in for
     * a total that was never computed.
     */
    public function agreesWithShare(): bool
    {
        return $this->shareIsKnown()
            && $this->amount->currency === $this->share->currency
            && $this->amount->exponent === $this->share->exponent
            && $this->amount->minor === $this->share->minor;
    }

    public function hasReceipt(): bool
    {
        return $this->receiptPath !== '';
    }
}
