#!/usr/bin/env python3
"""
چهار صفحهٔ نمونهٔ هدر را از یک الگو می‌سازد — فقط پیش‌نمایش محلی.

    python3 homepage-redesign/header/tools/build-pages.py [fragment.html]

ورودی‌ها:
  shared/header.html        ساختار مشترک هدر (یک بار، برای هر دو طرح)
  shared/home-0.2.0.html    خروجی واقعی [tecteb_home] ِ افزونهٔ ۰٫۲٫۰ از وردپرس محلی
                            (با آرگومان، از یک خروجی تازه دوباره ساخته می‌شود؛ tools/capture-home.sh)
خروجی: a-home.html · a-inner.html · b-home.html · b-inner.html · index.html

نشانی‌های 127.0.0.1 در خروجی افزونه به مسیر نسبی برگردانده می‌شوند و تصویرهای محصول
آزمایشی در demo-products/ کپی می‌شوند. هیچ چیزی از سایت واقعی در این صفحه‌ها نیست.
"""
from __future__ import annotations

import pathlib
import re
import shutil
import sys

HERE = pathlib.Path(__file__).resolve().parents[1]
WP_ROOT = pathlib.Path("/home/user/wp/core")
LOCAL = "http://127.0.0.1:8090"

# نمونهٔ نمایشی — در اجرا از product_cat خوانده می‌شود. نام‌ها دادهٔ سایت نیستند.
CATEGORIES = [
    ("c-monitor", "مانیتورینگ و علائم حیاتی", ["مانیتور علائم حیاتی", "پالس‌اکسیمتر", "فشارسنج", "دماسنج", "الکتروکاردیوگراف"]),
    ("c-or", "تجهیزات اتاق عمل", ["چراغ جراحی", "تخت جراحی", "الکتروکوتر", "ساکشن"]),
    ("c-lab", "آزمایشگاه", ["سانتریفیوژ", "میکروسکوپ", "انکوباتور", "پیپت و سمپلر"]),
    ("c-bed", "تجهیزات بیمارستانی", ["سرنگ‌پمپ", "انفوزیون‌پمپ", "تخت بیمار", "ترالی و پایه سرم"]),
    ("c-syringe", "ملزومات مصرفی", ["دستکش", "سرنگ و سوزن", "گاز و باند استریل", "ماسک"]),
    ("c-care", "توان‌بخشی و مراقبت در منزل", ["ویلچر", "واکر و عصا", "نبولایزر", "اکسیژن‌ساز"]),
]

PAGES = {
    "a": ("A", "Premium Dark Medical"),
    "b": ("B", "Premium Light Glass"),
}


def mega() -> str:
    cols = []
    for i, (icon, name, kids) in enumerate(CATEGORIES, 1):
        items = "".join(f'<li><a href="#cat-{i}-{j}" data-src="demo">{k}</a></li>' for j, k in enumerate(kids, 1))
        cols.append(
            f'<div class="tth-mega__col"><a class="tth-mega__head" href="#cat-{i}" data-src="demo">'
            f'<span class="tth-mega__dot"><svg class="tth-i" aria-hidden="true"><use href="#tth-i-{icon}"/></svg></span>{name}</a>'
            f'<ul class="tth-mega__list">{items}</ul></div>'
        )
    return "\n              ".join(cols)


def drawer_cats() -> str:
    out = []
    for i, (_, name, kids) in enumerate(CATEGORIES, 1):
        items = "".join(f'<li><a href="#cat-{i}-{j}" data-src="demo">{k}</a></li>' for j, k in enumerate(kids, 1))
        out.append(
            f'<details class="tth-drawer__cat"><summary>{name}<svg class="tth-i tth-i--chev" aria-hidden="true"><use href="#tth-i-chev"/></svg></summary>'
            f'<ul><li><a href="#cat-{i}" data-src="demo">همهٔ {name}</a></li>{items}</ul></details>'
        )
    return "\n        ".join(out)


