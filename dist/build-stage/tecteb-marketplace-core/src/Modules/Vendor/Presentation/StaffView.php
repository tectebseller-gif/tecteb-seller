<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffMember;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * The staff list and the form that adds to it (UX §9.1).
 *
 * Nothing on this page shows a password or a secret — including the
 * invitation link, which appears exactly once, right after it is created, and
 * is never rendered again from storage because only its hash is kept.
 */
final class StaffView
{
    /** @param list<StaffMember> $members */
    public static function render(
        array $members,
        int $limit,
        VendorUrls $urls,
        string $nonceField,
        ?VendorNotice $notice = null,
        string $freshInviteUrl = ''
    ): string {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $used = count(array_filter($members, static fn (StaffMember $m): bool => $m->status !== StaffStatus::Suspended));
        $html = '';

        if ($notice !== null) {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                VendorMessages::notice($notice->code, $notice->context)
            );
        }
        if ($freshInviteUrl !== '') {
            $html .= '<div class="tv-note tv-note--action"><h2>' . esc_html__('لینک دعوت', 'tecteb-marketplace-core') . '</h2>'
                . '<p>' . esc_html__('این لینک یک‌بار مصرف است و فقط همین حالا نشان داده می‌شود. آن را برای همکارتان بفرستید؛ ارسال خودکار پیامک و ایمیل در این نسخه انجام نمی‌شود.', 'tecteb-marketplace-core') . '</p>'
                . '<p><bdi class="tv-code tv-code--block">' . esc_html($freshInviteUrl) . '</bdi></p>'
                . '<p class="tv-hint">' . esc_html__('تا هفت روز معتبر است.', 'tecteb-marketplace-core') . '</p></div>';
        }

