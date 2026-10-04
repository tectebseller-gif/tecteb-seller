<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeenStoreInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRowVersion;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbReviewSeenStore;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;

/**
 * WHAT THIS PROVES: what the product page RECORDS is what the product page
 * SHOWED — measured with a submission landing in the middle of the render.
 *
 * `alpha.36` read the detail page in three statements: `find()` for the
 * product, `pendingFor()` for the proposal, `submissionsOf()` for the token. A
 * submission arriving between the first and the third let the page print the
 * previous content while recording the newer identity as seen — so the manager
 * never saw it and the badge said they had. The list had been fixed for exactly
 * this (`forManagerWithSubmission()`); this path had not.
 *
 * **The interleaving is scheduled, not raced for.** `InterferingDatabase` runs
 * a callback immediately before a named statement, in the same process, through
 * the same connection — so «the submission landed after the content was read»
 * happens on every run, in the same order, with no clock and no sleep. A race
 * test that waits is a race test that passes on a bad build one run in five.
 *
 * **And it runs through the page, not past it.** Every test here calls the real
 * render callback the admin menu registered, with `?product=<id>` in `$_GET`.
 * Asserting on `ReviewSeen::markSeen()` with a ready-made token would have
 * proved that a method writes what it is handed, which was never the question:
 * the question is what the PAGE hands it.
 */
final class ReviewDetailSnapshotTest extends DatabaseTestCase
{
    private const VENDOR = 7;

    private InterferingDatabase $spy;
    private DatabaseInterface $inner;
    private DbProductRepository $products;
    private DbProductRevisionRepository $revisions;
    private DbProductDecisionRepository $decisions;
    private DbReviewSeenStore $store;
    private int $manager = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        $container = Bootstrap::container();
        $container->get(MigrationRunner::class)->run();

        $this->inner = $container->get(DatabaseInterface::class);
        $this->resetSchema($this->inner);

        // The gateway the PAGE will use, wrapped. Rebinding the two services
        // that read and write the mark — rather than only replacing the
        // gateway — because the container caches an instance the moment it is
        // resolved, and a repository built earlier would hold the unwrapped
        // database and quietly make every hook here a no-op.
        $this->spy = new InterferingDatabase($this->inner);
        $clock = new SystemClock();
        $container->instance(DatabaseInterface::class, $this->spy);
        $container->bind(
            ProductRepositoryInterface::class,
            static fn ($c) => new DbProductRepository($c->get(DatabaseInterface::class), $c->get(ClockInterface::class))
        );
        $container->bind(
            ReviewSeenStoreInterface::class,
            static fn ($c) => new DbReviewSeenStore($c->get(DatabaseInterface::class), $c->get(ClockInterface::class))
        );
        $container->bind(
            ReviewSeen::class,
            static fn ($c) => new ReviewSeen($c->get(ReviewSeenStoreInterface::class))
        );

