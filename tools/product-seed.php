<?php
/**
 * Seeds the product stage on a DISPOSABLE WordPress, through the plugin's own
 * services — never with hand-written SQL.
 *
 * That is the point: if a seeded row exists, the code path that creates it
 * works on real WordPress, and the browser suite then measures the same rows
 * a vendor would have produced by hand.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval(), where the
 * declaration is a fatal error.
 *
 *   wp eval-file tools/product-seed.php <vendor-user-id>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ConfigureSpecTemplates;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpProductImages;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

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


$vendorId = (int) ($args[0] ?? 0);
if ($vendorId <= 0) {
    echo "usage: wp eval-file tools/product-seed.php <vendor-user-id>\n";
    return;
}
$c = Bootstrap::container();

// The manager the review calls act as. wp-cli has no current user, so the
// capability checker is pointed at an administrator for this run only.
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

/** A vendor who may sell, so StaffAccess answers yes. */
$c->get(VendorRepositoryInterface::class)->upsertProfile($vendorId, 'داروخانه نمونه تک‌طب', true, false);

/** One category template with a required field and a choice field. */
$templates = $c->get(ConfigureSpecTemplates::class);
$created = $templates->createTemplate('gloves', 'دستکش و ملزومات یکبارمصرف');
$templateId = (int) ($created->context['template_id'] ?? 0);
if ($templateId === 0) {
    $existing = $c->get(\Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface::class)
        ->findByCategory('gloves');
    $templateId = $existing?->id ?? 0;
}
$templates->addField($templateId, 'material', 'جنس', 'text', true, '', [], 1);
$templates->addField($templateId, 'size_mm', 'اندازه', 'number', false, 'mm', [], 2);
$templates->addField($templateId, 'sterile', 'استریل', 'boolean', false, '', [], 3);
$templates->addField($templateId, 'grade', 'رده', 'choice', false, '', ['A', 'B'], 4);

/** A real attachment, owned by this shop, so the gallery rule is satisfied. */
$images = new WpProductImages(new \Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy());
$makeImage = static function (string $name) use ($images, $vendorId) {
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAWklEQVR42u3QMQEAAAgDoC252H'
        . 'zAR4dAqz9zAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIEHgtL'
        . 'jkAAdSPbCEAAAAASUVORK5CYII='
    );
    $tmp = wp_tempnam($name);
    file_put_contents($tmp, $png);
    $upload = new \Tecteb\Marketplace\Contracts\Files\UploadedFile($name . '.png', $tmp, strlen($png), 'image/png');
    $stored = $images->store($upload, $vendorId, $name);
    return (int) $stored['media_id'];
};

$manage = $c->get(ManageProducts::class);
$review = $c->get(ReviewProducts::class);
$products = $c->get(ProductRepositoryInterface::class);

$make = static function (string $title, string $sku, int $price, int $stock, array $specs) use ($manage, $vendorId, $makeImage) {
    $created = $manage->save($vendorId, $vendorId, 0, new ProductDetails(
        title: $title,
        categoryKey: 'gloves',
        brand: 'تک‌طب',
        shortDescription: 'نمونه داده آزمایشی برای پیش‌نمایش رابط.',
        priceMinor: $price,
        sku: $sku,
        stock: $stock
    ));
    $id = (int) ($created->context['product_id'] ?? 0);
    if ($id === 0) {
        return 0;
    }
    $mediaId = $makeImage('tmc-' . $sku);
    $manage->save($vendorId, $vendorId, $id, new ProductDetails(
        title: $title,
        categoryKey: 'gloves',
        brand: 'تک‌طب',
        shortDescription: 'نمونه داده آزمایشی برای پیش‌نمایش رابط.',
        priceMinor: $price,
        sku: $sku,
        stock: $stock
    ), $specs, [$mediaId], $mediaId);
    return $id;
};

$specs = ['material' => 'لاتکس', 'size_mm' => '240', 'sterile' => '1', 'grade' => 'A'];

$draft = $make('دستکش لاتکس پودری — بسته ۱۰۰ عددی', 'TMC-GLV-100', 1850000, 42, $specs);
$queued = $make('ماسک سه‌لایه جراحی — بسته ۵۰ عددی', 'TMC-MSK-050', 620000, 8, $specs);
$live = $make('دستکش نیتریل بدون پودر — بسته ۱۰۰ عددی', 'TMC-NIT-100', 2450000, 3, $specs);

$manage->submit($vendorId, $vendorId, $queued);

$manage->submit($vendorId, $vendorId, $live);
$review->approve($live);
// A sensitive edit on the live product: the proposal queues, the live row stays.
$product = $products->find($live);
$manage->save($vendorId, $vendorId, $live, new ProductDetails(
    title: 'دستکش نیتریل بدون پودر — بسته ۲۰۰ عددی',
    categoryKey: 'gloves',
    brand: 'تک‌طب',
    shortDescription: 'نمونه داده آزمایشی برای پیش‌نمایش رابط.',
    priceMinor: 4300000,
    sku: 'TMC-NIT-100',
    stock: 3
), $specs, $product->imageIds, $product->mainImageId);

// One product the manager sent back, so the list shows a real reason.
$review->requestChanges($queued, 'تصویر بسته‌بندی واضح نیست؛ لطفاً تصویر تازه بگذارید.');

foreach ($products->forVendor($vendorId) as $p) {
    echo $p->id . "\t" . $p->status->value . "\t" . $p->details->sku . "\t" . count($p->imageIds) . " image(s)\n";
}
echo "template={$templateId}\n";
