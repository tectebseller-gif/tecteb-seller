<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbBrandProposalRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbReviewSeenStore;
use TmcWpStubs\State;

/**
 * ONE definition of «needs review», and every number that reads it — §5.
 *
 * The owner's screen said «در انتظار بررسی ۰» while the card under a published
 * product said a proposal was waiting and the line above the table said one
 * product had one. Three numbers, three questions, one page: the chip counted
 * products in the `submitted` STATUS, the line counted pending REVISIONS, and
 * the queue's own counter counted something else again.
 *
 * So the set is defined once, in `ReviewQueueSql::waiting()`, and these tests
 * assert that the counter, the filter, the list and the red badge all describe
 * it — including the part that is easy to get wrong: a product waiting on three
 * different things is still ONE product.
 */
final class ReviewQueueDefinitionTest extends DatabaseTestCase
{
    private const VENDOR = 7;
    private const ANA = 41;

    private WpDatabase $db;
    private DbProductRepository $products;
    private DbProductRevisionRepository $revisions;
    private DbBrandProposalRepository $brandRequests;
    private DbProductDecisionRepository $decisions;
    private ReviewSeen $seen;

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        $this->revisions = new DbProductRevisionRepository($this->db, $clock);
        $this->brandRequests = new DbBrandProposalRepository($this->db, $clock);
        $this->decisions = new DbProductDecisionRepository($this->db, $clock);
        $this->seen = new ReviewSeen(new DbReviewSeenStore($this->db, $clock));
    }

    /** The `alpha.36` rule: a fixture that leaves a queue behind fails another suite. */
    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    public function testThreeThingsPutAProductInTheQueueAndEachOneIsEnough(): void
    {
        $submitted = $this->product('ارسال‌شده', ProductStatus::Submitted);
        $proposed = $this->product('با نسخهٔ پیشنهادی', ProductStatus::Published);
        $this->revisions->create($proposed, self::VENDOR, ['details' => ['title' => 'تازه']]);
        $branded = $this->product('با درخواست برند', ProductStatus::Published);
        self::assertGreaterThan(0, $this->brandRequests->open($branded, self::VENDOR, 'سینا طب', 'سیناطب'));
        $quiet = $this->product('هیچ', ProductStatus::Published);

        self::assertSame(3, $this->products->countAwaitingReview());
        // SORTED before comparing: the default order is «last changed», which
        // is a real decision about the screen and not a promise about ids.
        $ids = $this->needsReviewIds();
        sort($ids);
        self::assertSame(
            [$submitted, $proposed, $branded],
            $ids,
            'the filter shows exactly the set the counter counted'
        );
        self::assertNotContains($quiet, $this->needsReviewIds());
    }

    /**
     * «یک محصول دوبار شمرده نشود» — the assertion the old shape could not make.
     *
     * Counting proposals would answer two here; counting statuses would answer
     * one and miss the other two reasons. The product is one thing to look at.
     */
    public function testAProductWaitingOnThreeThingsIsCountedOnce(): void
    {
        $id = $this->product('همه‌چیز با هم', ProductStatus::Submitted);
        $this->revisions->create($id, self::VENDOR, ['details' => ['title' => 'تازه']]);
        self::assertGreaterThan(0, $this->brandRequests->open($id, self::VENDOR, 'سینا طب', 'سیناطب'));

        self::assertSame(1, $this->products->countAwaitingReview());
        self::assertSame([$id], $this->needsReviewIds());
        self::assertSame(1, $this->products->countForManager(null, '', 0, true));
    }

    /**
     * A brand request that was registered and not applied is STILL waiting.
     *
     * The half-finished state is the one a screen would most easily hide, and
     * it is the one that needs the manager most: the brand is in the list and
     * the product does not have it.
     */
    public function testABrandRequestThatWasRegisteredButNotAppliedIsStillWaiting(): void
    {
        $id = $this->product('نیمه‌تمام', ProductStatus::Published);
        $proposalId = $this->brandRequests->open($id, self::VENDOR, 'سینا طب', 'سیناطب');
        self::assertTrue($this->brandRequests->decide(
            $proposalId,
            BrandProposal::REGISTERED,
            9,
            '',
            777,
            BrandProposal::PENDING
        ));

        self::assertSame(1, $this->products->countAwaitingReview());
        self::assertSame([$id], $this->needsReviewIds());
        self::assertSame([$id], $this->brandRequests->openProductIds([$id]));
    }

    public function testADecidedBrandRequestLeavesTheQueue(): void
    {
        $id = $this->product('تصمیم‌گرفته', ProductStatus::Published);
        $proposalId = $this->brandRequests->open($id, self::VENDOR, 'سینا طب', 'سیناطب');
        self::assertTrue($this->brandRequests->decide(
            $proposalId,
            BrandProposal::REJECTED,
            9,
            'نه',
            0,
            BrandProposal::PENDING
        ));

        self::assertSame(0, $this->products->countAwaitingReview());
        self::assertSame([], $this->needsReviewIds());
        self::assertSame([], $this->brandRequests->openProductIds([$id]));
    }

    /**
     * The badge reads the SAME set, and keeps its own meaning.
     *
     * «نشان قرمز همچنان یعنی ارسال‌های دیده‌نشده و با مشاهده پاک شود، ولی مورد
     * تا تصمیم مدیر در صف بماند.» Both halves are asserted: looking clears the
     * badge and leaves the product in the queue.
     */
    public function testLookingClearsTheBadgeAndLeavesTheProductInTheQueue(): void
    {
        $id = $this->product('برای دیدن', ProductStatus::Submitted);
        $this->decisions->record($id, self::VENDOR, 0, ProductDecision::SUBMITTED, '');
        self::assertSame(1, $this->seen->unseenCount(self::ANA));

        self::assertTrue($this->seen->markSeen(self::ANA, $this->products->submissionsOf([$id])));
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'the badge is cleared by looking');
        self::assertSame(1, $this->products->countAwaitingReview(), 'and the product is still waiting');
        self::assertSame([$id], $this->needsReviewIds());
    }

    /** A new submission after looking makes a badge again. */
    public function testANewSubmissionAfterLookingMakesABadgeAgain(): void
    {
        $id = $this->product('دوباره', ProductStatus::Submitted);
        $this->decisions->record($id, self::VENDOR, 0, ProductDecision::SUBMITTED, '');
        self::assertTrue($this->seen->markSeen(self::ANA, $this->products->submissionsOf([$id])));
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        $this->decisions->record($id, self::VENDOR, 0, ProductDecision::SUBMITTED, '');
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'a resubmission is a new notification');
    }

    /**
     * A brand request is a notification too, and it goes through the same
     * token — so it cannot be seen without being looked at.
     */
    public function testABrandRequestOnAPublishedProductIsAnUnseenNotification(): void
    {
        $id = $this->product('برند تازه', ProductStatus::Published);
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'nothing is waiting yet');
        self::assertGreaterThan(0, $this->brandRequests->open($id, self::VENDOR, 'سینا طب', 'سیناطب'));
        self::assertSame(1, $this->seen->unseenCount(self::ANA));

        self::assertTrue($this->seen->markSeen(self::ANA, $this->products->submissionsOf([$id])));
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
        self::assertSame(1, $this->products->countAwaitingReview(), 'still waiting for a decision');
    }

    /** The published status is NOT changed to put a product in the queue. */
    public function testAProductsStatusIsNeverMovedJustToShowItInTheQueue(): void
    {
        $id = $this->product('منتشرشده', ProductStatus::Published);
        $this->revisions->create($id, self::VENDOR, ['details' => ['title' => 'تازه']]);
        self::assertGreaterThan(0, $this->brandRequests->open($id, self::VENDOR, 'سینا طب', 'سیناطب'));

        self::assertSame([$id], $this->needsReviewIds());
        self::assertSame(
            ProductStatus::Published,
            $this->products->find($id)?->status,
            '«وضعیت منتشرشدهٔ محصول را صرفاً برای نمایش در صف تغییر نده»'
        );
    }

    /** Search and pagination narrow the SAME set, so the numbers agree. */
    public function testSearchAndPaginationAgreeWithTheCounter(): void
    {
        $gauze = $this->product('گاز استریل', ProductStatus::Submitted);
        $this->product('دستکش لاتکس', ProductStatus::Submitted);
        $this->product('ماسک سه‌لایه', ProductStatus::Published);

        self::assertSame(2, $this->products->countForManager(null, '', 0, true));
        self::assertSame(1, $this->products->countForManager(null, 'گاز', 0, true));
        $page = $this->products->forManager(null, 'گاز', 20, 0, 0, null, true);
        self::assertCount(1, $page);
        self::assertSame($gauze, $page[0]->id);
        // One row per page, two pages, and no row on both.
        $first = $this->products->forManager(null, '', 1, 0, 0, null, true);
        $second = $this->products->forManager(null, '', 1, 1, 0, null, true);
        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]->id, $second[0]->id);
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<int> */
    private function needsReviewIds(): array
    {
        return array_values(array_map(
            static fn (object $product): int => $product->id,
            $this->products->forManager(null, '', 100, 0, 0, null, true)
        ));
    }

    private function product(string $title, ProductStatus $status): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(
                title: $title,
                type: 'simple',
                categoryKey: 'gloves',
                priceMinor: 100000,
                sku: 'SKU-' . substr(md5($title), 0, 8),
                stock: 3
            ),
            $status
        );
    }
}
