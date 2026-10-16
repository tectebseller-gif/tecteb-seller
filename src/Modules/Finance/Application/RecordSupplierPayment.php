<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments as T;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0030SupplierPaymentShareUnknown as U;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;

/**
 * WRITING DOWN MONEY TECTEB HAS ALREADY PAID A SUPPLIER.
 *
 * The owner's §4 settled how the business runs: the buyer pays Tecteb through
 * WooCommerce and ZarinPal; Tecteb confirms supply and then **pays the
 * supplier by hand**; the supplier ships to the buyer. There is no transfer to
 * automate here and this service contains none — «نباید انتقال واقعی وجه انجام
 * دهد». What it does is record a payment that happened, with everything
 * needed to find it again: the supplier, the order items it covers, the
 * amount, the currency, the date, who recorded it, the tracking number and an
 * optional receipt.
 *
 * **Three things it refuses to decide.**
 *
 * 1. **The amount.** It comes from the person. There is no parameter this
 *    service can be called with that makes it derive one, and zero is refused
 *    — «مبلغ خرید از تأمین‌کننده را از صفر یا نرخ حدسی تولید نکن».
 *
 * 2. **Whether the amount settles what the supplier was owed.** The commission
 *    model computes a vendor share from each recorded sale; a cash purchase
 *    price is what Tecteb agreed to pay. The owner has not said how the two
 *    relate, and they are not the same question
 *    («فرض نکن قیمت خرید نقدی با سهم محاسبه‌شده برابر است»). So BOTH numbers
 *    are recorded, and the money line in `tmc_ledger` is written only when
 *    they are the same money. When they differ, the payment is recorded,
 *    marked `needs_definition`, listed, and nothing is posted — because a
 *    payout line that says «this settles their earnings» about an amount that
 *    does not would be the marketplace stating a business rule nobody wrote.
 *
 * 3. **The money unit.** Read off the supplier's own books through the
 *    registry, never defaulted. A supplier with no unit on record, or with
 *    two, is refused by name — the `alpha.39` and `alpha.40` rule, and the
 *    reason this service cannot be called into existence with `'IRR', 0`.
 *
 * **No parallel ledger** («دفتر موازی نساز»): when there is a money line it
 * goes into `tmc_ledger` through the same `LedgerTransaction` every other
 * amount in this plugin uses. The payment table holds the paperwork, not the
 * money.
 */
final class RecordSupplierPayment
{
    /** The marketplace's financial-management permission — the EXISTING one. */
    public const CAPABILITY = 'tmc_review_withdrawals';

    public const RECORDED = 'supplier_payment_recorded';
    public const RECORDED_UNPOSTED = 'supplier_payment_recorded_unposted';
    /**
     * Recorded, and the comparison with the computed share COULD NOT BE MADE
     * because at least one item's share is not on record.
     *
     * A third answer rather than a shade of the second: `RECORDED_UNPOSTED`
     * means both numbers were known and differed — the owner's open question —
     * and this one means there was no second number to compare with. Telling
     * them apart is the whole of §3.
     */
    public const RECORDED_SHARE_UNKNOWN = 'supplier_payment_recorded_share_unknown';

    public const FORBIDDEN = 'forbidden';
    public const NO_ITEMS = 'supplier_payment_no_items';
    public const AMOUNT_REQUIRED = 'supplier_amount_required';
    public const REFERENCE_REQUIRED = 'reference_required';
    public const DATE_REQUIRED = 'supplier_paid_on_required';
    public const UNIT_UNKNOWN = 'supplier_unit_unknown';
    public const WRONG_SUPPLIER = 'supplier_items_not_theirs';
    public const ITEM_UNKNOWN = 'supplier_item_unknown';
    public const ITEM_ALREADY_PAID = 'supplier_item_already_paid';
    /**
     * The item is claimed by the vendor's own withdrawal cycle.
     *
     * Reserved in an open request, in payment, or kept by a paid one — a paid
     * request holds its lines for ever as the record of what it covered. The
     * refusal names the item and the request so the manager can look at the
     * one that already exists; it never releases anything, because releasing
     * somebody's reservation to make room for a payment is a decision, not a
     * repair («رزرو را خودکار آزاد نکن»).
     */
    public const ITEM_HELD_BY_WITHDRAWAL = 'supplier_item_held_by_withdrawal';
    public const ALREADY_RECORDED = 'supplier_payment_already_recorded';
    public const NOT_ATOMIC = 'supplier_payment_not_atomic';
    public const STORAGE_FAILED = 'storage_failed';

