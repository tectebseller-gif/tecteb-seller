<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminNavigation;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: hiding the temporary section takes it out of the MENU and
 * leaves the pages registered — and every one of them still declares a
 * capability.
 *
 * ### The two halves, and why both are needed
 *
 * §5 asks for the migration tools to leave the everyday menu once a manager
 * confirms the migration finished, and adds: «ابزارهای پنهان‌شده نباید از
 * طریق URL مستقیم توسط کاربران فاقد مجوز قابل اجرا باشند». Those are two
 * different requirements and only one of them is about hiding:
 *
 *  - gone from the printed menu — asserted here, against the same
 *    `$GLOBALS['submenu']` array core prints from;
 *  - unreachable by a user WITHOUT the capability — which is not a property
 *    of hiding at all. It is a property of `add_submenu_page()` being called
 *    with a capability, which is why the second test below asserts that every
 *    page in the section declares a non-empty one.
 *
 * ⛔ **What is deliberately NOT asserted, and must not be claimed from this
 * file:** whether a capable manager can still open a hidden page by typing
 * its URL. That is `user_can_access_admin_page()` in
 * `wp-admin/includes/menu.php`, no WordPress checkout exists in this
 * environment, and `alpha.37` is exactly what this repository paid for
 * asserting core's menu internals from memory — a redirect registered on
 * `admin_init` that core had already 403'd sixty lines earlier. It is step 9
 * of the staging checklist instead.
 *
 * It is also not load-bearing: the supported way back is the button on the
 * health page, which needs no URL.
 */
final class MigrationToolsHideTest extends TestCase
{
    protected function setUp(): void
    {
        State::reset();
        $GLOBALS['submenu'] = [];
    }

    /**
     * The registration call is unchanged; only the menu array loses the row.
     *
     * Written against the stub of core's own function, which removes from
     * `$GLOBALS['submenu']` and leaves `State::$menus` — this environment's
     * stand-in for `$_registered_pages` — alone.
     */
    public function testRemovingAPageFromTheMenuLeavesItRegistered(): void
    {
        $hook = add_submenu_page(
            'tmc-dashboard',
            'ورود از دکان',
            'ورود از دکان',
            'tmc_review_vendor',
            'tmc-import',
            static fn (): string => ''
        );
        self::assertNotSame('', $hook, 'the page was registered and has a hook suffix');
        self::assertTrue($this->menuHas('tmc-import'), 'and it is in the printed menu');

        $removed = remove_submenu_page('tmc-dashboard', 'tmc-import');

        self::assertIsArray($removed, 'core answers with the row it took out');
        self::assertFalse($this->menuHas('tmc-import'), 'gone from the printed menu');
        // …and still registered, with its capability. This is the half that
        // makes hiding different from not registering: a page that was never
        // registered has no hook, no capability and no stylesheet.
        $registered = array_column(State::$menus, 'slug');
        self::assertContains('tmc-import', $registered, 'the registration record survives');
    }

    /** Removing a slug that is not there changes nothing and says so. */
    public function testRemovingAPageThatIsNotThereIsFalseAndHarmless(): void
    {
        add_submenu_page('tmc-dashboard', 'x', 'x', 'tmc_review_vendor', 'tmc-handover');

        self::assertFalse(remove_submenu_page('tmc-dashboard', 'tmc-nothing-like-this'));
        self::assertTrue($this->menuHas('tmc-handover'), 'the real page is untouched');
    }

    /**
     * ⛔ EVERY PAGE IN THE TEMPORARY SECTION DECLARES A CAPABILITY.
     *
     * This is the assertion that answers «نباید توسط کاربران فاقد مجوز
     * قابل اجرا باشند», and it is about registration rather than hiding:
     * `add_submenu_page()` refuses the page to anybody without the capability
     * whether or not the row is in the menu. A section whose pages passed
     * `'read'` would be reachable by every subscriber on the site, hidden or
     * not.
     */
    public function testEveryMigrationToolDeclaresACapability(): void
    {
        $pages = [
            'tmc-import' => 'Modules/Migration/Presentation/Admin/MigrationPage.php',
            'tmc-handover' => 'Modules/Migration/Presentation/Admin/HandoverPage.php',
            'tmc-minmax-exit' => 'Modules/Migration/Presentation/Admin/MinMaxExitPage.php',
        ];
        self::assertSame(
            array_keys($pages),
            AdminNavigation::GROUPS[AdminNavigation::MIGRATION],
            'this test and the navigation must name the same section'
        );
        foreach ($pages as $slug => $file) {
            $code = (string) file_get_contents(__DIR__ . '/../../src/' . $file);
            self::assertMatchesRegularExpression(
                '/public const CAPABILITY\s*=\s*(?!\s*\'\'|\s*\'read\')/',
                $code,
                $slug . ' must declare a capability that is neither empty nor «read»'
            );
            self::assertStringContainsString(
                'REVIEW_VENDOR',
                $code,
                $slug . ' is a migration tool and belongs to the vendor-review capability'
            );
        }
    }

    private function menuHas(string $slug): bool
    {
        foreach ((array) ($GLOBALS['submenu']['tmc-dashboard'] ?? []) as $row) {
            if (is_array($row) && ($row[2] ?? null) === $slug) {
                return true;
            }
        }
        return false;
    }
}
