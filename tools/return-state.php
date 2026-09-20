<?php
/**
 * The return and refund path, driven through the plugin's own services on a
 * DISPOSABLE WordPress — so the evidence next to it measures what a manager
 * would get, not a second implementation that happens to agree.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/return-state.php first-item
 *   wp eval-file tools/return-state.php show <order-item-id>
 *   wp eval-file tools/return-state.php open <order-item-id> <qty> [reason]
 *   wp eval-file tools/return-state.php decide <return-id> <status> [restock]
 *   wp eval-file tools/return-state.php refund <return-id>
 *   wp eval-file tools/return-state.php show-return <return-id>
 *   wp eval-file tools/return-state.php list <order-item-id>
 *   wp eval-file tools/return-state.php terms
 *   wp eval-file tools/return-state.php ledger-count
 *   wp eval-file tools/return-state.php reversal-sum <order-item-id> <return-id>
 *   wp eval-file tools/return-state.php accrual-intact <order-item-id>
 *   wp eval-file tools/return-state.php wc-refund <return-id>
 *   wp eval-file tools/return-state.php wc-refunds <order-item-id>
 *   wp eval-file tools/return-state.php unlink-refund <return-id>
 *   wp eval-file tools/return-state.php stage-orphan <return-id>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ManageReturns;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ReturnTerms;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file touches WordPress and writes. On the owner's site that is not a
// tool, it is damage, and a docblock saying «disposable» stops nobody who
// pastes the command at the wrong shell. Two independent facts, the same
// pair tools/disposable-site.sh trusts — NOT wp_get_environment_type(),
// which reports `production` on the disposable container itself.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


$command = (string) ($args[0] ?? 'first-item');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
        {
        }

        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});

$items = $c->get(OrderItemRepositoryInterface::class);
$returns = $c->get(ManageReturns::class);
$shipments = $c->get(ShipmentRepositoryInterface::class);
$ledger = $c->get(LedgerRepositoryInterface::class);

switch ($command) {
    case 'seed-line':
        // A FRESH recorded order line, through the marketplace's own capture
        // path — so this evidence can be re-run without hunting for a line
        // that still has units left. (Hunting is what the first version did,
        // and after a few runs every line was fully returned and sixteen
        // checks failed for a reason that had nothing to do with the code.)
        $wcProductId = (int) ($args[1] ?? 0);
        $quantity = max(1, (int) ($args[2] ?? 2));
        $product = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)
            ->findByWcProduct($wcProductId);
        if ($product === null) {
            echo "not a marketplace product\n";
            break;
        }
        $orderId = 90000 + random_int(1, 8999);
        $report = $c->get(\Tecteb\Marketplace\Modules\Order\Application\CaptureOrder::class)->capture($orderId, [[
            'order_item_id' => $orderId * 10,
            'wc_product_id' => $wcProductId,
            'variation_id' => null,
            'title' => $product->details->title,
            'sku' => $product->details->sku,
            'quantity' => $quantity,
            'line_total_minor' => $product->details->priceMinor * $quantity,
            'line_tax_minor' => 0,
        ]]);
        $line = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class)
            ->findByOrderItem($orderId * 10);
        printf(
            "seed item=%s order=%d captured=%d unrecorded=%d quantity=%d\n",
            $line === null ? '0' : (string) $line->id,
            $orderId,
            (int) $report['captured'],
            (int) $report['unrecorded'],
            $quantity
        );
        break;

    case 'first-item':
        global $wpdb;
        printf("item=%d\n", (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'tmc_order_items ORDER BY id ASC LIMIT 1'
        ));
        break;

    case 'eligible-line':
        // A recorded line nobody has locked into a withdrawal yet — the only
        // kind on which the "an open return holds the share" rule is visible.
        global $wpdb;
        printf("item=%d\n", (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'tmc_order_items
             WHERE withdrawal_id IS NULL AND vendor_share_minor IS NOT NULL
             ORDER BY id ASC LIMIT 1'
        ));
        break;

    case 'show':
        $item = $items->find((int) ($args[1] ?? 0));
        if ($item === null) {
            echo "no such line\n";
            break;
        }
        printf(
            "item=%d order=%d vendor=%d wc_product=%d quantity=%d base=%d tax=%d commission=%s share=%s status=%s returned=%d\n",
            $item->id,
            $item->orderId,
            $item->vendorUserId,
            $item->wcProductId,
            $item->quantity,
            $item->baseMinor,
            $item->taxMinor,
            $item->commissionMinor === null ? '-' : (string) $item->commissionMinor,
            $item->vendorShareMinor === null ? '-' : (string) $item->vendorShareMinor,
            $item->status->value,
            $shipments->returnedQuantity($item->id)
        );
        break;

    case 'open':
        $item = $items->find((int) ($args[1] ?? 0));
        if ($item === null) {
            echo "no such line\n";
            break;
        }
        // Opened by the SHOP, as a vendor's staff would.
        $result = $returns->open(
            $item->vendorUserId,
            $item->vendorUserId,
            $item->id,
            (int) ($args[2] ?? 1),
            (string) ($args[3] ?? '')
        );
        printf(
            "open ok=%s code=%s return=%s returnable=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['return_id'] ?? '-'),
            (string) ($result->context['returnable'] ?? '-')
        );
        break;

    case 'decide':
        $status = ReturnStatus::tryFrom((string) ($args[2] ?? ''));
        if ($status === null) {
            echo "unknown status\n";
            break;
        }
        $result = $returns->decide($manager, (int) ($args[1] ?? 0), $status, '', (string) ($args[3] ?? '0') === '1');
        printf(
            "decide ok=%s code=%s restocked=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['restocked'] ?? '0')
        );
        break;

    case 'refund':
        // An optional delay, so two processes can be made to meet inside the
        // same refund rather than merely one after the other.
        $delay = (float) ($args[2] ?? 0);
        if ($delay > 0) {
            usleep((int) ($delay * 1000000));
        }
        $result = $returns->refund($manager, (int) ($args[1] ?? 0));
        printf(
            "refund ok=%s code=%s refund_minor=%s tax_minor=%s account=%s did_ledger=%s did_stock=%s did_wc_refund=%s did_money=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['refund_minor'] ?? '0'),
            (string) ($result->context['tax_minor'] ?? '0'),
            (string) ($result->context['vendor_account'] ?? '-'),
            !empty($result->context['did_ledger']) ? 'true' : 'false',
            !empty($result->context['did_stock']) ? 'true' : 'false',
            !empty($result->context['did_wc_refund']) ? 'true' : 'false',
            !empty($result->context['did_money']) ? 'true' : 'false'
        );
        break;

    case 'scope':
        $scope = new \Tecteb\Marketplace\Modules\Order\Application\RefundScope();
        printf(
            "scope performed=%s on_request=%s not_performed=%s can_transfer_money=%s\n",
            implode(',', \Tecteb\Marketplace\Modules\Order\Application\RefundScope::PERFORMED),
            implode(',', \Tecteb\Marketplace\Modules\Order\Application\RefundScope::ON_REQUEST),
            implode(',', \Tecteb\Marketplace\Modules\Order\Application\RefundScope::NOT_PERFORMED),
            $scope->canTransferMoney() ? 'true' : 'false'
        );
        break;

    case 'show-return':
        $request = $shipments->findReturn((int) ($args[1] ?? 0));
        if ($request === null) {
            echo "no such return\n";
            break;
        }
        printf(
            "return=%d item=%d quantity=%d status=%s refund=%s restocked=%d event=%s\n",
            $request->id,
            $request->orderItemId,
            $request->quantity,
            $request->status->value,
            $request->refundMinor === null ? '-' : (string) $request->refundMinor,
            $request->restockedQuantity,
            $request->reversalEventKey ?? '-'
        );
        break;

    case 'list':
        foreach ($shipments->returnsFor((int) ($args[1] ?? 0)) as $request) {
            printf(
                "return=%d quantity=%d status=%s refund=%s\n",
                $request->id,
                $request->quantity,
                $request->status->value,
                $request->refundMinor === null ? '-' : (string) $request->refundMinor
            );
        }
        break;

    case 'terms':
        printf("open_terms=%s decision=%s automatic=%s\n",
            implode(',', (new ReturnTerms())->openTerms()),
            ReturnTerms::DECISION,
            (new ReturnTerms())->decidesAutomatically() ? 'true' : 'false');
        break;

    case 'ledger-count':
        global $wpdb;
        printf("lines=%d\n", (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tmc_ledger_entries'
        ));
        break;

    case 'reversal-sum':
        $key = 'return:' . (int) ($args[1] ?? 0) . ':' . (int) ($args[2] ?? 0);
        $sum = 0;
        foreach ($ledger->forEvent($key) as $entry) {
            $sum += $entry->amount->minor;
        }
        printf("sum=%d event=%s\n", $sum, $key);
        break;

    case 'accrual-intact':
        $item = $items->find((int) ($args[1] ?? 0));
        if ($item === null) {
            echo "no such line\n";
            break;
        }
        // Every line of the original accrual, summed: it must still add to
        // zero and still carry the base the sale recorded.
        $sum = 0;
        $central = 0;
        foreach ($ledger->forEvent($item->ledgerEvent) as $entry) {
            $sum += $entry->amount->minor;
            if ($entry->account === \Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount::CentralPayment) {
                $central += $entry->amount->minor;
            }
        }
        printf(
            "intact=%s sum=%d central=%d expected=%d\n",
            ($sum === 0 && $central === $item->baseMinor + $item->taxMinor) ? 'true' : 'false',
            $sum,
            $central,
            $item->baseMinor + $item->taxMinor
        );
        break;

    case 'wc-refund':
        // The THIRD part of RefundScope, asked for on its own. This is the
        // call the crash test interrupts and then repeats.
        $result = $returns->recordWooCommerceRefund($manager, (int) ($args[1] ?? 0));
        printf(
            "wc-refund ok=%s code=%s wc_refund_id=%s did_wc_refund=%s did_money=%s candidates=%s blockers=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['wc_refund_id'] ?? '-'),
            !empty($result->context['did_wc_refund']) ? 'true' : 'false',
            !empty($result->context['did_money']) ? 'true' : 'false',
            ((string) ($result->context['candidates'] ?? '')) === ''
                ? '-'
                : (string) $result->context['candidates'],
            (string) ($result->context['money_blockers'] ?? '-')
        );
        break;

    case 'wc-refunds':
        // Every refund WooCommerce itself holds against this line's order, with
        // the stamp that says which return made it. Counted from WooCommerce,
        // not from our own row — the whole point is to catch a refund our row
        // does not know about.
        $item = $items->find((int) ($args[1] ?? 0));
        if ($item === null) {
            echo "no such line\n";
            break;
        }
        $order = wc_get_order($item->orderId);
        $refunds = $order ? $order->get_refunds() : [];
        $stamped = 0;
        $total = 0.0;
        $ids = [];
        foreach ($refunds as $refund) {
            $ids[] = (int) $refund->get_id()
                . ':' . ((string) $refund->get_meta('_tmc_return_id') ?: '-')
                . ':' . (string) $refund->get_amount();
            if ((string) $refund->get_meta('_tmc_return_id') !== '') {
                $stamped++;
            }
            $total += (float) $refund->get_amount();
        }
        printf(
            "wc-refunds order=%d count=%d stamped=%d total=%s remaining=%s ids=%s\n",
            $item->orderId,
            count($refunds),
            $stamped,
            number_format($total, 2, '.', ''),
            $order ? number_format((float) $order->get_remaining_refund_amount(), 2, '.', '') : '-',
            $ids === [] ? '-' : implode(',', $ids)
        );
        break;

    case 'wc-refund-link':
        // What the MARKETPLACE row believes, as opposed to what WooCommerce
        // holds. The gap between the two is the crash this round is about.
        $request = $shipments->findReturn((int) ($args[1] ?? 0));
        printf(
            "link return=%s wc_refund_id=%s\n",
            $request === null ? '-' : (string) $request->id,
            ($request === null || $request->wcRefundId === null) ? '-' : (string) $request->wcRefundId
        );
        break;

    case 'stage-orphan':
        // The state a crash between the refund's row and its stamp leaves —
        // and the state a manager's OWN wp-admin refund leaves, which from the
        // outside is indistinguishable. That is the whole point: the plugin
        // cannot tell them apart, so it must not guess.
        //
        // Staged directly rather than by contorting a probe. Measured on this
        // site: under HPOS the stamp is written BEFORE `_refund_type`, so
        // killing on a meta hook lands after the stamp and cannot produce this
        // state — but the row insert and the meta insert are still separate
        // statements with no transaction between them, so the state is real.
        $id = (int) ($args[1] ?? 0);
        $request = $shipments->findReturn($id);
        if ($request === null) {
            echo "no such return\n";
            break;
        }
        $item = $items->find($request->orderItemId);
        $order = $item === null ? null : wc_get_order($item->orderId);
        if (!$order) {
            echo "no order behind that return\n";
            break;
        }
        // The intent marker, exactly as `record()` writes it before it tries.
        $order->update_meta_data(
            \Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce\WcRefundRecorder::INTENT_META . $id,
            (string) time()
        );
        $order->save();
        $refund = wc_create_refund([
            'order_id' => $item->orderId,
            'amount' => 1,
            'reason' => 'orphan without a stamp',
            'refund_payment' => false,
            'restock_items' => false,
        ]);
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'tmc_returns SET wc_refund_id = NULL WHERE id = %d',
            $id
        ));
        printf(
            "stage-orphan return=%d order=%d refund=%s intent=set\n",
            $id,
            $item->orderId,
            is_wp_error($refund) ? 'error' : (string) $refund->get_id()
        );
        break;

    case 'unlink-refund':
        // Puts the marketplace row back exactly where a crash would have left
        // it: WooCommerce holds the refund, our row has never heard of it.
        // Only the link is cleared — the WooCommerce refund is NOT touched,
        // because a test that tidied it away would test nothing.
        global $wpdb;
        $id = (int) ($args[1] ?? 0);
        $before = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT wc_refund_id FROM ' . $wpdb->prefix . 'tmc_returns WHERE id = %d',
            $id
        ));
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'tmc_returns SET wc_refund_id = NULL WHERE id = %d',
            $id
        ));
        printf("unlink return=%d was=%s now=%s\n", $id, $before === '' ? '-' : $before, '-');
        break;

    default:
        echo "unknown command\n";
        break;
}
