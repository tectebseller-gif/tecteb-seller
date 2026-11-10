#!/usr/bin/env python3
"""Mutations for the alpha.103 falsification loop.

⛔ The mutations live HERE, as data, and not in the shell driver. A heredoc
without quotes expands `$variable`, and every PHP snippet is full of them —
`alpha.77` watched a mutation silently fail to apply for exactly that reason,
and «nothing was measured» is byte-for-byte indistinguishable from «this guard
cannot be broken».

Each entry says which file it touches, the exact text to replace, and what it
is replaced with. `apply` refuses rather than guessing when the needle is not
found exactly once, because a mutation that lands twice is measuring something
nobody chose.
"""
from __future__ import annotations

import sys

MUTATIONS: list[dict[str, str]] = [
    {
        "name": "restock-guard-out-of-the-statement",
        "file": "src/Modules/Order/Infrastructure/DbShipmentRepository.php",
        "old": "             WHERE id = %d AND restocked_quantity = 0',",
        "new": "             WHERE id = %d',",
        "why": "a second number can land on a return that was already restocked",
    },
    {
        "name": "ceiling-checked-in-php-only",
        "file": "src/Modules/Order/Infrastructure/DbShipmentRepository.php",
        "old": "        if ($lineQuantity <= 0) {",
        "new": "        if (true) {",
        "why": "the bare INSERT is back, so two requests that both pass the read both write",
    },
    {
        "name": "return-scope-forgets-the-shop",
        "file": "src/Modules/Order/Application/ManageReturns.php",
        "old": "if ($item === null || !$item->belongsTo($vendorUserId)) {",
        "new": "if ($item === null) {",
        "why": "one shop can open a return on another shop's line",
    },
    {
        "name": "refund-without-a-unit-of-work",
        "file": "src/Modules/Order/Application/ManageReturns.php",
        "old": "        if ($this->tx === null) {",
        "new": "        if (false) {",
        "why": "the reversal and the row claim stop being one transaction",
    },
    {
        "name": "unrecordable-guard-trusts-an-unknown-module",
        "file": "src/Modules/Order/Infrastructure/WordPress/UnrecordableOrderGuard.php",
        "old": "                ModuleStatus::Degraded,\n                ModuleStatus::Planned,\n",
        "new": "",
        "why": "a module that failed to register, rather than being gated, is read as running",
    },
    {
        "name": "unrecordable-guard-marks-every-order",
        "file": "src/Modules/Order/Application/UnrecordableOrder.php",
        "old": "        if ($ourLines <= 0) {",
        "new": "        if (false) {",
        "why": "an order with no marketplace line is claimed as ours",
    },
    {
        "name": "sanitizer-denylist-only-nested",
        "file": "src/Core/Audit/AuditEventSanitizer.php",
        "old": "            if (preg_match(self::KEY_DENYLIST, $key) === 1) {\n                continue;\n            }\n            $value = $payload[$key];",
        "new": "            $value = $payload[$key];",
        "why": "a top-level key named `iban` or `token` reaches the audit row",
    },
    {
        "name": "cart-pricing-computes-on-a-scale-it-cannot-express",
        "file": "src/Modules/Marketplace/Infrastructure/WooCommerce/CartPricing.php",
        "old": "        return (int) wc_get_price_decimals() === 0;",
        "new": "        return true;",
        "why": "a two-decimal currency gets a hundredfold wholesale or coupon figure",
    },
]


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: alpha103-mutations.py count|name <n>|file <n>|why <n>|apply <n>", file=sys.stderr)
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    try:
        index = int(sys.argv[2]) - 1
        mutation = MUTATIONS[index]
    except (IndexError, ValueError):
        print(f"no mutation {sys.argv[2] if len(sys.argv) > 2 else ''}", file=sys.stderr)
        return 2

    if verb in {"name", "file", "why"}:
        print(mutation[verb])
        return 0

    if verb != "apply":
        print(f"unknown verb {verb}", file=sys.stderr)
        return 2

    # ⛔ No `revert`. Restoring by searching for the mutated text and putting
    # the original back needs the MUTATED text to be unique too, and it often
    # is not — `WHERE id = %d` appears in half the repository. The driver
    # copies the file aside instead and compares the hash afterwards, which
    # cannot be fooled.
    old, new = mutation["old"], mutation["new"]
    with open(mutation["file"], encoding="utf-8") as handle:
        source = handle.read()
    hits = source.count(old)
    if hits != 1:
        # ⛔ Refuse. A needle that matches twice mutates something nobody
        # chose, and one that matches nothing measures nothing at all.
        print(f"needle occurs {hits} times in {mutation['file']} — refusing", file=sys.stderr)
        return 1
    with open(mutation["file"], "w", encoding="utf-8") as handle:
        handle.write(source.replace(old, new, 1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
