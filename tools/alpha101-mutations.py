#!/usr/bin/env python3
"""The mutations for alpha.101, each one a fix of this round put back wrong.

Run through `tools/alpha101-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` must occur EXACTLY ONCE in its file (`alpha.88`), and each name
says what it BREAKS rather than what it edits (`alpha.82`).
"""
import hashlib
import pathlib
import sys

GATE = "src/Modules/Order/Application/OrderOperationsGate.php"
SETTLE_GATE = "src/Modules/Finance/Application/SettlementGate.php"
WITHDRAW = "src/Modules/Finance/Application/VendorWithdrawalPolicy.php"
ELIGIBILITY = "src/Modules/Order/Domain/SettlementEligibility.php"
MANAGE_ITEMS = "src/Modules/Order/Application/ManageOrderItems.php"
DISCOUNT = "src/Modules/Finance/Application/VendorFundedDiscount.php"
CART_GUARD = "src/Modules/Finance/Infrastructure/WooCommerce/CommissionCartGuard.php"
COUPONS = "src/Modules/Marketplace/Application/ManageCoupons.php"
CAPTURE = "src/Modules/Order/Application/CaptureOrder.php"
LIFECYCLE = "src/Modules/Order/Application/SyncOrderLifecycle.php"
ITEM_REPO = "src/Modules/Order/Infrastructure/DbOrderItemRepository.php"
DOKAN = "src/Modules/Order/Infrastructure/WordPress/WpDokanPresence.php"
VISIBILITY = "src/Modules/Migration/Application/MigrationToolsVisibility.php"
CONCLUDE = "src/Modules/Migration/Application/ConcludeMigration.php"
MENU = "src/Modules/Admin/Presentation/MenuRegistrar.php"

