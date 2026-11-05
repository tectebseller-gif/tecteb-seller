<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\ModulesPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\SettingsPage;

/**
 * The twenty-five manager screens, in eight groups and one temporary one —
 * written ONCE.
 *
 * Two lists used to decide where a page appears: the WordPress submenu, built
 * in `MenuRegistrar` in the order the modules happened to register, and the
 * blue header, built in `Components::shellOpen()` from `Navigation::items()`.
 * They could not disagree about WHICH pages exist (both read
 * `AdminExtensions::pages()`), but they could and did disagree about order —
 * and neither had any notion of a group. The owner's complaint was the result:
 * «همهٔ گزینه‌ها یک‌جا باز»، twenty-five pills above every page, with the four
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
    public const DASHBOARD = 'dashboard';
    public const SHOPS = 'shops';
    public const PRODUCTS = 'products';
    public const ORDERS = 'orders';
    public const FINANCE = 'finance';
    public const REQUESTS = 'requests';
    public const SETTINGS = 'settings';
    public const HEALTH = 'health';

    /**
     * The migration tools, kept apart on purpose.
     *
     * The owner's §5: «ابزارهای مهاجرت … را در یک بخش مدیریتی محدود و مشخص
     * سازمان‌دهی کن» and «پس از تأیید موفقیت مهاجرت سایت اصلی، بتوان
     * ابزارهای موقت را با یک مرحله پاک‌سازی کنترل‌شده از رابط و بسته نهایی
     * حذف کرد.»
     *
     * ⛔ So this group is LAST and named as temporary on the screen, and the
     * cleanup is ONE EDIT: delete this array's entry and the three page
     * classes it names. That is «یک مرحله پاک‌سازی کنترل‌شده» taken at its
     * word — a code change in a release, not a runtime switch.
     *
     * A runtime flag was considered and rejected. A hidden page whose address
     * still works is a page a manager cannot find and cannot be told about,
     * and «ویرایش دستی یک ردیف option» as the way back is the shape
     * `alpha.80` calls worse than having no gate at all. Removing the tools is
     * a decision about a release; until that release they are visible, named
     * as temporary, and out of the way.
     *
     * ⛔ **And nothing is deleted while the migration can still need
     * resuming.** `docs/dokan-exit-blockers-alpha89-fa.md` holds the list of
     * what may go and in which order; the migration CODE — the steps, the
     * finance archive, the rollback — stays whatever happens to the menu,
     * because a step already shipped can never be unshipped.
     */
    public const MIGRATION = 'migration';

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
        // 1 — پیشخوان
        self::DASHBOARD => [
            'tmc-dashboard',
        ],
        // 2 — فروشگاه‌ها و فروشندگان: the shop's own file, its application,
        //     and the document types an application is judged against.
        self::SHOPS => [
            'tmc-vendor-shops',
            'tmc-vendor-applications',
            'tmc-vendor-documents',
        ],
        // 3 — محصولات: review, the spec templates a product is judged
        //     against, and the reviews customers leave on one.
        self::PRODUCTS => [
            'tmc-product-review',
            'tmc-spec-templates',
            'tmc-reviews',
        ],
        // 4 — سفارش‌ها: the marketplace's selling state, and the returns
        //     that come out of an order.
        self::ORDERS => [
            'tmc-storefront',
            'tmc-returns',
        ],
        // 5 — کمیسیون و تسویه
        self::FINANCE => [
            'tmc-commission-rules',
            'tmc-withdrawals',
            'tmc-supplier-payments',
            'tmc-wholesale',
        ],
        // 6 — درخواست‌ها و اعلان‌ها
        self::REQUESTS => [
            'tmc-tickets',
        ],
        // 7 — تنظیمات: the settings, the module switches, and the first-run
        //     checklist that only ever points at them.
        self::SETTINGS => [
            'tmc-settings',
            'tmc-modules',
            'tmc-setup',
        ],
        // 8 — سلامت سیستم و گزارش‌ها: everything whose job is to say what
        //     happened — health, the figures, the queue, the outbox, the trail.
        self::HEALTH => [
            'tmc-health',
            'tmc-reports',
            'tmc-jobs',
            'tmc-events',
            'tmc-audit',
        ],
        // and last, apart, and temporary.
        self::MIGRATION => [
            'tmc-import',
            'tmc-handover',
            'tmc-minmax-exit',
            // `tmc-product-intake` was here in `alpha.55` and is gone: the
            // intake is part of «بررسی محصولات» now, and the old address
            // redirects there (`ProductIntakePage`).
        ],
    ];

    public const CORE_PAGES = [
        DashboardPage::class,
        HealthPage::class,
        SettingsPage::class,
        ModulesPage::class,
    ];

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            self::DASHBOARD => __('پیشخوان', 'tecteb-marketplace-core'),
            self::SHOPS => __('فروشگاه‌ها و فروشندگان', 'tecteb-marketplace-core'),
            self::PRODUCTS => __('محصولات', 'tecteb-marketplace-core'),
            self::ORDERS => __('سفارش‌ها', 'tecteb-marketplace-core'),
            self::FINANCE => __('کمیسیون و تسویه', 'tecteb-marketplace-core'),
            self::REQUESTS => __('درخواست‌ها و اعلان‌ها', 'tecteb-marketplace-core'),
            self::SETTINGS => __('تنظیمات', 'tecteb-marketplace-core'),
            self::HEALTH => __('سلامت سیستم و گزارش‌ها', 'tecteb-marketplace-core'),
            // Named as temporary on the screen, so nobody has to remember it.
            self::MIGRATION => __('مهاجرت از دکان (موقت)', 'tecteb-marketplace-core'),
            default => __('سایر بخش‌ها', 'tecteb-marketplace-core'),
        };
    }

    /** Whether a slug belongs to the temporary migration section. */
    public static function isMigrationTool(string $slug): bool
    {
        return in_array($slug, self::GROUPS[self::MIGRATION], true);
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
