<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionMetaGuard;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\WpTectebCommissionSource;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: «کمیسیون تک‌طب» cannot be moved by a logged-in actor who
 * does not hold the financial permission — on ANY path that ends in post
 * meta, which is every path WooCommerce offers.
 *
 * Why this test and not the panel's: the panel checks its nonce and the
 * capability, and that is a check on **one door**. Clause ۵-هـ names four
 * («فرم، CSV، درخواست دست‌کاری‌شده یا API»), and two of them belong to
 * WooCommerce, not to us: `WC_REST_Products_Controller` hands a request's
 * `meta_data` to `update_meta_data()`, and `WC_Product_CSV_Importer` turns a
 * `meta:<key>` column into the same call — both permitted by
 * `edit_products` / `manage_woocommerce`, which a `shop_manager` has and
 * which is NOT the marketplace's financial permission. So the assertion
 * below is deliberately made through `update_post_meta()` itself, the call
 * all of those end in, rather than through any of our own classes.
 *
 * These are the stubs, and that is stated: they run WordPress's
 * `update_post_metadata` / `delete_post_metadata` short-circuit filters
 * exactly as core does (returning `(bool) $check` when it is not null), which
 * is the contract this guard depends on. It is NOT evidence about a real REST
 * request; that stays `Not Run`.
 */
final class CommissionMetaGuardTest extends TestCase
{
    private const PRODUCT = 7701;
    private const TYPE = WpTectebCommissionSource::TYPE_META;
    private const VALUE = WpTectebCommissionSource::VALUE_META;

    protected function setUp(): void
    {
        State::reset();
        TectebCommissionMetaGuard::register();
        // A rule a manager already decided on, so «refused» can be told apart
        // from «there was nothing there anyway».
        State::$postMeta[self::PRODUCT] = [self::TYPE => 'percent', self::VALUE => '12.50'];
    }

    protected function tearDown(): void
    {
        State::reset();
    }

    /** All three verbs are guarded, including the one the stubs do not model. */
    public function testTheGuardSitsOnAddUpdateAndDelete(): void
    {
        // Read from the stubs' own hook registry rather than through
        // `has_filter()`, which these stubs do not model: asserting a fact
        // through a function that does not exist proves nothing about either.
        foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $filter) {
            $registered = [];
            foreach (State::$hooks[$filter] ?? [] as $callbacks) {
                foreach ($callbacks as $callback) {
                    $registered[] = $callback;
                }
            }
            self::assertContains(
                [TectebCommissionMetaGuard::class, 'decide'],
                $registered,
                $filter . ' must be guarded — a door left open is the whole defect'
            );
        }
    }

    /**
     * §5-هـ: the path WooCommerce's REST API and CSV importer both end in.
     */
    public function testAStaffMemberWithoutTheFinancialPermissionCannotMoveTheRule(): void
    {
        State::loginAs(31, ['read', 'edit_posts', 'edit_products', 'manage_woocommerce']);

        self::assertFalse(
            update_post_meta(self::PRODUCT, self::TYPE, 'fixed'),
            'the write must be REFUSED, and `false` is what update_post_meta() already means by that'
        );
        self::assertFalse(update_post_meta(self::PRODUCT, self::VALUE, '1'));
        self::assertFalse(delete_post_meta(self::PRODUCT, self::TYPE));

        self::assertSame('percent', get_post_meta(self::PRODUCT, self::TYPE, true));
        self::assertSame('12.50', get_post_meta(self::PRODUCT, self::VALUE, true));
    }

    /** And the manager who may decide money is not in the way. */
    public function testTheManagerWithTheFinancialPermissionWrites(): void
    {
        State::loginAs(7, ['read', 'edit_post', Capabilities::REVIEW_WITHDRAWALS]);

        self::assertTrue(update_post_meta(self::PRODUCT, self::TYPE, 'fixed'));
        self::assertTrue(update_post_meta(self::PRODUCT, self::VALUE, '20000'));
        self::assertSame('fixed', get_post_meta(self::PRODUCT, self::TYPE, true));

        self::assertTrue(delete_post_meta(self::PRODUCT, self::TYPE));
        self::assertFalse(metadata_exists('post', self::PRODUCT, self::TYPE));
    }

    /** The guard is narrow: it is about two keys, not about product meta. */
    public function testEveryOtherMetaKeyIsUntouched(): void
    {
        State::loginAs(31, ['read', 'edit_products']);

        self::assertTrue(update_post_meta(self::PRODUCT, '_price', '450000'));
        self::assertTrue(update_post_meta(self::PRODUCT, '_stock', '12'));
        self::assertTrue(update_post_meta(self::PRODUCT, '_tmc_max_purchase', '6'));
        self::assertSame('450000', get_post_meta(self::PRODUCT, '_price', true));
    }

    /**
     * The STATED LIMIT, asserted rather than left to a sentence: a request
     * with no logged-in actor is not refused.
     *
     * WP-CLI, cron and a migration have nobody to authorise. Refusing them
     * would break this plugin's own reset tools (`tools/alpha57-ui-state.php`,
     * `tools/upgrade-alpha56-state.php`) — the `alpha.33` trap, where a
     * fixture that cannot give back what it spent fails the NEXT run with a
     * correct answer — and would stop nobody: every door clause ۵-هـ names
     * arrives with a user, REST key authentication and application passwords
     * included, because WooCommerce resolves those before the write.
     */
    public function testARequestWithNoUserIsNotRefusedAndThisIsTheLimit(): void
    {
        State::logout();

        self::assertSame(0, get_current_user_id());
        self::assertTrue(update_post_meta(self::PRODUCT, self::TYPE, 'none'));
        self::assertSame('none', get_post_meta(self::PRODUCT, self::TYPE, true));
    }

    /**
     * Falsification: without the guard, the unauthorised write lands. A test
     * whose subject can be removed without it failing is testing nothing.
     */
    public function testWithoutTheGuardTheSameWriteWouldLand(): void
    {
        remove_filter('update_post_metadata', [TectebCommissionMetaGuard::class, 'decide'], 10);
        State::loginAs(31, ['read', 'edit_products', 'manage_woocommerce']);

        self::assertTrue(update_post_meta(self::PRODUCT, self::TYPE, 'fixed'));
        self::assertSame('fixed', get_post_meta(self::PRODUCT, self::TYPE, true));
    }
}
