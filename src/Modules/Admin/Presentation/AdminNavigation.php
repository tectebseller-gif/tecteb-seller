<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\ModulesPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\SettingsPage;

/**
 * The twenty-two manager screens, in five groups — written ONCE.
 *
 * Two lists used to decide where a page appears: the WordPress submenu, built
 * in `MenuRegistrar` in the order the modules happened to register, and the
 * blue header, built in `Components::shellOpen()` from `Navigation::items()`.
 * They could not disagree about WHICH pages exist (both read
 * `AdminExtensions::pages()`), but they could and did disagree about order —
 * and neither had any notion of a group. The owner's complaint was the result:
 * «همهٔ گزینه‌ها یک‌جا باز»، twenty-two pills above every page, with the four
 * screens somebody opens every day sitting between a migration tool and an
 * event log.
 *
 * So the grouping lives here, and both readers ask this class. A page that
 * nobody put in a group is NOT dropped — `groups()` returns it in a final
 * bucket — because a navigation definition that can hide a registered page is
 * worse than one that is untidy. `NavigationGroupsTest` then asserts that
 * bucket is empty for the real page set, so «سایر بخش‌ها» is a safety net that
 * never renders rather than a place things quietly land.
 *
 * **Slugs, not classes.** Half of these pages live in other modules and are
 * contributed through a filter; naming them by slug is what lets this list be
 * one list. It also means the reconciliation is a string comparison a test can
 * do, which is the only way «هیچ صفحه‌ای جا نماند» can be checked rather than
 * believed.
 */
final class AdminNavigation
{
    public const EVERYDAY = 'everyday';
    public const SALES = 'sales';
    public const CONTENT = 'content';
    public const TOOLS = 'tools';
    public const SETUP = 'setup';
    /** Where a registered page with no group goes, so it can never vanish. */
    public const OTHER = 'other';

    /**
     * Group key => the slugs in it, in the order they appear.
     *
     * The order inside a group is the order in the sidebar and in the header's
     * second row, and the order of the groups is the order of the first row.
     * Everyday work first and migration last is the requirement, and it is this
     * array that keeps it.
     *
     * @var array<string,list<string>>
     */
    public const GROUPS = [
        self::EVERYDAY => [
            'tmc-dashboard',
            'tmc-product-review',
            'tmc-vendor-applications',
            'tmc-vendor-documents',
            'tmc-tickets',
        ],
        self::SALES => [
            'tmc-storefront',
            'tmc-commission-rules',
            'tmc-withdrawals',
            'tmc-returns',
            'tmc-wholesale',
        ],
        self::CONTENT => [
            'tmc-spec-templates',
            'tmc-reviews',
            'tmc-reports',
        ],
        self::TOOLS => [
            'tmc-settings',
            'tmc-modules',
            'tmc-health',
            'tmc-jobs',
            'tmc-events',
            'tmc-audit',
        ],
        self::SETUP => [
            'tmc-setup',
            'tmc-import',
            'tmc-handover',
        ],
    ];

