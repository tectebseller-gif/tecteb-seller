<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\DokanMigrationPlan;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCountReport;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\OwnershipTransferPlan;
use Tecteb\Marketplace\Modules\Migration\Application\ShopTransferTally;
use Tecteb\Marketplace\Modules\Product\Domain\ProjectedStatus;
use Tecteb\Marketplace\Modules\Migration\Application\ApplyRefreshSnapshot;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileStorefrontStatus;
use Tecteb\Marketplace\Modules\Migration\Application\ApplyOwnershipTransfer;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * The Dokan migration, in the order the owner asked for it: look first,
 * import second, and be able to go back.
 *
 * The page opens on the dry run and shows the mapping row by row. The import
 * button appears only when the plan has no conflicts, and every run that has
 * happened is listed with an «undo» next to it.
 *
 * What the page says about what it will not do is as important as the buttons:
 * nothing of Dokan's is written, imported products arrive as drafts, and past
 * orders are counted but not re-recorded.
 */
final class MigrationPage
{
    public const SLUG = 'tmc-import';

    /**
     * Where the staged-transfer section begins, so a link can land ON it.
     *
     * ⛔ **Why these two constants exist.** The wizard's «رفتن» for steps 6
     * to 10 pointed at `admin.php?page=tmc-import` with no shop and no
     * fragment — the SAME address steps 4 and 5 use, whose top is the Dokan
     * dry run. So a manager following step 7 landed on a different part of a
     * long page, about whichever shop the page defaults to. A link that goes
     * to the right page and the wrong place is the `alpha.85` lesson in
     * presentation: find the control you tell somebody to press.
     */
    public const ANCHOR_TRANSFER = 'tmc-staged-transfer';

    /**
     * The two batch controls of wizard step 7 — «همگام‌سازی امن دادهٔ قابل
     * اصلاح» — in one wrapper.
     *
     * It is a wrapper rather than an id on either control because BOTH of
     * them are conditional: the refresh form is drawn when something is
     * fixable on this page and the repair card when something is repairable
     * on it, and a fragment that sometimes resolves is worse than one that
     * always does. The wrapper exists whenever the section does.
     */
    public const ANCHOR_SYNC = 'tmc-sync-controls';
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;
    private const NONCE = 'tmc_dokan_migration';
    private const PLAN_TRANSIENT = 'tmc_dokan_plan';

    /**
     * Rows this page is willing to import inside the POST that asked for it.
     *
     * Not a guess: `max_execution_time` is 30 seconds on most shared hosts and
     * each product costs an insert plus a link plus an option write. Above this
     * the inline button is not rendered at all, and the queue — which checkpoints
     * after every page and resumes where it stopped — is the only path offered.
     */
    private const INLINE_LIMIT = 200;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('مهاجرت از دکان', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $service = $this->container->get(ImportFromDokan::class);
        $notice = $this->handleAction(Request::capture(), $service);
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(
                str_starts_with($notice, 'ok:') ? 'success' : 'error',
                substr($notice, strpos($notice, ':') + 1)
            );
        }
        echo Components::notice('info', __('این صفحه هیچ‌چیزِ دکان را تغییر نمی‌دهد: نه کاربر فروشنده، نه مالکیت محصول، نه جدول‌های دکان. آنچه ساخته می‌شود ردیف‌های بازارگاه است که به همان محصول ووکامرسِ موجود اشاره می‌کنند، پس شناسه و نشانی محصول دست‌نخورده می‌ماند.', 'tecteb-marketplace-core'));

        if (!$service->dokanIsPresent()) {
            echo Components::notice('warning', __('دکان روی این سایت پیدا نشد. تا نصب و فعال‌بودن دکان، چیزی برای مهاجرت خوانده نمی‌شود.', 'tecteb-marketplace-core'));
            // ⛔ The figures section is drawn here too, because it is about
            // OUR OWN rows and not about Dokan — and a site where Dokan has
            // been deactivated is exactly when somebody comes looking for
            // where a number came from.
            // ⛔ And so is the staged transfer, for the same reason: it reads
            // OUR rows and WooCommerce, never Dokan. A manager who switched
             // Dokan off before finishing the hand-over would otherwise find
            // the only screen that can move ownership gone.
            $this->renderStagedTransfer(Request::capture(), $fa);
            $this->renderCounts($fa);
            echo Components::shellClose();
            return;
        }

        $plan = $this->storedPlan();
        if ($plan !== null) {
            $this->renderPlan($plan, $fa);
        } else {
            echo '<section class="tmc-card"><h2 class="tmc-card__title">'
                . esc_html__('اجرای آزمایشی', 'tecteb-marketplace-core') . '</h2>'
                . '<p>' . esc_html__('اول یک اجرای آزمایشی بگیرید: داده‌های دکان خوانده می‌شود، تطبیق سطربه‌سطر نمایش داده می‌شود و هیچ چیزی نوشته نمی‌شود.', 'tecteb-marketplace-core') . '</p>'
                . $this->form('dry_run', __('گرفتن اجرای آزمایشی', 'tecteb-marketplace-core'))
                . '</section>';
        }

