# نصب، بازگشت و راه رساندن گیت‌های Not Run به Passed — فاز ۱

> ## وضعیت بسته: گیت‌های پذیرش روی WordPress یکبارمصرف قبول شدند
> پروتکل بند ۴ روی یک WordPress یکبارمصرف (WP 7.1 · WC 11.0.1 · MariaDB
> 10.11.14) دو بار اجرا شد — روی PHP 8.1.32 و PHP 8.4.19 — و همه گیت‌ها قبول
> شدند. خروجی خام: `docs/evidence/acceptance/`؛ جمع‌بندی: `docs/phase-1-report.md`
> بند ۳. **نصب روی `tecteb.com` یا `staging.tecteb.com` انجام نشده و در مجوز
> فعلی نیست.** این سند همچنان دستور اجراست: برای هر محیط تازه باید دوباره
> اجرا شود.

## ۱. این بسته چیست و چه نیست

**هست:** زیرساخت فاز ۱ و چهار صفحه مدیریت، به‌همراه یک endpoint خصوصی سلامت.

**نیست:** فروشنده، محصول، سفارش، کمیسیون، تسویه، مهاجرت از دکان، احراز هویت
واقعی، و هر ارسال واقعی. هیچ ایمیل/پیامک/پرداختی از خود افزونه خارج نمی‌شود و
این قفل با هیچ گزینه‌ای باز نمی‌شود.

**درباره سایر افزونه‌ها هیچ ادعایی نمی‌کند.** «ارسال‌های TMC مسدود است» به
معنی «کل سایت امن است» نیست.

## ۲. پیش‌نیازها

| مورد | مقدار |
|---|---|
| PHP | ۸٫۱ یا بالاتر (اجرای واقعی روی ۸٫۱ هنوز آزموده نشده) |
| WordPress | ۶٫۳ — **حداقل پیشنهادی و آزموده‌نشده** (بند ۲٫۱) |
| WooCommerce | لازم نیست؛ در نبودش افزونه در حالت محدود بالا می‌آید |
| Multisite | **پشتیبانی نمی‌شود**؛ فعال‌سازی رد می‌شود |
| Composer/Node روی هاست | لازم نیست |

### ۲٫۱ درباره «WordPress 6.3»

عدد ۶٫۳ در هدر افزونه (`Requires at least`) فعلاً **حداقل پیشنهادی و
آزموده‌نشده** است، نه نتیجه آزمون و نه یک مبنای فنی مستند. هیچ نسخه‌ای از
WordPress در این پروژه اجرا نشده است.

برای اینکه این عدد به «حداقل پشتیبانی‌شده» تبدیل شود، دو چیز لازم است:

1. **مبنای فنی:** فهرست APIهای وردپرسی که افزونه به آن‌ها تکیه می‌کند و نسخه
   معرفی هرکدام، تا پایین‌ترین نسخه ممکن از روی کد محاسبه شود — نه حدس.
2. **آزمون:** اجرای پروتکل بند ۴ روی همان نسخه حداقلی و روی یک نسخه جاری.

تا آن زمان `docs/compatibility-matrix.md` هر نسخه WordPress را `Not Run`
نگه می‌دارد و هیچ ادعای پشتیبانی در هیچ سندی نوشته نمی‌شود.

## ۳. نصب روی یک WordPress **یکبارمصرف** (تنها نصب مجاز فعلی)

«یکبارمصرف» یعنی سایتی که فقط برای همین آزمون ساخته شده، داده مصنوعی دارد و
دور انداختنش هیچ هزینه‌ای ندارد. **Staging عملیاتی یا production در این مجوز
نیست.**

```bash
# ۱. یکپارچگی بسته
sha256sum -c SHA256SUMS

# ۲. نصب از پیشخوان: افزونه‌ها → افزودن → بارگذاری افزونه → tecteb-marketplace-core.zip
#    یا با WP-CLI:
wp plugin install tecteb-marketplace-core.zip
wp plugin activate tecteb-marketplace-core

# ۳. با WP_DEBUG روشن باید هیچ fatal و هیچ warning ای نباشد
#    در wp-config.php: define('WP_DEBUG', true); define('WP_DEBUG_LOG', true);
```

### چه چیزی باید ببینید
1. پیام موفقیت فارسی «بازارگاه تک‌طب فعال شد. ساختار داده آماده است.»
2. منوی «بازارگاه تک‌طب» با دقیقاً چهار زیرصفحه.
3. جدول `{prefix}tmc_audit_events` با یک ردیف `plugin.activated`.
4. `tmc_schema_version = 1` در `wp_options`.
5. بدون WooCommerce: هشدار فارسی و سالم ماندن هر چهار صفحه.

## ۴. پروتکل پذیرش گیت‌ها

> **این بخش چک اولیه نیست.** هر گیت یک آزمون کامل است با پیش‌شرط، اقدام،
> نتیجه مورد انتظار و **مدرک خروجی**. اجرای یک دستور و ندیدن خطا، به‌تنهایی
> هیچ گیتی را `Passed` نمی‌کند.
>
> یک گیت **فقط وقتی** `Passed` می‌شود که هر سه شرط برقرار باشد:
> ۱) فایل مدرک وجود داشته باشد، ۲) محتوایش با «نتیجه مورد انتظار» بخواند، و
> ۳) در `docs/compatibility-matrix.md` با ارجاع به همان فایل ثبت شود.
> هر خروجی مبهم یا ناقص یعنی `Failed`، نه `Passed`.

### ۴٫۰ آماده‌سازی مشترک

