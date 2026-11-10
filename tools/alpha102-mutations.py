#!/usr/bin/env python3
"""The mutations for alpha.102 — each one a fix of this round put back wrong.

Run through `tools/alpha102-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` must occur EXACTLY ONCE in its file (`alpha.88`), and each name
says what it BREAKS rather than what it edits (`alpha.82`).
"""
import hashlib
import pathlib
import sys

SCOPE = "src/Modules/Finance/Application/ForeignDiscountScope.php"
CART_GUARD = "src/Modules/Finance/Infrastructure/WooCommerce/CommissionCartGuard.php"
POLICY = "src/Modules/Product/Application/DeactivationStopPolicy.php"
DEACTIVATOR = "src/Infrastructure/WordPress/Lifecycle/Deactivator.php"
CATALOG = "src/Core/Audit/AuditEventCatalog.php"
CONTROLLER = "src/Modules/Operations/Infrastructure/Rest/MarketplaceController.php"
STOP = "src/Modules/Product/Application/StorefrontStop.php"
REDIRECTS = "src/Modules/Vendor/Infrastructure/WordPress/DokanUrlRedirects.php"
LEDGER_PAGE = "src/Modules/Order/Presentation/Admin/OrderLedgerPage.php"
PRODUCT_CSV = "src/Modules/Product/Application/ProductCsv.php"
FINANCE_AREA = "src/Modules/Finance/Infrastructure/WordPress/FinanceArea.php"

