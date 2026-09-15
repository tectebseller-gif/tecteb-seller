<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * A discount code, and who it belongs to.
 *
 * `vendorUserId` is the whole design. The approved rule is «فروشنده برای
 * محصولات خودش کد تخفیف می‌سازد؛ تخفیف سراسری فقط با مدیر است», and a coupon
 * with a vendor is one that may only ever reduce that vendor's own lines. A
 * coupon with no vendor is a marketplace-wide one — and who funds it is
 * DEC-04, so this object can describe one while the service refuses to create
 * or apply it.
 */
final class Coupon
{
    public const PERCENT = 'percent';
    public const FIXED = 'fixed';

    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly int $vendorUserId,
        public readonly string $kind = self::PERCENT,
        public readonly int $value = 0,
        public readonly int $minSubtotalMinor = 0,
        public readonly ?int $maxDiscountMinor = null,
        public readonly int $usageLimit = 0,
        public readonly int $perCustomerLimit = 0,
        public readonly ?string $startsAt = null,
        public readonly ?string $endsAt = null,
        public readonly string $status = self::ACTIVE,
        public readonly int $createdBy = 0,
        public readonly string $createdAt = ''
    ) {
    }

    public function isGlobal(): bool
    {
        return $this->vendorUserId === 0;
    }

    public function belongsTo(int $vendorUserId): bool
    {
        return $this->vendorUserId === $vendorUserId;
    }

    /**
     * What this coupon takes off a subtotal, in minor units.
     *
     * Never more than the subtotal: a discount that exceeds what is being
     * bought would turn a sale into a payment to the customer, and nothing in
     * the approved rules says the marketplace does that.
     */
    public function discountFor(int $subtotalMinor): int
    {
        if ($subtotalMinor <= 0 || $subtotalMinor < $this->minSubtotalMinor) {
            return 0;
        }
        $discount = $this->kind === self::PERCENT
            ? intdiv($subtotalMinor * max(0, $this->value), 10000)   // basis points
            : max(0, $this->value);
        if ($this->maxDiscountMinor !== null) {
            $discount = min($discount, $this->maxDiscountMinor);
        }
        return min($discount, $subtotalMinor);
    }

    public function isWithinWindow(string $now): bool
    {
        if ($this->startsAt !== null && $now < $this->startsAt) {
            return false;
        }
        return $this->endsAt === null || $now <= $this->endsAt;
    }
}
