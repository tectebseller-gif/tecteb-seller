<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * One line of an order, as the marketplace records it.
 *
 * It carries the commission SNAPSHOT (FIN-01: «قانون در ایجاد قلم سفارش
 * snapshot می‌شود») rather than a reference to today's rate, so changing the
 * rate tomorrow cannot change what this sale owed. It carries no customer
 * contact detail at all — not because the view hides it, but because it was
 * never read into this object (PRIV-01).
 */
final class VendorOrderItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $orderItemId,
        public readonly int $productId,
        public readonly int $vendorUserId,
        public readonly string $title,
        public readonly string $sku,
        public readonly int $quantity,
        /**
         * A DISPLAY FIGURE, and `null` when there is no whole one.
         *
         * The line's total divided by its quantity. `null` means that
         * division is not exact — ۱۰۱ تومان over three units is ۳۳٫۶۶۶… and
         * no exponent makes it an integer — and «نمی‌دانم» is an answer
         * (`alpha.49`). The authoritative per-line amount is `baseMinor`
         * beside it; nothing in this plugin computes money from this column.
         */
        public readonly ?int $unitPriceMinor,
        public readonly int $baseMinor,
        public readonly int $taxMinor = 0,
        public readonly ?int $commissionMinor = null,
        public readonly ?int $vendorShareMinor = null,
        public readonly ?int $rateBasisPoints = null,
        public readonly string $rateSource = '',
        public readonly string $ledgerEvent = '',
        public readonly OrderItemStatus $status = OrderItemStatus::Placed,
        public readonly string $carrier = '',
        public readonly string $trackingCode = '',
        public readonly ?string $shippedAt = null,
        public readonly ?int $variationId = null,
        public readonly int $wcProductId = 0,
        public readonly string $createdAt = '',
        /**
         * THE UNIT the five money columns on this row are written in.
         *
         * `null` means «not recorded on this row», which is the truth about
         * every line captured before `alpha.67` and is resolved the way it
         * always was: from the vendor's single money unit
         * (`tmc_vendor_money_unit`, `alpha.40`). A value means the row says
         * its own unit, which it must from `alpha.67` on because a commission
         * is now recorded at FOUR more decimal places than the order's own
         * unit — the exponent `CommissionPrecision` derives so that
         * ۱۰۱ تومان at ۱۲٫۵٪ can be exactly ۱۲٫۶۲۵ instead of a rounded ۱۳.
         *
         * Two fields and not one: «the unit travels with the amount» is
         * `alpha.39`'s rule and the currency is half of a unit.
         *
         * Last, with defaults, because these are addressed positionally.
         */
        public readonly ?string $currency = null,
        public readonly ?int $exponent = null
    ) {
    }

    public function belongsTo(int $vendorUserId): bool
    {
        return $this->vendorUserId === $vendorUserId;
    }

    /** Whether the money for this line reached the ledger. */
    public function isRecorded(): bool
    {
        return $this->ledgerEvent !== '' && $this->vendorShareMinor !== null;
    }
}
