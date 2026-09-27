<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspace;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
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
        ?VendorNotice $notice = null,
        string $mobileOnFile = ''
    ): string {
        $details = $workspace->application?->details ?? new ApplicantDetails();
        $editable = $workspace->application === null || $workspace->application->status->isEditableByApplicant();
        $html = '';

        if ($notice !== null) {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                VendorMessages::notice($notice->code, $notice->context)
            );
        }

        // What this page IS, said before anything on it is read. Until
        // `alpha.33` it opened straight into a form headed «اطلاعات فروشگاه» —
        // the name of a different page, the one that holds the shop's live
        // settings — so a vendor editing their address here could not tell
        // which of the two they were changing.
        $html .= '<p class="tv-lead">' . esc_html(self::lead($workspace)) . '</p>';

        // The manager's own words, on the path the vendor is already on. Every
        // decision that carries a reason shows it: «نیازمند اصلاح» to act on,
        // «رد شده» and «تعلیق‌شده» to understand. Reading it for only one of
        // the three is how a manager's sentence disappears.
        $note = $workspace->application?->reviewNote;
        $heading = self::noteHeading($workspace->status());
        if ($note !== null && trim($note) !== '' && $heading !== '') {
            $tone = $workspace->status() === ApplicationStatus::ChangesRequested ? ' tv-note--action' : '';
            $html .= '<div class="tv-note' . $tone . '"><h2>' . esc_html($heading) . '</h2>'
                . '<p>' . esc_html($note) . '</p></div>';
        }

        if (!$editable) {
            // Five statuses reach this line and only two of them are «در حال
            // بررسی». An approved shop was told for months that its own file
            // was still being reviewed.
            $html .= VendorUi::notice(self::lockedTone($workspace->status()), self::whyLocked($workspace->status()));
        }

        // ---- details -------------------------------------------------------
        $html .= '<form class="tv-card" method="post" action="' . esc_url($urls->application()) . '">'
            . $nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="save_draft">'
            . '<h2 class="tv-card__title">' . esc_html__('اطلاعات ثبت‌شده در درخواست', 'tecteb-marketplace-core') . '</h2>'
            . VendorUi::input('store_name', __('نام فروشگاه', 'tecteb-marketplace-core'), $details->storeName, $editable)
            . VendorUi::input('legal_name', __('نام حقوقی یا نام کامل مالک', 'tecteb-marketplace-core'), $details->legalName, $editable)
            . VendorUi::input('contact_email', __('ایمیل تماس', 'tecteb-marketplace-core'), $details->contactEmail, $editable, 'email', 'ltr')
            . VendorUi::input('contact_mobile', __('موبایل', 'tecteb-marketplace-core'), $details->contactMobile, $editable, 'tel', 'ltr',
                $workspace->mobileVerificationAvailable
                    ? __('ثبت شماره به‌معنای تأیید آن نیست؛ تأیید با پیامک انجام می‌شود.', 'tecteb-marketplace-core')
                    : __('تأیید پیامکی فعلاً در دسترس نیست؛ اقدامی لازم نیست.', 'tecteb-marketplace-core'))
            . VendorUi::textarea('address', __('نشانی', 'tecteb-marketplace-core'), $details->address, $editable)
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

    /**
     * One sentence naming the data on the page — and, for a shop that is
     * already trading, naming the OTHER page too.
     *
     * The application record and the store settings are two sets of values
     * with one subject, and the approval froze the first. Sending an approved
     * vendor here to change their address would be sending them to edit a
     * document nobody reads any more.
     */
    private static function lead(VendorWorkspace $workspace): string
    {
        if ($workspace->application === null) {
            return __('این صفحه درخواست فروشندگی شماست. تا وقتی ارسال نکنید پیش‌نویس می‌ماند.', 'tecteb-marketplace-core');
        }
        if ($workspace->status()->isEditableByApplicant()) {
            return __('این صفحه درخواست فروشندگی شماست: همان اطلاعاتی که مدیر بازارگاه برای بررسی می‌بیند.', 'tecteb-marketplace-core');
        }
        if ($workspace->status() === ApplicationStatus::Approved) {
            return __('این پروندهٔ فروشندگی شماست — اطلاعاتی که در زمان درخواست ثبت شد و مبنای تأیید بود. تنظیمات جاری فروشگاه (نام نمایشی، لوگو، حساب بانکی) در «تنظیمات فروشگاه» است و از اینجا عوض نمی‌شود.', 'tecteb-marketplace-core');
        }
        return __('این پروندهٔ فروشندگی شماست: اطلاعاتی که ثبت شده و مدیر بازارگاه بررسی می‌کند.', 'tecteb-marketplace-core');
    }

    /** Why the form is read-only — per status, because the reasons differ. */
    private static function whyLocked(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Submitted, ApplicationStatus::InReview => __('این درخواست در نوبت بررسی است و تا اعلام نتیجه ویرایش نمی‌شود. اطلاعات ثبت‌شده در پایین آمده است.', 'tecteb-marketplace-core'),
            ApplicationStatus::Approved => __('این درخواست تأیید شده است، پس پروندهٔ آن بسته و فقط برای مشاهده است. تغییر اطلاعات فروشگاه از «تنظیمات فروشگاه» انجام می‌شود.', 'tecteb-marketplace-core'),
            ApplicationStatus::Rejected => __('این درخواست رد شده و ویرایش نمی‌شود. برای درخواست تازه با مدیر بازارگاه تماس بگیرید.', 'tecteb-marketplace-core'),
            ApplicationStatus::Suspended => __('فروشگاه شما تعلیق شده است؛ تا رفع تعلیق، این پرونده فقط برای مشاهده است.', 'tecteb-marketplace-core'),
            default => __('این پرونده در این وضعیت ویرایش نمی‌شود. اطلاعات ثبت‌شده در پایین آمده است.', 'tecteb-marketplace-core'),
        };
    }

    /**
     * A refusal and a decision are not the same colour.
     *
     * `info` for «در نوبت» and for a closed, successful file; `warning` only
     * where something is actually wrong — a rejection or a suspension.
     */
    private static function lockedTone(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Rejected, ApplicationStatus::Suspended => 'warning',
            default => 'info',
        };
    }

    /** The heading the manager's note gets, or '' when this status has none. */
    private static function noteHeading(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::ChangesRequested => __('چه چیزی باید اصلاح شود', 'tecteb-marketplace-core'),
            ApplicationStatus::Rejected => __('دلیل رد درخواست', 'tecteb-marketplace-core'),
            ApplicationStatus::Suspended => __('دلیل تعلیق فروشگاه', 'tecteb-marketplace-core'),
            default => '',
        };
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
}