> **exit code در دستورهای لوله‌شده:** در `cmd | tee file` مقدار `$?` متعلق به
> `tee` است، نه `cmd`؛ یعنی یک دستور شکست‌خورده «موفق» به نظر می‌رسد. در تمام
> این بخش `set -o pipefail` روشن است تا exit code لوله همان دستور آزمون باشد،
> و هرجا عدد را جدا ثبت می‌کنیم از `${PIPESTATUS[0]}` استفاده می‌شود.

```bash
set -o pipefail          # exit code لوله = اولین دستور شکست‌خورده، نه tee

# سایت یکبارمصرف با داده مصنوعی. Staging عملیاتی یا production مجاز نیست.
SITE="https://<disposable-site>"
EV="$HOME/tmc-gate-evidence"; mkdir -p "$EV"

# WP_DEBUG و لاگ فایل روشن باشد (wp-config.php):
#   define('WP_DEBUG', true);
#   define('WP_DEBUG_LOG', true);
#   define('WP_DEBUG_DISPLAY', false);
wp config get WP_DEBUG WP_DEBUG_LOG > "$EV/00-debug-flags.txt"

# نشست واقعی مدیرکل برای گیت‌هایی که به صفحه‌های wp-admin نیاز دارند.
# رمز را در تاریخچه شل نگذارید: از فایل یا Application Password استفاده کنید.
curl -s -c "$EV/cookies-admin.txt" -o /dev/null \
     --data-urlencode "log=$WP_USER" --data-urlencode "pwd@$HOME/.wp_pass" \
     -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
# تأیید اینکه نشست واقعاً معتبر است (وگرنه همه گیت‌های بعدی بی‌معنا می‌شوند):
curl -s -b "$EV/cookies-admin.txt" -o /dev/null -w '%{http_code}\n' "$SITE/wp-admin/profile.php" \
     | tee "$EV/00-session-check.txt"     # انتظار: 200
```

`cookies-admin.txt` **مدرک نیست و نباید در هیچ مخزنی commit شود.** پس از پایان
آزمون حذفش کنید.

**قاعده بازه خطا:** پیش از هر گیت، `debug.log` را خالی یا نشانه‌گذاری کنید و
پس از گیت فقط همان بازه را بردارید. خطاهای قدیمی سایت به این افزونه نسبت داده
نمی‌شوند و خطاهای این افزونه هم زیر انبوه لاگ قبلی گم نمی‌شوند.

```bash
mark() { date -u +'%Y-%m-%dT%H:%M:%SZ' > "$EV/.mark"; : > wp-content/debug.log; }
slice() { cp wp-content/debug.log "$EV/$1-debug.log"; wc -l < "$EV/$1-debug.log"; }
# ثبت exit code واقعی یک دستور همراه با نگه‌داشتن خروجی آن:
run_ev() { # run_ev <evidence-name> <command…>
  local name="$1"; shift
  "$@" > "$EV/$name.txt" 2>&1
  local code=$?
  echo "exit=$code" >> "$EV/$name.txt"
  cat "$EV/$name.txt"
  return $code
}
```

---

### گیت G-01 — نصب اولیه و فعال‌سازی

| | |
|---|---|
| **پیش‌شرط** | سایت یکبارمصرف؛ افزونه **هرگز فعال نشده**: `wp plugin list` آن را نشان ندهد، `wp option get tmc_schema_version` خطا بدهد، و جدول `{prefix}tmc_audit_events` وجود نداشته باشد. WooCommerce **غیرفعال**. |
| **اقدام** | `mark`؛ نصب ZIP از پیشخوان یا `wp plugin install`؛ سپس یک بار فعال‌سازی. |
| **نتیجه مورد انتظار** | خروج ۰؛ در بازه لاگ **هیچ** `Fatal error`، `Warning`، `Notice` یا `Deprecated` مربوط به `tecteb-marketplace-core`؛ `tmc_schema_version = 1`؛ جدول audit با دقیقاً یک ردیف `plugin.activated`؛ چهار capability فقط روی `administrator`؛ `tmc_settings` با مقادیر پیش‌فرض (`default_commission_rate_bp` برابر `null`، نه `0`). |
| **مدرک** | `G-01-*.txt`, `G-01-debug.log` |

```bash
mark
wp plugin install "$EV/tecteb-marketplace-core.zip" 2>&1 | tee "$EV/G-01-install.txt"
wp plugin activate tecteb-marketplace-core 2>&1 | tee "$EV/G-01-activate.txt"
echo "exit=${PIPESTATUS[0]}" >> "$EV/G-01-activate.txt"   # the command's code, not tee's
slice G-01
grep -Ei 'fatal|warning|notice|deprecated' "$EV/G-01-debug.log" | grep -i tecteb \
  | tee "$EV/G-01-plugin-errors.txt"                    # انتظار: فایل خالی
wp option get tmc_schema_version                 | tee "$EV/G-01-schema.txt"     # انتظار: 1
wp option get tmc_settings --format=json         | tee "$EV/G-01-settings.txt"
wp db query "SELECT event_type, actor_id, created_at FROM $(wp db prefix)tmc_audit_events" \
  | tee "$EV/G-01-audit.txt"                                                     # انتظار: یک ردیف plugin.activated
wp cap list administrator | grep tmc_            | tee "$EV/G-01-caps-admin.txt" # انتظار: چهار مورد
for r in subscriber editor author contributor customer shop_manager seller; do
  echo "== $r: $(wp cap list "$r" 2>/dev/null | grep -c tmc_ || echo 'role absent')"
done | tee "$EV/G-01-caps-other.txt"                                             # انتظار: همه صفر یا absent
```

