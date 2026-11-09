#!/usr/bin/env python3
"""The mutations for alpha.100, each one a fix of this round put back wrong.

Run through `tools/alpha100-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` here must occur EXACTLY ONCE in its file (`alpha.88`), and each
one's name says what it BREAKS rather than what it edits (`alpha.82`).
"""
import hashlib
import pathlib
import sys

LEDGER_PAGE = "src/Modules/Order/Presentation/Admin/OrderLedgerPage.php"
LEDGER_MSG = "src/Modules/Order/Presentation/OrderLedgerMessages.php"
ADMIN_PATH = "src/Modules/Order/Domain/OrderAdminPath.php"
ADMIN_URL = "src/Modules/Order/Infrastructure/WooCommerce/WcOrderAdminUrl.php"
ITEM_REPO = "src/Modules/Order/Infrastructure/DbOrderItemRepository.php"
LEDGER_REPO = "src/Modules/Finance/Infrastructure/DbLedgerRepository.php"
REPORT = "src/Modules/Order/Application/OrderLedgerReport.php"
RECONCILE = "src/Modules/Order/Application/ReconcileOrders.php"
LINE_CHECK = "src/Modules/Order/Domain/LineReconciliation.php"
STOP = "src/Modules/Product/Application/StorefrontStop.php"
COMPONENTS = "src/Modules/Admin/Presentation/Components.php"
CAPTURE = "src/Modules/Order/Application/CaptureOrder.php"

