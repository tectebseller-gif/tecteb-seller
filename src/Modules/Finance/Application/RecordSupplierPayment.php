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
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations as V;
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

    /**
     * Recorded, and the agreed amount is not covered yet.
     *
     * «پرداخت کمتر از تعهد، پرداخت جزئی با باقی‌ماندهٔ مانده است، نه تسویهٔ
     * کامل» — so this is a SUCCESS with a balance, and not one of the three
     * «waiting on a person» states. The remaining balance travels in the
     * result so the screen can say it.
     */
    public const RECORDED_PARTIAL = 'supplier_payment_recorded_partial';
    /**
     * Recorded, and there is an obligation with no agreed amount on it.
     *
     * The manager said this item has an agreed purchase price and has not yet
     * written the number down. Different from `RECORDED_SHARE_UNKNOWN`, which
     * is about the COMPUTED share, and different again from no obligation at
     * all — which is the ordinary case and behaves exactly as it did before.
     */
    public const RECORDED_AGREED_UNKNOWN = 'supplier_payment_recorded_agreed_unknown';

    /** More than the balance. Refused by name, never turned into a credit. */
    public const ABOVE_BALANCE = 'supplier_payment_above_balance';
    /** The obligation is already covered in full. */
    public const OBLIGATION_SETTLED = 'supplier_obligation_settled';
    /**
     * Several items, and at least one of them has an agreed amount.
     *
     * How one payment should be split across several agreed amounts is not
     * something this build will guess: an automatic split would be a decision
     * nobody made, about money. One payment per item, and the refusal says so.
     * Asked as an open question with a worked example in
     * `docs/open-decisions-alpha62.md`.
     */
    public const ALLOCATION_AMBIGUOUS = 'supplier_payment_allocation_ambiguous';

    /** The void needs a reason, an actor and a time — all three. */
    public const VOID_REASON_REQUIRED = 'supplier_void_reason_required';
    public const VOIDED = 'supplier_payment_voided';
    /** Already void. Nothing written, and no second financial effect. */
    public const ALREADY_VOID = 'supplier_payment_already_void';
    public const NOT_FOUND = 'supplier_payment_not_found';
    /** A voided record is not reviewed; it is replaced by a correct one. */
    public const IS_VOID = 'supplier_payment_is_void';
    /** Reviewed already — the figures of the first review stand. */
    public const ALREADY_RECONCILED = 'supplier_payment_already_reconciled';
    /** There is nothing waiting on a person about this payment. */
    public const NOTHING_TO_RECONCILE = 'supplier_payment_nothing_to_reconcile';
    /** Reviewed, and at least one share is STILL not on record. */
    public const STILL_UNKNOWN = 'supplier_payment_still_unknown';
    public const RECONCILED = 'supplier_payment_reconciled';

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
        private readonly ?TransactionInterface $tx = null,
        /**
         * The agreed amounts, and the allocations against them.
         *
         * Optional, and the reason is the `alpha.32` trap rather than a
         * preference: the evidence probes of earlier rounds build this
         * service by reflection and a required argument would stop them
         * loading. A build without it behaves exactly as `alpha.61` did —
         * every payment is weighed against the computed share — which is a
         * documented older behaviour and not a wrong new one. The real
         * container always provides it.
         */
        private readonly ?SupplierObligationRepositoryInterface $obligations = null
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
        // THE REGISTRY'S EXPONENT IS THE SUPPLIER'S FIRST-RECORDED ONE, and
        // the shares this payment is weighed against may be at the finer one
        // an exact commission needs. The comparison has to happen in the
        // finest unit of the two, so the payment is read at THAT — the
        // manager's text is accepted at the finer scale, which can only make
        // more of what they typed exact, never less.
        $registryExponent = (int) ($unit['exponent'] ?? 0);
        $exponent = max($registryExponent, $this->units->finestExponentOf($supplierUserId) ?? $registryExponent);

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

        // THE AGREED AMOUNT, WHICH IS NOT THE COMPUTED SHARE.
        //
        // «فرض نکن قیمت خرید نقدی با سهم محاسبه‌شده برابر است» — so when an
        // obligation has been recorded, IT is what the payment is measured
        // against, and the share comparison below is not consulted at all.
        // When none has been recorded the behaviour is unchanged from
        // `alpha.61`, which matters: there are no obligation rows on any
        // existing site, and a build that suddenly measured every payment
        // against a number nobody had entered would stop recording payments
        // that are being recorded today.
        $obligation = null;
        /** @var array<int,\Tecteb\Marketplace\Modules\Finance\Domain\SupplierObligation> $obligations */
        $obligations = [];
        $agreedElsewhere = 0;
        if ($this->obligations !== null) {
            foreach ($ids as $itemId) {
                $row = $this->obligations->forOrderItem($itemId);
                if ($row === null) {
                    continue;
                }
                $obligations[$itemId] = $row;
                if ($row->isAgreed()) {
                    $agreedElsewhere++;
                }
                if (count($ids) === 1) {
                    $obligation = $row;
                }
            }
            if ($agreedElsewhere > 0 && count($ids) > 1) {
                // No automatic split, about money. One payment per item.
                return OperationResult::failure(self::ALLOCATION_AMBIGUOUS, [
                    'items' => count($ids),
                    'agreed_items' => $agreedElsewhere,
                ]);
            }
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
            // A SECOND PAYMENT IS ORDINARY WHEN A BALANCE REMAINS.
            //
            // Until `alpha.61` one payment settled an item outright, so an
            // item with a live claim was simply refused. §3 changed what a
            // payment means, not what a claim means: the claim still says
            // «this item is being settled by hand rather than through the
            // vendor's own cycle», and a further payment against a balance
            // the manager recorded is the thing the owner asked for.
            //
            // Narrow on purpose. A balance has to EXIST — an agreed amount,
            // with something still owed on it — for this to open at all;
            // without one the old refusal is unchanged, which is what every
            // existing site gets.
            $obligationHere = $obligations[$itemId] ?? null;
            $hasBalance = $obligationHere !== null
                && $obligationHere->isAgreed()
                && ($obligationHere->balanceMinor() ?? 0) > 0;
            // AND WHEN THE OBLIGATION IS COVERED, SAY THAT RATHER THAN
            // «ALREADY PAID». Both are refusals and the difference is the
            // sentence a person reads: «این قلم قبلاً پرداخت شده» sends them
            // looking for one payment, and «مبلغ توافق‌شده کامل پرداخت شده»
            // tells them the agreed amount is covered — which it might be by
            // three payments. Measured on the disposable site in the
            // two-process race, where the first answer was the vaguer one.
            if ($obligationHere !== null && $obligationHere->isAgreed() && !$hasBalance) {
                return OperationResult::failure(self::OBLIGATION_SETTLED, [
                    'order_item_id' => $itemId,
                    'agreed_minor' => $obligationHere->agreed?->minor ?? 0,
                    'paid_minor' => $obligationHere->paidMinor,
                ]);
            }
            if ($already !== null && !$hasBalance) {
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
            // IN THE PAYMENT'S UNIT. A line records its own exponent from
            // `alpha.67`; one that does not is at the supplier's
            // first-recorded unit, which is what it was written in. Rescaled
            // by a power of ten — exact — and a line FINER than the
            // comparison unit is counted as unknown rather than rounded down
            // to fit, because «اختلاف … خودکار … گرد … اعلام نشود».
            $from = $item->exponent ?? $registryExponent;
            if ($from > $exponent) {
                $unknown[] = $itemId;
                $shares[$itemId] = null;
                continue;
            }
            $inUnit = (int) $item->vendorShareMinor * (10 ** ($exponent - $from));
            $shares[$itemId] = $inUnit;
            $shareMinor += $inUnit;
        }

        $paid = Money::of($minor, $currency, $exponent);
        // The share carried on the row is the sum of the KNOWN items, which is
        // a true statement about those items and not a total; `posting` is
        // what says whether it may be read as one.
        $share = Money::of($shareMinor, $currency, $exponent);

        $balanceBefore = null;
        if ($obligation !== null && $obligation->isAgreed()) {
            $balanceBefore = (int) $obligation->balanceMinor();
            if ($obligation->currency !== $currency || $obligation->exponent !== $exponent) {
                // Two units for one obligation is the `alpha.40` refusal in a
                // new place: never averaged, never converted.
                return OperationResult::failure(self::UNIT_UNKNOWN, [
                    'supplier_id' => $supplierUserId,
                    'obligation_currency' => $obligation->currency,
                ]);
            }
            if ($balanceBefore <= 0) {
                return OperationResult::failure(self::OBLIGATION_SETTLED, [
                    'order_item_id' => $obligation->orderItemId,
                    'agreed_minor' => $obligation->agreed?->minor ?? 0,
                    'paid_minor' => $obligation->paidMinor,
                ]);
            }
            if ($minor > $balanceBefore) {
                // «پرداخت بیش از مانده نباید خودکار بدهکاری یا پیش‌پرداخت
                // شود؛ تا تعیین‌نشدن، با پیام مشخص رد شود.»
                return OperationResult::failure(self::ABOVE_BALANCE, [
                    'order_item_id' => $obligation->orderItemId,
                    'amount_minor' => $minor,
                    'balance_minor' => $balanceBefore,
                    'agreed_minor' => $obligation->agreed?->minor ?? 0,
                    'paid_minor' => $obligation->paidMinor,
                ]);
            }
        }

        // TWO QUESTIONS, NOT ONE — and conflating them would undo `alpha.61`.
        //
        //   «is the obligation covered?»  → the agreed amount decides
        //   «may a payout line be written?» → the computed share decides
        //
        // The second is the open question the owner has NOT answered
        // («معنای اختلاف مبلغ و سهم»), and it is still open with an agreed
        // amount in the picture: a payout line asserts «this settles what
        // they had earned», and a purchase price that equals the agreed price
        // but differs from the earned share does not say that. So covering
        // the obligation in full ends the PARTIAL state and nothing more; the
        // money line still waits on the share agreeing, exactly as before.
        $covered = $balanceBefore !== null && $minor === $balanceBefore;
        $postable = $unknown === [] && $paid->minor === $share->minor;
        $posting = match (true) {
            $balanceBefore !== null && !$covered => V::POSTING_PARTIAL,
            $covered && $postable => T::POSTING_POSTED,
            $covered && $unknown !== [] => U::POSTING_SHARE_UNKNOWN,
            $covered => T::POSTING_NEEDS_DEFINITION,
            // An obligation with NO amount: the manager said there is an
            // agreed price and has not written it. «نامعلوم» again, about a
            // different number, and kept apart from the one below.
            $obligation !== null => V::POSTING_AGREED_UNKNOWN,
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
        // The one item whose CLAIM row may already exist, because an earlier
        // payment of this supplier's made it. Named rather than inferred: the
        // repository keeps the old refusal for every item the caller did not
        // name, so the unique key still protects everything it protected
        // before (see the parameter's docblock).
        $partialItems = $obligation !== null && $obligation->paidMinor > 0
            ? [$obligation->orderItemId]
            : [];
        $written = $this->payments->record($record, $shares, $partialItems);
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

        // THE MONEY AGAINST THE OBLIGATION, in the same unit of work.
        //
        // The guard is inside `allocate()`'s own statement — the obligation
        // row is locked and `paid_so_far + amount <= agreed` is evaluated by
        // the database — so this call is not a second check of what was
        // checked above: it is the check. The one above exists to give the
        // manager a sentence with numbers in it; this one is what two
        // concurrent records both have to pass.
        $balanceAfter = null;
        if ($obligation !== null && $obligation->isAgreed() && $this->obligations !== null) {
            $allocated = $this->obligations->allocate(
                $paymentId,
                $obligation->id,
                $obligation->orderItemId,
                $supplierUserId,
                $minor,
                $currency,
                $exponent
            );
            if ($allocated['outcome'] !== SupplierObligationRepositoryInterface::ALLOCATED) {
                $this->tx->rollback();
                return $allocated['outcome'] === SupplierObligationRepositoryInterface::ABOVE_BALANCE
                    ? OperationResult::failure(self::ABOVE_BALANCE, [
                        'order_item_id' => $obligation->orderItemId,
                        'amount_minor' => $minor,
                        'balance_minor' => (int) ($allocated['balance_minor'] ?? 0),
                        'agreed_minor' => $obligation->agreed?->minor ?? 0,
                        'paid_minor' => $obligation->paidMinor,
                    ])
                    : OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
            }
            $balanceAfter = (int) ($allocated['balance_minor'] ?? 0);
            if ($balanceAfter !== 0 && $covered) {
                // The balance moved between the read and the write — another
                // record of a partial payment landed first, so this one no
                // longer covers the obligation and must not be recorded as
                // though it did.
                $this->tx->rollback();
                return OperationResult::failure(self::ABOVE_BALANCE, [
                    'order_item_id' => $obligation->orderItemId,
                    'amount_minor' => $minor,
                    'balance_minor' => $balanceAfter,
                ]);
            }
        }

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
            'obligation_id' => $obligation?->id ?? 0,
            'balance_minor' => $balanceAfter,
        ]);

        return OperationResult::success(
            match ($posting) {
                T::POSTING_POSTED => self::RECORDED,
                U::POSTING_SHARE_UNKNOWN => self::RECORDED_SHARE_UNKNOWN,
                V::POSTING_PARTIAL => self::RECORDED_PARTIAL,
                V::POSTING_AGREED_UNKNOWN => self::RECORDED_AGREED_UNKNOWN,
                default => self::RECORDED_UNPOSTED,
            },
            [
                'payment_id' => $paymentId,
                'amount_minor' => $paid->minor,
                'share_minor' => $share->minor,
                'ledger_event' => $event,
                'obligation_id' => $obligation?->id ?? 0,
                'agreed_minor' => $obligation?->agreed?->minor,
                'balance_minor' => $balanceAfter,
                // The items whose share was not on record, BY NUMBER, because
                // «۱۲ قلم، یکی‌شان نامعلوم» is not something a person can act
                // on. Stored too, as `NULL` on each item's own row.
                'unknown_share_items' => $unknown,
            ]
        );
    }

    /**
     * VOIDING A RECORD THAT WAS WRONG — and nothing else.
     *
     * The owner's §5, point by point, and each point is a line of code below
     * rather than a sentence in a document:
     *
     *  - **a reason, the actor, the time and a reference to the original.**
     *    The reason is mandatory and the other three are the row's own; the
     *    reference is the payment itself, because a void is a state OF that
     *    document rather than a second document pointing at it.
     *  - **history is never deleted.** Nothing is removed — not the payment,
     *    not its claim rows, not its allocations. What changes is
     *    `voided_at`, and every reader that decides something asks it.
     *  - **a compensating entry, in the same unit of work.** When the payment
     *    had a money line, a label change would leave the ledger saying money
     *    left the marketplace. So the reverse entry is written here, inside
     *    the transaction, and a failure to write it takes the void with it.
     *  - **and «had a money line» is asked UNDER THE LOCK.** Until
     *    `alpha.65` that question was answered from a read taken before
     *    `begin()`, and a `reconcile()` request committing in the gap gave
     *    the payment its money line after the answer was already «no». The
     *    void then succeeded with `compensating_event=__none__`. The row is
     *    now re-read with `findForUpdate()` as the first statement of the
     *    transaction and every figure below comes from that read: «اطلاعات
     *    پیش از قفل مبنای تصمیم مالی نیست».
     *  - **a void is not a refund.** Nothing here moves money and there is no
     *    method that could: «ابطال سند به‌معنای بازگشت واقعی پول نیست» و
     *    «بازپرداخت واقعی تأمین‌کننده از اصلاح ثبت اشتباه جدا است» — the
     *    second is `ManageSupplierReceivables`, which is a different class
     *    with a different table.
     *  - **a repeated request makes no second financial effect.** The guard
     *    is `voided_at IS NULL` inside the `UPDATE`, so the second request
     *    changes no row and is told so; and the ledger's own event key would
     *    refuse the entry as well. Two independent refusals, deliberately.
     *  - **items are freed only on full success.** The claim rows lose their
     *    live key in the same transaction, so a rollback leaves the line held.
     */
    public function void(int $paymentId, string $reason): OperationResult
    {
        if (!$this->capabilities->can(self::CAPABILITY)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        if (trim($reason) === '') {
            return OperationResult::failure(self::VOID_REASON_REQUIRED, ['payment_id' => $paymentId]);
        }
        $payment = $this->payments->find($paymentId);
        if ($payment === null) {
            return OperationResult::failure(self::NOT_FOUND, ['payment_id' => $paymentId]);
        }
        if ($payment->isVoid()) {
            // Idempotent, and said as a fact rather than as an error: the
            // first void stands with its own reason, actor and time.
            return OperationResult::failure(self::ALREADY_VOID, [
                'payment_id' => $paymentId,
                'voided_at' => (string) $payment->voidedAt,
                'void_reason' => $payment->voidReason,
            ]);
        }
        if ($this->tx === null) {
            return OperationResult::failure(self::NOT_ATOMIC, ['payment_id' => $paymentId]);
        }
        if (!$this->tx->begin()) {
            return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
        }

        // ⛔ THE ROW IS READ AGAIN HERE, UNDER A LOCK, and the read above is
        // kept only for the two cheap answers it can give before a
        // transaction is worth opening. The difference was measured on
        // `alpha.64`: a payment recorded with an unknown agreed amount
        // carries no money line, `reconcile()` is the documented path that
        // gives it one, and both are POST actions on the same admin screen.
        // With the decision taken from the unlocked snapshot, an independent
        // reconcile landing in this gap produced
        // `void_code=supplier_payment_voided compensating_event=__none__
        // reversal_entry_exists=no` — the document marked void, the items
        // released, and the supplier's books still saying the money left.
        //
        // So everything below that decides or records money — whether a
        // reversal is owed, what it reverses, the amount, and the item count
        // in the answer — comes from `$locked` and never from `$payment`.
        $held = $this->payments->findForUpdate($paymentId);
        $payment = $held['payment'];
        if ($held['outcome'] !== SupplierPaymentRepositoryInterface::LOCKED || $payment === null) {
            $this->tx->rollback();
            return OperationResult::failure(
                $held['outcome'] === SupplierPaymentRepositoryInterface::NOT_FOUND
                    ? self::NOT_FOUND
                    : self::STORAGE_FAILED,
                ['payment_id' => $paymentId]
            );
        }
        if ($payment->isVoid()) {
            // The other request won. Said as the same fact as above rather
            // than as a storage failure, because that is what happened.
            $this->tx->rollback();
            return OperationResult::failure(self::ALREADY_VOID, [
                'payment_id' => $paymentId,
                'voided_at' => (string) $payment->voidedAt,
                'void_reason' => $payment->voidReason,
            ]);
        }

        $compensating = $payment->ledgerEvent !== '' ? self::voidEventKey($paymentId) : '';
        $voided = $this->payments->void(
            $paymentId,
            $reason,
            (int) $this->capabilities->currentUserId(),
            $compensating,
            $this->clock->now()->format('Y-m-d H:i:s')
        );
        if ($voided['outcome'] !== SupplierPaymentRepositoryInterface::VOIDED) {
            $this->tx->rollback();
            return match ($voided['outcome']) {
                SupplierPaymentRepositoryInterface::ALREADY_VOID => OperationResult::failure(
                    self::ALREADY_VOID,
                    ['payment_id' => $paymentId]
                ),
                SupplierPaymentRepositoryInterface::NOT_FOUND => OperationResult::failure(
                    self::NOT_FOUND,
                    ['payment_id' => $paymentId]
                ),
                SupplierPaymentRepositoryInterface::REASON_REQUIRED => OperationResult::failure(
                    self::VOID_REASON_REQUIRED,
                    ['payment_id' => $paymentId]
                ),
                default => OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]),
            };
        }
        if ($compensating !== '' && !$this->postReversal($payment, $compensating)) {
            // «اصلاح آن با سند معکوس در همان واحد کار انجام شود» — so a
            // reverse entry that cannot be written takes the void with it,
            // rather than leaving a document labelled void beside a ledger
            // that still says the money left.
            $this->tx->rollback();
            return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
        }
        if (!$this->tx->commit()) {
            $this->tx->rollback();
            return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
        }

        $this->audit->log(AuditEventCatalog::SUPPLIER_PAYMENT_VOIDED, 0, 'supplier_payment', (string) $paymentId, [
            'supplier_id' => $payment->supplierUserId,
            'payment_id' => $paymentId,
            'amount_minor' => $payment->amount->minor,
            'has_reason' => 1,
            'compensated' => $compensating !== '' ? 1 : 0,
        ]);
        return OperationResult::success(self::VOIDED, [
            'payment_id' => $paymentId,
            'amount_minor' => $payment->amount->minor,
            'compensating_event' => $compensating,
            'items_released' => count($payment->orderItemIds),
        ]);
    }

    /**
     * REVIEWING THE SAME PAYMENT ONCE ITS FIGURES ARE KNOWABLE — §6.
     *
     * «پس از تکمیل اطلاعات، مسیر بررسی و تطبیق همان پرداخت وجود داشته باشد؛
     * پرداخت تازه ساخته نشود و snapshot اولیهٔ پرداخت پاک نشود.» All three
     * are structural here rather than remembered:
     *
     *  - the same payment, because this method takes an id and has no way to
     *    create one — there is no call to `record()` anywhere in it;
     *  - no second payment, for the same reason;
     *  - the initial snapshot intact, because `share_minor` is not in the
     *    `SET` list of `markReconciled()`. What the review established goes
     *    into `share_minor_final` beside it.
     *
     * Idempotent on `reconciled_at IS NULL`, and auditable through its own
     * event. An item whose share is STILL not recorded is a named answer and
     * not a silent no-op: «نامعلوم با صفرِ معتبر یکی نیست» — a share of zero
     * that was actually recorded reconciles, and a missing one does not.
     *
     * The state it decides from is read under a lock inside its own
     * transaction, for the same reason `void()` does: these two are the two
     * buttons of one screen and either can land in the other's gap. The
     * checks before `begin()` are kept because they let a plainly impossible
     * request be answered without opening a transaction, and they are
     * repeated afterwards because only the second reading is binding.
     */
    public function reconcile(int $paymentId): OperationResult
    {
        if (!$this->capabilities->can(self::CAPABILITY)) {
            return OperationResult::failure(self::FORBIDDEN);
        }
        $payment = $this->payments->find($paymentId);
        if ($payment === null) {
            return OperationResult::failure(self::NOT_FOUND, ['payment_id' => $paymentId]);
        }
        if ($payment->isVoid()) {
            return OperationResult::failure(self::IS_VOID, ['payment_id' => $paymentId]);
        }
        if ($payment->isReconciled()) {
            return OperationResult::failure(self::ALREADY_RECONCILED, [
                'payment_id' => $paymentId,
                'reconciled_at' => (string) $payment->reconciledAt,
                'share_minor_final' => $payment->shareMinorFinal,
            ]);
        }
        if (!in_array($payment->posting, [
            U::POSTING_SHARE_UNKNOWN,
            V::POSTING_AGREED_UNKNOWN,
            T::POSTING_NEEDS_DEFINITION,
        ], true)) {
            return OperationResult::failure(self::NOTHING_TO_RECONCILE, [
                'payment_id' => $paymentId,
                'posting' => $payment->posting,
            ]);
        }
        if ($this->tx === null) {
            return OperationResult::failure(self::NOT_ATOMIC, ['payment_id' => $paymentId]);
        }

        if (!$this->tx->begin()) {
            return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
        }

        // ⛔ THE SAME CORRECTION AS IN `void()`, FROM THE OTHER SIDE. The
        // checks above are cheap answers given before a transaction is worth
        // opening; they are not the basis of the write. Whether a money line
        // may be posted depends on this payment having none and not being
        // void, and a void committed in that gap would otherwise be found
        // only by `markReconciled()`'s own `voided_at IS NULL` — which does
        // refuse, but as `storage_failed` and after a ledger entry had
        // already been written and had to be rolled back. Under the lock the
        // answer is named and nothing is written at all.
        $held = $this->payments->findForUpdate($paymentId);
        $payment = $held['payment'];
        if ($held['outcome'] !== SupplierPaymentRepositoryInterface::LOCKED || $payment === null) {
            $this->tx->rollback();
            return OperationResult::failure(
                $held['outcome'] === SupplierPaymentRepositoryInterface::NOT_FOUND
                    ? self::NOT_FOUND
                    : self::STORAGE_FAILED,
                ['payment_id' => $paymentId]
            );
        }
        if ($payment->isVoid()) {
            $this->tx->rollback();
            return OperationResult::failure(self::IS_VOID, ['payment_id' => $paymentId]);
        }
        if ($payment->isReconciled()) {
            $this->tx->rollback();
            return OperationResult::failure(self::ALREADY_RECONCILED, [
                'payment_id' => $paymentId,
                'reconciled_at' => (string) $payment->reconciledAt,
                'share_minor_final' => $payment->shareMinorFinal,
            ]);
        }
        if (!in_array($payment->posting, [
            U::POSTING_SHARE_UNKNOWN,
            V::POSTING_AGREED_UNKNOWN,
            T::POSTING_NEEDS_DEFINITION,
        ], true)) {
            $this->tx->rollback();
            return OperationResult::failure(self::NOTHING_TO_RECONCILE, [
                'payment_id' => $paymentId,
                'posting' => $payment->posting,
            ]);
        }

        // THE SHARES AS THEY ARE NOW, read item by item and never summed over
        // an absence. `isRecorded()` is the bar — a share AND the ledger event
        // it was written under — which is the same bar `record()` used, so the
        // two cannot disagree about what «known» means. Read inside the unit
        // of work, so the figure written down is the figure that was compared.
        $shareMinor = 0;
        $stillUnknown = [];
        foreach ($payment->orderItemIds as $itemId) {
            $item = $this->items->find((int) $itemId);
            if ($item === null || !$item->isRecorded()) {
                $stillUnknown[] = (int) $itemId;
                continue;
            }
            $shareMinor += (int) $item->vendorShareMinor;
        }
        if ($stillUnknown !== []) {
            $this->tx->rollback();
            return OperationResult::failure(self::STILL_UNKNOWN, [
                'payment_id' => $paymentId,
                'unknown_share_items' => $stillUnknown,
            ]);
        }

        $postable = $shareMinor === $payment->amount->minor;
        $posting = $postable ? T::POSTING_POSTED : T::POSTING_NEEDS_DEFINITION;
        $event = '';
        if ($postable && $payment->ledgerEvent === '') {
            $event = self::eventKey($paymentId);
            if (!$this->postPayout($payment->supplierUserId, $event, $paymentId, $payment->amount)) {
                $this->tx->rollback();
                return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
            }
        }
        if (!$this->payments->markReconciled(
            $paymentId,
            $shareMinor,
            $posting,
            $event,
            (int) $this->capabilities->currentUserId(),
            $this->clock->now()->format('Y-m-d H:i:s')
        )) {
            // A row that says «posted» with no entry behind it, or an entry
            // with no row, is the pair FIN-03 exists to prevent.
            $this->tx->rollback();
            return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
        }
        if (!$this->tx->commit()) {
            $this->tx->rollback();
            return OperationResult::failure(self::STORAGE_FAILED, ['payment_id' => $paymentId]);
        }

        $this->audit->log(
            AuditEventCatalog::SUPPLIER_PAYMENT_RECONCILED,
            0,
            'supplier_payment',
            (string) $paymentId,
            [
                'supplier_id' => $payment->supplierUserId,
                'payment_id' => $paymentId,
                'share_minor' => $payment->share->minor,
                'share_minor_final' => $shareMinor,
                'posting' => $posting,
            ]
        );
        return OperationResult::success(self::RECONCILED, [
            'payment_id' => $paymentId,
            // BOTH figures, because the whole point is that the first one is
            // still there to be compared with.
            'share_minor' => $payment->share->minor,
            'share_minor_final' => $shareMinor,
            'posting' => $posting,
            'ledger_event' => $event,
        ]);
    }

    /** One reserved event key per void, so a retry writes nothing twice. */
    public static function voidEventKey(int $paymentId): string
    {
        return 'supplier-payment-void:' . $paymentId;
    }

    /**
     * The exact reverse of `postPayout()`, under its own event key.
     *
     * A reversal and not a deletion: `tmc_ledger` is append-only by design
     * (FIN-03), so «اصلاح» means a second, opposite entry. The accounts are
     * the same two and the signs are swapped, so the pair sums to nought and
     * the supplier's balance reads as though the payment had never been
     * recorded — which is what a void of a MIS-RECORD means, and is why it
     * says nothing about where the money physically is.
     */
    private function postReversal(SupplierPayment $payment, string $event): bool
    {
        $amount = $payment->amount;
        $transaction = (new LedgerTransaction(
            $event,
            $payment->supplierUserId,
            'supplier-payment-void',
            (string) $payment->id
        ))
            ->snapshot([
                'kind' => 'supplier_payment_void',
                'payment_id' => $payment->id,
                'reverses' => $payment->ledgerEvent,
                'amount_minor' => $amount->minor,
                'currency' => $amount->currency,
                'exponent' => $amount->exponent,
            ])
            ->add(LedgerAccount::VendorEarning, $amount->negate(), 'supplier_payment_voided')
            ->add(LedgerAccount::CentralPayment, $amount, 'supplier_payment_voided');

        return $transaction->balances() && $this->ledger->record($transaction);
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
