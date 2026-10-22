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

/**
 * «دکمهٔ بازارگاه در صفحهٔ حساب کاربری» — drawn whether or not the health
 * module is up.
 *
 * A closure rather than two copies of the markup, and defined before the
 * early return rather than after it: this answer does not come from the
 * health module at all, and an owner whose health module is off is exactly
 * the owner who needs to know why a button is missing.
 */
/**
 * «حد خریدی که بی‌صدا از بین رفته» — a Min Max grant that no longer
 * describes the rule on its product.
 *
 * The grant is the fingerprint of the three values it was given for, so a
 * rule edited afterwards leaves it alive and dead and the product allows any
 * quantity. The Min Max exit page says this above its own table; this is the
 * same sentence for a manager who has not opened that page.
 *
 * `null` means the Migration module is not loaded, which is a different
 * answer from «none» and is said as one.
 */
$staleGrants = static function () use ($vm): string {
    $ids = $vm['stale_grants'] ?? null;
    $html = '<section class="tmc-card" aria-labelledby="tmc-stale-grants-title">'
        . '<h2 id="tmc-stale-grants-title" class="tmc-card__title">'
        . esc_html__('حدهای خرید منتقل‌شده از Min Max', 'tecteb-marketplace-core') . '</h2>';
    if ($ids === null) {
        return $html . '<p>'
            . esc_html__('ماژول مهاجرت بارگذاری نشده، پس این وضعیت خوانده نشد. این «هیچ موردی نیست» نیست.', 'tecteb-marketplace-core')
            . '</p></section>';
    }
    if ($ids === []) {
        return $html . '<p>'
            . esc_html__('هر مجوزی که صادر شده هنوز دربارهٔ همان قاعده‌ای است که برایش صادر شد.', 'tecteb-marketplace-core')
            . '</p></section>';
    }
    return $html . Components::notice('warning', sprintf(
        /* translators: 1: how many products, 2: their ids */
        __('%1$s محصول مجوز اعمال قاعده دارند ولی قاعده‌شان پس از صدور مجوز عوض شده، پس همین حالا هیچ حد خریدی روی آن‌ها اعمال نمی‌شود: %2$s. در صفحهٔ «خروج از Min Max» قاعده را به همان مقداری برگردانید که منتقل شده بود، یا بازگشت بزنید و دوباره منتقل و مجوز کنید. صدور دوبارهٔ مجوز روی قاعدهٔ عوض‌شده رد می‌شود.', 'tecteb-marketplace-core'),
        PersianDigits::toPersian((string) count($ids)),
        PersianDigits::toPersian(implode('، ', array_map('strval', $ids)))
    )) . '</section>';
};

/**
 * THE ROLLBACK GATE, as a count. Shown here because an operator deciding
 * whether to go back to an earlier release looks at this screen, and because
 * the previous release's answer to that question was «inspect the rows» —
 * which the owner rejected and which nobody can verify (`alpha.68` §4).
 */
