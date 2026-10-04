<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Admin\Presentation\MenuRegistrar;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeenStoreInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage;

/**
 * WHAT THIS PROVES: the red count is right on the page that lowered it, in the
 * HTML of that same request, with no JavaScript and nowhere else to go.
 *
 * `alpha.36` built the bubble while the menu was built and recorded the view
 * inside the render callback, and `wp-admin/admin.php` runs those in that
 * order with `admin-header.php` — which PRINTS the menu — in between. So the
 * one screen that cleared the notification was the one screen that still
 * showed it. «عدد اعلان باید در همان صفحه به‌روز شود».
 *
 * The order this test walks is core's, measured against WordPress 7.1's
 * `wp-admin/admin.php`:
 *
 * ```
 * 163  require ABSPATH . 'wp-admin/menu.php';   // `admin_menu`
 * 242  do_action( "load-{$page_hook}" );        // ← the fix lives here
 * 244  require_once ABSPATH . 'wp-admin/admin-header.php';   // prints $menu
 * 264  do_action( $page_hook );                 // render
 * ```
 *
 * So the assertions read `$GLOBALS['menu']` and `$GLOBALS['submenu']` — the
 * two arrays `wp-admin/menu-header.php` reads at print time — after the load
 * hook and before anything would have printed them. Asserting on
 * `ReviewSeen::unseenCount()` instead would have measured the database, which
 * was never in doubt; what was wrong was the string on the page.
 */
final class MenuBadgeRefreshTest extends DatabaseTestCase
{
    private const VENDOR = 7;

