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
            $rows[] = new StorefrontField(
                $field,
                $this->marketplaceValue($product, $field, array_map('intval', $wcProduct->get_category_ids())),
                $storefront,
                (string) get_post_meta($id, ProjectedFieldOwnership::PENDING_PREFIX . $field, true),
                // `$createdByUs` is false and cannot be anything else here:
                // this reads a post that already existed. An unstamped field
                // therefore comes back `unknown`, which is what the review
                // screen needs in order to ask.
                ProjectedFieldOwnership::owner($stamp, $storefront, $managerOwns),
                in_array($field, ProjectedFieldOwnership::ROUND_TRIP, true),
                $managerOwns
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

    public function acceptProposal(Product $product, string $field): bool
    {
        $wcProduct = $this->storefrontProduct($product);
        if ($wcProduct === null || !in_array($field, ProjectedFieldOwnership::FIELDS, true)) {
            return false;
        }
        $id = (int) $wcProduct->get_id();
        $pending = (string) get_post_meta($id, ProjectedFieldOwnership::PENDING_PREFIX . $field, true);
        if ($pending === '') {
            // No proposal recorded. On a product older than the stamps that
            // is the normal case rather than an edge one: the field was
            // frozen the moment it was read, and no projection has run since
            // to write a proposal down. «خواستهٔ فروشنده اعمال شود» still has
            // to mean the marketplace's value, so it is taken from the
            // product record here instead of being read back off the shop —
            // reading it off the shop would make the button a no-op that
            // looked like it had done something.
            $marketplace = $this->marketplaceValue(
                $product,
                $field,
                array_map('intval', $wcProduct->get_category_ids())
            );
            if (ProjectedFieldOwnership::fingerprint($marketplace)
                !== ProjectedFieldOwnership::fingerprint(self::readField($wcProduct, $field))) {
                $pending = $marketplace;
            }
        }
        if ($pending !== '' && !self::writeField($wcProduct, $field, $pending)) {
            return false;
        }
        delete_post_meta($id, ProjectedFieldOwnership::PENDING_PREFIX . $field);
        delete_post_meta($id, ProjectedFieldOwnership::MANAGER_PREFIX . $field);
        // Re-read before stamping, for the reason the projector does: the
        // value WooCommerce keeps is not always the one it was handed.
        $fresh = wc_get_product($id);
        update_post_meta(
            $id,
            ProjectedFieldOwnership::STAMP_PREFIX . $field,
            ProjectedFieldOwnership::fingerprint(
                $fresh instanceof \WC_Product ? self::readField($fresh, $field) : $pending
            )
        );
        return true;
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