    /**
     * The four pages this module owns, in the order `MenuRegistrar` builds them.
     *
     * Listed here rather than in the registrar so that one place knows every
     * slug: the reconciliation test can then ask THIS class for the full set
     * instead of instantiating a registrar that needs WordPress.
     *
     * @var list<class-string<Pages\AbstractPage>>
     */
    public const CORE_PAGES = [
        DashboardPage::class,
        HealthPage::class,
        SettingsPage::class,
        ModulesPage::class,
    ];

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            self::EVERYDAY => __('روزمره', 'tecteb-marketplace-core'),
            self::SALES => __('فروش و مالی', 'tecteb-marketplace-core'),
            self::CONTENT => __('محتوا و گزارش', 'tecteb-marketplace-core'),
            self::TOOLS => __('تنظیمات و ابزارها', 'tecteb-marketplace-core'),
            self::SETUP => __('راه‌اندازی و مهاجرت', 'tecteb-marketplace-core'),
            default => __('سایر بخش‌ها', 'tecteb-marketplace-core'),
        };
    }

    /** Which group a slug belongs to, or '' when no group claims it. */
    public static function groupOf(string $slug): string
    {
        foreach (self::GROUPS as $group => $slugs) {
            if (in_array($slug, $slugs, true)) {
                return $group;
            }
        }
        return '';
    }

    /**
     * Every grouped slug, groups in order, slugs in order.
     *
     * `MenuRegistrar` sorts by position in this list, so the sidebar reads
     * everyday-first and migration-last without the registrar knowing what a
     * group is.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Where a slug sits in the sidebar, and where an unknown one sits: last.
     *
     * A page added tomorrow and forgotten here still registers, still opens,
     * and lands at the end of the menu — visible and out of the way. Losing it
     * would be the real failure.
     */
    public static function position(string $slug): int
    {
        $at = array_search($slug, self::order(), true);
        return $at === false ? PHP_INT_MAX : (int) $at;
    }

    /**
     * The header's two rows, for one viewer and one current page.
     *
     * `$items` is `Navigation::items()` — already filtered to what this user may
     * open — so a group with nothing in it for this viewer is not rendered at
     * all. That is not a cosmetic choice: a group heading that leads to a page
     * the viewer cannot open is a dead link, and the owner asked for none.
     *
     * The active group is derived from the CURRENT slug, so a direct link to
     * any page opens with the right group already selected, with no state kept
     * anywhere.
     *
     * Each group's own link points at its first permitted page. Groups are not
     * pages — there is no `tmc-group-sales` screen and this round adds none —
     * so the only honest destination for «فروش و مالی» is the first thing in
     * it.
     *
     * @param list<array{slug:string,label:string,capability:string,url:string}> $items
     * @return list<array{key:string,label:string,url:string,current:bool,items:list<array{slug:string,label:string,url:string,current:bool}>}>
     */
    public static function groups(array $items, string $currentSlug): array
    {
        $bySlug = [];
        foreach ($items as $item) {
            $bySlug[$item['slug']] = $item;
        }
        $currentGroup = self::groupOf($currentSlug);

        $out = [];
        foreach (self::GROUPS as $group => $slugs) {
            $rows = [];
            foreach ($slugs as $slug) {
                if (!isset($bySlug[$slug])) {
                    continue;
                }
                $rows[] = [
                    'slug' => $slug,
                    'label' => (string) $bySlug[$slug]['label'],
                    'url' => (string) $bySlug[$slug]['url'],
                    'current' => $slug === $currentSlug,
                ];
                unset($bySlug[$slug]);
            }
            if ($rows === []) {
                continue;
            }
            $out[] = [
                'key' => $group,
                'label' => self::groupLabel($group),
                'url' => $rows[0]['url'],
                'current' => $group === $currentGroup,
                'items' => $rows,
            ];
        }

        // Anything left is a registered page no group claims. It keeps its
        // link; the test says this must be empty.
        $rest = [];
        foreach ($bySlug as $slug => $item) {
            $rest[] = [
                'slug' => (string) $slug,
                'label' => (string) $item['label'],
                'url' => (string) $item['url'],
                'current' => $slug === $currentSlug,
            ];
        }
        if ($rest !== []) {
            $out[] = [
                'key' => self::OTHER,
                'label' => self::groupLabel(self::OTHER),
                'url' => $rest[0]['url'],
                'current' => $currentGroup === '',
                'items' => $rest,
            ];
        }

        // Exactly one group is open, always. A slug this viewer cannot see, or
        // one nothing registered, would otherwise leave the second row empty —
        // a header that shows no pages at all, which is worse than showing the
        // wrong ones.
        $anyCurrent = false;
        foreach ($out as $group) {
            $anyCurrent = $anyCurrent || $group['current'];
        }
        if (!$anyCurrent && $out !== []) {
            $out[0]['current'] = true;
        }
        return $out;
    }
}
