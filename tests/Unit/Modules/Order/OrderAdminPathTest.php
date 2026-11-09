<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Domain\OrderAdminPath;

/**
 * WHAT THIS PROVES: the two shapes WooCommerce edits an order through, pinned.
 *
 * ⛔ **THE DEFECT THIS CLOSES.** `alpha.99`'s per-line report built
 * `post.php?post=<id>&action=edit` for every row. That is the address of an
 * order stored as a POST, and the owner's staging runs HPOS — where orders
 * live in their own tables and `post.php` finds no row and shows «Invalid post
 * ID». The single most useful link on the report was therefore dead on the
 * only installation it had to work on, and no suite could see it, because
 * building a string always succeeds.
 *
 * The test is a string comparison on purpose. There is nothing clever to
 * assert here; what was missing was anybody writing the second shape down.
 */
final class OrderAdminPathTest extends TestCase
{
    public function testAnOrderInWooCommercesOwnTablesIsEditedThroughTheOrdersScreen(): void
    {
        self::assertSame('admin.php?page=wc-orders&action=edit&id=12300', OrderAdminPath::of(true, 12300));
    }

    public function testAnOrderStoredAsAPostIsEditedThroughPostPhp(): void
    {
        self::assertSame('post.php?post=12300&action=edit', OrderAdminPath::of(false, 12300));
    }

    /**
     * ⛔ The two shapes are never the same string, for any id — which is the
     * whole reason guessing one of them was a defect rather than a detail.
     */
    public function testTheTwoShapesNeverAgree(): void
    {
        foreach ([1, 2, 99, 12299, 12300, PHP_INT_MAX] as $id) {
            self::assertNotSame(
                OrderAdminPath::of(true, $id),
                OrderAdminPath::of(false, $id),
                'id ' . $id
            );
        }
    }

    /**
     * An id of nought addresses the LIST, and `editable()` says so — so a
     * caller cannot print «open order #۰» as though it were an order.
     */
    public function testNoughtIsNotAnOrderAndAddressesTheListInstead(): void
    {
        self::assertFalse(OrderAdminPath::editable(0));
        self::assertFalse(OrderAdminPath::editable(-5));
        self::assertTrue(OrderAdminPath::editable(1));
        self::assertSame('admin.php?page=wc-orders', OrderAdminPath::of(true, 0));
        self::assertSame('edit.php?post_type=shop_order', OrderAdminPath::of(false, 0));
        self::assertSame('admin.php?page=wc-orders', OrderAdminPath::of(true, -3));
    }

    /**
     * Relative, always: this class has no opinion about the site's address.
     * A path that began with `http` or `/` would be a second place that
     * decides where wp-admin is.
     */
    public function testEveryPathIsRelativeToWpAdmin(): void
    {
        foreach ([true, false] as $hpos) {
            foreach ([0, 1, 12300] as $id) {
                $path = OrderAdminPath::of($hpos, $id);
                self::assertStringStartsNotWith('/', $path);
                self::assertStringStartsNotWith('http', $path);
                self::assertNotSame('', $path);
            }
        }
    }
}
