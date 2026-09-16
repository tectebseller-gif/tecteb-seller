# قرارداد اتصال «دروازه» (Kamangir Smart Login) — بررسی، و چرا Adapter ارسال نمی‌کند

مرجع: `tecteb-gateway-reference.zip`
SHA256 `17b37ede69d2d84fdd4ec65bdc97d5f75909eb275519f6eee7c3c32df6e50a4f` —
همان مقداری که گزارش بررسی نوشته بود، پس آرشیو سالم به workspace رسیده است.
۱۵۳۸ ورودی، ریشهٔ `kamangir-smart-login/`.

هدر افزونه: **«دروازه»**، نسخه **۲٫۲٫۳٫۱**، Text Domain `ak-sm-sdk`،
`Requires PHP: 7.4`، `Tested up to: 6.8`.

**کد مرجع اجرا نشد، کپی نشد، و بخش لایسنسش دست نخورد.** آنچه زیر آمده از
**خواندن** به دست آمده: هدر افزونه، `libs/composer.json`، و فایل‌های JavaScript
که رمزنگاری نشده‌اند.

## ۱. یافتهٔ تعیین‌کننده: قرارداد PHP وجود ندارد

هر ۱۶۲ فایل PHP خود افزونه — و هر دو ماژول SDK همراهش
(`kamangir/sdk-core`, `kamangir/sdk-module-sms`) — با **SourceGuardian**
رمزنگاری شده‌اند. هر فایل با stub ای شروع می‌شود که `sg_load` را می‌سازد و
loader افزونهٔ `ixed` را برای دقیقاً همان نسخهٔ PHP لازم دارد.

اندازه‌گیری، نه فرض:

```
$ for f in $(find src kamangir -name "*.php"); do
      head -c 300 "$f" | grep -q "sg_load" && echo ENC || echo PLN
  done | sort | uniq -c
    162 ENC

$ grep -rhoP "do_action\(\s*['\"][^'\"]+" --include="*.php" src/ | sort -u
(هیچ)
$ grep -rhoP "apply_filters\(\s*['\"][^'\"]+" --include="*.php" src/ | sort -u
(هیچ)
```

**پس هیچ hook، فیلتر، امضای تابع یا کلید گزینه‌ای قابل خواندن نیست** — نه چون
جست‌وجو ناقص بوده، بلکه چون در بایت‌های رمزنگاری‌شده چیزی برای پیدا کردن نیست.

این دو پیامد عملی دارد:

1. **`OtpProviderInterface` (CORE-09 / OTP-01) می‌گوید Adapter فقط روی یک API
   عمومیِ مستند نوشته می‌شود.** این افزونه هیچ API عمومی‌ای منتشر نمی‌کند.
2. اجرا شدنش روی سایت به **افزونهٔ loader سازگار با نسخهٔ PHP همان سرور**
   وابسته است. این یک وابستگی محیطی است که باید در ماتریس سازگاری بیاید.

## ۲. قراردادی که **قابل مشاهده است** — از JavaScript خودِ افزونه

JS رمزنگاری نشده، پس سیمِ سمتِ مرورگر کاملاً خواناست:

### ترابری

یک POST به `ksmAjaxData.ajax_url` با:

| فیلد | مقدار |
|---|---|
| `action` | `ksmAjaxData.action` — یک action واحد برای همهٔ عملیات |
| `security` | nonce |
| `operation` | زیرفرمان |

پاسخ در قالب `wp_send_json_*` خودِ وردپرس؛ JS مقدار `responseJSON.data` را
می‌خواند.

### زیرفرمان‌ها

| `operation` | ورودی | خروجی |
|---|---|---|
| `send_handler` | `receiver`, `key_action` | `{send: bool, message, mobile?, code?}` — `code` می‌تواند `otp_already_sent` باشد |
| `mv_verifyotp` | `mobile`, `otp` | `{status: 'success'\|…, message}` |
| `wc_integration_verify_otp` | `receiver`, `otp` | `{success: bool, message}` |

در مسیر checkout، پس از تأیید، یک فیلد مخفی `ksm-otp` با کدِ تأییدشده به فرم
پرداخت ووکامرس اضافه می‌شود، و شمارهٔ تأییدشده در
`localStorage['ksm_wccheckout']` می‌ماند.

### پیکربندی سمت مرورگر

`KsmOtpConfig` شامل `otp_length` و `resend_timer`. سه کد منفی (`-1`, `-2`, `-3`)
throttle سمت مرورگرند، نه پاسخ سرور.

### تنها نقاط اتصال عمدی

دو رویداد jQuery روی `document`:

- `ksm/otp/confirm/{operatorName}` با `[form, receiver, otp, handler]`
- `ksm/otp/back/{operatorName}`

این‌ها **عمداً** برای شخص ثالث fire می‌شوند و تنها چیزی در کل بسته‌اند که
می‌شود اسمشان را «نقطهٔ توسعه» گذاشت. ولی در مرورگرند، نه در PHP.

### وابستگی‌ها (از `libs/composer.json`)

`kamangir/sdk-core` ۲٫۴٫۸ · `kamangir/sdk-module-sms` ۱٫۳٫۰ ·
`kamangir/sdk-module-date` ۱٫۰٫۱ · `nesbot/carbon` · `morilog/jalali` ·
`econea/nusoap` (یعنی دست‌کم یکی از درگاه‌های پیامک SOAP است) ·
`beberlei/assert`.

## ۳. چرا دانستن همهٔ این‌ها باز هم به معنی ارسال نیست

هر کدام از آن عملیات، رابط **خصوصی** افزونه با JavaScript خودش است، پشت nonce ای
که برای یک نشست مرورگر ساخته شده. صدا زدنشان از PHP یعنی:

- **جعل آن nonce**، و
- **تکیه بر نام‌های داخلیِ operation** که هیچ‌وقت تعهدی به حفظشان داده نشده.

شکلی که روزِ نوشتنش کار می‌کند و با انتشار بعدیِ آن‌ها بی‌صدا می‌شکند — در همان
جریانی که تصمیم می‌گیرد کسی بتواند وارد شود یا نه. و افزونه‌ای که به endpoint
خصوصیِ افزونهٔ دیگری دست می‌زند، همان کاری است که این پروژه با دکان نمی‌کند و
اینجا هم نمی‌کند.

## ۴. طراحیِ Adapter: `KamangirSmartLoginAdapter`

`src/Infrastructure/Otp/KamangirSmartLoginAdapter.php`

- `sendChallenge()` و `verifyChallenge()` هر دو `OtpStatus::Unavailable`
  برمی‌گردانند — **چه افزونه نصب و فعال باشد چه نباشد**.
- `probe()` می‌گوید نصب هست یا نه، فعال هست یا نه، نسخه چیست، و `usable` که
  **هیچ شاخه‌ای از آن true برنمی‌گرداند**، با `reason: no_published_php_contract`.
  مدیری که «دروازه نصب است ولی استفاده نمی‌شود» را می‌بیند حق دارد بداند این یک
  ردِ عمدی با علت نام‌دار است، نه تنظیمی که یادش رفته.
- `observedContract()` همین سند را به‌صورت داده برمی‌گرداند، برای صفحهٔ سلامت و
  تحویل بعدی.

آزمون (`tests/Unit/Infrastructure/OtpAdapterTest.php`) چهار چیز را می‌سنجد، و
سومی مهم‌ترین است: **نبودِ ارسال به کد گره خورده، نه به یک تنظیم.** آزمون
سورس کلاس را می‌خواند (پس از حذف docblock ها، چون آن‌ها قرارداد را *توصیف*
می‌کنند) و مطمئن می‌شود هیچ‌کدام از این‌ها در آن نیست: `wp_remote_post`,
`wp_remote_get`, `wp_remote_request`, `curl_exec`, `fsockopen`,
`stream_socket_client`, `admin-ajax.php`, `do_action`, `apply_filters`.

## ۵. آنچه برای اتصال واقعی لازم است

به ترتیب اهمیت:

1. **یک قرارداد PHP منتشرشده از سازندهٔ افزونه** — دست‌کم: یک تابع یا hook برای
   «کد بفرست» و یکی برای «کد را تأیید کن»، با تعهد سازگاری. بدون این، هیچ
   Adapter ای نوشته نمی‌شود که فردا هم کار کند.
2. **تصمیم مالک دربارهٔ اینکه اصلاً OTP بازارگاه از این افزونه بیاید یا نه** —
   ممکن است جواب «نه، مستقیم از یک درگاه پیامک» باشد، که مسیر سرراست‌تری است.
3. **مجوز ارسال واقعی** — قفل `OutboundPolicy` در این نسخه غیرقابل بازکردن است
   و بازکردنش یک تصمیم است، نه یک تنظیم.
4. **نسخهٔ PHP سرور و افزونهٔ `ixed` سازگار با آن** — تا خودِ «دروازه» اجرا شود.

تا آن موقع `NullOtpProvider` تنها provider ای است که در ZIP bind می‌شود، و
**تأیید موبایل انجام نمی‌شود**.