    private DatabaseInterface $db;
    private ProductRepositoryInterface $products;
    private DbProductDecisionRepository $decisions;
    private ReviewSeenStoreInterface $store;
    private int $manager = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        $container = Bootstrap::container();
        $container->get(MigrationRunner::class)->run();
        $this->db = $container->get(DatabaseInterface::class);
        $this->resetSchema($this->db);
        $this->products = $container->get(ProductRepositoryInterface::class);
        $this->decisions = new DbProductDecisionRepository($this->db, new SystemClock());
        $this->store = $container->get(ReviewSeenStoreInterface::class);
        $this->manager = get_current_user_id();
    }

    protected function tearDown(): void
    {
        $_GET['product'] = '';
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    /**
     * WHAT THIS PROVES: three waiting, the menu says three; the load hook runs;
     * the menu says nothing — in the same request, on both the submenu item
     * and the parent.
     */
    public function testOpeningTheListUpdatesBothBadgesOnTheSameRequest(): void
    {
        $ids = [$this->submit('دستکش'), $this->submit('ماسک'), $this->submit('گان')];
        self::assertSame(3, $this->unseen());

        $hook = $this->buildMenu();
        self::assertSame(3, $this->badgeOn(ProductReviewPage::SLUG), 'the menu must start by saying three');
        self::assertSame(3, $this->parentBadge(), 'and the parent carries the same three');

        // `load-{$hook}`: WordPress fires this BEFORE it requires
        // `admin-header.php`, so everything after this line is still ahead of
        // the menu being printed.
        $_GET['page'] = ProductReviewPage::SLUG;
        $_GET['product'] = '';
        do_action('load-' . $hook);

        self::assertSame(0, $this->badgeOn(ProductReviewPage::SLUG), 'zero removes the bubble entirely');
        self::assertSame(0, $this->parentBadge(), 'and takes it off the parent too');
        self::assertSame(0, $this->unseen(), 'the database agrees');
        self::assertCount(3, $this->store->marksFor($this->manager, $ids));

        // And the page still prints once, with what the load hook built.
        $html = $this->renderRegistered(ProductReviewPage::SLUG);
        self::assertStringContainsString('دستکش', $html);
        self::assertSame(1, substr_count($html, 'tmc-header__top'), 'the page rendered exactly once');
    }

    /**
     * WHAT THIS PROVES: a filtered list lowers the badge by what it SHOWED,
     * on that same page — not to nought.
     *
     * «فهرست فیلترشده و چندصفحه‌ای: فقط ردیف‌های نمایش‌داده‌شده». The badge is
     * re-asked from the database rather than decremented by «how many rows I
     * drew», which is why this number can only be right.
     */
    public function testAFilteredListLowersTheBadgeByWhatItShowed(): void
    {
        $this->submit('الف');
        $this->submit('ب');
        $this->submit('پ');
        $hook = $this->buildMenu();
        self::assertSame(3, $this->badgeOn(ProductReviewPage::SLUG));

        $_GET['page'] = ProductReviewPage::SLUG;
        $_GET['product'] = '';
        $_GET['q'] = 'الف';
        do_action('load-' . $hook);
        unset($_GET['q']);

        self::assertSame(2, $this->badgeOn(ProductReviewPage::SLUG), 'the search showed one of three');
        self::assertSame(2, $this->parentBadge());
    }

    /**
     * WHAT THIS PROVES: a view that could not be recorded shows no success.
     *
     * «اگر ثبت مشاهده شکست بخورد، رابط نباید عدد را کاهش‌یافته نشان دهد». The
     * number printed is read back from the database, so a failed write leaves
     * it where it was — there is no arithmetic here to be optimistic with.
     */
    public function testAFailedRecordLeavesTheBadgeWhereItWas(): void
    {
        $this->submit('سرنگ');
        $this->submit('ویال');
        $hook = $this->buildMenu();
        self::assertSame(2, $this->badgeOn(ProductReviewPage::SLUG));

        // The marks table, gone from under the page: every `markSeen()` write
        // fails and every count still answers, because the count's LEFT JOIN
        // is what breaks — so this also proves the count's own failure is not
        // mistaken for «nothing unseen».
        $table = $this->db->prefix() . 'tmc_review_seen';
        self::assertNotNull($this->db->execute('RENAME TABLE `' . $table . '` TO `' . $table . '_away`'));

        $_GET['page'] = ProductReviewPage::SLUG;
        $_GET['product'] = '';
        do_action('load-' . $hook);

        // Nothing was recorded, and nothing claims it was: the label is
        // untouched, which is the same string the request started with.
        self::assertSame(2, $this->badgeOn(ProductReviewPage::SLUG), 'a failed write must not lower the number');
        self::assertSame(2, $this->parentBadge());

        self::assertNotNull($this->db->execute('RENAME TABLE `' . $table . '_away` TO `' . $table . '`'));
        self::assertSame(2, $this->unseen(), 'and the view really was not recorded');
    }

    /**
     * WHAT THIS PROVES: another page's notification on the parent survives the
     * products one being cleared.
     *
     * «اگر در آینده والد اعلان دیگری داشته باشد، به‌روزرسانی اعلان محصولات آن
     * را حذف نکند». The parent is the SUM of every page's own count, re-asked
     * from scratch; subtracting «the rows I marked» from it would have taken
     * the sibling's number down with it.
     */
    public function testASiblingNotificationOnTheParentSurvives(): void
    {
        $this->submit('ترالی');
        add_filter(AdminExtensions::FILTER, static function (array $pages): array {
            $pages[] = [
                'slug' => 'tmc-health',
                'page_title' => 'سلامت',
                'menu_label' => 'سلامت',
                'capability' => 'manage_options',
                'render' => static function (): void {
                },
                'bubble' => static fn (): int => 4,
            ];
            return $pages;
        }, 50);

        $hook = $this->buildMenu();
        self::assertSame(1, $this->badgeOn(ProductReviewPage::SLUG));
        self::assertSame(5, $this->parentBadge(), 'one waiting plus the sibling\'s four');

        $_GET['page'] = ProductReviewPage::SLUG;
        $_GET['product'] = '';
        do_action('load-' . $hook);

        self::assertSame(0, $this->badgeOn(ProductReviewPage::SLUG));
        self::assertSame(4, $this->parentBadge(), 'the sibling kept its own four');
    }

    /**
     * WHAT THIS PROVES: a page with no `prepare` hooks nothing on its load
     * hook, so opening it writes nothing and repaints nothing.
     *
     * The same guarantee `AdminPagesRenderTest` makes about rendering, one hook
     * earlier: the load hook is on every plugin screen, and a repaint wired to
     * all of them would be a marking call on all of them.
     */
    public function testAPageWithoutPrepareDoesNothingOnItsLoadHook(): void
    {
        $id = $this->submit('پالس اکسیمتر');
        $this->buildMenu();

        foreach (AdminExtensions::pages() as $page) {
            if ($page['slug'] === ProductReviewPage::SLUG) {
                self::assertNotNull($page['prepare'], 'the review page is the one page that prepares');
                continue;
            }
            self::assertNull($page['prepare'], $page['slug'] . ' must not prepare');
            $_GET['page'] = $page['slug'];
            do_action('load-tecteb-marketplace_page_' . $page['slug']);
        }

        self::assertSame(1, $this->badgeOn(ProductReviewPage::SLUG), 'nobody else touched the count');
        self::assertSame([], $this->store->marksFor($this->manager, [$id]));
    }

    // ------------------------------------------------------------------ helpers

    /** Runs `admin_menu` and returns the review page's hook suffix. */
    private function buildMenu(): string
    {
        do_action('admin_menu');
        return 'tecteb-marketplace_page_' . ProductReviewPage::SLUG;
    }

    /**
     * The number in the bubble on one submenu row of core's `$submenu`, as
     * `menu-header.php` would print it — or 0 when there is no bubble at all.
     */
    private function badgeOn(string $slug): int
    {
        foreach ((array) ($GLOBALS['submenu'][DashboardPage::SLUG] ?? []) as $row) {
            if (is_array($row) && (string) ($row[2] ?? '') === $slug) {
                return self::bubbleNumber((string) ($row[0] ?? ''));
            }
        }
        self::fail('no submenu row for ' . $slug);
    }

    /** The same, for the top-level «بازارگاه تک‌طب» row of core's `$menu`. */
    private function parentBadge(): int
    {
        foreach ((array) ($GLOBALS['menu'] ?? []) as $row) {
            if (is_array($row) && (string) ($row[2] ?? '') === DashboardPage::SLUG) {
                return self::bubbleNumber((string) ($row[0] ?? ''));
            }
        }
        self::fail('no top-level row for ' . DashboardPage::SLUG);
    }

    /**
     * Read from the markup, not from a number we kept.
     *
     * Both halves are checked: core's `count-N` class, which its CSS hooks on,
     * and the Persian digits a human reads. A label carrying one and not the
     * other is a bubble that is right for exactly one of them.
     */
    private static function bubbleNumber(string $label): int
    {
        if (!str_contains($label, MenuRegistrar::BUBBLE_CLASS)) {
            return 0;
        }
        self::assertSame(
            1,
            preg_match('/count-(\d+)/', $label, $m),
            'a bubble without core\'s count class: ' . $label
        );
        $count = (int) $m[1];
        self::assertStringContainsString(
            '>' . PersianDigits::toPersian((string) $count) . '<',
            $label,
            'the digits a human reads must be the same number'
        );
        return $count;
    }

    private function unseen(): int
    {
        return Bootstrap::container()->get(ReviewSeen::class)->unseenCount($this->manager);
    }

    private function renderRegistered(string $slug): string
    {
        foreach (AdminExtensions::pages() as $page) {
            if ($page['slug'] !== $slug) {
                continue;
            }
            ob_start();
            try {
                ($page['render'])();
            } finally {
                return (string) ob_get_clean();
            }
        }
        self::fail('no registered page ' . $slug);
    }

    private function submit(string $title): int
    {
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 100000, stock: 3),
            ProductStatus::Submitted
        );
        self::assertGreaterThan(0, $id);
        self::assertTrue($this->decisions->record($id, self::VENDOR, self::VENDOR, ProductDecision::SUBMITTED, ''));
        return $id;
    }
}
