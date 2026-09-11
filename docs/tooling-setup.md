# راه‌اندازی ابزارهای توسعه و طراحی — گزارش

تاریخ اجرا: ۱۱ سپتامبر ۲۰۲۶ · محیط: کانتینر اجرای از راه دور Claude Code
(Linux 6.18، Ubuntu noble) · شاخه: `claude/new-session-z1vafy`

> این گزارش فقط چیزی را ثبت می‌کند که **در همین کانتینر اجرا و مشاهده شد**.
> «نصب شد» و «در این جلسه کار می‌کند» دو ادعای جداگانه‌اند و جداگانه اثبات
> شده‌اند. نوشتن `enabledPlugins` به‌تنهایی در هیچ ردیفی به‌عنوان اثبات
> بارگذاری شمرده نشده است.
>
> در این دور **هیچ تغییری در کد اجرایی افزونه، دیتابیس، سایت آزمایشی،
> `tecteb.com` یا `staging.tecteb.com` انجام نشد** و فاز ۲ شروع نشد.

## ۱. پاسخ چهار پرسش برای هر ابزار

| ابزار | پیکربندی شد؟ | دانلود/نصب شد؟ | در همین جلسه قابل استفاده؟ | اقدام لازم |
|---|---|---|---|---|
| `frontend-design` ۱٫۱٫۰ | ✅ scope=project | ✅ روی دیسک (`~/.claude/plugins/marketplaces/`) | ❌ **خیر** | فقط جلسهٔ تازه |
| `pr-review-toolkit` ۱٫۰٫۰ | ✅ scope=project | ✅ | ❌ **خیر** | فقط جلسهٔ تازه |
| `security-guidance` ۲٫۰٫۰ | ✅ scope=project | ✅ | ❌ **خیر** | فقط جلسهٔ تازه |
| ۵ مهارت `WordPress/agent-skills` | ✅ `.claude/skills/` | ✅ رونوشت داخل مخزن | ❌ **خیر** (اسکریپت‌هایشان ✅ بله) | فقط جلسهٔ تازه |
| Playwright ۱٫۶۳٫۰ | ✅ دست‌نخورده | ✅ از قبل | ✅ **بله، اجرا شد** | — |
| `axe-core` / `@axe-core/playwright` ۴٫۱۳٫۰ | ✅ دست‌نخورده | ✅ از قبل | ✅ **بله، اجرا شد** | — |
| Chromium ۱۴۱٫۰٫۷۳۹۰٫۳۷ (ایمیج) | ✅ مسیر صریح | ✅ از قبل | ✅ **بله؛ اسکرین‌شات گرفته شد** | — |
| Figma | ⛔️ عمداً انجام نشد | ⛔️ | — | طبق دستور مالک |
| PHP LSP | ⛔️ عمداً انجام نشد | ⛔️ | — | طبق دستور مالک |

**چرا سه پلاگین و پنج مهارت در این جلسه کار نمی‌کنند:** Claude Code فهرست
مهارت‌ها، زیرعامل‌ها و hookها را در **شروع جلسه** می‌خواند. این جلسه پیش از
نصب آن‌ها شروع شده بود، پس نه `frontend-design` در فهرست مهارت‌های من است،
نه زیرعامل‌های `pr-review-toolkit` در ابزار Agent، و نه هیچ hook‌ای از
`security-guidance` روی ویرایش‌های همین جلسه فعال شد. اثبات اینکه **جلسهٔ
بعدی** آن‌ها را دارد، در بند ۲٫۳ آمده — با اجرای واقعی یک جلسهٔ تازه، نه با
استناد به فایل تنظیمات.

## ۲. پلاگین‌های marketplace رسمی Anthropic

### ۲٫۱ منبع

| مورد | مقدار |
|---|---|
| marketplace | `claude-code-plugins` (owner: Anthropic) |
| منبع | GitHub · `anthropics/claude-code` |
| commit کش‌شده | `536a2e23d9e28586f81f17b3535281b5f2995a70` (۲۰۲۶-۰۹-۱۰T20:30:46Z) |
| محل کش | `~/.claude/plugins/marketplaces/claude-code-plugins` (~۲۹ مگابایت، **بیرون از مخزن**) |
| اعلان در مخزن | `.claude/settings.json` → `extraKnownMarketplaces` + `enabledPlugins` |

