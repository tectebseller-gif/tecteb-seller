"""The number a shopper actually sees as the cart total.

WooCommerce splits a price across several tags with the currency symbol in the
middle, so a regex over the raw HTML finds either nothing or the wrong number —
which is exactly what the first version of this evidence did, reporting an
empty total while the discount line was plainly on the page.

This strips the markup first and then reads the totals table, which is what a
person does.
"""
import re
import sys

html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
wanted = sys.argv[2] if len(sys.argv) > 2 else 'order-total'

FA = '۰۱۲۳۴۵۶۷۸۹'


def as_latin(text):
    """The same text with Persian digits written the way a computer compares."""
    return ''.join(str(FA.index(ch)) if ch in FA else ch for ch in text)


if wanted == '--contains-number':
    # Does this page SHOW this number? Asked of the visible text rather than
    # of the markup, and after the digits and separators are normalised —
    # a grep for «960000» never matches a page that reads «۹۶۰٬۰۰۰», so a
    # negative check written that way would pass without measuring anything.
    visible = as_latin(re.sub(r'<[^>]+>', ' ', html))
    plain = re.sub(r'[,\.٬\s\u200c]', '', visible)
    print('yes' if re.sub(r'\D', '', sys.argv[3]) in plain else 'no')
    sys.exit(0)

# The row we want, from its class to the end of that table row.
row = re.search(r'class="[^"]*' + re.escape(wanted) + r'[^"]*"(.*?)</tr>', html, re.S)
block = row.group(1) if row else ''
text = re.sub(r'<[^>]+>', '', block)
text = text.replace('&nbsp;', ' ')
# Persian digits, Latin digits, and the separators WooCommerce puts between.
digits = re.findall(r'[-−]?[\d۰-۹][\d۰-۹,\.٬]*', text)
if not digits:
    print('')
    sys.exit(0)
raw = max(digits, key=len)
print(re.sub(r'[^\d-]', '', as_latin(raw)) or '')
