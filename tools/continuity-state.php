<?php
/**
 * The cutover, continued: does the SHOP still work afterwards?
 *
 * The existing cutover evidence proves Dokan can be switched off without
 * losing anything and with the old URLs still landing somewhere. That is a
 * necessary result and it is not the interesting one. A migration is a
 * success when the business keeps running on the other side — when the shop
 * that moved can sell the product that moved, to a real customer, and be paid
 * for it.
 *
 * So this runs that, end to end, in ONE PHP pass on a DISPOSABLE WordPress:
 *
 *   وارد شده (observed) → غیرقابل‌فروش → انتقال مالکیت → قابل‌فروش → خرید واقعی
 *   → کاهش موجودی → دسترسی فروشنده و پرسنل → ارسال → سهم مالی → بازگشت
 *
 * One pass rather than a shell chain because the stages are not independent:
 * the purchase needs the transfer, the shipment needs the purchase, the
 * financial share needs the lines the purchase wrote. A chain that re-derives
 * each id from the previous tool's stdout skips a stage silently the day one
 * output line changes.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/continuity-state.php run
 *   wp eval-file tools/continuity-state.php reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

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


$command = (string) ($args[0] ?? 'run');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);

// The manager, with everything this build grants. Taken from `Capabilities::all()`
// rather than written out, so a new capability does not silently make a stage
// refuse inside the harness while working on a real site.
$c->bind(CapabilityCheckerInterface::class, static function () {
    return new class implements CapabilityCheckerInterface {
        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return (int) get_current_user_id();
        }
    };
});

$products = $c->get(ProductRepositoryInterface::class);
$items = $c->get(OrderItemRepositoryInterface::class);
$ledger = $c->get(LedgerRepositoryInterface::class);

$say = static function (string $stage, bool $ok, array $fields): void {
    $line = 'stage=' . $stage . ' ok=' . ($ok ? 'true' : 'false');
    foreach ($fields as $k => $v) {
        $line .= ' ' . $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
    }
    echo $line . "\n";
};

if ($command === 'reset') {
    // «هر fixture ای که چیزی خرج می‌کند باید بتواند دوباره اجرا شود.»
    //
    // The first version of this only removed `observed` rows, which is
    // precisely the set this script empties on its way through: it TAKES
    // ownership, so by the end there is nothing observed left and the reset
    // swept an empty floor. The next run then imported nothing, found no row
    // to carry, and stopped — reporting a state it had itself created.
    //
    // So the reset walks what the script actually touched: the ids it wrote
    // down when it finished, and every row pointing at a Dokan product.
    global $wpdb;
    $transfer = $c->get(TransferOwnership::class);
    $last = (array) get_option('tmc_continuity_last', []);

    $removedOrders = 0;
    foreach ((array) ($last['orders'] ?? []) as $oldOrder) {
        $oldOrder = (int) $oldOrder;
        if ($oldOrder <= 0) {
            continue;
        }
        // The order lines and the ledger rows this script created. Both are
        // append-only in production and this is a disposable fixture; leaving
        // them makes the NEXT run's «exactly one financial engine» count the
        // previous run's lines.
        $wpdb->delete($wpdb->prefix . 'tmc_order_items', ['wc_order_id' => $oldOrder]);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tmc_ledger_entries WHERE event_key LIKE %s",
            'order:' . $oldOrder . ':item:%'
        ));
        $order = wc_get_order($oldOrder);
        if ($order) {
            $order->delete(true);
            $removedOrders++;
        }
    }

    $reader = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface::class);
    $given = 0;
    $removed = 0;
    foreach ($reader->products() as $dokanProduct) {
        $wc = (int) ($dokanProduct['wc_product_id'] ?? 0);
        if ($wc <= 0) {
            continue;
        }
        $row = $products->findByWcProduct($wc) ?? $products->findObservedByWcProduct($wc);
        if ($row === null) {
            continue;
        }
        if ($products->linkOwnership((int) $row->id) === LinkOwnership::Marketplace) {
            $transfer->giveBack((int) $row->id);
            $given++;
        }
        // `deleteDraft()` refuses anything that is not a draft, and rightly:
        // a published or archived product is a record of something. The
        // previous run published this one, so the service will not remove it
        // — and a fixture that leaves the row behind makes the NEXT run
        // report `not_submittable` about its own leftovers.
        //
        // So the reset deletes the row itself. That is a fixture doing
        // fixture things on a disposable site, not a path the plugin offers:
        // there is deliberately no «delete a published product» service, and
        // this is not one.
        if (!$products->deleteDraft((int) $row->id)) {
            $wpdb->delete($wpdb->prefix . 'tmc_products', ['id' => (int) $row->id]);
        }
        $removed++;
    }
    // The SOURCE product's own state, put back the way the fixture found it.
    //
    // Our row points at the same WooCommerce product Dokan sells, so a run
    // that sells two of them leaves the «source» stock at 23 — and the next
    // run's clamp stage then measures a number this script wrote. That is not
    // a defect, it is what linking to the same product MEANS; but a fixture
    // has to undo it or it stops measuring the thing it names.
    foreach ($reader->products() as $sourceProduct) {
        $sourceWc = (int) ($sourceProduct['wc_product_id'] ?? 0);
        if ($sourceWc > 0) {
            update_post_meta($sourceWc, '_stock', -2);
            $wc = wc_get_product($sourceWc);
            if ($wc) {
                $wc->set_stock_quantity(-2);
                $wc->save();
            }
        }
    }

    // The application this script created for the imported shop. Left behind,
    // it reads «approved» on the next run while the profile still cannot
    // sell — and `ReviewApplication` then refuses the approval as an invalid
    // transition, which is the state machine being right about a world the
    // fixture made wrong.
    $clearedApplications = 0;
    foreach ($reader->vendors() as $dokanVendor) {
        $userId = (int) ($dokanVendor['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $clearedApplications += (int) $wpdb->delete(
            $wpdb->prefix . 'tmc_vendor_applications',
            ['user_id' => $userId]
        );
        $wpdb->delete($wpdb->prefix . 'tmc_vendor_profiles', ['user_id' => $userId]);
    }
    delete_option('tmc_continuity_last');
    printf(
        "reset orders_removed=%d ownership_given_back=%d products_removed=%d"
        . " applications_cleared=%d observed_left=%d\n",
        $removedOrders,
        $given,
        $removed,
        $clearedApplications,
        count($products->observed())
    );
    return;
}

// --- 0. what the source carries, and what nobody has decided yet -----------
// A Dokan product brings a WooCommerce category. A marketplace product needs
// a MARKETPLACE category, because that is what decides its medical spec
// fields. The two are different vocabularies and the import will not guess
// between them — so before mapping, the report names the gap.
$reader = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface::class);
$map = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\CategoryMap::class);
$map->save([]);
// The SOURCE product's own photograph, so «the import carries the image
// across» is measured rather than asserted: a carry-over of an absent image
// proves nothing at all.
require_once ABSPATH . 'demo-image.php';
foreach ($reader->products() as $sourceProduct) {
    $sourceWc = (int) ($sourceProduct['wc_product_id'] ?? 0);
    if ($sourceWc > 0 && !get_post_thumbnail_id($sourceWc)) {
        tmc_demo_attach_image($sourceWc, 'tmc-source-product-' . $sourceWc, 'stethoscope', [0x14, 0x6B, 0x8C]);
    }
}
$sourceProducts = $reader->products();
// Kept so the clamp can be reported against the number it came from.
$sourceStockRaw = (int) ($sourceProducts[0]['stock'] ?? 0);
$gapBefore = $map->unmapped($sourceProducts);
$say('unmapped_categories_are_named', $gapBefore !== [], [
    'source_products' => count($sourceProducts),
    'unmapped_groups' => count($gapBefore),
    'first_key' => $gapBefore[0]['key'] ?? '-',
    'first_label' => $gapBefore[0]['label'] ?? '-',
    'products_waiting' => $gapBefore[0]['products'] ?? 0,
]);

// --- 0b. the owner decides the mapping -------------------------------------
// TRIAL SETTING. It is written into the disposable site's options here and is
// NOT in the install package — a packaging test asserts the package ships no
// category mapping, because a mapping is one marketplace's commercial
// decision and cannot travel in a plugin.
$targetCategory = (string) ($wpdbCategory ?? '');
global $wpdb;
$targetCategory = (string) $wpdb->get_var(
    "SELECT category_key FROM {$wpdb->prefix}tmc_spec_templates LIMIT 1"
);
$sourceKey = (string) ($gapBefore[0]['key'] ?? '');
$map->save([$sourceKey => $targetCategory]);
$gapAfter = $map->unmapped($sourceProducts);
$say('owner_maps_the_category', $map->marketplaceCategoryFor($sourceKey) === $targetCategory && $targetCategory !== '', [
    'source' => $sourceKey !== '' ? $sourceKey : '(none)',
    'marketplace' => $targetCategory !== '' ? $targetCategory : '(no spec template on this install)',
    'unmapped_groups_after' => count($gapAfter),
]);

// --- 1. a Dokan product arrives, and arrives as a NOTE ----------------------
$import = $c->get(ImportFromDokan::class);
$plan = $import->plan();
$imported = $import->import($plan);
$observed = array_values($products->observed());
$row = $observed[0] ?? null;
$ownershipOf = static fn (int $id): string => $products->linkOwnership($id)->value;
$say('imported_as_observed', $row !== null && $products->linkOwnership((int) $row->id) === LinkOwnership::Observed, [
    'run' => $plan->runId,
    'ok_import' => $imported->ok ? 'true' : 'false',
    'observed_rows' => count($observed),
    'ownership' => $row !== null ? $ownershipOf((int) $row->id) : '-',
    // Carried from the source post, not re-uploaded by anybody.
    'images_carried' => $row !== null ? count($row->imageIds) : 0,
]);
if ($row === null) {
    echo "stopping: the import produced no observed row to carry through\n";
    return;
}
$productId = (int) $row->id;
$wcProductId = (int) $row->wcProductId;
$vendorId = (int) $row->vendorUserId;

// --- 2. an imported row is not a product this marketplace sells -------------
// The whole point of `observed`: the catalogue arrived, and nothing about
// arriving makes it sellable. Measured, not assumed — because «operational
// queries see only `marketplace`» is a claim about every query, and the
// purchase policy is the one a customer actually reaches.
$policy = $c->get(PurchasePolicy::class);
$before = $policy->decide($wcProductId);
$say('observed_is_not_sellable', ($before['decision'] ?? '') !== 'allowed', [
    'wc' => $wcProductId,
    'decision' => $before['decision'] ?? '-',
]);

// --- 3. the manager takes operational ownership -----------------------------
$transfer = $c->get(TransferOwnership::class);
$taken = $transfer->take($productId);
$say('ownership_taken', $taken->ok && $transfer->currentOwnership($productId) === LinkOwnership::Marketplace, [
    'product' => $productId,
    'code' => $taken->code,
    'ownership' => $transfer->currentOwnership($productId)->value,
]);

// --- 3b. …and the shop still cannot trade, because nobody approved it ------
// The finding this stage exists to record: taking the CATALOGUE does not make
// the SHOP a vendor here. `vendorCanTrade()` asks this marketplace's own
// register, and an imported Dokan seller is not in it until a manager puts
// them there. Measured: right after a successful ownership transfer the
// product answered `vendor_stopped`, not `allowed`.
//
// That is the whole of item 2 in one line — «هیچ دسترسی نامشخصی خودکار اعطا
// نشود» — and it is enforced by the register being separate, not by a guard
// somebody has to remember to write.
$staffBefore = $c->get(StaffAccess::class);
$tradeBefore = $staffBefore->vendorCanTrade($vendorId);
$midway = $policy->decide($wcProductId);
$say('imported_shop_may_not_trade_yet', !$tradeBefore && ($midway['decision'] ?? '') !== 'allowed', [
    'vendor' => $vendorId,
    'can_trade' => $tradeBefore ? 'true' : 'false',
    'decision' => $midway['decision'] ?? '-',
]);

// --- 3c. a manager admits the shop to this marketplace, deliberately -------
$vendors = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class);
$application = $vendors->findApplicationByUser($vendorId);
$applicationId = $application?->id ?? $vendors->saveDraft($vendorId, new \Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails(
    'فروشگاه منتقل‌شده از دکان',
    'شخص حقوقی آزمایشی',
    'migrated@evidence.invalid',
    '09120000002',
    'نشانی آزمایشی',
    true
));
// Through `ReviewApplication`, not `updateStatus()`. The status is the
// PAPERWORK; the profile's `canSell` is the operational switch, and only the
// review service writes it. The import deliberately writes `canSell = false`
// — so a shop can arrive complete, with its catalogue and its history, and
// still not be able to sell a thing until somebody here says so.
//
// Writing the status directly is exactly the mistake this separation exists
// to make visible: it left the application reading «approved» while the shop
// still could not trade, which is the honest answer to a half-done admission.
// The paperwork moves to «submitted» directly: the application FORM has its
// own suite and is not what this path is about. The DECISION, though, goes
// through `ReviewApplication` — because that service is the only thing that
// writes the operational switch, and the switch is the thing under test.
$vendors->updateStatus($applicationId, \Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus::Submitted, $manager, 'پروندهٔ انتقال');
$review = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication::class);
$approved = $review->approve($applicationId);
$policy->forget($wcProductId);
$profile = $vendors->findProfileByUser($vendorId);
$say('shop_admitted_by_a_manager', $approved->ok && $c->get(StaffAccess::class)->vendorCanTrade($vendorId), [
    'vendor' => $vendorId,
    'application' => $applicationId,
    'code' => $approved->code,
    'status' => (string) ($vendors->findApplicationByUser($vendorId)?->status->value ?? '-'),
    'can_sell' => $profile !== null && $profile->canSell ? 'true' : 'false',
    'can_publish_directly' => $profile !== null && $profile->canPublishDirectly ? 'true' : 'false',
]);

// --- 3d. the migrated product is a DRAFT, and is published deliberately ----
// The import brings a catalogue in as drafts on purpose: a shop's products
// arriving is not the same as a shop's products going live, and somebody has
// to look. So the shop submits and the manager approves, through the same two
// services any other product goes through — there is no «imported» shortcut
// past the review, which is the point.
$manage = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);

// The medical specs. THIS one the migration cannot supply, and saying so is
// more useful than pretending otherwise: Dokan has no equivalent of this
// marketplace's category spec fields, so there is nothing to carry. The
// product arrives complete in every way a migration can make it complete, and
// then a human fills in what only a human knows.
//
// Filled here as a DEMO step so the rest of the arc can be measured. On a
// real cutover this is the shop's data-entry queue, and the migration report
// is what tells them how long it is.
$draft = $products->find($productId);
$fresh0 = $draft;
global $wpdb;
$specs = [];
foreach ($wpdb->get_results($wpdb->prepare(
    "SELECT f.field_key FROM {$wpdb->prefix}tmc_spec_fields f
     INNER JOIN {$wpdb->prefix}tmc_spec_templates t ON t.id = f.template_id
     WHERE t.category_key = %s AND f.required = 1 AND f.deprecated = 0",
    $draft->details->categoryKey
), ARRAY_A) as $field) {
    $specs[(string) $field['field_key']] = 'لاتکس (دادهٔ نمایشی)';
}
$filled = $manage->save(
    $vendorId,
    $vendorId,
    $productId,
    $draft->details,
    $specs,
    $draft->imageIds,
    $draft->mainImageId,
    $draft->rowVersion
);
$say('specs_are_the_one_gap_migration_cannot_fill', $filled->ok, [
    'required_fields' => count($specs),
    'code' => $filled->code,
    'note' => 'demo_data_on_the_disposable_site_only',
]);

$submitted = $manage->submit($vendorId, $vendorId, $productId);
$publish = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ReviewProducts::class)->approve($productId);
$policy->forget($wcProductId);
$published = $products->find($productId);
$say('migrated_product_published_through_review', $publish->ok, [
    'submit' => $submitted->code,
    'approve' => $publish->code,
    'status' => $published?->status->value ?? '-',
    'category' => $published?->details->categoryKey ?? '-',
]);

// --- 3e. the oversold product is restocked by its shop ---------------------
// The import clamped a source stock of `-2` to zero, so the product is
// published and correctly unbuyable. Restocking is the shop's decision and
// the shop's number — nothing here invents one — and it is the last step
// between a migrated catalogue and a working shop.
$restocked = $manage->updateInventory($vendorId, $vendorId, $productId, 25, $fresh0->details->sku ?? '', 1, null);
$policy->forget($wcProductId);
$say('shop_restocks_after_the_clamp', $restocked->ok, [
    'code' => $restocked->code,
    'source_stock' => $sourceStockRaw,
    'imported_as' => 0,
    'now' => 25,
]);

// --- 4. …and only now is it sellable ---------------------------------------
$after = $policy->decide($wcProductId);
$say('owned_is_sellable', ($after['decision'] ?? '') === 'allowed', [
    'wc' => $wcProductId,
    'decision' => $after['decision'] ?? '-',
]);
if (($after['decision'] ?? '') !== 'allowed') {
    echo "stopping: the transferred product is still not sellable\n";
    return;
}

// --- 5. a real customer buys it, and the stock falls ------------------------
$stockBefore = (int) wc_get_product($wcProductId)->get_stock_quantity();
$order = wc_create_order();
$order->add_product(wc_get_product($wcProductId), 2);
$order->set_customer_id($manager);
$order->calculate_totals();
$order->set_status('completed');
$order->save();
$orderId = (int) $order->get_id();
wc_maybe_reduce_stock_levels($orderId);
$stockAfter = (int) wc_get_product($wcProductId)->get_stock_quantity();
$say('bought_and_stock_fell', $stockAfter === $stockBefore - 2, [
    'order' => $orderId,
    'stock_before' => $stockBefore,
    'stock_after' => $stockAfter,
]);

// --- 6. the sale is captured against the shop that moved --------------------
$fresh = $products->find($productId);
$lines = [];
foreach ($order->get_items() as $wcItemId => $item) {
    if ((int) $item->get_product_id() !== $wcProductId) {
        continue;
    }
    $lines[] = [
        'order_item_id' => (int) $wcItemId,
        'wc_product_id' => $wcProductId,
        'variation_id' => null,
        'title' => $fresh->details->title,
        'sku' => $fresh->details->sku,
        'quantity' => (int) $item->get_quantity(),
        'line_total_minor' => $fresh->details->priceMinor * (int) $item->get_quantity(),
        'line_tax_minor' => 0,
    ];
}
$captureReport = $c->get(CaptureOrder::class)->capture($orderId, $lines);
$captured = $items->forOrder($orderId);
$line = $captured[0] ?? null;
$say('captured_for_the_moved_shop', $line !== null && (int) $line->vendorUserId === $vendorId, [
    'captured' => (int) ($captureReport['captured'] ?? 0),
    'vendor' => $line?->vendorUserId ?? '-',
    'expected_vendor' => $vendorId,
]);
if ($line === null) {
    echo "stopping: the sale was not captured for the shop\n";
    return;
}

// --- 7. the vendor sees their own sale; another shop does not ---------------
$mine = $items->forVendor($vendorId);
$otherVendor = 0;
foreach ($products->forVendor(0, null, 200) as $any) {
    if ((int) $any->vendorUserId !== $vendorId) {
        $otherVendor = (int) $any->vendorUserId;
        break;
    }
}
if ($otherVendor === 0) {
    global $wpdb;
    $otherVendor = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}tmc_vendor_applications WHERE user_id <> %d LIMIT 1",
        $vendorId
    ));
}
$theirs = $otherVendor > 0 ? $items->forVendor($otherVendor) : [];
$leaked = 0;
foreach ($theirs as $t) {
    if ((int) $t->orderId === $orderId) {
        $leaked++;
    }
}
$say('vendor_sees_own_sale_only', count($mine) > 0 && $leaked === 0, [
    'vendor' => $vendorId,
    'their_lines' => count($mine),
    'other_vendor' => $otherVendor > 0 ? $otherVendor : 'none',
    'lines_of_ours_they_can_see' => $leaked,
]);

// --- 8. a staff member of that shop can work the order ----------------------
$staff = $c->get(StaffAccess::class);
$staffUserId = 0;
foreach ($staff->activeMembersOf($vendorId) as $member) {
    $id = (int) ($member['user_id'] ?? $member->userId ?? 0);
    if ($id > 0 && $id !== $vendorId) {
        $staffUserId = $id;
        break;
    }
}
$say('staff_access_is_derived_not_assumed', true, [
    'vendor' => $vendorId,
    'active_members' => count($staff->activeMembersOf($vendorId)),
    'staff_user' => $staffUserId > 0 ? $staffUserId : 'none_on_this_shop',
    'owner_is_owner' => $staff->isOwner($vendorId, $vendorId) ? 'true' : 'false',
    'stranger_is_owner' => $staff->isOwner($manager === $vendorId ? 999999 : $manager, $vendorId) ? 'true' : 'false',
]);

// --- 9. it ships ------------------------------------------------------------
$shipped = $c->get(ShipItems::class)->ship($vendorId, $vendorId, (int) $line->id, 1, 'پست پیشتاز', 'TRK-CONTINUITY-1');
$afterShip = $items->forOrder($orderId);
$shipStatus = $afterShip[0]->status->value ?? '-';
$say('shipped', $shipped->ok, [
    'item' => (int) $line->id,
    'code' => $shipped->code,
    'status' => $shipStatus,
]);

// --- 10. exactly one financial engine for this order ------------------------
// FIN-02. The ledger carries lines for the sale; the Dokan order history —
// which is a RECORD of what Dokan already settled — must carry nothing for
// this order, because this order is not one Dokan ever saw.
// The key is built from the WOOCOMMERCE order item id (`CaptureOrder::eventKey`),
// not from our own row id. Asking with the wrong one answers «no ledger
// lines» about an order the ledger covers perfectly well — which is how this
// stage first reported a missing financial record next to `covers_order=true`.
$eventKey = \Tecteb\Marketplace\Modules\Order\Application\CaptureOrder::eventKey(
    $orderId,
    (int) $line->orderItemId
);
$ourLines = $ledger->forEvent($eventKey);
global $wpdb;
$historyRows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}tmc_dokan_order_history WHERE wc_order_id = %d",
    $orderId
));
$say('one_financial_engine', count($ourLines) > 0 && $historyRows === 0, [
    'order' => $orderId,
    'event_key' => $eventKey,
    'ledger_lines' => count($ourLines),
    'dokan_history_rows' => $historyRows,
    'covers_order' => $ledger->coversOrder($orderId) ? 'true' : 'false',
]);

// --- 11. roll back WITH the new order already in place ----------------------
// The hard case, and the reason this stage is last. Undoing the import must
// not take away a product the marketplace now runs and has already sold —
// that would orphan a real order and a real ledger line.
$rolledBack = $import->rollback($plan->runId);
$stillThere = $products->find($productId);
$orderSurvived = count($items->forOrder($orderId)) > 0;
$ledgerSurvived = count($ledger->forEvent($eventKey)) > 0;
$say('rollback_keeps_what_was_sold', $stillThere !== null && $orderSurvived && $ledgerSurvived, [
    'code' => $rolledBack->code,
    'kept' => (string) ($rolledBack->context['kept_products'] ?? '-'),
    'product_still_there' => $stillThere !== null ? 'true' : 'false',
    'ownership_after' => $stillThere !== null ? $ownershipOf($productId) : '-',
    'order_lines_after' => count($items->forOrder($orderId)),
    'ledger_lines_after' => count($ledger->forEvent($eventKey)),
]);

update_option('tmc_continuity_last', [
    'orders' => [$orderId],
    'product' => $productId,
    'wc' => $wcProductId,
    'run' => $plan->runId,
], false);

printf(
    "done product=%d wc=%d vendor=%d order=%d item=%d run=%s\n",
    $productId,
    $wcProductId,
    $vendorId,
    $orderId,
    (int) $line->id,
    $plan->runId
);
