<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Coupon;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * A vendor's own discount codes — and the marketplace-wide one this plugin
 * refuses to create.
 *
 * The approved rule is «فروشنده برای محصولات خودش کد تخفیف می‌سازد؛ تخفیف
 * سراسری فقط با مدیر است», and it is enforced twice over:
 *
 *  - A vendor's coupon carries their user id and `discountFor()` is only ever
 *    handed THEIR subtotal, so it cannot reduce another shop's line even if
 *    the code is typed on a mixed basket.
 *  - A marketplace-wide coupon needs somebody to fund it, and who that is
 *    lives in DEC-04. So creating one is refused with `global_coupon_undecided`
 *    rather than created and quietly charged to the vendors. The manager's
 *    screen shows the refusal and names the decision.
 *
 * The permission asked for is the store's PRODUCT one. Master Spec §3.1 fixes
 * exactly five areas — product, inventory, order, report, finance — and a code
 * a vendor writes for their own goods is part of running that catalogue. A
 * sixth «marketing» area would be this plugin adding a permission the approved
 * matrix does not have.
 *
 * The commission base is not affected by any of this beyond the approved rule
 * that it is «پس از تخفیف و قبل از مالیات» — the discount reaches the ledger
 * by being inside the line total WooCommerce reports, never by a second
 * adjustment here.
 */
final class ManageCoupons
{
    public const GLOBAL_UNDECIDED = 'global_coupon_undecided';

    /** The decision that has to close before a marketplace-wide code exists. */
    public const GLOBAL_DECISION = 'DEC-04';

    public function __construct(
        private readonly EngagementRepositoryInterface $repository,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly ?CapabilityCheckerInterface $capabilities = null
    ) {
    }

