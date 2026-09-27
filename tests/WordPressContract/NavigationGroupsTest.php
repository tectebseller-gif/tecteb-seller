<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminNavigation;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Admin\Presentation\Navigation;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the five groups account for every page, and the header
 * shows one group at a time.
 *
 * «فهرست واقعی صفحات را از کد استخراج کن و تطبیق بده تا صفحه‌ای جا نماند» is a
 * reconciliation, and a reconciliation somebody does by reading is one that is
 * right on the day it is done. So the page list here is not written out: it is
 * whatever the loaded modules registered plus the four this module owns, and
 * the assertion is that each of them is claimed by exactly one group.
 *
 * The other half matters just as much. `AdminNavigation::groups()` has a bucket
 * for a page no group claims, so a page added tomorrow and forgotten still
 * appears in the header and still opens — and this test says that bucket is
 * EMPTY today. A safety net that quietly catches things is a place pages go to
 * be ignored; one that is asserted empty is a safety net.
 */
final class NavigationGroupsTest extends ContractTestCase
{
    /** @return list<string> every slug the sidebar registers, however it got there */
    private function everySlug(): array
    {
        $slugs = [];
        foreach (AdminNavigation::CORE_PAGES as $page) {
            $slugs[] = $page::SLUG;
        }
        foreach (AdminExtensions::pages() as $extra) {
            $slugs[] = $extra['slug'];
        }
        return $slugs;
    }

    public function testEveryRegisteredPageIsInExactlyOneGroup(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        $slugs = $this->everySlug();
        self::assertGreaterThan(20, count($slugs), 'the modules must have registered their pages');
        self::assertSame(count($slugs), count(array_unique($slugs)), 'no slug is registered twice');

        $ungrouped = [];
        $twice = [];
        foreach ($slugs as $slug) {
            $in = [];
            foreach (AdminNavigation::GROUPS as $group => $members) {
                if (in_array($slug, $members, true)) {
                    $in[] = $group;
                }
            }
            if ($in === []) {
                $ungrouped[] = $slug;
            } elseif (count($in) > 1) {
                $twice[] = $slug . ' → ' . implode(', ', $in);
            }
        }
        self::assertSame([], $ungrouped, 'these registered pages are in no group: ' . implode(', ', $ungrouped));
        self::assertSame([], $twice, 'these pages are in more than one group: ' . implode(', ', $twice));

        // And nothing in the definition points at a page that does not exist: a
        // group holding a retired slug renders a dead link, which is the one
        // thing the grouping was told not to add.
        $unknown = array_values(array_diff(AdminNavigation::order(), $slugs));
        self::assertSame([], $unknown, 'these grouped slugs are not registered: ' . implode(', ', $unknown));
    }