**درخواست واقعی صفحه‌ها** (وجود نداشتن fatal کافی نیست؛ صفحه باید واقعاً
بیاید و محتوای درست داشته باشد):

```bash
for p in tmc-dashboard tmc-health tmc-settings tmc-modules; do
  code=$(curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-01-page-$p.html" -w '%{http_code}' \
         "$SITE/wp-admin/admin.php?page=$p")
  printf '%s http=%s bytes=%s persian=%s dir_rtl=%s php_error=%s\n' "$p" "$code" \
    "$(wc -c < "$EV/G-01-page-$p.html")" \
    "$(grep -c 'بازارگاه تک‌طب' "$EV/G-01-page-$p.html")" \
    "$(grep -c 'dir="rtl"' "$EV/G-01-page-$p.html")" \
    "$(grep -Eic 'fatal error|<b>Warning</b>|<b>Notice</b>' "$EV/G-01-page-$p.html")"
done | tee "$EV/G-01-pages.txt"
# انتظار برای هر چهار صفحه: http=200، persian≥1، dir_rtl=1، php_error=0
```

**بدون WooCommerce** باید هشدار فارسی دیده شود و هر چهار صفحه سالم بمانند:

```bash
grep -c 'WooCommerce فعال نیست' "$EV/G-01-page-tmc-dashboard.html" | tee "$EV/G-01-wc-notice.txt"  # انتظار: ≥1
```

---

### گیت G-02 — فعال‌سازی مجدد (ایدمپوتنت بودن)

گیت جدا از G-01 است: نصب اولیه و فعال‌سازی دوباره دو رفتار متفاوت‌اند و
نتیجه یکی، دیگری را تأیید نمی‌کند.

| | |
|---|---|
| **پیش‌شرط** | G-01 قبول شده؛ **مقادیر غیرپیش‌فرض** ذخیره شده و **رکورد audit قبلی** موجود باشد (زیر). |
| **اقدام** | `mark`؛ غیرفعال‌سازی، سپس فعال‌سازی دوباره. |
| **نتیجه مورد انتظار** | مقادیر غیرپیش‌فرض **دقیقاً** باقی بمانند؛ تعداد ردیف‌های audit فقط با ردیف‌های جدید (`plugin.deactivated` و `plugin.activated`) زیاد شود و **هیچ ردیف قبلی حذف نشود**؛ `tmc_schema_version` همان ۱ بماند؛ هیچ DDL جدیدی اجرا نشود؛ بازه لاگ خالی از خطای افزونه باشد. |
| **مدرک** | `G-02-before.json`, `G-02-after.json`, `G-02-diff.txt`, `G-02-debug.log` |

```bash
# --- ساخت وضعیت غیرپیش‌فرض (وگرنه آزمون حفظ داده بی‌معناست) ---
# از صفحه تنظیمات با کاربر مدیرکل: کمیسیون ۱۲٫۳۴، تسویه ۹ روز، پرسنل ۳۳، محیط staging.
# سپس بررسی کنید که واقعاً غیرپیش‌فرض شده:
wp option get tmc_settings --format=json | tee "$EV/G-02-before-settings.json"
# انتظار: {"default_commission_rate_bp":1234,"settlement_delay_days":9,"max_staff":33,...}

wp db query "SELECT id, event_type, correlation_id, created_at FROM $(wp db prefix)tmc_audit_events ORDER BY id" \
  > "$EV/G-02-before-audit.txt"
BEFORE_COUNT=$(wp db query "SELECT COUNT(*) FROM $(wp db prefix)tmc_audit_events" --skip-column-names)
BEFORE_MAXID=$(wp db query "SELECT COALESCE(MAX(id),0) FROM $(wp db prefix)tmc_audit_events" --skip-column-names)
echo "count=$BEFORE_COUNT maxid=$BEFORE_MAXID" | tee "$EV/G-02-before.json"

# --- اقدام ---
mark
wp plugin deactivate tecteb-marketplace-core 2>&1 | tee "$EV/G-02-deactivate.txt"
wp plugin activate   tecteb-marketplace-core 2>&1 | tee "$EV/G-02-activate.txt"
slice G-02

# --- مقایسه قبل و بعد ---
wp option get tmc_settings --format=json | tee "$EV/G-02-after-settings.json"
diff "$EV/G-02-before-settings.json" "$EV/G-02-after-settings.json" \
  | tee "$EV/G-02-diff.txt"                       # انتظار: بدون تفاوت
wp db query "SELECT id, event_type, correlation_id, created_at FROM $(wp db prefix)tmc_audit_events ORDER BY id" \
  > "$EV/G-02-after-audit.txt"
# هر ردیف قبلی باید عیناً هنوز باشد:
comm -23 <(sort "$EV/G-02-before-audit.txt") <(sort "$EV/G-02-after-audit.txt") \
  | tee "$EV/G-02-lost-rows.txt"                  # انتظار: فایل خالی
wp db query "SELECT COUNT(*) FROM $(wp db prefix)tmc_audit_events" --skip-column-names \
  | tee "$EV/G-02-after-count.txt"                # انتظار: BEFORE_COUNT + 2
wp option get tmc_schema_version | tee "$EV/G-02-schema.txt"   # انتظار: 1
grep -Ei 'fatal|warning|notice|deprecated' "$EV/G-02-debug.log" | grep -i tecteb \
  | tee "$EV/G-02-plugin-errors.txt"              # انتظار: خالی
```

---

### گیت G-03 — دسترسی UI برای سه هویت

