<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequest;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequestStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

/**
 * Store settings, five tabs (UX §11).
 *
 * Two of the tabs cannot simply save. The shop's name and its bank account
 * are the manager's call, so those forms open a request and then show its
 * state — and the banking tab says, in the tab itself, that the OTP step the
 * spec asks for is not performed in this build. A screen that quietly skipped
 * it would be claiming an identity check nobody ran.
 */
final class StoreView
{
    /**
     * @param array<string,string> $carriers manager's list, slug => label
     * @param array<string,string> $networks manager's list, slug => label
     * @param array{iban:string, holder:string, document_id:int, status:string, on_hold:bool} $bank
     * @param list<ChangeRequest> $requests this vendor's own history
     */
    public static function render(
        StoreSettings $settings,
        string $tab,
        VendorUrls $urls,
        string $nonceField,
        array $carriers,
        array $networks,
        array $bank,
        array $requests,
        bool $otpAvailable,
        ?VendorNotice $notice = null
    ): string {
        $html = '';
        if ($notice !== null) {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                VendorMessages::notice($notice->code, $notice->context)
            );
        }
        $html .= VendorUi::tabs(self::tabs(), $tab, $urls->store());

        $html .= match ($tab) {
            'shipping' => self::shipping($settings, $urls, $nonceField, $carriers),
            'closure' => self::closure($settings, $urls, $nonceField),
            'social' => self::social($settings, $urls, $nonceField, $networks),
            'bank' => self::bank($bank, $urls, $nonceField, $otpAvailable, self::pending($requests, ChangeRequest::FIELD_BANK)),
            default => self::general($settings, $urls, $nonceField, self::pending($requests, ChangeRequest::FIELD_STORE_NAME)),
        };