MUTATIONS = [
    # ── §4 the coupon, the P0 the owner measured on staging ─────────────────
    {
        "name": "the native coupon is applied again instead of refused",
        "file": CART_GUARD,
        "expect": "database",
        "old": "            throw new \\Exception(self::globalCouponMessage($verdict['product']));",
        "new": "            return $valid;",
    },
    {
        "name": "the refusal returns false, so the buyer loses the sentence",
        "file": CART_GUARD,
        "expect": "database",
        "old": "            throw new \\Exception(self::globalCouponMessage($verdict['product']));",
        "new": "            return false;",
    },
    {
        "name": "an unreadable basket refuses every coupon on the site",
        "file": CART_GUARD,
        "expect": "database",
        "old": "        if ($lines === []) {\n            return null;\n        }",
        "new": "        if ($lines === []) {\n            return ['ok' => false, 'reason' => ForeignDiscountScope::FOREIGN_CODE,\n                'product' => '', 'vendor' => 0];\n        }",
    },
    {
        "name": "a code whose reach is unknown is allowed through",
        "file": SCOPE,
        "expect": "unit",
        "old": "            if (($line['touches'] ?? null) === false) {",
        "new": "            if (($line['touches'] ?? null) !== true) {",
    },
    {
        "name": "the vendor's own coupon is refused and a foreign one is not",
        "file": SCOPE,
        "expect": "unit,database",
        "old": "            if (($line['owned'] ?? false) === true) {",
        "new": "            if (($line['owned'] ?? false) === false) {",
    },
    {
        "name": "the host shop's own goods are treated as a vendor's",
        "file": SCOPE,
        "expect": "unit",
        "old": "            if (!is_int($vendor) || $vendor <= 0) {",
        "new": "            if (false) {",
    },

    # ── §6 deactivation ─────────────────────────────────────────────────────
    {
        "name": "deactivation drafts the catalogue again, with nobody asking",
        "file": DEACTIVATOR,
        "expect": "contract",
        "old": "        if ($alsoStop) {",
        "new": "        if (true) {",
    },
    {
        # ⛔ `architecture`, not `contract`: measured unreachable. The only
        # test that runs `Deactivator` boots a minimal container where
        # `ProductRepositoryInterface` is not registered, so the wrapped
        # `get()` throws and the documented answer is `0` — identical to this
        # mutant. The property is asserted in the source instead
        # (`DeactivationCountsWhatItLeftBehindTest`), per `alpha.77`/`alpha.95`.
        "name": "what is left on sale is never counted, so nothing says so",
        "file": DEACTIVATOR,
        "expect": "architecture",
        "old": "            $leftOnSale = self::noteWhatIsLeftOnSale();",
        "new": "            $leftOnSale = 0;",
    },
    {
        "name": "the audit row drops the two keys that say which path ran",
        "file": CATALOG,
        "expect": "contract",
        "old": "            self::PLUGIN_DEACTIVATED => ['plugin_version', 'withdrawn', 'stopped_selling', 'left_on_sale'],",
        "new": "            self::PLUGIN_DEACTIVATED => ['plugin_version', 'withdrawn'],",
    },
    {
        "name": "any stored value counts as the owner having said yes",
        "file": POLICY,
        "expect": "unit",
        "old": "            return in_array(strtolower(trim($stored)), ['1', 'yes', 'true', 'on'], true);",
        "new": "            return $stored !== '';",
    },
    {
        "name": "the default becomes «stop», so an unanswered gate acts",
        "file": POLICY,
        "expect": "unit,contract",
        "old": "        return false;\n    }",
        "new": "        return true;\n    }",
    },

    # ── §2/§3 the REST routes that carry money ──────────────────────────────
    {
        "name": "the orders route takes the catalogue permission again",
        "file": CONTROLLER,
        "expect": "architecture",
        "old": "            'permission_callback' => [$this, 'mayReadVendorOrders'],",
        "new": "            'permission_callback' => [$this, 'mayReadVendor'],",
    },
    {
        "name": "reading order lines needs the catalogue instead of the money",
        "file": CONTROLLER,
        "expect": "architecture,database",
        "old": "            [StaffArea::Order, StaffLevel::View],\n            [StaffArea::Finance, StaffLevel::View],",
        "new": "            [StaffArea::Product, StaffLevel::View],",
    },
    {
        "name": "order access alone opens a payload carrying commission",
        "file": CONTROLLER,
        "expect": "architecture,database",
        "old": "            [StaffArea::Finance, StaffLevel::View],\n        ]);",
        "new": "        ]);",
    },

    # ── §6 the 500 cap, and the note a real stop answers ────────────────────
    {
        "name": "a stop that hit its cap reports success",
        "file": STOP,
        "expect": "database",
        "old": "        if ($outcome['failed'] > 0 || $outcome['unseen'] > 0) {",
        "new": "        if ($outcome['failed'] > 0) {",
    },
    {
        "name": "a real stop leaves the «left on sale» warning standing",
        "file": STOP,
        "expect": "database",
        "old": "        $this->switch->clearLeftOnSale();",
        "new": "",
    },

    # ── the old store address after Dokan is REMOVED, not just switched off ─
    {
        "name": "the store base is not remembered, so removal kills redirects",
        "file": REDIRECTS,
        "expect": "contract",
        "old": "                update_option(self::BASE_SNAPSHOT_OPTION, $live, false);",
        "new": "",
    },
    {
        "name": "every front-end hit writes an option while reading",
        "file": REDIRECTS,
        "expect": "contract",
        "old": "            if (is_admin() && self::rememberedBase() !== $live) {",
        "new": "            if (self::rememberedBase() !== $live) {",
    },

    # ── one CSV rule, two readers ───────────────────────────────────────────
    {
        "name": "the ledger export gets its own, contradictory escaping back",
        "file": LEDGER_PAGE,
        "expect": "unit,architecture",
        "old": """        return CsvCell::of(str_replace(["\\r", "\\n"], ' ', $value));""",
        "new": """        $flat = str_replace(["\\r", "\\n"], ' ', $value);
        if ($flat !== '' && in_array($flat[0], ['=', '+', '-', '@'], true)) {
            $flat = "\\t" . $flat;
        }
        return '"' . str_replace('"', '""', $flat) . '"';""",
    },
    {
        "name": "the formula list is typed out a second time",
        "file": PRODUCT_CSV,
        "expect": "architecture",
        "old": "    private const FORMULA_STARTERS = CsvCell::FORMULA_STARTERS;",
        "new": "    private const FORMULA_STARTERS = ['=', '+', '-', '@', \"\\t\", \"\\r\"];",
    },

    # ── a dispatcher that chooses its last verb by falling through ──────────
    {
        "name": "the finance dispatcher cancels for any unlisted verb again",
        "file": FINANCE_AREA,
        "expect": "architecture",
        "old": "        $result = match ($action) {\n            'request_withdrawal' => $service->handle($userId, $vendorUserId),\n            'cancel_withdrawal' => $service->cancel($userId, $vendorUserId, $request->postInt('withdrawal_id')),\n            default => null,\n        };",
        "new": "        $result = $action === 'request_withdrawal'\n            ? $service->handle($userId, $vendorUserId)\n            : $service->cancel($userId, $vendorUserId, $request->postInt('withdrawal_id'));",
    },
]


def main() -> int:
    root = pathlib.Path(__file__).resolve().parent.parent
    if len(sys.argv) < 2:
        print("usage: alpha102-mutations.py count|name <n>|file <n>|expect <n>|apply <n>")
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    index = int(sys.argv[2]) - 1
    if index < 0 or index >= len(MUTATIONS):
        print("no such mutation", file=sys.stderr)
        return 2
    m = MUTATIONS[index]
    if verb == "name":
        print(m["name"])
        return 0
    if verb == "file":
        print(m["file"])
        return 0
    if verb == "expect":
        print(m["expect"])
        return 0
    if verb != "apply":
        print("unknown verb", file=sys.stderr)
        return 2

    path = root / m["file"]
    text = path.read_text(encoding="utf-8")
    hits = text.count(m["old"])
    if hits != 1:
        # NOT applying is a failure, not a guard that cannot be broken.
        print(f"REFUSED: anchor occurs {hits} times in {m['file']}", file=sys.stderr)
        return 1
    before = hashlib.sha256(text.encode()).hexdigest()
    path.write_text(text.replace(m["old"], m["new"], 1), encoding="utf-8")
    after = hashlib.sha256(path.read_text(encoding="utf-8").encode()).hexdigest()
    if before == after:
        print("REFUSED: the file did not change", file=sys.stderr)
        return 1
    print(f"applied: {m['name']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
