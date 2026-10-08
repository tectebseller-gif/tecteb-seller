<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\ClockInterface;
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
 * WHAT THIS PROVES: «ثبت دیرهنگامِ صفحهٔ قدیمی، مشاهدهٔ جدید را عقب نمی‌برد» —
 * on both paths, for both managers, and across a decision.
 *
 * **The defect this file is about.** `alpha.37` guarded the mark with `seen_at`,
 * taken from the clock at WRITE time, so the order it enforced was the order in
 * which requests finished. A request that had rendered the previous version and
 * then took a long time wrote last, carried the newer timestamp, won the guard,
 * and put the older token over the newer one. The manager's notification came
 * back for a version they had already seen, and no work of theirs could clear
 * it.
 *
 * **Why the fix is not a different clock.** Two things were ruled out before
 * the guard was rewritten, and both are asserted here rather than argued:
 *
 *  - the time a request STARTS is not the order in which two requests read
 *    their snapshots, so moving the clock read earlier moves the defect rather
 *    than removing it;
 *  - the two halves of the token are not monotonic, so «the bigger number
 *    wins» cannot order them. `approveRevision()` and `rejectRevision()` decide
 *    a proposal WITHOUT appending to the decision trail, so the `r` half falls
 *    back to nought — and `testDecidingAProposalReturnsTheSamePairAsBeforeItExisted`
 *    measures the case where two different states carry the SAME pair, which no
 *    comparison of numbers can tell apart.
 *
 * The guard that replaced it is equality with the identity that is current at
 * write time, asked inside the statement that writes. Deterministic throughout:
 * every interleaving below is written out in order. A real two-process race is
 * a separate claim and is NOT what this file asserts.
 */
final class ReviewSeenLateWriteTest extends DatabaseTestCase
{
    private const ANA = 41;
    private const BABAK = 42;
    private const VENDOR = 7;

    /** The slow request reads here… */
    private const EARLY = '2030-03-01 09:00:00.000000';
    /** …the fast one reads and WRITES here… */
    private const MIDDLE = '2030-03-01 09:00:10.000000';
    /** …and the slow one finishes here, which is what a real clock gives it. */
    private const LATE = '2030-03-01 09:00:20.000000';

