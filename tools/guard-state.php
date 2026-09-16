<?php
/**
 * The unpaid-order guard, driven from the command line on a DISPOSABLE
 * WordPress — including the things the owner said a general claim was not
 * enough for: many orders at once, a save that fails at each step, and stock
 * measured rather than described.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/guard-state.php seed <count> <wc-product> [pending|failed] [qty]
 *   wp eval-file tools/guard-state.php seed-mixed <tmc-product> <shop-product>
 *   wp eval-file tools/guard-state.php seed-prereduced <wc-product>
 *   wp eval-file tools/guard-state.php hold [reason]
 *   wp eval-file tools/guard-state.php release
 *   wp eval-file tools/guard-state.php trail <order-id>
 *   wp eval-file tools/guard-state.php reconcile-list
 *   wp eval-file tools/guard-state.php move-order <order-id> <status>
 *   wp eval-file tools/guard-state.php sell-more <wc-product> <qty>
 *   wp eval-file tools/guard-state.php payable
 *   wp eval-file tools/guard-state.php order <order-id>
 *   wp eval-file tools/guard-state.php census
 *   wp eval-file tools/guard-state.php stock <wc-product-id>…
 *   wp eval-file tools/guard-state.php probe <order-id> <step>
 *   wp eval-file tools/guard-state.php probe-off
 *   wp eval-file tools/guard-state.php forget-all
 *   wp eval-file tools/guard-state.php cleanup
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WcUnpaidOrderGuard;

$command = (string) ($args[0] ?? '');
$c = Bootstrap::container();
$guard = $c->get(\Tecteb\Marketplace\Modules\Product\Application\UnpaidOrderGuardInterface::class);
$META = WcUnpaidOrderGuard::PREVIOUS_STATUS_META;

/** Stock as a number, or the word that says the product does not track it. */
$stockOf = static function (int $productId) {
    $product = wc_get_product($productId);
    if (!$product) {
        return 'missing';
    }
    return $product->get_manage_stock() ? (string) (int) $product->get_stock_quantity() : 'unmanaged';
};

/** One unpaid order holding the given products, in the given status. */
$makeOrder = static function (array $productIds, string $status, int $quantity) {
    $order = wc_create_order();
    // Tagged so a later run can clear ITS OWN fixtures and nothing else. Two
    // hundred orders left behind from a previous run make every count in the
    // next one meaningless, and the counts are the whole evidence.
    $order->update_meta_data('_tmc_guard_fixture', '1');
    foreach ($productIds as $productId) {
        $product = wc_get_product((int) $productId);
        if ($product) {
            $order->add_product($product, $quantity);
        }
    }
    $order->calculate_totals();
    // set_status(), not update_status(): the fixture is being BUILT, and the
    // transition hooks would move stock for a sale nobody made.
    $order->set_status($status);
    $order->save();
    return (int) $order->get_id();
};

