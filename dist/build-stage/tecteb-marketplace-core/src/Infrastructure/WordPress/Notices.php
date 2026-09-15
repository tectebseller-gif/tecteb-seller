<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Core\Support\PersianDigits;

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
     */
    public static function registerStuckStorefront(ContainerInterface $container): void
    {
        add_action('admin_notices', static function () use ($container): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            $stuck = $container->get(StorefrontSwitch::class)->stuck();
            if ($stuck['products'] === [] && $stuck['orders'] === []) {
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
            echo '<div class="notice notice-error tmc-notice"><p><strong>'
                . esc_html__('بازارگاه تک‌طب: توقف فروش کامل نشد.', 'tecteb-marketplace-core')
                . '</strong> ' . esc_html(implode(' ', $lines)) . ' '
                . esc_html__('تا بسته‌شدن این موارد، بستهٔ افزونه را عوض یا غیرفعال نکنید؛ بدون افزونه چیزی جلوی فروش یا پرداختشان را نمی‌گیرد. صفحهٔ «وضعیت فروش بازارگاه» جزئیات و دکمهٔ تلاش دوباره را دارد.', 'tecteb-marketplace-core')
                . '</p></div>';
        });
    }
}
