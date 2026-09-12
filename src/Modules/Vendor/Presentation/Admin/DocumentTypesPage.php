<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Vendor\Application\ConfigureDocumentTypes;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentTypeRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementMode;

/**
 * Where the manager decides which documents applicants must upload.
 *
 * The plugin ships no list and suggests none: a marketplace for medical
 * goods has legal requirements we are in no position to invent. The page
 * therefore starts empty and says plainly what that empty state means for
 * applicants — and offers the one other honest option, "this needs no
 * documents", as an explicit, recorded decision.
 */
final class DocumentTypesPage
{
    public const SLUG = 'tmc-vendor-documents';
    public const CAPABILITY = VendorCapabilities::MANAGE_DOCUMENTS;
    private const NONCE = 'tmc_vendor_doctypes';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('مدارک فروشندگان', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $requirements = $this->container->get(DocumentTypeRepositoryInterface::class)->requirementSet();
        $mode = $requirements->mode();
        $fa = static fn (float $n): string => PersianDigits::toPersian((string) $n);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(str_starts_with($notice, 'ok:') ? 'success' : 'error', substr($notice, strpos($notice, ':') + 1));
        }

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('وضعیت فعلی', 'tecteb-marketplace-core') . '</h2>';
        echo Components::notice(
            $mode === RequirementMode::Undefined ? 'warning' : 'info',
            match ($mode) {
                RequirementMode::Undefined => __('هنوز هیچ مدرکی تعریف نشده و «بدون مدرک» هم ثبت نشده است. تا یکی از این دو انجام نشود، فروشندگان می‌توانند فرم را پر و پیش‌نویس را ذخیره کنند، اما درخواستشان ارسال نمی‌شود.', 'tecteb-marketplace-core'),
                RequirementMode::ExplicitlyNone => __('ثبت شده که این نوع فروشندگی مدرکی لازم ندارد. فروشندگان می‌توانند درخواست را ارسال کنند.', 'tecteb-marketplace-core'),
                RequirementMode::Configured => __('مدارک تعریف شده‌اند. فروشندگان باید موارد اجباری را بارگذاری کنند تا ارسال ممکن شود.', 'tecteb-marketplace-core'),
            }
        );
        echo '</section>';

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('مدارک تعریف‌شده', 'tecteb-marketplace-core') . '</h2>';
        if ($requirements->types() === []) {
            echo '<p>' . esc_html__('فهرست خالی است.', 'tecteb-marketplace-core') . '</p>';
        } else {
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('عنوان', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('اجباری', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('فرمت', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('حداکثر حجم', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($requirements->types() as $type) {
                echo '<tr>'
                    . '<th scope="row" data-label="' . esc_attr__('عنوان', 'tecteb-marketplace-core') . '">' . esc_html($type->label) . '</th>'
                    . '<td data-label="' . esc_attr__('اجباری', 'tecteb-marketplace-core') . '">' . esc_html($type->required ? __('بله', 'tecteb-marketplace-core') : __('خیر', 'tecteb-marketplace-core')) . '</td>'
                    . '<td data-label="' . esc_attr__('فرمت', 'tecteb-marketplace-core') . '">' . Components::code(implode(', ', $type->allowedMime)) . '</td>'
                    . '<td data-label="' . esc_attr__('حداکثر حجم', 'tecteb-marketplace-core') . '">' . esc_html(sprintf(__('%s مگابایت', 'tecteb-marketplace-core'), $fa(round($type->maxBytes / 1048576, 1)))) . '</td>'
                    . '<td data-label="' . esc_attr__('اقدام', 'tecteb-marketplace-core') . '">'
                    . '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
                    . wp_nonce_field(self::NONCE, 'tmc_doctypes_nonce', true, false)
                    . '<input type="hidden" name="type_id" value="' . esc_attr((string) $type->id) . '">'
                    . '<button type="submit" name="doc_action" value="remove" class="tmc-button tmc-button--secondary">' . esc_html__('حذف', 'tecteb-marketplace-core') . '</button>'
                    . '</form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('افزودن مدرک', 'tecteb-marketplace-core') . '</h2>'
            . '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . wp_nonce_field(self::NONCE, 'tmc_doctypes_nonce', true, false)
            . '<div class="tmc-field"><label class="tmc-field__label" for="doc-label">' . esc_html__('عنوان مدرک', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="doc-label" name="label" required style="inline-size:100%">'
            . '<p class="tmc-field__desc">' . esc_html__('همان عنوانی که فروشنده می‌بیند؛ افزونه هیچ عنوانی را پیشنهاد نمی‌کند.', 'tecteb-marketplace-core') . '</p></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="doc-instructions">' . esc_html__('راهنما برای فروشنده (اختیاری)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="doc-instructions" name="instructions" style="inline-size:100%"></div>'
            . '<div class="tmc-field tmc-field--check"><label><input type="checkbox" name="required" value="1" checked> '
            . esc_html__('بارگذاری این مدرک اجباری است', 'tecteb-marketplace-core') . '</label></div>'
            . '<fieldset class="tmc-field"><legend class="tmc-field__label">' . esc_html__('فرمت‌های مجاز', 'tecteb-marketplace-core') . '</legend>'
            . '<label><input type="checkbox" name="mime[]" value="application/pdf" checked> PDF</label> '
            . '<label><input type="checkbox" name="mime[]" value="image/jpeg" checked> JPG</label> '
            . '<label><input type="checkbox" name="mime[]" value="image/png"> PNG</label></fieldset>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="doc-size">' . esc_html__('حداکثر حجم (مگابایت)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input tmc-input--short" type="number" id="doc-size" name="max_mb" value="5" min="1" max="20" step="1"></div>'
            . '<div class="tmc-actions"><button type="submit" name="doc_action" value="add" class="tmc-button tmc-button--primary">'
            . esc_html__('افزودن به فهرست', 'tecteb-marketplace-core') . '</button></div></form></section>';

        echo '<section class="tmc-card tmc-card--muted"><h2 class="tmc-card__title">' . esc_html__('یا: این نوع فروشندگی مدرکی لازم ندارد', 'tecteb-marketplace-core') . '</h2>'
            . '<p>' . esc_html__('اگر واقعاً مدرکی لازم نیست، همین را ثبت کنید تا فروشندگان بتوانند درخواست بدهند. این تصمیم با نام شما و زمانش ثبت می‌شود و «هنوز تعیین نشده» به‌خودیِ‌خود هرگز به این معنا گرفته نمی‌شود.', 'tecteb-marketplace-core') . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . wp_nonce_field(self::NONCE, 'tmc_doctypes_nonce', true, false)
            . '<div class="tmc-actions">'
            . ($mode === RequirementMode::ExplicitlyNone
                ? '<button type="submit" name="doc_action" value="withdraw_none" class="tmc-button tmc-button--secondary">' . esc_html__('لغو «بدون مدرک»', 'tecteb-marketplace-core') . '</button>'
                : '<button type="submit" name="doc_action" value="declare_none" class="tmc-button tmc-button--secondary">' . esc_html__('ثبت «بدون مدرک»', 'tecteb-marketplace-core') . '</button>')
            . '</div></form></section>';

        echo Components::shellClose();
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('doc_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_doctypes_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $service = $this->container->get(ConfigureDocumentTypes::class);
        $action = $request->postKey('doc_action');

        $result = match ($action) {
            'add' => $service->add(
                $request->postText('label'),
                $request->postChecked('required'),
                $request->postTextList('mime'),
                max(1, min(20, $request->postInt('max_mb') ?: 5)) * 1048576,
                $request->postText('instructions')
            ),
            'remove' => $service->remove($request->postInt('type_id')),
            'declare_none' => $service->declareNoDocumentsNeeded(true),
            'withdraw_none' => $service->declareNoDocumentsNeeded(false),
            default => null,
        };
        if ($result === null) {
            return '';
        }
        if ($result->ok) {
            return 'ok:' . match ($result->code) {
                'type_added' => __('مدرک به فهرست اضافه شد.', 'tecteb-marketplace-core'),
                'type_removed' => __('مدرک از فهرست حذف شد.', 'tecteb-marketplace-core'),
                'declared_none' => __('ثبت شد: این نوع فروشندگی مدرکی لازم ندارد.', 'tecteb-marketplace-core'),
                default => __('انجام شد.', 'tecteb-marketplace-core'),
            };
        }
        return 'err:' . match ($result->code) {
            'label_required' => __('عنوان مدرک را بنویسید.', 'tecteb-marketplace-core'),
            'mime_required' => __('دست‌کم یک فرمت مجاز انتخاب کنید.', 'tecteb-marketplace-core'),
            'bad_size' => __('حداکثر حجم باید بین ۱ تا ۲۰ مگابایت باشد.', 'tecteb-marketplace-core'),
            'forbidden' => __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'),
            default => __('انجام نشد.', 'tecteb-marketplace-core'),
        };
    }
}
