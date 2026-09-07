# قراردادهای عمومی فاز ۱

هر چیزی که در این فایل آمده، **سطح عمومی** افزونه است: تغییرش شکستن قرارداد
محسوب می‌شود و طبق بخش ۲۰ سند مادر نیازمند شناسه تغییر، دلیل و تأیید مالک است.
هر چیزی که اینجا نیست، جزئیات داخلی است و بدون اعلام تغییر می‌کند.

## ۱. هویت

| مورد | مقدار |
|---|---|
| slug | `tecteb-marketplace-core` |
| main file | `tecteb-marketplace-core.php` |
| namespace | `Tecteb\Marketplace\` |
| prefix | `tmc_` |
| text domain | `tecteb-marketplace-core` |
| نسخه انتشار | `0.1.0-alpha.1` — **موقت**، وابسته به F-01 و تعیین تبار (DEC-06-d) |
| نسخه schema دیتابیس | `1` — مستقل از نسخه انتشار |
| حداقل PHP | 8.1 |

## ۲. Optionها

| کلید | نوع | autoload | توضیح |
|---|---|---|---|
| `tmc_settings` | array | خیر | `['schema_version' => 1, 'values' => [...]]` |
| `tmc_schema_version` | int | خیر | فقط پس از migration موفق نوشته می‌شود |
| `tmc_migration_last_error` | array\|absent | خیر | `['step','message','at']`؛ پس از موفقیت حذف می‌شود |
| `tmc_migration_lock` | string (JSON) | خیر | `{"owner","acquired_at","expires_at"}` — قفل موقت، نه داده |

### مقادیر داخل `tmc_settings['values']`

| کلید ذخیره‌شده | نوع | پیش‌فرض | دامنه | ماهیت |
|---|---|---|---|---|
| `default_commission_rate_bp` | int\|null | `null` | `0..10000` (basis points) | `null` = تعیین‌نشده، `0` = بدون کمیسیون |
| `settlement_delay_days` | int | `4` | `0..365` | صفر = بدون تأخیر زمانی اضافی |
| `max_staff` | int | `10` | `1..100` | — |
| `environment_override` | string | `auto` | `auto\|staging\|production` | قفل outbound را باز نمی‌کند |

**کلید فرم ≠ کلید ذخیره‌شده.** فرم `default_commission_rate` را به‌صورت رشته
درصد می‌فرستد؛ افزونه آن را به `default_commission_rate_bp` صحیح تبدیل می‌کند.
هیچ‌کدام از دو نمایش هرگز به‌جای دیگری تفسیر نمی‌شود.

### حدود فنی پیشنهادی
`SettingsSchema::MAX_STAFF_PROPOSED_TECHNICAL_MAX = 100` و
`SettingsSchema::SETTLEMENT_DELAY_PROPOSED_TECHNICAL_MAX = 365` **حد
اعتبارسنجی ورودی** هستند. قاعده تجاری نیستند، در متن کاربری «حداکثر مجاز
کسب‌وکار» خوانده نمی‌شوند و **وجودشان اثبات ظرفیت آزموده‌شده نیست**. تغییرشان
ویرایش ثابت در یک انتشار نسخه‌دار است؛ در فاز ۱ فیلتر عمومی برایشان وجود ندارد.

## ۳. Capabilityها

| capability | مصرف |
|---|---|
| `tmc_view_dashboard` | منو و صفحه پیشخوان |
| `tmc_view_health` | صفحه سلامت و `GET /tmc/v1/health` |
| `tmc_manage_settings` | صفحه تنظیمات و ذخیره آن |
| `tmc_view_modules` | صفحه ماژول‌ها |

در نصب single-site به نقش `administrator` افزوده می‌شوند. هیچ نقش دیگری
(از جمله نقش‌های فروشنده موجود) لمس نمی‌شود. حذف افزونه آن‌ها را برنمی‌دارد.

## ۴. جدول

`{$wpdb->prefix}tmc_audit_events`

| ستون | نوع | Null |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | خیر |
| `event_type` | VARCHAR(64) | خیر |
| `actor_id` | BIGINT UNSIGNED | **بله** |
| `object_type` | VARCHAR(64) | بله |
| `object_id` | VARCHAR(64) | بله |
| `payload` | LONGTEXT (JSON) | خیر |
| `correlation_id` | CHAR(36) | بله |
| `created_at` | DATETIME (**UTC**) | خیر |

ایندکس‌ها: `tmc_evt_created(event_type, created_at)`،
`tmc_actor_created(actor_id, created_at)`، `tmc_object(object_type, object_id)`.

`actor_id` **داده مستعارشده** است، نه «فاقد داده شخصی» (SEC-02).

### رویدادهای allowlist‌شده

| `event_type` | کلیدهای مجاز payload |
|---|---|
| `settings.updated` | `changed`, `old`, `new` (فقط کلیدهای ذخیره‌شده تنظیمات) |
| `plugin.activated` | `plugin_version`, `schema_version`, `migration_status` |
| `plugin.deactivated` | `plugin_version` |
| `migration.applied` | `from`, `to`, `steps` |
| `migration.failed` | `step`, `message` |

هر کلید دیگری پیش از insert حذف می‌شود. هیچ raw request، ایمیل، شماره، OTP،
token، cookie یا کلیدی ثبت نمی‌شود.

## ۵. REST

`GET /wp-json/tmc/v1/health` — تنها route این فاز.

- `permission_callback` = `tmc_view_health`. بدون آن: 401 برای مهمان،
  403 برای کاربر واردشده. **nonce به‌تنهایی مجوز نیست.**
- سرآیندها: `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`،
  `Pragma: no-cache`، `X-Content-Type-Options: nosniff`.

```json
{
  "schema_version": "1",
  "plugin": { "version": "0.1.0-alpha.1" },
  "environment": { "resolved": "production|staging|development|local|unknown",
                   "source": "constant|option|platform|none" },
  "dependencies": {
    "woocommerce": { "available": false, "version": null },
    "hpos": { "enabled": null }
  },
  "outbound": { "tmc": "blocked", "other_plugins": "unknown" },
  "modules": [ { "id": "core", "status": "active" } ],
  "checked_at": "2026-09-07T08:15:00Z"
}
```

`null` یعنی **نامشخص** و هرگز به `false` تبدیل نمی‌شود.
`modules[].status` ∈ `planned | active | degraded | blocked`.
پاسخ هیچ مسیر سرور، نسخه PHP/WP، فهرست کاربر، credential یا stack trace ندارد.

## ۵٫۱ نتیجه ذخیره تنظیمات

چهار نتیجه از هم جدا هستند و هرگز با هم اشتباه نمی‌شوند:

| کد | نوع | معنی | audit |
|---|---|---|---|
| `tmc_saved` | success | مقادیر واقعاً در پایگاه داده نوشته شدند | بله، با مقادیر واقعی قبل/بعد |
| `tmc_no_change` | info | ارسال معتبر بود ولی چیزی تغییر نکرد | خیر |
| `tmc_save_failed` | **error** | تغییر معتبر بود ولی نوشتن انجام **نشد**؛ مقادیر قبلی دست‌نخورده‌اند | خیر |
| `tmc_audit_failed` | warning | مقادیر ذخیره شدند ولی ثبت ممیزی شکست خورد | تلاش شد و ناموفق بود |
| `tmc_field_<field>` | error | آن فیلد رد شد و مقدار قبلی‌اش حفظ شد | — |
| `tmc_forbidden` | error | بدون `tmc_manage_settings`؛ هیچ تغییری اعمال نشد | خیر |

ردیف `settings.updated` **فقط پس از ذخیره موفق** و از روی مقادیر واقعی خود
option نوشته می‌شود، نه از روی مقادیر ارسالی.

## ۵٫۲ نسخه schema: حالت‌های ممکن

| رابطه | وضعیت سلامت | رفتار migration |
|---|---|---|
| `stored == target` | سالم | هیچ کاری |
| `stored < target` | نیازمند اقدام | مهاجرت اجرا می‌شود (در `admin_init`، نه فقط activation) |
| `stored > target` | **نیازمند اقدام** | **هرگز** اجرا نمی‌شود؛ ساختار پایین آورده نمی‌شود |

مهاجرت دیگر فقط به فعال‌سازی وابسته نیست: به‌روزرسانی فایل‌های افزونه hook
فعال‌سازی را دوباره اجرا نمی‌کند، بنابراین `UpgradeGate` در `admin_init`
نسخه را بررسی می‌کند. مهاجرت شکست‌خورده حداکثر هر ۳۰۰ ثانیه یک بار دوباره
تلاش می‌شود و خطا در تمام مدت روی صفحه سلامت دیده می‌شود.

## ۶. ثابت‌ها و hookها

| مورد | نوع | توضیح |
|---|---|---|
| `TMC_ENVIRONMENT` | ثابت ورودی (اختیاری) | اگر تعریف شود بر تنظیم محیط مقدم است؛ مقدار نامعتبر نادیده گرفته و در سلامت گزارش می‌شود |
| `TMC_PLUGIN_VERSION` | ثابت خروجی | نسخه انتشار |
| `TMC_PLUGIN_FILE` | ثابت خروجی | مسیر main file |
| `option_page_capability_tmc_settings_group` | فیلتر مصرفی | افزونه آن را روی `tmc_manage_settings` تنظیم می‌کند |
| `update_option_tmc_settings` / `add_option_tmc_settings` | action مصرفی | نقطه‌ای که وردپرس تأیید می‌کند مقدار ذخیره شد؛ ممیزی همان‌جا نوشته می‌شود |
| `pre_set_transient_settings_errors` | فیلتر مصرفی | پس از پایان نوشتن‌های options.php، نتیجه نهایی ذخیره تعیین می‌شود |
| `admin_init` | action مصرفی (اولویت ۵) | بررسی و اجرای مهاجرت معلق، مستقل از فعال‌سازی |

در فاز ۱ **هیچ hook عمومی برای شخص ثالث منتشر نمی‌شود**. سطح افزودنی
(`tmc_*` actions/filters) عمداً خالی است تا در فازهای بعد با طراحی معرفی شود.

## ۷. صفحه‌ها

| slug | capability | عنوان |
|---|---|---|
| `tmc-dashboard` | `tmc_view_dashboard` | پیشخوان |
| `tmc-health` | `tmc_view_health` | سلامت |
| `tmc-settings` | `tmc_manage_settings` | تنظیمات |
| `tmc-modules` | `tmc_view_modules` | ماژول‌ها |

## ۸. ماژول‌ها

| id | نوع | وابستگی | وضعیت این نسخه |
|---|---|---|---|
| `core` | زیرساخت | — | active |
| `environment-guard` | زیرساخت | `core` | active |
| `admin` | عملیاتی | `core`, `environment-guard` | active |
| `health` | عملیاتی | `core`, `environment-guard` | active |
| `vendor`, `product`, `order`, `commission`, `settlement`, `migration` | نقشه راه | — | **planned** — بدون کد، بدون hook، بدون دکمه فعال‌سازی |

`admin` و `health` عمداً به WooCommerce وابسته **نیستند**: در نبود WooCommerce
باید در دسترس بمانند تا وضعیت قابل بررسی باشد.
