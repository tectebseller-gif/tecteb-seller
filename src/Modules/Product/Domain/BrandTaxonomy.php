<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * WHICH taxonomy holds this shop's approved brands — discovered, never typed.
 *
 * ⛔ **THE DEFECT THIS EXISTS FOR, AND THE TWO TRUE SENTENCES THAT HID IT.**
 *
 * `WooCommerceBrandDirectory` had `const TAXONOMY = 'product_brand'` and
 * gated every one of its methods on `taxonomy_exists()` of that one name. Its
 * own docblock explains why, and the explanation is correct: measured on the
 * disposable install with WooCommerce 11.0.1, `get_taxonomies()` really does
 * list `product_brand` beside `product_cat`.
 *
 * And `ManageBrands`'s docblock says, equally correctly, that the brand
 * reaching the product is «the path the owner measured working — WooCommerce →
 * Product data → Attributes → Brand».
 *
 * Both sentences are true about their own object and together they are a false
 * conclusion, which is the `alpha.105` shape. «Product data → Attributes» is
 * where a WooCommerce ATTRIBUTE lives — a global one is the taxonomy
 * `pa_<slug>`, a custom one is not a taxonomy at all. It is not
 * `product_brand`, which has its own top-level screen. So on a shop whose
 * brands are an attribute:
 *
 *   - `isAvailable()` is false, so `pick()` returns an EMPTY list;
 *   - the field paints a `<datalist>` with no options;
 *   - `BrandSuggest` answers `count: 0` to every keystroke;
 *   - and no screen anywhere says why.
 *
 * «Autocomplete does nothing» is the exact symptom, and the cause is a typed
 * key for data this plugin does not own — `alpha.34`, in as many words:
 * «کلیدِ افزونه‌ای که سورسش را ندارید را کشف کنید، تایپ نکنید».
 *
 * ⛔ **WHAT THIS DOES NOT DO.** It does not decide how a brand is WRITTEN onto
 * a product. That path is `factAttributes()`, the owner measured it working,
 * and §5 says the other attributes and the categories stay unchanged — so
 * nothing here touches it. This answers one question: where does the list the
 * vendor is offered come from.
 *
 * ⛔ **AND `product_brand` STAYS FIRST.** A shop that has it is answered
 * exactly as before, byte for byte, so the discovery can only ADD an answer
 * where there was none. A rule that reordered the preferences would be a
 * change to every shop that is working today.
 */
final class BrandTaxonomy
{
    /**
     * Taxonomies that are a brand list by name, most authoritative first.
     *
     * `product_brand` is WooCommerce's own since 9.4 and is what this plugin
     * has always read. The rest are the names the widely-installed brand
     * plugins register; they are listed because a name that is ALREADY on the
     * site is a discovery, not a guess — nothing here creates a taxonomy.
     */
    public const KNOWN = [
        'product_brand',        // WooCommerce core, 9.4+
        'product_brands',       // WooCommerce Brands (legacy official add-on)
        'pwb-brand',            // Perfect Brands for WooCommerce
        'yith_product_brand',   // YITH WooCommerce Brands
        'berocket_brand',       // BeRocket Brands
    ];

    /**
     * The words a brand attribute is labelled with, folded by `BrandName` so
     * «برند» and «برَند» and «Brand» are one word.
     *
     * Matched against the LABEL rather than the slug, because a Persian shop's
     * global attribute is `pa_<transliterated-or-encoded>` and the label is
     * the only part a human chose.
     */
    public const LABELS = ['برند', 'brand', 'marque', 'مارک'];

    /**
     * @param list<string>                           $registered taxonomies registered for `product`
     * @param list<array{taxonomy:string,label:string}> $attributes global product attributes
     *
     * @return string|null the taxonomy to read, or null when the shop has no
     *                     brand list this can recognise
     */
    public static function discover(array $registered, array $attributes = []): ?string
    {
        $have = [];
        foreach ($registered as $taxonomy) {
            $taxonomy = trim((string) $taxonomy);
            if ($taxonomy !== '') {
                $have[$taxonomy] = true;
            }
        }
        foreach (self::KNOWN as $candidate) {
            if (isset($have[$candidate])) {
                return $candidate;
            }
        }
        // ⛔ A GLOBAL ATTRIBUTE, and only one that is REGISTERED.
        //
        // `wc_get_attribute_taxonomies()` lists what the shop defined;
        // `$registered` lists what WooCommerce actually registered this
        // request. An attribute in the first and not the second is a row
        // nothing can read terms from, and answering with it would trade an
        // empty list for an error.
        $wanted = [];
        foreach (self::LABELS as $label) {
            $key = BrandName::key($label);
            if ($key !== '') {
                $wanted[$key] = true;
            }
        }
        foreach ($attributes as $attribute) {
            $taxonomy = trim((string) ($attribute['taxonomy'] ?? ''));
            if ($taxonomy === '' || !isset($have[$taxonomy])) {
                continue;
            }
            if (isset($wanted[BrandName::key((string) ($attribute['label'] ?? ''))])) {
                return $taxonomy;
            }
        }
        return null;
    }
}