        // The fixtures write through the UNWRAPPED gateway, so seeding never
        // trips a hook that is waiting for the page's own statement.
        $this->products = new DbProductRepository($this->inner, $clock);
        $this->revisions = new DbProductRevisionRepository($this->inner, $clock);
        $this->decisions = new DbProductDecisionRepository($this->inner, $clock);
        $this->store = new DbReviewSeenStore($this->inner, $clock);
        $this->manager = get_current_user_id();
        self::assertGreaterThan(0, $this->manager, 'the render path needs a logged-in manager');
    }

    /**
     * Gives the tables back empty — the contract suite's stub `wpdb` is a real
     * PDO on this same database, and a product left waiting here puts a red
     * bubble into a menu title another suite asserts exactly (`alpha.36`).
     */
    protected function tearDown(): void
    {
        $_GET['product'] = '';
        $this->resetSchema($this->inner);
        parent::tearDown();
    }

    /**
     * WHAT THIS PROVES: a submission that lands AFTER the snapshot read is not
     * recorded as seen, and the page shows the older content it really had.
     *
     * «ارسال تازه‌ای که میان خواندن و ثبت می‌رسد، دیده‌نشده بماند».
     */
    public function testASubmissionLandingAfterTheSnapshotStaysUnseen(): void
    {
        $id = $this->submit('نبولایزر رومیزی');
        $before = $this->tokenOf($id);
        self::assertSame(1, $this->unseen(), 'the manager has not looked yet');

        // Just before the page records the view — which is the LAST thing it
        // does — the vendor edits the title and sends it again.
        $this->spy->before(['INSERT INTO', 'tmc_review_seen'], 1, function () use ($id): void {
            $this->retitle($id, 'نبولایزر پرتابل');
        });

        $html = $this->renderDetail($id);
        self::assertNotSame([], $this->spy->fired, 'the interference never ran: this test proved nothing');

        $after = $this->tokenOf($id);
        self::assertNotSame($before, $after, 'the fixture must really have resubmitted');

        self::assertStringContainsString('نبولایزر رومیزی', $html, 'the page printed the title it had read');
        self::assertStringNotContainsString('نبولایزر پرتابل', $html, 'the newer title was never on screen');
        self::assertSame(
            [$id => $before],
            $this->store->marksFor($this->manager, [$id]),
            'the recorded identity is the one that was displayed'
        );
        self::assertSame(1, $this->unseen(), 'the newer submission is still a notification');
    }

    /**
     * WHAT THIS PROVES: and a submission that lands BEFORE the snapshot read is
     * the one recorded — the snapshot is not stale either.
     *
     * The pair is the point. A page that always recorded the older identity
     * would also pass the test above, and would be wrong: «شناسهٔ ثبت‌شده دقیقاً
     * به همان محصول/پیشنهادی تعلق داشته باشد که نمایش داده شده».
     */
    public function testASubmissionLandingBeforeTheSnapshotIsTheOneRecorded(): void
    {
        $id = $this->submit('اکسیژن‌ساز ۵ لیتری');
        $before = $this->tokenOf($id);

        $this->spy->beforeRead(['AS tmc_waiting'], 1, function () use ($id): void {
            $this->retitle($id, 'اکسیژن‌ساز ۱۰ لیتری');
        });

        $html = $this->renderDetail($id);
        self::assertNotSame([], $this->spy->fired, 'the interference never ran: this test proved nothing');

        $after = $this->tokenOf($id);
        self::assertNotSame($before, $after);
        self::assertStringContainsString('اکسیژن‌ساز ۱۰ لیتری', $html, 'the page read the newer row');
        self::assertSame([$id => $after], $this->store->marksFor($this->manager, [$id]));
        self::assertSame(0, $this->unseen(), 'what was shown is what was recorded');
    }

    /**
     * WHAT THIS PROVES: the same, for a PROPOSAL on a published product — the
     * other half of the detail page, checked separately.
     *
     * «مسیر اولین ارسال و مسیر پیشنهاد روی محصول منتشرشده را جدا بررسی کن». The
     * proposal is loaded BY THE ID the snapshot named, so a newer proposal
     * superseding it mid-render cannot make the page print one revision and
     * record another: a revision row is append-only, and fetching it by its own
     * id returns the payload the recorded token is about.
     */
    public function testANewerProposalLandingMidRenderIsNotTheOneRecorded(): void
    {
        $id = $this->publish('ترازوی دیجیتال');
        $first = $this->propose($id, 'ترازوی دیجیتال نسخهٔ دو');
        $before = $this->tokenOf($id);
        self::assertStringEndsWith('.r' . $first, $before, 'the token names the open proposal');
        self::assertSame(1, $this->unseen());

        $second = 0;
        $this->spy->before(['INSERT INTO', 'tmc_review_seen'], 1, function () use ($id, $first, &$second): void {
            self::assertTrue($this->revisions->decide($first, ProductRevision::SUPERSEDED, 0, ''));
            $second = $this->propose($id, 'ترازوی دیجیتال نسخهٔ سه');
        });

        $html = $this->renderDetail($id);
        self::assertNotSame([], $this->spy->fired, 'the interference never ran: this test proved nothing');
        self::assertGreaterThan($first, $second);

        self::assertStringContainsString('ترازوی دیجیتال نسخهٔ دو', $html, 'the proposal on screen is the one the snapshot named');
        self::assertStringNotContainsString('ترازوی دیجیتال نسخهٔ سه', $html);
        self::assertSame([$id => $before], $this->store->marksFor($this->manager, [$id]));
        self::assertSame(1, $this->unseen(), 'the newer proposal is a new question');
    }

    /**
     * WHAT THIS PROVES: opening the page changes no status, no decision, no
     * baseline and no proposal.
     *
     * «مشاهده هیچ وضعیت محصول، تصمیم، مبنا یا پیشنهادی را تغییر ندهد». Measured
     * on both halves of the page: a submitted product and a published one with
     * an open proposal.
     */
    public function testViewingChangesNoStatusDecisionBaselineOrProposal(): void
    {
        $submitted = $this->submit('ساکشن پرتابل');
        $published = $this->publish('اتوکلاو ۱۸ لیتری');
        $revision = $this->propose($published, 'اتوکلاو ۲۳ لیتری');

        $snapshot = [];
        foreach ([$submitted, $published] as $id) {
            $product = $this->products->find($id);
            self::assertNotNull($product);
            $snapshot[$id] = [
                'status' => $product->status->value,
                'note' => $product->reviewNote,
                'baseline' => $product->baseline === null ? null : (array) $product->baseline,
                'decisions' => count($this->decisions->forProduct($id)),
                'pending' => $this->revisions->pendingFor($id)?->id,
            ];
        }

        $this->renderDetail($submitted);
        $this->renderDetail($published);

        foreach ([$submitted, $published] as $id) {
            $product = $this->products->find($id);
            self::assertNotNull($product);
            self::assertSame($snapshot[$id]['status'], $product->status->value, 'status moved on ' . $id);
            self::assertSame($snapshot[$id]['note'], $product->reviewNote, 'note changed on ' . $id);
            self::assertSame(
                $snapshot[$id]['baseline'],
                $product->baseline === null ? null : (array) $product->baseline,
                'baseline changed on ' . $id
            );
            self::assertSame(
                $snapshot[$id]['decisions'],
                count($this->decisions->forProduct($id)),
                'a decision was recorded on ' . $id
            );
            self::assertSame($snapshot[$id]['pending'], $this->revisions->pendingFor($id)?->id, 'the proposal moved on ' . $id);
        }
        self::assertSame($revision, $this->revisions->pendingFor($published)?->id);
        // …and the marks ARE there, so the test above is not passing because
        // nothing happened at all.
        self::assertCount(2, $this->store->marksFor($this->manager, [$submitted, $published]));
    }

    // ------------------------------------------------------------------ helpers

    /** The real render callback the admin menu registered, for one product. */
    private function renderDetail(int $productId): string
    {
        do_action('admin_menu');
        $_GET['page'] = 'tmc-product-review';
        $_GET['product'] = (string) $productId;
        $review = null;
        foreach (AdminExtensions::pages() as $page) {
            if ($page['slug'] === 'tmc-product-review') {
                $review = $page;
            }
        }
        self::assertNotNull($review, 'the review page must be registered');
        ob_start();
        try {
            ($review['render'])();
        } finally {
            $html = (string) ob_get_clean();
        }
        self::assertStringContainsString('tmc-admin', $html, 'the page must really have rendered');
        return $html;
    }

    private function unseen(): int
    {
        return (new ReviewSeen($this->store))->unseenCount($this->manager);
    }

    private function tokenOf(int $id): string
    {
        $snapshot = $this->products->findWithSubmission($id);
        self::assertNotNull($snapshot);
        return (string) $snapshot['submission'];
    }

    private function create(string $title): int
    {
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 100000, stock: 3),
            ProductStatus::Draft
        );
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function submit(string $title): int
    {
        $id = $this->create($title);
        $this->submitExisting($id);
        return $id;
    }

    /**
     * What a vendor's «اصلاح و ارسال مجدد» is: out of the queue, a new title,
     * back in with a new row in the decision trail.
     *
     * `ProductRowVersion::UNGUARDED` because this fixture is the concurrent
     * editor — it has not rendered a form and has no stamp to carry, and an
     * empty token is a REFUSAL rather than a waiver (`alpha.15`). A fixture
     * that passed `''` would silently write nothing and the test would be
     * about a product nobody edited.
     */
    private function retitle(int $id, string $title): void
    {
        self::assertTrue($this->products->updateStatus($id, ProductStatus::ChangesRequested, ''));
        self::assertTrue($this->products->updateDetails(
            $id,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 100000, stock: 3),
            ProductRowVersion::UNGUARDED
        ));
        $this->submitExisting($id);
    }

    private function submitExisting(int $id): void
    {
        self::assertTrue($this->products->updateStatus($id, ProductStatus::Submitted, ''));
        self::assertTrue($this->decisions->record($id, self::VENDOR, self::VENDOR, ProductDecision::SUBMITTED, ''));
    }

    /** A live product: submitted, then approved, so a proposal has something to sit on. */
    private function publish(string $title): int
    {
        $id = $this->submit($title);
        self::assertTrue($this->products->updateStatus($id, ProductStatus::Published, ''));
        self::assertTrue($this->decisions->record($id, self::VENDOR, $this->manager, ProductDecision::APPROVED, ''));
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
}
