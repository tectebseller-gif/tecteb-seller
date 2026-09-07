<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Core\Config\Sanitizer\BoundedInteger;
use Tecteb\Marketplace\Core\Config\Sanitizer\EnvironmentOverride;
use Tecteb\Marketplace\Core\Config\Sanitizer\PercentToBasisPoints;
use Tecteb\Marketplace\Core\Config\SettingsSchema;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Health\Application\HealthStatus;

/**
 * The single place where machine codes become Persian, translatable text.
 * Text domain: tecteb-marketplace-core.
 */
final class Messages
{
    public static function forbidden(): string
    {
        return __('شما مجوز تغییر تنظیمات بازارگاه را ندارید. تغییری ذخیره نشد.', 'tecteb-marketplace-core');
    }

    public static function auditFailed(): string
    {
        return __('تنظیمات ذخیره شد، اما ثبت رویداد ممیزی ناموفق بود. این مورد را در صفحه سلامت بررسی کنید.', 'tecteb-marketplace-core');
    }

    public static function saved(): string
    {
        return __('تنظیمات ذخیره شد.', 'tecteb-marketplace-core');
    }

    public static function savedNoChange(): string
    {
        return __('مقادیر تغییری نکرده بود؛ چیزی برای ذخیره نبود.', 'tecteb-marketplace-core');
    }

    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            SettingsSchema::INPUT_COMMISSION_PERCENT => __('درصد کمیسیون عمومی', 'tecteb-marketplace-core'),
            SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS => __('فاصله زمانی تسویه (روز)', 'tecteb-marketplace-core'),
            SettingsSchema::INPUT_MAX_STAFF => __('حداکثر پرسنل هر فروشگاه', 'tecteb-marketplace-core'),
            SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE => __('محیط اجرا', 'tecteb-marketplace-core'),
            default => $field,
        };
    }

    public static function fieldError(string $field, string $code): string
    {
        $label = self::fieldLabel($field);
        $detail = match ($code) {
            PercentToBasisPoints::ERR_RANGE => __('باید بین ۰ و ۱۰۰ باشد.', 'tecteb-marketplace-core'),
            PercentToBasisPoints::ERR_DECIMALS => __('حداکثر دو رقم اعشار مجاز است.', 'tecteb-marketplace-core'),
            PercentToBasisPoints::ERR_FORMAT => __('فقط عدد با حداکثر دو رقم اعشار (مثلاً ۱۲٫۵) مجاز است؛ جداکننده اعشار نقطه یا ممیز فارسی است.', 'tecteb-marketplace-core'),
            PercentToBasisPoints::ERR_TYPE => __('مقدار ارسالی نامعتبر است.', 'tecteb-marketplace-core'),
            BoundedInteger::ERR_EMPTY => __('نمی‌تواند خالی باشد.', 'tecteb-marketplace-core'),
            BoundedInteger::ERR_FORMAT => __('باید عدد صحیح باشد.', 'tecteb-marketplace-core'),
            BoundedInteger::ERR_TYPE => __('مقدار ارسالی نامعتبر است.', 'tecteb-marketplace-core'),
            BoundedInteger::ERR_RANGE => self::rangeText($field),
            EnvironmentOverride::ERR_VALUE => __('گزینه انتخاب‌شده معتبر نیست.', 'tecteb-marketplace-core'),
            default => __('مقدار نامعتبر است.', 'tecteb-marketplace-core'),
        };
        return sprintf(__('%1$s: %2$s مقدار قبلی حفظ شد.', 'tecteb-marketplace-core'), $label, $detail);
    }

    private static function rangeText(string $field): string
    {
        return match ($field) {
            SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS => sprintf(
                __('باید عدد صحیح بین %1$s و %2$s باشد.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) SettingsSchema::SETTLEMENT_DELAY_MIN),
                PersianDigits::toPersian((string) SettingsSchema::SETTLEMENT_DELAY_PROPOSED_TECHNICAL_MAX)
            ),
            SettingsSchema::INPUT_MAX_STAFF => sprintf(
                __('باید عدد صحیح بین %1$s و %2$s باشد.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) SettingsSchema::MAX_STAFF_MIN),
                PersianDigits::toPersian((string) SettingsSchema::MAX_STAFF_PROPOSED_TECHNICAL_MAX)
            ),
            default => __('خارج از بازه مجاز است.', 'tecteb-marketplace-core'),
        };
    }

    public static function moduleStatus(ModuleStatus $status): string
    {
        return match ($status) {
            ModuleStatus::Active => __('فعال', 'tecteb-marketplace-core'),
            ModuleStatus::Degraded => __('ناقص (خطا)', 'tecteb-marketplace-core'),
            ModuleStatus::Blocked => __('متوقف (وابستگی)', 'tecteb-marketplace-core'),
            ModuleStatus::Planned => __('برنامه‌ریزی‌شده', 'tecteb-marketplace-core'),
        };
    }

    public static function moduleKind(ModuleKind $kind): string
    {
        return match ($kind) {
            ModuleKind::Infrastructure => __('زیرساخت', 'tecteb-marketplace-core'),
            ModuleKind::Operational => __('عملیاتی', 'tecteb-marketplace-core'),
            ModuleKind::Planned => __('نقشه راه', 'tecteb-marketplace-core'),
        };
    }

    public static function moduleReason(?string $code, ?string $detail, ?string $phase): string
    {
        if ($code === null) {
            return '';
        }
        $text = match ($code) {
            ModuleLoader::REASON_CYCLE => __('چرخه وابستگی', 'tecteb-marketplace-core'),
            ModuleLoader::REASON_MISSING_DEPENDENCY => __('وابستگی گمشده', 'tecteb-marketplace-core'),
            ModuleLoader::REASON_DEPENDENCY_FAILED => __('وابستگی خراب یا متوقف', 'tecteb-marketplace-core'),
            ModuleLoader::REASON_REQUIRES_WOOCOMMERCE => __('نیازمند WooCommerce است که فعال نیست', 'tecteb-marketplace-core'),
            ModuleLoader::REASON_EXCEPTION => __('خطا هنگام راه‌اندازی', 'tecteb-marketplace-core'),
            default => $code,
        };
        $phaseText = match ($phase) {
            'register' => __('مرحله register', 'tecteb-marketplace-core'),
            'boot' => __('مرحله boot', 'tecteb-marketplace-core'),
            'resolve' => __('مرحله تحلیل وابستگی', 'tecteb-marketplace-core'),
            default => null,
        };
        $parts = [$text];
        if ($phaseText !== null) {
            $parts[] = $phaseText;
        }
        if ($detail !== null && $detail !== '') {
            $parts[] = $detail;
        }
        return implode(' — ', $parts);
    }

    public static function healthStatus(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Healthy => __('سالم', 'tecteb-marketplace-core'),
            HealthStatus::ActionRequired => __('نیازمند اقدام', 'tecteb-marketplace-core'),
            HealthStatus::Unknown => __('نامشخص', 'tecteb-marketplace-core'),
        };
    }

    public static function healthLabel(string $key): string
    {
        return match ($key) {
            'php_version' => __('نسخه PHP', 'tecteb-marketplace-core'),
            'platform_version' => __('نسخه WordPress', 'tecteb-marketplace-core'),
            'woocommerce' => __('WooCommerce', 'tecteb-marketplace-core'),
            'hpos_enabled' => __('HPOS فعال است؟', 'tecteb-marketplace-core'),
            'hpos_tested' => __('HPOS آزموده شده؟', 'tecteb-marketplace-core'),
            'schema' => __('ساختار داده (schema)', 'tecteb-marketplace-core'),
            'environment' => __('محیط اجرا', 'tecteb-marketplace-core'),
            'outbound_tmc' => __('ارسال‌های خود افزونه (TMC)', 'tecteb-marketplace-core'),
            'outbound_other_plugins' => __('ارسال‌های سایر افزونه‌ها', 'tecteb-marketplace-core'),
            'modules' => __('ماژول‌ها', 'tecteb-marketplace-core'),
            'commission_rate' => __('نرخ کمیسیون عمومی', 'tecteb-marketplace-core'),
            default => $key,
        };
    }

    /** @param array<string, int|string|bool|null> $facts */
    public static function healthDescription(string $key, HealthStatus $status, array $facts): string
    {
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);
        switch ($key) {
            case 'php_version':
                return sprintf(__('نسخه %1$s؛ حداقل لازم %2$s.', 'tecteb-marketplace-core'), $fa($facts['version'] ?? '?'), $fa($facts['minimum'] ?? ''));
            case 'platform_version':
                return $facts['version'] === null
                    ? __('نسخه WordPress قابل تشخیص نبود.', 'tecteb-marketplace-core')
                    : sprintf(__('نسخه %s. سازگاری با این نسخه آزموده نشده است.', 'tecteb-marketplace-core'), $fa($facts['version']));
            case 'woocommerce':
                if (!($facts['available'] ?? false)) {
                    return __('WooCommerce فعال نیست. قابلیت‌های وابسته راه‌اندازی نشدند و هیچ عملیات وابسته‌ای اجرا نمی‌شود. برای رفع، WooCommerce را از صفحه افزونه‌ها فعال کنید.', 'tecteb-marketplace-core');
                }
                return sprintf(__('فعال است (نسخه %s). سازگاری با این نسخه آزموده نشده است.', 'tecteb-marketplace-core'), $fa($facts['version'] ?? '?'));
            case 'hpos_enabled':
                if (($facts['enabled'] ?? null) === null) {
                    return __('نامشخص — WooCommerce یا ابزار تشخیص HPOS در دسترس نیست. نامشخص به معنی غیرفعال نیست.', 'tecteb-marketplace-core');
                }
                return $facts['enabled'] ? __('ذخیره‌سازی HPOS فعال است.', 'tecteb-marketplace-core') : __('ذخیره‌سازی HPOS فعال نیست (حالت قدیمی).', 'tecteb-marketplace-core');
            case 'hpos_tested':
                return __('هیچ آزمون سازگاری HPOS اجرا نشده است. «فعال بودن» با «آزموده بودن» یکی نیست.', 'tecteb-marketplace-core');
            case 'schema':
                if (($facts['last_error_step'] ?? null) !== null) {
                    return sprintf(__('آخرین migration ناموفق بود (مرحله %1$s، زمان %2$s): %3$s. فعال‌سازی دوباره افزونه تلاش را از سر می‌گیرد.', 'tecteb-marketplace-core'), (string) $facts['last_error_step'], $fa($facts['last_error_at'] ?? ''), (string) $facts['last_error_message']);
                }
                return sprintf(__('نسخه ذخیره‌شده %1$s از %2$s.', 'tecteb-marketplace-core'), $fa($facts['stored'] ?? 0), $fa($facts['target'] ?? 0))
                    . ((($facts['stored'] ?? 0) < ($facts['target'] ?? 0)) ? ' ' . __('ساختار داده کامل نیست؛ افزونه را دوباره فعال کنید.', 'tecteb-marketplace-core') : '');
            case 'environment':
                $text = sprintf(__('تشخیص: %1$s (منبع: %2$s).', 'tecteb-marketplace-core'), self::environmentType((string) $facts['resolved']), self::environmentSource((string) $facts['source']));
                if (!empty($facts['warnings'])) {
                    $text .= ' ' . __('هشدار:', 'tecteb-marketplace-core') . ' ' . implode('، ', array_map([self::class, 'environmentWarning'], explode(',', (string) $facts['warnings'])));
                }
                if (($facts['hostname_hint'] ?? null) === 'looks_non_production') {
                    $text .= ' ' . __('نام میزبان شبیه محیط غیراصلی است (فقط شاهد کمکی).', 'tecteb-marketplace-core');
                }
                return $text;
            case 'outbound_tmc':
                return __('مسدود. در نسخه آزمایشی هیچ ایمیل، پیامک یا پرداختی از خود افزونه ارسال نمی‌شود؛ هیچ گزینه‌ای این قفل را باز نمی‌کند.', 'tecteb-marketplace-core');
            case 'outbound_other_plugins':
                return __('بررسی‌نشده. ایمنی ارسال پیامک/ایمیل/پرداخت سایر افزونه‌ها باید جداگانه آزموده شود؛ این افزونه درباره آن ادعایی نمی‌کند.', 'tecteb-marketplace-core');
            case 'modules':
                if (!($facts['loaded'] ?? false)) {
                    return __('ماژول‌ها هنوز بارگذاری نشده‌اند.', 'tecteb-marketplace-core');
                }
                return sprintf(__('فعال %1$s، ناقص %2$s، متوقف %3$s، برنامه‌ریزی‌شده %4$s، خطای ثبت %5$s. جزئیات در صفحه ماژول‌ها.', 'tecteb-marketplace-core'), $fa($facts['active']), $fa($facts['degraded']), $fa($facts['blocked']), $fa($facts['planned']), $fa($facts['registry_errors']));
            case 'commission_rate':
                return ($facts['configured'] ?? false)
                    ? __('تعیین شده است.', 'tecteb-marketplace-core')
                    : __('هنوز تعیین نشده. این فقط یک پیام است و مانع فعال بودن افزونه نیست؛ در صفحه تنظیمات قابل تعیین است.', 'tecteb-marketplace-core');
        }
        return '';
    }

    public static function environmentType(string $type): string
    {
        return match ($type) {
            'production' => __('اصلی (production)', 'tecteb-marketplace-core'),
            'staging' => __('آزمایشی (staging)', 'tecteb-marketplace-core'),
            'development' => __('توسعه (development)', 'tecteb-marketplace-core'),
            'local' => __('محلی (local)', 'tecteb-marketplace-core'),
            default => __('نامشخص', 'tecteb-marketplace-core'),
        };
    }

    public static function environmentSource(string $source): string
    {
        return match ($source) {
            'constant' => __('ثابت TMC_ENVIRONMENT', 'tecteb-marketplace-core'),
            'option' => __('تنظیمات افزونه', 'tecteb-marketplace-core'),
            'platform' => __('تشخیص WordPress', 'tecteb-marketplace-core'),
            default => __('هیچ', 'tecteb-marketplace-core'),
        };
    }

    public static function environmentWarning(string $code): string
    {
        return match ($code) {
            'constant_invalid' => __('مقدار ثابت TMC_ENVIRONMENT نامعتبر است و نادیده گرفته شد', 'tecteb-marketplace-core'),
            'option_invalid' => __('مقدار تنظیم محیط نامعتبر است و نادیده گرفته شد', 'tecteb-marketplace-core'),
            'platform_unknown' => __('مقدار محیط WordPress ناشناخته است', 'tecteb-marketplace-core'),
            default => $code,
        };
    }
}
