<?php
declare(strict_types=1);

use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Admin\Presentation\Messages;
use Tecteb\Marketplace\Core\Support\PersianDigits;

/** @var array<string,mixed> $vm */
echo Components::shellOpen(__('پیشخوان بازارگاه تک‌طب', 'tecteb-marketplace-core'), 'tmc-dashboard', __('نسخه آزمایشی', 'tecteb-marketplace-core'));

if (!$vm['woocommerce_available']) {
    echo '<div class="tmc-notice tmc-notice--warning" role="status"><span class="tmc-notice__icon" aria-hidden="true">!</span><div>'
        . '<p class="tmc-notice__text">' . esc_html__('WooCommerce فعال نیست. قابلیت‌های وابسته به WooCommerce راه‌اندازی نشدند؛ صفحه‌های سلامت، تنظیمات و ماژول‌ها برای بررسی وضعیت در دسترس‌اند.', 'tecteb-marketplace-core') . '</p>'
        . '<p><a class="tmc-button tmc-button--secondary" href="' . esc_url($vm['plugins_url']) . '">' . esc_html__('رفتن به صفحه افزونه‌ها', 'tecteb-marketplace-core') . '</a></p>'
        . '</div></div>';
}
if (is_array($vm['migration_error'])) {
    echo Components::notice('error', __('آماده‌سازی ساختار داده ناموفق بوده است. جزئیات در صفحه سلامت؛ فعال‌سازی دوباره افزونه تلاش را از سر می‌گیرد.', 'tecteb-marketplace-core'));
} elseif ($vm['schema_stored'] < $vm['schema_target']) {
    echo Components::notice('warning', __('ساختار داده هنوز کامل نیست. افزونه را دوباره فعال کنید تا آماده‌سازی ادامه یابد.', 'tecteb-marketplace-core'));
}
if ($vm['modules_problems']) {
    echo Components::notice('warning', __('برخی ماژول‌ها فعال نشده‌اند. علت در صفحه ماژول‌ها ثبت شده است.', 'tecteb-marketplace-core'));
}
?>
<section class="tmc-card" aria-labelledby="tmc-env-title">
    <h2 id="tmc-env-title" class="tmc-card__title"><?php esc_html_e('خلاصه محیط', 'tecteb-marketplace-core'); ?></h2>
    <?php
    $env = $vm['environment'];
    echo Components::dataList([
        ['label' => __('نسخه افزونه', 'tecteb-marketplace-core'), 'value' => Components::code($vm['version']), 'raw' => true],
        ['label' => __('محیط تشخیص‌داده‌شده', 'tecteb-marketplace-core'), 'value' => Messages::environmentType($env->type->value) . ' — ' . Messages::environmentSource($env->source)],
        ['label' => __('ارسال‌های خود افزونه (TMC)', 'tecteb-marketplace-core'), 'value' => __('مسدود — هیچ گزینه‌ای این قفل را باز نمی‌کند', 'tecteb-marketplace-core')],
        ['label' => __('ارسال‌های سایر افزونه‌ها', 'tecteb-marketplace-core'), 'value' => __('بررسی‌نشده — باید جداگانه آزموده شود', 'tecteb-marketplace-core')],
        ['label' => __('WooCommerce', 'tecteb-marketplace-core'), 'value' => $vm['woocommerce_available'] ? __('فعال', 'tecteb-marketplace-core') : __('فعال نیست', 'tecteb-marketplace-core')],
        ['label' => __('ساختار داده', 'tecteb-marketplace-core'), 'value' => sprintf(__('نسخه %1$s از %2$s', 'tecteb-marketplace-core'), PersianDigits::toPersian((string) $vm['schema_stored']), PersianDigits::toPersian((string) $vm['schema_target']))],
    ]);
    ?>
</section>
<section class="tmc-card" aria-labelledby="tmc-links-title">
    <h2 id="tmc-links-title" class="tmc-card__title"><?php esc_html_e('بخش‌های این نسخه', 'tecteb-marketplace-core'); ?></h2>
    <ul class="tmc-linkgrid">
        <?php foreach ($vm['links'] as $link) : ?>
            <li><a class="tmc-linkgrid__item" href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a></li>
        <?php endforeach; ?>
    </ul>
</section>
<section class="tmc-card tmc-card--muted" aria-labelledby="tmc-scope-title">
    <h2 id="tmc-scope-title" class="tmc-card__title"><?php esc_html_e('آنچه در این نسخه نیست', 'tecteb-marketplace-core'); ?></h2>
    <p><?php esc_html_e('این نسخه آزمایشی فقط زیرساخت و چهار صفحه مدیریت را دارد. فروشنده، محصول، سفارش، کمیسیون، تسویه، مهاجرت از دکان و هر ارسال واقعی هنوز ساخته نشده‌اند. هیچ آمار فروش یا درآمدی نمایش داده نمی‌شود چون داده‌ای وجود ندارد.', 'tecteb-marketplace-core'); ?></p>
</section>
<?php echo Components::shellClose();
