#!/usr/bin/env python3
"""
Concept C ← افزونه: دارایی‌های تولید را از پیش‌نمایش تأییدشده می‌سازد.

پیش‌نمایش مرجع است و دست‌نویس دوباره نوشته نمی‌شود؛ این اسکریپت فقط سه کار
می‌کند و هر سه قابل تکرارند:

1. CSS: هر قاعده‌ای که فقط مال پیش‌نمایش است (برچسب «نمونه»، «تصویر نمایشی»،
   زیرنویس Hero و Hero روشنِ انتخاب‌نشده) برداشته می‌شود؛ @font-face به فایل
   جدای فونت می‌رود تا اگر فونت سایت انتخاب شد اصلاً بارگذاری نشود؛ انتخابگر
   <picture> به پوستهٔ تصویر وردپرس نگاشت می‌شود؛ و سخت‌سازی در برابر Hello و
   kit ِ Elementor (src/hardening.css) به انتها اضافه می‌شود.
2. JS: concept-c.js بی‌تغییر کپی می‌شود.
3. sprite آیکون‌ها از index.html به templates/sprite.svg.

اجرا:  python3 homepage-redesign/plugin/tools/port-assets.py
"""
from __future__ import annotations

import pathlib
import re
import sys

HERE = pathlib.Path(__file__).resolve().parent
PLUGIN = HERE.parent / "tecteb-homepage"
CONCEPT = HERE.parent.parent / "concept-c"

# هر انتخابگری که یکی از این‌ها را دارد، مال پیش‌نمایش است.
PREVIEW_ONLY = re.compile(r"\.th-sample|\.th-imgnote|\.th-hero__note|\.th-hero--light")


def split_top(text: str) -> list[str]:
    """CSS را به بلوک‌های سطح بالا می‌شکند (قاعده یا at-rule با بدنه)، با کامنت‌ها."""
    out, depth, start, i, n = [], 0, 0, 0, len(text)
    while i < n:
        if text.startswith("/*", i):
            j = text.index("*/", i) + 2
            if depth == 0:
                out.append(text[start:i]); out.append(text[i:j]); start = j
            i = j
            continue
        c = text[i]
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0:
                out.append(text[start:i + 1]); start = i + 1
        i += 1
    out.append(text[start:])
    return [b for b in out if b.strip()]


def selectors_of(prelude: str) -> list[str]:
    parts, depth, cur = [], 0, ""
    for ch in prelude:
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        if ch == "," and depth == 0:
            parts.append(cur); cur = ""
        else:
            cur += ch
    parts.append(cur)
    return [p.strip() for p in parts if p.strip()]


PREVIEW_COMMENT = re.compile(r"نمونه|پیش‌نمایش|Hero A")


def clean(block: str, fonts: list[str]) -> str | None:
    if block.lstrip().startswith("/*"):
        return None if PREVIEW_COMMENT.search(block) else block
    head, _, body = block.partition("{")
    prelude = head.strip()
    if prelude.startswith("@font-face"):
        fonts.append(block.strip())
        return None
    if prelude.startswith("@"):
        inner = body.rsplit("}", 1)[0]
        kept = [b for b in (clean(x, fonts) for x in split_top(inner)) if b is not None]
        kept = [b for b in kept if not b.lstrip().startswith("/*")]
        if not kept:
            return None
        return prelude + " {\n" + "\n".join("  " + b.strip() for b in kept) + "\n}"
    sels = [s for s in selectors_of(prelude) if not PREVIEW_ONLY.search(s)]
    if not sels:
        return None
    return ",\n".join(sels) + " {" + body


def main() -> int:
    src = (CONCEPT / "concept-c.css").read_text(encoding="utf-8")
    src = src.replace(".th-product__media picture", ".th-product__media .th-product__pic")
    fonts: list[str] = []
    blocks = [b for b in (clean(x, fonts) for x in split_top(src)) if b is not None]
    css = "\n\n".join(b.strip() for b in blocks) + "\n"
    if PREVIEW_ONLY.search(css):
        print("preview-only selector survived", file=sys.stderr)
        return 1
    hardening = (HERE.parent / "src" / "hardening.css").read_text(encoding="utf-8")
    header = (
        "/* tecteb-homepage — ساخته‌شده از concept-c/concept-c.css با tools/port-assets.py.\n"
        " * دستی ویرایش نکنید: پیش‌نمایش مرجع است و سخت‌سازی در src/hardening.css. */\n\n"
    )
    (PLUGIN / "assets/css/tecteb-home.css").write_text(header + css + "\n" + hardening, encoding="utf-8")

    if len(fonts) != 1:
        print(f"expected one @font-face, got {len(fonts)}", file=sys.stderr)
        return 1
    font = fonts[0].replace("../shared/fonts/", "../fonts/")
    (PLUGIN / "assets/css/tecteb-home-font.css").write_text(
        "/* فقط وقتی بارگذاری می‌شود که «فونت طرح» انتخاب شده باشد. Vazirmatn، OFL-1.1. */\n" + font + "\n",
        encoding="utf-8",
    )

    js = (CONCEPT / "concept-c.js").read_text(encoding="utf-8")
    (PLUGIN / "assets/js/tecteb-home.js").write_text(js, encoding="utf-8")

    html = (CONCEPT / "index.html").read_text(encoding="utf-8")
    m = re.search(r"<svg[^>]*class=\"th-sprite\".*?</svg>", html, re.S)
    if not m:
        print("sprite not found", file=sys.stderr)
        return 1
    (PLUGIN / "templates/sprite.svg").write_text(m.group(0) + "\n", encoding="utf-8")
    print("ok", len(css), "bytes css")
    return 0


if __name__ == "__main__":
    sys.exit(main())
