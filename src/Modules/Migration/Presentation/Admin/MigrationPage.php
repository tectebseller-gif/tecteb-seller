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
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\DokanMigrationPlan;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
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

        $this->renderObserved($fa);
        $this->renderCompletion($service, $fa);
        $this->renderRuns($service, $fa);
        echo Components::shellClose();
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
            $risks = $transfer->risksFor($product->id);
            // Where the shop has moved SINCE the intake — `alpha.56`'s third
            // finding. Read once and used twice: the sentence a manager reads
            // and the values the tick carries come from the same answer, so a
            // page cannot let somebody acknowledge a difference the guard is
            // not checking.
            $readable = $transfer->storefrontReadable($product->id);
            $drift = $readable ? $transfer->storefrontDrift($product->id) : [];
            $form = '<form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
                . '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '">';
            foreach (array_keys($risks) as $key) {
                // The key travels with the tick, so a form that did not print
                // this risk cannot acknowledge it (`alpha.49`).
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
            $form .= '<button type="submit" class="tmc-button" name="migration_action" value="take_ownership">'
                . ($risks === [] && $drift === []
                    ? esc_html__('انتقال مالکیت عملیاتی', 'tecteb-marketplace-core')
                    : esc_html__('با وجود این موارد، مالکیت را بپذیر', 'tecteb-marketplace-core'))
                . '</button></form>';
            if ($drift !== []) {
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
                . ($risks === []
                    ? ''
                    : '<br><span class="tmc-card__note">'
                        . esc_html__('پذیرش مدیریت این فیلدها را عوض می‌کند یا نمی‌تواند نگه دارد: ', 'tecteb-marketplace-core')
                        . esc_html(implode(' ', $risks)) . '</span>')
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
                ['run_id' => $plan->runId, 'total' => (int) $summary['vendors'] + (int) $summary['products']],
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
                return 'err:' . __('این تغییر مالکیت انجام نشد.', 'tecteb-marketplace-core');
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
