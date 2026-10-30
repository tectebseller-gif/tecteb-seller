import re, sys, os, json
# Measure every translatable UI string in the admin surface: how long is it,
# in characters (not bytes — Persian is two bytes a letter).
PAT = re.compile(r"(?:esc_html__|esc_attr__|__)\(\s*'((?:[^'\\]|\\.)*)'", re.S)
roots = sys.argv[1:]
rows = []
for root in roots:
    for dirpath, _dirs, files in os.walk(root):
        for f in files:
            if not f.endswith('.php'):
                continue
            p = os.path.join(dirpath, f)
            src = open(p, encoding='utf-8').read()
            # blank out comments first: a docblock is not a screen
            src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
            src = re.sub(r'(?m)^\s*//.*$', '', src)
            for m in PAT.finditer(src):
                s = m.group(1)
                n = len(s)
                line = src.count('\n', 0, m.start()) + 1
                rows.append((n, p, line, s))
rows.sort(reverse=True, key=lambda r: r[0])
over = [r for r in rows if r[0] >= 120]
print(f"ui_strings_total={len(rows)}")
print(f"ui_strings_over_120_chars={len(over)}")
print(f"ui_strings_over_200_chars={len([r for r in rows if r[0] >= 200])}")
byfile = {}
for n, p, line, s in over:
    byfile.setdefault(p, []).append(n)
print("--- files with long strings (count, max) ---")
for p, ns in sorted(byfile.items(), key=lambda kv: (-len(kv[1]), kv[0])):
    print(f"{len(ns):3d}  max={max(ns):4d}  {p}")
print("--- the twelve longest ---")
for n, p, line, s in rows[:12]:
    print(f"{n:4d}  {p}:{line}")
    print(f"      {s[:110]}…")
