<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Application\DeactivationStopPolicy;
use Tecteb\Marketplace\Modules\Order\Application\UnrecordableOrder;

/** Persian admin notices for dependency and activation outcomes. */
final class Notices
{
    public static function registerWooCommerceMissing(): void
    {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-warning tmc-notice"><p>'
                . esc_html__('بازارگاه تک‌طب: افزونه WooCommerce فعال نیست. قابلیت‌های وابسته به WooCommerce راه‌اندازی نشدند؛ صفحه‌های سلامت و تنظیمات برای بررسی وضعیت در دسترس‌اند.', 'tecteb-marketplace-core')
                . '</p></div>';
        });
    }

    public static function registerActivationResult(): void
    {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            $result = get_transient(Activator::NOTICE_TRANSIENT);
            if (!is_array($result)) {
                return;
            }
            delete_transient(Activator::NOTICE_TRANSIENT);
            $migration = (string) ($result['migration'] ?? '');
            if ($migration === 'applied' || $migration === 'up_to_date') {
                $class = 'notice-success';
                $text = __('بازارگاه تک‌طب فعال شد. ساختار داده آماده است.', 'tecteb-marketplace-core');
            } elseif ($migration === 'locked') {
                $class = 'notice-warning';
                $text = __('بازارگاه تک‌طب فعال شد، اما اجرای دیگری در حال آماده‌سازی ساختار داده بود. وضعیت را در صفحه سلامت بررسی کنید.', 'tecteb-marketplace-core');
            } else {
                $class = 'notice-error';
                $text = __('بازارگاه تک‌طب فعال شد، اما آماده‌سازی ساختار داده ناموفق بود. جزئیات در صفحه سلامت است؛ فعال‌سازی دوباره تلاش را از سر می‌گیرد.', 'tecteb-marketplace-core');
            }
            echo '<div class="notice ' . esc_attr($class) . ' tmc-notice"><p>' . esc_html($text) . '</p></div>';
        });
    }

    /**
     * The one warning that must find the manager rather than wait for them.
     *
     * A stop that left a product on sale, or an unpaid order's pay link alive,
     * is dangerous precisely because the next thing that person does is
     * replace or deactivate this package — and from that moment nothing here
     * runs. The storefront screen says so too, but only to somebody who went
     * looking. This says it on whatever admin page they are on, reads two
     * options and nothing else, and disappears by itself when the next stop
     * or resume succeeds.
     *
     * ⛔ Renamed from `registerStuckStorefront()` in `alpha.102`, because it
     * now registers a SECOND, unrelated notice: the count of what a gated
     * deactivation left on sale. A name that covers one of the two things it
     * does is a name that will be trusted about the other — so the name
     * changed rather than the contract (`alpha.82`).
     */
    public static function registerStorefrontNotices(ContainerInterface $container): void
    {
        add_action('admin_notices', static function () use ($container): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            $switch = $container->get(StorefrontSwitch::class);
            $stuck = $switch->stuck();
            // ⛔ The third shape of «the stop did not finish», and the one
            // that used to be silent: products the scan never reached. They
            // are not in `stuck` — that list holds the ones it TRIED — so
            // without this line a shop with more owned products than the
            // scan's cap was told nothing at all.
            $unseen = $switch->unseen();
            if ($stuck['products'] === [] && $stuck['orders'] === [] && $unseen === 0) {
                return;
            }
            $fa = static fn (array $ids): string => PersianDigits::toPersian(
                implode('، ', array_map('strval', $ids))
            );
            $lines = [];
            if ($stuck['products'] !== []) {
                $lines[] = sprintf(
                    /* translators: %s: marketplace product ids */
                    __('محصول(های) %s هنوز روی فروشگاه قابل خریدند.', 'tecteb-marketplace-core'),
                    $fa($stuck['products'])
                );
            }
            if ($stuck['orders'] !== []) {
                $lines[] = sprintf(
                    /* translators: %s: WooCommerce order numbers */
                    __('لینک پرداخت سفارش(های) %s هنوز کار می‌کند.', 'tecteb-marketplace-core'),
                    $fa($stuck['orders'])
                );
            }
            if ($unseen > 0) {
                $lines[] = sprintf(
                    /* translators: %s: how many products were never examined */
                    __('و %s محصول دیگر اصلاً بررسی نشدند، چون پویش به سقف خودش رسید؛ شناسه‌هایشان خوانده نشده است.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $unseen)
                );
            }
            echo '<div class="notice notice-error tmc-notice"><p><strong>'
                . esc_html__('بازارگاه تک‌طب: توقف فروش کامل نشد.', 'tecteb-marketplace-core')
                . '</strong> ' . esc_html(implode(' ', $lines)) . ' '
                . esc_html__('تا بسته‌شدن این موارد، بستهٔ افزونه را عوض یا غیرفعال نکنید؛ بدون افزونه چیزی جلوی فروش یا پرداختشان را نمی‌گیرد. صفحهٔ «وضعیت فروش بازارگاه» جزئیات و دکمهٔ تلاش دوباره را دارد.', 'tecteb-marketplace-core')
                . '</p></div>';
        });

        // ⛔ AND THE PRICE OF THE §6 DEFAULT, said out loud.
        //
        // Deactivation no longer takes the shelf down unless it was told to,
        // so it counts what it left buyable instead. This is read on the next
        // admin page — which, on a reactivation, is the first thing the person
        // who turned the plugin back on sees.
        //
        // It is not an error notice: nothing failed. It is the one fact that
        // would otherwise be invisible, and it clears itself the moment
        // somebody deals with it (the storefront page's own stop deletes the
        // row, and so does reading it here once the count is zero).
        add_action('admin_notices', static function (): void {
            if (!current_user_can(Capabilities::MANAGE_STOREFRONT)) {
                return;
            }
            $left = get_option(DeactivationStopPolicy::LEFT_ON_SALE_OPTION, null);
            if (!is_int($left) && !(is_string($left) && ctype_digit($left))) {
                return;             // never written, or written by nobody we know
            }
            $left = (int) $left;
            if ($left <= 0) {
                return;
            }
            echo '<div class="notice notice-warning tmc-notice"><p><strong>'
                . esc_html__('بازارگاه تک‌طب: افزونه یک بار غیرفعال شد و محصولات روی فروش ماندند.', 'tecteb-marketplace-core')
                . '</strong> ' . esc_html(sprintf(
                    /* translators: %s: how many marketplace products stayed on sale */
                    __('%s محصول بازارگاه در آن فاصله خریدنی بودند و سهم فروشنده برایشان ثبت نمی‌شد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $left)
                )) . ' '
                . esc_html__('اگر در آن فاصله سفارشی ثبت شده، صفحهٔ «ممیزی سفارش‌های بی‌سند» آن را با نام می‌آورد. این انتخاب را صفحهٔ «وضعیت فروش بازارگاه» عوض می‌کند.', 'tecteb-marketplace-core')
                . '</p></div>';
        });

        // ⛔ AND THE ONE AN ORDER CANNOT TELL YOU ITSELF.
        //
        // `UnrecordableOrderGuard` marks an order that was created while the
        // order module was not running — the wp-admin or programmatic door,
        // the one with no earlier refusal. The mark is on the order and in the
        // audit table, both permanently; this notice is the only thing that
        // puts it in front of somebody without their going looking.
        //
        // The capability is the financial one rather than the storefront one:
        // what the reader has to do next is reconcile money, and
        // `OrderAuditPage` — the page named here — is gated on exactly this.
        add_action('admin_notices', static function (): void {
            if (!current_user_can(Capabilities::VIEW_VENDOR_FINANCE)) {
                return;
            }
            $ids = get_option(UnrecordableOrder::REGISTER_OPTION, null);
            if (!is_array($ids) || $ids === []) {
                return;
            }
            $count = count($ids);
            echo '<div class="notice notice-error tmc-notice"><p><strong>'
                . esc_html__('بازارگاه تک‌طب: سفارشی ثبت شد که سهم فروشنده‌اش نوشته نشد.', 'tecteb-marketplace-core')
                . '</strong> ' . esc_html(sprintf(
                    /* translators: %s: how many orders carry the mark */
                    __('%s سفارش در فاصله‌ای ساخته شده که ماژول سفارش اجرا نمی‌شده؛ هر کدام روی خودِ سفارش علامت دارند و در جدول ممیزی با نام آمده‌اند.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $count)
                )) . ' '
                . esc_html__('هیچ تسویه‌ای برایشان ممکن نیست، چون قلمی ثبت نشده. صفحهٔ «ممیزی سفارش‌های بی‌سند» آن‌ها را ردیف‌به‌ردیف می‌آورد؛ تصمیم مالی دربارهٔ هر کدام با مدیر است.', 'tecteb-marketplace-core')
                . '</p></div>';
        });
    }
}
