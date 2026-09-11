<?php
declare(strict_types=1);

use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Admin\Presentation\Messages;

/** @var array<string,mixed> $vm */
echo Components::shellOpen(__('ماژول‌های بازارگاه', 'tecteb-marketplace-core'), 'tmc-modules', __('نسخه آزمایشی', 'tecteb-marketplace-core'));
$report = $vm['report'];
if ($report === null) {
    echo Components::notice('warning', __('گزارش بارگذاری ماژول‌ها هنوز در دسترس نیست.', 'tecteb-marketplace-core'));
    echo Components::shellClose();
    return;
}
?>
<p class="tmc-lead"><?php esc_html_e('وضعیت هر ماژول همان چیزی است که هنگام بارگذاری رخ داده است. ماژول‌های برنامه‌ریزی‌شده کد ندارند و دکمه فعال‌سازی ندارند.', 'tecteb-marketplace-core'); ?></p>
<?php foreach ($report->registryErrors() as $err) : ?>
    <?php echo Components::notice('error', sprintf(__('خطای ثبت ماژول «%1$s»: %2$s', 'tecteb-marketplace-core'), $err['id'], $err['reason'])); ?>
<?php endforeach; ?>
<ul class="tmc-cards">
<?php foreach ($report->states() as $state) :
    $badge = match ($state->status) {
        ModuleStatus::Active => Components::badge('success', '✓', Messages::moduleStatus($state->status)),
        ModuleStatus::Degraded => Components::badge('error', '✕', Messages::moduleStatus($state->status)),
        ModuleStatus::Blocked => Components::badge('warning', '!', Messages::moduleStatus($state->status)),
        ModuleStatus::Planned => Components::badge('neutral', '…', Messages::moduleStatus($state->status)),
    };
    $reason = Messages::moduleReason($state->reasonCode, $state->reasonDetail, $state->phase);
    // Technical facts a manager does not need in order to read the page. They
    // stay one click away instead of pushing the readable text into a corner.
    $tech = array_values(array_filter([
        ['label' => __('شناسه', 'tecteb-marketplace-core'), 'value' => Components::code($state->id()), 'raw' => true],
        ['label' => __('نسخه', 'tecteb-marketplace-core'), 'value' => Components::code($state->manifest->version), 'raw' => true],
        $state->manifest->dependencies === []
            ? ['label' => __('وابستگی‌ها', 'tecteb-marketplace-core'), 'value' => __('ندارد', 'tecteb-marketplace-core')]
            : ['label' => __('وابستگی‌ها', 'tecteb-marketplace-core'), 'value' => Components::codes($state->manifest->dependencies), 'raw' => true],
        $state->manifest->requiresWooCommerce ? ['label' => __('نیازمند WooCommerce', 'tecteb-marketplace-core'), 'value' => __('بله', 'tecteb-marketplace-core')] : null,
    ]));
    ?>
    <li class="tmc-card tmc-module" data-status="<?php echo esc_attr($state->status->value); ?>" aria-labelledby="tmc-mod-<?php echo esc_attr($state->id()); ?>">
        <div class="tmc-module__head">
            <h2 id="tmc-mod-<?php echo esc_attr($state->id()); ?>" class="tmc-card__title"><?php echo esc_html($state->manifest->label); ?></h2>
            <?php echo $badge; ?>
        </div>
        <?php if ($state->manifest->description !== '') : ?>
            <p class="tmc-module__summary"><?php echo esc_html($state->manifest->description); ?></p>
        <?php endif; ?>
        <?php if ($reason !== '') : ?>
            <p class="tmc-module__reason"><?php echo esc_html($reason); ?></p>
        <?php endif; ?>
        <?php if ($state->status === ModuleStatus::Planned) : ?>
            <p class="tmc-module__reason"><?php esc_html_e('فعال‌سازی در این نسخه ممکن نیست؛ این ماژول در نقشه راه است.', 'tecteb-marketplace-core'); ?></p>
        <?php endif; ?>
        <p class="tmc-module__kind"><?php echo esc_html(sprintf(__('نوع: %s', 'tecteb-marketplace-core'), Messages::moduleKind($state->kind()))); ?></p>
        <?php echo Components::techDetails($tech); ?>
    </li>
<?php endforeach; ?>
</ul>
<p><a href="<?php echo esc_url($vm['health_url']); ?>"><?php esc_html_e('وضعیت کلی در صفحه سلامت', 'tecteb-marketplace-core'); ?></a></p>
<?php echo Components::shellClose();