| | |
|---|---|
| **پیش‌شرط** | افزونه فعال. سه کاربر آزمایشی: مهمان (بدون نشست)، `tmc_none` (نقش subscriber، بدون هیچ capability افزونه)، `tmc_admin` (مدیرکل). |
| **اقدام** | درخواست هر چهار صفحه با هر سه هویت. |
| **نتیجه مورد انتظار** | مهمان → ریدایرکت به `wp-login.php` (۳۰۲) یا ۴۰۳؛ کاربر فاقد مجوز → **۴۰۳** با پیام فارسی و **بدون افشای نام capability**؛ مدیرکل → ۲۰۰ با محتوای صفحه. در هیچ حالتی محتوای صفحه برای دو هویت اول نشت نکند. |
| **مدرک** | `G-03-matrix.txt` و فایل بدنه هر درخواست |

```bash
# نشست کاربر فاقد مجوز را جدا بسازید: $EV/cookies-none.txt
for who in guest none admin; do
  case $who in
    guest) COOKIE="/dev/null" ;;
    none)  COOKIE="$EV/cookies-none.txt" ;;
    admin) COOKIE="$EV/cookies-admin.txt" ;;
  esac
  for p in tmc-dashboard tmc-health tmc-settings tmc-modules; do
    out="$EV/G-03-$who-$p.html"
    code=$(curl -s -b "$COOKIE" -o "$out" -w '%{http_code}' "$SITE/wp-admin/admin.php?page=$p")
    # نشانه محتوا: کلاس پوسته صفحه که هر چهار صفحه آن را می‌سازند.
    # نسخه قبلی این راهنما 'tmc-datalist|tmc-form' را می‌گرفت؛ آن دو کلاس فقط
    # در دو صفحه از چهار صفحه هستند، پس یک افزونه سالم روی tmc-health عدد صفر
    # می‌گرفت. (مشاهده‌شده در اجرای پذیرش روی WordPress 7.1.)
    printf '%-5s %-14s http=%s leak_content=%s leak_capname=%s\n' "$who" "$p" "$code" \
      "$(grep -c 'tmc-admin' "$out")" \
      "$(grep -c 'tmc_view_\|tmc_manage_' "$out")"
  done
done | tee "$EV/G-03-matrix.txt"
# انتظار: guest → 302/403؛ none → 403 و leak_content=0 و leak_capname=0؛ admin → 200 و leak_content≥1
#
# نکته: روی صفحه سلامتِ **کاربر مجاز**، leak_capname برابر ۱ است. آن یک جمله
# توضیحی برای خود مدیرکل است («فقط با مجوز tmc_view_health و nonce معتبر REST
# پاسخ می‌دهد…»). قاعده «افشا نشدن نام capability» درباره مهمان و کاربر فاقد
# مجوز است؛ آن دو باید صفر باشند.
```

---

### گیت G-04 — ذخیره تنظیمات: سه هویت + nonce نامعتبر

| | |
|---|---|
| **پیش‌شرط** | مقدار فعلی `max_staff` را یادداشت کنید (مثلاً ۳۳). گیت G-03 اجرا شده باشد: nonce معتبر از فایل `G-03-admin-tmc-settings.html` برداشته می‌شود. |
| **اقدام** | چهار ارسال به `options.php`: مهمان، کاربر فاقد مجوز، مدیرکل با **nonce نامعتبر**، مدیرکل با nonce معتبر. |
| **نتیجه مورد انتظار** | سه حالت اول **هیچ تغییری** در `tmc_settings` ایجاد نکنند و هیچ ردیف `settings.updated` نسازند؛ حالت nonce نامعتبر ۴۰۳ بدهد؛ فقط حالت چهارم ذخیره کند و **یک** ردیف audit بسازد. |
| **مدرک** | `G-04-*.txt` |

```bash
BEFORE=$(wp option get tmc_settings --format=json); echo "$BEFORE" > "$EV/G-04-before.json"
A_BEFORE=$(wp db query "SELECT COUNT(*) FROM $(wp db prefix)tmc_audit_events WHERE event_type='settings.updated'" --skip-column-names)

# nonce معتبر را از خود فرم بردارید (بدون آن، آزمون «nonce معتبر» ساختگی است):
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' "$EV/G-03-admin-tmc-settings.html" | cut -d'"' -f4)

post() { # who cookie nonce label
  curl -s -b "$2" -o "$EV/G-04-$4.html" -w "$4 http=%{http_code}\n" \
    -d "option_page=tmc_settings_group&action=update&_wpnonce=$3" \
    -d "tmc_settings[max_staff]=77" "$SITE/wp-admin/options.php"
}
{
  post guest /dev/null            "$NONCE"  guest
  post none  "$EV/cookies-none.txt" "$NONCE" none
  post admin "$EV/cookies-admin.txt" "forged" badnonce   # انتظار: 403
  post admin "$EV/cookies-admin.txt" "$NONCE" ok         # انتظار: 302 به صفحه تنظیمات
} | tee "$EV/G-04-http.txt"

wp option get tmc_settings --format=json | tee "$EV/G-04-after.json"
# انتظار: max_staff فقط بر اثر ارسال چهارم به 77 رسیده باشد
wp db query "SELECT COUNT(*) FROM $(wp db prefix)tmc_audit_events WHERE event_type='settings.updated'" --skip-column-names \
  | tee "$EV/G-04-audit-count.txt"                       # انتظار: A_BEFORE + 1
wp db query "SELECT payload FROM $(wp db prefix)tmc_audit_events WHERE event_type='settings.updated' ORDER BY id DESC LIMIT 1" \
  | tee "$EV/G-04-audit-payload.txt"
# انتظار: فقط کلیدهای allowlist؛ هیچ رمز، ایمیل، شماره یا raw POST
```

