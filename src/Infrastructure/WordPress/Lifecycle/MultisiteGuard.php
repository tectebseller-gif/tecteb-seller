<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle;

/**
 * Phase 1 does not support Multisite (DEC-06-e). Activation on a multisite
 * install — network-wide or per-site — is refused with a Persian message
 * before any capability, option or table is touched.
 */
final class MultisiteGuard
{
    public static function assertAllowed(bool $networkWide): void
    {
        if (!is_multisite()) {
            return;
        }
        $reason = $networkWide
            ? __('فعال‌سازی شبکه‌ای (Network Activation) در این نسخه پشتیبانی نمی‌شود.', 'tecteb-marketplace-core')
            : __('نصب چندسایتی (Multisite) در فاز اول پشتیبانی نمی‌شود.', 'tecteb-marketplace-core');
        wp_die(
            esc_html($reason . ' ' . __('هیچ تغییری روی شبکه اعمال نشد.', 'tecteb-marketplace-core')),
            esc_html__('بازارگاه تک‌طب — پشتیبانی نشده', 'tecteb-marketplace-core'),
            ['back_link' => true, 'response' => 400]
        );
    }
}
