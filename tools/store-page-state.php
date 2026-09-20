<?php
/**
 * Drives the shop's public page and its temporary closure, through the
 * plugin's own services on a DISPOSABLE WordPress.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/store-page-state.php seed <vendor-id>
 *   wp eval-file tools/store-page-state.php close|reopen <vendor-id>
 *   wp eval-file tools/store-page-state.php suspend|reinstate <vendor-id>
 *   wp eval-file tools/store-page-state.php buyable <vendor-id>
 *   wp eval-file tools/store-page-state.php ongoing <vendor-id>
 *   wp eval-file tools/store-page-state.php catalogue <vendor-id> [how-many]
 *   wp eval-file tools/store-page-state.php catalogue-count|catalogue-clear <vendor-id>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Presentation\PurchaseMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file WRITES test data — shops, products, orders, refunds. On the
// owner's site that is not a seeding tool, it is damage. A docblock saying
// «DISPOSABLE» is documentation, not a guard: it stops nobody who pastes the
// command at the wrong shell.
//
// Two independent facts, the same pair tools/disposable-site.sh already
// trusts: the database must be the disposable one by name, and the site must
// be on a host nobody outside the container can reach. Deliberately NOT
// wp_get_environment_type(), which reports `production` on the disposable
// container itself because nobody set the constant.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


/** Persian digits for the demo titles; the plugin has its own PersianDigits. */
function tmc_fa_digits(string $value): string
{
    return strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

$command = (string) ($args[0] ?? '');
$vendorId = (int) ($args[1] ?? 0);
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

$stores = $c->get(StoreRepositoryInterface::class);
$products = $c->get(ProductRepositoryInterface::class);

/** The shop's first projected product, which is what «buyable» asks about. */
$firstProduct = static function (int $vendorUserId) use ($products): int {
    foreach ($products->allForVendor($vendorUserId) as $product) {
        if ((int) ($product->wcProductId ?? 0) > 0) {
            return (int) $product->wcProductId;
        }
    }
    return 0;
};

switch ($command) {
    case 'seed':
        // REAL values in every private field. The privacy checks assert these
        // exact strings are absent from the page — and a page that omits an
        // empty field proves nothing, so they have to be filled first.
        $current = $stores->find($vendorId) ?? new StoreSettings();
        $stores->save($vendorId, new StoreSettings(
            'فروشگاه آزمایشی نمایش عمومی',
            'اصفهان',
            'معرفی آزمایشی فروشگاه برای صفحهٔ عمومی.',
            $current->logoId,
            $current->bannerId,
            3,
            'انبار خصوصی خیابان شماره ۱۲، پلاک ۴',
            $current->carriers,
            false,
            null,
            null,
            '',
            ['instagram' => 'https://example.test/tecteb-shop']
        ));
        // The applicant's own contact details live on the application, not the
        // store row — written here so their absence from the page is measured
        // too, not assumed from the fact that this page never queries them.
        update_user_meta($vendorId, 'tmc_probe_applicant_email', 'private-store@example.test');
        update_user_meta($vendorId, 'tmc_probe_applicant_mobile', '09121112233');
        update_user_meta($vendorId, 'tmc_probe_applicant_address', 'نشانی شخصی متقاضی');
        update_user_meta($vendorId, 'tmc_probe_iban', 'IR120000000000000000000000');
        // Back on the shelf, if an earlier run took it off.
        //
        // «آزمونی که چیزی را خرج می‌کند باید اول reset کند»: section 5 asks
        // whether this shop's product may be bought, and a suspension in an
        // earlier run leaves every product in `draft`, where reinstatement
        // deliberately does NOT put it back (F-14). Without this the closure
        // section measures `not_published` and reports it as a closure
        // failure — the wrong thing, failing for the right reason.
        $republished = '-';
        $manageForSeed = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);
        $reviewForSeed = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ReviewProducts::class);
        foreach ($products->allForVendor($vendorId) as $candidate) {
            if ((int) ($candidate->wcProductId ?? 0) <= 0) {
                continue;
            }
            if ($candidate->status === \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published) {
                $republished = 'already';
                break;
            }
            if ($candidate->status === \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Archived) {
                $manageForSeed->restore($vendorId, $vendorId, $candidate->id);
            }
            $submitted = $manageForSeed->submit($vendorId, $vendorId, $candidate->id);
            $republished = $submitted->ok ? 'restored' : $submitted->code;
            if ($submitted->ok) {
                $reviewForSeed->approve($candidate->id);
            }
            break;
        }
        $saved = $stores->find($vendorId);
        printf(
            "seed vendor=%d store=%s city=%s warehouse=%s shelf=%s\n",
            $vendorId,
            ($saved?->storeName ?? '') === '' ? 'فروشگاه بازارگاه تک‌طب' : $saved->storeName,
            $saved?->city ?? '-',
            ($saved?->originWarehouse ?? '') === '' ? '-' : 'set',
            $republished
        );
        break;

    case 'close':
    case 'reopen':
        $current = $stores->find($vendorId) ?? new StoreSettings();
        $closing = $command === 'close';
        $stores->save($vendorId, new StoreSettings(
            $current->storeName,
            $current->city,
            $current->intro,
            $current->logoId,
            $current->bannerId,
            $current->preparationDays,
            $current->originWarehouse,
            $current->carriers,
            $closing,
            $closing ? gmdate('Y-m-d', strtotime('-1 day')) : null,
            $closing ? gmdate('Y-m-d', strtotime('+7 days')) : null,
            $closing ? 'تا هفتهٔ آینده برمی‌گردیم.' : '',
            $current->social
        ));
        $now = gmdate('Y-m-d');
        printf(
            "%s vendor=%d closed=%s today=%s\n",
            $command,
            $vendorId,
            $stores->find($vendorId)?->isClosedOn($now) ? 'true' : 'false',
            $now
        );
        break;

    case 'suspend':
    case 'reinstate':
        // Through the application, because that is what «تعلیق فروشنده» is:
        // the marketplace withdrawing the yes it gave, not a flag on a store.
        $application = $c->get(VendorRepositoryInterface::class)->findApplicationByUser($vendorId);
        if ($application === null) {
            echo "no application for that vendor\n";
            break;
        }
        $service = $c->get(ReviewApplication::class);
        $result = $command === 'suspend'
            ? $service->suspend($application->id, 'آزمون صفحهٔ عمومی')
            : $service->reinstate($application->id);
        printf("%s vendor=%d ok=%s code=%s\n", $command, $vendorId, $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'buyable':
        // Asked of the policy itself, not of a page: what a shopper is told is
        // the SAME answer the cart and the checkout get.
        $wcProductId = $firstProduct($vendorId);
        $decision = $c->get(PurchasePolicy::class)->decide($wcProductId);
        printf(
            "buyable vendor=%d product=%d decision=%s message=%s\n",
            $vendorId,
            $wcProductId,
            $decision['decision'],
            str_replace(' ', '_', (string) PurchaseMessages::shopper($decision['decision']))
        );
        break;

    case 'ongoing':
        // A.5: «سفارش‌های قبلی باید ادامه یابند». The order module is never
        // asked about closure, and this proves it rather than asserting it: a
        // line captured before the shop closed is shipped while it is closed.
        // The line must have something LEFT to ship. «Placed» is not enough:
        // a previous run of this fixture already shipped the whole quantity of
        // the last open line, so the next run asked to ship one more unit of a
        // line with none remaining and read `quantity_exceeds_remaining` as
        // «a closed shop stops an ongoing order» — the opposite of the fact
        // under test, and a fixture measuring its own leftovers.
        $items = $c->get(OrderItemRepositoryInterface::class);
        $ship = $c->get(ShipItems::class);
        $line = null;
        foreach ($items->forVendor($vendorId, null, 50) as $candidate) {
            if ($candidate->status !== OrderItemStatus::Placed && $candidate->status !== OrderItemStatus::Preparing) {
                continue;
            }
            // Asked of `ShipItems::remaining()`, the same function the service
            // itself uses. Counting shipments alone was not enough: item 58
            // had none and still had nothing left, because two REFUNDED
            // returns had taken both its units. The fixture then read
            // `quantity_exceeds_remaining` as «a closed shop stops an ongoing
            // order» — the opposite of the fact under test.
            if ($ship->remaining($candidate->id, $candidate->quantity) >= 1) {
                $line = $candidate;
            }
        }
        if ($line === null) {
            echo "ongoing ok=false shipped=false reason=no_open_line_with_stock_left\n";
            break;
        }
        $result = $ship->ship($vendorId, $vendorId, $line->id, 1, 'پست پیشتاز', 'TRK-CLOSED-' . $line->id);
        $after = $items->find($line->id);
        printf(
            "ongoing ok=%s shipped=%s item=%d status=%s code=%s\n",
            $result->ok ? 'true' : 'false',
            $after !== null && $after->status !== OrderItemStatus::Placed ? 'true' : 'false',
            $line->id,
            $after?->status->value ?? '-',
            $result->code
        );
        break;

    case 'catalogue':
        // A catalogue big enough to PAGE. The test shop has two products, so
        // every paging rule in StorePage — prev/next, the 404 past the end,
        // the self-canonical — has until now been code nobody could see run.
        //
        // Published AND projected, because the page lists only what has a
        // WooCommerce id to link to: a shelf of thirty published-but-
        // unprojected rows would render an empty page two and prove the
        // opposite of what it looks like it proves.
        $want = max(1, (int) ($args[2] ?? 30));
        $manage = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);
        $review = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ReviewProducts::class);
        $images = new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpProductImages(
            new \Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy()
        );
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAWklEQVR42u3QMQEAAAgDoC252H'
            . 'zAR4dAqz9zAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIEHgtL'
            . 'jkAAdSPbCEAAAAASUVORK5CYII='
        );
        // One attachment, reused. Thirty identical PNGs would measure the
        // media library, which is not what this is about.
        $tmp = wp_tempnam('tmc-page-fixture');
        file_put_contents($tmp, $png);
        $stored = $images->store(
            new \Tecteb\Marketplace\Contracts\Files\UploadedFile('tmc-page-fixture.png', $tmp, strlen($png), 'image/png'),
            $vendorId,
            'tmc-page-fixture'
        );
        $mediaId = (int) ($stored['media_id'] ?? 0);

        // Reused, not re-created. A SKU stays taken after a row is archived —
        // `sku_taken` is asked of the whole shop, not of the shelf — so a
        // second run of this fixture would create nothing and then report
        // «made=0» as though the seeding code were broken. Which is exactly
        // what the first version of this did.
        $existing = [];
        foreach ($products->allForVendor($vendorId) as $candidate) {
            if (str_starts_with($candidate->details->sku, 'TMC-PAGE-')) {
                $existing[$candidate->details->sku] = $candidate;
            }
        }

        // Named after real equipment, in the order `demo-images` paints it,
        // so the walkthrough shows a medical shop rather than thirty rows of
        // «کالای شمارهٔ ۱۲». The shelf is still demo data and still lives only
        // on the disposable site — but a preview meant to show the DESIGN has
        // to look like the thing it stands for.
        $catalogue = [
            ['گوشی پزشکی دوکاره', 'مدلاین', 'دیافراگم و زنگ، شیلنگ دوکاناله، قابل استفاده برای بزرگسال و کودک.'],
            ['فشارسنج بازویی دیجیتال', 'تک‌طب', 'اندازه‌گیری خودکار فشار و نبض با حافظهٔ دو کاربره و بازوبند استاندارد.'],
            ['سرنگ یک‌بارمصرف ۵ سی‌سی', 'پارس‌طب', 'بستهٔ ۱۰۰ عددی، استریل، با سرسوزن جداشدنی.'],
            ['تب‌سنج دیجیتال نوک‌نرم', 'مدلاین', 'اندازه‌گیری در ۱۰ ثانیه، ضدآب، با حافظهٔ آخرین دما.'],
            ['پالس‌اکسیمتر انگشتی', 'تک‌طب', 'نمایش اشباع اکسیژن و ضربان با نمودار موج، مناسب بزرگسال.'],
            ['ماسک سه‌لایه جراحی', 'پارس‌طب', 'بستهٔ ۵۰ عددی، ملت‌بلون داخلی، کِشِ گوشی نرم.'],
        ];

        $made = 0;
        $listed = 0;
        for ($n = 1; $n <= $want; $n++) {
            $sku = sprintf('TMC-PAGE-%03d', $n);
            [$name, $brand, $blurb] = $catalogue[($n - 1) % count($catalogue)];
            $batch = (int) ceil($n / count($catalogue));
            $details = new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
                // The batch suffix keeps thirty rows distinguishable without
                // turning the title back into a number.
                title: $batch > 1 ? $name . ' — سری ' . tmc_fa_digits((string) $batch) : $name,
                categoryKey: 'gloves',
                brand: $brand,
                shortDescription: $blurb,
                priceMinor: 100000 + ($n * 1000),
                sku: $sku,
                stock: 10
            );
            $known = $existing[$sku] ?? null;
            if ($known === null) {
                $created = $manage->save($vendorId, $vendorId, 0, $details);
                $id = (int) ($created->context['product_id'] ?? 0);
                if ($id === 0) {
                    printf("catalogue stopped at %d code=%s\n", $n, $created->code);
                    break;
                }
            } else {
                $id = $known->id;
                if ($known->status === \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Archived) {
                    $manage->restore($vendorId, $vendorId, $id);
                }
            }
            // The row's CURRENT counter, read back rather than left empty.
            //
            // A save on an existing product carries an optimistic-lock token
            // and `alpha.14` refuses a blank one — correctly: a form with no
            // stamp is a form from a build that predates the counter, and
            // waving it through is how one editor's work vanishes. The
            // fixture had been passing nothing and then ignoring the result,
            // so every re-seed silently kept the PREVIOUS titles and reported
            // «made=30». «بررسی‌ای که اجرا نشود شبیه بررسی‌ای است که رد نشده».
            $withImages = $manage->save(
                $vendorId,
                $vendorId,
                $id,
                $details,
                ['material' => 'لاتکس'],
                [$mediaId],
                $mediaId,
                $products->rowVersion($id)
            );
            if (!$withImages->ok) {
                printf("catalogue stopped at %d save_code=%s media=%d\n", $n, $withImages->code, $mediaId);
                break;
            }
            $after = $products->find($id);
            if ($after?->status !== \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published) {
                $submitted = $manage->submit($vendorId, $vendorId, $id);
                if (!$submitted->ok) {
                    printf("catalogue stopped at %d code=%s\n", $n, $submitted->code);
                    break;
                }
                $review->approve($id);
            }
            $made++;
            $after = $products->find($id);
            if ($after !== null && (int) ($after->wcProductId ?? 0) > 0) {
                $listed++;
            }
        }
        $total = $products->countForVendor($vendorId, \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published);
        printf(
            "catalogue vendor=%d made=%d projected=%d published_total=%d\n",
            $vendorId,
            $made,
            $listed,
            $total
        );
        break;

    case 'catalogue-count':
        // Read back from the same repository the page reads, so the expected
        // page count in the evidence is not a number somebody typed.
        $total = $products->countForVendor($vendorId, \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published);
        $perPage = \Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\StorePage::PER_PAGE;
        printf(
            "catalogue-count vendor=%d published=%d per_page=%d pages=%d\n",
            $vendorId,
            $total,
            $perPage,
            max(1, (int) ceil($total / $perPage))
        );
        break;

    case 'catalogue-clear':
        // Named by SKU prefix, not «everything»: the shop's own two products
        // belong to the other sections of this evidence and must survive.
        $removed = 0;
        foreach ($products->allForVendor($vendorId) as $product) {
            if (!str_starts_with($product->details->sku, 'TMC-PAGE-')) {
                continue;
            }
            $c->get(\Tecteb\Marketplace\Modules\Product\Application\SyncCatalog::class)->withdraw($product->id);
            $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class)
                ->archive($vendorId, $vendorId, $product->id);
            $removed++;
        }
        printf("catalogue-clear vendor=%d removed=%d\n", $vendorId, $removed);
        break;

    case 'catalogue-purge':
        // `catalogue-clear` ARCHIVES, which is the right verb for a plugin
        // and the wrong one for a fixture that wants different rows next
        // time: an archived row keeps its SKU, so the next seeding run finds
        // it, restores it — and then cannot rename it, because renaming a
        // published product is a proposed revision, not an edit (the rule
        // from `alpha.6`). The catalogue silently kept last month's titles.
        //
        // So this removes the rows outright. A fixture doing fixture things
        // on a disposable site: there is deliberately no «delete a published
        // product» service, and this is not one.
        global $wpdb;
        $purged = 0;
        foreach ($products->allForVendor($vendorId) as $product) {
            if (!str_starts_with($product->details->sku, 'TMC-PAGE-')) {
                continue;
            }
            $c->get(\Tecteb\Marketplace\Modules\Product\Application\SyncCatalog::class)->withdraw($product->id);
            $wc = (int) ($product->wcProductId ?? 0);
            if ($wc > 0 && ($wcProduct = wc_get_product($wc))) {
                $wcProduct->delete(true);
            }
            foreach (['tmc_product_images', 'tmc_product_specs', 'tmc_product_revisions'] as $table) {
                $wpdb->delete($wpdb->prefix . $table, ['product_id' => $product->id]);
            }
            $wpdb->delete($wpdb->prefix . 'tmc_products', ['id' => $product->id]);
            $purged++;
        }
        printf("catalogue-purge vendor=%d purged=%d\n", $vendorId, $purged);
        break;

    default:
        echo "unknown command\n";
        break;
}