---

### گیت G-05 — REST سلامت: سه هویت + nonce نامعتبر

| | |
|---|---|
| **پیش‌شرط** | افزونه فعال. |
| **اقدام** | `GET /wp-json/tmc/v1/health` با مهمان، کاربر فاقد مجوز، مدیرکل بدون nonce، مدیرکل با nonce نامعتبر، مدیرکل با nonce معتبر. |
| **نتیجه مورد انتظار** | مهمان **۴۰۱**؛ فاقد مجوز **۴۰۳**؛ بدون nonce **۴۰۱**؛ nonce نامعتبر → **۴۰۳** با کد `rest_cookie_invalid_nonce` (این پاسخِ خودِ هسته وردپرس است — `rest_cookie_check_errors()` در `wp-includes/rest-api.php` وضعیت ۴۰۳ می‌دهد و درخواست هرگز به permission_callback افزونه نمی‌رسد؛ نکته اصلی این است که ۲۰۰ نباشد و هیچ داده‌ای برنگردد)؛ مجاز با nonce معتبر **۲۰۰** با schema دقیق، سرآیند `no-store`، و **بدون** مسیر سرور، نسخه PHP/WP، فهرست کاربر یا stack trace. |
| **مدرک** | `G-05-*.json` |

```bash
REST="$SITE/wp-json/tmc/v1/health"
RN=$(curl -s -b "$EV/cookies-admin.txt" "$SITE/wp-admin/admin-ajax.php?action=rest-nonce")

curl -s -o "$EV/G-05-guest.json"    -w 'guest      http=%{http_code}\n' "$REST"
curl -s -b "$EV/cookies-none.txt"  -o "$EV/G-05-none.json"     -w 'none       http=%{http_code}\n' -H "X-WP-Nonce: $RN" "$REST"
curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-05-nononce.json"  -w 'no-nonce   http=%{http_code}\n' "$REST"
curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-05-badnonce.json" -w 'bad-nonce  http=%{http_code}\n' -H 'X-WP-Nonce: forged' "$REST"
curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-05-ok.json" -D "$EV/G-05-ok.headers" \
     -w 'authorized http=%{http_code}\n' -H "X-WP-Nonce: $RN" "$REST"
# همه خطوط بالا را در $EV/G-05-http.txt ذخیره کنید.

grep -i 'cache-control' "$EV/G-05-ok.headers"          # انتظار: شامل no-store
python3 -c "import json,sys; d=json.load(open('$EV/G-05-ok.json'));
print(sorted(d));
assert d['schema_version']=='1' and d['outbound']=={'tmc':'blocked','other_plugins':'unknown'};
print('hpos.enabled =', repr(d['dependencies']['hpos']['enabled']))" | tee "$EV/G-05-schema.txt"
grep -Eic 'wp-content|/var/|/home/|Stack trace|PHP [0-9]|user_email' "$EV/G-05-ok.json" \
  | tee "$EV/G-05-leak.txt"                            # انتظار: 0
```

---

### گیت G-06 — حفظ داده هنگام غیرفعال‌سازی و حذف

| | |
|---|---|
| **پیش‌شرط** | تنظیمات **غیرپیش‌فرض** و **حداقل سه ردیف audit قبلی** موجود باشد. |
| **اقدام** | غیرفعال‌سازی، سپس **حذف افزونه از پیشخوان** (که `uninstall.php` را اجرا می‌کند). |
| **نتیجه مورد انتظار** | پس از حذف: جدول audit **و همه ردیف‌هایش** سرجایشان باشند (هیچ سطری کم نشود؛ افزوده‌شدن ردیف `plugin.deactivated` خودِ این گیت طبیعی است)؛ `tmc_settings` و `tmc_schema_version` دست‌نخورده؛ چهار capability روی مدیرکل باقی؛ هیچ محصول/سفارش/کاربری تغییر نکرده باشد. |
| **مدرک** | `G-06-before.txt`, `G-06-after.txt` |

```bash
{ echo "--- settings ---"; wp option get tmc_settings --format=json
  echo "--- schema ---";   wp option get tmc_schema_version
  echo "--- caps ---";     wp cap list administrator | grep tmc_
  echo "--- audit ---";    wp db query "SELECT id,event_type,correlation_id FROM $(wp db prefix)tmc_audit_events ORDER BY id"
  echo "--- counts ---";   wp post list --format=count; wp user list --format=count
} | tee "$EV/G-06-before.txt"

wp plugin deactivate tecteb-marketplace-core
wp plugin delete tecteb-marketplace-core        # uninstall.php اینجا اجرا می‌شود

{ echo "--- settings ---"; wp option get tmc_settings --format=json
  echo "--- schema ---";   wp option get tmc_schema_version
  echo "--- caps ---";     wp cap list administrator | grep tmc_
  echo "--- audit ---";    wp db query "SELECT id,event_type,correlation_id FROM $(wp db prefix)tmc_audit_events ORDER BY id"
  echo "--- counts ---";   wp post list --format=count; wp user list --format=count
} | tee "$EV/G-06-after.txt"

diff "$EV/G-06-before.txt" "$EV/G-06-after.txt" | tee "$EV/G-06-diff.txt"
comm -23 <(sort "$EV/G-06-before.txt") <(sort "$EV/G-06-after.txt") | tee "$EV/G-06-lost.txt"
# انتظار: G-06-lost.txt خالی باشد (هیچ چیزی حذف نشده)، و تنها تفاوت در diff
# یک سطر **اضافه‌شده** باشد: ردیف audit «plugin.deactivated» که همین گیت با
# غیرفعال‌سازی می‌سازد. انتظارِ «diff کاملاً خالی» نادرست بود: غیرفعال‌سازی
# طبق طراحی ممیزی می‌شود و گیت G-02 دقیقاً همان ردیف را الزامی می‌کند.
```