```bash
claude plugin marketplace add anthropics/claude-code --scope project
claude plugin install frontend-design@claude-code-plugins   --scope project
claude plugin install pr-review-toolkit@claude-code-plugins --scope project
claude plugin install security-guidance@claude-code-plugins --scope project
```

`--scope project` عمدی است: اعلان در خودِ مخزن می‌ماند و با شاخه commit
می‌شود، پس هر جلسهٔ بعدی روی همین مخزن همین سه پلاگین را می‌گیرد. خودِ کد
پلاگین‌ها در مخزن ذخیره نمی‌شود (بند ۶).

### ۲٫۲ موجودی اجزا و هزینهٔ توکن

| پلاگین | مهارت | زیرعامل | hook | هزینهٔ همیشگی هر جلسه |
|---|---|---|---|---|
| `frontend-design` | `frontend-design` | — | — | ~۷۸ توکن |
| `pr-review-toolkit` | `review-pr` | ۶ عدد: `code-reviewer`، `code-simplifier`، `comment-analyzer`، `pr-test-analyzer`، `silent-failure-hunter`، `type-design-analyzer` | — | **~۲٬۸۷۷ توکن** |
| `security-guidance` | — | — | ۴ عدد: `SessionStart`، `UserPromptSubmit`، `PostToolUse`، `Stop` | ~۰ (فقط سمت harness) |

خروجی کامل `claude plugin details`: `docs/evidence/tooling/plugin-inventory.txt`.

### ۲٫۳ شاهد بارگذاری در جلسهٔ تازه

یک جلسهٔ واقعی و جدا (`claude -p` در همین مخزن) اجرا شد و از خودش پرسیده شد
چه چیزی بارگذاری کرده است — شاهد: `docs/evidence/tooling/fresh-session-probe.txt`.

```
- wordpress-router, wp-performance, wp-plugin-development,
  wp-project-triage, wp-rest-api        ← ۵ مهارت پروژه‌ای
- frontend-design:frontend-design       ← پلاگین
- pr-review-toolkit:review-pr           ← پلاگین
زیرعامل‌ها: pr-review-toolkit:code-reviewer, :code-simplifier,
:comment-analyzer, :pr-test-analyzer, :silent-failure-hunter, :type-design-analyzer
```

`security-guidance` مهارت و زیرعامل ندارد، پس در آن فهرست دیده نمی‌شود؛
به‌جایش هر چهار hookش در همان جلسهٔ تازه **شلیک کردند** — شاهد:
`docs/evidence/tooling/fresh-session-hooks.txt` (رویدادهای `stream-json`).
انتساب این رویدادها به این پلاگین حدس نیست: hookهای خودِ میزبان فقط
`SessionStart` (هویت git) و `Stop` (بررسی git) هستند، و کلیدهای
`sdk_bootstrap`، `skip_reason` و `preexisting_untracked_excluded` در خروجی،
فقط در سورس hookهای `security-guidance` وجود دارند.

### ۲٫۴ رفتاری که باید بدانید

- `security-guidance` روی `Stop` یک بازبینی امنیتی مبتنی بر diff گیت اجرا
  می‌کند و `SessionStart` آن به‌صورت async، Agent SDK را آماده می‌کند
  (`asyncTimeout: 180000`). در پروب، بازبینی با `skipped: true` رد شد چون
  فایلِ نوشته‌شده بیرون از مخزن بود؛ **پس رفتار آن روی یک تغییر واقعی داخل
  مخزن هنوز آزموده نشده است**.
- `pr-review-toolkit` در هر جلسه ~۲٫۹ هزار توکن همیشگی می‌افزاید. اگر جلسه‌ای
  به بازبینی PR نیاز ندارد، `claude plugin disable pr-review-toolkit` ارزان‌ترش
  می‌کند.

## ۳. مهارت‌های وردپرس (`WordPress/agent-skills`)

منبع، commit، فرمان نصب، اثر انگشت هر مهارت و فهرست آنچه نصب **نشد**:
`.claude/skills/UPSTREAM.md`.

