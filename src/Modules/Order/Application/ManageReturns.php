<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnRequest;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Returns and refunds: the goods, the stock and the money, each recorded once
 * and none of them guessed.
 *
 * Four things this class is careful about, in the order they bite:
 *
 * 1. **Quantity.** A line of three can have at most three units returned, ever,
 *    counting requests that are still open. The check is against a SUM over
 *    the return rows, so two tabs cannot each pass it.
 * 2. **One refund per return.** `received → refunded` is the only transition
 *    that moves money, it leads nowhere, and the write that performs it is an
 *    UPDATE guarded by `reversal_event_key IS NULL` behind a unique index. A
 *    second attempt affects zero rows and is told so.
 * 3. **The ledger is not edited.** A refund adds REVERSING lines with their
 *    own event key; the original four lines stay exactly as they were. That is
 *    what «ثبت معکوس مالی بدون حذف سابقه» means, and it is also the only shape
 *    an append-only ledger allows.
 * 4. **The commercial terms are not invented.** No window, no fee, no shipping
 *    refund — see ReturnTerms. What is reversed is arithmetic from the line's
 *    own snapshot, and the last return on a line closes it to the cent rather
 *    than to a rounded share.
 * 5. **«بازپرداخت» is four things and this does two of them.** The ledger and
 *    the stock are performed here; the WooCommerce refund record and the
 *    actual transfer of money to the customer are not, and cannot be — there
 *    is no gateway adapter in this build. RefundScope names all four, and
 *    every screen quotes it instead of the bare word.
 *
 * Who may do what: a vendor (or their staff with order-edit) may OPEN a return
 * on their own line and receive the goods. Only a manager decides it, and only
 * a manager refunds — money leaving the marketplace is not a shop's call.
 */
