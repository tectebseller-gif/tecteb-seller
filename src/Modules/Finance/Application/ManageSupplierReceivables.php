<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierReceivable;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations as V;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * A RETURN AFTER THE SUPPLIER WAS PAID — three events, never two.
 *
 * The owner's §2 settled the decision and left the mechanism to be built:
 * «تصمیم با مدیر است: ثبت وصول واقعی وجه از تأمین‌کننده، یا ثبت مبلغ
 * قابل‌کسر از پرداخت بعدی. انتقال بانکی و کسر خودکار انجام نشود. ثبت طلب،
 * وصول واقعی و تهاتر سه رویداد متفاوت باشند؛ یک مبلغ دوبار وصول یا کسر نشود.»
 *
 * Three verbs, and the names are the owner's:
 *
 *   `record()`  ثبت طلب — the debt exists. No money moves, nothing is
 *               deducted, and the manager has made no choice yet.
 *   `collect()` وصول واقعی — the supplier sent the money back and somebody
 *               wrote down that they did. Still not a transfer: this records
 *               a fact about the world, it does not reach a bank.
 *   `offset()`  تهاتر — the amount is set against what Tecteb owes the
 *               supplier next. Recorded by a person, never applied by this
 *               code to a payment on its own.
 *
 * **The deduction is not automatic, and that is enforced by absence.**
 * Nothing in `RecordSupplierPayment` reads this table and subtracts from an
 * amount. The payments screen SHOWS an open receivable, because a manager who
 * cannot see it cannot act on it, and showing is not deducting.
 *
 * **One amount, one balance.** `record()` writes what is owed; both
 * settlements are weighed against the remainder inside the writing statement,
 * so «یک مبلغ دوبار وصول یا کسر نشود» is a subtraction nobody can get
 * around rather than a rule somebody has to remember.
 *
 * **A partial return touches only its own items and quantities.** The amount
 * and the quantity both come from the caller, per item, and nothing here
 * reaches for the rest of the order: «مرجوعی جزئی فقط روی اقلام و تعداد
 * مرتبط اثر بگذارد».
 *
 * **What this does NOT decide**: the terms on which a return is accepted at
 * all. That is DEC-03 and it is still open; §2 says so itself («این تصمیم
 * جایگزین تعیین شرایط پذیرش مرجوعی نیست»), and it is reported separately in
 * `docs/open-decisions-alpha62.md` rather than quietly folded in here.
 */
final class ManageSupplierReceivables
{
    /** The marketplace's financial-management permission — the EXISTING one. */
    public const CAPABILITY = 'tmc_review_withdrawals';

    public const RECORDED = 'supplier_receivable_recorded';
    public const ALREADY_RECORDED = 'supplier_receivable_already_recorded';
    public const COLLECTED = 'supplier_receivable_collected';
    public const OFFSET = 'supplier_receivable_offset';
    public const ALREADY_SETTLED = 'supplier_receivable_already_settled';
    /** More than the remaining balance — refused, never carried forward. */
    public const ABOVE_BALANCE = 'supplier_receivable_above_balance';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'supplier_receivable_not_found';
    public const ITEM_UNKNOWN = 'supplier_item_unknown';
    /** Nothing was ever paid for this item, so there is nothing to claim back. */
    public const ITEM_NOT_PAID = 'supplier_item_not_paid';
    public const AMOUNT_INVALID = 'supplier_receivable_amount_invalid';
    public const UNIT_UNKNOWN = 'supplier_unit_unknown';
    public const STORAGE_FAILED = 'storage_failed';

