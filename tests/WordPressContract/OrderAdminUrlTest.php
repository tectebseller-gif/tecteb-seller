<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce\WcOrderAdminUrl;

/**
 * WHAT THIS PROVES: the order link asks WooCommerce where its orders live,
 * and refuses rather than guessing.
 *
 * ⛔ **THE DEFECT.** `alpha.99` built `post.php?post=<id>&action=edit` for
 * every row of the per-line report. The owner's staging runs HPOS, where that
 * address finds no post and shows «Invalid post ID» — so the report's one
 * useful link was dead on the only installation it had to work on, and no
 * suite noticed, because building a string always succeeds.
 *
 * Process-isolated because every case has to define `class WooCommerce` and a
 * different `OrderUtil`, and `WpDependencyProbeTest` is about that class being
 * ABSENT (the same reason `ProjectedFactsTest` is isolated).
 */
final class OrderAdminUrlTest extends ContractTestCase
{
    /** `OrderUtil` with its own edit-url helper — WooCommerce's own answer. */
    private static function utilWithEditUrl(string $prefix): void
    {
        eval('namespace Automattic\WooCommerce\Utilities; class OrderUtil {
            public static function get_order_admin_edit_url(int $id): string {
                return "' . $prefix . '" . $id;
            }
            public static function custom_orders_table_usage_is_enabled(): bool { return true; }
        }');
    }

    /** An older `OrderUtil`: it can only say where orders are stored. */
    private static function utilWithStorageOnly(bool $hpos): void
    {
        eval('namespace Automattic\WooCommerce\Utilities; class OrderUtil {
            public static function custom_orders_table_usage_is_enabled(): bool {
                return ' . ($hpos ? 'true' : 'false') . ';
            }
        }');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWooCommercesOwnAnswerIsPreferredWhenItHasOne(): void
    {
        eval('class WooCommerce {}');
        // ⛔ AN ADDRESS THE FALLBACK COULD NOT INVENT. The first version of
        // this handed back exactly the string the HPOS branch builds, so
        // deleting the «ask WooCommerce» branch altogether left the test
        // green — it was asserting the SHAPE, not the SOURCE. A query
        // argument nothing else writes is what makes the two distinguishable.
        self::utilWithEditUrl(
            'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&woo=1&id='
        );
        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&woo=1&id=12300',
            (new WcOrderAdminUrl())->editUrl(12300),
            'WooCommerce knows where its own orders are, and is asked first'
        );
    }

    /**
     * ⛔ THE CASE THE OWNER'S STAGING IS IN. Orders in WooCommerce's own
     * tables, an `OrderUtil` without the helper: the storage question is asked
     * and the HPOS shape is built — NOT `post.php`.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithHposTheOrdersScreenIsAddressedAndNotPostPhp(): void
    {
        eval('class WooCommerce {}');
        self::utilWithStorageOnly(true);
        $url = (new WcOrderAdminUrl())->editUrl(12300);
        self::assertIsString($url);
        self::assertStringContainsString('page=wc-orders', $url);
        self::assertStringContainsString('id=12300', $url);
        self::assertStringNotContainsString('post.php', $url, 'the alpha.99 defect');
    }

    /** And without HPOS the post shape is still the right one. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutHposThePostShapeIsUsed(): void
    {
        eval('class WooCommerce {}');
        self::utilWithStorageOnly(false);
        $url = (new WcOrderAdminUrl())->editUrl(12300);
        self::assertIsString($url);
        self::assertStringContainsString('post.php?post=12300', $url);
        self::assertStringNotContainsString('wc-orders', $url);
    }

    /**
     * ⛔ NO WooCommerce MEANS NO LINK — not a guessed one. A wrong guess sends
     * a manager to «Invalid post ID» while LOOKING like a working link, and
     * the report prints the number with a reason instead (`alpha.88`).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutWooCommerceThereIsNoLinkAtAll(): void
    {
        self::assertNull((new WcOrderAdminUrl())->editUrl(12300));
    }

    /** An id of nought is not an order, whatever the storage mode. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoughtIsNotAnOrder(): void
    {
        eval('class WooCommerce {}');
        self::utilWithStorageOnly(true);
        $urls = new WcOrderAdminUrl();
        self::assertNull($urls->editUrl(0));
        self::assertNull($urls->editUrl(-1));
        self::assertIsString($urls->editUrl(1));
    }

    /**
     * ⛔ A WooCommerce TOO OLD FOR `OrderUtil` HAS NO HPOS, so the post shape
     * is the only shape it can have — a fact rather than a guess, and the one
     * place a fallback here is defensible.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAWooCommerceWithoutOrderUtilGetsThePostShape(): void
    {
        eval('class WooCommerce {}');
        $url = (new WcOrderAdminUrl())->editUrl(12300);
        self::assertIsString($url);
        self::assertStringContainsString('post.php?post=12300', $url);
    }

    /**
     * ⛔ AND A HELPER THAT THROWS DOES NOT COST THE LINK. Falling through to
     * the storage question is the difference between «WooCommerce had a bad
     * moment» and «there is no link for this order».
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAHelperThatThrowsFallsBackToTheStorageQuestion(): void
    {
        eval('class WooCommerce {}');
        eval('namespace Automattic\WooCommerce\Utilities; class OrderUtil {
            public static function get_order_admin_edit_url(int $id): string {
                throw new \RuntimeException("no");
            }
            public static function custom_orders_table_usage_is_enabled(): bool { return true; }
        }');
        $url = (new WcOrderAdminUrl())->editUrl(12300);
        self::assertIsString($url);
        self::assertStringContainsString('page=wc-orders', $url);
    }

    /**
     * The storage answer is memoised PER INSTANCE. A `static` memo would
     * outlive a test that changes the mode and answer the stale question —
     * the trap `alpha.67` paid for — so two instances ask twice.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheStorageAnswerIsAskedOncePerInstance(): void
    {
        eval('class WooCommerce {}');
        eval('namespace Automattic\WooCommerce\Utilities; class OrderUtil {
            public static int $asked = 0;
            public static function custom_orders_table_usage_is_enabled(): bool {
                self::$asked++;
                return true;
            }
        }');
        $urls = new WcOrderAdminUrl();
        $urls->editUrl(1);
        $urls->editUrl(2);
        $urls->editUrl(3);
        $util = 'Automattic\WooCommerce\Utilities\OrderUtil';
        self::assertSame(1, $util::$asked, 'once for three rows');
        (new WcOrderAdminUrl())->editUrl(4);
        self::assertSame(2, $util::$asked, 'and a new instance asks again');
    }
}
