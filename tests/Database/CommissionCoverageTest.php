<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionCoverage;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FakeTectebCommissionSource;

/**
 * WHAT THIS PROVES: how many marketplace products would earn the marketplace
 * NOTHING — and that the count comes from the reader a SALE uses.
 *
 * The question exists because of three decisions that are each correct and
 * together invisible: a sale reads only this plugin's own two meta keys,
 * nothing in `src/` reads any Dokan commission key, and a missing rule is
 * `none` — a RESULT, not a refusal. So after Dokan is gone a product whose
 * commission only ever lived in Dokan's metadata sells at zero, with a ledger
 * that balances. Nothing was broken; nothing COUNTED.
 *
 * On real MariaDB, because the thing that could silently break is the paging:
 * `forManager()` is asked page after page, and a reader that returned the
 * first page for ever would give a plausible, wrong, small number.
 *
 * The commission source is a double and says so — the real one reads post
 * meta, which this suite has no WooCommerce to hold. What is real here is
 * every row, the paging, and the arithmetic.
 */
final class CommissionCoverageTest extends DatabaseTestCase
{
    private const VENDOR = 8801;

    private WpDatabase $db;
    private DbProductRepository $products;
    private FakeTectebCommissionSource $rules;
    private CommissionCoverage $coverage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        (new DbVendorRepository($this->db, $clock))
            ->upsertProfile(self::VENDOR, 'فروشگاه کمیسیون', true, false);
        $this->rules = new FakeTectebCommissionSource();
        $this->coverage = new CommissionCoverage($this->products, $this->rules);
    }

    /** One marketplace product per answer, and each lands in its own bucket. */
    public function testEachProductLandsInExactlyOneBucket(): void
    {
        $earns = $this->marketplaceProduct(7001, 'با کمیسیون');
        $decided = $this->marketplaceProduct(7002, 'بدون کمیسیون، با تصمیم');
        $silent = $this->marketplaceProduct(7003, 'بی قاعده');

        $this->rules->set(7001, TectebCommission::percent(1250));
        $this->rules->set(7002, TectebCommission::none());
        // 7003 deliberately gets nothing: that is the bucket this card is for.

        $answer = $this->coverage->scan();

        self::assertSame(3, $answer['rows']);
        self::assertSame(1, $answer['counts'][CommissionCoverage::EARNS]);
        self::assertSame(1, $answer['counts'][CommissionCoverage::DECIDED_NONE]);
        self::assertSame(1, $answer['counts'][CommissionCoverage::NO_RULE]);
        self::assertSame(0, $answer['counts'][CommissionCoverage::NO_STOREFRONT]);
        self::assertSame([$silent], $answer['uncovered']);
        self::assertSame(1, $answer['uncovered_total']);
        self::assertTrue($answer['named_all']);
        self::assertTrue($answer['finished']);
        self::assertNotContains($earns, $answer['uncovered']);
        self::assertNotContains($decided, $answer['uncovered']);
    }

    /**
     * ⛔ «بدون کمیسیون» DECIDED is not the same answer as nobody deciding.
     *
     * Both earn the marketplace nothing and both let the sale through, so a
     * card that merged them would hand the manager a number they cannot act
     * on: one half needs a decision and the other half IS one.
     */
    public function testADecidedNoneIsNotCountedAsMissing(): void
    {
        $this->marketplaceProduct(7101, 'تصمیمِ صریح');
        $this->rules->set(7101, TectebCommission::none());

        $answer = $this->coverage->scan();

        self::assertSame(1, $answer['counts'][CommissionCoverage::DECIDED_NONE]);
        self::assertSame(0, $answer['counts'][CommissionCoverage::NO_RULE]);
        self::assertSame([], $answer['uncovered']);
    }

    /**
     * An `observed` row is a product this marketplace does not sell yet.
     *
     * Counting it would inflate the one number a person acts on, and the
     * action it suggests — set a commission — is not even available until the
     * row is transferred.
     */
    public function testAnObservedRowIsNotCounted(): void
    {
        $this->marketplaceProduct(7201, 'مالِ ما');
        $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'فقط مشاهده‌شده', priceMinor: 1000),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            7202
        );

        $answer = $this->coverage->scan();

        self::assertSame(1, $answer['rows'], 'only the marketplace row is counted');
        self::assertSame(1, $answer['counts'][CommissionCoverage::NO_RULE]);
    }

    /** A marketplace row with no WooCommerce product has its own answer. */
    public function testARowWithNoStorefrontProductIsNamedRatherThanCountedAsMissing(): void
    {
        $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'بی محصول ووکامرس', priceMinor: 1000),
            ProductStatus::Draft
        );

        $answer = $this->coverage->scan();

        self::assertSame(1, $answer['counts'][CommissionCoverage::NO_STOREFRONT]);
        self::assertSame(0, $answer['counts'][CommissionCoverage::NO_RULE]);
        self::assertSame([], $answer['uncovered']);
    }

    /**
     * ⛔ THE PAGING, on more rows than one page holds.
     *
     * A reader that answered the first page for ever would report a small,
     * plausible, wrong number — and on a catalogue of fifty it would look
     * right. So this plants one more than `PAGE` and asks for the total.
     */
    public function testItReadsPastTheFirstPage(): void
    {
        $planted = CommissionCoverage::PAGE + 1;
        for ($i = 0; $i < $planted; $i++) {
            $this->marketplaceProduct(7300 + $i, 'محصول ' . $i);
        }

        $answer = $this->coverage->scan();

        self::assertSame($planted, $answer['rows']);
        self::assertSame($planted, $answer['counts'][CommissionCoverage::NO_RULE]);
        self::assertSame($planted, $answer['uncovered_total']);
        self::assertTrue($answer['finished']);
    }

    /** The scan writes nothing — not a row, not an option. */
    public function testTheScanWritesNothing(): void
    {
        $this->marketplaceProduct(7401, 'محصول');
        $before = $this->rowSnapshot();

        $this->coverage->scan();

        self::assertSame($before, $this->rowSnapshot());
    }

    private function marketplaceProduct(int $wcId, string $title): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(title: $title, priceMinor: 1000),
            ProductStatus::Draft,
            LinkOwnership::Marketplace,
            $wcId
        );
    }

    /** @return array<string,string> */
    private function rowSnapshot(): array
    {
        $rows = $this->db->getResults(
            'SELECT id, vendor_user_id, status, link_ownership, row_version FROM `'
            . $this->db->prefix() . 'tmc_products` ORDER BY id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['id']] = implode('|', array_map('strval', $row));
        }
        return $out;
    }
}
