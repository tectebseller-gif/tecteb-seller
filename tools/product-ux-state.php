<?php
/**
 * Autosave drafts, revision conflict and bulk operations — exercised through
 * the plugin's own services on the disposable WordPress.
 *
 *   draft-put <user> <product>      keep a draft, then read it back
 *   draft-forget <user> <product>   what a successful save does to it
 *   conflict <user> <vendor> <product>  two editors, one product
 *   bulk <user> <vendor> <action> <ids…>  a batch with a refused row in it
 *   ids                             a vendor, a staff user and some products
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductDraftStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

if (DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refusing: not the disposable database\n");
    exit(2);
}

$command = (string) ($args[0] ?? 'ids');
$container = Bootstrap::container();
/** @var ProductRepositoryInterface $products */
$products = $container->get(ProductRepositoryInterface::class);
/** @var ProductDraftStoreInterface $drafts */
$drafts = $container->get(ProductDraftStoreInterface::class);
/** @var ManageProducts $manage */
$manage = $container->get(ManageProducts::class);

switch ($command) {
    case 'ids':
        global $wpdb;
        $row = $wpdb->get_row('SELECT vendor_user_id FROM `' . $wpdb->prefix . 'tmc_products` LIMIT 1', ARRAY_A);
        $vendor = (int) ($row['vendor_user_id'] ?? 0);
        echo 'vendor=', $vendor, "\n";
        $ids = [];
        foreach ($products->allForVendor($vendor) as $product) {
            $ids[] = $product->id . ':' . $product->status->value;
        }
        echo 'products=', implode(',', $ids), "\n";
        break;

    case 'draft-put':
        $user = (int) ($args[1] ?? 0);
        $product = (int) ($args[2] ?? 0);
        $ok = $drafts->put($user, $product, ['title' => 'عنوانِ نیمه‌تمام', 'price' => '123000'], 'rev-a');
        $back = $drafts->get($user, $product);
        echo 'stored=', $ok ? '1' : '0', "\n";
        echo 'read_back=', $back === null ? 'null' : ($back['payload']['title'] ?? '-'), "\n";
        echo 'revision=', $back['revision'] ?? '-', "\n";
        // A draft belongs to ONE person: another member of the same shop must
        // not see it, or autosave becomes the silent overwrite the revision
        // check exists to stop, one step earlier.
        echo 'visible_to_another_user=', $drafts->get($user + 1000, $product) === null ? 'no' : 'YES', "\n";
        break;

    case 'draft-forget':
        $user = (int) ($args[1] ?? 0);
        $product = (int) ($args[2] ?? 0);
        $drafts->put($user, $product, ['title' => 'x'], 'rev-a');
        echo 'before=', $drafts->get($user, $product) === null ? 'absent' : 'present', "\n";
        $drafts->forget($user, $product);
        echo 'after=', $drafts->get($user, $product) === null ? 'absent' : 'present', "\n";
        echo 'forget_is_idempotent=', $drafts->forget($user, $product) ? 'yes' : 'no', "\n";
        break;

    case 'conflict':
        $user = (int) ($args[1] ?? 0);
        $vendor = (int) ($args[2] ?? 0);
        $productId = (int) ($args[3] ?? 0);
        wp_set_current_user($user);
        $product = $products->findOwned($productId, $vendor);
        if ($product === null) {
            echo "conflict=no_such_product\n";
            break;
        }
        $stamp = $product->updatedAt;
        echo 'revision_on_the_form=', $stamp, "\n";

        // Editor A saves. Their stamp matches, so it goes through.
        $a = $manage->save($user, $vendor, $productId, $product->details, $product->specs,
            $product->imageIds, $product->mainImageId, $stamp);
        echo 'editor_a=', $a->ok ? 'saved' : ('refused:' . $a->code), "\n";

        // Editor B had the page open since before that, so their form still
        // carries the OLD stamp.
        $b = $manage->save($user, $vendor, $productId, $product->details, $product->specs,
            $product->imageIds, $product->mainImageId, $stamp);
        echo 'editor_b=', $b->ok ? 'saved' : ('refused:' . $b->code), "\n";
        echo 'both_stamps_reported=', (isset($b->context['submitted_revision'], $b->context['current_revision']) ? 'yes' : 'no'), "\n";

        // An EMPTY stamp still saves: a form from an older build has nothing to
        // compare, and refusing it would break saving across an upgrade.
        $fresh = $products->findOwned($productId, $vendor);
        $c = $manage->save($user, $vendor, $productId, $fresh->details, $fresh->specs,
            $fresh->imageIds, $fresh->mainImageId, '');
        echo 'empty_stamp=', $c->ok ? 'saved' : ('refused:' . $c->code), "\n";
        break;

    case 'bulk':
        $user = (int) ($args[1] ?? 0);
        $vendor = (int) ($args[2] ?? 0);
        $action = (string) ($args[3] ?? 'submit');
        $ids = array_map('intval', array_slice($args, 4));
        wp_set_current_user($user);

        $result = $manage->bulk($user, $vendor, $action, $ids);
        echo 'ok=', $result->ok ? '1' : '0', ' code=', $result->code, "\n";
        echo 'succeeded=', $result->context['ok'] ?? 0, "\n";
        echo 'refused=', $result->context['failed'] ?? 0, "\n";
        foreach ($result->context['rows'] ?? [] as $row) {
            echo 'row product=', $row['product_id'], ' ok=', $row['ok'] ? '1' : '0', ' code=', $row['code'], "\n";
        }
        // Empty selection, unknown verb, and an oversized batch: three refusals
        // that must be three different codes.
        echo 'empty=', $manage->bulk($user, $vendor, $action, [])->code, "\n";
        echo 'unknown_verb=', $manage->bulk($user, $vendor, 'delete_everything', $ids)->code, "\n";
        echo 'too_large=', $manage->bulk($user, $vendor, $action, range(1, ManageProducts::BULK_LIMIT + 1))->code, "\n";
        break;

    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(2);
}