        return $html . self::history($requests);
    }

    /** @return array<string,string> */
    public static function tabs(): array
    {
        return [
            'general' => __('عمومی', 'tecteb-marketplace-core'),
            'shipping' => __('ارسال', 'tecteb-marketplace-core'),
            'closure' => __('تعطیلی', 'tecteb-marketplace-core'),
            'social' => __('اجتماعی', 'tecteb-marketplace-core'),
            'bank' => __('بانکی', 'tecteb-marketplace-core'),
        ];
    }

    /**
     * Pick a picture, and see the one that is already there.
     *
     * Both fields were number inputs until `alpha.25`, with a hint promising
     * a picker «in the products stage». A vendor has no wp-admin, so there was
     * no way for them to learn an attachment id — the field was unusable by
     * the only people it was for. The hidden id is kept so that saving
     * without choosing a file changes nothing.
     */
    private static function imageField(string $name, string $label, int $currentId): string
    {
        $url = $currentId > 0 ? wp_get_attachment_image_url($currentId, 'medium') : false;
        $id = 'f-' . $name . '-file';
        $html = '<div class="tv-field tv-imgpick">'
            . '<label class="tv-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        if (is_string($url) && $url !== '') {
            $html .= '<img class="tv-imgpick__preview" src="' . esc_url($url) . '" alt="'
                . esc_attr(sprintf(
                    /* translators: %s: logo or banner */
                    __('%s فعلی', 'tecteb-marketplace-core'),
                    $label
                )) . '" loading="lazy" decoding="async">';
        } else {
            $html .= '<p class="tv-hint">' . esc_html__('هنوز تصویری انتخاب نشده است.', 'tecteb-marketplace-core') . '</p>';
        }
        return $html
            . '<input class="tv-input" type="file" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '_file"'
            . ' accept="image/png,image/jpeg,image/webp">'
            . '<input type="hidden" name="' . esc_attr($name) . '_id" value="' . esc_attr((string) $currentId) . '">'
            . '<p class="tv-hint">' . esc_html__('اگر فایلی انتخاب نکنید، تصویر فعلی دست‌نخورده می‌ماند.', 'tecteb-marketplace-core') . '</p>'
            . '</div>';
    }

    private static function general(StoreSettings $s, VendorUrls $urls, string $nonce, ?ChangeRequest $pending): string
    {
        $html = self::formOpen($urls, $nonce, 'save_store', 'general')
            . '<h2 class="tv-card__title">' . esc_html__('اطلاعات عمومی فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . VendorUi::input('city', __('شهر', 'tecteb-marketplace-core'), $s->city)
            . VendorUi::textarea('intro', __('معرفی فروشگاه', 'tecteb-marketplace-core'), $s->intro,
                true, __('این متن در صفحه عمومی فروشگاه دیده می‌شود.', 'tecteb-marketplace-core'))
            . self::imageField('logo', __('لوگوی فروشگاه', 'tecteb-marketplace-core'), $s->logoId)
            . self::imageField('banner', __('بنر فروشگاه', 'tecteb-marketplace-core'), $s->bannerId)
            . '<div class="tv-actions">' . VendorUi::submit(__('ذخیره', 'tecteb-marketplace-core'), 'secondary') . '</div></form>';

        $html .= '<section class="tv-card"><h2 class="tv-card__title">' . esc_html__('نام فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html__('نام فروشگاه پس از تأیید مدیر تغییر می‌کند، چون خریداران فروشنده را با همین نام می‌شناسند.', 'tecteb-marketplace-core') . '</p>'
            . '<p><strong>' . esc_html($s->storeName !== '' ? $s->storeName : __('هنوز تعیین نشده', 'tecteb-marketplace-core')) . '</strong></p>';
        if ($pending !== null) {
            $html .= VendorUi::notice('info', sprintf(
                __('درخواست تغییر نام به «%s» ثبت شده و در انتظار تأیید مدیر است.', 'tecteb-marketplace-core'),
                $pending->requestedValue
            ));
        } else {
            $html .= self::formOpen($urls, $nonce, 'request_rename', 'general', false)
                . VendorUi::input('store_name', __('نام تازه', 'tecteb-marketplace-core'), '')
                . '<div class="tv-actions">' . VendorUi::submit(__('درخواست تغییر نام', 'tecteb-marketplace-core')) . '</div></form>';
        }
        return $html . '</section>';
    }

    /** @param array<string,string> $carriers */
    private static function shipping(StoreSettings $s, VendorUrls $urls, string $nonce, array $carriers): string
    {
        $html = self::formOpen($urls, $nonce, 'save_store', 'shipping')
            . '<h2 class="tv-card__title">' . esc_html__('ارسال', 'tecteb-marketplace-core') . '</h2>'
            . VendorUi::input('preparation_days', __('زمان آماده‌سازی (روز)', 'tecteb-marketplace-core'), (string) $s->preparationDays, true, 'number', 'ltr')
            . VendorUi::input('origin_warehouse', __('انبار مبدأ', 'tecteb-marketplace-core'), $s->originWarehouse);

        if ($carriers === []) {
            $html .= VendorUi::notice('info', __('مدیر بازارگاه هنوز فهرست شرکت‌های حمل را تعیین نکرده است؛ تا آن زمان انتخابی در این بخش وجود ندارد. زمان آماده‌سازی و انبار مبدأ ذخیره می‌شوند.', 'tecteb-marketplace-core'));
        } else {
            $html .= '<fieldset class="tv-field"><legend class="tv-label">' . esc_html__('شرکت‌های حمل', 'tecteb-marketplace-core') . '</legend>';
            foreach ($carriers as $slug => $label) {
                $html .= VendorUi::checkbox('carriers[]', $label, in_array($slug, $s->carriers, true), $slug);
            }
            $html .= '</fieldset>';
        }
        return $html . '<div class="tv-actions">' . VendorUi::submit(__('ذخیره', 'tecteb-marketplace-core'), 'secondary') . '</div></form>';
    }

    private static function closure(StoreSettings $s, VendorUrls $urls, string $nonce): string
    {
        return self::formOpen($urls, $nonce, 'save_store', 'closure')
            . '<h2 class="tv-card__title">' . esc_html__('تعطیلی موقت', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html__('سفارش‌های ثبت‌شده قبلی با تعطیلی متوقف نمی‌شوند و باید مثل همیشه انجام شوند.', 'tecteb-marketplace-core') . '</p>'
            . VendorUi::checkbox('closed', __('فروشگاه موقتاً تعطیل است', 'tecteb-marketplace-core'), $s->closed)
            . VendorUi::input('closed_from', __('از تاریخ (اختیاری)', 'tecteb-marketplace-core'), (string) $s->closedFrom, true, 'date', 'ltr')
            . VendorUi::input('closed_to', __('تا تاریخ (اختیاری)', 'tecteb-marketplace-core'), (string) $s->closedTo, true, 'date', 'ltr')
            . VendorUi::input('reopen_message', __('پیام بازگشایی', 'tecteb-marketplace-core'), $s->reopenMessage)
            . '<div class="tv-actions">' . VendorUi::submit(__('ذخیره', 'tecteb-marketplace-core'), 'secondary') . '</div></form>';
    }

    /** @param array<string,string> $networks */
    private static function social(StoreSettings $s, VendorUrls $urls, string $nonce, array $networks): string
    {
        $html = self::formOpen($urls, $nonce, 'save_store', 'social')
            . '<h2 class="tv-card__title">' . esc_html__('شبکه‌های اجتماعی', 'tecteb-marketplace-core') . '</h2>';
        if ($networks === []) {
            $html .= VendorUi::notice('info', __('مدیر بازارگاه هنوز تعیین نکرده کدام شبکه‌ها مجازند.', 'tecteb-marketplace-core'));
        }
        foreach ($networks as $slug => $label) {
            $html .= VendorUi::input('social[' . $slug . ']', $label, $s->social[$slug] ?? '', true, 'url', 'ltr');
        }
        return $html . '<div class="tv-actions">' . VendorUi::submit(__('ذخیره', 'tecteb-marketplace-core'), 'secondary') . '</div></form>';
    }

    /** @param array{iban:string, holder:string, document_id:int, status:string, on_hold:bool} $bank */
    private static function bank(array $bank, VendorUrls $urls, string $nonce, bool $otpAvailable, ?ChangeRequest $pending): string
    {
        $html = '<section class="tv-card"><h2 class="tv-card__title">' . esc_html__('اطلاعات بانکی', 'tecteb-marketplace-core') . '</h2>';
        $html .= VendorUi::notice('warning', $otpAvailable
            ? __('تغییر شبا با تأیید پیامکی و سپس تأیید مدیر انجام می‌شود.', 'tecteb-marketplace-core')
            : __('تأیید پیامکی این تغییر در این نسخه انجام نمی‌شود، چون سرویس پیامک هنوز به بازارگاه وصل نیست. تغییر شبا فقط با تأیید مدیر ثبت می‌شود و تا آن زمان تسویه متوقف می‌ماند.', 'tecteb-marketplace-core'));

        $html .= '<dl class="tv-datalist">'
            . '<dt>' . esc_html__('شبای ثبت‌شده', 'tecteb-marketplace-core') . '</dt>'
            . '<dd><bdi class="tv-code">' . esc_html($bank['iban'] !== '' ? $bank['iban'] : '—') . '</bdi></dd>'
            . '<dt>' . esc_html__('صاحب حساب', 'tecteb-marketplace-core') . '</dt>'
            . '<dd>' . esc_html($bank['holder'] !== '' ? $bank['holder'] : '—') . '</dd>'
            . '<dt>' . esc_html__('وضعیت تسویه', 'tecteb-marketplace-core') . '</dt>'
            . '<dd>' . ($bank['on_hold']
                ? VendorUi::chip('warning', __('متوقف تا تأیید مدیر', 'tecteb-marketplace-core'))
                : VendorUi::chip('ok', __('بدون توقف', 'tecteb-marketplace-core'))) . '</dd>'
            . '</dl>';

        if ($pending !== null) {
            $html .= VendorUi::notice('info', __('درخواست تغییر حساب ثبت شده و در انتظار تأیید مدیر است.', 'tecteb-marketplace-core'));
            return $html . '</section>';
        }
        return $html . self::formOpen($urls, $nonce, 'request_bank', 'bank', false)
            . VendorUi::input('iban', __('شبا (۲۴ رقم پس از IR)', 'tecteb-marketplace-core'), '', true, 'text', 'ltr')
            . VendorUi::input('holder', __('نام صاحب حساب', 'tecteb-marketplace-core'), $bank['holder'])
            . '<div class="tv-actions">' . VendorUi::submit(__('ثبت درخواست تغییر حساب', 'tecteb-marketplace-core')) . '</div></form></section>';
    }

    /** @param list<ChangeRequest> $requests */
    private static function history(array $requests): string
    {
        if ($requests === []) {
            return '';
        }
        $html = '<section class="tv-card"><h2 class="tv-card__title">' . esc_html__('درخواست‌های تغییر', 'tecteb-marketplace-core') . '</h2><ul class="tv-list">';
        foreach ($requests as $request) {
            $html .= '<li>' . esc_html(VendorMessages::changeField($request->field)) . ' — '
                . VendorUi::chip(match ($request->status) {
                    ChangeRequestStatus::Approved => 'ok',
                    ChangeRequestStatus::Rejected => 'warning',
                    ChangeRequestStatus::Pending => 'info',
                }, VendorMessages::changeStatus($request->status))
                . ' <bdi class="tv-code">' . esc_html(PersianDigits::toPersian($request->createdAt)) . '</bdi>'
                . ($request->note !== '' ? '<p class="tv-hint">' . esc_html($request->note) . '</p>' : '')
                . '</li>';
        }
        return $html . '</ul></section>';
    }

    /** @param list<ChangeRequest> $requests */
    private static function pending(array $requests, string $field): ?ChangeRequest
    {
        foreach ($requests as $request) {
            if ($request->field === $field && $request->status === ChangeRequestStatus::Pending) {
                return $request;
            }
        }
        return null;
    }

    /**
     * `enctype` is not decoration: without it the browser sends the file's
     * NAME and no bytes, the upload silently does nothing, and the vendor
     * watches a save succeed with no picture — the exact silence the product
     * form's image handling was rewritten in `alpha.14` to stop.
     */
    private static function formOpen(VendorUrls $urls, string $nonce, string $action, string $tab, bool $card = true): string
    {
        return '<form class="' . ($card ? 'tv-card' : 'tv-subform') . '" method="post" enctype="multipart/form-data"'
            . ' action="' . esc_url(add_query_arg('tab', $tab, $urls->store())) . '">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
    }
}
