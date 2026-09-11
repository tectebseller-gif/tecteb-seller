# شواهد راه‌اندازی ابزار — ۱۱ سپتامبر ۲۰۲۶

گزارش این شواهد: `docs/tooling-setup.md`.

| فایل | چیست |
|---|---|
| `plugin-inventory.txt` | خروجی `claude plugin marketplace list`، `plugin list` و `plugin details` هر سه پلاگین |
| `fresh-session-probe.txt` | یک جلسهٔ تازهٔ واقعی (`claude -p`) فهرست مهارت‌ها و زیرعامل‌های بارگذاری‌شده‌اش را می‌گوید |
| `fresh-session-hooks.txt` | رویدادهای `hook_started`/`hook_response` همان جلسه — چهار hook پلاگین `security-guidance` |
| `browser-toolchain.json` / `.txt` | بررسی سلامت Playwright + axe + Chromium: ۷ بررسی، ۰ شکست |
| `browser-toolchain-dashboard-with-wc-1440.png` | اسکرین‌شات گرفته‌شده در همین جلسه از صفحهٔ harness پیشخوان |

اسکرین‌شات، مثل بقیهٔ تصاویر harness، **نمونهٔ رابط بیرون از WordPress** است و
اثبات کارکرد افزونه نیست؛ اینجا فقط ثابت می‌کند مرورگر و اسکرین‌شات کار می‌کنند.

این پوشه شواهد پذیرش (`docs/evidence/acceptance/`) را جایگزین نمی‌کند و
`check.mjs` / `check-wpadmin.mjs` در این دور دوباره اجرا نشدند.
