<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;

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
}