    public function testTheGroupsCoverTheSameSetTheHeaderDraws(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        $items = Navigation::items();
        $groups = AdminNavigation::groups($items, 'tmc-audit');

        $keys = array_column($groups, 'key');
        self::assertSame(
            [AdminNavigation::EVERYDAY, AdminNavigation::SALES, AdminNavigation::CONTENT, AdminNavigation::TOOLS, AdminNavigation::SETUP],
            $keys,
            'five groups, in order, and no «سایر بخش‌ها»'
        );

        $flat = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $flat[] = $item['slug'];
            }
        }
        $was = array_column($items, 'slug');
        sort($was);
        $now = $flat;
        sort($now);
        // Sorted on purpose: the ORDER is what the grouping changed, and the
        // claim here is about the SET — every link the flat header used to draw
        // is still reachable, and no more.
        self::assertSame($was, $now, 'the groups cover exactly the pages the header used to list');
        self::assertSame(count($flat), count(array_unique($flat)), 'no page is drawn in two groups');
    }

    /** WHAT THIS PROVES: one group open, and the page's own group is the open one. */
    public function testOnlyTheCurrentGroupsPagesAreRenderedAndTheGroupFollowsTheSlug(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        $html = Components::shellOpen('ممیزی', 'tmc-audit', 'نسخه آزمایشی');

        // The group row: all five, with «تنظیمات و ابزارها» marked.
        foreach (['روزمره', 'فروش و مالی', 'محتوا و گزارش', 'تنظیمات و ابزارها', 'راه‌اندازی و مهاجرت'] as $label) {
            self::assertStringContainsString('>' . $label . '</a>', $html, 'group ' . $label);
        }
        self::assertStringContainsString('aria-current="true"', $html, 'the open group says so');
        self::assertStringContainsString('aria-current="page"', $html, 'the current page says so');
        // Exactly one of each: two current groups, or two current pages, is two
        // answers to one question.
        self::assertSame(1, substr_count($html, 'aria-current="true"'));
        self::assertSame(1, substr_count($html, 'aria-current="page"'));

        // The open group's pages are there…
        foreach (['ممیزی', 'صف و سلامت اجرا', 'رویدادها و API', 'تنظیمات', 'ماژول‌ها', 'سلامت'] as $label) {
            self::assertStringContainsString($label, $html, 'page ' . $label . ' of the open group');
        }
        // …and no other group's are. Measured on the page LINKS rather than on
        // the words, because «گزارش‌ها» would match a sentence somewhere and
        // the question here is whether a link was drawn.
        $links = substr_count($html, '<a class="tmc-nav__link');
        self::assertSame(
            count(AdminNavigation::GROUPS[AdminNavigation::TOOLS]),
            $links,
            'only the open group draws page links'
        );
        self::assertSame(5, substr_count($html, '<a class="tmc-nav__group'), 'five group links');

        // A direct link to another group's page opens THAT group, with nothing
        // stored anywhere.
        $other = Components::shellOpen('مهاجرت از دکان', 'tmc-import', 'نسخه آزمایشی');
        self::assertStringContainsString('is-current" href', $other);
        self::assertSame(
            count(AdminNavigation::GROUPS[AdminNavigation::SETUP]),
            substr_count($other, '<a class="tmc-nav__link'),
            'the setup group is the open one now'
        );
        self::assertStringNotContainsString('>ممیزی</a>', $other, 'the tools group is closed');
    }

    /**
     * WHAT THIS PROVES: a viewer who may open only some pages gets only those,
     * and a group with nothing in it for them is not drawn at all.
     *
     * A group heading that leads to a page the viewer cannot open is a dead
     * link. The capability filter is `Navigation::items()`, which is the same
     * one the sidebar uses — so this is also the test that the header cannot
     * offer more than the menu.
     */
    public function testAGroupWithNoPermittedPageIsNotDrawn(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        // Only «بررسی محصولات» — one page, in «روزمره».
        State::loginAs(2, ['tmc_review_products']);

        $groups = AdminNavigation::groups(Navigation::items(), 'tmc-product-review');
        self::assertSame([AdminNavigation::EVERYDAY], array_column($groups, 'key'));
        self::assertSame(['tmc-product-review'], array_column($groups[0]['items'], 'slug'));
        self::assertTrue($groups[0]['current']);

        $html = Components::shellOpen('بررسی محصولات', 'tmc-product-review', '');
        self::assertStringNotContainsString('tmc-audit', $html, 'a page they cannot open is not linked');
        self::assertStringNotContainsString('راه‌اندازی و مهاجرت', $html, 'an empty group is not a heading');
        self::assertSame(1, substr_count($html, '<a class="tmc-nav__group'));
    }

    /**
     * WHAT THIS PROVES: the menu works with the stylesheet and the script both
     * missing — the state is in the markup, not in a class a script adds.
     *
     * `<details>` is the whole mechanism: collapsed without CSS, operable
     * without JavaScript, and its open state announced by the browser. The
     * visible «نمایش»/«بستن» pair is `aria-hidden` on purpose, because the
     * browser already says it.
     */
    public function testTheMenuNeedsNoScript(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        $html = Components::shellOpen('پیشخوان', 'tmc-dashboard', 'نسخه آزمایشی');
        self::assertStringContainsString('<details class="tmc-nav" id="tmc-nav">', $html);
        self::assertStringContainsString('<summary class="tmc-nav__toggle">', $html);
        self::assertStringContainsString('نمایش', $html);
        self::assertStringContainsString('بستن', $html);
        self::assertStringContainsString('aria-hidden="true"><span class="tmc-nav__state-closed">', $html);
        // Every destination is an href. A group that needed a click handler to
        // reveal its pages would be a group nobody without JavaScript can open.
        self::assertSame(0, substr_count($html, 'onclick'));
        self::assertSame(0, substr_count($html, '<button'));
        // «گروه › صفحه» on the closed toggle, so a collapsed menu still says
        // where you are.
        self::assertStringContainsString('روزمره › پیشخوان', $html);
    }
}
