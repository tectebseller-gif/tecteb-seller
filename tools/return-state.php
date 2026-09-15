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
            "item=%d vendor=%d wc_product=%d quantity=%d base=%d tax=%d commission=%s share=%s status=%s returned=%d\n",
            $item->id,
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
            "scope performed=%s not_performed=%s can_transfer_money=%s\n",
            implode(',', \Tecteb\Marketplace\Modules\Order\Application\RefundScope::PERFORMED),
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

    default:
        echo "unknown command\n";
        break;
}