---

### گیت G-07 — حالت‌های HPOS (تنظیم واقعی، نه خواندن option)

| | |
|---|---|
| **پیش‌شرط** | WooCommerce نصب و فعال؛ افزونه فعال؛ متغیرهای `$REST` و `$RN` از گیت G-05 در همان شل موجود باشند (این گیت پس از G-05 اجرا می‌شود). |
| **اقدام** | برای **هر سه حالت**: حالت را واقعاً تغییر دهید، **وضعیت مؤثر** را از خود WooCommerce تأیید کنید، سپس آزمون را اجرا کنید. |
| **نتیجه مورد انتظار** | در هر سه حالت: هیچ خطای افزونه در بازه لاگ؛ صفحه سلامت و پاسخ REST همان وضعیت مؤثر را نشان دهند؛ فیلد «HPOS آزموده شده» همچنان **نامشخص** بماند (اعلام سازگاری ادعای آزمون نیست). |
| **مدرک** | `G-07-<mode>-*.txt` |

> خواندن `woocommerce_custom_orders_table_enabled` **کافی نیست**: WooCommerce
> تنها وقتی HPOS را مؤثر می‌داند که جدول‌ها ساخته و همگام‌سازی حل شده باشد.
> مرجع تأیید، خود WooCommerce است.

```bash
effective() {   # وضعیت مؤثر از زبان خود WooCommerce
  wp eval 'echo "hpos_enabled=" . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "1" : "0") . "\n";'
  wp eval 'echo "sync_enabled=" . (get_option("woocommerce_custom_orders_table_data_sync_enabled") === "yes" ? "1" : "0") . "\n";'
  # SHOW TABLES نامِ جدول را برمی‌گرداند؛ (int) "wp_wc_orders" صفر است، پس
  # cast نسخه قبلی برای جدولِ موجود هم «۰» گزارش می‌کرد.
  wp eval 'echo "table_exists=" . ($GLOBALS["wpdb"]->get_var("SHOW TABLES LIKE \"" . $GLOBALS["wpdb"]->prefix . "wc_orders\"") ? "1" : "0") . "\n";'
}

for mode in hpos-sync-on hpos-sync-off legacy; do
  # تغییر حالت را از WooCommerce → Settings → Advanced → Features انجام دهید
  # (مرجع رسمی). سپس همگام‌سازی را تا پایان اجرا کنید:
  #   wp wc hpos sync         # اگر در دسترس بود
  # و تنها پس از آن ادامه دهید.
  read -r -p "حالت $mode را در WooCommerce اعمال و همگام‌سازی را تمام کنید، سپس Enter…"

  effective | tee "$EV/G-07-$mode-effective.txt"
  # انتظار: hpos-sync-on → 1/1/1 · hpos-sync-off → 1/0/1 · legacy → 0/-/-

  mark
  curl -s -b "$EV/cookies-admin.txt" -H "X-WP-Nonce: $RN" "$REST" -o "$EV/G-07-$mode-health.json"
  python3 -c "import json;d=json.load(open('$EV/G-07-$mode-health.json'));
print('reported hpos.enabled =', repr(d['dependencies']['hpos']['enabled']),
      '| wc =', d['dependencies']['woocommerce'])"  | tee "$EV/G-07-$mode-reported.txt"
  # انتظار: مقدار گزارش‌شده با hpos_enabled در فایل effective یکی باشد

  curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-07-$mode-health.html" \
       "$SITE/wp-admin/admin.php?page=tmc-health"
  grep -c 'HPOS آزموده شده' "$EV/G-07-$mode-health.html"      # انتظار: ≥1
  grep -A2 'HPOS آزموده شده' "$EV/G-07-$mode-health.html" | grep -c 'نامشخص'  # انتظار: ≥1
  slice "G-07-$mode"
  grep -Ei 'fatal|warning|notice' "$EV/G-07-$mode-debug.log" | grep -i tecteb \
    | tee "$EV/G-07-$mode-plugin-errors.txt"                  # انتظار: خالی
done
```

---

### گیت G-08 — بارگذاری asset فقط در صفحه‌های افزونه

| | |
|---|---|
| **پیش‌شرط** | افزونه فعال؛ **نشست معتبر مدیرکل** (بدون نشست، `edit.php` ریدایرکت می‌شود و آزمون بی‌معناست). |
| **اقدام** | درخواست `edit.php` و چند صفحه دیگر wp-admin **با نشست معتبر**؛ و صفحه اصلی سایت **بدون نشست** به‌صورت جداگانه. |
| **نتیجه مورد انتظار** | در همه صفحه‌های غیرافزونه: صفر ارجاع به `tmc-admin.css`/`tmc-admin.js`. در صفحه‌های افزونه: هر دو حاضر. |
| **مدرک** | `G-08-assets.txt` |