$rollbackGate = static function () use ($vm): string {
    $gate = $vm['rollback_gate'] ?? null;
    $html = '<section class="tmc-card" aria-labelledby="tmc-rollback-gate-title">'
        . '<h2 id="tmc-rollback-gate-title" class="tmc-card__title">'
        . esc_html__('بازگشت به نسخهٔ پیش از alpha.67', 'tecteb-marketplace-core') . '</h2>';
    if (!is_array($gate)) {
        return $html . '<p>'
            . esc_html__('ماژول مالی بارگذاری نشده، پس این وضعیت خوانده نشد. این «مشکلی نیست» نیست.', 'tecteb-marketplace-core')
            . '</p></section>';
    }
    $counts = [];
    foreach ((array) ($gate['counts'] ?? []) as $table => $count) {
        $counts[] = (string) $table . ': ' . ($count === null
            ? __('خوانده نشد', 'tecteb-marketplace-core')
            : PersianDigits::toPersian((string) (int) $count));
    }
    $detail = '<p class="tmc-hint">' . esc_html(implode(' · ', $counts)) . '</p>';
    $verdict = (string) ($gate['verdict'] ?? '');
    if ($verdict === 'safe') {
        return $html . Components::notice('success', __('هیچ ردیفی در واحدی ریزتر از واحد ثبت‌شدهٔ فروشنده‌اش نیست، پس بازگشت به بستهٔ قبلی هیچ مبلغی را بازتفسیر نمی‌کند. این یک شمارش است، نه یک بررسی چشمی.', 'tecteb-marketplace-core'))
            . $detail . '</section>';
    }
    if ($verdict === 'unknown') {
        return $html . Components::notice('warning', __('یکی از جدول‌های مالی خوانده نشد، پس پاسخ این پرسش معلوم نیست — و «معلوم نیست» با «امن است» یکی نیست. تا رفع این خطا بازگشت انجام نشود.', 'tecteb-marketplace-core'))
            . $detail . '</section>';
    }
    return $html . Components::notice('warning', sprintf(
        /* translators: %s: how many rows */
        __('⛔ بازگشت سادهٔ بسته ممنوع است: %s ردیف در واحدی ریزتر از واحد ثبت‌شدهٔ فروشنده‌اش ثبت شده‌اند و بستهٔ قبلی هر کدام را ده‌هزار برابر می‌خواند. تنها مسیر سازگار، بازگرداندن پشتیبان پیش از ارتقاست — و بازگرداندن پشتیبان کل دیتابیس را برمی‌گرداند: سفارش‌ها، کاربران، محصولات و هر ویرایشِ پس از پشتیبان هم از بین می‌روند. بند ۴۹ سند ارتقا می‌گوید چه چیزی شمرده می‌شود و چه چیزی شمرده نمی‌شود.', 'tecteb-marketplace-core'),
        PersianDigits::toPersian((string) array_sum(array_map('intval', (array) ($gate['counts'] ?? []))))
    )) . $detail . '</section>';
};

$accountHooks = static function () use ($vm): string {
    $seen = $vm['account_hooks'] ?? [];
    $answer = static fn (string $value): string => match ($value) {
        'yes' => __('اجرا شد', 'tecteb-marketplace-core'),
        'no' => __('اجرا شد و چیزی چاپ نشد', 'tecteb-marketplace-core'),
        default => __('تا حالا اجرا نشده', 'tecteb-marketplace-core'),
    };
    $html = '<section class="tmc-card" aria-labelledby="tmc-account-hooks-title">'
        . '<h2 id="tmc-account-hooks-title" class="tmc-card__title">'
        . esc_html__('دکمهٔ بازارگاه در صفحهٔ حساب کاربری', 'tecteb-marketplace-core') . '</h2>'
        . '<p>' . esc_html__('این افزونه نمی‌تواند قالب سایت شما را بخواند، پس این را خودِ سایت اندازه می‌گیرد: آخرین بار که یک صفحهٔ حساب کاربری باز شد، کدام قلاب‌های ووکامرس اجرا شدند و آیا دکمهٔ ما چاپ شد.', 'tecteb-marketplace-core') . '</p>'
        . Components::dataList([
            [
                'label' => __('منوی حساب کاربری ووکامرس', 'tecteb-marketplace-core'),
                'value' => $answer((string) ($seen['menu'] ?? '')),
            ],
            [
                'label' => __('پیشخوان حساب کاربری ووکامرس', 'tecteb-marketplace-core'),
                'value' => $answer((string) ($seen['dashboard'] ?? '')),
            ],
            [
                'label' => __('کد کوتاه [tmc_vendor_dashboard]', 'tecteb-marketplace-core'),
                'value' => $answer((string) ($seen['shortcode'] ?? '')),
            ],
            [
                'label' => __('دکمه چاپ شد؟', 'tecteb-marketplace-core'),
                'value' => match ((string) ($seen['printed'] ?? '')) {
                    'yes' => sprintf(
                        /* translators: %s: the label that was printed */
                        __('بله — «%s»', 'tecteb-marketplace-core'),
                        (string) ($seen['label'] ?? '')
                    ),
                    'no' => __('نه، چون آن کاربر نه فروشنده است، نه پرسنل، نه متقاضی و نه مدیر بازارگاه. این یک جواب است، نه خطا.', 'tecteb-marketplace-core'),
                    default => __('تا حالا به این نقطه نرسیده', 'tecteb-marketplace-core'),
                },
            ],
            [
                // The question the owner's report was actually about: the
                // button was there and went to the WRONG PLACE. «پنل
                // فروشنده» is this plugin's own front-end dashboard;
                // «پیشخوان مدیریت» is wp-admin, and an account sees that
                // one only when it has no shop in this marketplace.
                'label' => __('مقصد دکمه', 'tecteb-marketplace-core'),
                'value' => match ((string) ($seen['kind'] ?? '')) {
                    'vendor' => __('داشبورد فروشندهٔ همین افزونه', 'tecteb-marketplace-core'),
                    'admin' => __('پیشخوان مدیریت وردپرس — این حساب فروشگاهی در بازارگاه ندارد', 'tecteb-marketplace-core'),
                    default => '—',
                },
            ],
            [
                'label' => __('آخرین تغییر', 'tecteb-marketplace-core'),
                'value' => (string) ($seen['at'] ?? '') !== ''
                    ? PersianDigits::toPersian(substr((string) $seen['at'], 0, 16))
                    : '—',
            ],
        ]);
    if ((string) ($seen['menu'] ?? '') === '' && (string) ($seen['dashboard'] ?? '') === '') {
        $html .= Components::notice('warning', __('هیچ‌کدام از دو قلاب ووکامرس تا حالا اجرا نشده‌اند. یا کسی صفحهٔ حساب کاربری را باز نکرده، یا قالب سایت آن صفحه را خودش می‌سازد و این قلاب‌ها را صدا نمی‌زند. در حالت دوم، کد کوتاه [tmc_vendor_dashboard] را در محتوای همان صفحه بگذارید — همان دکمه، همان مقصد.', 'tecteb-marketplace-core'));
    }
    // Deliberately not a verdict: «بدون مشاهدهٔ قالب واقعی، سازگاری با آن را
    // «تأییدشده» اعلام نکن».
    return $html . '<p>'
        . esc_html__('این جدول دربارهٔ سازگاری با قالب شما ادعایی نمی‌کند؛ فقط می‌گوید آخرین بار چه شد.', 'tecteb-marketplace-core')
        . '</p></section>';
};

