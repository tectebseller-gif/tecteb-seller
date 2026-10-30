#!/usr/bin/env python3
"""
Falsification برای نمونهٔ هدر: هر گارد یک بار شکسته می‌شود و آزمون مرورگری باید قرمز شود.

    AXE=<axe.min.js> NODE_PATH=<playwright> python3 homepage-redesign/header/tests/falsify.py

هر جهش روی یک فایل اعمال، سوییت اجرا، و فایل بایت‌به‌بایت برگردانده می‌شود (finally).
needle ای که دقیقاً یک بار نباشد خودش شکست است — «هیچ چیز سنجیده نشد» نباید شبیه
«گارد شکستنی نیست» باشد. پس از هر جهش صفحه‌ها از نو ساخته نمی‌شوند: CSS و JS مستقیم
از همان فایل‌ها بارگذاری می‌شوند.
"""
from __future__ import annotations

import hashlib
import os
import pathlib
import subprocess
import sys

HERE = pathlib.Path(__file__).resolve().parents[1]

MUTATIONS = [
    ("reserved height removed (content jumps when the bar becomes fixed)", "shared/header-base.css",
     "  min-block-size: var(--tth-reserve, auto);\n", ""),
    ("a global selector slipped into the header CSS", "b/header-b.css",
     "/* نوار باریک برند", "body { margin: 0; }\n/* نوار باریک برند"),
    ("Escape no longer returns focus to the button", "shared/header.js",
     "      if (focus) d.btn.focus();\n", ""),
    ("menu row kept on mobile (no drawer)", "shared/header-base.css",
     "  .tecteb-header-v1[data-js] .tth-nav { display: none; }\n", ""),
    ("placeholder contrast lowered in B", "b/header-b.css",
     "  --tth-search-ph: #56697a;", "  --tth-search-ph: #9aaab5;"),
    ("counter back on the icon's corner (overlap)", "shared/header-base.css",
     ".tecteb-header-v1 .tth-tool--count .tth-badge {\n", ".tecteb-header-v1 .tth-tool--count .tth-badge {\n  position: absolute;\n  inset-block-start: 0.125rem;\n  inset-inline-end: 0.125rem;\n"),
    ("header search hidden on every page, not only the homepage", "shared/header-base.css",
     "  .tecteb-header-v1--home[data-js]:not(.is-search-open) .tth-search { display: none; }",
     "  .tecteb-header-v1[data-js]:not(.is-search-open) .tth-search { display: none; }"),
    ("search button opens the box but leaves focus on the button", "shared/header.js",
     "      if (open) form.querySelector('input[type=\"search\"]').focus();\n", "      if (open) { /* فوکوس جابه‌جا نمی‌شود */ }\n"),
    ("mobile account label removed from screen readers too (round-7 defect)", "shared/header-base.css",
     "  .tecteb-header-v1 .tth-tool__label {\n    position: absolute;\n", "  .tecteb-header-v1 .tth-tool__label {\n    display: none;\n    position: absolute;\n"),
]


def main() -> int:
    picks = [int(a) for a in sys.argv[1:]] or list(range(1, len(MUTATIONS) + 1))
    env = dict(os.environ, OUT=str(HERE / "evidence-falsify"))
    bad = 0
    lines = []
    for n in picks:
        name, rel, needle, repl = MUTATIONS[n - 1]
        path = HERE / rel
        original = path.read_bytes()
        digest = hashlib.sha256(original).hexdigest()
        text = original.decode("utf-8")
        if text.count(needle) != 1:
            lines.append(f"{n} NOT APPLIED ({text.count(needle)} matches) — {name}")
            bad += 1
            continue
        try:
            path.write_text(text.replace(needle, repl), encoding="utf-8")
            p = subprocess.run(["node", str(HERE / "tests/run.mjs")], capture_output=True, text=True, env=env)
            fails = [ln for ln in p.stdout.splitlines() if ln.startswith("FAIL")]
        finally:
            path.write_bytes(original)
        restored = hashlib.sha256(path.read_bytes()).hexdigest() == digest
        if fails and restored:
            lines.append(f"{n} CAUGHT  {len(fails)} failing — {name}\n     first: {fails[0][:160]}")
        else:
            bad += 1
            lines.append(f"{n} {'SURVIVED' if not fails else 'NOT RESTORED'} — {name}")
        print(lines[-1], flush=True)
    lines.append(f"{len(picks) - bad} of {len(picks)} mutations caught and restored")
    print(lines[-1])
    (HERE / "evidence/falsification.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
    subprocess.run(["rm", "-rf", str(HERE / "evidence-falsify")])
    return 1 if bad else 0


if __name__ == "__main__":
    raise SystemExit(main())
