<?php
declare(strict_types=1);

use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Core\Support\JalaliDate;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Admin\Presentation\Messages;
use Tecteb\Marketplace\Modules\Health\Application\HealthStatus;

/** @var array<string,mixed> $vm */
echo Components::shellOpen(__('سلامت بازارگاه', 'tecteb-marketplace-core'), 'tmc-health', __('نسخه آزمایشی', 'tecteb-marketplace-core'));

if (!$vm['available']) {
    $state = $vm['module_state'];
    $reason = $state ? Messages::moduleReason($state->reasonCode, $state->reasonDetail, $state->phase) : '';
    echo Components::notice('warning', __('ماژول سلامت فعال نیست، بنابراین گزارش کامل در دسترس نیست.', 'tecteb-marketplace-core') . ($reason !== '' ? ' ' . $reason : ''));
    echo Components::shellClose();
    return;
}

$report = $vm['report'];
$checkedAt = $report->checkedAtUtc;
$jalali = JalaliDate::format($checkedAt);
?>
<div class="tmc-overall">
    <span class="tmc-overall__label"><?php esc_html_e('وضعیت کلی:', 'tecteb-marketplace-core'); ?></span>
    <?php echo Components::healthBadge($report->overallStatus()); ?>
    <span class="tmc-overall__time"><?php esc_html_e('آخرین بررسی:', 'tecteb-marketplace-core'); ?>
        <time datetime="<?php echo esc_attr($checkedAt->format('Y-m-d\TH:i:s\Z')); ?>"><?php echo esc_html($jalali); ?> <?php esc_html_e('(UTC)', 'tecteb-marketplace-core'); ?></time>
    </span>
</div>

<section class="tmc-card" aria-labelledby="tmc-checks-title">
    <h2 id="tmc-checks-title" class="tmc-card__title"><?php esc_html_e('بررسی‌ها', 'tecteb-marketplace-core'); ?></h2>
    <table class="tmc-table">
        <caption class="tmc-sr-only"><?php esc_html_e('فهرست بررسی‌های سلامت با وضعیت و توضیح', 'tecteb-marketplace-core'); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('بررسی', 'tecteb-marketplace-core'); ?></th>
            <th scope="col"><?php esc_html_e('وضعیت', 'tecteb-marketplace-core'); ?></th>
            <th scope="col"><?php esc_html_e('توضیح', 'tecteb-marketplace-core'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($report->checks as $check) : ?>
            <tr>
                <th scope="row" data-label="<?php esc_attr_e('بررسی', 'tecteb-marketplace-core'); ?>"><?php echo esc_html(Messages::healthLabel($check->key)); ?></th>
                <td data-label="<?php esc_attr_e('وضعیت', 'tecteb-marketplace-core'); ?>"><?php echo Components::healthBadge($check->status); ?></td>
                <td data-label="<?php esc_attr_e('توضیح', 'tecteb-marketplace-core'); ?>"><?php echo esc_html(Messages::healthDescription($check->key, $check->status, $check->facts)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="tmc-card" aria-labelledby="tmc-modules-title">
    <h2 id="tmc-modules-title" class="tmc-card__title"><?php esc_html_e('ماژول‌ها', 'tecteb-marketplace-core'); ?></h2>
    <?php if ($vm['load_report'] === null) : ?>
        <p><?php esc_html_e('گزارش بارگذاری ماژول‌ها در دسترس نیست.', 'tecteb-marketplace-core'); ?></p>
    <?php else : ?>
        <ul class="tmc-list">
        <?php foreach ($vm['load_report']->states() as $state) :
            $badge = match ($state->status) {
                ModuleStatus::Active => Components::badge('success', '✓', Messages::moduleStatus($state->status)),
                ModuleStatus::Degraded => Components::badge('error', '✕', Messages::moduleStatus($state->status)),
                ModuleStatus::Blocked => Components::badge('warning', '!', Messages::moduleStatus($state->status)),
                ModuleStatus::Planned => Components::badge('neutral', '…', Messages::moduleStatus($state->status)),
            };
            $reason = Messages::moduleReason($state->reasonCode, $state->reasonDetail, $state->phase);
            ?>
            <li class="tmc-list__item">
                <span class="tmc-list__name"><?php echo esc_html($state->manifest->label); ?> <?php echo Components::bdi($state->id()); ?></span>
                <?php echo $badge; ?>
                <?php if ($reason !== '') : ?><span class="tmc-list__reason"><?php echo esc_html($reason); ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php foreach ($vm['load_report']->registryErrors() as $err) : ?>
            <?php echo Components::notice('error', sprintf(__('خطای ثبت ماژول «%1$s»: %2$s', 'tecteb-marketplace-core'), $err['id'], $err['reason'])); ?>
        <?php endforeach; ?>
        <p><a href="<?php echo esc_url($vm['modules_url']); ?>"><?php esc_html_e('جزئیات در صفحه ماژول‌ها', 'tecteb-marketplace-core'); ?></a></p>
    <?php endif; ?>
</section>

<section class="tmc-card tmc-card--muted" aria-labelledby="tmc-rest-title">
    <h2 id="tmc-rest-title" class="tmc-card__title"><?php esc_html_e('endpoint خصوصی سلامت', 'tecteb-marketplace-core'); ?></h2>
    <p><?php esc_html_e('مسیر:', 'tecteb-marketplace-core'); ?> <?php echo Components::bdi($vm['rest_path']); ?></p>
    <p><?php esc_html_e('فقط با مجوز tmc_view_health و nonce معتبر REST پاسخ می‌دهد؛ nonce به‌تنهایی مجوز نیست. پاسخ cache نمی‌شود و هیچ مسیر سرور، کاربر یا خطای خامی در آن نیست.', 'tecteb-marketplace-core'); ?></p>
    <p><?php echo esc_html(sprintf(__('نسخه افزونه %s', 'tecteb-marketplace-core'), '')); ?><?php echo Components::bdi($report->pluginVersion); ?></p>
</section>
<?php echo Components::shellClose();