| مورد | مقدار |
|---|---|
| منبع | <https://github.com/WordPress/agent-skills> · GPL-2.0-or-later |
| commit | `d87ee6916e740c7960b6959220c0481a41b320c7` (۲۰۲۶-۰۸-۱۶) |
| روش | همان README مخزن: `skillpack-build.mjs --clean` سپس `skillpack-install.mjs --dest=… --targets=claude --skills=…` |
| نصب‌شده | `wordpress-router`، `wp-project-triage`، `wp-plugin-development`، `wp-rest-api`، `wp-performance` |
| صحت رونوشت | هر ۵ پوشه با clone بالادست **byte-identical** بودند (sha256 تجمعی) |
| حجم | ۲۱۲ کیلوبایت، ۳۲ فایل |

دو رفتار مشاهده‌شده که **اصلاح نشدند** (نقص بالادست است، نه کد ما؛ رونوشت
دست‌نخورده می‌ماند):

1. `node .claude/skills/wp-project-triage/scripts/detect_wp_project.mjs`
   اجرا می‌شود و `composer install` / `vendor/bin/phpunit` را درست پیشنهاد
   می‌دهد، اما این مخزن را `kind: unknown` طبقه‌بندی می‌کند. الگوی بالادست
   `^\s*Plugin Name:` است و هدر ما به سبک docblock (` * Plugin Name:`) نوشته
   شده؛ همین یک `*` باعث عدم تطبیق می‌شود. آزمون مستقیم با همان الگو روی
   `tecteb-marketplace-core.php` خروجی `null` داد.
2. `wordpress-router` مسیر `skills/wp-project-triage/scripts/…` را صدا می‌زند؛
   در نصب پروژه‌ای، مسیر واقعی `.claude/skills/wp-project-triage/scripts/…` است.

## ۴. Playwright و axe — حفظ شدند، نصب دوباره انجام نشد

`tools/browser/node_modules` دست‌نخورده ماند؛ نه `npm install` تازه‌ای اجرا شد
و نه نسخه‌ای عوض شد. آنچه اضافه شد فقط یک بررسی سلامت است.

| مورد | نسخهٔ مشاهده‌شده |
|---|---|
| Node.js | ۲۲٫۲۲٫۲ |
| `playwright` | ۱٫۶۳٫۰ |
| `axe-core` | ۴٫۱۳٫۰ |
| `@axe-core/playwright` | ۴٫۱۳٫۰ |
| Chromium اجراشده | ۱۴۱٫۰٫۷۳۹۰٫۳۷ (`/opt/pw-browsers/chromium` → build ۱۱۹۴) |

**مرورگر و اسکرین‌شات کار می‌کنند** — در همین جلسه اجرا شد:

```bash
cd tools/browser && node check-toolchain.mjs      # ۷ بررسی، ۰ شکست
```

شواهد: `docs/evidence/tooling/browser-toolchain.json`،
`…/browser-toolchain.txt` و اسکرین‌شات
`…/browser-toolchain-dashboard-with-wc-1440.png` (۱۰۹٬۵۵۰ بایت، صفحهٔ
پیشخوان harness با چیدمان RTL). axe هم در همان صفحه اجرا شد (۲۰ pass،
۰ violation) — این فقط **اثبات کارکرد ابزار** است، نه نتیجهٔ جدید
دسترس‌پذیری برای wp-admin.

یک تلهٔ محیطی که در اسکریپت ثبت شده: Playwright ۱٫۶۳ به‌طور پیش‌فرض دنبال
build ۱۲۴۳ می‌گردد که در این ایمیج نیست و دانلود مرورگر هم بسته است؛ پس
`executablePath` باید صریح داده شود — دقیقاً همان کاری که `check.mjs` و
`check-wpadmin.mjs` از قبل می‌کنند (`TMC_CHROMIUM` یا `/opt/pw-browsers/chromium`).

**آنچه عمداً دوباره اجرا نشد:** `check.mjs` و `check-wpadmin.mjs`. نتایج
پذیرفته‌شدهٔ آن‌ها (دو اجرا، هرکدام ۲۵۲ بررسی، ۰ شکست) و شواهدشان باید
دست‌نخورده بمانند؛ اجرای دوباره آن‌ها را بازنویسی می‌کرد. `check-toolchain.mjs`
فقط داخل `docs/evidence/tooling/` می‌نویسد و صفحهٔ harness را فقط می‌خواند.