    private WpDatabase $db;
    private DbProductRepository $products;
    private DbProductRevisionRepository $revisions;
    private DbProductDecisionRepository $decisions;
    private DbReviewSeenStore $store;
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
        $this->decisions = new DbProductDecisionRepository($this->db, $clock);
        $this->store = new DbReviewSeenStore($this->db, $clock);
        $this->seen = new ReviewSeen($this->store);
    }

    /**
     * Gives the tables back empty: the CONTRACT suite's stub `wpdb` is a real
     * PDO on this same database, and a product left waiting here puts a red
     * bubble into a menu title another suite asserts exactly (`alpha.36`).
     */
    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    /**
     * WHAT THIS PROVES: the owner's scenario, on the first-submission path.
     *
     * Four steps in the order they happen on a real site, and the one that
     * matters is the fourth: the older view is written LAST and with the LATER
     * timestamp.
     */
    public function testTheLateRecordOfAnOlderSubmissionDoesNotRestoreTheNotification(): void
    {
        $id = $this->submit('نبولایزر رومیزی');
        $other = $this->submit('اکسیژن‌ساز');

        // 1. request A reads the old version and begins a slow render
        $displayedByA = $this->products->submissionsOf([$id]);

        // 2. the vendor submits again
        $this->resubmit($id);
        $current = $this->products->submissionsOf([$id]);
        self::assertNotSame($displayedByA[$id], $current[$id], 'the fixture must really have resubmitted');

        // 3. request B reads the new version and records it
        self::assertTrue($this->storeAt(self::MIDDLE)->markSeen(self::ANA, $current));
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'only the product nobody opened is left');

        // 4. request A finishes and records what it displayed, later
        $this->storeAt(self::LATE)->markSeen(self::ANA, $displayedByA);

        self::assertSame(
            $current[$id],
            $this->store->marksFor(self::ANA, [$id])[$id] ?? null,
            'the newer mark survived the late write'
        );
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'the count did not go back up');
        self::assertSame([], $this->store->marksFor(self::ANA, [$other]), 'and no other product was touched');
    }

    /**
     * WHAT THIS PROVES: the same, on the proposal-on-a-published-product path.
     *
     * Checked separately because it is a different read: the identity moves by
     * the `r` half, not the `s` half, and the two halves come from two
     * different append-only tables.
     */
    public function testTheLateRecordOfAnOlderProposalDoesNotRestoreTheNotification(): void
    {
        $id = $this->publish('ترازوی دیجیتال');
        $first = $this->propose($id, 'ترازوی دیجیتال نسخهٔ دو');

        $displayedByA = $this->products->submissionsOf([$id]);
        self::assertStringEndsWith('.r' . $first, $displayedByA[$id], 'the token names the open proposal');

        // The vendor replaces the proposal: the old one is superseded and a new
        // one is open, so the `r` half moves while the `s` half stands still.
        self::assertTrue($this->revisions->decide($first, ProductRevision::SUPERSEDED, 0, ''));
        $second = $this->propose($id, 'ترازوی دیجیتال نسخهٔ سه');
        $current = $this->products->submissionsOf([$id]);
        self::assertStringEndsWith('.r' . $second, $current[$id]);

        self::assertTrue($this->storeAt(self::MIDDLE)->markSeen(self::ANA, $current));
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        $this->storeAt(self::LATE)->markSeen(self::ANA, $displayedByA);

        self::assertSame($current[$id], $this->store->marksFor(self::ANA, [$id])[$id] ?? null);
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'the answered proposal stays answered');
    }

    /**
     * WHAT THIS PROVES: the newer version, which no page displayed, is NOT
     * marked seen by anybody.
     *
     * The other direction of the same guard: refusing a stale write must not
     * quietly become accepting a write for something that was never on screen.
     */
    public function testTheNewerUndisplayedVersionStaysUnseen(): void
    {
        $id = $this->submit('پالس‌اکسیمتر');
        $displayed = $this->products->submissionsOf([$id]);

        $this->resubmit($id);
        $current = $this->products->submissionsOf([$id]);

        // Only the slow request ever ran, and it had the older view.
        $this->storeAt(self::LATE)->markSeen(self::ANA, $displayed);

        self::assertSame([], $this->store->marksFor(self::ANA, [$id]), 'nothing was recorded for a view that is out of date');
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'the version nobody saw is a notification');
        self::assertNotSame($displayed[$id], $current[$id]);
    }

    /**
     * WHAT THIS PROVES: one manager's late write does not touch the other
     * manager's marks.
     *
     * «علامت مدیر دیگر تغییر نکند». The primary key is (manager, product), and
     * this asserts it with the two managers holding DIFFERENT identities for
     * the same product at the same time.
     */
    public function testTheOtherManagersMarkIsUntouched(): void
    {
        $id = $this->submit('ساکشن پرتابل');
        $displayedByAna = $this->products->submissionsOf([$id]);

        $this->resubmit($id);
        $current = $this->products->submissionsOf([$id]);

        // Babak opens the new version; Ana's slow request lands afterwards with
        // the old one.
        self::assertTrue($this->storeAt(self::MIDDLE)->markSeen(self::BABAK, $current));
        $this->storeAt(self::LATE)->markSeen(self::ANA, $displayedByAna);

        self::assertSame($current[$id], $this->store->marksFor(self::BABAK, [$id])[$id] ?? null);
        self::assertSame(0, $this->seen->unseenCount(self::BABAK), 'Babak saw what is current');
        self::assertSame([], $this->store->marksFor(self::ANA, [$id]));
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'and Ana has not');
    }

    /**
     * WHAT THIS PROVES: the two halves of the token are NOT monotonic, so a
     * «greatest number wins» guard could not have worked.
     *
     * Measured, not argued: deciding a proposal gives the product the same pair
     * it had before that proposal existed. Two different states, one pair. This
     * is the precondition of the warning the owner gave, and it holds.
     */
    public function testDecidingAProposalReturnsTheSamePairAsBeforeItExisted(): void
    {
        $id = $this->publish('دستگاه فشارسنج');
        $quiet = $this->products->submissionsOf([$id]);
        self::assertSame([], $quiet, 'a published product with no proposal is not in the queue at all');

        $revision = $this->propose($id, 'دستگاه فشارسنج بازویی');
        $withProposal = $this->products->submissionsOf([$id]);
        self::assertStringEndsWith('.r' . $revision, $withProposal[$id]);

        // The manager answers it. Nothing is appended to the decision trail by
        // that answer — this is the line the design depended on, so it is read
        // from the database rather than from memory.
        $decisionsBefore = $this->decisionCount($id);
        self::assertTrue($this->revisions->decide($revision, ProductRevision::REJECTED, self::ANA, 'تصویر ناخوانا'));
        self::assertSame($decisionsBefore, $this->decisionCount($id), 'deciding a proposal appends no decision row');

        $afterDecision = $this->products->submissionsOf([$id]);
        self::assertSame([], $afterDecision, 'and the product has left the queue, with the pair it started with');
    }

    /**
     * WHAT THIS PROVES: the full cycle — view, decide, resubmit — ends with
     * exactly one notification, and a late write from anywhere in it cannot
     * clear that one.
     */
    public function testAfterADecisionAndAResubmissionTheLateWriteStillCannotClearIt(): void
    {
        $id = $this->publish('ویلچر برقی');
        $revision = $this->propose($id, 'ویلچر برقی تاشو');
        $viewed = $this->products->submissionsOf([$id]);
        self::assertTrue($this->storeAt(self::EARLY)->markSeen(self::ANA, $viewed));
        self::assertSame(0, $this->seen->unseenCount(self::ANA));

        self::assertTrue($this->revisions->decide($revision, ProductRevision::REJECTED, self::ANA, 'مشخصات کم است'));
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'an answered question is not a notification');

        $again = $this->propose($id, 'ویلچر برقی تاشو — نسخهٔ دو');
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'a new proposal is a new question');
        self::assertGreaterThan($revision, $again);

        // A tab left open on the first proposal finally writes.
        $this->storeAt(self::LATE)->markSeen(self::ANA, $viewed);
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'the stale tab did not answer the new question');

        // And opening the new one does clear it.
        self::assertTrue($this->storeAt(self::LATE)->markSeen(self::ANA, $this->products->submissionsOf([$id])));
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
    }

    /**
     * WHAT THIS PROVES: a mark is never written for a product the caller named
     * but whose identity it did not hold — including a product id that is not
     * in the table at all.
     *
     * The guard reads the product row to ask its question, so a request naming
     * something that is gone writes nothing instead of writing a row that would
     * sit in the table for ever with no product behind it.
     */
    public function testAMarkIsNotWrittenForAProductThatIsNotThere(): void
    {
        $id = $this->submit('اسپیرومتر');
        $token = $this->products->submissionsOf([$id])[$id];

        $ghost = $id + 10_000;
        $this->storeAt(self::MIDDLE)->markSeen(self::ANA, [$ghost => $token, $id => $token]);

        self::assertSame([$id => $token], $this->store->marksFor(self::ANA, [$id, $ghost]));
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
    }

    // ------------------------------------------------------------------ helpers

    private function storeAt(string $when): DbReviewSeenStore
    {
        return new DbReviewSeenStore($this->db, new class ($when) implements ClockInterface {
            public function __construct(private readonly string $when)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->when);
            }
        });
    }

    private function create(string $title): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 250000, stock: 4),
            ProductStatus::Draft
        );
    }

    private function submit(string $title): int
    {
        $id = $this->create($title);
        $this->resubmit($id);
        return $id;
    }

    /** The two writes a submission is: the status, and the append-only row. */
    private function resubmit(int $id): void
    {
        self::assertTrue($this->products->updateStatus($id, ProductStatus::Submitted, ''));
        self::assertTrue($this->decisions->record($id, self::VENDOR, self::VENDOR, ProductDecision::SUBMITTED, ''));
    }

    private function publish(string $title): int
    {
        $id = $this->submit($title);
        self::assertTrue($this->products->updateStatus($id, ProductStatus::Published, ''));
        return $id;
    }

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

    private function decisionCount(int $productId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->db->prefix() . 'tmc_product_decisions` WHERE product_id = %d',
            [$productId]
        );
    }
}
