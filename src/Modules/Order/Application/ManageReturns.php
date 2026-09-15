<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
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
        private readonly ProductRepositoryInterface $products,
        private readonly CatalogProjectorInterface $catalog,
        private readonly ?CapabilityCheckerInterface $capabilities = null
    ) {
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
    public function refund(
        int $actorId,
        int $returnId,
        ?int $wcRefundId = null,
        string $note = '',
        string $currency = 'IRR',
        int $exponent = 0
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

        $share = $this->shareOf($item, $request);
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
            $actorId
        )) {
            return OperationResult::failure('already_refunded', ['return_id' => $returnId]);
        }

        $account = $this->vendorSideAccount($item);
        // The same currency and exponent the sale was recorded in: a reversal
        // in a different unit would balance arithmetically and mean nothing.
        $money = fn (int $minor): Money => Money::of($minor, $currency, $exponent);
        // …and each line points at the accrual line it undoes, so the ledger
        // itself says «this reverses that» rather than leaving a reader to
        // match event keys by eye. The vendor-side line is the exception when
        // the share was already paid out: the debt it creates reverses
        // nothing that exists, it is a new obligation.
        $original = $this->accrualLines($item);
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
     * This return's share of the line's money.
     *
     * @return array{refund:int, tax:int, commission:int, vendor_share:int}
     */
    private function shareOf(VendorOrderItem $item, ReturnRequest $request): array
    {
        $already = $this->returns->reversedTotals($item->id);
        $remainingQuantity = max(0, $item->quantity - $already['quantity']);
        $base = (int) $item->baseMinor;
        $tax = (int) $item->taxMinor;
        $commission = (int) ($item->commissionMinor ?? 0);

        if ($request->quantity >= $remainingQuantity) {
            // The last of the line: give back exactly what is left, so a
            // sequence of roundings cannot leave a stray unit of currency
            // accrued against goods that are all back on the shelf.
            $refund = max(0, $base - $already['refund']);
            $taxBack = max(0, $tax - $already['tax']);
            $commissionBack = max(0, $commission - $already['commission']);
        } else {
            $refund = intdiv($base * $request->quantity, max(1, $item->quantity));
            $taxBack = intdiv($tax * $request->quantity, max(1, $item->quantity));
            $commissionBack = intdiv($commission * $request->quantity, max(1, $item->quantity));
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
     * The accrual's own lines, by account, so a reversal can name them.
     *
     * @return array<string,int> account value => ledger entry id
     */
    private function accrualLines(VendorOrderItem $item): array
    {
        $byAccount = [];
        foreach ($this->ledger->forEvent($item->ledgerEvent) as $entry) {
            $byAccount[$entry->account->value] = $entry->id;
        }
        return $byAccount;
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
