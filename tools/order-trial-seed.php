<?php
/**
 * Seeds the order trial on a DISPOSABLE WordPress with real WooCommerce.
 *
 * Everything is created through the plugin's own services, so a row that
 * exists here is a row the real code path produced. The one thing written
 * directly is the SAMPLE commission rate — set on purpose, in a throwaway
 * database, because FIN-02 forbids guessing one and the trial needs a number
 * to compute with.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/order-trial-seed.php <vendor-a> <vendor-b>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Product\Application\ConfigureSpecTemplates;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ManageVariations;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpProductImages;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

$vendorA = (int) ($args[0] ?? 0);
$vendorB = (int) ($args[1] ?? 0);
if ($vendorA <= 0 || $vendorB <= 0) {
    echo "usage: wp eval-file tools/order-trial-seed.php <vendor-a> <vendor-b>\n";
    return;
}
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

/** Sample rule, trial only: ten percent for everything. */
$c->get(CommissionRuleRepositoryInterface::class)
    ->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));

$vendors = $c->get(VendorRepositoryInterface::class);
$vendors->upsertProfile($vendorA, 'داروخانه آزمایشی یک', true, false);
$vendors->upsertProfile($vendorB, 'داروخانه آزمایشی دو', true, false);

$templates = $c->get(ConfigureSpecTemplates::class);
$created = $templates->createTemplate('gloves', 'دستکش و ملزومات یکبارمصرف');
$templateId = (int) ($created->context['template_id'] ?? 0);
if ($templateId === 0) {
    $templateId = $c->get(SpecTemplateRepositoryInterface::class)->findByCategory('gloves')?->id ?? 0;
}
$templates->addField($templateId, 'material', 'جنس', 'text', true, '', [], 1);

$images = new WpProductImages(new ProductImagePolicy());
$makeImage = static function (string $name) use ($images) {
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAWklEQVR42u3QMQEAAAgDoC252H'
        . 'zAR4dAqz9zAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIEHgtL'
        . 'jkAAdSPbCEAAAAASUVORK5CYII='
    );
    $tmp = wp_tempnam($name);
    file_put_contents($tmp, $png);
    return new UploadedFile($name . '.png', $tmp, strlen($png), 'image/png');
};

$manage = $c->get(ManageProducts::class);
$review = $c->get(ReviewProducts::class);
$products = $c->get(ProductRepositoryInterface::class);
$variationService = $c->get(ManageVariations::class);

$simple = static function (int $vendor, string $title, string $sku, int $price, int $stock)
    use ($manage, $review, $images, $makeImage, $products) {
    $details = new ProductDetails(
        title: $title,
        categoryKey: 'gloves',
        brand: 'تک‌طب',
        shortDescription: 'داده آزمایشی مسیر سفارش.',
        priceMinor: $price,
        sku: $sku,
        stock: $stock
    );
    $created = $manage->save($vendor, $vendor, 0, $details);
    $id = (int) ($created->context['product_id'] ?? 0);
    if ($id === 0) {
        return 0;
    }
    $stored = $images->store($makeImage('tmc-' . $sku), $vendor, $title);
    $mediaId = (int) $stored['media_id'];
    $manage->save($vendor, $vendor, $id, $details, ['material' => 'لاتکس'], [$mediaId], $mediaId);
    $manage->submit($vendor, $vendor, $id);
    $review->approve($id);
    return $id;
};

$a = $simple($vendorA, 'دستکش لاتکس پودری — بسته ۱۰۰ عددی', 'TRIAL-A', 1200000, 10);
$b = $simple($vendorB, 'ماسک سه‌لایه جراحی — بسته ۵۰ عددی', 'TRIAL-B', 600000, 10);

/** One variable product, so the variation path is exercised too. */
$variableDetails = new ProductDetails(
    title: 'دستکش نیتریل — چند اندازه',
    type: ProductType::VARIABLE,
    categoryKey: 'gloves',
    brand: 'تک‌طب',
    shortDescription: 'داده آزمایشی محصول متغیر.',
    priceMinor: 900000,
    sku: 'TRIAL-VAR',
    stock: 0
);
$createdVariable = $manage->save($vendorA, $vendorA, 0, $variableDetails);
$variable = (int) ($createdVariable->context['product_id'] ?? 0);
if ($variable > 0) {
    $stored = $images->store($makeImage('tmc-var'), $vendorA, 'دستکش نیتریل');
    $mediaId = (int) $stored['media_id'];
    $manage->save($vendorA, $vendorA, $variable, $variableDetails, ['material' => 'نیتریل'], [$mediaId], $mediaId);
    $variationService->saveAttribute($vendorA, $vendorA, $variable, 'size', 'اندازه', ['کوچک', 'بزرگ']);
    $variationService->saveVariation($vendorA, $vendorA, $variable, ['size' => 'کوچک'], 900000, null, 'TRIAL-VAR-S', 6);
    $variationService->saveVariation($vendorA, $vendorA, $variable, ['size' => 'بزرگ'], 1100000, null, 'TRIAL-VAR-L', 4);
    $manage->submit($vendorA, $vendorA, $variable);
    $review->approve($variable);
}

foreach ([$a, $b, $variable] as $id) {
    if ($id <= 0) {
        continue;
    }
    $product = $products->find($id);
    echo $id . "\t" . $product->status->value . "\t" . $product->details->sku
        . "\twc=" . (string) ($product->wcProductId ?? '-')
        . "\tstock=" . $product->details->stock . "\n";
}
echo "rate=10%\n";
