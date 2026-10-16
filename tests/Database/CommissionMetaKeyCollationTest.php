<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionMetaGuard;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\WpTectebCommissionSource;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the guard asks the DATABASE which keys are its own, and
 * so it refuses spellings PHP cannot recognise.
 *
 * This test needs real MariaDB and says why: the thing under test is a
 * **collation**. `alpha.59` answered the key question with
 * `strtolower(rtrim($key))` — an imitation of `utf8mb4_unicode_520_ci`
 * written in PHP — which covered the two spellings that had been measured
 * and missed the ones that had not. Measured here, against the column's own
 * collation:
 *
 *   '_TMC_COMMISSION_TYPE'                      = '_tmc_commission_type' → 1
 *   '_tmc_commission_type ' (trailing space)    = '_tmc_commission_type' → 1
 *   '_tmc_commission' + U+200B + '_type'        = '_tmc_commission_type' → 1
 *   '_<FULLWIDTH T>MC_COMMISSION_TYPE'          = '_tmc_commission_type' → 1
 *   '_tmccommission_type'                       = '_tmc_commission_type' → 0
 *   '-tmc-commission-type'                      = '_tmc_commission_type' → 0
 *
 * The last two are why PHP is still allowed to answer inside plain ASCII:
 * there, case and trailing padding are the only ways two keys collate equal,
 * and that is measured rather than assumed.
 */
final class CommissionMetaKeyCollationTest extends DatabaseTestCase
{
    private const PRODUCT = 8801;
    private const ZWSP = "\u{200B}";
    private const FULLWIDTH_T = "\u{FF34}";

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        TectebCommissionMetaGuard::register();
        State::$postMeta[self::PRODUCT] = [
            WpTectebCommissionSource::TYPE_META => 'percent',
            WpTectebCommissionSource::VALUE_META => '12.50',
        ];

        // The real column, with the collation this plugin actually meets.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->pdo()->exec("DROP TABLE IF EXISTS `{$wpdb->postmeta}`");
        $wpdb->pdo()->exec(
            "CREATE TABLE `{$wpdb->postmeta}` (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) DEFAULT NULL,
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
        );
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->pdo()->exec("DROP TABLE IF EXISTS `{$wpdb->postmeta}`");
        State::reset();
        parent::tearDown();
    }

    /** First, the premise: these spellings really are the same key here. */
    public function testTheCollationReallyDoesEquateTheseSpellings(): void
    {
        self::assertSame('1', $this->collationSays(self::ZWSP, 'inside'), 'a zero-width space is ignorable in a UCA collation');
        self::assertSame('1', $this->collationSays(self::FULLWIDTH_T, 'fullwidth'), 'a full-width letter folds to its ASCII form');
        self::assertSame('0', $this->collationSays('', 'no_underscore'));
        self::assertSame('0', $this->collationSays('', 'hyphens'));
        // A LEADING space is not ignorable — only a trailing one is, because
        // the collation is PAD SPACE. So ` _tmc_commission_value` really is a
        // different key, and the guard that asks the database lets an
        // unprivileged actor write it. That is the COST of asking instead of
        // guessing, and it is measured here rather than argued: the key is
        // not one anything of ours reads, and refusing every unusual key was
        // the wrong fix (`alpha.60`: one real variation key out of 972 rows
        // would have been refused with it).
        self::assertSame('0', $this->collationSays('', 'leading_space'));
    }

    /** And so the guard refuses them, for an actor who may not write ours. */
    public function testASpellingTheDatabaseCallsOursIsRefused(): void
    {
        State::loginAs(31, ['read', 'edit_products', 'manage_woocommerce']);

        $zwsp = '_tmc_commission' . self::ZWSP . '_type';
        $wide = '_' . self::FULLWIDTH_T . 'MC_COMMISSION_VALUE';

        self::assertFalse(update_post_meta(self::PRODUCT, $zwsp, 'fixed'), 'U+200B is ignorable: MySQL calls this the guarded key');
        self::assertFalse(update_post_meta(self::PRODUCT, $wide, '999999'), 'a full-width letter folds: same key');
        self::assertFalse(delete_post_meta(self::PRODUCT, $zwsp));

        self::assertSame('percent', get_post_meta(self::PRODUCT, WpTectebCommissionSource::TYPE_META, true));
        self::assertSame('12.50', get_post_meta(self::PRODUCT, WpTectebCommissionSource::VALUE_META, true));
    }

    /**
     * …and the guard stays narrow: a non-ASCII key that is NOT ours is
     * untouched. WooCommerce's own variation keys are percent-encoded
     * (`attribute_%d8%a7…`), so refusing every unusual key outright would
     * stop variations saving — which is why this asks the database instead.
     */
    public function testANonAsciiKeyThatIsNotOursStillSaves(): void
    {
        State::loginAs(31, ['read', 'edit_products', 'manage_woocommerce']);

        self::assertTrue(update_post_meta(self::PRODUCT, 'attribute_%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87', 'بزرگ'));
        self::assertTrue(update_post_meta(self::PRODUCT, '_اندازه', '7'));
        self::assertSame('7', get_post_meta(self::PRODUCT, '_اندازه', true));
    }

    /** The manager who may decide money never pays for a lookup at all. */
    public function testAnAuthorisedActorIsAnsweredBeforeTheKeyIsEvenLookedAt(): void
    {
        State::loginAs(7, ['read', Capabilities::REVIEW_WITHDRAWALS]);
        $before = $GLOBALS['wpdb']->num_queries;

        self::assertTrue(update_post_meta(self::PRODUCT, '_tmc_commission' . self::ZWSP . '_type', 'fixed'));

        self::assertSame(
            $before,
            $GLOBALS['wpdb']->num_queries,
            'an administrator saving a product writes dozens of meta rows; none may cost a collation lookup'
        );
    }

    private function collationSays(string $inserted, string $case): string
    {
        $wpdb = $GLOBALS['wpdb'];
        $candidate = match ($case) {
            'inside' => '_tmc_commission' . $inserted . '_type',
            'fullwidth' => '_' . self::FULLWIDTH_T . 'MC_COMMISSION_TYPE',
            'no_underscore' => '_tmccommission_type',
            'hyphens' => '-tmc-commission-type',
            'leading_space' => ' _tmc_commission_type',
        };

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_520_ci'
            . ' = CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_520_ci',
            $candidate,
            WpTectebCommissionSource::TYPE_META
        ));
    }
}
