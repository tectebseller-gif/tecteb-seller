# مهارت‌های وردپرس — منبع و نسخه

این پوشه **رونوشت بدون تغییر** از مخزن رسمی WordPress است. دست‌نویس ویرایش
نکنید؛ هر اصلاحی باید بالادست انجام شود و سپس دوباره نصب شود.

| مورد | مقدار |
|---|---|
| منبع | <https://github.com/WordPress/agent-skills> |
| commit | `d87ee6916e740c7960b6959220c0481a41b320c7` |
| تاریخ commit | ۲۰۲۶-۰۸-۱۶ (`chore: refresh upstream indices (#82)`) |
| مجوز | GPL-2.0-or-later |
| تاریخ نصب در این مخزن | ۱۱ سپتامبر ۲۰۲۶ |
| روش نصب | طبق README همان مخزن (پایین) |

```bash
git clone https://github.com/WordPress/agent-skills.git
cd agent-skills
node shared/scripts/skillpack-build.mjs --clean
node shared/scripts/skillpack-install.mjs \
  --dest=/home/user/tecteb-seller --targets=claude \
  --skills=wordpress-router,wp-project-triage,wp-plugin-development,wp-rest-api,wp-performance
```

فقط `--targets=claude` استفاده شد؛ `.codex/`، `.github/skills/` و `.cursor/`
ساخته نشدند چون در این پروژه دستیار دیگری به کار نمی‌رود.

## مهارت‌های نصب‌شده و اثر انگشت آن‌ها

هر hash، خلاصهٔ `sha256sum` همهٔ فایل‌های آن پوشه به ترتیب مرتب است و در زمان
نصب با همان پوشه در clone بالادست مقایسه شد: **هر پنج مورد یکسان بودند**.

| مهارت | فایل | sha256 (خلاصه) | چرا انتخاب شد |
|---|---|---|---|
| `wordpress-router` | ۲ | `a6cf5781aa4fb593…` | نقطه ورود: نوع مخزن را تشخیص می‌دهد و به مهارت درست مسیر می‌دهد |
| `wp-project-triage` | ۳ | `89c781aa006663c9…` | بررسی قطعی پروژه (ابزار، تست، نسخه‌ها) با خروجی JSON |
| `wp-plugin-development` | ۸ | `72a0378ad0f95fa4…` | معماری افزونه، hookها، Settings API، امنیت، بسته‌بندی انتشار |
| `wp-rest-api` | ۷ | `bef28ea819ac3897…` | مسیرهای REST، schema، `permission_callback` — مسیر `/tmc/v1/health` و فاز بعد |
| `wp-performance` | ۱۲ | `354fa8994ae37027…` | پروفایل، کش، آپشن‌های autoload، کوئری — معیارهای فاز ۲ |

## آنچه عمداً نصب نشد

`wp-block-development`، `wp-block-themes`، `wp-patterns`،
`wp-interactivity-api`، `wpds`، `blueprint` — این افزونه بلوک/پوسته/رابط
Gutenberg ندارد. `wp-playground`، `wp-wpcli-and-ops`، `wp-phpstan`،
`wp-abilities-api`، `wp-abilities-audit`، `wp-abilities-verify`،
`wp-plugin-directory-guidelines` — خارج از چهار حوزه‌ای که مالک خواست؛ با
همان فرمان بالا و افزودن نامشان قابل نصب‌اند.

## راهنمای سازگاری پروژه‌ای

دو نکتهٔ زیر در یک مهارت پروژه‌ای جمع شده‌اند تا در عمل هم رعایت شوند:
**`.claude/skills/tmc-wp-skills/`** — نگاشت مسیرها و تشخیص درست پروژه، به‌همراه
یک wrapper که همان اسکریپت بالادست را اجرا و نتیجه را تصحیح می‌کند:

```bash
node .claude/skills/tmc-wp-skills/scripts/triage.mjs        # نتیجه تصحیح‌شده
node .claude/skills/tmc-wp-skills/scripts/triage.mjs --raw  # خروجی دست‌نخورده بالادست
```

## دو نکتهٔ رفتاری که در همین مخزن مشاهده شد

1. `wp-project-triage` این مخزن را `kind: unknown` طبقه‌بندی می‌کند. علت:
   الگوی بالادست `^\s*Plugin Name:` است و هدر ما به سبک docblock
   (` * Plugin Name:`) نوشته شده — که وردپرس خودش می‌پذیرد. خروجی همچنان
   `composer install` و `vendor/bin/phpunit` را درست پیشنهاد می‌دهد.
   این نقص بالادست است و **در این رونوشت اصلاح نشده**.
2. `wordpress-router` مسیر `skills/wp-project-triage/scripts/detect_wp_project.mjs`
   را صدا می‌زند؛ در نصب پروژه‌ای مسیر واقعی
   `.claude/skills/wp-project-triage/scripts/detect_wp_project.mjs` است.
