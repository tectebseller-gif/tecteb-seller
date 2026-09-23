<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontImages;

/**
 * Reading the other side of the projection, and closing a disagreement.
 *
 * Every write here is preceded by the same ownership check the projector
 * makes — `owns()` — so a shop product or a Dokan product is never touched,
 * however the review screen was reached.
 *
 * **Nothing in here creates a second copy.** `keepStorefront()` ends with the
 * marketplace and WooCommerce holding the same value for a field that can
 * round-trip, and with the split NAMED for the one field that cannot. The
 * caller writes the marketplace side; this class returns the value so it can.
 */
final class WooCommerceStorefrontFields implements StorefrontFieldsInterface
{
    public function __construct(private readonly WooCommerceProjector $projector)
    {
    }

    public function compare(Product $product): array
    {
        $wcProduct = $this->storefrontProduct($product);
        if ($wcProduct === null) {
            return [];
        }
        $id = (int) $wcProduct->get_id();
        $rows = [];
        foreach (ProjectedFieldOwnership::FIELDS as $field) {
            $storefront = self::readField($wcProduct, $field);
            $stamp = (string) get_post_meta($id, ProjectedFieldOwnership::STAMP_PREFIX . $field, true);
            $managerOwns = (string) get_post_meta($id, ProjectedFieldOwnership::MANAGER_PREFIX . $field, true) === '1';
            // Asked of the meta table, not of the value. A vendor who cleared
            // a field proposed an empty string, and a screen that reads
            // absence off emptiness never shows that proposal at all — the
            // manager is never asked the question, so it can never be
            // answered.
            $pendingKey = ProjectedFieldOwnership::PENDING_PREFIX . $field;
            $hasPending = metadata_exists('post', $id, $pendingKey);
            $rows[] = new StorefrontField(
                $field,
                $this->marketplaceValue($product, $field, array_map('intval', $wcProduct->get_category_ids())),
                $storefront,
                $hasPending ? (string) get_post_meta($id, $pendingKey, true) : '',
                // `$createdByUs` is false and cannot be anything else here:
                // this reads a post that already existed. An unstamped field
                // therefore comes back `unknown`, which is what the review
                // screen needs in order to ask.
                ProjectedFieldOwnership::owner($stamp, $storefront, $managerOwns),
                in_array($field, ProjectedFieldOwnership::ROUND_TRIP, true),
                $managerOwns,
                $hasPending
            );
        }
        return $rows;
    }

    public function keepStorefront(Product $product, string $field): ?string
    {
        $wcProduct = $this->storefrontProduct($product);
        if ($wcProduct === null || !in_array($field, ProjectedFieldOwnership::FIELDS, true)) {
            return null;
        }
        $id = (int) $wcProduct->get_id();
        update_post_meta($id, ProjectedFieldOwnership::MANAGER_PREFIX . $field, '1');
        delete_post_meta($id, ProjectedFieldOwnership::PENDING_PREFIX . $field);
        // The stamp is REMOVED, not refreshed. Leaving a stamp that matches
        // would say the marketplace wrote this, and a later projection would
        // then overwrite it — the flag and a matching stamp are two different
        // stories about the same field.
        delete_post_meta($id, ProjectedFieldOwnership::STAMP_PREFIX . $field);
        return self::readField($wcProduct, $field);
    }