    public function __construct(
        private readonly SupplierReceivableRepositoryInterface $receivables,
        private readonly SupplierPaymentRepositoryInterface $payments,
        private readonly OrderItemRepositoryInterface $items,
        private readonly VendorMoneyUnitRegistryInterface $units,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * ثبت طلب — the debt, and nothing else.
     *
     * Refused when the item was never paid for, because then there is nothing
     * to claim back and the ordinary return path already handles it: a
     * receivable against an unpaid item would be the marketplace inventing a
     * debt. Idempotent on `(return, item)`, so a resubmitted form names the
     * debt that exists rather than making a second one.
     */
    public function record(
        int $orderItemId,
        string $amount,
        int $returnId = 0,
        int $quantity = 0,
        string $reason = ''
    ): OperationResult {
        if (!$this->capabilities->can(self::CAPABILITY)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        $item = $this->items->find($orderItemId);
        if ($item === null) {
            return OperationResult::failure(self::ITEM_UNKNOWN, ['order_item_id' => $orderItemId]);
        }
        $supplierUserId = (int) $item->vendorUserId;
        $paidBy = $this->payments->forOrderItem($orderItemId);
        if ($paidBy === null || $paidBy->isVoid()) {
            // «مرجوعی پس از پرداخت به تأمین‌کننده» — the premise of the whole
            // decision. A voided payment is not a payment, so an item whose
            // only payment was voided is in the same position as one never
            // paid for at all.
            return OperationResult::failure(self::ITEM_NOT_PAID, ['order_item_id' => $orderItemId]);
        }

        $unit = $this->units->unitOf($supplierUserId);
        if (!is_array($unit) || ($unit['currency'] ?? '') === '') {
            return OperationResult::failure(self::UNIT_UNKNOWN, ['supplier_id' => $supplierUserId]);
        }
        $currency = (string) $unit['currency'];
        $exponent = (int) ($unit['exponent'] ?? 0);

        $minor = DecimalAmount::toMinor(trim($amount), $exponent);
        if ($minor === null || $minor <= 0) {
            return OperationResult::failure(self::AMOUNT_INVALID, ['typed' => trim($amount)]);
        }
        if ($minor > $paidBy->amount->minor) {
            // More than was ever paid for the item. Not a debt — an error in
            // one of the two numbers, and the manager is shown both.
            return OperationResult::failure(self::ABOVE_BALANCE, [
                'order_item_id' => $orderItemId,
                'amount_minor' => $minor,
                'paid_minor' => $paidBy->amount->minor,
            ]);
        }

        $written = $this->receivables->record(
            $supplierUserId,
            $orderItemId,
            $returnId,
            $quantity,
            $minor,
            $currency,
            $exponent,
            $reason,
            (int) $this->capabilities->currentUserId(),
            self::eventKey($returnId, $orderItemId)
        );
        if ($written['outcome'] === SupplierReceivableRepositoryInterface::ALREADY_RECORDED) {
            return OperationResult::failure(self::ALREADY_RECORDED, [
                'receivable_id' => (int) $written['receivable_id'],
                'order_item_id' => $orderItemId,
            ]);
        }
        if ($written['outcome'] !== SupplierReceivableRepositoryInterface::RECORDED) {
            return OperationResult::failure(self::STORAGE_FAILED, ['order_item_id' => $orderItemId]);
        }

        $this->audit->log(
            AuditEventCatalog::SUPPLIER_RECEIVABLE_RECORDED,
            0,
            'supplier_receivable',
            (string) $written['receivable_id'],
            [
                'supplier_id' => $supplierUserId,
                'order_item_id' => $orderItemId,
                'return_id' => $returnId,
                'amount_minor' => $minor,
                'quantity' => $quantity,
            ]
        );
        return OperationResult::success(self::RECORDED, [
            'receivable_id' => (int) $written['receivable_id'],
            'order_item_id' => $orderItemId,
            'amount_minor' => $minor,
            'payment_id' => $paidBy->id,
        ]);
    }

    /** وصول واقعی — the supplier sent it back, and somebody wrote that down. */
    public function collect(
        int $receivableId,
        string $amount,
        string $reference,
        string $note = '',
        string $idempotencyKey = ''
    ): OperationResult {
        return $this->settle($receivableId, V::SETTLEMENT_COLLECTED, $amount, 0, $reference, $note, $idempotencyKey);
    }

    /**
     * تهاتر — set against what Tecteb owes this supplier next.
     *
     * `$paymentId` is optional and is a REFERENCE, not an instruction: it
     * records which payment the manager intends to set it against, and
     * nothing in this plugin subtracts it from that payment's amount.
     */
    public function offset(
        int $receivableId,
        string $amount,
        int $paymentId = 0,
        string $note = '',
        string $idempotencyKey = ''
    ): OperationResult {
        return $this->settle($receivableId, V::SETTLEMENT_OFFSET, $amount, $paymentId, '', $note, $idempotencyKey);
    }

    /** @return list<SupplierReceivable> */
    public function openFor(int $supplierUserId, int $limit = 50): array
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->receivables->openFor($supplierUserId, $limit)
            : [];
    }

    /**
     * Every open debt, for the manager's screen.
     *
     * @return list<SupplierReceivable>
     */
    public function allOpen(int $limit = 100): array
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->receivables->allOpen($limit)
            : [];
    }

