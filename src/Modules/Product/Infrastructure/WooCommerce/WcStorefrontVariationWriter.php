<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationWriterInterface;

/**
 * The vendor's figures, written onto a real `WC_Product_Variation`.
 *
 * ⛔ **THE PARENT IS READ AND NEVER WRITTEN, and that is the whole shape of
 * this class.** It is loaded for one purpose — `get_children()` is the only
 * membership WooCommerce recognises, so it is the proof that this child
 * belongs to this product — and no setter is ever called on it.
 * `StorefrontVariationWriterTouchesOnlyTheChildTest` reads these bytes and
 * refuses any `$parent->set_*`, any `$parent->save()`, and the two parent
 * figures `alpha.106` made unwritable.
 *
 * **Why the stock write is WooCommerce's own statement.** Measured in
 * `alpha.50`: `WC_Product_Data_Store_CPT::update_product_stock()` builds one
 * relative/absolute `UPDATE` on the `_stock` row with no transaction, and
 * `wc_maybe_reduce_stock_levels()` runs it the moment a shopper pays. So a
 * read-then-write here would lose a sale that landed in between. The guard
 * therefore rides in on `woocommerce_update_product_stock_query`, the filter
 * that method passes its SQL through, and the comparison is NUMERIC because
 * the same number reaches that row as «7» from `save()` and as «7.000000»
 * from the direct path.
 *
 * **Why the price write is a read-back.** There is no equivalent filter for
 * `_price`/`_regular_price`, and `WC_Product::save()` returns the product id
 * whether it wrote or not (`alpha.28`). So the sequence is: read what the row
 * says, refuse unless it is what the vendor was shown, write, read again, and
 * report `written` only when the row now says what was asked. The window
 * between the two reads is narrower than a human edit and is not claimed to be
 * zero — what IS claimed is that a value changed since the RENDER is refused,
 * which is the consent question §3 asks («قفل خوش‌بینانه / داده کهنه»).
 */
final class WcStorefrontVariationWriter implements StorefrontVariationWriterInterface
{
    public function priceExponent(): ?int
    {
        return $this->available() ? $this->exponent() : null;
    }

    public function writePricesIfUnchanged(
        int $parentWcId,
        int $variationId,
        ?int $regularMinor,
        ?int $saleMinor,
        ?int $seenRegularMinor,
        ?int $seenSaleMinor
    ): array {
        $blank = ['found_regular' => null, 'found_sale' => null, 'exponent' => null];
        $child = $this->child($parentWcId, $variationId);
        if (is_string($child)) {
            return ['outcome' => $child] + $blank;
        }
        $exponent = $this->exponent();
        if ($exponent === null) {
            return ['outcome' => 'unreadable'] + $blank;
        }
        $foundRegular = $this->minor($child->get_regular_price(), $exponent);
        $foundSale = $this->minor($child->get_sale_price(), $exponent);
        $found = [
            'found_regular' => $foundRegular,
            'found_sale' => $foundSale,
            'exponent' => $exponent,
        ];
        if ($foundRegular !== $seenRegularMinor || $foundSale !== $seenSaleMinor) {
            return ['outcome' => 'moved'] + $found;
        }
        // A sale that is not below the regular price is one WooCommerce stores
        // and then ignores — `get_price()` would stay at the regular figure —
        // so the vendor would be told «saved» about a sale no shopper sees.
        if ($saleMinor !== null && ($regularMinor === null || $saleMinor >= $regularMinor)) {
            return ['outcome' => 'bad_sale'] + $found;
        }
        $regularText = $this->decimal($regularMinor, $exponent);
        $saleText = $this->decimal($saleMinor, $exponent);
        if ($regularText === null || $saleText === null) {
            return ['outcome' => 'unreadable'] + $found;
        }
        $child->set_regular_price($regularText);
        $child->set_sale_price($saleText);
        try {
            $child->save();
        } catch (\Throwable) {
            return ['outcome' => 'not_written'] + $found;
        }
        // READ BACK, from a product built after the save rather than from the
        // object we just wrote through: WooCommerce filters values on the way
        // in (`alpha.26`) and `save()` cannot be asked whether it wrote.
        $fresh = $this->reload($variationId);
        if ($fresh === null) {
            return ['outcome' => 'not_written'] + $found;
        }
        $nowRegular = $this->minor($fresh->get_regular_price(), $exponent);
        $nowSale = $this->minor($fresh->get_sale_price(), $exponent);
        $now = [
            'found_regular' => $nowRegular,
            'found_sale' => $nowSale,
            'exponent' => $exponent,
        ];
        if ($nowRegular !== $regularMinor || $nowSale !== $saleMinor) {
            return ['outcome' => 'not_written'] + $now;
        }
        return ['outcome' => 'written'] + $now;
    }

