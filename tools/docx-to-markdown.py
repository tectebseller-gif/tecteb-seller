import zipfile, sys, re
import xml.etree.ElementTree as ET

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'

def text_of_run(r):
    out = []
    for node in r.iter():
        tag = node.tag
        if tag == W+'t':
            out.append(node.text or '')
        elif tag == W+'tab':
            out.append('\t')
        elif tag in (W+'br', W+'cr'):
            out.append('\n')
    return ''.join(out)

def para_text(p):
    out = []
    for child in p:
        if child.tag == W+'r':
            out.append(text_of_run(child))
        elif child.tag == W+'hyperlink':
            for r in child.findall(W+'r'):
                out.append(text_of_run(r))
        elif child.tag in (W+'ins',):
            for r in child.iter(W+'r'):
                out.append(text_of_run(r))
    return ''.join(out)

def para_style(p):
    pPr = p.find(W+'pPr')
    if pPr is None: return None, None
    st = pPr.find(W+'pStyle')
    style = st.get(W+'val') if st is not None else None
    numPr = pPr.find(W+'numPr')
    ilvl = None
    if numPr is not None:
        il = numPr.find(W+'ilvl')
        ilvl = int(il.get(W+'val')) if il is not None else 0
    return style, ilvl

def heading_level(style):
    if not style: return None
    m = re.match(r'^Heading(\d)$', style)
    if m: return int(m.group(1))
    m = re.match(r'^heading\s*(\d)$', style, re.I)
    if m: return int(m.group(1))
    if style in ('Title',): return 1
    if style in ('Subtitle',): return 2
    return None

def render_cell(tc):
    parts = []
    for child in tc:
        if child.tag == W+'p':
            t = para_text(child).strip()
            parts.append(t)
        elif child.tag == W+'tbl':
            parts.append('[nested-table]')
    return ' <br> '.join([x for x in parts if x != '']) or ''

def grid_span(tc):
    tcPr = tc.find(W+'tcPr')
    if tcPr is None: return 1
    gs = tcPr.find(W+'gridSpan')
    return int(gs.get(W+'val')) if gs is not None else 1

def render_table(tbl):
    rows = []
    for tr in tbl.findall(W+'tr'):
        cells = []
        for tc in tr.findall(W+'tc'):
            txt = render_cell(tc).replace('|', '\\|')
            cells.append(txt)
            for _ in range(grid_span(tc)-1):
                cells.append('')
        rows.append(cells)
    if not rows: return ''
    width = max(len(r) for r in rows)
    rows = [r + ['']*(width-len(r)) for r in rows]
    out = []
    out.append('| ' + ' | '.join(rows[0]) + ' |')
    out.append('|' + '---|'*width)
    for r in rows[1:]:
        out.append('| ' + ' | '.join(r) + ' |')
    return '\n'.join(out)

def convert(path):
    z = zipfile.ZipFile(path)
    root = ET.fromstring(z.read('word/document.xml'))
    body = root.find(W+'body')
    out = []
    for el in body:
        if el.tag == W+'p':
            style, ilvl = para_style(el)
            txt = para_text(el).rstrip()
            hl = heading_level(style)
            if txt.strip() == '' and hl is None:
                out.append('')
                continue
            if hl:
                out.append('#'*min(hl,6) + ' ' + txt.strip())
            elif ilvl is not None:
                out.append('  '*ilvl + '- ' + txt.strip())
            else:
                out.append(txt)
        elif el.tag == W+'tbl':
            out.append('')
            out.append(render_table(el))
            out.append('')
    # collapse >2 blank lines
    md = '\n'.join(out)
    md = re.sub(r'\n{3,}', '\n\n', md)
    return md

if __name__ == '__main__':
    sys.stdout.write(convert(sys.argv[1]))