    /**
     * @param int $vendorUserId the shop this code belongs to; 0 means
     *                          marketplace-wide, which is refused
     */
    public function create(
        int $actorId,
        int $vendorUserId,
        string $code,
        string $kind,
        int $value,
        int $minSubtotalMinor = 0,
        ?int $maxDiscountMinor = null,
        int $usageLimit = 0,
        int $perCustomerLimit = 0,
        ?string $startsAt = null,
        ?string $endsAt = null
    ): OperationResult {
        if ($vendorUserId === 0) {
            return OperationResult::failure(self::GLOBAL_UNDECIDED, ['decision' => self::GLOBAL_DECISION]);
        }
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $code = strtoupper(trim($code));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,64}$/', $code)) {
            return OperationResult::failure('coupon_code_invalid');
        }
        if (!in_array($kind, [Coupon::PERCENT, Coupon::FIXED], true)) {
            return OperationResult::failure('coupon_kind_invalid');
        }
        if ($value <= 0 || ($kind === Coupon::PERCENT && $value > 10000)) {
            // Percent is held in basis points, so 10000 is 100%: a code that
            // took more than the whole subtotal would be a payment out.
            return OperationResult::failure('coupon_value_invalid');
        }
        if ($endsAt !== null && $startsAt !== null && $endsAt < $startsAt) {
            return OperationResult::failure('coupon_window_invalid');
        }
        if ($this->repository->findCouponByCode($code) !== null) {
            return OperationResult::failure('coupon_code_taken', ['code' => $code]);
        }

        $id = $this->repository->createCoupon(new Coupon(
            0,
            $code,
            $vendorUserId,
            $kind,
            $value,
            max(0, $minSubtotalMinor),
            $maxDiscountMinor,
            max(0, $usageLimit),
            max(0, $perCustomerLimit),
            $startsAt,
            $endsAt,
            Coupon::ACTIVE,
            $actorId
        ));
        if ($id === 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::COUPON_CREATED, $actorId, 'coupon', (string) $id, [
            'vendor_id' => $vendorUserId,
            'coupon_id' => $id,
            'kind' => $kind,
            'value' => $value,
        ]);
        return OperationResult::success('coupon_created', ['coupon_id' => $id, 'code' => $code]);
    }

    public function disable(int $actorId, int $vendorUserId, int $couponId): OperationResult
    {
        $coupon = $this->repository->findCoupon($couponId);
        if ($coupon === null || !$coupon->belongsTo($vendorUserId)) {
            return OperationResult::failure('not_found');
        }
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        if (!$this->repository->setCouponStatus($couponId, Coupon::DISABLED)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::COUPON_DISABLED, $actorId, 'coupon', (string) $couponId, [
            'vendor_id' => $vendorUserId,
            'coupon_id' => $couponId,
        ]);
        return OperationResult::success('coupon_disabled', ['coupon_id' => $couponId]);
    }

    /** @return list<Coupon> */
    public function forVendor(int $actorId, int $vendorUserId): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::View)) {
            return [];
        }
        return $this->repository->couponsForVendor($vendorUserId);
    }

    /**
     * What this code takes off ONE vendor's part of a basket — and why not.
     *
     * The vendor id is required, not optional: a coupon is asked about a
     * shop's subtotal, never about a whole multi-vendor basket, so there is no
     * code path where one shop's code could discount another's goods.
     *
     * @return array{ok:bool, reason:string, discount_minor:int}
     */
    public function quote(string $code, int $vendorUserId, int $subtotalMinor, int $customerUserId = 0): array
    {
        $refuse = static fn (string $reason): array => ['ok' => false, 'reason' => $reason, 'discount_minor' => 0];
        $coupon = $this->repository->findCouponByCode(strtoupper(trim($code)));
        if ($coupon === null) {
            return $refuse('coupon_not_found');
        }
        if ($coupon->isGlobal()) {
            // It can only exist in a database somebody wrote by hand, and it
            // still does not apply: DEC-04 has not said who pays for it.
            return $refuse(self::GLOBAL_UNDECIDED);
        }
        if (!$coupon->belongsTo($vendorUserId)) {
            return $refuse('coupon_other_vendor');
        }
        if ($coupon->status !== Coupon::ACTIVE) {
            return $refuse('coupon_disabled');
        }
        if (!$coupon->isWithinWindow($this->clock->now()->format('Y-m-d H:i:s'))) {
            return $refuse('coupon_out_of_window');
        }
        if ($coupon->usageLimit > 0 && $this->repository->couponUseCount($coupon->id) >= $coupon->usageLimit) {
            return $refuse('coupon_exhausted');
        }
        if ($coupon->perCustomerLimit > 0 && $customerUserId > 0
            && $this->repository->couponUseCount($coupon->id, $customerUserId) >= $coupon->perCustomerLimit) {
            return $refuse('coupon_customer_limit');
        }
        $discount = $coupon->discountFor($subtotalMinor);
        if ($discount <= 0) {
            return $refuse('coupon_below_minimum');
        }
        return ['ok' => true, 'reason' => 'coupon_applies', 'discount_minor' => $discount];
    }

    /**
     * Records that an order used this code, once.
     *
     * False means the order had already been counted — which is the truth a
     * retried checkout callback needs, not an error.
     */
    public function recordUse(int $couponId, int $wcOrderId, int $customerUserId, int $amountMinor): bool
    {
        $recorded = $this->repository->recordCouponUse($couponId, $wcOrderId, $customerUserId, $amountMinor);
        if ($recorded) {
            $this->audit->log(AuditEventCatalog::COUPON_USED, $customerUserId, 'coupon', (string) $couponId, [
                'coupon_id' => $couponId,
                'order_id' => $wcOrderId,
                'amount_minor' => $amountMinor,
            ]);
        }
        return $recorded;
    }

    /**
     * Whether a marketplace-wide code may be made yet. It may not.
     *
     * A method rather than a constant so that the day DEC-04 closes, the
     * screens asking this question do not change — only the answer does.
     */
    public function globalCouponsAvailable(): bool
    {
        return false;
    }
}
