<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;

/**
 * What the storefront says about a product, field by field, and who owns it.
 *
 * The projector's ownership stamps answer «may we write this?» one field at a
 * time, on the way out. Nothing could answer the other question — «what does
 * WooCommerce actually have, and is it different from what the vendor asked
 * for?» — so a manager's correction was invisible until the day it vanished.
 *
 * This is the read, and the two ways to end a disagreement. Both of them end
 * it: there is no state where the marketplace row and the storefront say
 * different things and nobody has decided which is right.
 */
interface StorefrontFieldsInterface
{
    /**
     * One row per field the marketplace projects, or [] when the product has
     * no storefront post yet.
     *
     * @return list<StorefrontField>
     */
    public function compare(Product $product): array;

    /**
     * The manager's WooCommerce value wins, permanently.
     *
     * @return string the value now on the storefront, so the caller can write
     *         it into the marketplace row for the fields that round-trip
     */
    public function keepStorefront(Product $product, string $field): ?string;

    /**
     * The vendor's held proposal wins: written to the storefront, re-stamped,
     * and the field belongs to the marketplace again.
     *
     * Returns a CODE rather than a boolean because the two ways this can
     * refuse are not the same refusal. «عنوان نمی‌تواند خالی بماند» is a
     * decision the manager can act on; «ذخیره نشد» is a fault to retry. A
     * boolean made both of them the same sentence.
     *
     * @return string|null null when the storefront now holds the proposal;
     *         otherwise the reason it does not, and nothing was changed
     */
    public function acceptProposal(Product $product, string $field): ?string;

    /** Where the manager edits this product — WooCommerce's own editor. */
    public function editorUrl(int $wcProductId): string;

    /**
     * Whether an SEO plugin is present, and which.
     *
     * Not so the plugin can be reimplemented — the opposite. The review screen
     * links INTO it and says its name, because a manager told «edit the SEO»
     * with no idea where is a manager who will retype it somewhere else.
     */
    public function seoPluginName(): string;
}
