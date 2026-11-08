#!/usr/bin/env python3
"""تفاوت‌های دو تصویر را به نوارهای افقیِ جدا می‌شکند و برای هر نوار جعبه، شمار پیکسل و بیشترین تفاوت کانال را می‌دهد.

    python3 perf/tools/visual-bands.py <before-dir> <after-dir> <out.txt>
"""
import pathlib, sys
from PIL import Image, ImageChops
bd, ad, out = map(pathlib.Path, sys.argv[1:4])
lines = []
for b in sorted(bd.glob("*.png")):
    ib, ia = Image.open(b).convert("RGB"), Image.open(ad / b.name).convert("RGB")
    if ib.size != ia.size:
        lines.append(f"{b.name}: SIZE {ib.size} → {ia.size}"); continue
    d = ImageChops.difference(ib, ia); m = d.convert("L").point(lambda v: 255 if v else 0)
    W, H = m.size; bands = []
    for y in range(H):
        if m.crop((0, y, W, y + 1)).getbbox():
            if bands and y - bands[-1][1] <= 3: bands[-1][1] = y
            else: bands.append([y, y])
    parts = []
    for y0, y1 in bands:
        x0, _, x1, _ = m.crop((0, y0, W, y1 + 1)).getbbox(); reg = (x0, y0, x1, y1 + 1)
        dd = d.crop(reg); n = sum(1 for px in dd.getdata() if px != (0, 0, 0))
        parts.append(f"  y {y0}–{y1 + 1} x {x0}–{x1}: {n} px, max channel diff {max(e[1] for e in dd.getextrema())}")
    lines.append(f"{b.name}: {len(bands)} band(s)"); lines += parts
out.write_text("\n".join(lines) + "\n", encoding="utf-8"); print("\n".join(lines))
