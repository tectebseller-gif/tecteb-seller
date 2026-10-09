<?php
/**
 * A product written by whichever build is installed, for the 22 → 23 check.
 *
 * Schema 23 adds two nullable columns to `tmc_products` and seeds NOTHING, so
 * the upgrade check needs a row that the OLD build really wrote — through the
 * plugin's own repository, never with an `INSERT` behind the code's back (the
 * `alpha.17` rule). A row this file typed would make stage 4 a measurement of
 * this file.
 *
 * `DbProductRepository::create()` grew a `$createToken` argument this round, so
 * it is called with REFLECTION ARITY: the same invocation has to be legal on
 * `alpha.40`, where the parameter does not exist. That is `alpha.40`'s fourth
 * rule, which broke the previous round's evidence tool on the very bytes it
 * existed to measure.
 *
 * It PRINTS what the shell asserts against, so the numbers live in one place
 * (the `alpha.22` rule) — including the product id, which the shell reads out
 * rather than spelling. ONE key per line: the shell's reader takes the rest of
 * the line as the value, so two keys on one line made every id «1229 reused=0»
 * and every comparison after it a string mismatch about a correct site.
 *
 *   wp eval-file tools/description-state.php seed    # one product, no long text
 *   wp eval-file tools/description-state.php write   # give it a long description
 *   wp eval-file tools/description-state.php report  # what the install holds
 *   wp eval-file tools/description-state.php reset   # give back what it spent
 */

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES product rows. On the owner's site that is not a fixture.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases. This tool writes product rows and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes product rows and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

const TMC_DESC_VENDOR = 8801;
const TMC_DESC_SKU = 'SKU-DESC-STATE';

$command = (string) ($args[0] ?? 'report');
$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$products = new DbProductRepository($db, $clock);

/** The details both builds can build: `description` is passed only if it exists. */
$detailsOf = static function (?string $long): ProductDetails {
    $named = [
        'title' => 'محصول سندِ ارتقا',
        'categoryKey' => 'gloves',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 200000,
        'sku' => TMC_DESC_SKU,
        'stock' => 5,
    ];
    if ($long !== null && property_exists(ProductDetails::class, 'description')) {
        $named['description'] = $long;
    }
    return new ProductDetails(...$named);
};

$findMine = static function () use ($products): ?int {
    foreach ($products->forVendor(TMC_DESC_VENDOR) as $product) {
        if ($product->details->sku === TMC_DESC_SKU) {
            return $product->id;
        }
    }
    return null;
};

/** `description` as stored, told apart from «no such column on this build». */
$longOf = static function (int $productId) use ($products): string {
    if (!property_exists(ProductDetails::class, 'description')) {
        return 'no-field';
    }
    $product = $products->find($productId);
    if ($product === null) {
        return 'no-product';
    }
    return $product->details->description === null ? 'null' : $product->details->description;
};

switch ($command) {
    case 'seed':
        $existing = $findMine();
        if ($existing !== null) {
            echo 'product=' . $existing . "\n";
            echo "reused=1\n";
            echo 'long=' . $longOf($existing) . "\n";
            break;
        }
        $create = new ReflectionMethod(DbProductRepository::class, 'create');
        $args2 = [TMC_DESC_VENDOR, $detailsOf(null), ProductStatus::Draft];
        // Fill whatever this build's `create()` declares beyond the two it has
        // always had, with that parameter's own default — so the call is legal
        // on both builds without this file knowing their names.
        foreach (array_slice($create->getParameters(), count($args2)) as $parameter) {
            $args2[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }
        $made = (int) $create->invokeArgs($products, $args2);
        echo 'product=' . $made . "\n";
        echo "reused=0\n";
        echo 'long=' . ($made > 0 ? $longOf($made) : 'no-product') . "\n";
        break;

    case 'write':
        $productId = $findMine();
        if ($productId === null) {
            echo "product=0\n";
            echo "wrote=0\nreason=no_product\n";
            break;
        }
        if (!property_exists(ProductDetails::class, 'description')) {
            echo 'product=' . $productId . "\n";
            echo "wrote=0\nreason=no_field\n";
            break;
        }
        $ok = $products->updateDetails(
            $productId,
            $detailsOf('<p>متن کاملِ فروشنده</p>'),
            $products->rowVersion($productId)
        );
        echo 'product=' . $productId . "\n";
        echo 'wrote=' . ($ok ? 1 : 0) . "\n";
        echo 'long=' . $longOf($productId) . "\n";
        break;

    case 'report':
        $productId = $findMine();
        echo 'product=' . ($productId ?? 0) . "\n";
        echo 'long=' . ($productId === null ? 'no-product' : $longOf($productId)) . "\n";
        echo 'has_field=' . (property_exists(ProductDetails::class, 'description') ? 1 : 0) . "\n";
        break;

    case 'reset':
        $productId = $findMine();
        if ($productId === null) {
            echo "removed=0\n";
            break;
        }
        // Through the repository, so whatever rows hang off a product go with
        // it rather than being left for the next run to measure.
        echo 'removed=' . ($products->deleteDraft($productId) ? 1 : 0) . "\n";
        break;

    default:
        echo "usage: seed|write|report|reset\n";
}