    public function writeStockIfUnchanged(
        int $parentWcId,
        int $variationId,
        int $stock,
        ?int $seen
    ): array {
        $child = $this->child($parentWcId, $variationId);
        if (is_string($child)) {
            return ['outcome' => $child, 'found' => null];
        }
        $amount = (int) wc_stock_amount(max(0, $stock));
        $managed = (bool) $child->get_manage_stock();

        if ($seen === null) {
            // The screen said «به‌ازای والد»: no number of this child's own.
            // A child that has acquired one since is a child somebody else has
            // been editing, and the vendor's «first number» is then about a
            // state that no longer exists.
            if ($managed) {
                return ['outcome' => 'moved', 'found' => self::storedStock($variationId)];
            }
            // ⛔ `set_manage_stock(true)` ON THE CHILD, which is what
            // WooCommerce's own «Manage stock?» tick on a variation sets, and
            // NOT on the parent. The parent object is never saved here.
            $child->set_manage_stock(true);
            $child->set_stock_quantity($amount);
            $child->set_stock_status($amount > 0 ? 'instock' : 'outofstock');
            try {
                $child->save();
            } catch (\Throwable) {
                return ['outcome' => 'not_written', 'found' => null];
            }
            // ⛔ READ BACK FROM THE PRODUCT, NOT FROM THE ROW — and that is a
            // decision about this branch only.
            //
            // The guarded branch below reads `_stock` directly, because there
            // the question is «did the statement's condition hold», and only
            // the row can answer it. Here the question is «did this save turn
            // management on and store the number», and the row is the wrong
            // place to ask: `WC_Product::save()` writes it through the data
            // store, so a build whose data store is a double has the prop and
            // not the row — and a check that insisted on the row would report
            // `not_written` about a save that worked. Both are compared when
            // the row IS there, so neither can drift from the other.
            $fresh = $this->reload($variationId);
            if ($fresh === null || !$fresh->get_manage_stock()) {
                return ['outcome' => 'not_written', 'found' => null];
            }
            $quantity = $fresh->get_stock_quantity();
            $landed = $quantity === null ? null : (int) $quantity;
            $row = self::storedStock($variationId);
            if ($landed !== $amount || ($row !== null && $row !== $amount)) {
                return ['outcome' => 'not_written', 'found' => $landed];
            }
            return ['outcome' => 'written', 'found' => $amount];
        }

        if (!$managed) {
            // The screen showed a number and the child no longer keeps one.
            return ['outcome' => 'moved', 'found' => null];
        }
        // ⛔ **THE SECOND LAYER, AND IT IS NOT REDUNDANT.**
        // `wc_update_product_stock()` writes the row named by
        // `get_stock_managed_by_id()`, which on a variation is the variation's
        // id ONLY while it manages its own stock and the PARENT's id
        // otherwise. The `$managed` check above makes that true today — but a
        // guard that holds because of the order of two lines is an ordering,
        // not a guard (`alpha.98`), and the row this could otherwise move is
        // the one `alpha.106` exists to protect.
        if ((int) $child->get_stock_managed_by_id() !== $variationId) {
            return ['outcome' => 'not_written', 'found' => null];
        }
        $before = self::storedStock($variationId);
        if ($before === null || $before !== $seen) {
            // An early answer, NOT the guard — the condition inside the
            // statement below is the guard. This is the only way to tell «the
            // statement was blocked» from «the statement wrote a number the
            // row already had», which the read-back cannot separate
            // (`alpha.49`, measured in `alpha.50`).
            return ['outcome' => 'moved', 'found' => $before];
        }
        $condition = ' AND meta_value + 0 = ' . $seen;
        $guard = static function (
            string $sql,
            int $id,
            mixed $newStock,
            string $operation
        ) use ($variationId, $condition): string {
            return $operation === 'set' && $id === $variationId ? $sql . $condition : $sql;
        };
        add_filter('woocommerce_update_product_stock_query', $guard, 10, 4);
        try {
            wc_update_product_stock($child, $amount, 'set', true);
        } catch (\Throwable) {
            remove_filter('woocommerce_update_product_stock_query', $guard, 10);
            return ['outcome' => 'not_written', 'found' => null];
        }
        remove_filter('woocommerce_update_product_stock_query', $guard, 10);

        $landed = self::storedStock($variationId);
        if ($landed === null || $landed !== $amount) {
            return ['outcome' => 'moved', 'found' => $landed];
        }
        $this->syncStatus($variationId, $amount);
        return ['outcome' => 'written', 'found' => $amount];
    }

