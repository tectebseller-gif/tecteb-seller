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
    private const TYPE_ROW = 9001;
    private const VALUE_ROW = 9002;

    protected function setUp(): void
    {
        State::reset();
        TectebCommissionMetaGuard::register();
        // A rule a manager already decided on, so «refused» can be told apart
        // from «there was nothing there anyway» — seeded in BOTH shapes,
        // because WordPress addresses a meta row two ways and WooCommerce
        // uses both.
        State::$postMeta[self::PRODUCT] = [self::TYPE => 'percent', self::VALUE => '12.50'];
        State::$metaRows = [
            self::TYPE_ROW => ['meta_id' => self::TYPE_ROW, 'post_id' => self::PRODUCT, 'meta_key' => self::TYPE, 'meta_value' => 'percent'],
            self::VALUE_ROW => ['meta_id' => self::VALUE_ROW, 'post_id' => self::PRODUCT, 'meta_key' => self::VALUE, 'meta_value' => '12.50'],
        ];
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
        $verbs = [
            'add_post_metadata' => 'decide',
            'update_post_metadata' => 'decide',
            'delete_post_metadata' => 'decide',
            // The two `alpha.58` missed — and the ones WooCommerce uses.
            'update_post_metadata_by_mid' => 'decideByRow',
            'delete_post_metadata_by_mid' => 'decideByRow',
        ];
        foreach ($verbs as $filter => $method) {
            $registered = [];
            foreach (State::$hooks[$filter] ?? [] as $callbacks) {
                foreach ($callbacks as $callback) {
                    $registered[] = $callback;
                }
            }
            self::assertContains(
                [TectebCommissionMetaGuard::class, $method],
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
     * ⛔ THE HOLE `alpha.58` SHIPPED, and the path it was on.
     *
     * `WC_Data_Store_WP::update_meta()` calls `update_metadata_by_mid()` and
     * `delete_meta()` calls `delete_metadata_by_mid()`
     * (`class-wc-data-store-wp.php:132,155`) — so a REST `meta_data` entry
     * carrying an `id`, which is what the REST API hands out for every row,
     * never touches the three key-addressed filters at all. Measured on
     * `alpha.58` with a real `shop_manager`:
     * `after_wc_object_save=999999`.
     */
    public function testTheRowAddressedVerbsAreGuardedToo(): void
    {
        State::loginAs(31, ['read', 'edit_products', 'manage_woocommerce']);

        self::assertFalse(
            update_metadata_by_mid('post', self::VALUE_ROW, '999999'),
            'the row-addressed update is the path WooCommerce itself takes'
        );
        self::assertFalse(delete_metadata_by_mid('post', self::TYPE_ROW));

        self::assertSame('12.50', get_post_meta(self::PRODUCT, self::VALUE, true));
        self::assertSame('percent', get_post_meta(self::PRODUCT, self::TYPE, true));
    }

    /** And a rename cannot carry a row INTO the guarded keys either. */
    public function testARenameCannotMoveARowIntoOrOutOfTheGuardedKeys(): void
    {
        State::$metaRows[9003] = ['meta_id' => 9003, 'post_id' => self::PRODUCT, 'meta_key' => '_something_else', 'meta_value' => 'x'];
        State::$postMeta[self::PRODUCT]['_something_else'] = 'x';
        State::loginAs(31, ['read', 'edit_products', 'manage_woocommerce']);

        // Renaming an unguarded row INTO a guarded key would install a rule.
        self::assertFalse(update_metadata_by_mid('post', 9003, 'fixed', self::TYPE));
        self::assertSame('percent', get_post_meta(self::PRODUCT, self::TYPE, true));

        // …and renaming a guarded row OUT would remove one.
        self::assertFalse(update_metadata_by_mid('post', self::TYPE_ROW, 'percent', '_laundered'));
        self::assertSame('percent', get_post_meta(self::PRODUCT, self::TYPE, true));

        // The manager who may decide money is still not in the way.
        State::loginAs(7, ['read', Capabilities::REVIEW_WITHDRAWALS]);
        self::assertTrue(update_metadata_by_mid('post', self::VALUE_ROW, '20000'));
        self::assertSame('20000', get_post_meta(self::PRODUCT, self::VALUE, true));
    }

    /**
     * ⛔ THE SECOND HOLE: `===` is not how MySQL compares a meta key.
     *
     * `wp_postmeta.meta_key` is `utf8mb4_unicode_520_ci` — case-insensitive
     * and PAD SPACE — so `WHERE meta_key = '_TMC_COMMISSION_VALUE'` finds the
     * lower-case row and overwrites its value. Measured on `alpha.58`:
     * `uppercase_write=landed value_after_uppercase=777777 rows_for_value=1`.
     *
     * The stubs cannot model a collation, so this asserts the GUARD'S answer
     * rather than the row's — stated plainly: it proves the comparison is
     * case-folded and right-trimmed, and the measurement on the real database
     * is what proves that matters.
     */
    public function testAKeyMySQLCallsTheSameIsRefusedWhateverItsCase(): void
    {
        State::loginAs(31, ['read', 'edit_products', 'manage_woocommerce']);

        foreach ([
            '_TMC_COMMISSION_VALUE',
            '_Tmc_Commission_Value',
            '_tmc_commission_value ',
            '_tmc_commission_value' . "\t",
            '_TMC_COMMISSION_TYPE ',
        ] as $spelling) {
            self::assertFalse(
                update_post_meta(self::PRODUCT, $spelling, 'fixed'),
                'MySQL would match this spelling to a guarded row: ' . $spelling
            );
        }

        // A key that merely STARTS the same is somebody else's and untouched.
        // Plain ASCII, so PHP answers it with no lookup at all — measured:
        // within `[A-Za-z0-9_-]` the collation equates only case and
        // trailing padding, so an ASCII key that is not ours cannot become
        // one.
        self::assertTrue(update_post_meta(self::PRODUCT, '_tmc_commission_value_note', 'x'));
        self::assertTrue(update_post_meta(self::PRODUCT, '_tmc_commission_valu', 'x'));
        self::assertTrue(update_post_meta(self::PRODUCT, 'tmc_commission_value', 'x'));
    }

    /**
     * ⛔ THE THIRD HOLE, and the shape of the conservative fallback.
     *
     * `alpha.59` answered the key question with `strtolower(rtrim($key))` —
     * **a Unicode collation imitated in PHP**. It covered the two spellings
     * that had been measured (case, trailing space) and missed the ones that
     * had not: a zero-width space is IGNORABLE in a UCA collation and a
     * full-width letter folds to its ASCII form, both measured `= 1` against
     * `utf8mb4_unicode_520_ci`. The guard now asks the DATABASE for any key
     * outside plain ASCII.
     *
     * HERE there is no readable `meta_key` column — these are the stubs — so
     * this test pins the FALLBACK rather than the comparison: when the
     * question cannot be asked, the answer is «ours», and the write is
     * refused. «نمی‌دانم» is not «مال ما نیست» (`alpha.49`). The
     * comparison itself is measured against real MariaDB in
     * `CommissionMetaKeyCollationTest`, and the cost of this fallback — one
     * unusual key a non-privileged actor cannot write — is stated in the
     * guard's own docblock rather than discovered later.
     */
    public function testWithNoReadableCollationAnUnusualKeyIsRefusedRatherThanAssumedSafe(): void
    {
        State::loginAs(31, ['read', 'edit_products', 'manage_woocommerce']);

        self::assertFalse(update_post_meta(self::PRODUCT, '_tmc_commission' . "\u{200B}" . '_type', 'fixed'));
        self::assertFalse(update_post_meta(self::PRODUCT, ' _tmc_commission_value', 'x'));

        // …and the manager is still not refused, whatever the key looks like.
        State::loginAs(7, ['read', Capabilities::REVIEW_WITHDRAWALS]);
        self::assertTrue(update_post_meta(self::PRODUCT, '_tmc_commission' . "\u{200B}" . '_type', 'fixed'));
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