    /** @return list<SupplierReceivable> */
    public function forSupplier(int $supplierUserId, int $limit = 50): array
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->receivables->forSupplier($supplierUserId, $limit)
            : [];
    }

    /**
     * @return list<array{id:int, kind:string, amount_minor:int, payment_id:int, reference:string, recorded_by:int, created_at:string}>
     */
    public function settlementsOf(int $receivableId, int $limit = 50): array
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->receivables->settlementsOf($receivableId, $limit)
            : [];
    }

    /** One receivable per return and item, so a resubmission writes nothing. */
    public static function eventKey(int $returnId, int $orderItemId): string
    {
        return 'supplier-receivable:' . max(0, $returnId) . ':' . $orderItemId;
    }

    /**
     * One settlement, whichever kind — the balance guard is the same for both
     * and so is the idempotency, which is the point of one method.
     */
    private function settle(
        int $receivableId,
        string $kind,
        string $amount,
        int $paymentId,
        string $reference,
        string $note,
        string $idempotencyKey
    ): OperationResult {
        if (!$this->capabilities->can(self::CAPABILITY)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        $receivable = $this->receivables->find($receivableId);
        if ($receivable === null) {
            return OperationResult::failure(self::NOT_FOUND, ['receivable_id' => $receivableId]);
        }
        $minor = DecimalAmount::toMinor(trim($amount), $receivable->amount->exponent);
        if ($minor === null || $minor <= 0) {
            return OperationResult::failure(self::AMOUNT_INVALID, ['typed' => trim($amount)]);
        }
        $key = trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : 'auto:' . hash('sha256', implode('|', [$receivableId, $kind, $minor, $paymentId, trim($reference)]));

        $written = $this->receivables->settle(
            $receivableId,
            $kind,
            $minor,
            $paymentId,
            $reference,
            $note,
            (int) $this->capabilities->currentUserId(),
            $key
        );
        if ($written['outcome'] === SupplierReceivableRepositoryInterface::ABOVE_BALANCE) {
            return OperationResult::failure(self::ABOVE_BALANCE, [
                'receivable_id' => $receivableId,
                'amount_minor' => $minor,
                'balance_minor' => (int) $written['balance_minor'],
            ]);
        }
        if ($written['outcome'] === SupplierReceivableRepositoryInterface::ALREADY_SETTLED) {
            return OperationResult::failure(self::ALREADY_SETTLED, [
                'receivable_id' => $receivableId,
                'settlement_id' => (int) $written['settlement_id'],
            ]);
        }
        if ($written['outcome'] !== SupplierReceivableRepositoryInterface::SETTLED) {
            return OperationResult::failure(self::STORAGE_FAILED, ['receivable_id' => $receivableId]);
        }

        $this->audit->log(
            AuditEventCatalog::SUPPLIER_RECEIVABLE_SETTLED,
            0,
            'supplier_receivable',
            (string) $receivableId,
            [
                'supplier_id' => $receivable->supplierUserId,
                'receivable_id' => $receivableId,
                'kind' => $kind,
                'amount_minor' => $minor,
                'balance_minor' => (int) $written['balance_minor'],
            ]
        );
        return OperationResult::success(
            $kind === V::SETTLEMENT_COLLECTED ? self::COLLECTED : self::OFFSET,
            [
                'receivable_id' => $receivableId,
                'settlement_id' => (int) $written['settlement_id'],
                'amount_minor' => $minor,
                'balance_minor' => (int) $written['balance_minor'],
            ]
        );
    }
}
