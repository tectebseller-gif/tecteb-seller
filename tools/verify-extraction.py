#!/usr/bin/env python3
"""تطبیق ترتیبی متن DOCX با Markdown تولیدشده.

پوشش عددی کاراکتر اثبات وفاداری نیست: جابه‌جایی دو بند، افتادن یک سلول و
ادغام دو سلول همگی می‌توانند شمارش یکسان بدهند. این ابزار به‌جای شمارش،
دو «دنباله واحدهای متنی» می‌سازد و آن‌ها را عضوبه‌عضو و در ترتیب مقایسه
می‌کند.

واحد متنی = متن یک پاراگراف بدنه، یا متن یک پاراگراف داخل یک سلول جدول.
هر واحد با مسیر ساختاری‌اش برچسب می‌خورد (block/row/cell) تا اختلاف قابل
مکان‌یابی باشد.

خروج ۰ = دنباله‌ها یکسان‌اند. خروج ۱ = اختلاف دارند (با گزارش).
"""
import re, sys, zipfile
import xml.etree.ElementTree as ET
from difflib import SequenceMatcher

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'


def run_text(r):
    out = []
    for n in r.iter():
        if n.tag == W + 't':
            out.append(n.text or '')
        elif n.tag == W + 'tab':
            out.append('\t')
        elif n.tag in (W + 'br', W + 'cr'):
            out.append('\n')
    return ''.join(out)


def para_text(p):
    out = []
    for c in p:
        if c.tag == W + 'r':
            out.append(run_text(c))
        elif c.tag == W + 'hyperlink':
            for r in c.findall(W + 'r'):
                out.append(run_text(r))
        elif c.tag == W + 'ins':
            for r in c.iter(W + 'r'):
                out.append(run_text(r))
    return ''.join(out)


def norm(s):
    """فقط نویز نمایشی حذف می‌شود، نه محتوا."""
    s = s.replace('‌', '‌')          # نیم‌فاصله حفظ می‌شود
    s = s.replace('\t', ' ').replace('\n', ' ')
    return re.sub(r'\s+', ' ', s).strip()


def docx_units(path):
    """دنباله واحدها به ترتیب دقیق سند."""
    root = ET.fromstring(zipfile.ZipFile(path).read('word/document.xml'))
    body = root.find(W + 'body')
    units = []
    for bi, el in enumerate(body):
        if el.tag == W + 'p':
            t = norm(para_text(el))
            if t:
                units.append((f'block{bi}/p', t))
        elif el.tag == W + 'tbl':
            for ri, tr in enumerate(el.findall(W + 'tr')):
                for ci, tc in enumerate(tr.findall(W + 'tc')):
                    for pi, p in enumerate(tc.findall(W + 'p')):
                        t = norm(para_text(p))
                        if t:
                            units.append((f'block{bi}/r{ri}/c{ci}/p{pi}', t))
    return units


SEP = re.compile(r'^\|(\s*-{3,}\s*\|)+$')


def md_units(path):
    """همان دنباله، بازسازی‌شده از Markdown."""
    units = []
    for ln, raw in enumerate(open(path, encoding='utf-8').read().split('\n'), 1):
        line = raw.rstrip()
        if not line.strip():
            continue
        if line.lstrip().startswith('|'):
            if SEP.match(line.strip()):
                continue
            body = line.strip()
            body = body[1:] if body.startswith('|') else body
            body = body[:-1] if body.endswith('|') else body
            # تقسیم روی | که escape نشده
            cells = re.split(r'(?<!\\)\|', body)
            for ci, cell in enumerate(cells):
                cell = cell.replace('\\|', '|').strip()
                if not cell:
                    continue
                for pi, part in enumerate(cell.split(' <br> ')):
                    t = norm(part)
                    if t:
                        units.append((f'line{ln}/c{ci}/p{pi}', t))
        else:
            t = line
            t = re.sub(r'^#{1,6}\s+', '', t)        # سرفصل
            t = re.sub(r'^\s*-\s+', '', t)          # آیتم فهرست
            t = re.sub(r'^\s*1\.\s+', '', t)         # آیتم شماره‌دار تولیدشده
            t = norm(t)
            if t:
                units.append((f'line{ln}/p', t))
    return units


def compare(name, docx_path, md_path):
    d = docx_units(docx_path)
    m = md_units(md_path)
    dt = [t for _, t in d]
    mt = [t for _, t in m]
    print(f'=== {name} ===')
    print(f'واحد متنی در DOCX : {len(dt)}')
    print(f'واحد متنی در MD   : {len(mt)}')

    sm = SequenceMatcher(None, dt, mt, autojunk=False)
    ops = [o for o in sm.get_opcodes() if o[0] != 'equal']
    matched = sum(o2 - o1 for tag, o1, o2, _, _ in sm.get_opcodes() if tag == 'equal')
    print(f'واحد یکسان و هم‌ترتیب: {matched}')
    print(f'نسبت تطبیق ترتیبی: {matched / max(len(dt), 1) * 100:.2f}%')

    if not ops:
        print('اختلاف: هیچ. دنباله‌ها عضوبه‌عضو و در ترتیب یکسان‌اند.')
        return 0

    print(f'اختلاف: {len(ops)} ناحیه')
    for tag, i1, i2, j1, j2 in ops:
        print(f'  [{tag}] DOCX[{i1}:{i2}] ↔ MD[{j1}:{j2}]')
        for k in range(i1, min(i2, i1 + 4)):
            print(f'      - DOCX {d[k][0]}: {dt[k][:110]}')
        for k in range(j1, min(j2, j1 + 4)):
            print(f'      + MD   {m[k][0]}: {mt[k][:110]}')
    return 1


if __name__ == '__main__':
    rc = 0
    rc |= compare('Master Spec v0.3',
                  'docs/Tecteb-Marketplace-Core-Master-Spec-v0.3.docx',
                  'docs/generated/Tecteb-Marketplace-Core-Master-Spec-v0.3.md')
    print()
    rc |= compare('UX Spec v0.2',
                  'docs/Tecteb-Marketplace-Core-UX-Wireframe-Spec-v0.2.docx',
                  'docs/generated/Tecteb-Marketplace-Core-UX-Wireframe-Spec-v0.2.md')
    sys.exit(rc)
