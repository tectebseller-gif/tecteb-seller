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
    ?>
    <li class="tmc-card tmc-module" aria-labelledby="tmc-mod-<?php echo esc_attr($state->id()); ?>">
        <h2 id="tmc-mod-<?php echo esc_attr($state->id()); ?>" class="tmc-card__title"><?php echo esc_html($state->manifest->label); ?></h2>
        <?php echo $badge; ?>
        <?php echo Components::dataList(array_values(array_filter([
            ['label' => __('شناسه', 'tecteb-marketplace-core'), 'value' => Components::bdi($state->id()), 'raw' => true],
            ['label' => __('نسخه', 'tecteb-marketplace-core'), 'value' => Components::bdi($state->manifest->version), 'raw' => true],
            ['label' => __('نوع', 'tecteb-marketplace-core'), 'value' => Messages::moduleKind($state->kind())],
            ['label' => __('وابستگی‌ها', 'tecteb-marketplace-core'), 'value' => $state->manifest->dependencies === [] ? __('ندارد', 'tecteb-marketplace-core') : implode('، ', $state->manifest->dependencies)],
            $state->manifest->requiresWooCommerce ? ['label' => __('نیازمند WooCommerce', 'tecteb-marketplace-core'), 'value' => __('بله', 'tecteb-marketplace-core')] : null,
            $state->manifest->description !== '' ? ['label' => __('شرح', 'tecteb-marketplace-core'), 'value' => $state->manifest->description] : null,
            $reason !== '' ? ['label' => __('علت', 'tecteb-marketplace-core'), 'value' => $reason] : null,
            $state->status === ModuleStatus::Planned ? ['label' => __('فعال‌سازی', 'tecteb-marketplace-core'), 'value' => __('در این نسخه ممکن نیست (نقشه راه)', 'tecteb-marketplace-core')] : null,
        ]))); ?>
    </li>
<?php endforeach; ?>
</ul>
<p><a href="<?php echo esc_url($vm['health_url']); ?>"><?php esc_html_e('وضعیت کلی در صفحه سلامت', 'tecteb-marketplace-core'); ?></a></p>
<?php echo Components::shellClose();
