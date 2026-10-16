<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\ProductIntakePage;
use TmcWpStubs\RedirectedException;
use TmcWpStubs\State;

/**
 * The address «ورود محصولات موجود» used to live at.
 *
 * `alpha.55` gave the intake a menu entry of its own; `alpha.56` folded it
 * into «بررسی محصولات» and removed the entry. What must NOT happen is the
 * thing WordPress does by default for a `page=` slug nothing registered:
 * «Sorry, you are not allowed to access this page». A manager who bookmarked
 * the old screen, or a line in `docs/` that names it, would read that as a
 * permission problem and go looking for a capability.
 *
 * «نشانی قدیمی با حفظ دسترسی و پارامترهای معتبر به مسیر مناسب هدایت شود» —
 * so three things are proved here: it redirects, it redirects somewhere
 * USEFUL (the one list, narrowed to what the old page showed), and it does
 * not redirect for somebody who may not do intakes.
 */
final class RetiredIntakeAddressTest extends TestCase
{
    protected function setUp(): void
    {
        State::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        State::$throwOnRedirect = false;
    }

    public function testTheOldAddressGoesToTheOneList(): void
    {
        $_GET = ['page' => ProductIntakePage::SLUG];
        State::$currentUserCaps = [ProductIntakePage::CAPABILITY];
        State::$throwOnRedirect = true;

        try {
            ProductIntakePage::redirectOldAddress('tmc-product-review');
            self::fail('the old address must not simply render nothing');
        } catch (RedirectedException) {
            // The request ended, which is the outcome under test.
        }

        self::assertCount(1, State::$redirects);
        $location = (string) State::$redirects[0]['location'];
        self::assertStringContainsString('page=tmc-product-review', $location);
        self::assertStringContainsString(
            'source=' . CatalogueFacets::SOURCE_WOOCOMMERCE,
            $location,
            'and it lands on what that page used to show: the products with no marketplace row'
        );
        self::assertSame(302, (int) State::$redirects[0]['status'], 'moved, not moved permanently');
    }

    /**
     * A user without the intake capability is NOT redirected.
     *
     * Not an unkindness: WordPress's own refusal screen is the one the rest
     * of wp-admin uses, and a redirect would tell somebody who may not see
     * either page where the thing they may not see has gone.
     */
    public function testSomebodyWhoMayNotTakeProductsInIsLeftToWordPress(): void
    {
        $_GET = ['page' => ProductIntakePage::SLUG];
        State::$currentUserCaps = [];

        ProductIntakePage::redirectOldAddress('tmc-product-review');

        self::assertSame([], State::$redirects);
    }

    /** And every other page in wp-admin is untouched. */
    public function testEveryOtherAddressIsLeftAlone(): void
    {
        State::$currentUserCaps = [ProductIntakePage::CAPABILITY];
        foreach ([[], ['page' => 'tmc-product-review'], ['page' => 'woocommerce']] as $query) {
            $_GET = $query;
            ProductIntakePage::redirectOldAddress('tmc-product-review');
        }

        self::assertSame([], State::$redirects);
    }
}