```bash
for p in edit.php index.php plugins.php options-general.php users.php; do
  code=$(curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-08-$p.html" -w '%{http_code}' "$SITE/wp-admin/$p")
  printf 'admin %-22s http=%s tmc_assets=%s\n' "$p" "$code" \
    "$(grep -c 'tmc-admin\.\(css\|js\)' "$EV/G-08-$p.html")"
done | tee "$EV/G-08-assets.txt"
# انتظار: http=200 برای همه (اگر 302 دیدید نشست معتبر نیست → آزمون را تکرار کنید) و tmc_assets=0

# صفحه اصلی، جدا و بدون نشست:
code=$(curl -s -o "$EV/G-08-home.html" -w '%{http_code}' "$SITE/")
printf 'front  %-22s http=%s tmc_assets=%s\n' "/" "$code" \
  "$(grep -c 'tmc-admin\.\(css\|js\)' "$EV/G-08-home.html")" | tee -a "$EV/G-08-assets.txt"
# انتظار: http=200 و tmc_assets=0

# و یک صفحه افزونه به‌عنوان شاهد مثبت (وگرنه «صفر» ممکن است یعنی هیچ‌جا بارگذاری نمی‌شود):
printf 'plugin %-22s tmc_assets=%s\n' "tmc-dashboard" \
  "$(grep -c 'tmc-admin\.\(css\|js\)' "$EV/G-01-page-tmc-dashboard.html")" | tee -a "$EV/G-08-assets.txt"
# انتظار: ≥2 (css و js)
```

---

### گیت G-09 — نسخه PHP: خط فرمان و وب

| | |
|---|---|
| **پیش‌شرط** | افزونه فعال. |
| **اقدام** | ثبت نسخه PHP هر دو محیط. |
| **نتیجه مورد انتظار** | هر دو ≥ ۸٫۱٫۰ ثبت شوند. **اگر یکسان نیستند، هر دو در ماتریس سازگاری نوشته می‌شوند**؛ نسخه CLI به‌تنهایی نماینده سایت نیست. |
| **مدرک** | `G-09-php.txt` |

```bash
{ echo "cli_php=$(php -r 'echo PHP_VERSION;')"
  echo "cli_wp_php=$(wp eval 'echo PHP_VERSION;')"
  echo "cli_sapi=$(php -r 'echo PHP_SAPI;')"
} | tee "$EV/G-09-php.txt"

# نسخه وب: از Site Health → Info → Server (فقط‌خواندنی و معتبر)
curl -s -b "$EV/cookies-admin.txt" "$SITE/wp-admin/site-health.php?tab=debug" \
  -o "$EV/G-09-site-health.html"
grep -A3 -i 'PHP version' "$EV/G-09-site-health.html" | sed 's/<[^>]*>//g' | tr -s ' \n' ' \n' \
  | tee -a "$EV/G-09-php.txt"
# تصویر همان بخش را هم به‌عنوان مدرک نگه دارید: G-09-site-health.png
```

> `phpinfo()` را روی سایت قرار ندهید: پیکربندی و مسیرها را افشا می‌کند.

---

### ۴٫۱ ثبت نتیجه

پس از پایان، برای هر گیت یک سطر بنویسید و همان را به
`docs/compatibility-matrix.md` منتقل کنید:

```
G-01 نصب اولیه            | Passed/Failed | مدرک: G-01-*.txt, G-01-debug.log | تاریخ | اجراکننده
G-02 فعال‌سازی مجدد        | …
…
```

نسخه‌های واقعی محیط (`wp core version`، نسخه WooCommerce، PHP وب و CLI،
نسخه MySQL/MariaDB) را هم در همان جدول ثبت کنید؛ ماتریس سازگاری فقط به
نسخه‌هایی که این‌طور ثبت شده‌اند اجازه `Passed` می‌دهد.

**برای PHP 8.1:** همین پروتکل باید روی میزبانی با PHP 8.1.x تکرار شود. تنها
این کار سطر «اجرای واقعی روی 8.1» را از `Not Run` خارج می‌کند؛ نتیجه روی
نسخه دیگر به آن سطر تعمیم داده نمی‌شود.

## ۵. بازگشت (rollback) — فقط فاز ۱

فاز ۱ **هیچ داده تجاری‌ای نمی‌نویسد**: نه محصول، نه سفارش، نه کاربر، نه سفارش
دکان. تنها چیزهایی که می‌نویسد چهار option با پیشوند `tmc_`، چهار capability
روی نقش مدیرکل، و جدول `{prefix}tmc_audit_events` است.

بنابراین **مسیر عادی بازگشت هیچ حذفی ندارد**:

| گام | اقدام | اثر |
|---|---|---|
| ۱ | غیرفعال کردن افزونه از پیشخوان | همه hookها قطع می‌شوند. هیچ داده‌ای حذف نمی‌شود |
| ۲ | حذف افزونه از پیشخوان | فایل‌ها می‌روند. `uninstall.php` عمداً **هیچ چیز پاک نمی‌کند** |

همین. گیت G-06 دقیقاً همین را می‌سنجد: پس از حذف، جدول، ردیف‌ها، optionها و
capabilityها باید دست‌نخورده باقی بمانند.

باقی‌ماندن این داده‌ها **اشکال نیست، تصمیم است**: نگهداری نهایی لاگ ممیزی در
DEC-05 باز است و تا تعیین تکلیف، حذف خودکار انجام نمی‌شود.

> پاک‌سازی کامل و برگشت‌ناپذیر **بخشی از مسیر آزمون نیست** و در پیوست الف
> آمده است. اجرای آن برای قبولی هیچ گیتی لازم نیست.

## ۶. درباره اسکلت قبلی

وضعیت `tecteb-marketplace-v0.1.1.zip` **تعیین‌نشده** است
(`docs/decision-log.md` بند ۴). تا بررسی فقط‌خواندنی محتوا، وابستگی‌ها و وضعیت
نصب آن، این سند **هیچ دستوری** درباره‌اش نمی‌دهد — نه غیرفعال‌کردن، نه حذف، نه
هم‌زیستی. اگر احتمال می‌دهید روی همان سایت نصب است، پیش از هر نصبی آن بررسی را
انجام دهید.