MUTATIONS = [
    # ── §1 the owner's answers, put back as unanswered ──────────────────────
    {
        "name": "the order gate goes back to refusing for DEC-02 and DEC-04",
        "file": GATE,
        "expect": "unit,architecture,database",
        "old": "    public const OPEN_DECISIONS = [];",
        "new": "    public const OPEN_DECISIONS = ['DEC-02', 'DEC-04'];",
    },
    {
        "name": "automatic settlement opens, against the owner's decision",
        "file": SETTLE_GATE,
        "expect": "unit,architecture",
        "old": "    private const AUTOMATIC_SETTLEMENT_OPEN = false;",
        "new": "    private const AUTOMATIC_SETTLEMENT_OPEN = true;",
    },
    {
        "name": "vendor withdrawal requests open, against the owner's decision",
        "file": WITHDRAW,
        "expect": "unit,architecture",
        "old": "    private const OPEN = false;",
        "new": "    private const OPEN = true;",
    },
    # ── §1 the native WooCommerce coupon bypass ─────────────────────────────
    {
        "name": "the cart stops asking whether a discount was the vendor's",
        "file": CART_GUARD,
        "expect": "database",
        "old": "            if ($discount !== null && !$discount['ok']) {",
        "new": "            if (false) {",
    },
    {
        "name": "a native shop_coupon is no longer recognised as the host shop's",
        "file": CART_GUARD,
        "expect": "database",
        "old": "            if (function_exists('wc_get_coupon_id_by_code') && (int) wc_get_coupon_id_by_code($code) > 0) {",
        "new": "            if (false) {",
    },
    {
        "name": "a coupon code nothing can resolve is trusted instead of refused",
        "file": CART_GUARD,
        "expect": "database",
        "old": "            if ($coupon === null || $coupon->vendorUserId !== $vendorUserId) {",
        "new": "            if ($coupon !== null && $coupon->vendorUserId !== $vendorUserId) {",
    },
    {
        "name": "an unreadable pair of amounts sells instead of refusing",
        "file": DISCOUNT,
        "expect": "unit",
        "old": "        if ($subtotalMinor === null || $totalMinor === null) {",
        "new": "        if (false) {",
    },
    {
        "name": "a marketplace-wide coupon that exists starts applying again",
        "file": COUPONS,
        "expect": "database",
        "old": "        if ($coupon->isGlobal()) {",
        "new": "        if (false) {",
    },
    # ── §2 settlement completion on an unpaid order ─────────────────────────
    {
        "name": "a manager may settle a line whose order was never paid",
        "file": MANAGE_ITEMS,
        "expect": "database",
        "old": "            if (!$receipt['ok']) {",
        "new": "            if (false) {",
    },
    {
        "name": "an order status nobody recorded counts as paid",
        "file": ELIGIBILITY,
        "expect": "unit,database",
        "old": "        if ($wcOrderStatus === null || trim($wcOrderStatus) === '') {",
        "new": "        if (false) {",
    },
    {
        "name": "a refunded order settles instead of asking a person",
        "file": ELIGIBILITY,
        "expect": "unit",
        "old": "            WcOrderDisposition::Refunded => self::REFUND_NEEDS_DECISION,",
        "new": "            WcOrderDisposition::Refunded => self::OK,",
    },
    # ── §2 the variation rule ───────────────────────────────────────────────
    {
        "name": "capture reads the commission rule without the variation id",
        "file": CAPTURE,
        "expect": "database",
        "old": "                ($line['variation_id'] ?? null) === null ? 0 : (int) $line['variation_id']\n            );",
        "new": "                0\n            );",
    },
    # ── §2 DEC-02: the parent status settles nothing ────────────────────────
    {
        "name": "the lifecycle sync starts settling lines on a completed parent",
        "file": LIFECYCLE,
        "expect": "architecture",
        "old": "    public function sync(int $orderId, string $status): array\n    {",
        "new": "    public function sync(int $orderId, string $status): array\n    {\n        if ($status === 'completed') {\n            $this->items->recordSettlementCompletion(0, null, null);\n        }",
    },
    {
        "name": "the settlement view starts carrying the parent's status",
        "file": ITEM_REPO,
        "expect": "architecture",
        "old": "'SELECT i.id, i.vendor_share_minor, i.settlement_completed_at, i.withdrawal_id,",
        "new": "'SELECT i.id, i.vendor_share_minor, i.settlement_completed_at, i.withdrawal_id, i.`' . WcStatus::COLUMN . '` AS wc_order_status,",
    },
    # ── the only remaining guard: Dokan, fail-closed ────────────────────────
    {
        "name": "a plugin list that could not be read reads as «no Dokan»",
        "file": DOKAN,
        "expect": "contract",
        "old": "        if (!$this->pluginListWasReadable()) {",
        "new": "        if (false) {",
    },
    {
        "name": "the plugin list is believed whatever shape it came back in",
        "file": DOKAN,
        "expect": "contract",
        "old": "        return is_array(get_option('active_plugins', []));",
        "new": "        return true;",
    },
    # ── §5 hiding the migration tools ───────────────────────────────────────
    {
        "name": "an unreadable conclusion option hides the tools instead of showing them",
        "file": VISIBILITY,
        "expect": "unit",
        "old": "        return $this->concludedAt() === '';",
        "new": "        return false;",
    },
    {
        "name": "a stored value that is not a date counts as a conclusion",
        "file": VISIBILITY,
        "expect": "unit",
        "old": "        if (!is_string($stored)) {\n            return '';\n        }",
        "new": "        if (!is_string($stored)) {\n            return '1970-01-01 00:00:00';\n        }",
    },
    {
        "name": "a step that could not be measured counts as finished",
        "file": CONCLUDE,
        "expect": "architecture",
        "old": "            if (($step['status'] ?? MigrationPlanChecklist::UNKNOWN) !== MigrationPlanChecklist::DONE) {",
        "new": "            if (($step['status'] ?? MigrationPlanChecklist::UNKNOWN) === MigrationPlanChecklist::BLOCKED) {",
    },
    {
        "name": "concluding checks the capability after it has already read the shops",
        "file": CONCLUDE,
        "expect": "architecture",
        "old": "        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {\n            return OperationResult::failure(self::FORBIDDEN);\n        }\n        if ($this->visibility->concludedAt() !== '') {",
        "new": "        if ($this->visibility->concludedAt() !== '') {",
    },
    {
        "name": "the menu hides slugs typed by hand rather than the migration group",
        "file": MENU,
        "expect": "architecture",
        "old": "        foreach (AdminNavigation::GROUPS[AdminNavigation::MIGRATION] as $slug) {",
        "new": "        foreach (['tmc-import', 'tmc-handover', 'tmc-minmax-exit'] as $slug) {",
    },
]


def body(n: int) -> dict:
    if n < 1 or n > len(MUTATIONS):
        raise SystemExit(f"no mutation {n}; there are {len(MUTATIONS)}")
    return MUTATIONS[n - 1]


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: alpha101-mutations.py count|name <n>|file <n>|expect <n>|apply <n>")
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
