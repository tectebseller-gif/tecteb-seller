# شواهد آزمون فاز ۱

هر فایل اینجا خروجی خام یک اجرای واقعی است. بازتولید همه موارد اجراشدنی:

```bash
bash tools/run-all-tests.sh          # پنج suite + lint + سازگاری + کنتراست
php  tools/render-harness/render.php # ساخت harness رابط
node tools/browser/check.mjs         # ۲۳۳ بررسی مرورگر روی harness
bash tools/build.sh                  # ساخت بسته

# روی یک WordPress یکبارمصرف (نه سایت واقعی):
bash tools/acceptance-gates.sh /usr/bin/php /tmp/ev dist/tecteb-marketplace-core.zip
node tools/browser/check-wpadmin.mjs # ۱۸۸ بررسی روی wp-admin واقعی
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
| `browser-checks.json` / `browser-checks.log` | نتیجه هر بررسی مرورگر به تفکیک صفحه و عرض |
| `regression-migration-takeover.txt` | شاهد قبل/بعد بازبینی دور اول: تصاحب قفل وسط مهاجرت |
| `regression-guarded-writes-vs-previous.log` | شاهد دور دوم: با برگرداندن موقت نوشتن‌های guarded به «بررسی، سپس نوشتن» و برگرداندن ممیزی به `resolveOutcome()`، سه تست تازه می‌شکنند. سورس بلافاصله بازگردانده و byte-identical بودنش در همان فایل ثبت شده است |
| `regression-cleanup-outcome.log` | شاهد دور سوم: با برگرداندن `run()` به حالتی که نتیجه پاک‌سازی را دور می‌ریخت و صفحه سلامت را به «هر رکورد خطا یعنی خرابی»، ۸ از ۱۰ تست `MigrationCleanupTest`، یک تست سلامت و دو تست MariaDB می‌شکنند |
| `harness/*.html` | خروجی رندر هشت سناریو خارج از WordPress |
| `screenshots/*.png` | **نمونه رابط (خارج WordPress)** — اثبات کارکرد افزونه نیستند |
| `acceptance/` | خروجی خام پروتکل پذیرش روی **WordPress واقعی یکبارمصرف**، به‌علاوه تصاویر گرفته‌شده از **خود wp-admin** (که نمونه رابط نیستند) — `acceptance/README.md` |

## درباره تصاویر

دو مجموعه تصویر وجود دارد و **با هم اشتباه نشوند**:

| مسیر | چه چیزی است |
|---|---|
| `screenshots/*.png` | از **harness** بیرون از WordPress. «نمونه رابط». نشان می‌دهند markup و CSS خود افزونه چه تولید می‌کند و جایگزین نصب واقعی نیستند |
| `acceptance/wpadmin-a11y*/screenshots/*.png` | از **خود wp-admin** با نشست معتبر مدیرکل؛ نوار مدیریت وردپرس در آن‌ها پیداست. این‌ها شاهد سطرهای `Passed` بند ۵ ماتریس‌اند |

هیچ ایمیل، شماره یا داده شخصی واقعی در این شواهد نیست؛ همه داده‌ها مصنوعی‌اند.
