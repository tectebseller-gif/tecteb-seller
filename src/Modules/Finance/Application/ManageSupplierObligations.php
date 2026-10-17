<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierObligation;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * RECORDING WHAT WAS AGREED WITH A SUPPLIER — the number, and its history.
 *
 * The owner's §3 opens by separating two things this module had conflated:
 * «مبلغ توافق‌شدهٔ خرید با تأمین‌کننده، سهم محاسبه‌شدهٔ کمیسیون نیست و نباید
 * برابر فرض شود. مبلغ توافق باید صریح ثبت شود و تغییراتش سابقه داشته باشد.»
 *
 * So there are exactly two verbs here and no third:
 *
 *   `agree()`     write the number down, or change it, with a reason
 *   `historyFor()` read every change that was ever made to it
 *
 * There is NO verb that derives an agreed amount from anything. Not from the
 * commission model, not from the line total, not from a previous order. A
 * number nobody typed is not an agreement, and the only honest answer when
 * nobody has typed one is «نامعلوم» — which is a nullable column rather than
 * a zero (`alpha.28`).
 *
 * **Lowering the amount below what has already been paid is refused.** That
 * is not a correction; it is a statement that an over-payment happened, and
 * §3 says an over-payment has no defined meaning yet. The refusal names both
 * numbers so the manager can decide which one is wrong.
 *
 * **Changing it does not touch anything already recorded.** «رکوردهای قبلی و
 * snapshot سفارش بازنویسی نشوند» — payments keep their amounts, allocations
 * keep theirs, and the order-item snapshot is not read here at all. What
 * changes is the balance from now on, and the history row says when and why.
 */
final class ManageSupplierObligations
{
    /** The marketplace's financial-management permission — the EXISTING one. */
    public const CAPABILITY = 'tmc_review_withdrawals';

    public const AGREED = 'supplier_amount_agreed';
    public const UNCHANGED = 'supplier_amount_unchanged';
    public const CLEARED = 'supplier_amount_cleared';
    public const FORBIDDEN = 'forbidden';
    public const ITEM_UNKNOWN = 'supplier_item_unknown';
    public const WRONG_SUPPLIER = 'supplier_items_not_theirs';
    public const AMOUNT_INVALID = 'supplier_agreed_amount_invalid';
    public const UNIT_UNKNOWN = 'supplier_unit_unknown';
    /** The new amount is under what has already been handed over. */
    public const BELOW_PAID = 'supplier_agreed_below_paid';
    public const STORAGE_FAILED = 'storage_failed';

    public function __construct(
        private readonly SupplierObligationRepositoryInterface $obligations,
        private readonly OrderItemRepositoryInterface $items,
        private readonly VendorMoneyUnitRegistryInterface $units,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param string $amount the agreed amount as the person typed it, exact
     *        decimal text — or `''` for «we have no agreed amount», which is
     *        a real answer and not the same as `0`
     */
    public function agree(int $orderItemId, string $amount, string $reason = ''): OperationResult
    {
        if (!$this->capabilities->can(self::CAPABILITY)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        $item = $this->items->find($orderItemId);
        if ($item === null) {
            return OperationResult::failure(self::ITEM_UNKNOWN, ['order_item_id' => $orderItemId]);
        }
        $supplierUserId = (int) $item->vendorUserId;
        if ($supplierUserId <= 0) {
            return OperationResult::failure(self::WRONG_SUPPLIER, ['order_item_id' => $orderItemId]);
        }

        // THE UNIT, from the supplier's own books and never defaulted — the
        // `alpha.39`/`alpha.40` rule, and the reason this service cannot be
        // called into existence with `'IRR', 0`.
        $unit = $this->units->unitOf($supplierUserId);
        if (!is_array($unit) || ($unit['currency'] ?? '') === '') {
            return OperationResult::failure(self::UNIT_UNKNOWN, ['supplier_id' => $supplierUserId]);
        }
        $currency = (string) $unit['currency'];
        $exponent = (int) ($unit['exponent'] ?? 0);

        $typed = trim($amount);
        $minor = null;
        if ($typed !== '') {
            $minor = DecimalAmount::toMinor($typed, $exponent);
            if ($minor === null || $minor < 0) {
                // Refused rather than rounded, so nobody has to pick a
                // direction for somebody else's money. Zero IS accepted: a
                // line a supplier is owed nothing for is a real agreement.
                return OperationResult::failure(self::AMOUNT_INVALID, ['typed' => $typed]);
            }
        }

        $written = $this->obligations->agree(
            $orderItemId,
            $supplierUserId,
            $minor,
            $currency,
            $exponent,
            (int) $this->capabilities->currentUserId(),
            $reason
        );
        if ($written['outcome'] === SupplierObligationRepositoryInterface::BELOW_PAID) {
            return OperationResult::failure(self::BELOW_PAID, [
                'order_item_id' => $orderItemId,
                'typed' => $typed,
                'amount_minor' => $minor,
                'paid_minor' => (int) $written['paid_minor'],
            ]);
        }
        if ($written['outcome'] === SupplierObligationRepositoryInterface::UNCHANGED) {
            return OperationResult::failure(self::UNCHANGED, [
                'order_item_id' => $orderItemId,
                'obligation_id' => (int) $written['obligation_id'],
                'amount_minor' => $minor,
            ]);
        }
        if ($written['outcome'] !== SupplierObligationRepositoryInterface::AGREED) {
            return OperationResult::failure(self::STORAGE_FAILED, ['order_item_id' => $orderItemId]);
        }

        $this->audit->log(
            AuditEventCatalog::SUPPLIER_AMOUNT_AGREED,
            0,
            'supplier_obligation',
            (string) $written['obligation_id'],
            [
                'supplier_id' => $supplierUserId,
                'order_item_id' => $orderItemId,
                'previous_minor' => $written['previous_minor'],
                'new_minor' => $minor,
                'has_reason' => trim($reason) !== '' ? 1 : 0,
            ]
        );
        return OperationResult::success($minor === null ? self::CLEARED : self::AGREED, [
            'order_item_id' => $orderItemId,
            'obligation_id' => (int) $written['obligation_id'],
            'previous_minor' => $written['previous_minor'],
            'amount_minor' => $minor,
            'paid_minor' => (int) $written['paid_minor'],
        ]);
    }

    public function forOrderItem(int $orderItemId): ?SupplierObligation
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->obligations->forOrderItem($orderItemId)
            : null;
    }

    /** @return list<SupplierObligation> */
    public function forSupplier(int $supplierUserId, int $limit = 50): array
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->obligations->forSupplier($supplierUserId, $limit)
            : [];
    }

    /**
     * @return list<array{id:int, previous_minor:?int, new_minor:?int, reason:string, changed_by:int, created_at:string}>
     */
    public function historyFor(int $obligationId, int $limit = 50): array
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->obligations->historyFor($obligationId, $limit)
            : [];
    }
}
