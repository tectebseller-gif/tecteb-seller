# Tecteb Marketplace Core — راهنمای کار در این مخزن

## محدوده فعلی: فاز ۱، Alpha قابل بررسی
فقط زیرساخت و **چهار صفحه مدیریت** (پیشخوان، سلامت، تنظیمات، ماژول‌ها) زیر
«بازارگاه تک‌طب». هیچ Vendor/Staff/Product/Order/Commission/Withdrawal/
Refund/Coupon/B2B/Ticket/SEO/Migration یا auth واقعی ساخته نمی‌شود. هیچ
sender/gateway واقعی وجود ندارد؛ خروجی‌های افزونه در Alpha همیشه بسته‌اند.

**وضعیت جاری:** فاز ۱ پیاده‌سازی شد، **چهار دور بازبینی سورس** روی آن اعمال شد،
و پروتکل پذیرش (G-01 تا G-09 به‌علاوه ارتقا/بازیابی) روی یک **WordPress
یکبارمصرف** با WooCommerce واقعی، دو بار — روی PHP 8.1.32 و 8.4.19 — اجرا و
قبول شد. دسترس‌پذیری و چیدمان روی همان wp-admin واقعی با **زبان مدیریت fa_IR و RTL**
اجرا شد (با **بسته ترجمه حداقلی آزمایشی**) — ۵ viewport، شبیه‌سازی فضای چیدمان،
device scale factor سطح مرورگر، کیبورد با Tab واقعی، و axe: دو اجرا، هرکدام
۲۵۲ بررسی، ۰ شکست. زوم صفحه از منوی مرورگر و ترجمه رسمی فارسی `Not Run`.
اجرای گیت‌ها یک اشکال واقعی پیدا کرد (بند ۱۴ اصلاح‌های بازبینی) که اصلاح شد.
نصب روی `tecteb.com` یا `staging.tecteb.com` **انجام نشده و در مجوز فعلی نیست**؛
PHP 8.1.34 خودِ سایت، سرور وب واقعی، تداخل با افزونه‌های موجود، screen reader
دستی و مرورگرهای غیر Chromium همچنان `Not Run`.
گزارش: `docs/phase-1-report.md` بند ۳ · شواهد: `docs/evidence/acceptance/` ·
اصلاح‌های بازبینی: `docs/review-fixes-phase-1.md` · تحویل بعدی: `docs/next-phase-handoff.md`.

**۱۱ سپتامبر — دو چیز عوض شد (جزئیات: `docs/phase-1-report.md` بند ۵):**
- مالک `0.1.0-alpha.1` را روی `staging.tecteb.com` **نصب و فعال کرده است**. این
  کار را مالک انجام داده؛ این مخزن هیچ دسترسی و هیچ آزمونی روی آن سایت ندارد و
  هیچ عددی از آنجا نمی‌آید.
- همان نصب یک نقص واقعی نشان داد: شناسه و نسخه در کارت‌های ماژول‌ها حرف‌به‌حرف
  زیر هم می‌افتادند. بازتولید شد، علت در CSS خودمان بود، اصلاح شد و گارد
  رگرسیون گرفت (`ADR-006`, `docs/evidence/redesign/`). بسته فعلی
  `12773052…035b20` است و گیت‌های G-01…G-05، G-08 و G-09 روی آن با PHP 8.1.32
  (CLI و وب) قبول شدند؛ G-06/G-07 دوباره اجرا نشدند.
- طرح بخش فروشندگان و پیشخوان اولیه: `docs/phase-2-vendor-plan.md` — **طرح
  است، پیاده‌سازی شروع نشده**.

## مرجع‌ها (ترتیب اعتبار: دستور جاری مالک ← تصمیم مصوب ← Master ← UX ← پرامپت)
- `docs/Tecteb-Marketplace-Core-Master-Spec-v0.3.docx` (مرجع) → `docs/generated/…Master-Spec-v0.3.md`
- `docs/Tecteb-Marketplace-Core-UX-Wireframe-Spec-v0.2.docx` (مرجع) → `docs/generated/…UX-Wireframe-Spec-v0.2.md`
- `docs/Tecteb-Marketplace-Core-Claude-Code-Prompt-Phase-1-v0.2.md` (قرارداد اجرا)
- `docs/reference-checksums.txt` · `docs/decision-log.md` · `docs/adr/` ·
  `docs/environment-inventory.md` · `docs/compatibility-matrix.md`

## هویت
slug `tecteb-marketplace-core` · namespace `Tecteb\Marketplace` · prefix `tmc_` ·
text domain `tecteb-marketplace-core`. نسخه انتشار: به F-01 در `docs/decision-log.md`
وابسته است (تبار تعیین‌نشده)؛ نسخه schema دیتابیس مستقل از آن و از ۱ شروع می‌شود.

