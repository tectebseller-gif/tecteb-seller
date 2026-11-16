<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontImageReaderInterface;

/**
 * The featured image of a storefront product, by id, in one query.
 *
 * ⛔ WHY THIS CLASS EXISTS, measured on the source: `ProductArea::listThumbnails()`
 * resolves a row's picture from `tmc_products.main_image_id` and the gallery
 * rows beside it. An intake writes neither — `ImportFromDokan` and
 * `ImportExistingProducts` record the fields a marketplace row owns and
 * deliberately copy no media (see `ProductImageLibraryInterface::duplicateForVendor()`
 * for why a copy is the only honest way to take one over) — so for an imported
 * product that resolver had no id to resolve, handed the view an empty string,
 * and the view drew its «no picture» placeholder beside a product whose photo
 * is on the shop page right now.
 *
 * ONE INDEXED QUERY for a page of rows, over `postmeta(post_id, meta_key)`,
 * rather than a `wc_get_product()` per row: twenty product loads for twenty
 * thumbnails is the shape `alpha.91` opened on the handover screen, and a list
 * is the one place it costs the most.
 *
 * `_thumbnail_id` is WordPress's own key, not WooCommerce's, and is read as
 * such. The URL is then resolved through `wp_get_attachment_image_src()` so
 * the size WordPress generated is what the browser fetches, with
 * `wp_get_attachment_url()` behind it for a library that never made the
 * thumbnail size.
 *
 * ⛔ It cannot write. There is no setter, no `save()`, no `update_post_meta()`,
 * no `wp_insert_attachment()` and no `wp_delete_*` in this file, and
 * `StorefrontImageReaderIsReadOnlyTest` reads these bytes and keeps it so.
 */
final class WcStorefrontImages implements StorefrontImageReaderInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function thumbnailUrls(array $wcProductIds): array
    {
        $ids = [];
        foreach ($wcProductIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === [] || !function_exists('wp_get_attachment_image_src')) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT post_id, meta_value FROM `' . $this->db->prefix() . 'postmeta`
             WHERE meta_key = \'_thumbnail_id\' AND post_id IN (' . $placeholders . ')',
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['post_id'] ?? 0);
            $attachmentId = (int) ($row['meta_value'] ?? 0);
            if ($productId <= 0 || $attachmentId <= 0 || !in_array($productId, $ids, true)) {
                continue;
            }
            $url = $this->urlOf($attachmentId);
            // ABSENT rather than empty: «this product has no picture» and
            // «the attachment this product points at is gone» are the same
            // thing to a list that draws a placeholder, and neither is a URL.
            if ($url !== '') {
                $out[$productId] = $url;
            }
        }
        return $out;
    }

    private function urlOf(int $attachmentId): string
    {
        $src = wp_get_attachment_image_src($attachmentId, 'thumbnail');
        if (is_array($src) && isset($src[0]) && (string) $src[0] !== '') {
            return (string) $src[0];
        }
        if (!function_exists('wp_get_attachment_url')) {
            return '';
        }
        $url = wp_get_attachment_url($attachmentId);
        return is_string($url) ? $url : '';
    }
}
