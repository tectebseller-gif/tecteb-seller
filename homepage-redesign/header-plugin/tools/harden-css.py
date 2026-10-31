#!/usr/bin/env python3
"""
CSS ِ ارسالی هدر = تبدیلِ قطعی و بی‌دستِ دو فایل تأییدشدهٔ طرح B:

  header/shared/header-base.css + header/b/header-b.css  ⇒  tecteb-header/assets/css/header.css

    python3 tools/harden-css.py          # می‌نویسد
    python3 tools/harden-css.py --check  # فقط می‌سنجد که فایل ارسالی همان خروجی است

تبدیل (و هیچ چیز دیگر):
  ۱. هر `.tecteb-header-v1` در **انتخابگرها** (نه در توضیح‌ها) ⇒ `#tth-root.tecteb-header-v1`.
     ریشهٔ هدر id="tth-root" دارد و در هر صفحه فقط یک هدر چاپ می‌شود. همهٔ قاعده‌ها یکسان یک ID
     می‌گیرند، پس ترتیب و برتری قاعده‌های خودِ طرح میان خودشان عوض نمی‌شود، ولی هر CSS ِ سراسری
     بی ID (Kit ِ Elementor مثل `.elementor-kit-N input:not(...):not(...)`، قالب، Custom CSS) کم می‌آورد.
  ۲. یک لایهٔ ایزوله پیش از همه: هر فرزند هدر (جز SVG و درونش، و img که اندازه‌اش از ویژگی
     width/height است) با `all: revert` به پیش‌فرض مرورگر برمی‌گردد؛ قاعده‌های طرح که بعد می‌آیند و
     هم‌وزن یا سنگین‌ترند، دوباره سبک خودشان را می‌گذارند. برای svg و img فقط ویژگی‌هایی که پیش‌فرض
     مرورگرشان روشن است (شفافیت، فیلتر، تبدیل، حاشیه، …) برگردانده می‌شوند؛ fill/stroke ِ درون sprite
     دست نمی‌خورد، چون ویژگی‌های ارائه‌ای SVG را باطل می‌کرد.
"""
from __future__ import annotations

import hashlib
import pathlib
import re
import sys

HERE = pathlib.Path(__file__).resolve().parents[1]
SOURCES = [HERE.parent / "header/shared/header-base.css", HERE.parent / "header/b/header-b.css"]
OUT = HERE / "tecteb-header/assets/css/header.css"
ROOT = "#tth-root.tecteb-header-v1"

ISOLATION = f"""/* ---- لایهٔ ایزوله (tools/harden-css.py) */
{ROOT} :where(:not(svg, svg *, img)),
{ROOT} :where(:not(svg, svg *, img))::before,
{ROOT} :where(:not(svg, svg *, img))::after {{ all: revert; }}
{ROOT} :where(svg) {{ opacity: 1; filter: none; transform: none; margin: 0; padding: 0; border: 0; box-shadow: none; float: none; visibility: visible; max-width: none; }}
{ROOT} :where(img) {{ border: 0; padding: 0; margin: 0; box-shadow: none; opacity: 1; border-radius: 0; float: none; vertical-align: middle; max-width: none; }}
{ROOT} {{ letter-spacing: normal; word-spacing: normal; text-transform: none; font-style: normal; font-weight: 400; text-indent: 0; text-shadow: none; white-space: normal; }}
"""


def transform(css: str) -> str:
    parts = re.split(r"(/\*.*?\*/)", css, flags=re.S)
    return "".join(p if p.startswith("/*") else p.replace(".tecteb-header-v1", ROOT) for p in parts)


def build() -> str:
    head = ["/*", " * tecteb-header — CSS ِ هدر B، ساخته‌شده با tools/harden-css.py؛ ویرایش دستی نکنید.", " * منبع (بایت‌به‌بایت، تأییدشده):"]
    for s in SOURCES:
        head.append(f" *   {s.relative_to(HERE.parent)}  sha256 {hashlib.sha256(s.read_bytes()).hexdigest()}")
    head.append(" */\n")
    body = "\n".join(transform(s.read_text(encoding="utf-8")) for s in SOURCES)
    return "\n".join(head) + ISOLATION + "\n" + body


if __name__ == "__main__":
    css = build()
    if "--check" in sys.argv:
        ok = OUT.exists() and OUT.read_text(encoding="utf-8") == css
        print("header.css is the harden-css.py output" if ok else "header.css differs from harden-css.py output", file=sys.stderr if not ok else sys.stdout)
        sys.exit(0 if ok else 1)
    OUT.write_text(css, encoding="utf-8")
    print(OUT)