MUTATIONS = [
    # ── §3-ب the table that could never be a table ──────────────────────────
    {
        "name": "the report table loses the card it needs to become a table",
        "file": LEDGER_PAGE,
        "expect": "architecture,database",
        "old": "        echo '<section class=\"tmc-card\"><h2 class=\"tmc-card__title\">'\n            . esc_html__('اقلام سفارش', 'tecteb-marketplace-core') . '</h2>';",
        "new": "        echo '<section><h2 class=\"tmc-card__title\">'\n            . esc_html__('اقلام سفارش', 'tecteb-marketplace-core') . '</h2>';",
    },
    # ── §3-ج the stacked cell with no label ─────────────────────────────────
    {
        "name": "a stacked cell stops saying which figure it holds",
        "file": LEDGER_PAGE,
        "expect": "architecture,database",
        "old": "            $out .= '<td' . $class . ' data-label=\"' . esc_attr($column['label']) . '\">'",
        "new": "            $out .= '<td' . $class . '>'",
    },
    # ── §3-هـ the HPOS link ─────────────────────────────────────────────────
    {
        "name": "every order link goes back to post.php, dead on HPOS",
        "file": ADMIN_PATH,
        "expect": "unit,contract,database",
        "old": "        return $hpos\n            ? 'admin.php?page=wc-orders&action=edit&id=' . $wcOrderId\n            : 'post.php?post=' . $wcOrderId . '&action=edit';",
        "new": "        return 'post.php?post=' . $wcOrderId . '&action=edit';",
    },
    {
        "name": "WooCommerce's own address helper is no longer preferred",
        "file": ADMIN_URL,
        "expect": "contract",
        "old": "        if (class_exists(self::UTIL, false) && method_exists(self::UTIL, 'get_order_admin_edit_url')) {",
        "new": "        if (false) {",
    },
    {
        "name": "an address is invented on a site with no WooCommerce",
        "file": ADMIN_URL,
        "expect": "contract",
        "old": "        if (!class_exists('WooCommerce', false) && !function_exists('WC')) {",
        "new": "        if (false) {",
    },
    # ── the placeholder ordering, the real bug the tests caught ─────────────
    {
        "name": "the SELECT's own placeholders are bound after the WHERE's",
        "file": ITEM_REPO,
        "expect": "database",
        "old": "            [$cancelled, '', $cancelled, $cancelled, ...$params]",
        "new": "            [...$params, $cancelled, '', $cancelled, $cancelled]",
    },
    # ── the sweep that called a truncated export «finished» ────────────────
    {
        "name": "a sweep cut off at its cap reports that it finished",
        "file": REPORT,
        "expect": "database",
        "old": "            if ($capped) {\n                return ['rows' => $sent, 'finished' => false, 'readable' => $readable];\n            }",
        "new": "            if (false) {\n                return ['rows' => $sent, 'finished' => false, 'readable' => $readable];\n            }",
    },
    # ── the state cell that was a paragraph ────────────────────────────────
    {
        "name": "the payment column holds a sentence again, one word per line",
        "file": LEDGER_MSG,
        "expect": "database",
        "old": "        if (!$readable || $wcStatus === null || trim($wcStatus) === '') {\n            return __('نامعلوم', 'tecteb-marketplace-core');\n        }",
        "new": "        if (!$readable || $wcStatus === null || trim($wcStatus) === '') {\n            return __('نامعلوم — از زمان نصب، وضعیتی برای این سفارش اعلام نشده', 'tecteb-marketplace-core');\n        }",
    },
    # ── §5 the reconciliation ───────────────────────────────────────────────
    {
        "name": "the audit reads the books before asking who is asking",
        "file": RECONCILE,
        "expect": "architecture,database",
        "old": "        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::VIEW_VENDOR_FINANCE)) {",
        "new": "        if (false) {",
    },
    {
        "name": "a cancelled line with no reversing document is called clean",
        "file": LINE_CHECK,
        "expect": "unit,database",
        "old": "        if ($cancelled && !$reversalPresent) {\n            $found[] = OrderInconsistency::CancelledWithoutReversal;\n        }",
        "new": "        if (false) {\n            $found[] = OrderInconsistency::CancelledWithoutReversal;\n        }",
    },
    {
        "name": "a reversal standing beside a live line is called clean",
        "file": LINE_CHECK,
        "expect": "unit,database",
        "old": "        if (!$cancelled && $reversalPresent) {",
        "new": "        if (false) {",
    },
    {
        "name": "a document in two units is no longer noticed",
        "file": LEDGER_REPO,
        "expect": "database",
        "old": "COUNT(DISTINCT CONCAT(currency, %s, exponent)) AS units",
        "new": "1 AS units",
    },
    # ── the whole-set summary ───────────────────────────────────────────────
    {
        "name": "the summary adds figures across two units of money",
        "file": REPORT,
        "expect": "database",
        "old": "                count($currencies) > 1 => 'mixed_currencies',\n                count($answer['groups']) > 1 => 'mixed_scales',",
        "new": "                false => 'mixed_currencies',\n                false => 'mixed_scales',",
    },
    # ── §8 the silent partial stop ──────────────────────────────────────────
    {
        "name": "a stop that reached its cap goes back to saying nothing",
        "file": STOP,
        "expect": "database",
        "old": "        return max(0, $total - $seen);",
        "new": "        return 0;",
    },
    {
        "name": "an unreadable count is read as «nothing left over»",
        "file": STOP,
        "expect": "unit",
        "old": "            return $seen >= $limit ? 1 : 0;",
        "new": "            return 0;",
    },
    # ── the reports strip ───────────────────────────────────────────────────
    {
        "name": "the reports strip stops asking who may see each place",
        "file": COMPONENTS,
        "expect": "architecture",
        "old": "            if (!current_user_can($spec['capability'])) {",
        "new": "            if (false) {",
    },
    # ── the commission base ─────────────────────────────────────────────────
    {
        "name": "the tax is folded into the commission base",
        "file": CAPTURE,
        "expect": "architecture",
        "old": "            $base = Money::of((int) ($line['line_total_minor'] ?? 0), $lineCurrency, $lineExponent);",
        "new": "            $base = Money::of((int) ($line['line_total_minor'] ?? 0) + (int) ($line['line_tax_minor'] ?? 0), $lineCurrency, $lineExponent);",
    },
]


def body(n: int) -> dict:
    if n < 1 or n > len(MUTATIONS):
        raise SystemExit(f"no mutation {n}; there are {len(MUTATIONS)}")
    return MUTATIONS[n - 1]


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: alpha100-mutations.py count|name <n>|file <n>|expect <n>|apply <n>")
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    m = body(n)
    if verb in ("name", "file", "expect"):
        print(m[verb])
        return 0
    if verb != "apply":
        print("unknown verb " + verb)
        return 2

    path = pathlib.Path(m["file"])
    text = path.read_text()
    found = text.count(m["old"])
    if found != 1:
        print(f"REFUSED: snippet occurs {found} time(s) in {m['file']}", file=sys.stderr)
        return 3
    path.write_text(text.replace(m["old"], m["new"]))
    print("applied: " + m["name"])
    print("sha256 after: " + hashlib.sha256(path.read_bytes()).hexdigest())
    return 0


if __name__ == "__main__":
    sys.exit(main())
