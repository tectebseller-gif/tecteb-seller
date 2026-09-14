<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * The page a new colleague lands on. They are NOT signed in here — the token
 * is the only thing vouching for them — so the page shows no store data and
 * no other person's name, only what is needed to choose a password.
 */
final class InviteView
{
    public static function render(string $token, string $displayName, string $username, string $nonceField, ?VendorNotice $notice = null, int $minPassword = 10): string
    {
        $html = '';
        if ($notice !== null) {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                VendorMessages::notice($notice->code, $notice->context)
            );
        }
        if ($token === '') {
            return $html . VendorUi::notice('warning', __('این لینک معتبر نیست یا منقضی شده است. از فروشنده‌ای که شما را دعوت کرده لینک تازه بخواهید.', 'tecteb-marketplace-core'));
        }

        return $html . '<form class="tv-card" method="post">' . $nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="accept_invite">'
            . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
            . '<h2 class="tv-card__title">' . esc_html__('فعال‌سازی حساب پرسنل', 'tecteb-marketplace-core') . '</h2>'
            . '<p>' . esc_html(sprintf(__('%s عزیز، برای فعال‌سازی حسابتان یک رمز عبور انتخاب کنید.', 'tecteb-marketplace-core'), $displayName)) . '</p>'
            . VendorUi::input('username_display', __('نام کاربری شما', 'tecteb-marketplace-core'), $username, false, 'text', 'ltr')
            . VendorUi::input('password', __('رمز عبور تازه', 'tecteb-marketplace-core'), '', true, 'password', 'ltr',
                sprintf(__('دست‌کم %s نویسه.', 'tecteb-marketplace-core'), \Tecteb\Marketplace\Core\Support\PersianDigits::toPersian((string) $minPassword)))
            . '<div class="tv-actions">' . VendorUi::submit(__('فعال‌سازی حساب', 'tecteb-marketplace-core')) . '</div></form>';
    }
}
