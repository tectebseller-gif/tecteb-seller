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
        string $freshInviteUrl = '',
        array $activity = []
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

        return $html . self::activityCard($activity, $fa) . self::addForm($urls, $nonceField, $limit, $used);
    }

    /**
     * What each member has actually DONE, beside what the list above says
     * they MAY do.
     *
     * The row above already carries «آخرین فعالیت» — a stamp saying somebody
     * opened a page. That answers «are they still here», not «are they doing
     * the job», and an owner deciding whether to keep giving somebody order
     * rights needs the second question answered.
     *
     * A card list rather than a table, for the reason the whole vendor area
     * is card lists: a four-column table on a 390px screen is either a
     * horizontal scroll nobody finds or a column squeezed to one character.
     *
     * @param array<string,mixed> $activity as StaffActivityReport returns it
     * @param callable(string|int):string $fa
     */
    private static function activityCard(array $activity, callable $fa): string
    {
        if (($activity['allowed'] ?? false) !== true) {
            return '';
        }
        $rows = array_values(array_filter(
            (array) ($activity['rows'] ?? []),
            static fn (array $r): bool => (int) $r['actions'] > 0
        ));
        $days = (int) ($activity['window_days'] ?? 0);

        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('فعالیت پرسنل', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html(sprintf(
                /* translators: %s is a number of days */
                __('کارهای ثبت‌شده در %s روز گذشته، از روی ردِ ممیزی. فقط کارهای همین فروشگاه شمرده می‌شود.', 'tecteb-marketplace-core'),
                $fa($days)
            )) . '</p>';

        if ($rows === []) {
            return $html . VendorUi::notice('info', __('در این بازه هیچ کار ثبت‌شده‌ای از پرسنل نیست. این یعنی کاری ثبت نشده، نه اینکه کسی وارد نشده باشد.', 'tecteb-marketplace-core'))
                . '</section>';
        }

        $html .= '<ul class="tv-staff">';
        foreach ($rows as $row) {
            $html .= '<li class="tv-staff__item"><div class="tv-staff__head">'
                . '<strong>' . esc_html((string) $row['display_name']) . '</strong> '
                . VendorUi::chip('ok', sprintf(
                    /* translators: %s is a number of recorded actions */
                    __('%s کار', 'tecteb-marketplace-core'),
                    $fa((int) $row['actions'])
                ))
                . '</div><dl class="tv-datalist">'
                . '<dt>' . esc_html__('آخرین کار', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html(self::eventLabel((string) $row['last_event'])) . '</dd>'
                . '<dt>' . esc_html__('زمان آخرین کار', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html($fa((string) $row['last_at'])) . '</dd>'
                . '</dl></li>';
        }
        return $html . '</ul></section>';
    }

    /**
     * A Persian name for the events a vendor's own staff can generate.
     *
     * Unknown keys fall through as themselves rather than as «نامشخص»: an
     * event this map has not caught up with is still a true thing that
     * happened, and hiding its name would make the newest features the
     * hardest to audit.
     */
    private static function eventLabel(string $event): string
    {
        return match ($event) {
            'product.saved' => __('ذخیرهٔ محصول', 'tecteb-marketplace-core'),
            'product.submitted' => __('ارسال محصول برای بررسی', 'tecteb-marketplace-core'),
            'product.inventory_changed' => __('تغییر موجودی', 'tecteb-marketplace-core'),
            'product.revision_requested' => __('درخواست نسخهٔ تازه', 'tecteb-marketplace-core'),
            'product.csv_imported' => __('ورود CSV', 'tecteb-marketplace-core'),
            'product.csv_exported' => __('خروجی CSV', 'tecteb-marketplace-core'),
            // Keys copied from AuditEventCatalog, not guessed: the first
            // version of this map invented `order.shipped`, which no event
            // uses, so the commonest staff action of all fell through to its
            // raw key on the page.
            'order.item_shipped' => __('ثبت ارسال', 'tecteb-marketplace-core'),
            'order.item_status_changed' => __('تغییر وضعیت سفارش', 'tecteb-marketplace-core'),
            'order.return_opened' => __('باز کردن مرجوعی', 'tecteb-marketplace-core'),
            'order.return_decided' => __('تصمیم دربارهٔ مرجوعی', 'tecteb-marketplace-core'),
            'vendor.store_updated' => __('تغییر تنظیمات فروشگاه', 'tecteb-marketplace-core'),
            'vendor.staff_invited' => __('دعوت همکار', 'tecteb-marketplace-core'),
            'vendor.staff_activated' => __('پذیرش دعوت', 'tecteb-marketplace-core'),
            default => $event,
        };
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
