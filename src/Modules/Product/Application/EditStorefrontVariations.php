<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * The vendor's own figures on an IMPORTED variation — §3 of the owner's
 * instruction, and the door `VariationsView` has been promising since
 * `alpha.105` («ویرایش آن‌ها از این پنل مرحلهٔ بعدی مهاجرت است»).
 *
 * ⛔ **IT IS A SEPARATE SERVICE, NOT A RELAXATION OF `ManageVariations`.**
 * That class writes `tmc_product_attributes` and `tmc_product_variations` and
 * refuses when the shop holds the combinations, because a row of ours beside
 * WooCommerce's own children is a SECOND set of variations. This class writes
 * the real `WC_Product_Variation` and refuses in the opposite direction. Both
 * ask `StorefrontVariationOwnership`, so the two doors are mutually exclusive
 * by construction and «ترکیب‌های ساخته‌شده توسط افزونه بدون تغییر باقی بمانند»
 * is a structural fact rather than a rule somebody has to remember
 * (`alpha.78`).
 *
 * **Which policy applies, read off the existing policy rather than invented.**
 * §3 asks that a published product's price change follow «سیاست تأیید فعلی»
 * and the stock «سیاست فعلی», so both were looked up:
 *
 *  - the PRICE is `IMMEDIATE` for the vendor. It left `SensitiveChange::
 *    SENSITIVE` in `alpha.83` by the owner's explicit decision of 26 October
 *    2026 — «فروشنده همیشه مجاز است قیمت محصول متعلق به خودش را مستقیماً و
 *    بدون تأیید مدیر تغییر دهد» — with all four sale fields, because the
 *    projector writes a price and its sale as one unit. So a variation's price
 *    applies at once, and that IS the current approval policy; queueing it
 *    would be a new rule, not the existing one;
 *  - the STOCK is immediate for a variation. `ManageVariations::
 *    updateVariationStock()` has said so since it was written — «the same
 *    exception the parent product's inventory gets, and for the same reason
 *    (A.1)» — and asks for the Inventory area rather than Product.
 *    `IMMEDIATE_UNTIL_LIVE` is about the PARENT product's single `stock`
 *    field, which a variable product does not have.
 *
 * So the two verbs here mirror those two policies and those two capability
 * areas exactly. Nothing about approval, commission, ownership or the
 * marketplace row's own figures moves: this service has no access to
 * `tmc_products` beyond reading the row that proves the vendor owns the
 * parent, and no method that writes one.
 */
