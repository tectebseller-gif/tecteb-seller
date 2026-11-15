<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationReaderInterface;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontPriceRange;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;

/**
 * The imported variable product, read from WooCommerce's own objects.
 *
 * ⛔ WHY THIS CLASS EXISTS, measured on the source rather than guessed:
 * `DbVariationRepository` reads `tmc_product_attributes` and
 * `tmc_product_variations` and nothing else, and `ImportFromDokan` writes
 * neither — so for every product that came in from Dokan the panel asked «what
 * are this product's variations?» of a pair of tables that have no row for it
 * and got `[]`. `VariationsView` turns `[]` attributes into «هنوز ویژگی‌ای
 * تعریف نشده است» and returns early, and `ProductListView::row()` prints the
 * marketplace row's own `price_minor`/`stock`, which an intake leaves at
 * nought. The vendor's panel therefore described a parent with three priced,
 * in-stock children in WooCommerce as a product with no attributes, no price
 * and no stock.
 *
 * For such a product WOOCOMMERCE IS THE TRUTH (§3-۲ of the owner's
 * instruction), and this reader is the only thing that says so. It asks for
 * the real `WC_Product_Variation` children by their real ids and reports what
 * they hold. It cannot write: there is no setter, no `save()`, no
 * `update_post_meta()` and no `wp_insert_post()` anywhere in this file, and an
 * architecture test reads these bytes and keeps it that way.
 *
 * Prices come back through `DecimalAmount::toMinor()` at the shop's own scale,
 * which is EXACT OR NULL. `(int) round((float) …)` — the shape `alpha.53`
 * found in the Dokan order reader — would turn `'5187400.50'` into a price
 * nobody set, and at the wrong scale it would move the figure by a factor of a
 * hundred.
 */