## ۷. عیب‌یابی

| نشانه | معنی | اقدام |
|---|---|---|
| هشدار «WooCommerce فعال نیست» | حالت محدود؛ عمدی است | WooCommerce را فعال کنید یا نادیده بگیرید |
| «آماده‌سازی ساختار داده ناموفق بود» | migration شکست خورده | صفحه سلامت مرحله و علت را نشان می‌دهد؛ فعال‌سازی دوباره از سر می‌گیرد |
| «اجرای دیگری در حال آماده‌سازی بود» | قفل در اختیار اجرای دیگری بود | چند لحظه بعد دوباره فعال کنید؛ قفل حداکثر ۵ دقیقه عمر دارد |
| صفحه سلامت: «ماژول سلامت فعال نیست» | ماژول degraded/blocked شده | علت زیر همان پیام آمده است |
| «نرخ کمیسیون هنوز تعیین نشده» | فقط پیام است | مانع کار افزونه نیست؛ در تنظیمات قابل تعیین است |
| ۴۰۳ روی `/wp-json/tmc/v1/health` | `tmc_view_health` ندارید | با مدیرکل وارد شوید؛ nonce به‌تنهایی کافی نیست |

---

## پیوست الف — پاک‌سازی کامل (اختیاری، برگشت‌ناپذیر، خارج از مسیر آزمون)

> **این پیوست بخشی از نصب، حذف عادی یا هیچ گیت آزمونی نیست.** افزونه هیچ‌کدام
> از این دستورها را اجرا نمی‌کند و هرگز نخواهد کرد. فقط وقتی سراغش بروید که
> صریحاً می‌خواهید اثر افزونه روی یک سایت **یکبارمصرف** صفر شود.

### الف-۱. سه بررسی اجباری پیش از هر حذف

هر سه باید **قبل** از اجرای هر دستور حذف انجام و خروجی‌شان دیده شود.

**۱) پیشوند واقعی همین سایت را از خود وردپرس بگیرید — حدس نزنید.**
`wp_` صرفاً پیش‌فرض است؛ روی سایت شما می‌تواند چیز دیگری باشد و اجرای دستور
با پیشوند اشتباه یا جدول سایت دیگری را هدف می‌گیرد یا بی‌صدا هیچ کاری نمی‌کند.

```bash
PREFIX="$(wp db prefix)"; echo "prefix=$PREFIX"
wp eval 'echo "db=" . DB_NAME . " prefix=" . $GLOBALS["wpdb"]->prefix . "\n";'
# هر دو باید یکی باشند و همان سایتی باشند که قصدش را دارید.
```

**۲) مالکیت داده را تأیید کنید: این جدول واقعاً ساخته همین افزونه است؟**
نام مشابه کافی نیست. ساختار باید دقیقاً همان چیزی باشد که
`docs/public-contracts.md` §۴ توصیف می‌کند.

```bash
wp db query "SHOW CREATE TABLE ${PREFIX}tmc_audit_events\G"
# انتظار: هشت ستون id, event_type, actor_id, object_type, object_id,
#          payload, correlation_id, created_at
#          و ایندکس‌های tmc_evt_created, tmc_actor_created, tmc_object
wp db query "SELECT DISTINCT event_type FROM ${PREFIX}tmc_audit_events"
# انتظار: فقط رویدادهای allowlist‌شده (settings.updated, plugin.activated, …)
# اگر رویداد ناشناخته دیدید، جدول را حذف نکنید: مالکیتش روشن نیست.
```

**۳) فقط optionهای همین افزونه را هدف بگیرید و فهرست را ببینید.**

```bash
wp db query "SELECT option_name FROM ${PREFIX}options WHERE option_name LIKE 'tmc\\_%'"
# انتظار: دقیقاً چهار نام؛ هر نام دیگری یعنی چیزی خارج از قرارداد این افزونه
# وجود دارد — پیش از حذف، منشأش را روشن کنید.
```

**۴) بک‌آپ تازه بگیرید.** بدون این، هیچ‌کدام از دستورهای زیر را اجرا نکنید.

```bash
wp db export "$HOME/pre-purge-$(date -u +%Y%m%dT%H%M%SZ).sql"
```

### الف-۲. حذف، پس از قبولی هر چهار بررسی بالا

```bash
# optionها: فقط چهار نام صریح، بدون الگوی wildcard
for o in tmc_settings tmc_schema_version tmc_migration_last_error tmc_migration_lock; do
  wp option delete "$o" 2>/dev/null && echo "deleted $o" || echo "absent $o"
done

# جدول: با پیشوند تأییدشده، نه با پیشوند حدسی
wp db query "DROP TABLE IF EXISTS ${PREFIX}tmc_audit_events"

# capabilityها: فقط از نقش مدیرکل، فقط چهار مورد
for c in tmc_view_dashboard tmc_view_health tmc_manage_settings tmc_view_modules; do
  wp cap remove administrator "$c"
done
```

### الف-۳. تأیید پس از حذف

```bash
wp db query "SELECT option_name FROM ${PREFIX}options WHERE option_name LIKE 'tmc\\_%'"   # انتظار: خالی
wp db query "SHOW TABLES LIKE '${PREFIX}tmc_%'"                                            # انتظار: خالی
wp cap list administrator | grep -c tmc_ || echo 0                                         # انتظار: 0
wp post list --format=count; wp user list --format=count
# انتظار: شمارش محصول/سفارش/کاربر **تغییر نکرده باشد** — این دستورها نباید به
# هیچ داده‌ای بیرون از قرارداد افزونه دست زده باشند.
```

