<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpReviewSeenStore;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: every registered admin page actually RENDERS.
 *
 * This test exists because of a defect that reached the disposable site.
 * `AdminMenuAndAssetsTest` asserted that four new pages were registered with
 * the right slug, label and capability — and every one of them threw the
 * moment it was opened, because they constructed `Request` directly and its
 * constructor is private. Registration was measured; rendering was not; and
 * «the page is in the menu» had been treated as «the page works».
 *
 * So: call every page's render callback, and require HTML with the shell in it.
 * The suite deliberately does NOT hard-code the list — it walks whatever the
 * loaded modules registered, so the next page added is covered by the fact of
 * being added.
 *
 * It lives in the DATABASE suite because these pages read the database, and a
 * render test that stubbed that away would be measuring a different page from
 * the one a manager opens. Running the migrations first is part of the test:
 * a page against a schema that has not caught up is one of the things this
 * catches.
 */
final class AdminPagesRenderTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real tables, built by the real migrations — the same ones an upgrade
        // runs. A page that needs schema 13 must find schema 13.
        $this->bootPlugin(true);
        $this->loginAdmin();
        Bootstrap::container()->get(MigrationRunner::class)->run();
    }

    public function testEveryRegisteredPageRendersTheAdminShell(): void
    {
        do_action('admin_menu');

        $pages = AdminExtensions::pages();
        self::assertNotSame([], $pages, 'the modules must register at least one page');

        $rendered = [];
        foreach ($pages as $page) {
            $_GET['page'] = $page['slug'];

            ob_start();
            try {
                ($page['render'])();
            } catch (\Throwable $e) {
                ob_end_clean();
                self::fail(sprintf(
                    'page %s threw while rendering: %s (%s:%d)',
                    $page['slug'],
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
            $html = (string) ob_get_clean();

            self::assertNotSame('', $html, 'page ' . $page['slug'] . ' rendered nothing at all');
            self::assertStringContainsString(
                'tmc-admin',
                $html,
                'page ' . $page['slug'] . ' did not render the admin shell'
            );
            // Persian, and RTL: a page that came out in English or LTR is a
            // page that skipped the shell and printed something of its own.
            self::assertMatchesRegularExpression(
                '/[\x{0600}-\x{06FF}]/u',
                $html,
                'page ' . $page['slug'] . ' has no Persian text'
            );
            $rendered[] = $page['slug'];
        }

        // And the four Operations pages this release added are among them,
        // named explicitly so removing one is a failure rather than a smaller
        // number nobody notices.
        foreach (['tmc-setup', 'tmc-jobs', 'tmc-events', 'tmc-audit'] as $slug) {
            self::assertContains($slug, $rendered);
        }
    }

    /**
     * A page a user may not see must refuse, not render.
     *
     * The capability is declared in the menu registration; this checks the
     * page ENFORCES it too. A gate that lives only in the menu is bypassed by
     * anybody who knows the URL.
     */
    /**
     * WHAT THIS PROVES: every page renders with ROWS in it, not just empty.
     *
     * `testEveryRegisteredPageRendersTheAdminShell()` opens each page against
     * empty tables. That catches a page that cannot boot — and it caught two
     * in `alpha.13` — but it never reaches the code that draws a row, because
     * there are no rows. The audit page fatalled on its very first record for
     * three releases underneath a green suite that "rendered" it every run:
     * `details()` returned a map where `Components::dataList()` takes a list,
     * and `$row['label']` on a string is a TypeError in PHP 8.
     *
     * So: write a record first, then render. A page proved to survive zero
     * rows is not a page proved to work.
     */
    public function testEveryRegisteredPageRendersWithRealRowsInIt(): void
    {
        // A trail with several shapes in it: a payload with scalars, one with
        // a nested array (which `details()` must skip, not crash on), and one
        // with nothing at all.
        $audit = Bootstrap::container()->get(AuditLogger::class);
        $audit->log(AuditEventCatalog::PRODUCT_SAVED, 1, 'product', '11', ['product_id' => 11, 'status' => 'draft']);
        $audit->log(AuditEventCatalog::SETTINGS_UPDATED, 1, 'settings', 'general', ['changed' => ['a', 'b']]);
        $audit->log(AuditEventCatalog::PLUGIN_ACTIVATED, 1, null, null, []);

        do_action('admin_menu');
        $pages = AdminExtensions::pages();
        self::assertNotSame([], $pages);

        foreach ($pages as $page) {
            $_GET['page'] = $page['slug'];
            ob_start();
            try {
                ($page['render'])();
            } catch (\Throwable $e) {
                ob_end_clean();
                self::fail(sprintf(
                    'page %s threw while rendering a real row: %s (%s:%d)',
                    $page['slug'],
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
            $html = (string) ob_get_clean();
            self::assertNotSame('', $html, 'page ' . $page['slug'] . ' rendered nothing at all');
        }

        // And the audit page specifically must now be SHOWING one of them —
        // otherwise this test would pass against a page that silently drew no
        // rows, which is the same blind spot one level along.
        $_GET['page'] = 'tmc-audit';
        $auditPage = null;
        foreach ($pages as $page) {
            if ($page['slug'] === 'tmc-audit') {
                $auditPage = $page;
            }
        }
        self::assertNotNull($auditPage, 'the audit page must be registered');
        ob_start();
        ($auditPage['render'])();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('product_id', $html, 'the audit page must actually print a payload row');
    }

    /**
     * WHAT THIS PROVES: only the review screens mark a submission as seen.
     *
     * «باز کردن پیشخوان بازارگاه یا منو، به‌تنهایی نباید اعلانی را پاک کند». The
     * risk is not theoretical: the badge is built while the MENU is built, on
     * every wp-admin request, so a marking call in the wrong place would clear
     * the notification for every screen the manager happens to open — including
     * screens that show them nothing about the product.
     *
     * Measured by rendering every registered page, one at a time, and asking
     * after each one whether a mark appeared. The review pages are named as the
     * only two that may.
     */
    public function testOnlyTheReviewScreensRecordThatASubmissionWasSeen(): void
    {
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        // Measured, not assumed. This class does not reset the schema, so «the
        // queue is empty» is a claim about whatever ran before — and when it is
        // wrong the failure is about a number rather than about the thing under
        // test.
        $before = $products->countAwaitingReview();
        $id = $products->create(
            7,
            new ProductDetails(title: 'ماسک سه‌لایه', categoryKey: 'gloves', priceMinor: 120000, stock: 5),
            ProductStatus::Submitted
        );
        self::assertGreaterThan(0, $id);
        self::assertSame($before + 1, $products->countAwaitingReview(), 'the fixture must be waiting for review');

        // Building the menu — which is what computes the red count — writes
        // nothing. The count is a question; answering it is not reading.
        State::$userMeta = [];
        do_action('admin_menu');
        self::assertSame([], State::$userMeta, 'building the menu marked something as seen');

        $pages = AdminExtensions::pages();
        $marked = [];
        foreach ($pages as $page) {
            State::$userMeta = [];
            $_GET['page'] = $page['slug'];
            $_GET['product'] = '';
            ob_start();
            try {
                ($page['render'])();
            } finally {
                ob_end_clean();
            }
            if (isset(State::$userMeta[1][WpReviewSeenStore::META_KEY])) {
                $marked[] = $page['slug'];
            }
        }
        self::assertSame(['tmc-product-review'], $marked, 'these pages recorded a view: ' . implode(', ', $marked));

        // And the product page of the review screen does too — the other half of
        // «مشاهدهٔ موفق جزئیات محصول».
        State::$userMeta = [];
        $_GET['page'] = 'tmc-product-review';
        $_GET['product'] = (string) $id;
        $review = null;
        foreach ($pages as $page) {
            if ($page['slug'] === 'tmc-product-review') {
                $review = $page;
            }
        }
        self::assertNotNull($review);
        ob_start();
        try {
            ($review['render'])();
        } finally {
            ob_end_clean();
        }
        self::assertArrayHasKey(
            (string) $id,
            (array) State::$userMeta[1][WpReviewSeenStore::META_KEY],
            'opening the product did not record its submission'
        );
        $_GET['product'] = '';

        // And give the queue back. The contract suite's stub `wpdb` is a real
        // PDO on this same database, and a product left waiting here puts a red
        // bubble into a menu title another suite asserts exactly.
        self::assertTrue($products->updateStatus($id, ProductStatus::Archived, ''));
        self::assertSame($before, $products->countAwaitingReview(), 'the fixture gave back what it spent');
    }

    public function testAPageRefusesAUserWithoutItsCapability(): void
    {
        do_action('admin_menu');

        $pages = AdminExtensions::pages();
        State::loginAs(77, ['read']);            // a subscriber, nothing more

        foreach ($pages as $page) {
            State::$wpDieCalls = [];
            $_GET['page'] = $page['slug'];
            ob_start();
            try {
                ($page['render'])();
            } catch (\Throwable) {
                // wp_die() in the stub throws; that IS the refusal.
            }
            ob_end_clean();
            self::assertNotSame(
                [],
                State::$wpDieCalls,
                'page ' . $page['slug'] . ' rendered for a user without its capability'
            );
        }
    }
}
