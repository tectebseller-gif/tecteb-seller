#!/usr/bin/env python3
"""مقایسهٔ پیکسل‌به‌پیکسل تصویرهای before/after (perf/tools/visual-shots.mjs).

    python3 perf/tools/visual-diff.py <before-dir> <after-dir> <out.txt>

برای هر تصویر: ابعاد، تعداد پیکسل‌های متفاوت و جعبهٔ دربرگیرندهٔ تفاوت؛ اگر تفاوتی هست، تصویر تفاوت (diff-*.png)
کنار out.txt ذخیره می‌شود تا با چشم بررسی شود.
"""
import pathlib
import sys

from PIL import Image, ImageChops

before, after, out = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), pathlib.Path(sys.argv[3])
lines, total = [], 0
for b in sorted(before.glob("*.png")):
    a = after / b.name
    if not a.exists():
        lines.append(f"{b.name}: MISSING after")
        total += 1
        continue
    ib, ia = Image.open(b).convert("RGB"), Image.open(a).convert("RGB")
    if ib.size != ia.size:
        lines.append(f"{b.name}: SIZE {ib.size} → {ia.size}")
        total += 1
        continue
    diff = ImageChops.difference(ib, ia)
    box = diff.getbbox()
    n = sum(1 for px in diff.getdata() if px != (0, 0, 0)) if box else 0
    total += 1 if n else 0
    lines.append(f"{b.name}: {ib.size[0]}×{ib.size[1]} — {n} differing pixels" + (f", box {box}" if box else ""))
    if n:
        diff.point(lambda v: 255 if v else 0).save(out.parent / f"diff-{b.name}")
lines.append(f"\n{total} of {len(list(before.glob('*.png')))} screenshots differ")
out.write_text("\n".join(lines) + "\n", encoding="utf-8")
print("\n".join(lines))