## ۵. آنچه عمداً نصب نشد

| ابزار | دلیل |
|---|---|
| Figma (MCP/پلاگین) | طرح مرجعی وجود ندارد و محیط ابری است — طبق دستور مالک |
| PHP LSP | طبق دستور مالک؛ تحلیل ایستا فعلاً با `php -l` و PHPCompatibility انجام می‌شود |

## ۶. محدودیت‌های محیط (بدون دور زدن)

1. **پلاگین و مهارت به جلسهٔ در حال اجرا اضافه نمی‌شود.** تنها راه، جلسهٔ تازه
   است. هیچ راهکاری برای تزریق آن‌ها به جلسهٔ جاری امتحان نشد.
2. **کد پلاگین‌ها در مخزن ذخیره نمی‌شود.** کش ۲۹ مگابایتی در `~/.claude/plugins`
   است و کانتینر موقتی است؛ در محیط تازه، Claude Code باید دوباره از
   `github.com` بگیرد. اگر خروجی شبکه به GitHub بسته باشد، سه پلاگین
   بارگذاری نمی‌شوند. پنج مهارت وردپرس این مشکل را ندارند چون داخل مخزن‌اند.
3. `SKIP_PLUGIN_MARKETPLACE=true` در env این جلسه ست است. با همین متغیر،
   `marketplace add` و `install` کار کردند و جلسهٔ تازه هم پلاگین‌ها را
   بارگذاری کرد، پس مانع عملی مشاهده نشد؛ تنها کاتالوگ claude.ai (ابزار
   `ListPlugins`) خالی برگشت — آن کاتالوگ با marketplace گیت‌هابی یکی نیست.
4. مرورگر غیر Chromium (Firefox/WebKit) در ایمیج نیست و زوم صفحه از منوی
   مرورگر همچنان قابل هدایت نیست — هر دو مثل قبل `Not Run` می‌مانند
   (`docs/evidence/acceptance/REMAINING-TESTS.md`).
5. رفتار `security-guidance` روی یک تغییر واقعی داخل مخزن هنوز دیده نشده
   (بند ۲٫۴).

## ۷. آنچه تغییر نکرد

- `dist/tecteb-marketplace-core.zip` دست‌نخورده است:
  `c37f8902ef3152bfc897114f7044f546d521783528e2c9f38c1d3442227f46f1` — مطابق
  `dist/SHA256SUMS`. ساخت ZIP از فهرست سفید فایل‌های اجرایی افزونه است، پس
  `.claude/` و `docs/evidence/tooling/` هرگز واردش نمی‌شوند.
- آرشیو سورس فعلی هم دست‌نخورده است
  (`f4e958700d62e68177ff0385566bf480663ea6b4129373c706f5d8aefafee318`).
  **توجه:** آرشیو سورس از `git ls-files` ساخته می‌شود؛ چون این commit چند فایل
  ابزار و سند به درخت افزوده، **ساختِ بعدی** هش سورس متفاوتی می‌دهد. برای
  حفظ شواهد تحویل‌شده، `tools/build.sh` در این دور اجرا نشد.
- هیچ فایل PHP افزونه، هیچ تست، هیچ جدول دیتابیس و هیچ سایتی لمس نشد.

## ۸. فایل‌های افزوده‌شده

| مسیر | چیست |
|---|---|
| `.claude/settings.json` | اعلان marketplace و سه پلاگین (scope=project) |
| `.claude/skills/UPSTREAM.md` | منبع، commit و اثر انگشت مهارت‌های وردپرس |
| `.claude/skills/w*/` | ۵ مهارت رونوشت بالادست (۳۲ فایل) |
| `tools/browser/check-toolchain.mjs` | بررسی سلامت مرورگر/axe (فقط توسعه) |
| `docs/evidence/tooling/` | شواهد این دور: JSON، لاگ، اسکرین‌شات، پروب جلسه، hookها، موجودی پلاگین |
| `docs/tooling-setup.md` | همین گزارش |
