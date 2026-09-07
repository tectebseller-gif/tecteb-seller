# شواهد آزمون فاز ۱

هر فایل اینجا خروجی خام یک اجرای واقعی است. بازتولید همه موارد اجراشدنی:

```bash
bash tools/run-all-tests.sh          # پنج suite + lint + سازگاری + کنتراست
php  tools/render-harness/render.php # ساخت harness رابط
node tools/browser/check.mjs         # ۲۳۳ بررسی مرورگر روی harness
bash tools/build.sh                  # ساخت بسته
```

| فایل | چیست |
|---|---|
| `unit.log` | suite خالص — بدون هیچ نماد WordPress |
| `architecture.log` | قواعد مرز معماری + خودآزمایی اسکنر |
| `contract.log` | قرارداد با **stub** وردپرس — یکپارچگی واقعی نیست |
| `database.log` | MariaDB 10.11 واقعی با داده مصنوعی |
| `packaging.log` | گیت‌های بسته‌بندی روی artefact واقعی |
| `lint.log` | `php -l` روی همه فایل‌های ارسالی |
| `phpcompat-8.1.log` | سازگاری ایستا با PHP 8.1 |
| `phpcompat-selfcheck.log` | اثبات اینکه گیت ۸٫۱ واقعاً روی نحو ۸٫۲ خطا می‌دهد، و parse شدن main file روی ۷٫۲ |
| `contrast.log` / `contrast.json` | نسبت کنتراست ۱۳ ترکیب رنگ برند |
| `browser-checks.json` | نتیجه هر بررسی مرورگر به تفکیک صفحه و عرض |
| `harness/*.html` | خروجی رندر هشت سناریو خارج از WordPress |
| `screenshots/*.png` | **نمونه رابط (خارج WordPress)** — اثبات کارکرد افزونه نیستند |

## درباره تصاویر

تصاویر در عرض‌های ۳۷۵ و ۱۴۴۰ از harness گرفته شده‌اند، نه از wp-admin. آن‌ها
نشان می‌دهند markup و CSS خود افزونه چه چیزی تولید می‌کند. هیچ‌کدام جایگزین
«نصب و فعال‌سازی واقعی» نیستند و در `docs/phase-1-report.md` در ستون جدا آمده‌اند.

هیچ ایمیل، شماره یا داده شخصی واقعی در این شواهد نیست؛ همه داده‌ها مصنوعی‌اند.
