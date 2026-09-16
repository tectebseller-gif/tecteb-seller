<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
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
