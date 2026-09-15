<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
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
            echo $this->form('import', __('ورود آزمایشی داده‌ها', 'tecteb-marketplace-core'));
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
        foreach ($observed as $product) {
            echo '<tr><td>' . Components::code((string) $product->id) . '</td>'
                . '<td>' . esc_html($product->details->title) . '</td>'
                . '<td>' . Components::code((string) ($product->wcProductId ?? 0)) . '</td>'
                . '<td><form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_migration_nonce', true, false)
                . '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '">'
                . '<button type="submit" class="tmc-button" name="migration_action" value="take_ownership">'
                . esc_html__('انتقال مالکیت عملیاتی', 'tecteb-marketplace-core') . '</button>'
                . '</form></td></tr>';
        }
        unset($fa);
        echo '</tbody></table></div></section>';
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
        if ($action === 'take_ownership' || $action === 'give_back_ownership') {
            $transfer = $this->container->get(TransferOwnership::class);
            $productId = $request->postInt('product_id');
            $result = $action === 'take_ownership'
                ? $transfer->take($productId)
                : $transfer->giveBack($productId);
            if (!$result->ok) {
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
            'no_price_recorded' => __('قیمتی ثبت نشده است', 'tecteb-marketplace-core'),
            'historic_commission_not_recomputed' => __('کمیسیون این سفارش با قواعد دکان حساب شده و دوباره ثبت نمی‌شود', 'tecteb-marketplace-core'),
            default => $reason,
        };
    }
}