        // Before the per-product table, because this is the path for the
        // bulk of a hand-over and that one is for the exceptions.
        $this->renderStagedTransfer(Request::capture(), $fa);
        $this->renderObserved($fa);
        $this->renderCompletion($service, $fa);
        $this->renderRuns($service, $fa);
        // Last, and inside a closed `<details>`, for the reason `alpha.34`
        // measured: a disclosure at the top of a page pushes the tool
        // somebody came for down the screen when it opens. This is the
        // section a person reads once, when two figures disagree.
        $this->renderCounts($fa);
        echo Components::shellClose();
    }

    /**
     * Where each number comes from — read only, and it corrects nothing.
     *
     * ⛔ The owner read «۱۰۰٪ (۱۹۰ از ۱۸۶)» on a finished job and «۲ فروشنده
     * و ۱۷۹ محصول» in the run ledger beside a completion report saying ۱۸۴.
     * Both pairs are made of numbers that are individually true and answer
     * different questions, which is the shape that gets «fixed» by editing
     * one of them. So this section names the question and the read behind
     * each figure and changes nothing: «علت نساز و شمارنده‌ها را دستی اصلاح
     * نکن».
     */
    private function renderCounts(callable $fa): void
    {
        if (!$this->container->has(MigrationCountReport::class)) {
            return;
        }
        $report = $this->container->get(MigrationCountReport::class)->report();

        echo '<details class="tmc-card"><summary class="tmc-card__title">'
            . esc_html__('این عددها از کجا می‌آیند؟', 'tecteb-marketplace-core') . '</summary>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('دو عدد که با هم نمی‌خوانند، معمولاً جوابِ دو پرسش مختلف‌اند. این بخش می‌گوید هر رقم از کدام خواندن آمده و چه چیزی را می‌شمارد — و هیچ عددی را عوض نمی‌کند.', 'tecteb-marketplace-core')
            . '</p>';

        echo Components::dataList([
            [
                'label' => __('نگاشت‌شده در کلِ کاتالوگ (هر محصولی که مهر اجرا دارد)', 'tecteb-marketplace-core'),
                'value' => $fa((string) $report['catalogue']['mapped']),
            ],
            [
                'label' => __('از این‌ها، کامل', 'tecteb-marketplace-core'),
                'value' => $fa((string) $report['catalogue']['complete']),
            ],
            [
                'label' => __('بدون تصویر', 'tecteb-marketplace-core'),
                'value' => $fa((string) $report['catalogue']['without_image']),
            ],
            [
                'label' => __('منبعش قابل‌خواندن نبود', 'tecteb-marketplace-core'),
                'value' => $fa((string) $report['catalogue']['source_unreadable']),
            ],
            [
                'label' => __('نگاشت تکراری', 'tecteb-marketplace-core'),
                'value' => $fa((string) $report['catalogue']['duplicate_mappings']),
            ],
            [
                'label' => __('جمعِ دفترِ اجراها (آنچه خودِ اجراها ساخته‌اند)', 'tecteb-marketplace-core'),
                'value' => $fa((string) $report['manifest_total']),
            ],
            [
                'label' => __('نگاشت‌شده و در دفترِ هیچ اجرایی نیست', 'tecteb-marketplace-core'),
                'value' => $fa((string) $report['mapped_in_no_manifest']['count']),
            ],
        ]);

        if (!$report['catalogue']['finished']) {
            // A bounded read that stopped at its ceiling is not «no more»
            // (`alpha.69`), and this is the figure a reader would otherwise
            // take for the whole catalogue.
            echo Components::notice('warning', __('این اسکن به سقف خودش خورد، پس عددِ بالا «همهٔ کاتالوگ» نیست.', 'tecteb-marketplace-core'));
        }

        if ($report['mapped_in_no_manifest']['count'] > 0) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('اختلاف این دو عدد یعنی «این محصول‌ها نگاشت‌شده‌اند و هیچ اجرایی ادعایشان نمی‌کند» — نه اینکه گم شده‌اند. دو دلیلِ واقعی: محصولی که از قبل نگاشت داشت در پاس بعد «رد» می‌شود و اجرا آن را به نام خودش ثبت نمی‌کند؛ و محصولی که در تلاشی ساخته شد که پیش از checkpoint اش مرد، ردیف و نگاشت دارد و مهر اجرا ندارد. شناسه‌ها:', 'tecteb-marketplace-core')
                . ' ' . Components::code(implode('، ', array_map(
                    static fn (int $id): string => PersianDigits::toPersian((string) $id),
                    $report['mapped_in_no_manifest']['ids']
                ))) . '</p>';
        }

        if ($report['runs'] !== []) {
            echo '<table class="tmc-table"><caption>'
                . esc_html__('هر اجرا، و آنچه خودش ثبت کرده', 'tecteb-marketplace-core') . '</caption><thead><tr>'
                . '<th scope="col">' . esc_html__('اجرا', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('منبع', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('فروشنده', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($report['runs'] as $run) {
                echo '<tr><td>' . Components::code((string) $run['run_id']) . '</td>'
                    . '<td>' . esc_html(self::runSourceLabel((string) $run['source'])) . '</td>'
                    . '<td>' . esc_html($fa((string) $run['vendors'])) . '</td>'
                    . '<td>' . esc_html($fa((string) $run['products'])) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if (!$report['jobs']['read']) {
            // «I could not ask» is not «the job declared nothing».
            echo Components::notice('info', __('ردیف‌های صف خوانده نشد، پس دربارهٔ سقفِ اعلام‌شدهٔ کارها چیزی گفته نمی‌شود.', 'tecteb-marketplace-core'));
        } elseif ($report['jobs']['rows'] !== []) {
            echo '<table class="tmc-table"><caption>'
                . esc_html__('کارهای صف: سقفِ اعلام‌شده در برابر آنچه شمرده شد', 'tecteb-marketplace-core')
                . '</caption><thead><tr>'
                . '<th scope="col">' . esc_html__('شماره', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('سقفِ اعلام‌شده', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('انجام', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('رد', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('بیشتر از سقف', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($report['jobs']['rows'] as $row) {
                echo '<tr><td>' . Components::code((string) $row['id']) . '</td>'
                    . '<td>' . esc_html((string) $row['status']) . '</td>'
                    . '<td>' . esc_html($fa((string) $row['declared_total'])) . '</td>'
                    . '<td>' . esc_html($fa((string) $row['done'])) . '</td>'
                    . '<td>' . esc_html($fa((string) $row['failed'])) . '</td>'
                    . '<td>' . esc_html($fa((string) $row['over_declared'])) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="tmc-field__desc">'
                . esc_html__('«بیشتر از سقف» یعنی کار از عددی که در صف اعلام شده بود جلو زده. عددِ کم سقف است نه کار: ورود محصولات سه مرحله دارد و سقفی که اعلام شده بود دو مرحله را می‌شمرد. این ردیف‌ها سابقهٔ کاری‌اند که انجام شده و دست‌کاری نمی‌شوند.', 'tecteb-marketplace-core')
                . '</p>';
        }

        echo '</details>';
    }

    private static function runSourceLabel(string $source): string
    {
        return match ($source) {
            'manifest' => __('دفترِ خودِ اجرا', 'tecteb-marketplace-core'),
            'rows_only' => __('فقط ردیف‌ها — اجرا نیمه‌کاره مانده', 'tecteb-marketplace-core'),
            default => $source,
        };
    }

    private function renderPlan(DokanMigrationPlan $plan, callable $fa): void
    {
        $summary = $plan->summary();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html(sprintf(__('نتیجهٔ اجرای آزمایشی %s', 'tecteb-marketplace-core'), $plan->runId))
            . '</h2>';
        echo Components::notice($plan->isClean() ? 'success' : 'warning', sprintf(
            /* translators: 1: vendors, 2: products, 3: conflicts, 4: skipped */
            __('%1$s فروشنده و %2$s محصول آمادهٔ ورودند، %3$s مورد تعارض دارند و %4$s مورد رد می‌شوند. تا رفع تعارض‌ها، ورود انجام نمی‌شود.', 'tecteb-marketplace-core'),
            esc_html($fa((string) $summary['vendors'])),
            esc_html($fa((string) $summary['products'])),
            esc_html($fa((string) $summary['conflicts'])),
            esc_html($fa((string) $summary['skipped']))
        ));

        foreach ([
            __('فروشندگان', 'tecteb-marketplace-core') => $plan->vendors,
            __('محصولات', 'tecteb-marketplace-core') => $plan->products,
            __('سفارش‌های گذشته', 'tecteb-marketplace-core') => $plan->orders,
        ] as $title => $rows) {
            if ($rows === []) {
                continue;
            }
            echo '<h3 class="tmc-card__title">' . esc_html($title) . '</h3>';
            echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="' . esc_attr($title) . '">';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('در دکان', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('عنوان', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('نتیجه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('توضیح', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مقصد در بازارگاه', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td>' . Components::code((string) $row['dokan_id']) . '</td>'
                    . '<td>' . esc_html((string) $row['title']) . '</td>'
                    . '<td>' . esc_html(self::verdict((string) $row['verdict'])) . '</td>'
                    . '<td>' . esc_html(self::reason((string) $row['reason'])) . '</td>'
                    . '<td>' . Components::code((string) $row['target']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<p class="tmc-field__desc">'
            . esc_html__('محصولات واردشده «پیش‌نویس» می‌مانند و روی فروشگاه ظاهر نمی‌شوند. زنده‌کردنشان یعنی نوشتن روی پستی که دکان مدیریتش می‌کند و تصمیمِ جداگانهٔ شماست.', 'tecteb-marketplace-core')
            . '</p>';
        echo $this->form('dry_run', __('گرفتن اجرای آزمایشی تازه', 'tecteb-marketplace-core'));
        if ($plan->isClean()) {
            $rows = (int) $summary['vendors'] + (int) $summary['products'];
            if ($rows > self::INLINE_LIMIT) {
                // Above this, running inside the POST is how an import gets
                // killed by max_execution_time half way through. The button
                // that would do that is not offered — a warning next to it
                // would have been read after the timeout, not before.
                echo Components::notice('info', sprintf(
                    /* translators: 1: rows in the plan, 2: inline limit */
                    __('این نقشه %1$s ردیف دارد و بیش از %2$s ردیف در همان درخواست وارد نمی‌شود: مهلت اجرای PHP وسط کار تمام می‌شود. ورود به صف می‌رود و صفحه‌به‌صفحه اجرا می‌گردد؛ اگر نیمه‌کاره قطع شود، از همان‌جا ادامه می‌دهد.', 'tecteb-marketplace-core'),
                    esc_html($fa((string) $rows)),
                    esc_html($fa((string) self::INLINE_LIMIT))
                ));
            } else {
                echo $this->form('import', __('ورود آزمایشی داده‌ها', 'tecteb-marketplace-core'));
            }
            echo $this->form('queue_import', __('ورود آزمایشی در صف (قابل ادامه)', 'tecteb-marketplace-core'));
        }
        echo '</section>';
    }

    /**
     * The staged hand-over: one shop, one page of its observed rows, one
     * explicit confirmation per batch.
     *
     * ⛔ **Nothing here decides anything.** `OwnershipTransferPlan` has no
     * write method of any kind; the button posts to `ApplyOwnershipTransfer`,
     * which re-reads the plan and passes EMPTY acknowledgement arrays to the
     * existing guard — so a row carrying a preservation risk or a storefront
     * drift is refused by `TransferOwnership::take()` and the batch stops.
     * Those rows are listed by name and left to the per-product table below,
     * where the actual values are printed beside the tick (`alpha.49`).
     *
     * The shop and the cursor travel in the query string and nothing is
     * stored — no option, no transient, no meta (`alpha.85`).
     */
    private function renderStagedTransfer(Request $request, callable $fa): void
    {
        $completeness = $this->container->get(MigrationCompleteness::class);
        $shops = $completeness->shopVendorUserIds();
        echo '<section class="tmc-card" id="' . esc_attr(self::ANCHOR_TRANSFER) . '">'
            . '<h2 class="tmc-card__title">'
            . esc_html__('انتقال مالکیت مرحله‌ای', 'tecteb-marketplace-core') . '</h2>';
        if ($shops === []) {
            echo '<p>' . esc_html__('هیچ فروشگاه واردشده‌ای نیست.', 'tecteb-marketplace-core')
                . '</p></section>';
            return;
        }

        $shop = $request->queryInt('tv_shop');
        if (!in_array($shop, $shops, true)) {
            $shop = (int) $shops[0];
        }
        $after = max(0, $request->queryInt('tv_after'));
        $plan = $this->container->get(OwnershipTransferPlan::class)->plan($shop, $after);

        echo $this->shopPicker($shops, $shop, $fa);
        if ($plan['checked'] !== true) {
            // Not «everything is ready». A build that cannot ask whether
            // anything would be lost must not answer that nothing would.
            echo Components::notice('warning', __('بررسی ریسک و اختلاف در این نصب اجرا نشد، پس هیچ ردیفی «آماده» شمرده نمی‌شود.', 'tecteb-marketplace-core'));
        }
        // THE WHOLE SHOP FIRST, then this page.
        //
        // The three numbers used to be per page, which is how «۰ آماده» and
        // «۵۰ دارای ریسک» stood against «۱۸۴ محصول» with nothing adding up.
        echo $this->renderShopTally($shop, $fa);
        echo '<p class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: 1: ready on this page, 2: fixable drift, 3: real risks */
            __('در این صفحه: %1$s آمادهٔ انتقال، %2$s اختلاف قابل اصلاح، %3$s ریسک واقعی.', 'tecteb-marketplace-core'),
            $fa((string) ($plan['counts'][OwnershipTransferPlan::READY] ?? 0)),
            $fa((string) ($plan['counts'][OwnershipTransferPlan::DRIFT_FIXABLE] ?? 0)),
            $fa((string) ($plan['counts'][OwnershipTransferPlan::RISK_REAL] ?? 0))
        )) . '</p>';

        $this->renderStagedRows($plan, $fa);
        echo $this->stagedApplyForm($plan, $shop, $after, $fa);
        // Wizard step 7's two controls, wrapped so its «رفتن» can land here
        // rather than at the top of the page.
        echo '<div id="' . esc_attr(self::ANCHOR_SYNC) . '">'
            . $this->refreshBatchForm($plan, $shop, $after, $fa)
            . $this->statusRepairCard($shop, $after, $fa)
            . '</div>';
        echo $this->stagedPager($plan, $shop, $fa);
        echo '</section>';
    }

    /**
     * Every imported row of this shop, grouped by the reason it is waiting.
     *
     * ⛔ It says when it did NOT reach the end, and the number it prints then
     * is `scanned` rather than the total: a partial count presented as the
     * whole is the mistake this section exists to undo.
     */
    private function renderShopTally(int $shop, callable $fa): string
    {
        $tally = $this->container->get(ShopTransferTally::class)->of($shop);
        $counts = (array) $tally['counts'];
        $out = '<p>' . esc_html(sprintf(
            /* translators: 1: ready, 2: fixable, 3: real risks, 4: scanned, 5: total */
            __('کل فروشگاه: %1$s آمادهٔ انتقال، %2$s اختلاف قابل اصلاح، %3$s ریسک واقعی — از %4$s ردیفِ بررسی‌شده از %5$s ردیف.', 'tecteb-marketplace-core'),
            $fa((string) ($counts[OwnershipTransferPlan::READY] ?? 0)),
            $fa((string) ($counts[OwnershipTransferPlan::DRIFT_FIXABLE] ?? 0)),
            $fa((string) ($counts[OwnershipTransferPlan::RISK_REAL] ?? 0)),
            $fa((string) $tally['scanned']),
            $fa((string) $tally['observed_total'])
        )) . '</p>';
        if ($tally['finished'] !== true) {
            $out .= Components::notice('warning', sprintf(
                /* translators: %s: how many rows were examined */
                __('این شمارش به آخر کاتالوگ نرسید: %1$s ردیف از %2$s بررسی شد، چون سقف اعلام‌شدهٔ یک درخواست %3$s صفحه است. اعدادِ بالا دربارهٔ همین %1$s ردیف‌اند.', 'tecteb-marketplace-core'),
                $fa((string) $tally['scanned']),
                $fa((string) $tally['observed_total']),
                $fa((string) ShopTransferTally::MAX_PAGES)
            ));
        }
        $groups = (array) $tally['groups'];
        if ($groups === []) {
            return $out;
        }
        $rows = '';
        foreach ($groups as $group) {
            $group = (array) $group;
            $ids = array_map('strval', (array) ($group['ids'] ?? []));
            $more = (int) ($group['more'] ?? 0);
            $list = implode('، ', array_map(static fn (string $id): string => $fa($id), $ids));
            if ($more > 0) {
                $list .= ' ' . sprintf(
                    /* translators: %s: how many more ids are not printed */
                    __('و %s مورد دیگر', 'tecteb-marketplace-core'),
                    $fa((string) $more)
                );
            }
            $rows .= '<tr><td>' . esc_html(self::stagedBucket((string) $group['bucket'])) . '</td>'
                . '<td>' . Components::code($fa((string) $group['count'])) . '</td>'
                . '<td>' . esc_html((string) $group['why']) . '</td>'
                . '<td>' . esc_html($list) . '</td></tr>';
        }
        // ⛔ `moreDetailsBlock()`, not `moreDetails()`: that one takes an
        // array of PARAGRAPHS and `esc_html()`s each, so handing it a table
        // prints the markup as text — and PHP refuses the string outright,
        // which is how a database test caught this. And not a hand-written
        // `<details>` either: the markup lives in exactly one file, which an
        // architecture test keeps true. Every value below is escaped here,
        // because that builder escapes nothing.
        return $out . Components::moreDetailsBlock(
            '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول وضعیت کل فروشگاه', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تعداد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('دلیل', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('ردیف‌های بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>',
            __('وضعیت هر ردیف این فروشگاه، گروه‌شده', 'tecteb-marketplace-core')
        );
    }

    /**
     * The second button: re-read these rows from their shop, writing nothing
     * to any WooCommerce product.
     *
     * Its own form, its own digest and its own action name, because it is a
     * different act from the transfer and sharing either would let one
     * confirmation carry the other.
     *
     * @param array<string,mixed> $plan
     */
    private function refreshBatchForm(array $plan, int $shop, int $after, callable $fa): string
    {
        $ids = array_map('intval', (array) ($plan['refreshable'] ?? []));
        if ($ids === []) {
            return '';
        }
        $form = '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
            . '<input type="hidden" name="tv_shop" value="' . esc_attr((string) $shop) . '">'
            . '<input type="hidden" name="tv_after" value="' . esc_attr((string) $after) . '">'
            . '<input type="hidden" name="refresh_digest" value="'
            . esc_attr((string) $plan['refresh_digest']) . '">';
        foreach ($ids as $productId) {
            $form .= '<input type="hidden" name="refresh[]" value="' . esc_attr((string) $productId) . '">';
        }
        $form .= '<p class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: %s: how many rows this click re-reads */
            __('این دکمه اطلاعات %s ردیف را از خودِ فروشگاه بازمی‌خواند، هیچ‌چیزی روی محصول ووکامرس نمی‌نویسد، و سرِ اولین خطا می‌ایستد.', 'tecteb-marketplace-core'),
            $fa((string) count($ids))
        )) . '</p>';
        return $form . '<button type="submit" class="button" name="migration_action"'
            . ' value="apply_refresh_batch">'
            . esc_html__('بازخوانی اطلاعات این دسته', 'tecteb-marketplace-core')
            . '</button></form>';
    }

    /**
     * The status repair — this plugin's own column, against the storefront.
     *
     * ⛔ **Why this card exists at all.** On a site whose products were
     * imported by a package older than `alpha.92`, every imported row carries
     * `draft` while the product is live: the old intake wrote
     * `ProductStatus::Draft` unconditionally, migration 38 filled the STAMP
     * and deliberately left the status column alone, and nothing since brings
     * the two together — `refreshFrom()` rewrites thirteen fields and not
     * this one, and refuses a row the transfer has already moved anyway. The
     * consequences are in `StorefrontStatusReconciliation`'s docblock and the
     * middle one is not cosmetic: a `draft` row is vendor-editable, so a
     * sensitive change to a LIVE product never reaches the review queue.
     *
     * It is drawn only when there is something to repair, so a healthy site
     * never sees a migration-era control it has no use for.
     */
    private function statusRepairCard(int $shop, int $after, callable $fa): string
    {
        $plan = $this->container->get(ReconcileStorefrontStatus::class)->plan($shop, $after);
        $ids = array_map('intval', (array) $plan['repairable']);
        // ⛔ THIS CARD PAGES ITSELF, and that is not a nicety.
        //
        // `stagedPager()` below is built from the OWNERSHIP plan, which walks
        // `observedForVendorAfter()` — only the rows the transfer has not
        // moved yet. This card walks STAMPED rows, and the stamp SURVIVES the
        // transfer (`setLinkOwnership()` does not touch it). So on a shop
        // whose transfer is already finished the ownership plan returns no
        // rows, its `finished` is true and its `cursor` is nought — and that
        // pager prints NOTHING, while this card still has rows on the pages
        // after this one.
        //
        // That is the shape staging reports: 184 rows already transferred,
        // fifty scanned to a page, and no control to reach the rest. A cursor
        // that is computed and never read is the defect `alpha.85` took
        // apart, and the shop this card was written for is precisely the case
        // that reaches it. So the cursor read here is THIS plan's own.
        $pager = $this->statusRepairPager($plan, $shop);
        if ($ids === []) {
            // Nothing to repair HERE is not nothing to repair anywhere. Say
            // which of the two it is, and keep the way forward on the page.
            if ($pager === '') {
                return '';
            }
            return '<h3>' . esc_html__('اصلاح وضعیت انتشار در بازارگاه', 'tecteb-marketplace-core') . '</h3>'
                . '<p>' . esc_html__('در این صفحه ردیفی برای اصلاح نیست — ممکن است در صفحه‌های بعد باشد.', 'tecteb-marketplace-core') . '</p>'
                . $pager;
        }
        $out = '<h3>' . esc_html__('اصلاح وضعیت انتشار در بازارگاه', 'tecteb-marketplace-core') . '</h3>';
        $out .= '<p>' . esc_html(sprintf(
            /* translators: %s: how many rows disagree with the shop */
            __('در این صفحه، وضعیتِ ثبت‌شدهٔ %s ردیف با وضعیت واقعی همان محصول در ووکامرس یکی نیست. مرجع، ووکامرس است.', 'tecteb-marketplace-core'),
            $fa((string) count($ids))
        )) . '</p>';
        $out .= '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول اصلاح وضعیت', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('ردیف بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت در بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت در ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('پس از اصلاح', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ((array) $plan['rows'] as $row) {
            $row = (array) $row;
            // Only the rows this press would move are listed. The others are
            // counted in the shop tally above, so nothing is hidden by the
            // narrowing — and a table of twenty-five rows nobody can act on
            // is the kind of screen `alpha.85` took apart.
            if (!in_array((int) $row['product_id'], $ids, true)) {
                continue;
            }
            $out .= '<tr><td>' . esc_html($fa((string) $row['product_id'])) . '</td>'
                . '<td>' . esc_html(ProductMessages::displayTitle(
                    (string) $row['title'],
                    (int) $row['product_id']
                )) . '</td>'
                . '<td>' . esc_html(self::statusName((string) $row['row_status'])) . '</td>'
                . '<td>' . esc_html(ProductMessages::shopStatus((string) $row['shop_status'])) . '</td>'
                . '<td>' . esc_html(self::statusName((string) $row['target'])) . '</td>'
                . '</tr>';
        }
        $out .= '</tbody></table></div>';
        $out .= Components::moreDetails(
            [
                __('تنها ستون وضعیتِ خودِ بازارگاه نوشته می‌شود. قیمت، موجودی، SKU، دسته‌بندی، تصویر، فروشنده، محدودیت خرید و مهر وضعیت دست‌نخورده می‌مانند، و هیچ‌چیزی روی محصول ووکامرس نوشته نمی‌شود.', 'tecteb-marketplace-core'),
                __('فقط ردیف‌هایی اصلاح می‌شوند که مهر وضعیت دارند — یعنی همگام‌سازی هرگز وضعیتشان را ننوشته، پس مقدار فعلی تصمیم هیچ‌کس نیست. ردیفی که مدیر دربارهٔ آن تصمیم گرفته باشد مهر ندارد و اصلاح نمی‌شود.', 'tecteb-marketplace-core'),
                __('ردیفی که در انتظار بررسی یا تعلیق‌شده است دست نمی‌خورد، و وضعیتی که این بازارگاه نمی‌تواند بیان کند با نام رد می‌شود.', 'tecteb-marketplace-core'),
                __('این کارت دربارهٔ همین صفحه است و هر فشار تا ۲۵ ردیف. برای ردیف‌های بعدی، پیوند «ادامهٔ اصلاح وضعیت از اینجا» را در پایین همین کارت بزنید؛ تا وقتی آن پیوند چاپ می‌شود، صفحهٔ دیگری مانده است.', 'tecteb-marketplace-core'),
            ],
            __('این اصلاح چه می‌کند و چه نمی‌کند', 'tecteb-marketplace-core')
        );
        $form = '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
            . '<input type="hidden" name="tv_shop" value="' . esc_attr((string) $shop) . '">'
            . '<input type="hidden" name="tv_after" value="' . esc_attr((string) $after) . '">'
            . '<input type="hidden" name="status_digest" value="'
            . esc_attr((string) $plan['digest']) . '">';
        foreach ($ids as $productId) {
            $form .= '<input type="hidden" name="status_fix[]" value="' . esc_attr((string) $productId) . '">';
        }
        $form .= '<p class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: %s: how many rows this click repairs */
            __('این دکمه وضعیتِ ثبت‌شدهٔ %s ردیف را با ووکامرس هماهنگ می‌کند و سرِ اولین خطا می‌ایستد.', 'tecteb-marketplace-core'),
            $fa((string) count($ids))
        )) . '</p>';
        return $out . $form . '<button type="submit" class="button" name="migration_action"'
            . ' value="apply_status_repair">'
            . esc_html__('اصلاح وضعیت این دسته', 'tecteb-marketplace-core')
            . '</button></form>' . $pager;
    }

    /**
     * The way to the next page of stamped rows, from THIS plan's own cursor.
     *
     * Printed only when it would go somewhere — a cursor that did not move
     * links back to the page you are on, which is how `alpha.85` described a
     * pager that looks like progress and is not. `finished` here means the
     * scan saw fewer rows than its limit, so there is no page after it.
     *
     * @param array<string,mixed> $plan
     */
    private function statusRepairPager(array $plan, int $shop): string
    {
        $cursor = (int) $plan['cursor'];
        if ($plan['finished'] === true || $cursor <= 0) {
            return '';
        }
        return '<p><a class="button" href="' . esc_url(add_query_arg(
            ['page' => self::SLUG, 'tv_shop' => $shop, 'tv_after' => $cursor],
            admin_url('admin.php')
        )) . '">' . esc_html__('ادامهٔ اصلاح وضعیت از اینجا', 'tecteb-marketplace-core') . '</a></p>';
    }

    /**
     * The Persian name of a stored status value.
     *
     * `ProductMessages::status()` takes the enum, and the plan carries the
     * stored string — so the mapping happens once, here, rather than every
     * row doing its own `tryFrom` and printing a raw `draft` when it fails.
     */
    private static function statusName(string $value): string
    {
        $status = ProductStatus::tryFrom(trim($value));
        return $status === null ? trim($value) : ProductMessages::status($status);
    }

    /** @param list<int> $shops */
    private function shopPicker(array $shops, int $current, callable $fa): string
    {
        $out = '<form method="get" class="tmc-inline-form">'
            . '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">'
            . '<label class="tmc-field__label" for="tv-shop">'
            . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</label>'
            . '<select id="tv-shop" name="tv_shop">';
        foreach ($shops as $shopId) {
            $out .= '<option value="' . esc_attr((string) $shopId) . '"'
                . ((int) $shopId === $current ? ' selected' : '') . '>'
                . esc_html('#' . $fa((string) $shopId)) . '</option>';
        }
        return $out . '</select>'
            . '<button type="submit" class="button">'
            . esc_html__('نمایش', 'tecteb-marketplace-core') . '</button></form>';
    }

    /** @param array<string,mixed> $plan */
    private function renderStagedRows(array $plan, callable $fa): void
    {
        $rows = (array) $plan['rows'];
        if ($rows === []) {
            echo '<p>' . esc_html__('در این صفحه ردیفی نیست.', 'tecteb-marketplace-core') . '</p>';
            return;
        }
        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول انتقال مرحله‌ای', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('ردیف بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('عنوان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('محصول ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $row = (array) $row;
            echo '<tr><td>' . Components::code($fa((string) $row['product_id'])) . '</td>'
                . '<td>' . esc_html((string) $row['title']) . '</td>'
                . '<td>' . Components::code($fa((string) $row['wc_product_id'])) . '</td>'
                . '<td>' . esc_html(self::stagedBucket((string) $row['bucket'])) . self::stagedWhy($row) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * WHY a row is not ready, from the row's own answer.
     *
     * @param array<string,mixed> $row
     */
    private static function stagedWhy(array $row): string
    {
        // ⛔ The row's OWN sentence, built by `OwnershipTransferPlan::
        // classify()` — not rebuilt here from the keys. The owner's §4 asks
        // that «دلیل توقف هر محصول به فارسی و واضح مشخص باشد», and a page
        // that composed its own wording from the same keys would be a second
        // answer to the question the planner already answered (`alpha.78`):
        // the whole-shop table and this column would drift apart the first
        // time either was edited.
        $why = trim((string) ($row['why'] ?? ''));
        $disclosed = array_map('strval', (array) ($row['disclosed'] ?? []));
        $out = $why === '' ? '' : '<span class="tmc-field__desc">' . esc_html($why) . '</span>';
        if ($disclosed !== []) {
            // Reported, never a blocker: these are the fields no projection
            // writes, so they are said out loud beside a row that may well be
            // «آمادهٔ انتقال».
            $out .= '<span class="tmc-field__desc">' . esc_html(sprintf(
                /* translators: %s: comma-separated field keys */
                __('اطلاع‌رسانی (همگام‌سازی این‌ها را نمی‌نویسد): %s', 'tecteb-marketplace-core'),
                implode('، ', $disclosed)
            )) . '</span>';
        }
        return $out;
    }

    private static function stagedBucket(string $bucket): string
    {
        return match ($bucket) {
            OwnershipTransferPlan::READY => __('آمادهٔ انتقال', 'tecteb-marketplace-core'),
            OwnershipTransferPlan::DRIFT_FIXABLE => __('اختلاف قابل اصلاح', 'tecteb-marketplace-core'),
            OwnershipTransferPlan::STATUS_BLOCKED => __('وضعیت انتشار تغییر می‌کند', 'tecteb-marketplace-core'),
            OwnershipTransferPlan::RISK_REAL => __('ریسک واقعی', 'tecteb-marketplace-core'),
            OwnershipTransferPlan::UNREADABLE => __('محصول ووکامرس خوانده نشد', 'tecteb-marketplace-core'),
            OwnershipTransferPlan::NO_STOREFRONT_PRODUCT => __('محصول ووکامرسی ندارد', 'tecteb-marketplace-core'),
            default => $bucket,
        };
    }

    /**
     * The one button, carrying the exact ids and their fingerprint.
     *
     * @param array<string,mixed> $plan
     */
    private function stagedApplyForm(array $plan, int $shop, int $after, callable $fa): string
    {
        $ready = array_map('intval', (array) $plan['ready']);
        if ($ready === []) {
            return '<p class="tmc-field__desc">'
                . esc_html__('در این صفحه هیچ ردیفی آمادهٔ انتقال گروهی نیست.', 'tecteb-marketplace-core')
                . '</p>';
        }
        $form = '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
            . '<input type="hidden" name="tv_shop" value="' . esc_attr((string) $shop) . '">'
            . '<input type="hidden" name="tv_after" value="' . esc_attr((string) $after) . '">'
            // The fingerprint of the ORDERED list this page printed. If the
            // catalogue has moved by the time the form arrives, the digest
            // differs and the whole batch is refused (`alpha.49`).
            . '<input type="hidden" name="plan_digest" value="'
            . esc_attr((string) $plan['digest']) . '">';
        foreach ($ready as $productId) {
            $form .= '<input type="hidden" name="batch[]" value="' . esc_attr((string) $productId) . '">';
        }
        $count = count($ready);
        $total = (int) ($plan['counts'][OwnershipTransferPlan::READY] ?? 0);
        $form .= '<p class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: 1: how many this click moves, 2: how many are ready on this page */
            __('این دکمه مالکیت %1$s محصول از %2$s محصول آمادهٔ این صفحه را منتقل می‌کند و سرِ اولین خطا می‌ایستد.', 'tecteb-marketplace-core'),
            $fa((string) $count),
            $fa((string) $total)
        )) . '</p>';
        return $form . '<button type="submit" class="tmc-button" name="migration_action"'
            . ' value="apply_ownership_batch">'
            . esc_html__('انتقال مالکیت این دسته', 'tecteb-marketplace-core')
            . '</button></form>';
    }

    /** @param array<string,mixed> $plan */
    private function stagedPager(array $plan, int $shop, callable $fa): string
    {
        $out = '';
        $cursor = (int) $plan['cursor'];
        // ⛔ Printed only when it would GO somewhere: a cursor that did not
        // move would link back to this page (`alpha.85`).
        if ($plan['finished'] !== true && $cursor > 0) {
            $out .= '<a class="button" href="' . esc_url(add_query_arg(
                ['page' => self::SLUG, 'tv_shop' => $shop, 'tv_after' => $cursor],
                admin_url('admin.php')
            )) . '">' . esc_html__('ادامه از اینجا', 'tecteb-marketplace-core') . '</a> ';
        }
        if ((int) $plan['cursor'] > 0 || $plan['finished'] !== true) {
            $out .= '<a class="button" href="' . esc_url(add_query_arg(
                ['page' => self::SLUG, 'tv_shop' => $shop],
                admin_url('admin.php')
            )) . '">' . esc_html__('بازگشت به ابتدا', 'tecteb-marketplace-core') . '</a>';
        }
        unset($fa);
        return $out === '' ? '' : '<p>' . $out . '</p>';
    }

    /**
     * The mapped products, and the one button that changes what they mean.
     *
     * Deliberately loud. Everything else on this page is reversible bookkeeping;
     * this is the act that makes the marketplace start deciding whether another
     * plugin's product may be sold.
     */
    private function renderObserved(callable $fa): void
    {
        $observed = $this->container->get(ProductRepositoryInterface::class)->observed();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('محصولات نگاشت‌شده (هنوز مال بازارگاه نیستند)', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('این ردیف‌ها فقط می‌دانند کدام محصول ووکامرس متناظرشان است. بازارگاه دربارهٔ خرید، توقف فروش یا انتشار این محصولات هیچ تصمیمی نمی‌گیرد و فروشندهٔ فعلی‌شان مثل قبل می‌فروشد.', 'tecteb-marketplace-core')
            . '</p>';
        if ($observed === []) {
            echo '<p>' . esc_html__('هیچ محصول نگاشت‌شده‌ای وجود ندارد.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo Components::notice('warning', __('«انتقال مالکیت عملیاتی» یعنی از آن لحظه بازارگاه تصمیم می‌گیرد این محصول فروخته شود یا نه: توقف فروش آن را به پیش‌نویس می‌برد و گارد خرید دربارهٔ آن نظر می‌دهد. محصول ووکامرس با این کار بازنویسی نمی‌شود و همین دکمه در جهت عکس هم کار می‌کند.', 'tecteb-marketplace-core'));
        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول محصولات نگاشت‌شده', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('ردیف بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('عنوان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('محصول ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        $transfer = $this->container->get(TransferOwnership::class);
        foreach ($observed as $product) {
            // What accepting THIS product would overwrite or cannot express,
            // asked of the same method the guard asks. One answer, so the
            // warning a manager reads and the keys the refusal demands cannot
            // drift apart.
            // ⛔ ONE read of the WooCommerce product for all three answers.
            // This used to be three calls — `risksFor()`,
            // `storefrontReadable()` and `storefrontDrift()` — each of which
            // reads the product, and `detail()` is deliberately not memoised.
            // On 184 observed rows that was 552 product loads in one admin
            // request before a button was drawn. Still ONE answer used twice,
            // so the sentence a manager reads and the values the tick carries
            // cannot differ.
            $state = $transfer->acceptanceState($product->id);
            $risks = $state['risks'];
            // The two halves, split by the service rather than here, so the
            // keys this form ticks are the keys the guard demands.
            $blocking = array_map('strval', (array) $state['blocking']);
            $disclosed = array_map('strval', (array) $state['disclosed']);
            $readable = (bool) $state['readable'];
            $drift = $state['drift'];
            $statusSettled = ProjectedStatus::settled((string) $state['status']);
            $form = '<form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
                . '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '">';
            foreach ($blocking as $key) {
                // The key travels with the tick, so a form that did not print
                // this risk cannot acknowledge it (`alpha.49`).
                //
                // ⛔ And only the BLOCKING half. A tick for something the
                // guard never asks about is a tick that teaches people to
                // tick — and a disclosure is reported, never accepted.
                $form .= '<input type="hidden" name="acknowledged[]" value="' . esc_attr((string) $key) . '">';
            }
            foreach ($drift as $field => $pair) {
                // And the VALUE travels with it, not just the field name: a
                // permission is about the number that was shown, never about
                // whatever is there when the form arrives (`alpha.49`, one
                // object further out).
                $form .= '<input type="hidden" name="seen_shop[' . esc_attr((string) $field) . ']"'
                    . ' value="' . esc_attr((string) $pair['fingerprint']) . '">';
            }
            // ⛔ No button at all while the publish state would move. The
            // refusal has no acknowledgement key, so a button here would be
            // one whose failure a manager could not predict (`alpha.78`), and
            // the way forward is the re-snapshot below.
            $form .= $statusSettled
                ? '<button type="submit" class="tmc-button" name="migration_action" value="take_ownership">'
                    . ($blocking === [] && $drift === []
                        ? esc_html__('انتقال مالکیت عملیاتی', 'tecteb-marketplace-core')
                        : esc_html__('با وجود این موارد، مالکیت را بپذیر', 'tecteb-marketplace-core'))
                    . '</button>'
                : '';
            $form .= '</form>';
            if ($drift !== [] || !$statusSettled) {
                // The other way out, and the one that loses nothing: take the
                // snapshot again and there is no difference left to accept.
                $form .= '<form method="post" class="tmc-inline-form">'
                    . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
                    . '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '">'
                    . '<button type="submit" class="tmc-button tmc-button--quiet"'
                    . ' name="migration_action" value="refresh_snapshot">'
                    . esc_html__('مبنا را از فروشگاه تازه کن', 'tecteb-marketplace-core')
                    . '</button></form>';
            }
            echo '<tr><td>' . Components::code((string) $product->id) . '</td>'
                . '<td>' . esc_html($product->details->title)
                . ($blocking === []
                    ? ''
                    : '<br><span class="tmc-card__note">'
                        . esc_html__('پذیرش مدیریت این فیلدها را عوض می‌کند یا نمی‌تواند نگه دارد: ', 'tecteb-marketplace-core')
                        . esc_html(implode(' ', array_intersect_key($risks, array_flip($blocking))))
                        . '</span>')
                // ⛔ A SECOND sentence, not the same one. These fields are
                // never written by a projection — counted, in
                // `PreservationRisk` — so saying they «would change» was the
                // sentence that put fifty of fifty rows into «needs a
                // decision» on shop 102. They are reported and recorded, and
                // nothing is ticked for them.
                . ($disclosed === []
                    ? ''
                    : '<br><span class="tmc-card__note">'
                        . esc_html__('این موارد با پذیرش مدیریت عوض نمی‌شوند و فقط اطلاع‌رسانی‌اند: ', 'tecteb-marketplace-core')
                        . esc_html(implode(' ', array_intersect_key($risks, array_flip($disclosed))))
                        . '</span>')
                . ($statusSettled
                    ? ''
                    : '<br><span class="tmc-card__note">'
                        . esc_html(sprintf(
                            /* translators: 1: the shop's status, 2: what a projection would write */
                            __('وضعیت انتشار: اولین همگام‌سازی «%1$s» را به «%2$s» تغییر می‌دهد، پس انتقال انجام نمی‌شود. با «مبنا را از فروشگاه تازه کن» وضعیت فعلی ثبت می‌شود و این مانع برداشته می‌شود.', 'tecteb-marketplace-core'),
                            (string) $state['shop_status'] === '' ? '—' : (string) $state['shop_status'],
                            (string) $state['would_write']
                        )) . '</span>')
                . (!$readable
                    ? '<br><span class="tmc-card__note">'
                        . esc_html__('مقدارهای فعلی این محصول در ووکامرس خوانده نشد، پس معلوم نیست از زمان ورود عوض شده‌اند یا نه. تا خوانده نشود پذیرش انجام نمی‌شود.', 'tecteb-marketplace-core')
                        . '</span>'
                    : '')
                . ($drift === []
                    ? ''
                    : '<br><span class="tmc-card__note">'
                        . esc_html__('از زمان ورود، این مقدارها در ووکامرس عوض شده‌اند (مبنای ورود ← مقدار فعلی فروشگاه): ', 'tecteb-marketplace-core')
                        . esc_html(implode('، ', array_map(
                            static fn (array $pair): string => sprintf(
                                '%s: %s ← %s',
                                (string) $pair['label'],
                                (string) $pair['row'] === '' ? '—' : (string) $pair['row'],
                                (string) $pair['shop'] === '' ? '—' : (string) $pair['shop']
                            ),
                            $drift
                        ))) . '</span>')
                . '</td>'
                . '<td>' . Components::code((string) ($product->wcProductId ?? 0)) . '</td>'
                . '<td>' . $form . '</td></tr>';
        }
        unset($fa);
        echo '</tbody></table></div></section>';
    }

    /**
     * How complete the imports really are — four numbers, not one percentage.
     *
     * ⛔ «عدد ۱۰۰٪ به‌تنهایی کافی نیست», and this round is why: until now a
     * job could report every unit done while a product sat in the catalogue
     * with no picture, because the row was written before the picture and only
     * the row was counted. The figures are derived from the rows and the shop
     * each time this page is drawn, so there is nothing stored to fall out of
     * date.
     *
     * Scanned across EVERY import, not one run, because a half-made product
     * left by an earlier package belongs to a job that finished long ago and
     * no batch will ever look at it again.
     */
    private function renderCompletion(ImportFromDokan $service, callable $fa): void
    {
        $report = $service->importCompletion();
        if ($report['mapped'] === 0) {
            return;
        }
        $rows = [
            __('محصول واردشده', 'tecteb-marketplace-core') => $report['mapped'],
            __('کامل', 'tecteb-marketplace-core') => $report['complete'],
            __('بدون تصویر (نیمه‌تمام)', 'tecteb-marketplace-core') => $report['without_image'],
            __('قابل‌بررسی نبود', 'tecteb-marketplace-core') => $report['source_unreadable'],
            __('نگاشت تکراری', 'tecteb-marketplace-core') => $report['duplicate_mappings'],
        ];
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('کامل‌بودن ورود', 'tecteb-marketplace-core') . '</h2>'
            . '<table class="tmc-table"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html((string) $label) . '</th>'
                . '<td>' . esc_html($fa((string) $value)) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (!$report['finished']) {
            echo '<p class="tmc-card__note">'
                . esc_html__('این شمارش به سقف خودش رسید، پس «همه را دیدم» نمی‌گوید.', 'tecteb-marketplace-core')
                . '</p>';
        }
        if ($report['incomplete'] !== []) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('شناسهٔ محصول‌های نیمه‌تمام: ', 'tecteb-marketplace-core')
                . esc_html($fa(implode('، ', array_map('strval', $report['incomplete']))))
                . '</p>'
                . '<form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
                . '<button type="submit" class="tmc-button" name="migration_action" value="repair_imports">'
                . esc_html__('تکمیل محصول‌های نیمه‌تمام', 'tecteb-marketplace-core') . '</button></form>'
                . '<p class="tmc-field__desc">'
                . esc_html__('فقط همان نیمهٔ انجام‌نشده را تلاش می‌کند: تصویری که روی خودِ محصول ووکامرس هست کپی و به محصول بازارگاه وصل می‌شود. هیچ ردیفی ساخته یا بازنویسی نمی‌شود و هیچ رسانه‌ای حذف نمی‌شود.', 'tecteb-marketplace-core')
                . '</p>';
        }
        if ($report['duplicates'] !== []) {
            echo '<p class="tmc-card__note">'
                . esc_html__('شناسهٔ محصول ووکامرس که بیش از یک ردیف به آن اشاره می‌کند: ', 'tecteb-marketplace-core')
                . esc_html($fa(implode('، ', array_map('strval', $report['duplicates']))))
                . esc_html__(' — این وضعیت تصمیم یک انسان است و این صفحه هیچ ردیفی را ادغام یا حذف نمی‌کند.', 'tecteb-marketplace-core')
                . '</p>';
        }
        echo '</section>';
    }

    private function renderRuns(ImportFromDokan $service, callable $fa): void
    {
        $runs = $service->runs();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('اجراهای انجام‌شده', 'tecteb-marketplace-core') . '</h2>';
        if ($runs === []) {
            echo '<p>' . esc_html__('هنوز هیچ ورودی انجام نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('شناسهٔ اجرا', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('زمان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('ساخته‌شده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('بازگشت', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($runs as $runId => $run) {
            echo '<tr><td>' . Components::code((string) $runId) . '</td>'
                . '<td>' . esc_html($fa((string) ($run['at'] ?? ''))) . '</td>'
                . '<td>' . esc_html(sprintf(
                    /* translators: 1: vendors, 2: products */
                    __('%1$s فروشنده، %2$s محصول', 'tecteb-marketplace-core'),
                    $fa((string) count($run['vendors'] ?? [])),
                    $fa((string) count($run['products'] ?? []))
                )) . '</td>'
                . '<td><form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
                . '<input type="hidden" name="run_id" value="' . esc_attr((string) $runId) . '">'
                . '<button type="submit" class="tmc-button" name="migration_action" value="rollback">'
                . esc_html__('بازگرداندن این اجرا', 'tecteb-marketplace-core') . '</button>'
                . '</form></td></tr>';
        }
        echo '</tbody></table>'
            . '<p class="tmc-field__desc">'
            . esc_html__('بازگرداندن، دقیقاً همان ردیف‌هایی را که این اجرا ساخته بود حذف می‌کند و به هیچ‌چیز دیگری دست نمی‌زند. محصولی که منتشر شده یا سفارشی به آن خورده، حذف نمی‌شود و همان‌جا گزارش می‌شود.', 'tecteb-marketplace-core')
            . '</p></section>';
    }

    private function form(string $action, string $label): string
    {
        return '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
            . '<button type="submit" class="tmc-button" name="migration_action" value="' . esc_attr($action) . '">'
            . esc_html($label) . '</button></form>';
    }

    /** Why a batch was refused whole, with nothing written. */
    private static function batchRefusal(string $code): string
    {
        return match ($code) {
            'forbidden' => __('دسترسی لازم برای انتقال مالکیت را ندارید.', 'tecteb-marketplace-core'),
            'nothing_selected' => __('هیچ محصولی در این درخواست نبود، پس چیزی منتقل نشد.', 'tecteb-marketplace-core'),
            'plan_moved' => __('فهرست این صفحه از زمان نمایش عوض شده است، پس هیچ‌چیز منتقل نشد. صفحه را تازه کنید.', 'tecteb-marketplace-core'),
            'selection_not_offered' => __('این درخواست محصولی داشت که این صفحه پیشنهاد نکرده بود، پس کل دسته رد شد و چیزی منتقل نشد.', 'tecteb-marketplace-core'),
            default => __('این دسته انجام نشد و چیزی منتقل نشد.', 'tecteb-marketplace-core'),
        };
    }

    private function handleAction(Request $request, ImportFromDokan $service): string
    {
        if (!$request->isPost() || !$request->hasPost('migration_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_migration_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $action = $request->postKey('migration_action');
        if ($action === 'dry_run') {
            $plan = $service->plan();
            // Kept for this admin session only: a plan is a photograph of the
            // two sites at one moment, and acting on yesterday's photograph is
            // how a migration imports something that has since changed.
            set_transient(self::PLAN_TRANSIENT . '_' . get_current_user_id(), $plan, 30 * MINUTE_IN_SECONDS);
            return 'ok:' . __('اجرای آزمایشی گرفته شد. هیچ‌چیزی نوشته نشد.', 'tecteb-marketplace-core');
        }
        if ($action === 'import') {
            $plan = $this->storedPlan();
            if ($plan === null) {
                return 'err:' . __('اول یک اجرای آزمایشی بگیرید؛ ورود بدون تطبیق انجام نمی‌شود.', 'tecteb-marketplace-core');
            }
            $result = $service->import($plan);
            delete_transient(self::PLAN_TRANSIENT . '_' . get_current_user_id());
            return $result->ok
                ? 'ok:' . sprintf(
                    /* translators: 1: vendors, 2: products */
                    __('ورود آزمایشی انجام شد: %1$s فروشنده و %2$s محصول (پیش‌نویس) ساخته شد. هیچ‌چیز دکان تغییر نکرد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $result->context['vendors']),
                    PersianDigits::toPersian((string) $result->context['products'])
                )
                : 'err:' . __('ورود انجام نشد. اگر تعارضی هست، اول آن را رفع کنید.', 'tecteb-marketplace-core');
        }
        if ($action === 'queue_import') {
            $plan = $this->storedPlan();
            if ($plan === null) {
                return 'err:' . __('اول یک اجرای آزمایشی بگیرید؛ ورود بدون تطبیق انجام نمی‌شود.', 'tecteb-marketplace-core');
            }
            if (!$plan->isClean()) {
                return 'err:' . __('ورود انجام نشد. اگر تعارضی هست، اول آن را رفع کنید.', 'tecteb-marketplace-core');
            }
            $summary = $plan->summary();
            /** @var JobRepositoryInterface $jobs */
            $jobs = $this->container->get(JobRepositoryInterface::class);
            $queued = $jobs->enqueue(
                DokanImportJob::TYPE,
                [
                    'run_id' => $plan->runId,
                    // ⛔ All THREE phases, because all three count towards
                    // `done_count`. Until this round it declared vendors plus
                    // products while the handler also walked the order
                    // history, so the figure on the queue page went past its
                    // own denominator — the owner read «۱۰۰٪ (۱۹۰ از ۱۸۶)» on
                    // a job that had done exactly what it was asked. The
                    // arithmetic was right and the ceiling was short.
                    //
                    // `summary()` has carried the `orders` key since the
                    // history phase was added; nothing was missing except
                    // this line reading it.
                    'total' => (int) $summary['vendors']
                        + (int) $summary['products']
                        + (int) ($summary['orders'] ?? 0),
                ],
                // One LIVE import at a time, whatever the run id. Two managers
                // pressing this at once must not start two walks over the same
                // Dokan rows; the unique index decides, not a check.
                DokanImportJob::TYPE,
                get_current_user_id()
            );
            if ($queued['id'] === 0) {
                return 'err:' . __('ورود به صف اضافه نشد. صفحهٔ سلامت صف را ببینید.', 'tecteb-marketplace-core');
            }
            delete_transient(self::PLAN_TRANSIENT . '_' . get_current_user_id());
            if (!$queued['created']) {
                return 'err:' . sprintf(
                    /* translators: %s: job id */
                    __('یک ورود در صف هست (کار شمارهٔ %s) و تا پایانش ورود تازه‌ای شروع نمی‌شود.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $queued['id'])
                );
            }
            // The manager has just pressed a button; they should not be told to
            // wait for the five-minute tick.
            WpJobScheduler::nudge();
            return 'ok:' . sprintf(
                /* translators: %s: job id */
                __('ورود به صف رفت (کار شمارهٔ %s). پیشرفتش در «صف و سلامت اجرا» دیده می‌شود و قطع‌شدن این صفحه کاری با آن ندارد.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $queued['id'])
            );
        }

        if ($action === 'take_ownership' || $action === 'give_back_ownership') {
            $transfer = $this->container->get(TransferOwnership::class);
            $productId = $request->postInt('product_id');
            $result = $action === 'take_ownership'
                ? $transfer->take(
                    $productId,
                    $request->postTextList('acknowledged'),
                    $request->postMap('seen_shop')
                )
                : $transfer->giveBack($productId);
            if (!$result->ok) {
                if ($result->code === 'ownership_risks_unacknowledged') {
                    return 'err:' . sprintf(
                        /* translators: %s: the sentences for the fields at risk */
                        __('پذیرش انجام نشد، چون این موارد تأیید نشده بودند: %s صفحه را تازه کنید و دکمهٔ «با وجود این موارد» را بزنید.', 'tecteb-marketplace-core'),
                        implode(' ', array_map('strval', (array) ($result->context['sentences'] ?? [])))
                    );
                }
                // Each refusal says which one it is, because they send the
                // manager to three different places: refresh the page, take
                // the snapshot again, or find out why WooCommerce cannot be
                // read.
                if ($result->code === 'storefront_moved') {
                    return 'err:' . sprintf(
                        /* translators: %s: the names of the fields that moved */
                        __('پذیرش انجام نشد: از زمان ورود، این مقدارها در ووکامرس عوض شده‌اند و این فرم آن‌ها را حمل نمی‌کرد: %s. صفحه را تازه کنید تا مقدارهای فعلی را ببینید.', 'tecteb-marketplace-core'),
                        (string) ($result->context['fields'] ?? '')
                    );
                }
                if ($result->code === 'storefront_moved_again') {
                    return 'err:' . sprintf(
                        /* translators: 1: field, 2: the value the shop holds now */
                        __('پذیرش انجام نشد: «%1$s» بین نمایش صفحه و زدن دکمه عوض شد — مقدار فعلی فروشگاه %2$s است. آن مقدار بازنویسی نشد؛ صفحه را تازه کنید.', 'tecteb-marketplace-core'),
                        (string) ($result->context['field'] ?? ''),
                        PersianDigits::toPersian((string) ($result->context['now'] ?? ''))
                    );
                }
                if ($result->code === 'storefront_unreadable') {
                    return 'err:' . __('پذیرش انجام نشد: مقدارهای فعلی این محصول در ووکامرس خوانده نشد، پس معلوم نیست مبنای ورود کهنه است یا نه.', 'tecteb-marketplace-core');
                }
                // Two halves that disagree is not «nothing changed», and it
                // sends the manager somewhere else: `storage_failed` means the
                // row refused and the stamp went back, so pressing the button
                // again is the whole answer.
                if ($result->code === 'ownership_stamp_unrestored') {
                    return 'err:' . sprintf(
                        /* translators: %s: WooCommerce product id */
                        __('انجام نشد و مهر محصول ووکامرس %s سر جایش برنگشت: ردیف و مهر با هم نمی‌خوانند. همین دکمه را یک بار دیگر بزنید؛ اگر باز هم شد، این متن را بفرستید.', 'tecteb-marketplace-core'),
                        PersianDigits::toPersian((string) ($result->context['wc_product_id'] ?? 0))
                    );
                }
                return 'err:' . __('این تغییر مالکیت انجام نشد. هیچ نیمه‌ای از آن هم انجام نشد؛ همین دکمه را دوباره بزنید.', 'tecteb-marketplace-core');
            }
            return 'ok:' . ($action === 'take_ownership'
                ? sprintf(
                    /* translators: %s: WooCommerce product id */
                    __('مالکیت عملیاتی محصول ووکامرس %s به بازارگاه منتقل شد. از این پس توقف فروش و گارد خرید دربارهٔ آن تصمیم می‌گیرند.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $result->context['wc_product_id'])
                )
                : sprintf(
                    /* translators: %s: WooCommerce product id */
                    __('مالکیت عملیاتی محصول ووکامرس %s پس گرفته شد و دوباره فقط نگاشت است.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $result->context['wc_product_id'])
                ));
        }

        if ($action === 'apply_ownership_batch') {
            $result = $this->container->get(ApplyOwnershipTransfer::class)->apply(
                $request->postInt('tv_shop'),
                $request->postInt('tv_after'),
                $request->postIdList('batch'),
                $request->postKey('plan_digest')
            );
            $moved = count((array) $result['moved']);
            if ($result['ok'] === true) {
                return 'ok:' . sprintf(
                    /* translators: %s: how many products moved */
                    __('مالکیت %s محصول منتقل شد. هر محصول ردیف ممیزی خودش را دارد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $moved)
                );
            }
            // ⛔ `stopped` is the one refusal that says something CHANGED, so
            // it names both halves: how many moved and which product stopped
            // it. «۱۱ از ۲۵» with no id is a sentence nobody can act on.
            if ($result['code'] === 'stopped') {
                return 'err:' . sprintf(
                    /* translators: 1: moved count, 2: product row id, 3: refusal code */
                    __('دسته سرِ محصول %2$s ایستاد (%3$s). تا آن لحظه مالکیت %1$s محصول منتقل شده بود و همان‌ها منتقل مانده‌اند؛ بقیه دست‌نخورده‌اند.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $moved),
                    PersianDigits::toPersian((string) $result['stopped_at']),
                    (string) $result['reason']
                );
            }
            return 'err:' . self::batchRefusal((string) $result['code']);
        }

        if ($action === 'apply_refresh_batch') {
            $result = $this->container->get(ApplyRefreshSnapshot::class)->apply(
                $request->postInt('tv_shop'),
                $request->postInt('tv_after'),
                $request->postIdList('refresh'),
                $request->postKey('refresh_digest')
            );
            $done = count((array) $result['refreshed']);
            if ($result['ok'] === true) {
                return 'ok:' . sprintf(
                    /* translators: %s: how many rows were re-read */
                    __('اطلاعات %s ردیف از فروشگاه بازخوانی شد. هیچ‌چیزی روی محصول ووکامرس نوشته نشد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $done)
                );
            }
            // Both halves, for the same reason the transfer batch gives them:
            // a batch that did part of its work has changed the marketplace's
            // own copy of a catalogue, and «۱۱ از ۲۵» with no id is a sentence
            // nobody can act on.
            if ($result['code'] === 'stopped') {
                return 'err:' . sprintf(
                    /* translators: 1: refreshed count, 2: product row id, 3: refusal code */
                    __('بازخوانی سرِ محصول %2$s ایستاد (%3$s). تا آن لحظه %1$s ردیف بازخوانی شده بود و همان‌ها تازه مانده‌اند؛ بقیه دست‌نخورده‌اند.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $done),
                    PersianDigits::toPersian((string) $result['stopped_at']),
                    (string) $result['reason']
                );
            }
            return 'err:' . self::batchRefusal((string) $result['code']);
        }

        if ($action === 'apply_status_repair') {
            $result = $this->container->get(ReconcileStorefrontStatus::class)->apply(
                $request->postInt('tv_shop'),
                $request->postInt('tv_after'),
                $request->postIdList('status_fix'),
                $request->postKey('status_digest')
            );
            $done = count((array) $result['repaired']);
            if ($result['ok'] === true) {
                return 'ok:' . sprintf(
                    /* translators: %s: how many rows were brought into line */
                    __('وضعیتِ ثبت‌شدهٔ %s ردیف با ووکامرس هماهنگ شد. هیچ‌چیزی روی محصول ووکامرس نوشته نشد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $done)
                );
            }
            // Both halves, for the reason the two batches above give them: a
            // run that did part of its work has changed which save path those
            // vendors' forms take, and a count with no id cannot be acted on.
            if ($result['code'] === 'stopped') {
                return 'err:' . sprintf(
                    /* translators: 1: repaired count, 2: product row id, 3: refusal code */
                    __('اصلاح سرِ محصول %2$s ایستاد (%3$s). تا آن لحظه وضعیت %1$s ردیف هماهنگ شده بود و همان‌ها هماهنگ مانده‌اند؛ بقیه دست‌نخورده‌اند.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $done),
                    PersianDigits::toPersian((string) $result['stopped_at']),
                    (string) $result['reason']
                );
            }
            return 'err:' . self::batchRefusal((string) $result['code']);
        }

        if ($action === 'refresh_snapshot') {
            $result = $this->container->get(TransferOwnership::class)
                ->refreshFromStorefront($request->postInt('product_id'));
            if ($result->ok) {
                return 'ok:' . sprintf(
                    /* translators: %s: how many fields were brought up to date */
                    __('مبنای توافق از مقدارهای فعلی ووکامرس تازه شد (%s فیلد). محصول ووکامرس عوض نشد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) ($result->context['refreshed'] ?? 0))
                );
            }
            return 'err:' . sprintf(
                /* translators: %s: the refusal's code */
                __('تازه‌کردن مبنا انجام نشد: %s', 'tecteb-marketplace-core'),
                $result->code
            );
        }

        if ($action === 'repair_imports') {
            $result = $service->repairImports($request->postText('run_id'));
            if (!$result->ok) {
                return 'err:' . sprintf(
                    /* translators: %s: the refusal's code */
                    __('تکمیل انجام نشد: %s', 'tecteb-marketplace-core'),
                    $result->code
                );
            }
            $refused = (string) ($result->context['refused'] ?? '');
            return 'ok:' . sprintf(
                /* translators: 1: repaired, 2: still incomplete */
                __('%1$s محصول تکمیل شد؛ %2$s محصول همچنان نیمه‌تمام است.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($result->context['repaired'] ?? 0)),
                PersianDigits::toPersian((string) ($result->context['still_incomplete'] ?? 0))
            ) . ($refused === '' ? '' : ' ' . sprintf(
                /* translators: %s: ids and reasons */
                __('و اینها نشد: %s', 'tecteb-marketplace-core'),
                $refused
            ));
        }
        if ($action === 'rollback') {
            $result = $service->rollback($request->postText('run_id'));
            if ($result->ok) {
                return 'ok:' . sprintf(
                    /* translators: 1: vendors, 2: products */
                    __('این اجرا برگردانده شد: %1$s فروشنده و %2$s محصول حذف شدند.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $result->context['vendors']),
                    PersianDigits::toPersian((string) $result->context['products'])
                );
            }
            if ($result->code === 'rollback_not_removed') {
                return 'err:' . sprintf(
                    /* translators: 1: ids and parts, 2: removed products */
                    __('بازگرداندن نیمه‌کاره ماند: ذخیره‌سازی این محصول(ها) را نپذیرفت — %1$s. %2$s محصول برداشته شد و بقیه سر جایشان هستند؛ همین اقدام را دوباره بزنید.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $result->context['failed']),
                    PersianDigits::toPersian((string) $result->context['products'])
                );
            }
            if ($result->code === 'rollback_kept_transferred') {
                return 'err:' . sprintf(
                    /* translators: 1: kept product ids, 2: removed products */
                    __('محصول(های) %1$s حذف نشدند چون مالکیت عملیاتی‌شان منتقل شده و دیگر یک کپی آزمایشی نیستند؛ %2$s محصول دیگر برداشته شد. اگر می‌خواهید کاملاً برگردند، اول مالکیتشان را پس بگیرید.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $result->context['kept']),
                    PersianDigits::toPersian((string) $result->context['products'])
                );
            }
            return 'err:' . __('بازگرداندن انجام نشد.', 'tecteb-marketplace-core');
        }
        return 'err:' . __('این اقدام از این صفحه انجام نمی‌شود.', 'tecteb-marketplace-core');
    }

    private function storedPlan(): ?DokanMigrationPlan
    {
        $stored = get_transient(self::PLAN_TRANSIENT . '_' . get_current_user_id());
        return $stored instanceof DokanMigrationPlan ? $stored : null;
    }

    private static function verdict(string $verdict): string
    {
        return match ($verdict) {
            DokanMigrationPlan::IMPORT => __('وارد می‌شود', 'tecteb-marketplace-core'),
            DokanMigrationPlan::SKIP => __('رد می‌شود', 'tecteb-marketplace-core'),
            default => __('تعارض', 'tecteb-marketplace-core'),
        };
    }

    private static function reason(string $reason): string
    {
        return match ($reason) {
            '' => '—',
            'already_a_marketplace_vendor' => __('از قبل فروشندهٔ بازارگاه است', 'tecteb-marketplace-core'),
            'already_owned_by_the_marketplace' => __('از قبل مال بازارگاه است', 'tecteb-marketplace-core'),
            'already_mapped_by_an_earlier_run' => __('اجرای قبلی نگاشتش کرده است', 'tecteb-marketplace-core'),
            'sku_already_used_in_this_shop' => __('این SKU در همین فروشگاه استفاده شده؛ کدام اصل است؟', 'tecteb-marketplace-core'),
            // `no_price_recorded` is kept as a NAME and is no longer produced:
            // a stored plan or a log line written before `alpha.66` still
            // reads as what it was rather than as an unknown string. From
            // `alpha.66` a product with no price is IMPORTED and this sentence
            // is the one that explains what the manager still has to do.
            'no_price_recorded' => __('قیمتی ثبت نشده است', 'tecteb-marketplace-core'),
            'imported_without_a_price' => __('وارد می‌شود، ولی قیمت ندارد — تا تعیین قیمت، تأیید و انتشارش رد می‌شود و قیمت فروشگاه هم دست‌نخورده می‌ماند', 'tecteb-marketplace-core'),
            'price_not_exact_for_this_currency' => __('قیمت این محصول رقمی دارد که واحد پول فروشگاه نمی‌تواند نگه دارد؛ گرد نمی‌شود', 'tecteb-marketplace-core'),
            'price_is_negative' => __('قیمت منفی است', 'tecteb-marketplace-core'),
            'status_not_expressible' => __('وضعیت انتشار این محصول در بازارگاه معادلی ندارد (مثل «خصوصی» یا «زمان‌بندی‌شده»)', 'tecteb-marketplace-core'),
            'historic_commission_not_recomputed' => __('کمیسیون این سفارش با قواعد دکان حساب شده و دوباره ثبت نمی‌شود', 'tecteb-marketplace-core'),
            default => $reason,
        };
    }
}
