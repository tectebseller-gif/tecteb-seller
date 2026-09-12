<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspace;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\DocumentType;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementMode;

/**
 * The application form: details, documents, submit.
 *
 * The documents section is the interesting part. It has three shapes, one
 * per requirement mode, and the "not configured yet" shape is written to be
 * read by the applicant, not by us: it says nothing is being asked of them,
 * their draft is safe, and submission will open when the manager decides.
 */
final class ApplicationView
{
    public static function render(
        VendorWorkspace $workspace,
        VendorUrls $urls,
        string $nonceField,
        string $notice = '',
        string $mobileOnFile = ''
    ): string {
        $details = $workspace->application?->details ?? new ApplicantDetails();
        $editable = $workspace->application === null || $workspace->application->status->isEditableByApplicant();
        $html = '';

        if ($notice !== '') {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice) ? 'warning' : 'success',
                VendorMessages::notice($notice)
            );
        }

        $note = $workspace->application?->reviewNote;
        if ($note !== null && trim($note) !== '' && $workspace->status()->value === 'changes_requested') {
            $html .= '<div class="tv-note tv-note--action"><h2>' . esc_html__('چه چیزی باید اصلاح شود', 'tecteb-marketplace-core') . '</h2>'
                . '<p>' . esc_html($note) . '</p></div>';
        }

        if (!$editable) {
            $html .= VendorUi::notice('info', __('این درخواست در حال بررسی است و تا اعلام نتیجه ویرایش نمی‌شود. اطلاعات ثبت‌شده در پایین آمده است.', 'tecteb-marketplace-core'));
        }

        // ---- details -------------------------------------------------------
        $html .= '<form class="tv-card" method="post" action="' . esc_url($urls->application()) . '">'
            . $nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="save_draft">'
            . '<h2 class="tv-card__title">' . esc_html__('اطلاعات فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . self::field('store_name', __('نام فروشگاه', 'tecteb-marketplace-core'), $details->storeName, $editable)
            . self::field('legal_name', __('نام حقوقی یا نام کامل مالک', 'tecteb-marketplace-core'), $details->legalName, $editable)
            . self::field('contact_email', __('ایمیل تماس', 'tecteb-marketplace-core'), $details->contactEmail, $editable, 'email', 'ltr')
            . self::field('contact_mobile', __('موبایل', 'tecteb-marketplace-core'), $details->contactMobile, $editable, 'tel', 'ltr',
                __('ثبت شماره به‌معنای تأیید آن نیست. تأیید با پیامک انجام می‌شود و این سرویس هنوز به بازارگاه وصل نیست.', 'tecteb-marketplace-core'))
            . self::textarea('address', __('نشانی', 'tecteb-marketplace-core'), $details->address, $editable)
            . '<div class="tv-field tv-field--check"><label>'
            . '<input type="checkbox" name="terms" value="1"' . checked($details->termsAccepted, true, false) . ($editable ? '' : ' disabled') . '> '
            . esc_html__('قوانین بازارگاه تک‌طب را می‌پذیرم.', 'tecteb-marketplace-core')
            . '</label></div>';
        if ($editable) {
            $html .= '<div class="tv-actions">' . VendorUi::submit(__('ذخیره پیش‌نویس', 'tecteb-marketplace-core'), 'secondary') . '</div>';
        }
        $html .= '</form>';

        // ---- documents ------------------------------------------------------
        $html .= '<section class="tv-card" aria-labelledby="tv-docs">'
            . '<h2 id="tv-docs" class="tv-card__title">' . esc_html__('مدارک', 'tecteb-marketplace-core') . '</h2>'
            . match ($workspace->requirements->mode()) {
                RequirementMode::Undefined => self::documentsUndefined(),
                RequirementMode::ExplicitlyNone => '<p>' . esc_html__('برای این نوع فروشندگی مدرکی لازم نیست.', 'tecteb-marketplace-core') . '</p>',
                RequirementMode::Configured => self::documentsList($workspace, $urls, $nonceField, $editable),
            }
            . '</section>';

        // ---- submit ---------------------------------------------------------
        if ($editable) {
            $html .= '<section class="tv-card tv-card--submit" aria-labelledby="tv-submit">'
                . '<h2 id="tv-submit" class="tv-card__title">' . esc_html__('ارسال درخواست', 'tecteb-marketplace-core') . '</h2>';
            if ($workspace->canSubmit()) {
                $html .= '<p>' . esc_html__('همه‌چیز آماده است. با ارسال، درخواست در صف بررسی مدیر قرار می‌گیرد و تا اعلام نتیجه ویرایش نمی‌شود.', 'tecteb-marketplace-core') . '</p>'
                    . '<form method="post" action="' . esc_url($urls->application()) . '">' . $nonceField
                    . '<input type="hidden" name="tmc_vendor_action" value="submit">'
                    . '<div class="tv-actions">' . VendorUi::submit(__('ارسال برای بررسی', 'tecteb-marketplace-core')) . '</div></form>';
            } else {
                $html .= '<p>' . esc_html(self::whyNotSubmittable($workspace)) . '</p>';
            }
            $html .= '</section>';
        }

        return $html;
    }

    private static function documentsUndefined(): string
    {
        return '<div class="tv-note"><p>'
            . esc_html__('مدیر بازارگاه هنوز تعیین نکرده چه مدارکی لازم است؛ بنابراین در این مرحله هیچ مدرکی از شما خواسته نمی‌شود.', 'tecteb-marketplace-core')
            . '</p><p>'
            . esc_html__('اطلاعاتی که وارد کرده‌اید به‌صورت پیش‌نویس محفوظ می‌ماند و به‌محض تعیین فهرست، همین‌جا از شما خواسته می‌شود.', 'tecteb-marketplace-core')
            . '</p></div>';
    }

    private static function documentsList(VendorWorkspace $workspace, VendorUrls $urls, string $nonceField, bool $editable): string
    {
        $html = '<ul class="tv-docs">';
        foreach ($workspace->requirements->types() as $type) {
            $doc = $workspace->documentFor($type->slug);
            $html .= '<li class="tv-doc">'
                . '<div class="tv-doc__head">'
                . '<h3 class="tv-doc__title">' . esc_html($type->label) . '</h3>'
                . ($type->required
                    ? VendorUi::chip('warning', __('اجباری', 'tecteb-marketplace-core'))
                    : VendorUi::chip('neutral', __('اختیاری', 'tecteb-marketplace-core')))
                . ($doc !== null ? VendorUi::chip('success', __('بارگذاری شد', 'tecteb-marketplace-core')) : '')
                . '</div>';
            if ($type->instructions !== '') {
                $html .= '<p class="tv-doc__hint">' . esc_html($type->instructions) . '</p>';
            }
            $html .= '<p class="tv-doc__rules">' . esc_html(self::rulesLine($type)) . '</p>';

            if ($doc !== null) {
                $html .= '<p class="tv-doc__file">' . esc_html($doc->originalName)
                    . ' — ' . esc_html(self::size($doc->sizeBytes))
                    . ' <a href="' . esc_url($urls->document($doc->id)) . '">' . esc_html__('دریافت فایل', 'tecteb-marketplace-core') . '</a></p>';
            }

            if ($editable) {
                $html .= '<form method="post" enctype="multipart/form-data" action="' . esc_url($urls->application()) . '">'
                    . $nonceField
                    . '<input type="hidden" name="tmc_vendor_action" value="upload">'
                    . '<input type="hidden" name="type_slug" value="' . esc_attr($type->slug) . '">'
                    . '<div class="tv-field"><label class="tv-label" for="file-' . esc_attr($type->slug) . '">'
                    . esc_html($doc !== null ? __('جایگزینی فایل', 'tecteb-marketplace-core') : __('انتخاب فایل', 'tecteb-marketplace-core'))
                    . '</label>'
                    . '<input class="tv-input" type="file" id="file-' . esc_attr($type->slug) . '" name="document" required>'
                    . '</div>'
                    . '<div class="tv-actions">' . VendorUi::submit($doc !== null ? __('جایگزین کن', 'tecteb-marketplace-core') : __('بارگذاری', 'tecteb-marketplace-core'), $doc !== null ? 'secondary' : 'primary') . '</div>'
                    . '</form>';
            }
            $html .= '</li>';
        }
        return $html . '</ul>';
    }

    private static function rulesLine(DocumentType $type): string
    {
        $formats = [];
        foreach ($type->allowedMime as $mime) {
            $formats[] = match ($mime) {
                'application/pdf' => 'PDF',
                'image/jpeg' => 'JPG',
                'image/png' => 'PNG',
                default => $mime,
            };
        }
        return sprintf(
            /* translators: 1: file formats, 2: maximum size in megabytes */
            __('فرمت مجاز: %1$s · حداکثر حجم: %2$s مگابایت', 'tecteb-marketplace-core'),
            implode('، ', $formats),
            PersianDigits::toPersian((string) round($type->maxBytes / 1048576, 1))
        );
    }

    private static function size(int $bytes): string
    {
        return sprintf(__('%s مگابایت', 'tecteb-marketplace-core'), PersianDigits::toPersian((string) round($bytes / 1048576, 2)));
    }

    private static function whyNotSubmittable(VendorWorkspace $workspace): string
    {
        if ($workspace->application === null || !$workspace->application->details->isComplete()) {
            return __('ابتدا اطلاعات بالا را کامل و ذخیره کنید؛ سپس دکمه ارسال همین‌جا ظاهر می‌شود.', 'tecteb-marketplace-core');
        }
        if ($workspace->requirements->mode() === RequirementMode::Undefined) {
            return __('تا وقتی مدیر بازارگاه فهرست مدارک را تعیین نکند، ارسال ممکن نیست. پیش‌نویس شما محفوظ است و نیازی به اقدام دیگری ندارید.', 'tecteb-marketplace-core');
        }
        return __('مدارک اجباری را بارگذاری کنید تا ارسال ممکن شود.', 'tecteb-marketplace-core');
    }

    private static function field(
        string $name,
        string $label,
        string $value,
        bool $editable,
        string $type = 'text',
        string $dir = 'rtl',
        string $hint = ''
    ): string {
        $id = 'f-' . $name;
        return '<div class="tv-field">'
            . '<label class="tv-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<input class="tv-input" type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"'
            . ' value="' . esc_attr($value) . '" dir="' . esc_attr($dir) . '"' . ($editable ? '' : ' readonly') . '>'
            . ($hint !== '' ? '<p class="tv-hint">' . esc_html($hint) . '</p>' : '')
            . '</div>';
    }

    private static function textarea(string $name, string $label, string $value, bool $editable): string
    {
        $id = 'f-' . $name;
        return '<div class="tv-field">'
            . '<label class="tv-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<textarea class="tv-input" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="3"'
            . ($editable ? '' : ' readonly') . '>' . esc_textarea($value) . '</textarea></div>';
    }
}
