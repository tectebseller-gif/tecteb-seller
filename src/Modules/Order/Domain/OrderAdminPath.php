<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * Which wp-admin address edits a WooCommerce order.
 *
 * ⛔ **WHY THIS IS A CLASS AND NOT A STRING.** Until this round the per-line
 * report built `post.php?post=<id>&action=edit` — the shape WooCommerce used
 * when an order was a post. The owner's staging runs **HPOS**, where orders
 * live in their own tables and that address opens nothing: `post.php` looks
 * the id up in `wp_posts`, finds no row, and shows «Invalid post ID». So the
 * single most useful link on the report was dead on the only installation it
 * had to work on, and nothing in PHP could notice, because building a string
 * always succeeds.
 *
 * The two shapes are a fact about WooCommerce, so they are written down once,
 * pure, and pinned by a test. Which of them applies is a fact about the SITE,
 * so it is asked of WooCommerce itself — see `OrderAdminUrlInterface`.
 *
 * Both shapes are relative to `admin_url()`: this class has no opinion about
 * the site's address and never touches WordPress.
 */
final class OrderAdminPath
{
    /**
     * @param bool $hpos whether WooCommerce stores orders in its own tables
     * @return string the path under wp-admin, never empty
     */
    public static function of(bool $hpos, int $wcOrderId): string
    {
        // An id of nought is not an order. Rather than build an address that
        // opens the order list and looks like a lost order, the caller is
        // handed the list on purpose — and `editable()` says so.
        if ($wcOrderId <= 0) {
            return $hpos ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order';
        }
        return $hpos
            ? 'admin.php?page=wc-orders&action=edit&id=' . $wcOrderId
            : 'post.php?post=' . $wcOrderId . '&action=edit';
    }

    /** Whether `of()` would address one order rather than the list. */
    public static function editable(int $wcOrderId): bool
    {
        return $wcOrderId > 0;
    }
}
