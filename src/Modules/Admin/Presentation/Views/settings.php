<?php
declare(strict_types=1);

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Admin\Presentation\Messages;

/** @var array<string,mixed> $vm */
$f = $vm['fields'];
$invalid = static fn (string $field): bool => in_array($field, $vm['invalid'], true);
$fa = static fn (int|string $n): string => PersianDigits::toPersian((string) $n);

echo Components::shellOpen(__('تنظیمات بازارگاه', 'tecteb-marketplace-core'), 'tmc-settings', __('نسخه آزمایشی', 'tecteb-marketplace-core'));
echo Components::errorSummary(array_map(static fn ($e) => ['href' => $e['href'], 'message' => $e['message']], $vm['errors']));
echo '<div class="tmc-live" aria-live="polite" role="status">';
foreach ($vm['successes'] as $msg) {
    echo Components::notice('success', $msg);
}
foreach ($vm['warnings'] as $msg) {
    echo Components::notice('warning', $msg);
}
echo '</div>';
?>
<form method="post" action="<?php echo esc_url($vm['action']); ?>" class="tmc-form" novalidate>
    <?php settings_fields($vm['group']); ?>

    <fieldset class="tmc-card">
        <legend class="tmc-card__title"><?php esc_html_e('کمیسیون', 'tecteb-marketplace-core'); ?></legend>
        <div class="tmc-field<?php echo $invalid($f['commission']) ? ' is-invalid' : ''; ?>">
            <label class="tmc-field__label" for="tmc-field-<?php echo esc_attr($f['commission']); ?>"><?php echo esc_html(Messages::fieldLabel($f['commission'])); ?></label>
            <div class="tmc-field__control">
                <input type="text" inputmode="decimal" dir="ltr"
                       id="tmc-field-<?php echo esc_attr($f['commission']); ?>"
                       name="<?php echo esc_attr($vm['option']); ?>[<?php echo esc_attr($f['commission']); ?>]"
                       value="<?php echo esc_attr($vm['commission_input']); ?>"
                       aria-describedby="tmc-desc-commission"
                       <?php echo $invalid($f['commission']) ? 'aria-invalid="true"' : ''; ?>
                       class="tmc-input tmc-input--short" autocomplete="off">
                <span class="tmc-field__unit" aria-hidden="true">٪</span>
            </div>
            <p id="tmc-desc-commission" class="tmc-field__desc">
                <?php if ($vm['commission_state'] === 'unset') : ?>
                    <strong><?php esc_html_e('هنوز تعیین نشده.', 'tecteb-marketplace-core'); ?></strong>
                <?php elseif ($vm['commission_state'] === 'zero') : ?>
                    <strong><?php esc_html_e('صفر یعنی بدون کمیسیون.', 'tecteb-marketplace-core'); ?></strong>
                <?php endif; ?>
                <?php esc_html_e('درصد با حداکثر دو رقم اعشار، بین ۰ و ۱۰۰. خالی گذاشتن یعنی «تعیین‌نشده» و با صفر متفاوت است. مقدار به‌صورت basis point صحیح ذخیره می‌شود و در این نسخه در هیچ محاسبه‌ای مصرف نمی‌شود.', 'tecteb-marketplace-core'); ?>
            </p>
        </div>
    </fieldset>

    <fieldset class="tmc-card">
        <legend class="tmc-card__title"><?php esc_html_e('تنظیمات ماژول‌های آینده', 'tecteb-marketplace-core'); ?></legend>
        <p class="tmc-card__note"><?php esc_html_e('این مقادیر ذخیره می‌شوند اما در فاز اول هنوز مصرف عملیاتی ندارند.', 'tecteb-marketplace-core'); ?></p>

        <div class="tmc-field<?php echo $invalid($f['delay']) ? ' is-invalid' : ''; ?>">
            <label class="tmc-field__label" for="tmc-field-<?php echo esc_attr($f['delay']); ?>"><?php echo esc_html(Messages::fieldLabel($f['delay'])); ?></label>
            <div class="tmc-field__control">
                <input type="text" inputmode="numeric" dir="ltr"
                       id="tmc-field-<?php echo esc_attr($f['delay']); ?>"
                       name="<?php echo esc_attr($vm['option']); ?>[<?php echo esc_attr($f['delay']); ?>]"
                       value="<?php echo esc_attr((string) $vm['settlement_delay_days']); ?>"
                       aria-describedby="tmc-desc-delay"
                       <?php echo $invalid($f['delay']) ? 'aria-invalid="true"' : ''; ?>
                       class="tmc-input tmc-input--short" autocomplete="off">
                <span class="tmc-field__unit"><?php esc_html_e('روز', 'tecteb-marketplace-core'); ?></span>
            </div>
            <p id="tmc-desc-delay" class="tmc-field__desc">
                <?php echo esc_html(sprintf(__('عدد صحیح بین %1$s و %2$s؛ پیش‌فرض %3$s. صفر یعنی بدون تأخیر زمانی اضافی — هیچ شرط پرداخت معتبر، تکمیل معتبر یا نگه‌داشت مالی را حذف نمی‌کند. حد بالا یک حد فنی پیشنهادی است، نه قاعده تجاری.', 'tecteb-marketplace-core'), $fa($vm['bounds']['delay_min']), $fa($vm['bounds']['delay_max']), $fa(4))); ?>
            </p>
        </div>

        <div class="tmc-field<?php echo $invalid($f['staff']) ? ' is-invalid' : ''; ?>">
            <label class="tmc-field__label" for="tmc-field-<?php echo esc_attr($f['staff']); ?>"><?php echo esc_html(Messages::fieldLabel($f['staff'])); ?></label>
            <div class="tmc-field__control">
                <input type="text" inputmode="numeric" dir="ltr"
                       id="tmc-field-<?php echo esc_attr($f['staff']); ?>"
                       name="<?php echo esc_attr($vm['option']); ?>[<?php echo esc_attr($f['staff']); ?>]"
                       value="<?php echo esc_attr((string) $vm['max_staff']); ?>"
                       aria-describedby="tmc-desc-staff"
                       <?php echo $invalid($f['staff']) ? 'aria-invalid="true"' : ''; ?>
                       class="tmc-input tmc-input--short" autocomplete="off">
                <span class="tmc-field__unit"><?php esc_html_e('نفر', 'tecteb-marketplace-core'); ?></span>
            </div>
            <p id="tmc-desc-staff" class="tmc-field__desc">
                <?php echo esc_html(sprintf(__('عدد صحیح بین %1$s و %2$s؛ پیش‌فرض %3$s. حد بالا یک حد فنی پیشنهادی برای رد ورودی نامعقول است، نه حداکثر مجاز کسب‌وکار و نه ظرفیت آزموده‌شده.', 'tecteb-marketplace-core'), $fa($vm['bounds']['staff_min']), $fa($vm['bounds']['staff_max']), $fa(10))); ?>
            </p>
        </div>
    </fieldset>

    <fieldset class="tmc-card">
        <legend class="tmc-card__title"><?php esc_html_e('محیط', 'tecteb-marketplace-core'); ?></legend>
        <div class="tmc-field<?php echo $invalid($f['environment']) ? ' is-invalid' : ''; ?>">
            <label class="tmc-field__label" for="tmc-field-<?php echo esc_attr($f['environment']); ?>"><?php echo esc_html(Messages::fieldLabel($f['environment'])); ?></label>
            <div class="tmc-field__control">
                <select id="tmc-field-<?php echo esc_attr($f['environment']); ?>"
                        name="<?php echo esc_attr($vm['option']); ?>[<?php echo esc_attr($f['environment']); ?>]"
                        aria-describedby="tmc-desc-environment" class="tmc-select"
                        <?php echo $invalid($f['environment']) ? 'aria-invalid="true"' : ''; ?>>
                    <?php foreach ($vm['environment_values'] as $value) :
                        $label = match ($value) {
                            'auto' => __('خودکار (تشخیص WordPress)', 'tecteb-marketplace-core'),
                            'staging' => __('آزمایشی (staging)', 'tecteb-marketplace-core'),
                            'production' => __('اصلی (production)', 'tecteb-marketplace-core'),
                            default => $value,
                        }; ?>
                        <option value="<?php echo esc_attr($value); ?>"<?php selected($vm['environment_override'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p id="tmc-desc-environment" class="tmc-field__desc">
                <?php esc_html_e('ثابت TMC_ENVIRONMENT در صورت تعریف بر این گزینه مقدم است. ارسال‌های خود افزونه در نسخه آزمایشی همیشه بسته است؛ انتخاب «اصلی» این محدودیت را دور نمی‌زند.', 'tecteb-marketplace-core'); ?>
            </p>
        </div>
    </fieldset>

    <div class="tmc-actions">
        <button type="submit" class="tmc-button tmc-button--primary"><?php esc_html_e('ذخیره تنظیمات', 'tecteb-marketplace-core'); ?></button>
    </div>
</form>
<?php echo Components::shellClose();
