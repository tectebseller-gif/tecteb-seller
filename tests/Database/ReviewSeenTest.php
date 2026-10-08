<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbReviewSeenStore;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the red count is «چند مورد را ندیده‌ام», per manager.
 *
 * Until `alpha.35` it was `countAwaitingReview()` — the size of the queue, the
 * same number for every manager, changed only by a decision. So a manager who
 * had read every submission still had a red badge, and the only way to clear it
 * was to approve or reject something. The owner asked for a notification: it
 * appears when a vendor submits, and it goes away when THIS manager has looked.
 *
 * Every assertion here is about the count and the marks. Nothing in this file
 * touches a product's status, its baseline or its proposal, and two tests assert
 * that on purpose: a view state that could move a product through the review
 * flow would be a permission wearing a notification's clothes.
 *
 * On the real database because the token is two correlated reads over the
 * append-only trails, and the whole claim — «a resubmission is a new
 * notification, an edited price is not» — is about which rows those reads see.
 */
final class ReviewSeenTest extends DatabaseTestCase
{
    private const ANA = 41;
    private const BABAK = 42;
    private const VENDOR = 7;

    private WpDatabase $db;
    private DbProductRepository $products;
    private DbProductRevisionRepository $revisions;
    private DbProductDecisionRepository $decisions;
    private ReviewSeen $seen;
    private DbReviewSeenStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        $this->revisions = new DbProductRevisionRepository($this->db, $clock);
        $this->decisions = new DbProductDecisionRepository($this->db, $clock);
        $this->store = new DbReviewSeenStore($this->db, $clock);
        $this->seen = new ReviewSeen($this->store);
    }

    /**
     * Gives the tables back empty.
     *
     * Not tidiness: the CONTRACT suite's stub `wpdb` is a real PDO on this same
     * database, and `AdminMenuAndAssetsTest` asserts the marketplace menu title
     * is exactly «بازارگاه تک‌طب» — which is only true while nothing is waiting
     * for review. A submitted product left behind here puts a red bubble in that
     * string and fails a test in another suite about a menu that is perfectly
     * correct. `setUp()` already resets before each of these tests; what was
     * missing was giving it back after the LAST one.
     */
    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    /**
     * WHAT THIS PROVES: two managers, two independent counts.
     *
     * The first thing the owner asked to be tested: «دو مدیر مستقل: مشاهدهٔ یکی،
     * نشان دیگری را صفر نکند». The old badge could not do this at all, because
     * it was a `COUNT(*)` with no notion of who was asking.
     */
    public function testOneManagerReadingDoesNotClearTheOthersBadge(): void
    {
        $first = $this->submit('ماسک N95');
        $second = $this->submit('دستکش لاتکس');

        self::assertSame(2, $this->seen->unseenCount(self::ANA));
        self::assertSame(2, $this->seen->unseenCount(self::BABAK));

        $this->view(self::ANA, [$first, $second]);

        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'Ana has read both');
        self::assertSame(2, $this->seen->unseenCount(self::BABAK), 'Babak has read neither');

        // And Babak reading does not un-read them for Ana.
        $this->view(self::BABAK, [$first]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
        self::assertSame(1, $this->seen->unseenCount(self::BABAK));
    }

    /**
     * WHAT THIS PROVES: only the rows that were ON the page are marked.
     *
     * «فهرست چندصفحه‌ای و فیلترشده: فقط موارد نمایش‌داده‌شده خوانده‌شده شوند».
     * The page is read through `forManagerWithSubmission()`, which is the same
     * `LIMIT` the list draws with, so «what was shown» is not a second opinion.
     */
    public function testOnlyTheRowsOnTheOpenedPageAreMarked(): void
    {
        $ids = [];
        foreach (['الف', 'ب', 'پ', 'ت', 'ث'] as $title) {
            $ids[] = $this->submit($title);
        }
        self::assertSame(5, $this->seen->unseenCount(self::ANA));

        // Page one of two, two rows at a time, oldest id first.
        $page = $this->products->forManagerWithSubmission(ProductStatus::Submitted, '', 2, 0);
        self::assertCount(2, $page);
        $this->record(self::ANA, $page);
        self::assertSame(3, $this->seen->unseenCount(self::ANA), 'three rows were never drawn');

        // …and page two.
        $this->record(self::ANA, $this->products->forManagerWithSubmission(ProductStatus::Submitted, '', 2, 2));
        self::assertSame(1, $this->seen->unseenCount(self::ANA));
        self::assertCount(4, $this->store->marksFor(self::ANA, $ids), 'page two did not un-see page one');

        // A filter that excludes a row leaves that row unseen, however wide the
        // page is: the marks come from the rows, not from the query's intent.
        $filtered = $this->products->forManagerWithSubmission(ProductStatus::Submitted, 'الف', 100, 0);
        self::assertCount(1, $filtered);
        $this->record(self::BABAK, $filtered);
        self::assertSame(4, $this->seen->unseenCount(self::BABAK), 'the search showed one of five');
    }

    /**
     * WHAT THIS PROVES: opening ONE product marks that one, and a published
     * product with nothing proposed marks nothing at all.
     */
    public function testOpeningOneProductMarksOnlyThatSubmission(): void
    {
        $first = $this->submit('کیف کمک‌های اولیه');
        $this->submit('ترمومتر');
        $quiet = $this->create('گوشی پزشکی');
        $this->products->updateStatus($quiet, ProductStatus::Published, '');

        self::assertSame(2, $this->seen->unseenCount(self::ANA));

        // The detail page's read, for one id.
        $this->view(self::ANA, [$first]);
        self::assertSame(1, $this->seen->unseenCount(self::ANA));

        // A product nobody is waiting on has no submission to have seen, so
        // opening it records nothing rather than recording a token of zero.
        self::assertSame([], $this->products->submissionsOf([$quiet]));
        self::assertFalse($this->seen->markSeen(self::ANA, $this->products->submissionsOf([$quiet])));
        self::assertSame(1, $this->seen->unseenCount(self::ANA));
    }

    /**
     * WHAT THIS PROVES: after a read, the vendor submitting again is a NEW
     * notification — and an ordinary edit is not.
     *
     * This is the reason the token is two append-only ids and not `updated_at`.
     * A price corrected on a product already in the queue is not a new thing to
     * look at; a resubmission is.
     */
    public function testAResubmissionIsUnseenAgainAndAnOrdinaryEditIsNot(): void
    {
        $id = $this->submit('سرنگ ۵ سی‌سی');
        $this->view(self::ANA, [$id]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        // An edit that is not a submission: the row itself changes, `updated_at`
        // moves, the version counter moves — and the badge does not.
        $before = $this->products->find($id);
        self::assertNotNull($before);
        self::assertTrue($this->products->updateDetails(
            $id,
            new ProductDetails(title: 'سرنگ ۵ سی‌سی', categoryKey: 'gloves', priceMinor: 999000, stock: 3),
            \Tecteb\Marketplace\Modules\Product\Domain\ProductRowVersion::UNGUARDED
        ));
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'an edited price is not a new submission');

        // The manager asks for a correction and the vendor sends it back. Two
        // rows in the decision trail, so the token moves.
        $this->products->updateStatus($id, ProductStatus::ChangesRequested, 'قیمت را بررسی کنید');
        $this->decisions->record($id, self::VENDOR, self::ANA, ProductDecision::CHANGES_REQUESTED, 'قیمت را بررسی کنید');
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'out of the queue, so nothing is waiting');

        $this->submitExisting($id);
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'the resubmission is a new notification');
        self::assertSame(1, $this->seen->unseenCount(self::BABAK));
    }

    /**
     * WHAT THIS PROVES: a proposal on a live product is a submission too, and a
     * newer proposal is a new one.
     */
    public function testANewProposalOnALiveProductIsANewNotification(): void
    {
        $id = $this->create('ویلچر');
        $this->products->updateStatus($id, ProductStatus::Published, '');
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        $first = $this->propose($id, 'ویلچر تاشو');
        self::assertSame(1, $this->seen->unseenCount(self::ANA));
        $this->view(self::ANA, [$id]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        // Superseded and replaced: one waiting product, and a token that moved.
        self::assertTrue($this->revisions->decide($first, ProductRevision::SUPERSEDED, 0, ''));
        $this->propose($id, 'ویلچر تاشو سبک');
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'a newer proposal is unseen');
    }

    /**
     * WHAT THIS PROVES: a submission that lands between the read and the record
     * keeps its notification.
     *
     * «ارسالی که میان خواندن داده و ثبت مشاهده برسد، نباید اشتباهاً خوانده‌شده
     * شود». The mechanism is that `markSeen()` writes the token the page READ and
     * never looks it up again — so a submission arriving afterwards leaves a
     * token that no longer matches, and the product is unseen.
     *
     * Deterministic: the interleaving is written out in order, not raced.
     */
    public function testASubmissionArrivingAfterTheReadIsStillUnseen(): void
    {
        $id = $this->create('نبولایزر');
        $this->products->updateStatus($id, ProductStatus::Published, '');
        $this->propose($id, 'نبولایزر خانگی');

        // 1. the page reads its rows, tokens and all
        $page = $this->products->forManagerWithSubmission(null, '', 20, 0, 0, null, true);
        self::assertCount(1, $page);

        // 2. the vendor replaces the proposal while the page is being rendered
        self::assertTrue($this->revisions->decide(
            (int) $this->revisions->pendingFor($id)?->id,
            ProductRevision::SUPERSEDED,
            0,
            ''
        ));
        $this->propose($id, 'نبولایزر پرتابل');

        // 3. the page records what it showed
        $this->record(self::ANA, $page);

        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'the newer proposal was never on screen');
        // And NOTHING was recorded for it. Until `alpha.38` the stale token was
        // written — harmless on its own, because a mark that is not the current
        // identity reads as unseen anyway, and fatal in company: that same write
        // is what could land on top of a newer mark. Since the write is guarded
        // on «what I displayed is still current», a page whose content went out
        // of date mid-render records nothing at all. Either way the manager sees
        // the notification, which is the assertion above.
        self::assertSame([], $this->store->marksFor(self::ANA, [$id]), 'a view that went stale records nothing');
    }

    /**
     * WHAT THIS PROVES: a decision empties the badge without leaving a mark
     * behind that could suppress the next submission.
     */
    public function testADecidedProductLeavesNothingInTheCounter(): void
    {
        $id = $this->submit('اکسیژن‌ساز');
        $this->view(self::ANA, [$id]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
        self::assertSame(1, $this->seen->unseenCount(self::BABAK), 'Babak never looked');

        // Rejected: out of the queue for everybody, read or unread.
        $this->products->updateStatus($id, ProductStatus::Archived, 'مدارک ناقص');
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
        self::assertSame(0, $this->seen->unseenCount(self::BABAK), 'a decided product is in no count at all');

        // The stale mark Ana still carries must not swallow the next submission.
        $this->products->updateStatus($id, ProductStatus::Draft, '');
        $this->submitExisting($id);
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'a stale mark does not suppress a new submission');
    }

    /**
     * WHAT THIS PROVES: at zero there is nothing to draw, and the product is
     * still in the review queue.
     *
     * «محصول در صف بررسی بماند» — the badge going to nought says «you have seen
     * everything», never «there is nothing to decide». The two numbers are read
     * from the same data and they are different numbers.
     */
    public function testTheBadgeReachesZeroWhileTheQueueIsUnchanged(): void
    {
        $id = $this->submit('اتوکلاو');
        self::assertSame(1, $this->products->countAwaitingReview());

        $this->view(self::ANA, [$id]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
        self::assertSame(1, $this->products->countAwaitingReview(), 'still waiting for a decision');

        $product = $this->products->find($id);
        self::assertNotNull($product);
        self::assertSame(ProductStatus::Submitted, $product->status, 'reading decided nothing');
        self::assertSame('', $product->reviewNote, 'reading wrote no note');
        self::assertNull($product->baseline, 'reading recorded no baseline');
        self::assertCount(1, $this->products->forManager(ProductStatus::Submitted, '', 20, 0));
    }

    /**
     * WHAT THIS PROVES: with nobody logged in, or with no page drawn, nothing is
     * marked.
     *
     * «مجوز نامعتبر و درخواست ناموفق چیزی را خوانده‌شده نکنند». The capability
     * check is on the page — `ProductReviewPage::render()` calls `wp_die()` before
     * any of this runs — and the second half is here: an empty set of rows is a
     * request that drew nothing, and it writes nothing.
     */
    public function testNoViewerAndNoRowsMarkNothing(): void
    {
        $id = $this->submit('ساکشن');

        self::assertFalse($this->seen->markSeen(0, [$id => 's1.r0']), 'no user, no mark');
        self::assertFalse($this->seen->markSeen(self::ANA, []), 'no rows, no mark');
        self::assertSame([], $this->store->marksFor(self::ANA, [$id]));
        self::assertSame(1, $this->seen->unseenCount(self::ANA));
        // And a request that never got as far as reading rows: a filter that
        // matches nothing records nothing, even for a real manager.
        $this->record(self::ANA, $this->products->forManagerWithSubmission(ProductStatus::Submitted, 'چیزی که نیست', 20, 0));
        self::assertSame([], $this->store->marksFor(self::ANA, [$id]));
    }

    /**
     * WHAT THIS PROVES: a product waiting since before this feature existed is
     * unseen until the manager's first view.
     *
     * The documented behaviour for pre-upgrade data, and it is not a guess:
     * nothing is backfilled, no migration writes a mark, so the absence of a
     * mark IS the answer. A product submitted before the decision trail existed
     * (`alpha.29`) has no `submitted` row to point at, and its token is `s0.r0`
     * — a real token, not a missing one.
     */
    public function testAProductWaitingSinceBeforeTheUpgradeIsUnseenUntilTheFirstView(): void
    {
        // Submitted with no decision row at all, which is what an `alpha.28`
        // site's queue looks like after the upgrade.
        $id = $this->create('ترازوی دیجیتال');
        $this->products->updateStatus($id, ProductStatus::Submitted, '');
        self::assertSame(['s0.r0'], array_values($this->products->submissionsOf([$id])));

        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'unseen on the first request after the upgrade');
        $this->view(self::ANA, [$id]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        // And it stays seen — there is no clock in the token, so nothing makes
        // it come back until the vendor submits again.
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
        $this->submitExisting($id);
        self::assertSame(1, $this->seen->unseenCount(self::ANA));
    }

    /**
     * WHAT THIS PROVES: a queue LARGER than the old cap reaches nought, and
     * stays there.
     *
     * This is the owner's own case, and the one `alpha.36` could not pass:
     * «حداقل ۶۰۰ محصول در صف — پس از مشاهدهٔ همهٔ صفحه‌ها اعلان صفر باشد و
     * شمارش صف همچنان ۶۰۰ بماند، و باز کردن صفحهٔ دیگر یا ورود دوبارهٔ کاربر،
     * اعلان قبلی را برنگرداند». With a 500-mark cap, recording the
     * five-hundred-and-first view dropped the first, so the count could not go
     * below the overflow however much the manager read.
     *
     * Paged twenty at a time, which is what the list actually draws with — a
     * single `markSeen()` of six hundred tokens would have been a scenario no
     * manager can produce.
     */
    public function testAQueueLargerThanTheOldCapReachesZeroAndStaysThere(): void
    {
        $total = 600;
        for ($i = 1; $i <= $total; $i++) {
            $this->submit('قلم ' . $i);
        }
        self::assertSame($total, $this->products->countAwaitingReview());
        self::assertSame($total, $this->seen->unseenCount(self::ANA));

        for ($offset = 0; $offset < $total; $offset += 20) {
            $this->record(self::ANA, $this->products->forManagerWithSubmission(
                ProductStatus::Submitted,
                '',
                20,
                $offset
            ));
        }

        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'every page was read');
        // «شمارش صف همچنان ۶۰۰ بماند» — reading is not deciding.
        self::assertSame($total, $this->products->countAwaitingReview());
        // «باز کردن صفحهٔ دیگر یا ورود دوبارهٔ کاربر، اعلان قبلی را برنگرداند».
        // A fresh store on the same database is exactly «logged in again»:
        // nothing about the answer lives in this process.
        $fresh = new DbReviewSeenStore($this->db, new SystemClock());
        self::assertSame(0, (new ReviewSeen($fresh))->unseenCount(self::ANA));
        self::assertSame($total, $this->seen->unseenCount(self::BABAK), 'and Babak still has all of them');
    }

    /**
     * WHAT THIS PROVES: after that same scenario, one resubmission is exactly
     * one notification.
     *
     * «پس از همان سناریو، ارسال مجدد یک محصول یک اعلان تازه ایجاد کند» — one,
     * not nought and not six hundred. The product is chosen from the marks ANA
     * actually holds, because a falsification that picks at random can land on
     * something she never saw (`alpha.36`).
     */
    public function testOneResubmissionAfterALargeQueueIsOneNotification(): void
    {
        $ids = [];
        for ($i = 1; $i <= 520; $i++) {
            $ids[] = $this->submit('سرنگ ' . $i);
        }
        for ($offset = 0; $offset < count($ids); $offset += 20) {
            $this->record(self::ANA, $this->products->forManagerWithSubmission(ProductStatus::Submitted, '', 20, $offset));
        }
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        $marked = $this->store->marksFor(self::ANA, $ids);
        self::assertCount(520, $marked, 'all of them are recorded, past the old cap');
        $target = (int) array_key_first($marked);

        // The vendor edits and sends it back: the status leaves the queue and
        // returns, which is what writes a new `submitted` row.
        $this->products->updateStatus($target, ProductStatus::ChangesRequested, 'عکس بهتر');
        $this->submitExisting($target);

        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'exactly one, and it is the resubmitted one');
        self::assertSame(
            [$target => true],
            array_map(static fn (): bool => true, array_diff_assoc(
                $this->products->submissionsOf([$target]),
                [$target => $marked[$target]]
            )),
            'the token moved for that product and no other'
        );
    }

    /**
     * WHAT THIS PROVES: two records at once lose nothing.
     *
     * «دو ثبت مشاهدهٔ هم‌زمان برای یک مدیر، علامتی را از دست ندهد». A row per
     * (manager, product) rather than one blob is the whole mechanism: two
     * writes touch two keys and neither is a read-modify-write over the other's.
     */
    public function testTwoRecordsAtOnceLoseNothing(): void
    {
        $a = $this->submit('ونتیلاتور');
        $b = $this->submit('مونیتور');
        $tokens = $this->products->submissionsOf([$a, $b]);

        $one = new DbReviewSeenStore($this->db, self::clockAt('2030-01-01 10:00:00.500000'));
        $two = new DbReviewSeenStore($this->db, self::clockAt('2030-01-01 09:59:59.500000'));

        self::assertTrue($one->markSeen(self::ANA, $tokens));
        self::assertTrue($two->markSeen(self::ANA, [$a => $tokens[$a]]));

        $kept = $one->marksFor(self::ANA, [$a, $b]);
        self::assertCount(2, $kept, 'neither write removed the other\'s row');
        self::assertSame($tokens[$a], $kept[$a]);
        self::assertSame($tokens[$b], $kept[$b]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
    }

    /**
     * WHAT THIS PROVES: a record that arrives LATE, carrying an older view and
     * a LATER write time, does not pull a newer mark backwards.
     *
     * **Why this test is written this way.** The `alpha.37` version of it gave
     * the late writer an EARLIER clock, which is the one case `alpha.37` got
     * right and is not what a real `SystemClock` does: a request that finishes
     * later reads a later instant. So the test agreed with the code it was
     * testing instead of with the site. Here the slow request carries the older
     * view AND the later timestamp — exactly the shape the owner described —
     * and `alpha.37` fails it.
     *
     * Deterministic: the four steps are written out in order. A real
     * two-process race is a separate claim and is not what this asserts.
     */
    public function testALateRecordOfAnOlderViewCannotPullANewerOneBackwards(): void
    {
        $a = $this->submit('ونتیلاتور');
        $b = $this->submit('مونیتور');

        // 1. the slow request reads its rows and starts rendering
        $old = $this->products->submissionsOf([$a, $b]);

        // 2. the vendor sends A back while it renders
        $this->products->updateStatus($a, ProductStatus::ChangesRequested, 'عکس بهتر');
        $this->submitExisting($a);
        $new = $this->products->submissionsOf([$a, $b]);
        self::assertNotSame($old[$a], $new[$a], 'the fixture must really have resubmitted');
        self::assertSame($old[$b], $new[$b], 'and B did not move');

        // 3. a second request reads the NEW version and records it first
        $fast = new DbReviewSeenStore($this->db, self::clockAt('2030-01-01 10:00:00.000000'));
        self::assertTrue($fast->markSeen(self::ANA, $new));
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'the manager has seen both');

        // 4. the slow request finishes LAST and records the view it displayed,
        //    with the later write time a real clock gives it
        $slow = new DbReviewSeenStore($this->db, self::clockAt('2030-01-01 10:00:05.000000'));
        $slow->markSeen(self::ANA, $old);

        $kept = $this->store->marksFor(self::ANA, [$a, $b]);
        self::assertSame($new[$a], $kept[$a], 'the late record did not overwrite the newer mark');
        self::assertSame($new[$b], $kept[$b], 'and the product that did not move is still recorded');
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'the count did not go back up');
    }

    /**
     * WHAT THIS PROVES: and a NEWER view does still move the mark — the guard
     * refuses stale writes, not writes.
     */
    public function testANewerViewStillMovesTheMark(): void
    {
        $a = $this->submit('ونتیلاتور');
        $this->view(self::ANA, [$a]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        $this->products->updateStatus($a, ProductStatus::ChangesRequested, '');
        $this->submitExisting($a);
        $moved = $this->products->submissionsOf([$a]);
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'a resubmission is a new notification');

        $newest = new DbReviewSeenStore($this->db, self::clockAt('2030-01-01 11:00:00.000000'));
        self::assertTrue($newest->markSeen(self::ANA, $moved));
        self::assertSame($moved[$a], $newest->marksFor(self::ANA, [$a])[$a]);
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
    }

    // ------------------------------------------------------------------ helpers

    /** A clock that answers one instant, to the microsecond. */
    private static function clockAt(string $when): \Tecteb\Marketplace\Contracts\ClockInterface
    {
        return new class ($when) implements \Tecteb\Marketplace\Contracts\ClockInterface {
            public function __construct(private readonly string $when)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->when);
            }
        };
    }

    private function create(string $title): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 100000, stock: 3),
            ProductStatus::Draft
        );
    }

    /** A product submitted for review, the way `ManageProducts` does it. */
    private function submit(string $title): int
    {
        $id = $this->create($title);
        $this->submitExisting($id);
        return $id;
    }

    /**
     * The two writes a submission is: the status, and the append-only row.
     *
     * Both, because the token is read from the second one and the queue from the
     * first — a fixture that wrote only the status would be measuring a site
     * whose decision trail is broken.
     */
    private function submitExisting(int $id): void
    {
        self::assertTrue($this->products->updateStatus($id, ProductStatus::Submitted, ''));
        self::assertTrue($this->decisions->record($id, self::VENDOR, self::VENDOR, ProductDecision::SUBMITTED, ''));
    }

    /** A proposal on a live product; returns the revision id. */
    private function propose(int $productId, string $title): int
    {
        $id = $this->revisions->create($productId, self::VENDOR, [
            'details' => ['title' => $title],
            'specs' => [],
            'images' => [],
            'main_image_id' => 0,
        ]);
        self::assertGreaterThan(0, $id);
        return $id;
    }

    /** What the detail page does: read the token for these ids, then record it. */
    private function view(int $userId, array $productIds): void
    {
        self::assertTrue($this->seen->markSeen($userId, $this->products->submissionsOf($productIds)));
    }

    /**
     * What the list page does: record the tokens that came back WITH the rows.
     *
     * @param list<array{product:\Tecteb\Marketplace\Modules\Product\Domain\Product, submission:string}> $page
     */
    private function record(int $userId, array $page): void
    {
        $shown = [];
        foreach ($page as $row) {
            $shown[$row['product']->id] = $row['submission'];
        }
        $this->seen->markSeen($userId, $shown);
    }
}