    // ------------------------------------------------------------ internals

    /**
     * The child, proven to be this parent's — or the refusal code.
     *
     * @return \WC_Product_Variation|string
     */
    private function child(int $parentWcId, int $variationId): mixed
    {
        if (!$this->available()) {
            return 'no_woocommerce';
        }
        if ($parentWcId <= 0 || $variationId <= 0) {
            return 'not_a_child';
        }
        $parent = wc_get_product($parentWcId);
        if (!$parent instanceof \WC_Product_Variable) {
            return 'not_variable';
        }
        $children = array_map('intval', (array) $parent->get_children());
        if (!in_array($variationId, $children, true)) {
            // ⛔ THE OWNERSHIP PROOF. The caller has already established that
            // the vendor owns the marketplace row that points at this parent;
            // this is the other half — that the id they posted is one of that
            // parent's own children and not another shop's variation.
            return 'not_a_child';
        }
        $child = wc_get_product($variationId);
        if (!$child instanceof \WC_Product_Variation) {
            return 'not_a_child';
        }
        // Belt and braces: a child WooCommerce lists but whose own parent id
        // says otherwise is a product in a state nothing here should write to.
        if ((int) $child->get_parent_id() !== $parentWcId) {
            return 'not_a_child';
        }
        return $child;
    }

    private function reload(int $variationId): ?\WC_Product_Variation
    {
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($variationId);
        }
        $fresh = wc_get_product($variationId);
        return $fresh instanceof \WC_Product_Variation ? $fresh : null;
    }

    private function available(): bool
    {
        return class_exists(\WC_Product_Variable::class)
            && class_exists(\WC_Product_Variation::class)
            && function_exists('wc_get_product')
            && function_exists('wc_stock_amount');
    }

    /**
     * The shop's price scale, from the one source `WcStorefrontVariationReader`
     * asks, so the panel and the write cannot read one figure in two units.
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

    /** The decimal WooCommerce stores, or `''` for «no price of that kind». */
    private function decimal(?int $minor, int $exponent): ?string
    {
        if ($minor === null) {
            return '';
        }
        return DecimalAmount::toDecimal($minor, $exponent);
    }

    /** The `_stock` row, read numerically so either spelling answers the same. */
    private static function storedStock(int $variationId): ?int
    {
        if (!metadata_exists('post', $variationId, '_stock')) {
            return null;
        }
        $raw = get_post_meta($variationId, '_stock', true);
        if ($raw === '' || $raw === null) {
            return null;
        }
        return (int) round((float) $raw);
    }

    /**
     * The child's stock status, brought in line with the number on its row.
     *
     * The guarded statement runs with `$updating = true` so that WooCommerce
     * does not `save()` the product from the number it was ASKED for — on a
     * refusal that is not the number on the row. Same rule as
     * `WooCommerceProjector::syncStockStatus()`, so the two paths cannot
     * disagree about what «in stock» means.
     */
    private function syncStatus(int $variationId, int $amount): void
    {
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($variationId);
        }
        $fresh = wc_get_product($variationId);
        if (!$fresh instanceof \WC_Product) {
            return;
        }
        $wanted = $amount > 0 ? 'instock' : 'outofstock';
        if ($fresh->get_stock_status() === $wanted) {
            return;
        }
        try {
            wc_update_product_stock_status($variationId, $wanted);
        } catch (\Throwable) {
            // The number is on the row, which is what a shopper is held to.
        }
    }
}