final class ManageReturns
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $items,
        private readonly ShipmentRepositoryInterface $returns,
        private readonly LedgerRepositoryInterface $ledger,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly ReturnStateMachine $states,
        private readonly ReturnTerms $terms,
        private readonly RefundScope $scope,
        private readonly ?RefundRecorderInterface $recorder,
        private readonly ProductRepositoryInterface $products,
        private readonly CatalogProjectorInterface $catalog,
        private readonly ?CapabilityCheckerInterface $capabilities = null
    ) {
    }

    /**
     * The accrual this reversal undoes: its line ids, and the unit its money
     * was RECORDED in — from one read, and with every line required to agree.
     *
     * One source, and it is the ledger: `tmc_ledger` carries `currency` and
     * `exponent` on every row (migration 4), so the unit of an amount is
     * stored beside the amount rather than inferred.
     *
     * **Why every line, not the first.** `alpha.39` read the unit off
     * `forEvent()[0]` for the WooCommerce record and left `refund()` itself on
     * an `'IRR', 0` DEFAULT PARAMETER that `ReturnsPage` never passed. So the
     * reversal written into the LEDGER — the half that decides what a vendor
     * is owed — was in `IRR`/0 whatever the sale was in, and a first-line read
     * would not have noticed an event whose own lines disagreed either. Both
     * are closed here: `unit` is `null` when there is no event and `mixed` is
     * true when the event's own lines do not agree, and the caller refuses by
     * name before anything is written.
     *
     * @return array{lines:array<string,int>, unit:array{currency:string, exponent:int}|null, mixed:bool}
     */
    private function accrual(VendorOrderItem $item): array
    {
        $lines = [];
        $unit = null;
        $mixed = false;
        if ($item->ledgerEvent === '') {
            return ['lines' => $lines, 'unit' => null, 'mixed' => false];
        }
        foreach ($this->ledger->forEvent($item->ledgerEvent) as $entry) {
            $lines[$entry->account->value] = $entry->id;
            $here = ['currency' => $entry->amount->currency, 'exponent' => $entry->amount->exponent];
            if ($unit === null) {
                $unit = $here;
                continue;
            }
            if ($unit !== $here) {
                $mixed = true;
            }
        }
        return ['lines' => $lines, 'unit' => $unit, 'mixed' => $mixed];
    }

    /**
     * The refusal an unusable accrual unit earns, or null when it is usable.
     *
     * Shared by both halves of a refund — the ledger reversal and the
     * WooCommerce record — so the two cannot disagree about whether this
     * line's unit is known. «دادهٔ قدیمی با حدس دوباره تفسیر نشود»: neither
     * branch converts, defaults or falls back; they refuse and name the line.
     *
     * One context shape for both halves, so the two refusals a manager can
     * meet do not name the same facts with different keys.
     *
     * @param array{lines:array<string,int>, unit:array{currency:string, exponent:int}|null, mixed:bool} $accrual
     * @param array<string,mixed> $extra what the caller knows and this does not
     */
    private function unitRefusal(array $accrual, VendorOrderItem $item, array $extra = []): ?OperationResult
    {
        if ($accrual['unit'] !== null && !$accrual['mixed']) {
            return null;
        }
        return OperationResult::failure(
            $accrual['mixed'] ? 'refund_unit_inconsistent' : 'refund_unit_unknown',
            array_merge([
                'item_id' => $item->id,
                'order_item_id' => $item->id,
                'event_key' => $item->ledgerEvent,
                'ledger_event' => $item->ledgerEvent,
            ], $extra)
        );
    }

    /** What is still returnable on this line: quantity − already claimed. */
    public function returnableQuantity(int $itemId, int $lineQuantity): int
    {
        return max(0, $lineQuantity - $this->returns->returnedQuantity($itemId));
    }

    /**
     * Opens a return request. Decides nothing.
     *
     * Deliberately never auto-approved and never auto-refused for being late:
     * the return window is DEC-03 and undecided, so a manager looks at it.
     */
    public function open(
        int $actorId,
        int $vendorUserId,
        int $itemId,
        int $quantity,
        string $reason = '',
        string $note = ''
    ): OperationResult {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $item = $this->items->find($itemId);
        if ($item === null || !$item->belongsTo($vendorUserId)) {
            return OperationResult::failure('not_found');
        }
        if ($quantity < 1) {
            return OperationResult::failure('quantity_required');
        }
        $returnable = $this->returnableQuantity($item->id, $item->quantity);
        if ($quantity > $returnable) {
            return OperationResult::failure('quantity_exceeds_returnable', [
                'requested' => $quantity,
                'returnable' => $returnable,
            ]);
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $returnId = $this->returns->openReturn(new ReturnRequest(
            0,
            $item->id,
            $vendorUserId,
            $quantity,
            ReturnStatus::Requested,
            trim($reason),
            trim($note),
            null,
            null,
            null,
            null,
            0,
            null,
            null,
            $actorId,
            $now
        ));
        if ($returnId === 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::ORDER_RETURN_OPENED, $actorId, 'order_return', (string) $returnId, [
            'vendor_id' => $vendorUserId,
            'order_id' => $item->orderId,
            'item_id' => $item->id,
            'return_id' => $returnId,
            'quantity' => $quantity,
            'has_reason' => trim($reason) !== '',
        ]);
        return OperationResult::success('return_opened', [
            'return_id' => $returnId,
            'quantity' => $quantity,
            'open_terms' => implode('، ', $this->terms->openTerms()),
        ]);
    }

    /**
     * A manager's decision, or a vendor receiving the goods back.
     *
     * `refunded` is NOT reachable here: money goes through refund(), which has
     * the ledger and the idempotency with it.
     */
    public function decide(int $actorId, int $returnId, ReturnStatus $to, string $note = '', bool $restock = false): OperationResult
    {
        $request = $this->returns->findReturn($returnId);
        if ($request === null) {
            return OperationResult::failure('not_found');
        }
        if ($to === ReturnStatus::Refunded) {
            return OperationResult::failure('use_refund');
        }
        if (!$this->states->canMove($request->status, $to)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $request->status->value,
                'to' => $to->value,
            ]);
        }
        if (!$this->mayDecide($actorId, $request, $to)) {
            return OperationResult::failure('forbidden');
        }
        // ⛔ ACCEPTING THE GOODS IS THE LAST REVERSIBLE STEP, so a split that
        // cannot be exact is refused HERE as well as at the money.
        //
        // `received → refunded` is the only move out of `received`
        // (`ReturnStateMachine`), so a return accepted on a line whose share
        // cannot be given back exactly would be stuck: not refundable, not
        // cancellable, and holding a unit of the line so the WHOLE-line
        // return could not be opened either. `approved → cancelled` exists,
        // which is why the guard belongs on this transition: the manager can
        // cancel this request and open one for the whole remaining line,
        // which IS exact. A guard that creates a dead end is worse than the
        // defect it prevents (`alpha.60`).
        if ($to === ReturnStatus::Received) {
            $exactness = $this->splitExactness($returnId);
            if (!$exactness['exact']) {
                return OperationResult::failure('return_share_not_exact', [
                    'return_id' => $returnId,
                    'item_id' => $request->orderItemId,
                    'quantity' => $exactness['quantity'],
                    'line_quantity' => $exactness['line_quantity'],
                    'base_minor' => $exactness['base_minor'],
                    'commission_minor' => $exactness['commission_minor'],
                    'exponent' => $exactness['exponent'],
                    'parts' => implode(',', $exactness['parts']),
                ]);
            }
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $decidedAt = $to === ReturnStatus::Received ? $request->decidedAt : $now;
        $receivedAt = $to === ReturnStatus::Received ? $now : null;
        if (!$this->returns->updateReturnStatus($returnId, $to, $actorId, trim($note), $decidedAt, $receivedAt)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::ORDER_RETURN_DECIDED, $actorId, 'order_return', (string) $returnId, [
            'vendor_id' => $request->vendorUserId,
            'return_id' => $returnId,
            'item_id' => $request->orderItemId,
            'from' => $request->status->value,
            'to' => $to->value,
        ]);

        $context = ['return_id' => $returnId];
        if ($to === ReturnStatus::Received && $restock) {
            $context['restocked'] = $this->restock($actorId, $request);
        }
        return OperationResult::success('return_' . $to->value, $context);
    }

    /**
     * The money, once.
     *
     * Reverses this return's share of the four lines the sale wrote. The last
     * return on a line reverses whatever is LEFT rather than its own rounded
     * share, so a line of three refunded one-by-one adds up to exactly what it
     * accrued — no cent lost, none invented.
     *
     * @param int|null $wcRefundId the WooCommerce refund a manager already
     *                             made, when there is one. The unique index on
     *                             this column means one WooCommerce refund
     *                             cannot be recorded against two returns.
     */
    /**
     * Reverses the accrual in the ledger — in the unit the accrual was
     * recorded in, which this method READS rather than takes.
     *
     * **What it used to take.** `alpha.39` gave this method
     * `string $currency = 'IRR', int $exponent = 0`, and `ReturnsPage` — the
     * only caller in the plugin — passed neither. So every reversal line this
     * marketplace has ever written went into the books as `IRR`/0 regardless
     * of what the sale was in, and a caller COULD have passed something else
     * and overwritten the historical unit from a form. Both halves of that are
     * gone: there is no parameter to pass, and the answer comes off the event
     * being reversed.
     *
     * «پیش از هر نوشتن، وجود و سازگاری واحد خطوط اصلی بررسی شود»: the read and
     * both checks happen BEFORE `recordReversal()`, so a line whose unit
     * cannot be established leaves the return exactly as it was rather than
     * stranded with a row and no ledger entry.
     */
    public function refund(
        int $actorId,
        int $returnId,
        ?int $wcRefundId = null,
        string $note = ''
    ): OperationResult {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_WITHDRAWALS)) {
            return OperationResult::failure('forbidden');
        }
        $request = $this->returns->findReturn($returnId);
        if ($request === null) {
            return OperationResult::failure('not_found');
        }
        if ($request->status === ReturnStatus::Refunded) {
            // Said plainly rather than as "invalid transition": the person
            // asking wants to know whether the customer got their money, and
            // the answer is yes, already.
            return OperationResult::failure('already_refunded', [
                'return_id' => $returnId,
                'refunded_at' => (string) $request->refundedAt,
            ]);
        }
        if (!$this->states->canMove($request->status, ReturnStatus::Refunded)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $request->status->value,
                'to' => ReturnStatus::Refunded->value,
            ]);
        }
        $item = $this->items->find($request->orderItemId);
        if ($item === null) {
            return OperationResult::failure('not_found');
        }
        if (!$item->isRecorded()) {
            // Nothing was ever accrued for this sale (FIN-02: an unresolvable
            // rate records nothing rather than zero), so there is nothing to
            // reverse and pretending otherwise would invent a number.
            return OperationResult::failure('nothing_recorded', ['item_id' => $item->id]);
        }

        // THE UNIT, BEFORE ANY WRITE. One read of the accrual gives both the
        // line ids this reversal will point at and the unit it must be
        // written in; a missing or self-inconsistent event is refused here,
        // with nothing written and the return left where it was.
        $accrual = $this->accrual($item);
        $refusal = $this->unitRefusal($accrual, $item, ['return_id' => $returnId]);
        if ($refusal !== null) {
            return $refusal;
        }
        /** @var array{currency:string, exponent:int} $unit */
        $unit = $accrual['unit'];

        $share = $this->shareOf($item, $request);
        if ($share === null) {
            // ⛔ THE SPLIT IS NOT EXACT, and nothing is rounded to make it
            // one. Every figure is named so a human can see the arithmetic:
            // this quantity of this line's total cannot be given back as a
            // whole number of the unit the sale was recorded in, and no
            // additional scale would change that (one third has no
            // terminating decimal). Nothing is written and the return stays
            // where it is; a FULL return of what is left is exact and still
            // works. `docs/open-decisions-alpha68.md` §1 puts the allocation
            // question to the owner rather than answering it here.
            $exactness = $this->splitExactness($returnId);
            return OperationResult::failure('return_share_not_exact', [
                'return_id' => $returnId,
                'item_id' => $item->id,
                'quantity' => $request->quantity,
                'line_quantity' => $item->quantity,
                'base_minor' => (int) $item->baseMinor,
                'commission_minor' => (int) ($item->commissionMinor ?? 0),
                'exponent' => $unit['exponent'],
                'currency' => $unit['currency'],
                'parts' => implode(',', $exactness['parts']),
            ]);
        }
        // WHAT IS ALREADY REVERSED HAS TO BE IN THE SAME UNIT, because
        // `shareOf()` SUBTRACTS it from this line's figures. One item's
        // reversals are all written in that item's own accrual unit, so this
        // can only disagree if something outside this path wrote one — and a
        // subtraction across two scales is wrong by a factor of ten
        // thousand, which is exactly the size of mistake this round removes.
        // Refused by name rather than converted: «نمی‌دانم» is an answer and
        // a guessed conversion is not (`alpha.53`).
        $reversedScale = $this->returns->reversedTotals($item->id);
        // «I could not find out what unit the earlier reversals are in» is
        // its OWN answer, and not a scale disagreement: the sentence a human
        // reads has to be the thing that happened (`alpha.65`). Nothing is
        // written either way, but the two are different problems — one is a
        // contradiction in the data, the other is a database that could not
        // be asked.
        if (($reversedScale['unit_unreadable'] ?? false) === true) {
            return OperationResult::failure('reversed_unit_unreadable', [
                'return_id' => $returnId,
                'item_id' => $item->id,
            ]);
        }
        if (
            ($reversedScale['one_scale'] ?? true) !== true
            || ($reversedScale['exponent'] !== null && (int) $reversedScale['exponent'] !== $unit['exponent'])
        ) {
            return OperationResult::failure('reversed_unit_inconsistent', [
                'return_id' => $returnId,
                'item_id' => $item->id,
                'accrual_exponent' => $unit['exponent'],
                'reversed_exponent' => $reversedScale['exponent'],
            ]);
        }
        $eventKey = 'return:' . $item->id . ':' . $returnId;
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // The row first: it is the thing with the unique index, so it decides
        // whether this refund is the first one. Only then is the ledger told.
        if (!$this->returns->recordReversal(
            $returnId,
            $eventKey,
            $share['refund'],
            $share['tax'],
            $share['commission'],
            $share['vendor_share'],
            $wcRefundId,
            $now,
            $actorId,
            $unit['currency'],
            $unit['exponent']
        )) {
            return OperationResult::failure('already_refunded', ['return_id' => $returnId]);
        }

        $account = $this->vendorSideAccount($item);
        // The same currency and exponent the sale was recorded in — read off
        // the accrual above, not taken from a caller and not defaulted. A
        // reversal in a different unit would balance arithmetically and mean
        // nothing.
        $money = fn (int $minor): Money => Money::of($minor, $unit['currency'], $unit['exponent']);
        // …and each line points at the accrual line it undoes, so the ledger
        // itself says «this reverses that» rather than leaving a reader to
        // match event keys by eye. The vendor-side line is the exception when
        // the share was already paid out: the debt it creates reverses
        // nothing that exists, it is a new obligation.
        $original = $accrual['lines'];
        $transaction = (new LedgerTransaction($eventKey, $item->vendorUserId, (string) $item->orderId, (string) $item->id))
            ->add(
                LedgerAccount::CentralPayment,
                $money($share['refund'] + $share['tax'])->negate(),
                'refund_paid',
                $original[LedgerAccount::CentralPayment->value] ?? null
            )
            ->add(
                LedgerAccount::Commission,
                $money($share['commission']),
                'commission_reversed',
                $original[LedgerAccount::Commission->value] ?? null
            )
            ->add(
                $account,
                $money($share['vendor_share']),
                'vendor_share_reversed',
                $original[$account->value] ?? null
            )
            ->add(
                LedgerAccount::TaxCollected,
                $money($share['tax']),
                'tax_reversed',
                $original[LedgerAccount::TaxCollected->value] ?? null
            );

        if (!$transaction->balances()) {
            return OperationResult::failure('unbalanced_reversal');
        }
        if (!$this->ledger->record($transaction)) {
            // The row said this was the first refund and the ledger says the
            // event is already there. The two stores disagree, and the return
            // is parked in a state that says so rather than left claiming a
            // reversal that is not in the books. Nothing is retried: FIN-05's
            // rule about an unknown outcome applies here for the same reason.
            $this->returns->updateReturnStatus(
                $returnId,
                ReturnStatus::ReconciliationRequired,
                $actorId,
                'ledger refused the reversing transaction',
                null,
                null
            );
            $this->audit->log(AuditEventCatalog::ORDER_RETURN_RECONCILE, $actorId, 'order_return', (string) $returnId, [
                'return_id' => $returnId,
                'item_id' => $item->id,
                'event_key' => $eventKey,
            ]);
            return OperationResult::failure('ledger_already_recorded', [
                'event_key' => $eventKey,
                'return_id' => $returnId,
            ]);
        }

        $this->audit->log(AuditEventCatalog::ORDER_RETURN_REFUNDED, $actorId, 'order_return', (string) $returnId, [
            'vendor_id' => $item->vendorUserId,
            'return_id' => $returnId,
            'item_id' => $item->id,
            'quantity' => $request->quantity,
            'refund_minor' => $share['refund'],
            'tax_minor' => $share['tax'],
            'commission_minor' => $share['commission'],
            'vendor_share_minor' => $share['vendor_share'],
            'account' => $account->value,
            'event_key' => $eventKey,
            // The scale those four figures are in. An audit line that records
            // an amount without its unit records a number, not an amount.
            'currency' => $unit['currency'],
            'exponent' => $unit['exponent'],
        ]);
        unset($note);
        $parts = $this->scope->parts(
            $request->restockedQuantity > 0,
            $wcRefundId !== null
        );
        return OperationResult::success('return_refunded', [
            'return_id' => $returnId,
            'refund_minor' => $share['refund'],
            'tax_minor' => $share['tax'],
            'currency' => $unit['currency'],
            'exponent' => $unit['exponent'],
            'vendor_account' => $account->value,
            'open_terms' => implode('، ', $this->terms->openTerms()),
            // What this actually did, part by part. The screens quote these
            // rather than the word «بازپرداخت», because two of the four are
            // still a person's job and one of them is the money itself.
            'did_ledger' => $parts[RefundScope::LEDGER_REVERSAL],
            'did_stock' => $parts[RefundScope::STOCK_CORRECTION],
            'did_wc_refund' => $parts[RefundScope::WC_REFUND_RECORD],
            'did_money' => $parts[RefundScope::MONEY_TRANSFER],
        ]);
    }

    /**
     * Creates the WooCommerce refund RECORD for a return already reversed in
     * the ledger — and does not move a rial.
     *
     * A separate, deliberate, manager-only step, because the two halves of
     * «بازپرداخت» depend on different things and doing them together would
     * make the record look like proof the money went. Measured out of
     * WooCommerce 11.0.1: `wc_create_refund()` takes `refund_payment => false`
     * by default and touches no gateway; the money would be
     * `wc_refund_payment()`, which needs a gateway supporting refunds and a
     * stored transaction id, neither of which this build has.
     *
     * Only after the ledger reversal, never before: the plugin's own books are
     * the thing it is responsible for, and a WooCommerce record without one
     * would be a refund this marketplace cannot account for.
     */
    public function recordWooCommerceRefund(int $actorId, int $returnId): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        if ($this->recorder === null || !$this->recorder->isAvailable()) {
            return OperationResult::failure('refund_woocommerce_missing');
        }
        $request = $this->returns->findReturn($returnId);
        if ($request === null) {
            return OperationResult::failure('not_found');
        }
        if ($request->status !== ReturnStatus::Refunded) {
            return OperationResult::failure('refund_ledger_first', [
                'status' => $request->status->value,
            ]);
        }
        if ($request->wcRefundId !== null) {
            return OperationResult::failure('refund_already_recorded', [
                'wc_refund_id' => $request->wcRefundId,
            ]);
        }
        $item = $this->items->find($request->orderItemId);
        if ($item === null) {
            return OperationResult::failure('not_found');
        }
        // The return says it has no WooCommerce refund. WooCommerce might
        // disagree — a previous attempt can have created one and died before
        // the id got here. That lookup is NOT repeated here: `record()` does it
        // as its first act and hands the orphan back, so there is exactly one
        // place that decides «made one» from «found mine». Asking twice would
        // be two answers to keep in step.
        //
        // THE UNIT, read rather than assumed — and this line was the single
        // worst defect in the financial surface until `alpha.39`.
        //
        // It used to be `(refundMinor + taxRefundMinor) / 100`. Nothing else
        // anywhere in this plugin scales money by a hundred: orders are
        // captured with `exponent = 0`, because ریال and تومان have no minor
        // unit, so a line stored as `100000` IS one hundred thousand. Dividing
        // it by a hundred asked WooCommerce to refund **۱٬۰۰۰**. Measured on
        // the shipped `alpha.38` bytes.
        //
        // The unit is not guessed from «the shop shows تومان» either. It comes
        // off the LEDGER event this return already reversed: `tmc_ledger`
        // stores `currency` and `exponent` per line and has since migration 4,
        // so the unit of a recorded amount is a fact on disk. A row whose unit
        // cannot be established is refused by name — «با واحد حدسی ثبت
        // نشود» — rather than converted on a hunch.
        $accrual = $this->accrual($item);
        $refusal = $this->unitRefusal($accrual, $item, ['return_id' => $returnId]);
        if ($refusal !== null) {
            // What a person has to look at is in the context: either the item
            // carries no ledger event (so no recorded unit exists for it) or
            // the event's own lines disagree about the unit. Recording a
            // refund for it needs the order re-captured, or the amount
            // entered in WooCommerce by hand.
            return $refusal;
        }
        /** @var array{currency:string, exponent:int} $unit */
        $unit = $accrual['unit'];
        // Nullable on the row and non-null once refunded; coalesced anyway,
        // because a null here would silently become a zero-amount refund that
        // WooCommerce refuses with an exception instead of a sentence.
        $minor = ((int) $request->refundMinor) + ((int) $request->taxRefundMinor);
        $amount = DecimalAmount::toDecimal($minor, $unit['exponent']);
        if ($amount === null) {
            return OperationResult::failure('refund_unit_unknown', [
                'return_id' => $returnId,
                'order_item_id' => $item->id,
                'ledger_event' => $item->ledgerEvent,
            ]);
        }
        $result = $this->recorder->record(
            $item->orderId,
            $item->orderItemId,
            $amount,
            $request->quantity,
            // What a person reads on the refund, and — separately — the key
            // that makes a retry idempotent.
            sprintf('tmc return #%d', $returnId),
            $returnId
        );
        if (!$result['ok']) {
            return OperationResult::failure($result['reason'], [
                'return_id' => $returnId,
                'remaining' => $result['remaining'] ?? null,
                // The refund ids a person has to look at, when an interrupted
                // attempt left something this build cannot identify. Carried
                // through rather than summarised: «چند مورد» is not something
                // anybody can act on.
                'candidates' => implode('، ', array_map('strval', $result['candidates'] ?? [])),
            ]);
        }
        if (!$this->returns->linkWcRefund($returnId, $result['refund_id'])) {
            // The WooCommerce record exists and we could not attach it. Said
            // out loud with the id, because the manager now has a refund in
            // WooCommerce that this return does not know about, and a silent
            // success would hide exactly that.
            return OperationResult::failure('refund_link_failed', [
                'return_id' => $returnId,
                'wc_refund_id' => $result['refund_id'],
            ]);
        }
        $this->audit->log(AuditEventCatalog::RETURN_WC_REFUND_RECORDED, $actorId, 'return', (string) $returnId, [
            'return_id' => $returnId,
            'wc_refund_id' => $result['refund_id'],
            'amount_minor' => $request->refundMinor + $request->taxRefundMinor,
            'money_moved' => false,
            // Whether this run made the refund or adopted one an interrupted
            // run had already made. Different facts, and the audit trail is
            // the one place that difference must not be flattened.
            'recovered' => $result['reason'] === 'refund_recovered',
        ]);
        return OperationResult::success(
            $result['reason'] === 'refund_recovered' ? 'refund_recovered' : 'refund_recorded',
            [
            'return_id' => $returnId,
            'wc_refund_id' => $result['refund_id'],
            'did_wc_refund' => true,
            // Said in the SAME result, so no screen can quote the line above
            // without this one.
            'did_money' => false,
            'money_blockers' => implode('، ', $this->recorder->moneyBlockers($item->orderId)),
            ]
        );
    }

    /** @return list<ReturnRequest> */
    public function forVendor(int $actorId, int $vendorUserId, ?ReturnStatus $status = null, int $limit = 50, int $offset = 0): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::View)) {
            return [];
        }
        return $this->returns->returnsForVendor($vendorUserId, $status, $limit, $offset);
    }

    /** @return list<ReturnRequest> every shop's, for the manager's queue */
    public function forManager(?ReturnStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_WITHDRAWALS)) {
            return [];
        }
        return $this->returns->allReturns($status, $limit, $offset);
    }

    /** @return list<ReturnRequest> */
    public function forItem(int $itemId): array
    {
        return $this->returns->returnsFor($itemId);
    }

    /**
     * Approving and rejecting are the manager's; receiving the goods is the
     * shop's, because the shop is where the parcel arrives.
     */
    private function mayDecide(int $actorId, ReturnRequest $request, ReturnStatus $to): bool
    {
        if ($to === ReturnStatus::Received || $to === ReturnStatus::Cancelled) {
            return $this->access->can($actorId, $request->vendorUserId, StaffArea::Order, StaffLevel::Edit)
                || ($this->capabilities?->can(Capabilities::REVIEW_WITHDRAWALS) ?? false);
        }
        return $this->capabilities?->can(Capabilities::REVIEW_WITHDRAWALS) ?? false;
    }

    /**
     * This return's share of the line's money — EXACT, or nothing.
     *
     * ⛔ **`intdiv()` is rounding, and the owner was right to call it that.**
     * Until `alpha.68` a partial return divided each of the line's three
     * figures by the line's quantity with `intdiv()`, which is flooring. On
     * the owner's own example — a line whose total is ۱۰۱ تومان for three
     * units at ۱۲٫۵٪ — returning one unit of three stored `336666` where the
     * exact third is ۳۳۶۶۶۶٫۶۶۶…, and `42083` where it is ۴۲۰۸۳٫۳۳۳…
     * (measured on `alpha.67`: `base_divides_exactly=no`,
     * `stored_refund_is_exact=no`). Three times `336666` is `1009998`, not
     * `1010000`: the per-part figures did not reconstruct the line, and the
     * remainder was quietly handed to whichever return happened to be last.
     *
     * **No extra scale fixes this, and that is the point.** A third of
     * `1010000` is not an integer at exponent 4, nor at 5, nor at any finite
     * exponent: one third has no terminating decimal. `alpha.67` bought four
     * more places because the rate's denominator is `10000` and that made
     * every COMMISSION exact; a quantity of three is a different denominator
     * and no fixed number of places answers it.
     *
     * **So the inexact split is refused by name and nothing is chosen
     * silently.** `null` here means «this quantity cannot be given back
     * exactly», the caller refuses with every figure stated, and NOTHING is
     * written. What still works, and is measured as a control:
     *
     *   * a FULL return — the last of the line gives back exactly what is
     *     left, which is exact by construction whatever the quantities were;
     *   * any partial split that divides exactly, which is the ordinary case
     *     (a line of three units priced the same divides by three);
     *   * every return already recorded, which is not touched or reread.
     *
     * The alternative — floor each part and give the residue to the last
     * return — is a real allocation rule with real consequences for a vendor
     * who is paid per return, and choosing it here would be this plugin
     * deciding it. The question is put to the owner with this worked example
     * in `docs/open-decisions-alpha68.md` §1.
     *
     * @return array{refund:int, tax:int, commission:int, vendor_share:int}|null
     */
    private function shareOf(VendorOrderItem $item, ReturnRequest $request): ?array
    {
        $already = $this->returns->reversedTotals($item->id);
        $remainingQuantity = max(0, $item->quantity - $already['quantity']);
        $base = (int) $item->baseMinor;
        $tax = (int) $item->taxMinor;
        $commission = (int) ($item->commissionMinor ?? 0);

        if ($request->quantity >= $remainingQuantity) {
            // The last of the line: give back exactly what is left, so
            // nothing stays accrued against goods that are all back on the
            // shelf. Exact by subtraction, never by division.
            $refund = max(0, $base - $already['refund']);
            $taxBack = max(0, $tax - $already['tax']);
            $commissionBack = max(0, $commission - $already['commission']);
        } else {
            $quantity = max(1, $item->quantity);
            $exact = static function (int $amount) use ($request, $quantity): ?int {
                $product = $amount * $request->quantity;
                return $product % $quantity === 0 ? intdiv($product, $quantity) : null;
            };
            $refund = $exact($base);
            $taxBack = $exact($tax);
            $commissionBack = $exact($commission);
            if ($refund === null || $taxBack === null || $commissionBack === null) {
                return null;
            }
        }
        return [
            'refund' => $refund,
            'tax' => $taxBack,
            'commission' => $commissionBack,
            // Derived, never rounded on its own: the four lines have to sum to
            // zero, and B = C + V is the identity the sale was recorded with.
            'vendor_share' => $refund - $commissionBack,
        ];
    }

    /**
     * WHETHER THIS RETURN CAN BE GIVEN BACK EXACTLY, asked without writing
     * anything.
     *
     * Exists so a manager is told BEFORE the goods arrive rather than at the
     * moment the money should move: `refund()` is the last step, and a
     * refusal there leaves a received parcel and a decision nobody can
     * complete. Read by the returns screen.
     *
     * @return array{exact:bool, quantity:int, line_quantity:int, exponent:int|null,
     *               base_minor:int, commission_minor:int, parts:list<string>}
     */
    public function splitExactness(int $returnId): array
    {
        $blank = [
            'exact' => true,
            'quantity' => 0,
            'line_quantity' => 0,
            'exponent' => null,
            'base_minor' => 0,
            'commission_minor' => 0,
            'parts' => [],
        ];
        $request = $this->returns->findReturn($returnId);
        if ($request === null) {
            return $blank;
        }
        $item = $this->items->find($request->orderItemId);
        if ($item === null) {
            return $blank;
        }
        $already = $this->returns->reversedTotals($item->id);
        $remaining = max(0, $item->quantity - $already['quantity']);
        $quantity = max(1, $item->quantity);
        $answer = [
            'exact' => true,
            'quantity' => $request->quantity,
            'line_quantity' => $item->quantity,
            'exponent' => $item->exponent,
            'base_minor' => (int) $item->baseMinor,
            'commission_minor' => (int) ($item->commissionMinor ?? 0),
            'parts' => [],
        ];
        if ($request->quantity >= $remaining) {
            return $answer;         // the last of the line is exact by subtraction
        }
        foreach ([
            'base' => (int) $item->baseMinor,
            'tax' => (int) $item->taxMinor,
            'commission' => (int) ($item->commissionMinor ?? 0),
        ] as $part => $amount) {
            if (($amount * $request->quantity) % $quantity !== 0) {
                $answer['exact'] = false;
                $answer['parts'][] = $part;
            }
        }
        return $answer;
    }

    /**
     * Where the vendor's side of the reversal goes.
     *
     * If the vendor has already been PAID for this sale, taking it out of
     * «درآمد» would describe money that is not there. It becomes a debt
     * instead — the account LedgerAccount::VendorDebt exists for exactly this
     * case — and the next settlement can see it.
     */
    private function vendorSideAccount(VendorOrderItem $item): LedgerAccount
    {
        foreach ($this->items->settlementView($item->vendorUserId) as $line) {
            if ((int) $line['id'] === $item->id && $line['paid'] === true) {
                return LedgerAccount::VendorDebt;
            }
        }
        return LedgerAccount::VendorEarning;
    }

    /**
     * Puts the goods back on the shelf — WooCommerce's shelf, which is the
     * source of truth for stock (ADR-008). An explicit act on an explicit
     * event, never a background reconciliation.
     */
    private function restock(int $actorId, ReturnRequest $request): int
    {
        $item = $this->items->find($request->orderItemId);
        if ($item === null) {
            return 0;
        }
        $product = $this->products->find($item->productId);
        if ($product === null) {
            return 0;
        }
        $after = $this->catalog->increaseStock($product, $request->quantity);
        if ($after === null) {
            return 0;       // no link, or a shop that does not count this product
        }
        $this->returns->recordRestock($request->id, $request->quantity);
        $this->audit->log(AuditEventCatalog::ORDER_RETURN_RESTOCKED, $actorId, 'order_return', (string) $request->id, [
            'vendor_id' => $request->vendorUserId,
            'return_id' => $request->id,
            'product_id' => $product->id,
            'quantity' => $request->quantity,
            'stock_after' => $after,
        ]);
        return $request->quantity;
    }
}