if (!$vm['available']) {
    $state = $vm['module_state'];
    $reason = $state ? Messages::moduleReason($state->reasonCode, $state->reasonDetail, $state->phase) : '';
    echo Components::notice('warning', __('ماژول سلامت فعال نیست، بنابراین گزارش کامل در دسترس نیست.', 'tecteb-marketplace-core') . ($reason !== '' ? ' ' . $reason : ''));
    echo $accountHooks();
    echo $staleGrants();
    echo $rollbackGate();
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
                <span class="tmc-list__name"><?php echo esc_html($state->manifest->label); ?></span>
                <?php echo Components::code($state->id()); ?>
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

<?php echo $accountHooks(); ?>

<?php echo $staleGrants(); ?>
<?php echo $rollbackGate(); ?>

<section class="tmc-card tmc-card--muted" aria-labelledby="tmc-rest-title">
    <h2 id="tmc-rest-title" class="tmc-card__title"><?php esc_html_e('دسترسی برنامه‌نویسی به همین گزارش', 'tecteb-marketplace-core'); ?></h2>
    <p><?php esc_html_e('همان چیزی که در این صفحه می‌بینید، از یک مسیر خصوصی REST هم خواندنی است. برای کار روزمره لازمش ندارید.', 'tecteb-marketplace-core'); ?></p>
    <details class="tmc-tech">
        <summary><?php esc_html_e('جزئیات فنی', 'tecteb-marketplace-core'); ?></summary>
        <?php echo Components::dataList([
            ['label' => __('مسیر', 'tecteb-marketplace-core'), 'value' => Components::code($vm['rest_path']), 'raw' => true],
            ['label' => __('نسخه افزونه', 'tecteb-marketplace-core'), 'value' => Components::code($report->pluginVersion), 'raw' => true],
            ['label' => __('شرط دسترسی', 'tecteb-marketplace-core'), 'value' => __('فقط با مجوز tmc_view_health و nonce معتبر REST پاسخ می‌دهد؛ nonce به‌تنهایی مجوز نیست.', 'tecteb-marketplace-core')],
            ['label' => __('محتوای پاسخ', 'tecteb-marketplace-core'), 'value' => __('cache نمی‌شود و هیچ مسیر سرور، نام کاربر یا خطای خامی در آن نیست.', 'tecteb-marketplace-core')],
        ]); ?>
    </details>
</section>
<?php echo Components::shellClose();