    /**
     * The vendor's value wins — including when the vendor's value is nothing.
     *
     * Presence and content are two questions and `get_post_meta()` answers
     * them with the same empty string. The version this replaces asked only
     * the second: `$pending !== ''` meant «there is a proposal», so a vendor
     * who cleared their short description had that proposal treated as
     * absent. Pressing «خواستهٔ فروشنده اعمال شود» then deleted the proposal,
     * left the old text on the shop, stamped that text as the marketplace's
     * own — and said it had worked. Three lies in one click, and the next
     * projection kept all of them.
     *
     * So presence is `metadata_exists()` and the value is whatever is in
     * there, empty or not.
     */
    public function acceptProposal(Product $product, string $field): ?string
    {
        $wcProduct = $this->storefrontProduct($product);
        if ($wcProduct === null || !in_array($field, ProjectedFieldOwnership::FIELDS, true)) {
            return 'storage_failed';
        }
        $id = (int) $wcProduct->get_id();
        $pendingKey = ProjectedFieldOwnership::PENDING_PREFIX . $field;
        $before = self::readField($wcProduct, $field);

        if (metadata_exists('post', $id, $pendingKey)) {
            $value = (string) get_post_meta($id, $pendingKey, true);
        } else {
            // No proposal recorded. On a product older than the stamps that
            // is the normal case rather than an edge one: the field was
            // frozen the moment it was read, and no projection has run since
            // to write a proposal down. «خواستهٔ فروشنده اعمال شود» still has
            // to mean the marketplace's value, so it is taken from the
            // product record here instead of being read back off the shop —
            // reading it off the shop would make the button a no-op that
            // looked like it had done something. Empty counts here too: the
            // unsettled path and the proposal path are the same button and
            // have to be the same promise.
            $value = $this->marketplaceValue(
                $product,
                $field,
                array_map('intval', $wcProduct->get_category_ids())
            );
        }

        if ($value === '' && !StorefrontField::mayBeEmpty($field)) {
            // Refused before anything is written, and the proposal stays
            // where it is. A product with no name is a row nobody can find
            // again; quietly keeping the old title instead would leave the
            // record and the shop saying different things, which is the one
            // outcome this whole mechanism exists to prevent.
            return $field . '_required';
        }

        $changes = ProjectedFieldOwnership::fingerprint($value)
            !== ProjectedFieldOwnership::fingerprint($before);
        if ($changes && !self::writeField($wcProduct, $field, $value)) {
            return 'storage_failed';
        }
        // Re-read, for the reason the projector does: the value WooCommerce
        // keeps is not always the one it was handed. And `WC_Product::save()`
        // swallows its own exceptions and still hands back the product id
        // (`WC_Order::save()` does the same — `alpha.11`), so reading is the
        // only thing here that can tell a write from a failure.
        $fresh = wc_get_product($id);
        if (!$fresh instanceof \WC_Product) {
            return 'storage_failed';
        }
        $after = self::readField($fresh, $field);
        if ($changes && ProjectedFieldOwnership::fingerprint($after)
            === ProjectedFieldOwnership::fingerprint($before)) {
            // Asked for something different and got back exactly what was
            // there. Nothing moved, so nothing is cleared and nothing is
            // stamped, and the proposal is still on the review screen to try
            // again. A stamp written here would say the marketplace owns a
            // value it never managed to write.
            return 'storage_failed';
        }
        // Only now, and in this order: the proposal is gone BECAUSE it has
        // been applied, and the stamp records what the shop actually holds.
        delete_post_meta($id, $pendingKey);
        delete_post_meta($id, ProjectedFieldOwnership::MANAGER_PREFIX . $field);
        update_post_meta(
            $id,
            ProjectedFieldOwnership::STAMP_PREFIX . $field,
            ProjectedFieldOwnership::fingerprint($after)
        );
        return null;
    }

    public function editorUrl(int $wcProductId): string
    {
        return $wcProductId > 0 ? (string) get_edit_post_link($wcProductId, 'url') : '';
    }

    public function seoPluginName(): string
    {
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            return 'Rank Math';
        }
        if (defined('WPSEO_VERSION')) {
            return 'Yoast SEO';
        }
        return '';
    }

    // -------------------------------------------------------------- internals

    private function storefrontProduct(Product $product): ?\WC_Product
    {
        if (!$product->isProjected() || !function_exists('wc_get_product')) {
            return null;
        }
        $id = (int) $product->wcProductId;
        if (!$this->projector->owns($id, $product->id)) {
            return null;        // not ours; never read it as if it were
        }
        $wcProduct = wc_get_product($id);
        return $wcProduct instanceof \WC_Product ? $wcProduct : null;
    }

    private static function readField(\WC_Product $wcProduct, string $field): string
    {
        return match ($field) {
            'title' => (string) $wcProduct->get_name(),
            'short_description' => (string) $wcProduct->get_short_description(),
            'description' => (string) $wcProduct->get_description(),
            'images' => ProjectedFieldOwnership::imagesToValue(
                (int) $wcProduct->get_image_id(),
                $wcProduct->get_gallery_image_ids()
            ),
            'category' => ProjectedFieldOwnership::idsToValue(
                array_map('intval', $wcProduct->get_category_ids())
            ),
            default => '',
        };
    }

    private static function writeField(\WC_Product $wcProduct, string $field, string $value): bool
    {
        $ids = static fn (string $v): array => array_values(array_filter(
            array_map('intval', explode(',', $v)),
            static fn (int $id): bool => $id > 0
        ));
        try {
            switch ($field) {
                case 'title':
                    $wcProduct->set_name($value);
                    break;
                case 'short_description':
                    $wcProduct->set_short_description($value);
                    break;
                case 'description':
                    $wcProduct->set_description($value);
                    break;
                case 'category':
                    $wcProduct->set_category_ids($ids($value));
                    break;
                case 'images':
                    // Decoded, not split on commas: the value names which
                    // picture is the featured one and what order the gallery
                    // is in, and accepting a proposal has to reproduce both.
                    $images = StorefrontImages::decode($value);
                    $wcProduct->set_image_id($images->main);
                    $wcProduct->set_gallery_image_ids($images->gallery);
                    break;
                default:
                    return false;
            }
            return (int) $wcProduct->save() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<int> $currentCategoryIds what the storefront has right now */
    private function marketplaceValue(Product $product, string $field, array $currentCategoryIds = []): string
    {
        return match ($field) {
            'title' => $product->details->title,
            'short_description' => $product->details->shortDescription,
            // What the projector WOULD write, not the raw column: comparing a
            // rendered description against a raw one reports every product as
            // manager-edited.
            'description' => $this->projector->storefrontDescriptionFor($product),
            'category' => $this->projector->storefrontCategoryFor($product, $currentCategoryIds),
            'images' => ProjectedFieldOwnership::imagesToValue($product->mainImageId, $product->imageIds),
            default => '',
        };
    }
}