final class EditStorefrontVariations
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly StorefrontVariationOwnership $ownership,
        private readonly StorefrontVariationWriterInterface $writer,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * The regular and sale price of one real variation, as one unit.
     *
     * ⛔ **THE TYPED TEXT COMES IN, NOT A NUMBER, AND THAT IS DELIBERATE.**
     * The shop's price scale is the shop's — `wc_get_price_decimals()` — and
     * the figure on the screen was printed in it. `ProductArea::number()`,
     * which the marketplace's own price fields use, is
     * `(int) round((float) …)`: at the wrong scale that moves a price by a
     * factor of a hundred and on `'450000.50'` it invents a figure nobody set
     * (`alpha.39`, `alpha.53`). So the conversion happens HERE, once, with the
     * scale asked of the shop, through `DecimalAmount` — exact or refused.
     *
     * `$seenExponent` is the scale the screen printed in. A shop whose scale
     * changed between the render and the save is a shop in which the seen
     * figures mean something else, so it is `moved` rather than a write.
     *
     * @param string $regular  the vendor's typing, '' for «no regular price»
     * @param string $sale     the vendor's typing, '' for «no sale»
     */
    public function updatePrices(
        int $actorId,
        int $vendorUserId,
        int $productId,
        int $variationId,
        string $regular,
        string $sale,
        ?int $seenRegularMinor,
        ?int $seenSaleMinor,
        ?int $seenExponent
    ): OperationResult {
        // The PRODUCT area, because a price is product data. The same area
        // `ManageVariations::saveVariation()` asks for.
        $guard = $this->guard($actorId, $vendorUserId, $productId, StaffArea::Product);
        if ($guard instanceof OperationResult) {
            return $guard;
        }
        $exponent = $this->writer->priceExponent();
        if ($exponent === null) {
            return OperationResult::failure('variation_shop_unreadable', ['variation_id' => $variationId]);
        }
        if ($seenExponent !== $exponent) {
            return OperationResult::failure('variation_price_moved', [
                'variation_id' => $variationId,
                'found_regular' => null,
                'found_sale' => null,
                'exponent' => $exponent,
            ]);
        }
        $regularMinor = $this->minor($regular, $exponent);
        $saleMinor = $this->minor($sale, $exponent);
        if ($regularMinor === false || $saleMinor === false) {
            return OperationResult::failure('variation_bad_price', ['variation_id' => $variationId]);
        }
        if ($regularMinor !== null && $regularMinor < 0) {
            return OperationResult::failure('variation_bad_price', ['variation_id' => $variationId]);
        }
        if ($saleMinor !== null && $saleMinor < 0) {
            return OperationResult::failure('variation_bad_price', ['variation_id' => $variationId]);
        }
        $written = $this->writer->writePricesIfUnchanged(
            $guard,
            $variationId,
            $regularMinor,
            $saleMinor,
            $seenRegularMinor,
            $seenSaleMinor
        );
        $context = [
            'variation_id' => $variationId,
            'found_regular' => $written['found_regular'],
            'found_sale' => $written['found_sale'],
            'exponent' => $written['exponent'],
        ];
        if ($written['outcome'] !== 'written') {
            return OperationResult::failure(match ($written['outcome']) {
                'moved' => 'variation_price_moved',
                'bad_sale' => 'variation_sale_not_below_price',
                'not_a_child' => 'variation_not_a_child',
                'not_variable' => 'variation_parent_not_variable',
                'no_woocommerce' => 'variation_no_woocommerce',
                'unreadable' => 'variation_shop_unreadable',
                default => 'variation_price_not_written',
            }, $context);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_SAVED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'variation_id' => $variationId,
        ]);
        return OperationResult::success('variation_price_saved', $context);
    }

    /**
     * The typed decimal in minor units: `null` for «no price», `false` for a
     * text this scale cannot hold exactly.
     *
     * `false` rather than `null` for the refusal because the two answers mean
     * opposite things here and a single `null` would turn «I cannot read
     * this» into «clear the price» — the `alpha.8` shape, on money.
     */
    private function minor(string $typed, int $exponent): int|false|null
    {
        $text = PersianDigits::toLatin(trim($typed));
        $text = str_replace([',', "\u{066C}", "\u{060C}", ' '], '', $text);
        if ($text === '') {
            return null;
        }
        return DecimalAmount::toMinor($text, $exponent) ?? false;
    }

    /**
     * One real variation's stock, written only while the shop still holds the
     * number the vendor was shown.
     *
     * `$seen` of `null` is «the screen said this child has no number of its
     * own», which is a real state: a variation that inherits the parent's
     * answer. Declaring the first number for such a child turns management on
     * FOR THE CHILD — never for the parent, which `alpha.106` made unwritable
     * and this round does not touch.
     */
    public function updateStock(
        int $actorId,
        int $vendorUserId,
        int $productId,
        int $variationId,
        int $stock,
        ?int $seen
    ): OperationResult {
        // The INVENTORY area, exactly as `updateVariationStock()` asks (A.1):
        // a shop's stock clerk may correct a number without being allowed to
        // change what the product claims to be.
        $guard = $this->guard($actorId, $vendorUserId, $productId, StaffArea::Inventory);
        if ($guard instanceof OperationResult) {
            return $guard;
        }
        if ($stock < 0) {
            return OperationResult::failure('variation_bad_stock', ['variation_id' => $variationId]);
        }
        $written = $this->writer->writeStockIfUnchanged($guard, $variationId, $stock, $seen);
        $context = ['variation_id' => $variationId, 'found' => $written['found'], 'stock' => $stock];
        if ($written['outcome'] !== 'written') {
            return OperationResult::failure(match ($written['outcome']) {
                'moved' => 'variation_stock_moved',
                'not_a_child' => 'variation_not_a_child',
                'not_variable' => 'variation_parent_not_variable',
                'no_woocommerce' => 'variation_no_woocommerce',
                default => 'variation_stock_not_written',
            }, $context);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_INVENTORY_CHANGED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'variation_id' => $variationId,
            'stock' => $stock,
        ]);
        return OperationResult::success('variation_stock_saved', $context);
    }

    /**
     * Permission, ownership and whose combinations these are — in that order,
     * and all three on the server.
     *
     * Returns the PARENT's WooCommerce id on success, which is the one thing
     * both verbs need and the one thing a posted form may not be trusted for:
     * it is read from the marketplace row that `findOwned()` proved belongs to
     * this vendor, so a vendor cannot name another shop's parent.
     *
     * @return int|OperationResult
     */
    private function guard(int $actorId, int $vendorUserId, int $productId, StaffArea $area): mixed
    {
        if (!$this->access->can($actorId, $vendorUserId, $area, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        $wcProductId = (int) ($product->wcProductId ?? 0);
        if ($wcProductId <= 0) {
            return OperationResult::failure('variation_no_storefront_product');
        }
        // ⛔ THE MIRROR OF `ManageVariations`' GUARD, and fail-closed with it.
        // «ours» and «could not be read» both land here, because a shop that
        // cannot be read is not evidence that these children are the shop's —
        // and this door writes straight at WooCommerce.
        if (!$this->ownership->shopOwns($productId, $product->details->type, $wcProductId)) {
            return OperationResult::failure('variations_not_live_in_shop', ['product_id' => $productId]);
        }
        return $wcProductId;
    }
}
