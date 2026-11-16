#!/usr/bin/env python3
"""
§8's mutations: each one takes away exactly one thing the owner asked for, and
names the suite that must go red.

Run with `-I` so nothing in the working directory can be imported.
"""
import io
import sys

SHELL = 'src/Modules/Vendor/Presentation/VendorShell.php'
ROUTES = 'src/Modules/Vendor/Infrastructure/WordPress/VendorRoutes.php'
CSS = 'assets/vendor/tmc-vendor.css'

MUTATIONS = [
    # 1
    dict(name='the logout link is dropped from the account menu', file=SHELL,
         expect='contract',
         old="""            ['logout_url', __('خروج از حساب', 'tecteb-marketplace-core'), 'logout'],\n""",
         new=""),
    # 2
    dict(name='the shop name is a label again, never a control', file=SHELL,
         expect='contract + browser',
         old="""        if ($links === []) {
            return '<p class="tv-head__store">' . esc_html($storeName) . '</p>';
        }""",
         new="""        return '<p class="tv-head__store">' . esc_html($storeName) . '</p>';
        if ($links === []) {
            return '<p class="tv-head__store">' . esc_html($storeName) . '</p>';
        }"""),
    # 3
    dict(name='a page with no shop gets the account menu anyway', file=SHELL,
         expect='contract + browser',
         old="""        if ($storeName === '') {
            return '';
        }""",
         new="""        if (false) {
            return '';
        }"""),
    # 4
    dict(name='the summary gets an aria-label that hides the shop name', file=SHELL,
         expect='contract',
         old="""            . '<summary class="tv-acct__toggle">'
            . '<span class="tv-acct__store">'""",
         new="""            . '<summary class="tv-acct__toggle" aria-label="' . esc_attr($menuLabel) . '">'
            . '<span class="tv-acct__store">'"""),
    # 5
    dict(name='aria-current is dropped from the active menu item', file=SHELL,
         expect='contract',
         old="""                . ($isCurrent ? ' aria-current="page"' : '') . '>' . esc_html($item['label']) . '</a></li>';""",
         new="""                . '>' . esc_html($item['label']) . '</a></li>';"""),
    # 6
    dict(name='back to the site becomes the logout address', file=ROUTES,
         expect='architecture',
         old="""            'site_url' => home_url('/'),""",
         new="""            'site_url' => wp_logout_url(home_url('/')),"""),
    # 7
    dict(name='the shop link is printed whether the page exists or not', file=ROUTES,
         expect='architecture',
         old="""        if ($shop !== null) {
            $account['shop_url'] = (string) $shop['url'];
        }""",
         new="""        $account['shop_url'] = (string) ($shop['url'] ?? '');"""),
    # 8
    dict(name='the shell is never handed the account array', file=ROUTES,
         expect='architecture',
         old="""            $scripts,
            $this->accountFor($userId)
        );""",
         new="""            $scripts,
            []
        );"""),
    # 9
    dict(name='the vendor views stop handing renderShell the vendor id', file=ROUTES,
         expect='architecture',
         old="""                self::applicationTitle($workspace)
            ),
            $userId
        );
    }""",
         new="""                self::applicationTitle($workspace)
            )
        );
    }"""),
    # 10
    dict(name='the nav links lose the .tv prefix and go navy on navy', file=CSS,
         expect='browser (axe colour-contrast)',
         old=""".tv .tv-nav__link {
  display: flex; align-items: center; justify-content: flex-start; white-space: nowrap;""",
         new=""".tv-nav__link {
  display: flex; align-items: center; justify-content: flex-start; white-space: nowrap;"""),
    # 11
    # ⛔ BOTH CAUSES, because the first draft of this mutation put back only
    # the padding and the header still measured LOWER than before — «کم‌ارتفاع‌تر»
    # has two causes (the padding AND the brand sharing one line) and a
    # mutation that removes one of them measures nothing (`alpha.82`: a
    # mutation whose name does not match its effect is worse than none).
    dict(name='the header gets its old padding AND its stacked brand back', file=CSS,
         expect='browser (the header is lower)',
         old="""  padding: var(--tv-s2) var(--tv-s4);
  display: grid;
  gap: var(--tv-s2);
  border-block-end: 3px solid var(--tv-teal);""",
         new="""  padding: var(--tv-s4) var(--tv-s4) var(--tv-s3);
  display: grid;
  gap: var(--tv-s3);
  border-block-end: 3px solid var(--tv-teal);
}
.tv-head__brand { display: flex; flex-direction: column; align-items: stretch;"""),
    # 12
    # ⛔ AND BOTH CAUSES HERE TOO. Taking `nowrap` off alone changed nothing,
    # because one full-width column has room for every label; what broke them
    # was the TWO-COLUMN grid giving each item half the width and centring the
    # text in it. The mutation puts that back as well.
    dict(name='menu labels may break: two columns, centred, no nowrap', file=CSS,
         expect='browser (no label breaks)',
         old="""  display: flex; align-items: center; justify-content: flex-start; white-space: nowrap;
  min-height: 38px; padding: 0 var(--tv-s3);""",
         new="""  display: flex; align-items: center; justify-content: center; text-align: center;
  min-height: 38px; padding: 0 var(--tv-s3);
}
.tv-nav ul { grid-template-columns: 1fr 1fr;"""),
    # 13
    dict(name='the menu items become capsules again', file=CSS,
         expect='browser (not capsules)',
         old="""  background: rgba(255, 255, 255, 0.10); color: #fff; border: 1px solid transparent;
  border-radius: var(--tv-radius-sm); font-weight: 600; text-decoration: none;""",
         new="""  background: rgba(255, 255, 255, 0.10); color: #fff; border: 1px solid transparent;
  border-radius: 999px; font-weight: 600; text-decoration: none;"""),
    # 14
    # ⛔ MEASURED IN THE BROWSER, NOT IN THE CONTRAST TABLE. The first draft
    # of this mutation expected `tools/contrast.php` to go red and it did not:
    # that table asserts a PAIR of hex values and never reads the stylesheet,
    # so it cannot notice the stylesheet using a different one — `alpha.92`,
    # «a test asserting a constant has not measured its application». The
    # browser reads the computed colour of the mark's own pseudo-element.
    dict(name="the mark's cross goes white and drops below 3:1", file=CSS,
         expect='browser (the mark is readable)',
         old="""  background: var(--tv-ink); border-radius: 1px; transform: translate(50%, -50%);""",
         new="""  background: #fff; border-radius: 1px; transform: translate(50%, -50%);"""),
    # 15
    dict(name='the open account menu is pushed off the page edge', file=CSS,
         expect='browser (inside the viewport)',
         old="""  position: absolute; z-index: 20; inset-block-start: calc(100% + var(--tv-s1)); inset-inline-end: 0;""",
         new="""  position: absolute; z-index: 20; inset-block-start: calc(100% + var(--tv-s1)); inset-inline-end: -40rem;"""),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha107-mutations.py count|name <n>|file <n>|expect <n>|apply <n>')
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    if n < 1 or n > len(MUTATIONS):
        print('no such mutation', file=sys.stderr)
        return 2
    m = MUTATIONS[n - 1]
    if verb == 'name':
        print(m['name'])
        return 0
    if verb == 'file':
        print(m['file'])
        return 0
    if verb == 'expect':
        print(m['expect'])
        return 0
    if verb == 'apply':
        src = io.open(m['file'], encoding='utf-8').read()
        # ⛔ EXACTLY ONCE, IN THE WHOLE FILE. Two matches and the tool refuses
        # rather than guessing which one — «هیچ‌چیز سنجیده نشد» is
        # byte-for-byte like «this guard is unbreakable» (`alpha.88`).
        hits = src.count(m['old'])
        if hits != 1:
            print(f'snippet occurs {hits} times in {m["file"]}, refusing', file=sys.stderr)
            return 3
        io.open(m['file'], 'w', encoding='utf-8').write(src.replace(m['old'], m['new']))
        print('applied')
        return 0
    print('unknown verb', file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main())
