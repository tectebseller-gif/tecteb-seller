<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Product\Application\ConfigureSpecTemplates;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;

/**
 * The category form builder of §6.1 — «سازنده هوشمند مشخصات پزشکی».
 *
 * The screen makes MED-01's two rules visible rather than merely enforcing
 * them underneath: a field's key is shown but never editable, and retiring a
 * field says in words that the answers already given are kept. Reordering is
 * a number, not a drag handle, so it works with a keyboard by construction.
 */
final class SpecTemplatesPage
{
    public const SLUG = 'tmc-spec-templates';
    public const CAPABILITY = Capabilities::MANAGE_SPEC_TEMPLATES;
    private const NONCE = 'tmc_spec_templates';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('الگوهای مشخصات', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $templates = $this->container->get(SpecTemplateRepositoryInterface::class)->all();

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== null) {
            echo Components::notice(
                ProductMessages::isErrorNotice($notice['code']) ? 'error' : 'success',
                (string) (ProductMessages::notice($notice['code'], $notice['context'])
                    ?? VendorMessages::notice($notice['code'], $notice['context']))
            );
        }
        echo Components::notice('info', __('فیلدهای پزشکی برای همه دسته‌ها نمایش داده نمی‌شوند: هر دسته الگوی خودش را دارد و فرم فروشنده دقیقاً همین فیلدها را می‌پرسد. فهرست پیشنهادی مشخصات، الزام قانونی نیست؛ تعیین مدارک و دسته‌بندی لازم با مدیر کسب‌وکار است.', 'tecteb-marketplace-core'));

