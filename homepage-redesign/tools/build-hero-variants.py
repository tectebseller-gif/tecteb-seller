#!/usr/bin/env python3
"""
دو نمونهٔ کوتاه Hero را از Hero ِ خودِ concept-c/index.html می‌سازد، تا سه فایل
هرگز از هم جدا نشوند: سبک روشن فقط کلاس و تصویر را عوض می‌کند.
آیکون‌ها از shared/icons.svg.html درج می‌شوند (همان کاری که برای A و B شد).

    python3 homepage-redesign/tools/build-hero-variants.py
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
C = ROOT / "concept-c"
sprite = (ROOT / "shared" / "icons.svg.html").read_text(encoding="utf-8")
sprite = sprite[sprite.index("<svg"):].strip()
MARK = "<!--TH-SPRITE-->"
SPRITE_LINE = "<!-- th-sprite: copy of shared/icons.svg.html -->\n" + sprite

index = (C / "index.html").read_text(encoding="utf-8")
if MARK in index:
    index = index.replace(MARK, SPRITE_LINE)
    (C / "index.html").write_text(index, encoding="utf-8")

start = index.index('  <!-- ۱. معرفی تک‌طب')
end = index.index("  </section>", start) + len("  </section>")
hero = index[start:end]

SPECS = {
    "dark": dict(
        title="نمونهٔ Hero — سبک B: زمینهٔ تیرهٔ آبی نفتی با نور فیروزه‌ای",
        rows=[
            ("زمینه", "سه لایه: گرادیان خطی نفتی (۸۰۰ ← ۹۵۰)، نور شعاعی فیروزه‌ای پشت رندر، و نور پهن در لبهٔ پایین؛ به‌علاوهٔ شبکهٔ ۴۸ پیکسلی که با ماسک شعاعی محو می‌شود."),
            ("عنصر سه‌بعدی", "رندر انتزاعی با نور لبهٔ فیروزه‌ای (WebP شفاف، ۵۱KB دسکتاپ / ۴۲KB موبایل)؛ پایین تصویر با ماسک در زمینه حل می‌شود."),
            ("شیشه", "نوار جست‌وجو، دو کارت مسیر و دو برچسب شناور: blur(14px) و saturate(140%)، مرز سفید ۱۴٪، و نور داخلی یک‌پیکسلی در لبهٔ بالا. بی پشتیبانی از backdrop-filter: همان رنگ نیمه‌شفاف، بدون محوشدگی."),
            ("ورود", "متن‌ها پلکانی بالا می‌آیند (۷۰۰ms، فاصلهٔ ۸۰ms)، رندر از ۹۷٪ به ۱۰۰٪ (۱s)، برچسب‌ها و کارت‌ها پس از آن. همه زیر یک ثانیه و فقط یک بار."),
            ("حرکت مداوم", "شناوری آرام رندر (۱۰px، ۹ ثانیه) و برچسب‌ها (۶px، ۷ و ۸ ثانیه، خلاف هم)، و تپش نقطهٔ سبز برچسب بالا. هیچ چرخه‌ای سریع‌تر از ۲٫۸ ثانیه نیست."),
            ("نور اشاره‌گر", "روی دسکتاپ، هالهٔ فیروزه‌ای ۲۲rem دنبال اشاره‌گر می‌رود (۱ کیلوبایت JS، حداکثر یک نوشتن در هر فریم). روی لمسی و با حرکت کم‌شده خاموش است."),
            ("Hover", "کارت‌های مسیر ۳px بالا می‌آیند و دایرهٔ فلش فیروزه‌ای می‌شود؛ دکمهٔ جست‌وجو نوار نور می‌گیرد و فلشش ۴px جلو می‌رود؛ تمرکز جست‌وجو یک حلقهٔ نور ۴px می‌سازد."),
            ("حرکت کم‌شده", "با prefers-reduced-motion هیچ انیمیشن و انتقالی اجرا نمی‌شود و صفحه همان حالت نهایی است — تصویر پیش‌نمایش همین حالت است."),
        ],
    ),
    "light": dict(
        title="نمونهٔ Hero — سبک A: زمینهٔ روشن با گرادیان و عناصر سه‌بعدی",
        rows=[
            ("زمینه", "چهار لایه: سفید مایل به آبی (#FBFDFE ← #E3F0F3)، نور شعاعی فیروزه‌ای پشت رندر، مه آبی در گوشهٔ بالا، و هالهٔ سفید پشت شیء؛ شبکهٔ ظریف با رنگ نفتی ۶٪."),
            ("عنصر سه‌بعدی", "همان رندر با نور روز و سایهٔ تماس نرم روی کف (WebP شفاف، ۴۸KB / ۴۲KB)."),
            ("شیشه", "شیشهٔ سفید: پس‌زمینهٔ ۵۵–۷۲٪ سفید، blur(14px)، مرز سفید، و سایهٔ سه‌لایهٔ نفتی به‌جای تیرگی. متن همیشه روی سطح تقریباً سفید است تا کنتراست به محوشدگی وابسته نباشد."),
            ("ورود", "همان ترتیب سبک تیره: متن‌ها پلکانی، رندر با بزرگ‌شدن ملایم، سپس برچسب‌ها و کارت‌ها."),
            ("حرکت مداوم", "شناوری رندر و برچسب‌ها مثل سبک تیره؛ تپش نقطهٔ برچسب با رنگ فیروزه‌ای تیره."),
            ("نور اشاره‌گر", "هالهٔ فیروزه‌ای کم‌رنگ‌تر (۱۲٪) — روی زمینهٔ روشن نور قوی‌تر کثیف به‌نظر می‌رسد."),
            ("Hover", "کارت‌های مسیر از ۶۰٪ به ۹۰٪ سفید می‌روند و سایهٔ عمیق‌تر می‌گیرند؛ دایرهٔ فلش از نفتی به فیروزه‌ای."),
            ("حرکت کم‌شده", "مثل سبک تیره: هیچ حرکتی، حالت نهایی کامل."),
        ],
    ),
}

HEAD = """<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{title} (پیش‌نمایش)</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="../shared/prototype-frame.css">
  <link rel="stylesheet" href="concept-c.css">
  <script src="concept-c.js" defer></script>
</head>
<body class="proto-page">
{sprite}

<p class="proto-banner">{title} — داده‌ها نمونه‌اند و تصویر سه‌بعدی رندر انتزاعی است، نه عکس محصول.</p>

<main class="tecteb-home-v1 th-c" id="tecteb-home">

{hero}

</main>

<section class="proto-spec" aria-labelledby="proto-spec-title">
  <h2 id="proto-spec-title">افکت‌ها و رفتار حرکت</h2>
  <dl>
{rows}
  </dl>
</section>
</body>
</html>
"""

for variant, spec in SPECS.items():
    h = hero
    if variant == "light":
        h = h.replace("th-hero th-hero--dark", "th-hero th-hero--light")
        h = h.replace("hero-3d-dark-", "hero-3d-light-")
    rows = "\n".join(f"    <div><dt>{k}</dt><dd>{v}</dd></div>" for k, v in spec["rows"])
    out = HEAD.format(title=spec["title"], sprite=SPRITE_LINE, hero=h, rows=rows)
    (C / f"hero-{variant}.html").write_text(out, encoding="utf-8")
    print("wrote", f"hero-{variant}.html")
