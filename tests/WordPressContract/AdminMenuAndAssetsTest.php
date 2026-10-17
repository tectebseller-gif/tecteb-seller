<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Admin\Presentation\AssetLoader;
use TmcWpStubs\State;

/** CORE-03/05: four capability-gated pages; assets only on the plugin's own screens. */
final class AdminMenuAndAssetsTest extends ContractTestCase
{
    /** A menu label with the red count stripped, if WordPress drew one. */
    private static function withoutBubble(string $title): string
    {
        return trim((string) preg_replace('~<span class="update-plugins.*$~s', '', $title));
    }

    public function testMenuHasExactlyTheCapabilityGatedPagesOfTheLoadedModules(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        $top = array_values(array_filter(State::$menus, static fn ($m) => $m['parent'] === null));
        self::assertCount(1, $top);
        // THE NAME, with any bubble stripped. The red count belongs to
        // `MenuBadgeRefreshTest`; this test is about which pages exist and who
        // may open them. The stub `wpdb` of this suite is a real PDO on the
        // same disposable database as the database suite (the `alpha.36`
        // rule), so a product another suite left behind can legitimately put a
        // number on this menu — and a test about capabilities failing for that
        // reason is a broken measurement, not a finding.
        self::assertSame('بازارگاه تک‌طب', self::withoutBubble((string) $top[0]['menuTitle']));
        self::assertSame('tmc_view_dashboard', $top[0]['capability']);
        self::assertSame('tmc-dashboard', $top[0]['slug']);

        $subs = array_values(array_filter(State::$menus, static fn ($m) => $m['parent'] === 'tmc-dashboard'));
        // **The order is the decision, so it is written out.** From
        // `alpha.36` the sidebar follows `AdminNavigation::GROUPS`: the five
        // screens somebody opens every day, then sales and money, then content
        // and reports, then settings and tools, then setup and migration. Until
        // `alpha.35` it was the order the modules happened to register in, so
        // «راه‌اندازی» sat third and «بررسی محصولات» thirteenth.
        //
        // Every capability below is unchanged by that reordering, and that is
        // half of what this list is for: moving a page must not quietly widen
        // or narrow who can open it.
        $expected = [
            // روزمره
            ['tmc-dashboard', 'tmc_view_dashboard', 'پیشخوان'],
            ['tmc-product-review', 'tmc_review_products', 'بررسی محصولات'],
            ['tmc-vendor-applications', 'tmc_review_vendor', 'درخواست‌های فروشندگان'],
            // `alpha.62`: «انتخاب فروشگاه» — a manager's read-only view of one
            // shop, behind its OWN capability rather than the review one, so
            // the list of people who may read inside a shop can be shorter
            // than the list who may approve its products (§4).
            ['tmc-vendor-shops', 'tmc_view_vendor_shops', 'فروشگاه‌ها (مشاهده)'],
            ['tmc-vendor-documents', 'tmc_manage_vendor_documents', 'مدارک فروشندگان'],
            // The phase-7 module: a shop's own codes are not here (they are on
            // the vendor's own page); what a MANAGER decides is.
            ['tmc-tickets', 'tmc_review_vendor', 'تیکت فروشندگان'],
            // فروش و مالی
            ['tmc-storefront', 'tmc_manage_storefront', 'وضعیت فروش بازارگاه'],
            ['tmc-commission-rules', 'tmc_manage_settings', 'قواعد کمیسیون'],
            ['tmc-withdrawals', 'tmc_review_withdrawals', 'تسویه و برداشت'],
            // `alpha.57`: a RECORD of money already paid to a supplier, on the
            // same capability and deliberately NOT inside the settlement queue
            // (§4). Listed here because this test is the one place that says
            // which pages exist and who may open them.
            ['tmc-supplier-payments', 'tmc_review_withdrawals', 'پرداخت به تأمین‌کننده'],
            // Registered by the finance module although it is an order screen:
            // OrderModule is self-gated, and an open return has to stay
            // decidable on a day the order gate is shut (F-15).
            ['tmc-returns', 'tmc_review_withdrawals', 'مرجوعی و بازپرداخت'],
            ['tmc-wholesale', 'tmc_review_vendor', 'خریداران عمده'],
            // محتوا و گزارش
            ['tmc-spec-templates', 'tmc_manage_spec_templates', 'الگوهای مشخصات'],
            // Moderation, with its OWN capability: approving what the public
            // reads about a shop is not the same permission as approving the
            // shop, so it is not `tmc_review_vendor`.
            ['tmc-reviews', 'tmc_moderate_reviews', 'نظرات'],
            // «مالی و عملیاتی» (UX §12.3) plus the manager's OWN inbox. There
            // is no everybody's-notifications screen anywhere, on purpose.
            ['tmc-reports', 'tmc_review_vendor', 'گزارش‌ها'],
            // تنظیمات و ابزارها
            ['tmc-settings', 'tmc_manage_settings', 'تنظیمات'],
            ['tmc-modules', 'tmc_view_modules', 'ماژول‌ها'],
            ['tmc-health', 'tmc_view_health', 'سلامت'],
            // Operations: three of the four pages carry their OWN capability
            // rather than borrowing another's. Reading the audit trail is not
            // implied by being able to approve a vendor, and issuing an API
            // contract is not implied by being able to run a job.
            ['tmc-jobs', 'tmc_manage_jobs', 'صف و سلامت اجرا'],
            ['tmc-events', 'tmc_manage_api', 'رویدادها و API'],
            ['tmc-audit', 'tmc_view_audit', 'ممیزی'],
            // راه‌اندازی و مهاجرت
            ['tmc-setup', 'tmc_manage_settings', 'راه‌اندازی'],
            // The slug carries no other plugin's name — the assertion below
            // enforces that, so «مهاجرت از دکان» is the label, not the id.
            ['tmc-import', 'tmc_review_vendor', 'مهاجرت از دکان'],
            // Importing and handing over are two screens on purpose: the first
            // is reversible bookkeeping, the second is a person deciding who
            // may touch orders and who owes money.
            ['tmc-handover', 'tmc_review_vendor', 'تحویل مهاجرت'],
            ['tmc-minmax-exit', 'tmc_review_vendor', 'خروج از Min Max'],
            // There is NO «ورود محصولات موجود» entry. It had one in
            // `alpha.55`; from `alpha.56` the intake is part of «بررسی
            // محصولات», because two lists of one catalogue showed a product
            // that existed on both sides twice with nothing saying it was one
            // product. The old address still resolves — it redirects — and
            // that is a different test.
        ];
        // And the definition agrees with the list above. Without this, a group
        // edited in `AdminNavigation` and not reflected here would be caught
        // only by the slug-by-slug loop failing with an off-by-one message
        // about the wrong page.
        self::assertSame(
            array_column($expected, 0),
            \Tecteb\Marketplace\Modules\Admin\Presentation\AdminNavigation::order(),
            'the navigation groups and the expected sidebar order are one decision'
        );
        self::assertCount(count($expected), $subs);
        foreach ($expected as $i => [$slug, $cap, $label]) {
            self::assertSame($slug, $subs[$i]['slug']);
            self::assertSame($cap, $subs[$i]['capability']);
            self::assertSame($label, self::withoutBubble((string) $subs[$i]['menuTitle']));
        }
        foreach (State::$menus as $m) {
            self::assertStringNotContainsString('dokan', strtolower($m['slug']));
            self::assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $m['menuTitle'], 'menu labels are Persian');
        }
    }

    public function testAssetsLoadOnlyOnPluginScreensAndNeverFromCdn(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        foreach (['edit.php', 'index.php', 'plugins.php', 'toplevel_page_woocommerce', ''] as $hook) {
            do_action('admin_enqueue_scripts', $hook);
            self::assertSame([], State::$enqueued['style'], "no style on {$hook}");
            self::assertSame([], State::$enqueued['script'], "no script on {$hook}");
        }
        foreach (['toplevel_page_tmc-dashboard', 'tecteb-marketplace_page_tmc-health', 'tecteb-marketplace_page_tmc-settings', 'tecteb-marketplace_page_tmc-modules'] as $hook) {
            State::$enqueued = ['style' => [], 'script' => []];
            do_action('admin_enqueue_scripts', $hook);
            self::assertArrayHasKey(AssetLoader::STYLE_HANDLE, State::$enqueued['style'], $hook);
            self::assertArrayHasKey(AssetLoader::SCRIPT_HANDLE, State::$enqueued['script'], $hook);
            $style = State::$enqueued['style'][AssetLoader::STYLE_HANDLE];
            $script = State::$enqueued['script'][AssetLoader::SCRIPT_HANDLE];
            self::assertStringStartsWith(State::$homeUrl . '/wp-content/plugins/', $style['src']);
            self::assertStringEndsWith('assets/admin/tmc-admin.css', $style['src']);
            self::assertStringEndsWith('assets/admin/tmc-admin.js', $script['src']);
            self::assertSame(self::VERSION, $style['ver']);
            self::assertSame([], $script['deps'], 'no jQuery, no external deps');
            self::assertTrue($script['args']['in_footer']);
        }
    }

    public function testAssetFilesExistAndContainNoRemoteUrls(): void
    {
        $root = dirname(__DIR__, 2);
        $css = file_get_contents($root . '/assets/admin/tmc-admin.css');
        $js = file_get_contents($root . '/assets/admin/tmc-admin.js');
        self::assertStringNotContainsString('http://', $css);
        self::assertStringNotContainsString('https://', $css);
        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('http', $js);
        self::assertStringContainsString('.tmc-admin', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('--tmc-touch: 44px', $css);
        self::assertStringNotContainsString('window.tmc', $js, 'no globals');
    }
}
