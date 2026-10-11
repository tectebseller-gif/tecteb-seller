<?php
/**
 * A product written by whichever build is installed, for the 23 → 24 check.
 *
 * Schema 24 adds ONE column to `tmc_products` — `purchase_step INT NOT NULL
 * DEFAULT 1` — and seeds nothing, because the DEFAULT is the backfill: `1`
 * means «no stepping», which is exactly what every existing row does today.
 * So the upgrade check needs a row the OLD build really wrote, through the
 * plugin's own repository rather than with an `INSERT` behind the code's back
 * (the `alpha.17` rule). A row this file typed would make the backfill stage a
 * measurement of this file.
 *
 * `ProductDetails` gained its seventeenth promoted parameter this round and
 * `updateInventory()` a sixth argument, so both are called by REFLECTION
 * ARITY: the same invocation has to be legal on `alpha.44`, where neither
 * exists. That is `alpha.40`'s fourth rule, which broke the previous round's
 * evidence tool on the very bytes it existed to measure.
 *
 * ONE key per line — the shell's reader takes the rest of the line as the
 * value (`alpha.41`).
 *
 *   wp eval-file tools/purchase-step-state.php seed    # one product, default step
 *   wp eval-file tools/purchase-step-state.php write   # set min 2, max 6, step 2
 *   wp eval-file tools/purchase-step-state.php report  # what the install holds
 *   wp eval-file tools/purchase-step-state.php reset   # give back what it spent
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

const TMC_STEP_VENDOR = 8806;
const TMC_STEP_SKU = 'SKU-STEP-STATE';

$command = (string) ($args[0] ?? 'report');
$db = new WpDatabase($GLOBALS['wpdb']);
$products = new DbProductRepository($db, new SystemClock());

/** Named arguments only, so a build without the field is never handed it. */
$detailsOf = static function (int $min, ?int $max, ?int $step): ProductDetails {
    $named = [
        'title' => 'محصول سندِ گام',
        'categoryKey' => 'gloves',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 200000,
        'sku' => TMC_STEP_SKU,
        'stock' => 20,
        'minPurchase' => $min,
        'maxPurchase' => $max,
    ];
    if ($step !== null && property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = $step;
    }
    return new ProductDetails(...$named);
};

$findMine = static function () use ($products): ?int {
    foreach ($products->forVendor(TMC_STEP_VENDOR) as $product) {
        if ($product->details->sku === TMC_STEP_SKU) {
            return $product->id;
        }
    }
    return null;
};

/** What the install HOLDS, with «this build has no such field» told apart. */
$reportOn = static function (int $productId) use ($products): void {
    $product = $products->find($productId);
    echo 'product=' . $productId . "\n";
    if ($product === null) {
        echo "found=0\n";
        return;
    }
    echo "found=1\n";
    echo 'min=' . $product->details->minPurchase . "\n";
    echo 'max=' . ($product->details->maxPurchase === null ? 'null' : (string) $product->details->maxPurchase) . "\n";
    if (!property_exists(ProductDetails::class, 'purchaseStep')) {
        echo "has_field=0\n";
        echo "step=no-field\n";
        return;
    }
    echo "has_field=1\n";
    echo 'step=' . $product->details->purchaseStep . "\n";
};

/** `updateInventory()` with whatever arity this build declares. */
$writeInventory = static function (int $productId, int $min, ?int $max, int $step) use ($products): bool {
    $method = new ReflectionMethod(DbProductRepository::class, 'updateInventory');
    $call = [$productId, 20, TMC_STEP_SKU, $min, $max];
    foreach (array_slice($method->getParameters(), count($call)) as $parameter) {
        // The step if this build has a place for it, that parameter's own
        // default otherwise — so the call is legal on `alpha.44` without this
        // file knowing the parameter's name.
        $call[] = $parameter->getName() === 'purchaseStep'
            ? $step
            : ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null);
    }
    return (bool) $method->invokeArgs($products, $call);
};

switch ($command) {
    case 'seed':
        $existing = $findMine();
        if ($existing !== null) {
            echo "reused=1\n";
            $reportOn($existing);
            break;
        }
        $create = new ReflectionMethod(DbProductRepository::class, 'create');
        $call = [TMC_STEP_VENDOR, $detailsOf(1, null, null), ProductStatus::Draft];
        foreach (array_slice($create->getParameters(), count($call)) as $parameter) {
            $call[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }
        $made = (int) $create->invokeArgs($products, $call);
        echo "reused=0\n";
        $reportOn($made);
        break;

    case 'write':
        $productId = $findMine();
        if ($productId === null) {
            echo "product=0\n";
            echo "wrote=0\nreason=no_product\n";
            break;
        }
        // The IMMEDIATE path, which is where the step lives: it is written
        // straight through with the two numbers it works with, at every
        // status, and this is the write a vendor's own save performs.
        echo 'wrote=' . ($writeInventory($productId, 2, 6, 2) ? '1' : '0') . "\n";
        $reportOn($productId);
        break;

    case 'reset':
        $productId = $findMine();
        if ($productId === null) {
            echo "product=0\nreset=0\n";
            break;
        }
        $writeInventory($productId, 1, null, 1);
        echo "reset=1\n";
        $reportOn($productId);
        break;

    case 'report':
    default:
        $productId = $findMine();
        if ($productId === null) {
            echo "product=0\nfound=0\n";
            break;
        }
        $reportOn($productId);
}