    public function __construct(
        private readonly SupplierPaymentRepositoryInterface $payments,
        private readonly OrderItemRepositoryInterface $items,
        private readonly LedgerRepositoryInterface $ledger,
        private readonly VendorMoneyUnitRegistryInterface $units,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        /**
         * The unit of work the record and its ledger line share.
         *
         * MUST be the same gateway object the repository holds, not a second
         * one over the same connection: nesting is counted in the OBJECT, so a
         * decorator wrapping a second instance turns the repository's inner
         * `begin()` into a real `START TRANSACTION` and implicitly commits
         * this one — measured in `alpha.56`, where a rolled-back intake left
         * its row behind.
         */
        private readonly ?TransactionInterface $tx = null
    ) {
    }

    /**
     * @param list<int> $orderItemIds the marketplace order-item ids this payment covers
     * @param string $amount the amount as the person typed it, exact decimal text
     * @param string $paidOn `Y-m-d`, the date on the receipt
     * @param string $idempotencyKey the form's own token; the same token never writes twice
     */
    public function record(
        int $supplierUserId,
        array $orderItemIds,
        string $amount,
        string $paidOn,
        string $reference,
        string $note = '',
        string $receiptPath = '',
        string $idempotencyKey = ''
    ): OperationResult {
        if (!$this->capabilities->can(self::CAPABILITY)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        $ids = array_values(array_unique(array_map('intval', $orderItemIds)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === [] || $supplierUserId <= 0) {
            return OperationResult::failure(self::NO_ITEMS);
        }
        if (trim($reference) === '') {
            // The tracking number is what makes the row findable against a
            // bank statement, which is the whole point of recording it.
            return OperationResult::failure(self::REFERENCE_REQUIRED);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($paidOn)) !== 1) {
            return OperationResult::failure(self::DATE_REQUIRED);
        }

        // THE UNIT, from the supplier's own books. Asked before the amount is
        // read, because the amount cannot be converted without it and a
        // default would decide the currency of somebody's purchase.
        $unit = $this->units->unitOf($supplierUserId);
        if (!is_array($unit) || ($unit['currency'] ?? '') === '') {
            return OperationResult::failure(self::UNIT_UNKNOWN, ['supplier_id' => $supplierUserId]);
        }
        $currency = (string) $unit['currency'];
        $exponent = (int) ($unit['exponent'] ?? 0);

        $minor = DecimalAmount::toMinor(trim($amount), $exponent);
        if ($minor === null || $minor <= 0) {
            // Zero is not a payment and an inexact amount is not one either:
            // «مبلغ … را از صفر یا نرخ حدسی تولید نکن», and `DecimalAmount`
            // refuses rather than rounds so nobody has to pick a direction.
            return OperationResult::failure(self::AMOUNT_REQUIRED, ['typed' => trim($amount)]);
        }

        // THE KEY FIRST. A refreshed page fails the per-item test below as
        // well, and the per-item refusal would then name an item and read
        // like a mistake; this one points the manager at the payment they
        // already made. The unique key in the table is still the guard — two
        // tabs that both pass this read collide there — and this is only
        // about which true sentence to say.
        $key = trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : self::keyFor($supplierUserId, $ids, $minor, trim($paidOn), trim($reference));
        $sameSubmission = $this->payments->findByKey($key);
        if ($sameSubmission !== null) {
            return OperationResult::failure(self::ALREADY_RECORDED, ['payment_id' => $sameSubmission->id]);
        }

        // THE ITEMS, each checked against both paths that can already have
        // settled it. Read before anything is written so the refusal can name
        // the item and point at what holds it — the guard itself is inside the
        // write, in `record()`, because the two paths are two requests.
        // Checked for OWNER as well as existence: an item of another supplier
        // in this list would make one supplier's payment cover another's sale.
        // «سفارش چندتأمین‌کننده‌ای اقلام و دسترسی جدا دارد.»
        //
        // One read for the whole set, because `settlementView()` is the one
        // place the three facts a settlement reasons about are read together —
        // the same view `VendorBalance` uses, so the two cannot disagree about
        // whether a line is claimed.
        $claims = [];
        foreach ($this->items->settlementView($supplierUserId) as $line) {
            $claims[(int) $line['id']] = $line;
        }

        $shareMinor = 0;
        /** @var array<int,?int> $shares */
        $shares = [];
        /** @var list<int> $unknown */
        $unknown = [];
        foreach ($ids as $itemId) {
            $item = $this->items->find($itemId);
            if ($item === null) {
                return OperationResult::failure(self::ITEM_UNKNOWN, ['order_item_id' => $itemId]);
            }
            if ($item->vendorUserId !== $supplierUserId) {
                return OperationResult::failure(self::WRONG_SUPPLIER, [
                    'order_item_id' => $itemId,
                    'belongs_to' => $item->vendorUserId,
                ]);
            }
            $already = $this->payments->forOrderItem($itemId);
            if ($already !== null) {
                return OperationResult::failure(self::ITEM_ALREADY_PAID, [
                    'order_item_id' => $itemId,
                    'payment_id' => $already->id,
                    'paid_on' => $already->paidOn,
                    'reference' => $already->reference,
                ]);
            }
            $heldBy = (int) ($claims[$itemId]['withdrawal_id'] ?? 0);
            if ($heldBy > 0) {
                // ONE OBLIGATION, ONE SETTLEMENT. The vendor's own cycle has
                // this line — reserved, in payment, or paid — and recording a
                // purchase for it as well would settle the same sale twice
                // from two paths. Nothing is released and nothing is erased;
                // the manager is told which request to look at.
                return OperationResult::failure(self::ITEM_HELD_BY_WITHDRAWAL, [
                    'order_item_id' => $itemId,
                    'withdrawal_id' => $heldBy,
                    'withdrawal_paid' => ($claims[$itemId]['paid'] ?? false) ? 1 : 0,
                ]);
            }
            // AN UNKNOWN SHARE IS NOT A SHARE OF NOUGHT. `null` means this sale
            // recorded no money — an unreadable unit, a refused ledger write, a
            // commission bigger than the line — and `incompleteCaptures()`
            // lists exactly these. `alpha.60` added `(int) (null ?? 0)` into
            // the sum and then compared, so one unknown item hid inside the
            // arithmetic of the rest: measured, a payment equal to the FIRST
            // item's share alone was declared to settle both and posted.
            //
            // `isRecorded()` is the bar, and it is the domain's own: a share
            // AND the ledger event it was written under. A figure sitting in a
            // row with no money on the books is not a number this service may
            // declare a match against.
            if (!$item->isRecorded()) {
                $unknown[] = $itemId;
                $shares[$itemId] = null;
                continue;
            }
            $shares[$itemId] = (int) $item->vendorShareMinor;
            $shareMinor += (int) $item->vendorShareMinor;
        }

        $paid = Money::of($minor, $currency, $exponent);
        // The share carried on the row is the sum of the KNOWN items, which is
        // a true statement about those items and not a total; `posting` is
        // what says whether it may be read as one.
        $share = Money::of($shareMinor, $currency, $exponent);
        $posting = match (true) {
            // The comparison could not be made at all. Not a difference — an
            // absence — and never posted, whatever the arithmetic says.
            $unknown !== [] => U::POSTING_SHARE_UNKNOWN,
            $paid->minor === $share->minor => T::POSTING_POSTED,
            default => T::POSTING_NEEDS_DEFINITION,
        };

        if ($this->tx === null) {
            // A build that cannot make the record and its money line one unit
            // does not get to write either — the same posture as
            // `payment_not_atomic` in `ReviewWithdrawals`.
            return OperationResult::failure(self::NOT_ATOMIC, ['supplier_id' => $supplierUserId]);
        }

        if (!$this->tx->begin()) {
            return OperationResult::failure(self::STORAGE_FAILED);
        }

        $record = new SupplierPayment(
            0,
            $supplierUserId,
            $paid,
            $share,
            trim($paidOn),
            trim($reference),
            $note,
            $receiptPath,
            (int) $this->capabilities->currentUserId(),
            $key,
            '',
            // Written UNPOSTED whatever the comparison said, and moved to
            // `posted` only once the money line is really in the ledger:
            // `markPosted()` is guarded on this state, so a row that says
            // «posted» with no entry behind it cannot exist (FIN-03).
            $posting === T::POSTING_POSTED ? T::POSTING_NEEDS_DEFINITION : $posting,
            ''
        );
        $written = $this->payments->record($record, $shares);
        if ($written['outcome'] !== SupplierPaymentRepositoryInterface::RECORDED) {
            $this->tx->rollback();
            return match ($written['outcome']) {
                SupplierPaymentRepositoryInterface::ALREADY_RECORDED => OperationResult::failure(
                    self::ALREADY_RECORDED,
                    ['payment_id' => $written['payment_id']]
                ),
                SupplierPaymentRepositoryInterface::ITEM_ALREADY_PAID => OperationResult::failure(
                    self::ITEM_ALREADY_PAID,
                    ['order_item_id' => $written['conflicting_item']]
                ),
                // The claim the STATEMENT refused — the race the read above
                // cannot see, because the withdrawal landed after it.
                SupplierPaymentRepositoryInterface::ITEM_HELD_BY_WITHDRAWAL => OperationResult::failure(
                    self::ITEM_HELD_BY_WITHDRAWAL,
                    [
                        'order_item_id' => $written['conflicting_item'],
                        'withdrawal_id' => $written['conflicting_withdrawal'] ?? 0,
                    ]
                ),
                default => OperationResult::failure(self::STORAGE_FAILED),
            };
        }
        $paymentId = (int) $written['payment_id'];

        $event = '';
        if ($posting === T::POSTING_POSTED) {
            $event = self::eventKey($paymentId);
            if (!$this->postPayout($supplierUserId, $event, $paymentId, $paid)
                || !$this->payments->markPosted($paymentId, $event)) {
                // A row that says «posted» with no entry behind it, or an
                // entry with no row, is the pair FIN-03 exists to prevent.
                $this->tx->rollback();
                return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
            }
        }

        if (!$this->tx->commit()) {
            $this->tx->rollback();
            return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
        }

        $this->audit->log(AuditEventCatalog::SUPPLIER_PAYMENT_RECORDED, 0, 'supplier_payment', (string) $paymentId, [
            'supplier_id' => $supplierUserId,
            'amount_minor' => $paid->minor,
            'share_minor' => $share->minor,
            'items' => count($ids),
            'posting' => $posting,
            'unknown_share_items' => count($unknown),
            'has_receipt' => $receiptPath !== '' ? 1 : 0,
        ]);

        return OperationResult::success(
            match ($posting) {
                T::POSTING_POSTED => self::RECORDED,
                U::POSTING_SHARE_UNKNOWN => self::RECORDED_SHARE_UNKNOWN,
                default => self::RECORDED_UNPOSTED,
            },
            [
                'payment_id' => $paymentId,
                'amount_minor' => $paid->minor,
                'share_minor' => $share->minor,
                'ledger_event' => $event,
                // The items whose share was not on record, BY NUMBER, because
                // «۱۲ قلم، یکی‌شان نامعلوم» is not something a person can act
                // on. Stored too, as `NULL` on each item's own row.
                'unknown_share_items' => $unknown,
            ]
        );
    }

    /**
     * The payments recorded but not posted, for the screen that lists them.
     *
     * @return list<SupplierPayment>
     */
    public function awaitingDefinition(int $limit = 100): array
    {
        return $this->capabilities->can(self::CAPABILITY)
            ? $this->payments->awaitingDefinition($limit)
            : [];
    }

    /** One reserved event key per payment, so a retry writes nothing twice. */
    public static function eventKey(int $paymentId): string
    {
        return 'supplier-payment:' . $paymentId;
    }

    /**
     * The key that makes the same submission unable to write twice, when the
     * caller has no token of its own.
     *
     * Derived from the submission's own content — supplier, items, amount,
     * date, reference — so a refresh produces the same key and a genuinely
     * different payment produces a different one. A caller that HAS a form
     * token should pass it instead: a manager who really means to record two
     * identical payments on one day can then do so, and this fallback cannot
     * tell those apart from a double click.
     *
     * @param list<int> $orderItemIds
     */
    public static function keyFor(
        int $supplierUserId,
        array $orderItemIds,
        int $amountMinor,
        string $paidOn,
        string $reference
    ): string {
        sort($orderItemIds);
        return 'auto:' . hash('sha256', implode('|', [
            $supplierUserId,
            implode(',', $orderItemIds),
            $amountMinor,
            $paidOn,
            $reference,
        ]));
    }

    private function postPayout(int $supplierUserId, string $event, int $paymentId, Money $amount): bool
    {
        // The same two accounts a paid withdrawal writes, because it is the
        // same movement: money leaves the marketplace and the supplier's
        // earnings are reduced by what they were handed.
        $transaction = (new LedgerTransaction($event, $supplierUserId, 'supplier-payment', (string) $paymentId))
            ->snapshot([
                'kind' => 'supplier_payment',
                'payment_id' => $paymentId,
                'amount_minor' => $amount->minor,
                'currency' => $amount->currency,
                'exponent' => $amount->exponent,
            ])
            ->add(LedgerAccount::VendorEarning, $amount, 'supplier_paid')
            ->add(LedgerAccount::CentralPayment, $amount->negate(), 'supplier_paid');

        return $transaction->balances() && $this->ledger->record($transaction);
    }
}