def localise(fragment: str) -> str:
    """نشانی‌های وردپرس محلی ⇒ مسیر نسبی؛ تصویرها ⇒ کپی در کنار صفحه."""
    shots = HERE / "demo-products"
    shots.mkdir(exist_ok=True)

    def upload(m: re.Match) -> str:
        rel = m.group(1)
        src = WP_ROOT / "wp-content/uploads" / rel
        if src.exists():
            shutil.copy2(src, shots / src.name)
        return "demo-products/" + pathlib.Path(rel).name

    fragment = re.sub(re.escape(LOCAL) + r"/wp-content/uploads/([^\"' ,)]+)", upload, fragment)
    fragment = re.sub(re.escape(LOCAL) + r"/wp-content/plugins/tecteb-homepage/", "../plugin/tecteb-homepage/", fragment)
    fragment = fragment.replace(LOCAL + "/", "/")
    leftovers = re.findall(r"127\.0\.0\.1[^\"' ]*", fragment)
    if leftovers:
        raise SystemExit(f"unlocalised URL left: {leftovers[:3]}")
    return fragment


def page(variant: str, kind: str, header: str, home: str) -> str:
    letter, name = PAGES[variant]
    if kind == "home":
        main = home
        what = "زیر آن خروجی واقعی افزونهٔ صفحهٔ اصلی ۰٫۲٫۰ (Concept C) با دادهٔ آزمایشی محلی و نشانی‌های اعلام‌شدهٔ مالک است"
    else:
        main = ('<div class="proto-slot proto-slot--page" role="presentation">محتوای صفحه‌های دیگر سایت '
                '(فروشگاه، محصول، وبلاگ، حساب کاربری…) — در این مرحله بدون تغییر؛ فقط برای دیدن هدر روی صفحه‌ای غیر از صفحهٔ اصلی</div>')
        what = "زیر آن جای محتوای صفحه‌های دیگر است، تا هدر روی صفحه‌ای جز صفحهٔ اصلی هم دیده شود"
    nav = []
    for v, (l, _) in PAGES.items():
        for k, label in (("home", "صفحهٔ اصلی"), ("inner", "صفحهٔ دیگر")):
            href = f"{v}-{k}.html"
            cur = ' aria-current="page"' if (v, k) == (variant, kind) else ""
            nav.append(f'<a href="{href}"{cur}>طرح {l} · {label}</a>')
    return f"""<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="icon" href="data:,">
<title>هدر تک‌طب — طرح {letter} ({name}) · نمونهٔ طراحی</title>
<link rel="stylesheet" href="../shared/prototype-frame.css">
<link rel="stylesheet" href="../plugin/tecteb-homepage/assets/css/tecteb-home.css">
<link rel="stylesheet" href="shared/header-base.css">
<link rel="stylesheet" href="{variant}/header-{variant}.css">
<link rel="stylesheet" href="shared/proto.css">
<script src="shared/header.js" defer></script>
<script src="shared/proto.js" defer></script>
<script src="../plugin/tecteb-homepage/assets/js/tecteb-home.js" defer></script>
</head>
<body class="proto-page">
<p class="proto-banner">نمونهٔ طراحی هدر سراسری — <strong>طرح {letter} · {name}</strong>. {what}. پیوندها جایی نمی‌روند و با کلیک، مقصد و منبعشان گفته می‌شود. دسته‌های منوی بازشو <strong>نمونه</strong>‌اند. فوتر فعلی Elementor فقط با یک نوار جای‌خالی نشان داده شده و <strong>هیچ تغییری نمی‌کند</strong>.</p>
<div class="proto-bar">
  <span>{" · ".join(nav)}</span>
  <span>حالت کاربر: <button type="button" data-proto-user="guest" aria-pressed="true">مهمان</button> <button type="button" data-proto-user="member" aria-pressed="false">واردشده</button></span>
</div>
{header.replace("{{VARIANT}}", variant)}
<main id="main">
{main}
</main>
<div class="proto-slot proto-slot--footer" role="presentation">فوتر فعلی سایت (Elementor) — کاملاً بدون تغییر؛ این نوار فقط جای آن است</div>
</body>
</html>
"""


def main() -> int:
    src = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else None
    home_file = HERE / "shared/home-0.2.0.html"
    if src:
        home_file.write_text(localise(src.read_text(encoding="utf-8")), encoding="utf-8")
    home = home_file.read_text(encoding="utf-8")
    header = (HERE / "shared/header.html").read_text(encoding="utf-8")
    header = header.replace("{{ASSETS}}", "../shared/brand").replace("{{MEGA}}", mega()).replace("{{DRAWER_CATS}}", drawer_cats())
    for v in PAGES:
        for k in ("home", "inner"):
            (HERE / f"{v}-{k}.html").write_text(page(v, k, header, home), encoding="utf-8")
    print("built:", ", ".join(f"{v}-{k}.html" for v in PAGES for k in ("home", "inner")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