## فرمان‌های آزمون واقعی (فقط آنچه امروز اجرا می‌شود)
```bash
sha256sum -c docs/reference-checksums.txt   # سلامت فایل‌های مرجع
python3 tools/verify-extraction.py          # تطبیق ترتیبی DOCX ↔ Markdown

bash tools/run-all-tests.sh                 # پنج suite + lint + سازگاری ۸٫۱ + کنتراست
vendor/bin/phpunit --testsuite unit         # خالص: هیچ نماد WordPress تعریف نمی‌شود
vendor/bin/phpunit --testsuite architecture # مرز Core/Contracts/Application
vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php
vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php  # نیازمند .env.testing
vendor/bin/phpunit --testsuite packaging    # پس از tools/build.sh

php  tools/render-harness/render.php        # رندر ۸ سناریو خارج از WordPress
node tools/browser/check.mjs                # ۲۳۳ بررسی viewport/axe/keyboard
php  tools/contrast.php                     # کنتراست WCAG رنگ‌های برند
bash tools/build.sh                         # dist/ZIP + SHA256SUMS + source archive
```
دیتابیس آزمون **یکبارمصرف** است و پیکربندی‌اش در `.env.testing` می‌آید؛ هرگز
به دیتابیس واقعی اشاره نکنید (suite در نبود نام `tmc_test` اجرا نمی‌شود).

آزمون‌های وابسته به WordPress/WooCommerce واقعی همچنان **Not Run** هستند؛
`docs/compatibility-matrix.md` سطربه‌سطر می‌گوید کدام و چرا.

## قاعده عدم deploy
- هیچ نصب روی Staging/production، هیچ SSH/cPanel، هیچ credential واقعی.
- تنها مخزن محلی و DB آزمایشی disposable با داده مصنوعی قابل تغییر است.
- `git reset --hard`، clean مخرب و overwrite فایل کاربر ممنوع.
- هیچ افزونه موجودی (از جمله دکان) حذف/غیرفعال/ویرایش نمی‌شود.
- Skillهای حوزه‌ای وردپرس از ۱۱ سپتامبر نصب‌اند (`docs/tooling-setup.md`)،
  اما جای قواعد پرامپت فاز ۱ را نمی‌گیرند؛ در تعارض، دستور مالک و پرامپت مقدم است.
- محیط ساخت: PHP 8.4 فقط؛ WP/WC قابل دانلود نیست → آزمون‌های وابسته
  `Not Run` می‌مانند، نه `Passed`. تصاویر خارج از WordPress «نمونه رابط»
  هستند، نه اثبات کارکرد.

## ابزارهای دستیار (پلاگین و مهارت)
پیکربندی در `.claude/settings.json` (scope=project) و رونوشت مهارت‌ها در
`.claude/skills/`. گزارش کامل با شواهد: `docs/tooling-setup.md`.

- پلاگین‌های marketplace رسمی **`anthropics/claude-plugins-official`**:
  `frontend-design`، `pr-review-toolkit`، `security-guidance`. کدشان **در مخزن
  نیست**؛ هر محیط تازه باید از GitHub بگیردشان. (پیش‌تر از `anthropics/claude-code`
  نصب شده بود؛ دلیل تعویض در `docs/tooling-setup.md` بند ۲٫۱.)
- مهارت‌های `WordPress/agent-skills` @ `d87ee69`: `wordpress-router`،
  `wp-project-triage`، `wp-plugin-development`، `wp-rest-api`، `wp-performance`.
  رونوشت بالادست‌اند — دست‌نویس ویرایش نکنید (`.claude/skills/UPSTREAM.md`).
  پیش از استفاده، مهارت پروژه‌ای `tmc-wp-skills` را بخوانید: مسیر درست
  `.claude/skills/…` و تشخیص این مخزن به‌عنوان افزونه وردپرس
  (`node .claude/skills/tmc-wp-skills/scripts/triage.mjs`).
- Playwright ۱٫۶۳ + axe-core ۴٫۱۳ از قبل نصب‌اند و **نصب دوباره نمی‌شوند**.
  مرورگر باید با `executablePath` صریح اجرا شود (`/opt/pw-browsers/chromium`)؛
  بررسی سلامت: `cd tools/browser && node check-toolchain.mjs`.
- `check.mjs` و `check-wpadmin.mjs` را بی‌دلیل دوباره اجرا نکنید؛ شواهد
  پذیرفته‌شده را بازنویسی می‌کنند (`check-wpadmin.mjs` با `TMC_OUT` در پوشه دیگر
  می‌نویسد). اسکرین‌شات چهار صفحه در wp-admin واقعی:
  `OUT=… LABEL=… node tools/browser/shoot-wpadmin.mjs`.
- **چیدمان صفحات با `@container` نوشته می‌شود، نه `@media`** — ADR-006. هیچ
  تراکی نباید جعبه متن را خفه کند؛ بررسی `no-starved-text-box` این را می‌گیرد.
- Figma و PHP LSP عمداً نصب نشده‌اند.
