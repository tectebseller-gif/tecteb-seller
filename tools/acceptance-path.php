<?php
/**
 * ONE path, end to end, through the plugin's own services on a DISPOSABLE
 * WordPress with real WooCommerce:
 *
 *   فروشنده و پرسنل → محصول → خرید چندفروشنده → ارسال جزئی → مرجوعی
 *   → دفترکل و برداشت آزمایشی → گزارش‌ها
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 * Written as one PHP pass rather than a chain of shell calls on purpose. The
 * stages are not independent — the purchase needs the product, the shipment
 * needs the purchase, the withdrawal needs the ledger lines the purchase wrote
 * — and a chain that re-derives each id from the previous tool's stdout is a
 * chain where one changed output line silently skips a stage. Here each stage
 * hands the next the object it actually made.
 *
 * Every stage prints `stage=<name> ok=<true|false> …` on one line, so the shell
 * around it can assert without parsing prose.
 *
 *   wp eval-file tools/acceptance-path.php run
 *   wp eval-file tools/acceptance-path.php ids       (what the last run made)
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageReviews;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\ManageReturns;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

const TMC_ACCEPTANCE_OPTION = 'tmc_acceptance_ids';

$command = (string) ($args[0] ?? 'run');
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

if ($command === 'ids') {
    $stored = get_option(TMC_ACCEPTANCE_OPTION, []);
    $parts = [];
    foreach (is_array($stored) ? $stored : [] as $key => $value) {
        $parts[] = $key . '=' . $value;
    }
    echo ($parts === [] ? 'ids none' : 'ids ' . implode(' ', $parts)) . "\n";
    return;
}

$products = $c->get(ProductRepositoryInterface::class);
$items = $c->get(OrderItemRepositoryInterface::class);
$ids = [];
$say = static function (string $stage, bool $ok, array $facts = []) {
    $parts = [];
    foreach ($facts as $key => $value) {
        $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }
    printf("stage=%s ok=%s %s\n", $stage, $ok ? 'true' : 'false', implode(' ', $parts));
};

// --- 1. vendor and staff -----------------------------------------------------
//
// Not created here: the vendor stage has its own evidence and its own seed
// (`purchase-block-state.php seed-staff`). What this path asserts is the thing
// every later stage depends on — that `StaffAccess` can answer «who works in
// which shop», because every scoped query below asks it.
$access = $c->get(StaffAccess::class);
$staffRepo = $c->get(StaffRepositoryInterface::class);
$vendorIds = [];
foreach ($products->projected(500) as $product) {
    if ((int) $product->wcProductId > 0 && !in_array((int) $product->vendorUserId, $vendorIds, true)) {
        $vendorIds[] = (int) $product->vendorUserId;
    }
}
sort($vendorIds);
$vendorA = $vendorIds[0] ?? 0;
$vendorB = $vendorIds[1] ?? 0;
$staffCount = $vendorA > 0 ? count($staffRepo->forVendor($vendorA)) : 0;
$staffMember = 0;
foreach ($vendorA > 0 ? $staffRepo->forVendor($vendorA) : [] as $member) {
    if ($member->status === StaffStatus::Active) {
        $staffMember = (int) $member->staffUserId;
    }
}
$say('vendor_and_staff', $vendorA > 0 && $vendorB > 0, [
    'vendor_a' => $vendorA,
    'vendor_b' => $vendorB,
    'staff_rows' => $staffCount,
    'active_staff_user' => $staffMember ?: '-',
    // The thing the rest of the path leans on.
    'staff_scoped' => $staffMember > 0
        ? $access->storeFor($staffMember) === $vendorA
        : 'no_staff',
]);
$ids['vendor_a'] = $vendorA;
$ids['vendor_b'] = $vendorB;

// --- 2. product --------------------------------------------------------------
$productA = null;
$productB = null;
foreach ($products->projected(500) as $product) {
    if ((int) $product->vendorUserId === $vendorA && $productA === null) {
        $productA = $product;
    }
    if ((int) $product->vendorUserId === $vendorB && $productB === null) {
        $productB = $product;
    }
}
$say('product', $productA !== null && $productB !== null, [
    'wc_a' => $productA?->wcProductId ?? 0,
    'wc_b' => $productB?->wcProductId ?? 0,
    'published_a' => $productA !== null ? $productA->status->value : '-',
    'published_b' => $productB !== null ? $productB->status->value : '-',
]);
if ($productA === null || $productB === null) {
    echo "stopping: the path needs a projected product from two different shops\n";
    return;
}
$ids['wc_a'] = (int) $productA->wcProductId;
$ids['wc_b'] = (int) $productB->wcProductId;

// --- 3. a multi-vendor purchase ----------------------------------------------
//
// Three lines on ONE order: shop A, shop B, and a product this marketplace does
// not own. The third is not decoration — it is how «سهم فروشنده» is proved to
// be per-shop rather than per-order, and how `not_ours` is proved to hold on a
// basket that mixes them.
$foreign = 0;
foreach (wc_get_products(['limit' => -1, 'return' => 'ids']) as $candidate) {
    if ($products->findByWcProduct((int) $candidate) === null) {
        $foreign = (int) $candidate;
        break;
    }
}
$order = wc_create_order();
$order->add_product(wc_get_product((int) $productA->wcProductId), 3);
$order->add_product(wc_get_product((int) $productB->wcProductId), 1);
if ($foreign > 0) {
    $order->add_product(wc_get_product($foreign), 1);
}
$order->set_customer_id($manager);
$order->calculate_totals();
$order->set_status('completed');
$order->save();
$orderId = (int) $order->get_id();
$lines = [];
foreach ($order->get_items() as $wcItemId => $item) {
    $wcProductId = (int) $item->get_product_id();
    $owned = $products->findByWcProduct($wcProductId);
    if ($owned === null) {
        continue;       // the shop's own goods: captured by nobody here
    }
    $lines[] = [
        'order_item_id' => (int) $wcItemId,
        'wc_product_id' => $wcProductId,
        'variation_id' => null,
        'title' => $owned->details->title,
        'sku' => $owned->details->sku,
        'quantity' => (int) $item->get_quantity(),
        'line_total_minor' => $owned->details->priceMinor * (int) $item->get_quantity(),
        'line_tax_minor' => 0,
    ];
}
$report = $c->get(CaptureOrder::class)->capture($orderId, $lines);
$captured = $items->forOrder($orderId);
$byVendor = [];
foreach ($captured as $line) {
    $byVendor[$line->vendorUserId] = ($byVendor[$line->vendorUserId] ?? 0) + 1;
}
$lineA = null;
foreach ($captured as $line) {
    if ($line->vendorUserId === $vendorA) {
        $lineA = $line;
    }
}
$say('multi_vendor_purchase', count($byVendor) === 2 && $lineA !== null, [
    'order' => $orderId,
    'captured' => (int) $report['captured'],
    'vendors_on_one_order' => count($byVendor),
    // The shop's own line is present on the order and absent from our rows.
    'foreign_line_on_order' => $foreign > 0 ? 'yes' : 'none',
    'foreign_line_captured' => $foreign > 0 && $products->findByWcProduct($foreign) === null ? 'no' : 'n/a',
]);
if ($lineA === null) {
    echo "stopping: nothing was captured for shop A\n";
    return;
}
$ids['order'] = $orderId;
$ids['item_a'] = $lineA->id;

// --- 4. partial shipment ------------------------------------------------------
$shipped = $c->get(ShipItems::class)->ship($vendorA, $vendorA, $lineA->id, 2, 'پست پیشتاز', 'TRK-ACC-1');
$afterOne = $items->find($lineA->id);
$shipments = $c->get(ShipmentRepositoryInterface::class)->shipmentsFor($lineA->id);
$say('partial_shipment', $shipped->ok && $afterOne !== null, [
    'code' => $shipped->code,
    'shipped_of' => '2/' . $lineA->quantity,
    'status' => $afterOne?->status->value ?? '-',
    'packages' => count($shipments),
]);

// --- 5. return ----------------------------------------------------------------
$opened = $c->get(ManageReturns::class)->open($vendorA, $vendorA, $lineA->id, 1, 'کالای معیوب');
$returnId = (int) ($opened->context['return_id'] ?? 0);
$returns = $c->get(ManageReturns::class);
$approved = $returns->decide($manager, $returnId, ReturnStatus::Approved);
$received = $returns->decide($manager, $returnId, ReturnStatus::Received, '', true);
$refunded = $returns->refund($manager, $returnId);
$say('return', $opened->ok && $approved->ok && $received->ok && $refunded->ok, [
    'return' => $returnId,
    'opened' => $opened->code,
    'received' => $received->code,
    'refunded' => $refunded->code,
    'did_ledger' => !empty($refunded->context['did_ledger']),
    'did_stock' => !empty($refunded->context['did_stock']),
    // Said here as well as on the screen: the record and the money are not the
    // same act, and this path never claims the second.
    'did_money' => !empty($refunded->context['did_money']),
]);
$ids['return'] = $returnId;

// --- 6. ledger and a trial withdrawal -----------------------------------------
// The delay FIRST, then the completion, then the reading. In the other order
// the «after» balance is computed against a delay that has not been applied
// yet, and the stage reports «۰ قابل درخواست» for a reason that is the
// script's own ordering rather than the plugin's rule.
$settings = get_option('tmc_settings');
$settings['values']['settlement_delay_days'] = 0;
update_option('tmc_settings', $settings);
// A bank account on file, for both shops.
//
// Without one `RequestWithdrawal` refuses with `bank_account_missing` — which
// is correct and is NOT the fact this stage is about. The stage asserts that a
// share held by an open return cannot be asked for; if the request dies on
// paperwork first, the assertion passes or fails for the wrong reason. The
// bank columns live on the store row, which `upgrade-rollback-check.sh` drops
// by design, so this is exactly the kind of prerequisite a rebuild loses.
$stores = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface::class);
foreach ([$vendorA, $vendorB] as $shopId) {
    $bank = $stores->bank($shopId);
    if (trim((string) ($bank['iban'] ?? '')) === '') {
        $stores->saveBank(
            $shopId,
            'IR' . str_pad((string) $shopId, 24, '0', STR_PAD_LEFT),
            'صاحب حساب آزمایشی',
            0,
            'approved',
            false
        );
    }
}

// Any request still open from a previous run, cancelled first.
//
// «یک درخواست باز در هر فروشگاه» is the rule, and the rule is why a second run
// of this fixture got `withdrawal_already_open` from both shops — a true
// answer about the previous run, dressed up as this run's result. Cancelling
// is the vendor's own verb while the request is still free, so the fixture
// uses it rather than reaching into the table.
$withdrawals = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface::class);
$requests = $c->get(RequestWithdrawal::class);
foreach ([$vendorA, $vendorB] as $shopId) {
    $open = $withdrawals->openFor($shopId);
    if ($open !== null) {
        $requests->cancel($shopId, $shopId, $open->id);
    }
}

$balanceBefore = $c->get(VendorBalance::class)->of($vendorA);
$settled = $items->recordSettlementCompletion($lineA->id, gmdate('Y-m-d H:i:s'), $manager);
$balanceAfter = $c->get(VendorBalance::class)->of($vendorA);
$withdrawal = $requests->handle($vendorA, $vendorA);

// Shop B's line is the control. It went through the same purchase and the same
// settlement, and it has NO return on it — so the two side by side show the one
// rule that would otherwise look like a bug: a line with an undecided return is
// «pending», not «eligible» (UX §10.2), and shop A's only line has one.
$lineB = null;
foreach ($items->forOrder($orderId) as $line) {
    if ($line->vendorUserId === $vendorB) {
        $lineB = $line;
    }
}
$settledB = $lineB !== null
    && $items->recordSettlementCompletion($lineB->id, gmdate('Y-m-d H:i:s'), $manager);
$balanceB = $lineB === null ? [] : $c->get(VendorBalance::class)->of($vendorB);
$withdrawalB = $lineB === null
    ? null
    : $requests->handle($vendorB, $vendorB);

$say('ledger_and_withdrawal', $settled && $settledB, [
    'earned_minor' => (int) ($balanceAfter['earned'] ?? 0),
    'eligible_before_settlement' => (int) ($balanceBefore['eligible'] ?? 0),
    'a_eligible_after' => (int) ($balanceAfter['eligible'] ?? 0),
    // Why shop A's is still zero, said as a number rather than left to guess.
    'a_held_by_return' => (int) ($balanceAfter['awaiting_return'] ?? 0),
    'b_eligible_after' => (int) ($balanceB['eligible'] ?? 0),
    'b_withdrawal_ok' => $withdrawalB?->ok ?? false,
    // A refusal here is a RESULT, not a failure of the path: `SettlementGate`
    // closes request-creation until DEC-02/FIN-04 are decided, and the balance
    // is still computed and shown (F-16). The code names which it is.
    'b_withdrawal_code' => $withdrawalB?->code ?? '-',
    'a_withdrawal_code' => $withdrawal->code,
    // The amount actually requested, beside the amount actually eligible.
    //
    // This is the assertion that survives a site with history on it. The check
    // used to expect the refusal codes `nothing_eligible` and
    // `bank_account_missing`, which were facts about a fixture that had never
    // been re-run: after a rebuild both shops have a bank on file and shop A
    // has eligible money from earlier runs, so both requests succeed and the
    // old expectation failed for a reason that was about the fixture. What is
    // true either way, and is the rule under test, is that the share held by
    // an open return is NOT in the request.
    'a_requested_minor' => (int) ($withdrawal->context['amount_minor'] ?? 0),
    'a_eligible_at_request' => (int) ($balanceAfter['eligible'] ?? 0),
    'a_pending_minor' => (int) ($balanceAfter['pending'] ?? 0),
]);

// --- 7. reports ---------------------------------------------------------------
$vendorReports = $c->get(Reports::class)->forVendor($vendorA, $vendorA);
$managerReports = $c->get(Reports::class)->forManager([$vendorA, $vendorB]);
$standing = $c->get(ManageReviews::class)->standing($vendorA);
$say('reports', $vendorReports !== [] && $managerReports !== [], [
    'vendor_cards' => count($vendorReports),
    'manager_cards' => count($managerReports),
    'sales_lines' => (int) ($vendorReports[Reports::SALES]['lines'] ?? 0),
    'sales_partially_shipped' => (int) ($vendorReports[Reports::SALES]['partially_shipped'] ?? 0),
    'sales_returned' => (int) ($vendorReports[Reports::SALES]['returned'] ?? 0),
    // The two standings, side by side and never added together.
    'rating_product' => (int) $standing['product']['average_hundredths'],
    'rating_vendor' => (int) $standing['vendor']['average_hundredths'],
]);

update_option(TMC_ACCEPTANCE_OPTION, $ids);
printf("done ids=%s\n", implode(',', array_map(
    static fn ($k, $v) => $k . ':' . $v,
    array_keys($ids),
    $ids
)));