switch ($command) {
    case 'seed':
        // Many unpaid orders at once. The owner asked for more than 200
        // «سفارش واجد شرایط», because the bug this proves absent only appears
        // once the result set is larger than one page of the query the guard
        // used to iterate while mutating.
        $count = max(1, (int) ($args[1] ?? 1));
        $productId = (int) ($args[2] ?? 0);
        $status = (string) ($args[3] ?? 'pending');
        $quantity = max(1, (int) ($args[4] ?? 1));
        $made = [];
        for ($i = 0; $i < $count; $i++) {
            $made[] = $makeOrder([$productId], $status, $quantity);
        }
        printf(
            "seeded count=%d status=%s product=%d first=%d last=%d\n",
            count($made),
            $status,
            $productId,
            $made[0] ?? 0,
            $made[count($made) - 1] ?? 0
        );
        break;

    case 'seed-mixed':
        // One order holding a marketplace line AND the shop's own goods. The
        // hold moves the whole order, so WooCommerce moves the stock of BOTH —
        // which is the part a general claim about stock hides.
        $order = $makeOrder([(int) ($args[1] ?? 0), (int) ($args[2] ?? 0)], 'pending', 1);
        printf("mixed order=%d\n", $order);
        break;

    case 'seed-prereduced':
        // An order that ALREADY reduced stock before anything of ours touched
        // it — what an async gateway leaves behind. WooCommerce will not
        // reduce it again on the hold, but WILL increase it on the release.
        $orderId = $makeOrder([(int) ($args[1] ?? 0)], 'pending', 1);
        $order = wc_get_order($orderId);
        wc_reduce_stock_levels($order);
        $order->get_data_store()->set_stock_reduced($orderId, true);
        printf("pre-reduced order=%d stock_reduced=%s\n", $orderId,
            $order->get_data_store()->get_stock_reduced($orderId) ? 'true' : 'false');
        break;

    case 'hold':
        $result = $guard->hold((string) ($args[1] ?? 'manager_stopped'));
        printf(
            "hold held=%d examined=%d stuck=%s\n",
            $result['held'],
            $result['examined'],
            $result['stuck'] === [] ? '-' : implode(',', $result['stuck'])
        );
        break;

    case 'release':
        $result = $guard->release();
        printf(
            "release released=%d stuck=%s moved_on=%s reconcile=%s\n",
            $result['released'],
            $result['stuck'] === [] ? '-' : implode(',', $result['stuck']),
            $result['moved_on'] === [] ? '-' : implode(',', $result['moved_on']),
            $result['reconcile'] === [] ? '-' : implode(',', $result['reconcile'])
        );
        break;

    case 'trail':
        // What the guard wrote down about one order's stock.
        $trail = $guard->stockTrail((int) ($args[1] ?? 0));
        printf(
            "trail held=%s was_reduced=%s moved=%s reconcile=%s\n",
            $trail['held'] ? 'true' : 'false',
            $trail['was_reduced'] === null ? '-' : ($trail['was_reduced'] ? 'true' : 'false'),
            $trail['moved'] === null ? '-' : ($trail['moved'] ? 'true' : 'false'),
            $trail['reconcile'] === '' ? '-' : $trail['reconcile']
        );
        break;

    case 'reconcile-list':
        $ids = $guard->needsReconciliation();
        printf("reconcile count=%d ids=%s\n", count($ids), $ids === [] ? '-' : implode(',', $ids));
        break;

    case 'move-order':
        // Somebody pays or cancels the order WHILE it is held — the case the
        // release must leave alone rather than correct.
        $order = wc_get_order((int) ($args[1] ?? 0));
        if (!$order) {
            echo "no such order\n";
            break;
        }
        $order->update_status((string) ($args[2] ?? 'completed'), 'moved by hand during the hold', true);
        printf("moved order=%d status=%s\n", (int) $order->get_id(), $order->get_status());
        break;

    case 'sell-more':
        // A sale of the SAME product between the hold and the release, so the
        // evidence can show the release does not undo it.
        $product = wc_get_product((int) ($args[1] ?? 0));
        $by = max(1, (int) ($args[2] ?? 1));
        wc_update_product_stock($product, $by, 'decrease');
        printf("sold product=%d by=%d now=%s\n", (int) $product->get_id(), $by,
            (string) (int) wc_get_product((int) $product->get_id())->get_stock_quantity());
        break;

    case 'payable':
        $open = $guard->stillPayable();
        printf("payable count=%d first=%s\n", count($open), $open === [] ? '-' : (string) $open[0]);
        break;

    case 'order':
        $orderId = (int) ($args[1] ?? 0);
        $order = wc_get_order($orderId);
        if (!$order) {
            echo "no such order\n";
            break;
        }
        printf(
            "order=%d status=%s note=%s needs_payment=%s stock_reduced=%s\n",
            $orderId,
            $order->get_status(),
            ($order->get_meta($META) ?: '-'),
            $order->needs_payment() ? 'true' : 'false',
            $order->get_data_store()->get_stock_reduced($orderId) ? 'true' : 'false'
        );
        break;

    case 'census':
        // Counted from WooCommerce, not from our own bookkeeping: the whole
        // point is to catch a row the guard never looked at.
        $byStatus = [];
        foreach (['pending', 'failed', 'on-hold'] as $status) {
            $byStatus[$status] = count(wc_get_orders([
                'status' => $status, 'limit' => -1, 'return' => 'ids',
            ]));
        }
        $withNote = 0;
        foreach (wc_get_orders(['status' => 'any', 'limit' => -1, 'return' => 'ids']) as $id) {
            $order = wc_get_order($id);
            if ($order && (string) $order->get_meta($META) !== '') {
                $withNote++;
            }
        }
        printf(
            "census pending=%d failed=%d on_hold=%d with_note=%d\n",
            $byStatus['pending'], $byStatus['failed'], $byStatus['on-hold'], $withNote
        );
        break;

    case 'stock':
        $parts = [];
        foreach (array_slice($args, 1) as $productId) {
            $parts[] = ((int) $productId) . '=' . $stockOf((int) $productId);
        }
        printf("stock %s\n", $parts === [] ? '-' : implode(' ', $parts));
        break;

    case 'probe':
        update_option('tmc_probe_order_id', (int) ($args[1] ?? 0));
        update_option('tmc_probe_order_step', (string) ($args[2] ?? ''));
        printf("probe order=%s step=%s\n", (string) ($args[1] ?? ''), (string) ($args[2] ?? ''));
        break;

    case 'probe-off':
        delete_option('tmc_probe_order_id');
        delete_option('tmc_probe_order_step');
        echo "probe off\n";
        break;

    case 'cleanup':
        // Deletes only orders this file created, identified by their own tag.
        // Never touches an order somebody else made, on a disposable site or
        // anywhere else.
        $removed = 0;
        foreach (wc_get_orders(['status' => 'any', 'limit' => -1, 'return' => 'ids']) as $id) {
            $order = wc_get_order($id);
            if ($order && (string) $order->get_meta('_tmc_guard_fixture') === '1') {
                $order->delete(true);
                $removed++;
            }
        }
        printf("cleanup removed=%d\n", $removed);
        break;

    case 'purge':
        // Deletes EVERY unpaid or held order that carries a marketplace item,
        // whoever made it — so a stock sub-case can be measured to the unit.
        //
        // The tagged-only `cleanup` is not enough for that: this disposable
        // site carries orders from other evidence runs, they are eligible too,
        // and `hold()` moves all of them at once. That is correct behaviour
        // and it made «the hold takes exactly one unit out» read 4997.
        //
        // Fixture only, disposable site only. It deletes orders.
        $removed = 0;
        foreach (wc_get_orders(['status' => ['pending', 'failed', 'on-hold'], 'limit' => -1, 'return' => 'ids']) as $id) {
            $order = wc_get_order($id);
            if (!$order) {
                continue;
            }
            foreach ($order->get_items() as $item) {
                if ((string) get_post_meta((int) $item->get_product_id(), '_tmc_product_id', true) !== '') {
                    $order->delete(true);
                    $removed++;
                    break;
                }
            }
        }
        printf("purged orders=%d\n", $removed);
        break;

    case 'forget-all':
        // Clears this guard's restore notes without moving any status, so a
        // run starts from a known place. Fixture only.
        $cleared = 0;
        foreach (wc_get_orders(['status' => 'any', 'limit' => -1, 'return' => 'ids']) as $id) {
            $order = wc_get_order($id);
            if ($order && (string) $order->get_meta($META) !== '') {
                $order->delete_meta_data($META);
                $order->save();
                $cleared++;
            }
        }
        printf("forgot notes=%d\n", $cleared);
        break;

    default:
        echo "unknown command\n";
}