        $html .= '<section class="tv-card"><h2 class="tv-card__title">' . esc_html__('پرسنل فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html(sprintf(
                __('%1$s نفر از سقف %2$s', 'tecteb-marketplace-core'),
                $fa($used),
                $limit > 0 ? $fa($limit) : __('نامحدود', 'tecteb-marketplace-core')
            )) . '</p>';

        if ($members === []) {
            $html .= VendorUi::notice('info', __('هنوز کسی به فروشگاه شما اضافه نشده است. با فرم پایین می‌توانید اولین همکار را دعوت کنید.', 'tecteb-marketplace-core'));
        } else {
            $html .= '<ul class="tv-staff">';
            foreach ($members as $member) {
                $html .= self::row($member, $urls, $nonceField, $fa);
            }
            $html .= '</ul>';
        }
        $html .= '</section>';

        return $html . self::addForm($urls, $nonceField, $limit, $used);
    }

    /** @param callable(string|int):string $fa */
    private static function row(StaffMember $member, VendorUrls $urls, string $nonce, callable $fa): string
    {
        $tone = match ($member->status) {
            StaffStatus::Active => 'ok',
            StaffStatus::Invited => 'info',
            StaffStatus::Suspended => 'warning',
        };
        $html = '<li class="tv-staff__item"><div class="tv-staff__head">'
            . '<strong>' . esc_html($member->displayName) . '</strong> '
            . VendorUi::chip($tone, VendorMessages::staffStatus($member->status))
            . '</div>'
            . '<dl class="tv-datalist">'
            . '<dt>' . esc_html__('نقش', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html(VendorMessages::staffPreset($member->preset)) . '</dd>'
            . '<dt>' . esc_html__('نام کاربری', 'tecteb-marketplace-core') . '</dt><dd><bdi class="tv-code">' . esc_html($member->username) . '</bdi></dd>'
            . '<dt>' . esc_html__('ایمیل', 'tecteb-marketplace-core') . '</dt><dd><bdi class="tv-code">' . esc_html($member->email) . '</bdi></dd>'
            . '<dt>' . esc_html__('موبایل', 'tecteb-marketplace-core') . '</dt><dd><bdi class="tv-code">' . esc_html($member->mobile) . '</bdi> — '
            . esc_html__('ثبت‌شده، تأییدنشده', 'tecteb-marketplace-core') . '</dd>'
            . '<dt>' . esc_html__('آخرین فعالیت', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($member->lastSeenAt !== null ? $fa($member->lastSeenAt) : __('هنوز واردنشده', 'tecteb-marketplace-core')) . '</dd>'
            . '</dl>'
            . '<div class="tv-staff__perms">' . self::permissionChips($member) . '</div>';

        $action = $member->status === StaffStatus::Suspended ? 'reinstate_staff' : 'suspend_staff';
        $label = $member->status === StaffStatus::Suspended
            ? __('بازگرداندن دسترسی', 'tecteb-marketplace-core')
            : __('تعلیق فوری', 'tecteb-marketplace-core');
        return $html . '<form method="post" action="' . esc_url($urls->staff()) . '">' . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="staff_id" value="' . esc_attr((string) $member->id) . '">'
            . '<div class="tv-actions">' . VendorUi::submit($label, 'secondary') . '</div></form></li>';
    }

    private static function permissionChips(StaffMember $member): string
    {
        $html = '';
        foreach (StaffArea::all() as $area) {
            $level = $member->permissions->level($area);
            if ($level === StaffLevel::None) {
                continue;
            }
            $html .= VendorUi::chip('info', VendorMessages::staffArea($area) . ' · ' . VendorMessages::staffLevel($level));
        }
        return $html !== '' ? $html : VendorUi::chip('warning', __('بدون دسترسی', 'tecteb-marketplace-core'));
    }

    private static function addForm(VendorUrls $urls, string $nonce, int $limit, int $used): string
    {
        if ($limit > 0 && $used >= $limit) {
            return VendorUi::notice('warning', __('به سقف پرسنل رسیده‌اید. برای افزودن همکار تازه، یکی از اعضا را تعلیق کنید یا از مدیر بازارگاه افزایش سقف را بخواهید.', 'tecteb-marketplace-core'));
        }
        $presets = [];
        foreach (StaffRolePreset::presets() as $preset) {
            $presets[$preset->value] = VendorMessages::staffPreset($preset);
        }
        return '<form class="tv-card" method="post" action="' . esc_url($urls->staff()) . '">' . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="invite_staff">'
            . '<h2 class="tv-card__title">' . esc_html__('افزودن پرسنل', 'tecteb-marketplace-core') . '</h2>'
            . VendorUi::input('first_name', __('نام', 'tecteb-marketplace-core'), '')
            . VendorUi::input('last_name', __('نام خانوادگی', 'tecteb-marketplace-core'), '')
            . VendorUi::input('username', __('نام کاربری داخلی', 'tecteb-marketplace-core'), '', true, 'text', 'ltr',
                __('حروف کوچک انگلیسی، عدد، نقطه و خط تیره. در کل بازارگاه یکتاست.', 'tecteb-marketplace-core'))
            . VendorUi::input('email', __('ایمیل', 'tecteb-marketplace-core'), '', true, 'email', 'ltr')
            . VendorUi::input('mobile', __('موبایل', 'tecteb-marketplace-core'), '', true, 'tel', 'ltr',
                __('ثبت می‌شود اما تأیید نمی‌شود؛ سرویس پیامک هنوز وصل نیست.', 'tecteb-marketplace-core'))
            . VendorUi::select('preset', __('نقش', 'tecteb-marketplace-core'), $presets, StaffRolePreset::OrderAndShipping->value,
                __('دسترسی‌ها از همین نقش می‌آید و همیشه از فروشگاه شما مشتق می‌شود؛ پرسنل هرگز مالک محصول یا سفارش نیست.', 'tecteb-marketplace-core'))
            . '<div class="tv-actions">' . VendorUi::submit(__('ساخت دعوت', 'tecteb-marketplace-core')) . '</div></form>';
    }
}