final class WcStorefrontVariationReader implements StorefrontVariationReaderInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function setFor(int $wcProductId): StorefrontVariationSet
    {
        if ($wcProductId <= 0) {
            return StorefrontVariationSet::unavailable(StorefrontVariationSet::NO_STOREFRONT_PRODUCT);
        }
        if (!$this->available()) {
            return StorefrontVariationSet::unavailable(StorefrontVariationSet::NO_WOOCOMMERCE);
        }
        $parent = wc_get_product($wcProductId);
        if (!$parent) {
            return StorefrontVariationSet::unavailable(StorefrontVariationSet::NO_STOREFRONT_PRODUCT);
        }
        if (!$parent instanceof \WC_Product_Variable) {
            return StorefrontVariationSet::unavailable(StorefrontVariationSet::NOT_VARIABLE);
        }

        $exponent = $this->exponent();
        if ($exponent === null) {
            // The shop's scale is the unit every figure below would be read
            // in. Without it the numbers have no meaning, and inventing a
            // scale is how a price moves by a factor of a hundred.
            return StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE);
        }

        $variations = [];
        $unreadable = 0;
        foreach ($parent->get_children() as $childId) {
            $childId = (int) $childId;
            $child = $childId > 0 ? wc_get_product($childId) : false;
            if (!$child instanceof \WC_Product_Variation) {
                // Counted, not dropped: the shop listed this child and we
                // could not read it, which is a fact the panel has to print.
                $unreadable++;
                continue;
            }
            $variations[] = $this->variation($child, $childId, $exponent, $parent);
        }

        return StorefrontVariationSet::read($variations, $exponent, $unreadable);
    }

    public function rangesFor(array $wcProductIds): array
    {
        $ids = [];
        foreach ($wcProductIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        // Every id gets an answer, and the default answer is «not read».
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = StorefrontPriceRange::unreadable();
        }
        $exponent = $this->available() ? $this->exponent() : null;
        if ($exponent === null) {
            return $out;
        }
        $table = $this->lookupTable();
        if ($table === null) {
            return $out;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT product_id, min_price, max_price, stock_status, stock_quantity
             FROM `' . $table . '` WHERE product_id IN (' . $placeholders . ')',
            $ids
        );
        foreach ($rows as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if ($id <= 0 || !isset($out[$id])) {
                continue;
            }
            $out[$id] = StorefrontPriceRange::of(
                $this->minor($row['min_price'] ?? null, $exponent),
                $this->minor($row['max_price'] ?? null, $exponent),
                $exponent,
                (string) ($row['stock_status'] ?? ''),
                // `null` travels: WooCommerce leaves this empty exactly when
                // the parent delegates stock to its children, which is the
                // case the old «موجودی ۰ ⇒ ناموجود» reading got wrong.
                $row['stock_quantity'] === null ? null : (int) $row['stock_quantity']
            );
        }
        return $out;
    }

    // ------------------------------------------------------------ internals

    private function variation(
        \WC_Product_Variation $child,
        int $childId,
        int $exponent,
        \WC_Product_Variable $parent
    ): StorefrontVariation
    {
        $manageStock = (bool) $child->get_manage_stock();
        $quantity = $child->get_stock_quantity();

        return new StorefrontVariation(
            $childId,
            $this->attributesOf($child, $parent),
            (string) $child->get_sku(),
            $this->minor($child->get_regular_price(), $exponent),
            $this->minor($child->get_sale_price(), $exponent),
            $this->minor($child->get_price(), $exponent),
            $child->get_status() === 'publish',
            $manageStock,
            // Only a managed number is a number. An unmanaged child reports
            // `null` here and its status beside it, so «۰ عدد» is never
            // printed about a child that never counted.
            $manageStock && $quantity !== null ? (int) $quantity : null,
            (string) $child->get_stock_status(),
            (string) $child->get_backorders(),
            (int) $child->get_image_id()
        );
    }

    /**
     * The chosen options, with human labels where WooCommerce has them.
     *
     * A taxonomy attribute (`pa_*`) stores a SLUG, and a slug is not what the
     * vendor typed — «۱۰۰ عددی» is stored as something like `100-adadi`. The
     * term is resolved where it exists and the raw value is kept where it does
     * not, because a missing term is not a reason to print nothing.
     *
     * @return array<string,string>
     */
    private function attributesOf(\WC_Product_Variation $child, \WC_Product_Variable $parent): array
    {
        $out = [];
        foreach ((array) $child->get_attributes() as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            if ($key === '') {
                continue;
            }
            // ⛔ THE PARENT IS PASSED, and that is the whole of the fix
            // for a custom Persian attribute name.
            //
            // `wc_attribute_label($name)` with no product can only answer for
            // a TAXONOMY; for a custom attribute it hands back the stored key,
            // which `sanitize_title()` percent-encoded. Given the parent,
            // WooCommerce looks the original name up in that product's own
            // attribute list and answers «اندازه». This is resolution by the
            // platform, not a guess by us — and where it still cannot answer,
            // the display layer decodes the key for reading only
            // (`AttributeLabel`). Nothing stored is rewritten either way.
            $label = function_exists('wc_attribute_label')
                ? (string) wc_attribute_label($key, $parent)
                : $key;
            if ($label === '') {
                $label = $key;
            }
            if ($value !== '' && function_exists('taxonomy_exists') && taxonomy_exists($key)) {
                $term = function_exists('get_term_by') ? get_term_by('slug', $value, $key) : false;
                if (is_object($term) && isset($term->name) && (string) $term->name !== '') {
                    $value = (string) $term->name;
                }
            }
            $out[$label] = $value;
        }
        return $out;
    }

    private function available(): bool
    {
        return class_exists(\WC_Product_Variable::class)
            && class_exists(\WC_Product_Variation::class)
            && function_exists('wc_get_product');
    }

    /**
     * The shop's price scale, asked of WooCommerce — the same one source
     * `WcOrderReader::unit()` and `WpDokanReader::exponent()` ask
     * (`alpha.39`, `alpha.53`). `null` rather than a fallback of nought: this
     * reader reports what it read.
     */
    private function exponent(): ?int
    {
        if (!function_exists('wc_get_price_decimals')) {
            return null;
        }
        $decimals = (int) wc_get_price_decimals();
        return $decimals >= 0 && $decimals <= DecimalAmount::MAX_EXPONENT ? $decimals : null;
    }

    /** Exact or `null`. Never rounded, never nought for an empty string. */
    private function minor(mixed $decimal, int $exponent): ?int
    {
        if ($decimal === null) {
            return null;
        }
        $text = trim((string) $decimal);
        return $text === '' ? null : DecimalAmount::toMinor($text, $exponent);
    }

    /**
     * WooCommerce's own lookup table, or `null` when this installation has
     * none. Asked of `information_schema` rather than assumed: the table
     * arrived in WooCommerce 3.6 and a shop that has not run that upgrade
     * must read as «نامشخص», not as a catalogue of free products.
     */
    private function lookupTable(): ?string
    {
        $table = $this->db->prefix() . 'wc_product_meta_lookup';
        $exists = $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($exists === null) {
            return null;
        }
        return (int) $exists > 0 ? $table : null;
    }
}