        foreach ($templates as $template) {
            $this->renderTemplate($template);
        }
        if ($templates === []) {
            echo '<section class="tmc-card"><p>' . esc_html__('هنوز الگویی ساخته نشده است.', 'tecteb-marketplace-core') . '</p></section>';
        }
        $this->renderNewTemplateForm();
        echo Components::shellClose();
    }

    private function renderTemplate(SpecTemplate $template): void
    {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html($template->label) . '</h2>'
            . '<p class="tmc-hint">' . esc_html(sprintf(
                /* translators: 1: category key, 2: template schema version */
                __('کلید دسته: %1$s — نسخه الگو: %2$s', 'tecteb-marketplace-core'),
                $template->categoryKey,
                $fa($template->schemaVersion)
            )) . '</p>';

        $fields = $template->allFields();
        if ($fields === []) {
            echo '<p>' . esc_html__('این الگو هنوز فیلدی ندارد.', 'tecteb-marketplace-core') . '</p>';
        } else {
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('کلید', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('برچسب', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('نوع', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($fields as $field) {
                echo '<tr><td>' . Components::code($field->key) . '</td>'
                    . '<td>' . esc_html($field->label) . ($field->unit !== '' ? ' <small>(' . esc_html($field->unit) . ')</small>' : '') . '</td>'
                    . '<td>' . esc_html(ProductMessages::fieldType($field->type)) . '</td>'
                    . '<td>' . esc_html($field->deprecated
                        ? __('بازنشسته — دیگر پرسیده نمی‌شود', 'tecteb-marketplace-core')
                        : ($field->required ? __('اجباری', 'tecteb-marketplace-core') : __('اختیاری', 'tecteb-marketplace-core'))) . '</td>'
                    . '<td>' . $this->fieldActions($template, $field->id, $field->deprecated) . '</td></tr>';
            }
            echo '</tbody></table>'
                . '<p class="tmc-hint">' . esc_html__('کلید فیلد هرگز تغییر نمی‌کند و فیلد حذف نمی‌شود؛ بازنشسته‌کردن یعنی دیگر پرسیده نشود و پاسخ‌های قبلی سر جایشان بمانند.', 'tecteb-marketplace-core') . '</p>';
        }

        echo $this->addFieldForm($template);
        echo '</section>';
    }

    private function fieldActions(SpecTemplate $template, int $fieldId, bool $deprecated): string
    {
        return '<form method="post" class="tmc-inline">'
            . wp_nonce_field(self::NONCE, 'tmc_templates_nonce', true, false)
            . '<input type="hidden" name="template_id" value="' . esc_attr((string) $template->id) . '">'
            . '<input type="hidden" name="field_id" value="' . esc_attr((string) $fieldId) . '">'
            . '<button type="submit" class="tmc-button" name="template_action" value="' . ($deprecated ? 'restore_field' : 'deprecate_field') . '">'
            . esc_html($deprecated
                ? __('پرسیدن دوباره', 'tecteb-marketplace-core')
                : __('بازنشسته‌کردن', 'tecteb-marketplace-core'))
            . '</button></form>';
    }

    private function addFieldForm(SpecTemplate $template): string
    {
        $id = 'tpl-' . $template->id;
        $html = '<form method="post"><h3 class="tmc-card__subtitle">' . esc_html__('افزودن فیلد', 'tecteb-marketplace-core') . '</h3>'
            . wp_nonce_field(self::NONCE, 'tmc_templates_nonce', true, false)
            . '<input type="hidden" name="template_action" value="add_field">'
            . '<input type="hidden" name="template_id" value="' . esc_attr((string) $template->id) . '">'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-key">'
            . esc_html__('کلید (انگلیسی، ثابت و غیرقابل تغییر)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="' . $id . '-key" name="field_key" dir="ltr"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-label">'
            . esc_html__('برچسب فارسی', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="' . $id . '-label" name="field_label"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-type">'
            . esc_html__('نوع', 'tecteb-marketplace-core') . '</label>'
            . '<select class="tmc-input" id="' . $id . '-type" name="field_type">';
        foreach (ProductMessages::fieldTypes() as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        return $html . '</select></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-unit">'
            . esc_html__('واحد (اختیاری: kg، cm، ml، درصد…)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input tmc-input--short" type="text" id="' . $id . '-unit" name="field_unit"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-options">'
            . esc_html__('گزینه‌ها برای نوع «انتخاب از فهرست» — هر گزینه در یک سطر', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="' . $id . '-options" name="field_options" rows="3"></textarea></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-sort">'
            . esc_html__('ترتیب نمایش', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input tmc-input--short" type="text" id="' . $id . '-sort" name="field_sort" dir="ltr" inputmode="numeric" value="0"></div>'
            . '<div class="tmc-field"><label><input type="checkbox" name="field_required" value="1"> '
            . esc_html__('اجباری باشد', 'tecteb-marketplace-core') . '</label>'
            . '<p class="tmc-field__desc">' . esc_html__('اجباری‌کردن، محصول‌های قبلی را باطل نمی‌کند: هر محصول با نسخه‌ای که با آن پر شده معتبر می‌ماند و قاعده تازه از ویرایش بعدی خواسته می‌شود.', 'tecteb-marketplace-core') . '</p></div>'
            . '<p><button type="submit" class="tmc-button tmc-button--primary">' . esc_html__('افزودن فیلد', 'tecteb-marketplace-core') . '</button></p>'
            . '</form>';
    }

    private function renderNewTemplateForm(): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('الگوی تازه', 'tecteb-marketplace-core') . '</h2>'
            . '<form method="post">' . wp_nonce_field(self::NONCE, 'tmc_templates_nonce', true, false)
            . '<input type="hidden" name="template_action" value="create_template">'
            . '<div class="tmc-field"><label class="tmc-field__label" for="new-template-key">'
            . esc_html__('کلید دسته (انگلیسی)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="new-template-key" name="category_key" dir="ltr"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="new-template-label">'
            . esc_html__('عنوان فارسی دسته', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="new-template-label" name="template_label"></div>'
            . '<p><button type="submit" class="tmc-button tmc-button--primary">' . esc_html__('ساخت الگو', 'tecteb-marketplace-core') . '</button></p>'
            . '</form></section>';
    }

    /** @return array{code:string,context:array<string,scalar|null>}|null */
    private function handleAction(Request $request): ?array
    {
        if (!$request->isPost() || !$request->hasPost('template_action')) {
            return null;
        }
        if (!$request->nonceOk('tmc_templates_nonce', self::NONCE)) {
            return ['code' => 'forbidden', 'context' => []];
        }
        $service = $this->container->get(ConfigureSpecTemplates::class);
        $templateId = $request->postInt('template_id');
        $result = match ($request->postKey('template_action')) {
            'create_template' => $service->createTemplate($request->postText('category_key'), $request->postText('template_label')),
            'add_field' => $service->addField(
                $templateId,
                $request->postText('field_key'),
                $request->postText('field_label'),
                $request->postKey('field_type'),
                $request->postChecked('field_required'),
                $request->postText('field_unit'),
                $this->linesOf($request->postTextarea('field_options')),
                $request->postInt('field_sort')
            ),
            'deprecate_field' => $service->deprecateField($templateId, $request->postInt('field_id')),
            'restore_field' => $service->restoreField($templateId, $request->postInt('field_id')),
            default => null,
        };
        return $result === null ? null : ['code' => $result->code, 'context' => $result->context];
    }

    /** @return list<string> */
    private function linesOf(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }
}
