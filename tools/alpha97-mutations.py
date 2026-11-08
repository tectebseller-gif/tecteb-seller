#!/usr/bin/env python3
"""The mutations for alpha.97, each one a fix of this round put back wrong.

Run through `tools/alpha97-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` here must occur EXACTLY ONCE in its file. A snippet that matches
twice is refused by the harness below rather than applied to the first hit
(`alpha.88`).
"""
import hashlib
import pathlib
import sys

MUTATIONS = [
    {
        "name": "the ownership count stops saying it failed",
        "file": "src/Modules/Product/Infrastructure/DbProductRepository.php",
        "expect": "database",
        "old": """        $tally['readable'] = $seen === (int) $counted;""",
        "new": """        $tally['readable'] = true;""",
    },
    {
        "name": "the breakdown no longer has to add up to the total",
        "file": "src/Modules/Product/Infrastructure/DbProductRepository.php",
        "expect": "database",
        "old": """        if ($counted === null) {
            return ['marketplace' => 0, 'observed' => 0, 'readable' => false];
        }""",
        "new": """        if ($counted === null) {
            $counted = 0;
        }""",
    },
    {
        "name": "OwnershipState trusts an unreadable tally",
        "file": "src/Modules/Migration/Application/OwnershipState.php",
        "expect": "database",
        "old": """        if (($tally['readable'] ?? false) !== true) {
            return self::UNREADABLE;
        }""",
        "new": """        if (false) {
            return self::UNREADABLE;
        }""",
    },
    {
        "name": "a nought needs no import stamp to mean «nothing to transfer»",
        "file": "src/Modules/Migration/Application/OwnershipState.php",
        "expect": "database",
        "old": """        return $importStamped ? self::NOTHING_TO_TRANSFER : self::ROWS_OBSERVED;""",
        "new": """        return self::NOTHING_TO_TRANSFER;""",
    },
    {
        "name": "both finished answers collapse into one",
        "file": "src/Modules/Migration/Application/OwnershipState.php",
        "expect": "database",
        "old": """        return $state === self::TAKEN || $state === self::NOTHING_TO_TRANSFER;""",
        "new": """        return $state === self::TAKEN;""",
    },
    {
        "name": "staff_decided stops asking whether the list was read",
        "file": "src/Modules/Migration/Application/MigrationCompleteness.php",
        "expect": "database",
        "old": """            'staff_decided' => $staffReadable && $undecidedRoles === [],""",
        "new": """            'staff_decided' => $undecidedRoles === [],""",
    },
    {
        "name": "the staff count is cast, so a failed read becomes nought",
        "file": "src/Modules/Migration/Application/MigrationCompleteness.php",
        "expect": "database",
        "old": """        $staffReadable = $heldStaff !== null && (int) $heldStaff === count($staffRows);""",
        "new": """        $staffReadable = (int) $heldStaff === count($staffRows);""",
    },
    {
        "name": "the ownership gate derives its own answer again",
        "file": "src/Modules/Migration/Application/MigrationCompleteness.php",
        "expect": "database",
        "old": """            'ownership_taken' => OwnershipState::settled($ownershipState),""",
        "new": """            'ownership_taken' => $tally['marketplace'] > 0 && $tally['observed'] === 0,""",
    },
    {
        "name": "step 10 derives «transferred» from the two counts again",
        "file": "src/Modules/Migration/Application/MigrationPlanChecklist.php",
        "expect": "database",
        "old": """        return match ((string) ($state['ownership_state'] ?? OwnershipState::UNREADABLE)) {
            OwnershipState::TAKEN => [self::DONE, '', (string) (int) ($state['marketplace'] ?? 0)],
            OwnershipState::NOTHING_TO_TRANSFER => [self::DONE, '', 'nothing_to_transfer'],
            OwnershipState::ROWS_OBSERVED => [self::TODO, 'rows_still_observed', (string) (int) ($state['observed'] ?? 0)],
            default => [self::UNKNOWN, 'ownership_unreadable', ''],
        };""",
        "new": """        $observed = (int) ($state['observed'] ?? 0);
        $marketplace = (int) ($state['marketplace'] ?? 0);
        if ($observed === 0 && $marketplace > 0) {
            return [self::DONE, '', (string) $marketplace];
        }
        return [self::TODO, 'rows_still_observed', (string) $observed];""",
    },
    {
        "name": "step 9 stops distinguishing «nothing to consent to»",
        "file": "src/Modules/Migration/Application/MigrationPlanChecklist.php",
        "expect": "database",
        "old": """            OwnershipState::NOTHING_TO_TRANSFER => [self::DONE, '', 'nothing_to_transfer'],
            OwnershipState::ROWS_OBSERVED => [self::TODO, 'consent_is_a_press', (string) (int) ($state['observed'] ?? 0)],""",
        "new": """            OwnershipState::ROWS_OBSERVED, OwnershipState::NOTHING_TO_TRANSFER =>
                [self::TODO, 'consent_is_a_press', (string) (int) ($state['observed'] ?? 0)],""",
    },
    {
        "name": "the finished detail prints its identifier on the screen",
        "file": "src/Modules/Migration/Presentation/MigrationWizardText.php",
        "expect": "database",
        "old": """            if ($detail === OwnershipState::NOTHING_TO_TRANSFER) {
                return __('این فروشگاه محصولی ندارد، پس چیزی برای انتقال نبود.', 'tecteb-marketplace-core');
            }""",
        "new": """            // the branch this round added, removed""",
    },
    {
        # ⛔ This mutation was WRONG on its first writing, and the loop caught
        # it: it renamed the `TABLES` constant, which leaves every table-name
        # string in the file, and the coverage guard looks for the STRINGS.
        # So it survived — and on the run before that it had been scored
        # «falsified» only because the unit suite was already red for an
        # unrelated reason. A mutation whose name does not match its work is
        # worse than no mutation (`alpha.82`), and one scored against an
        # already-red suite measured nothing at all.
        #
        # It now removes the entry the guard actually found missing, which is
        # the thing the guard exists to refuse.
        "name": "the census drops a table that carries a vendor id",
        "file": "tools/shop-dependency-census.php",
        "expect": "unit",
        "old": """        'tmc_product_decisions' => 'vendor_user_id',""",
        "new": """        // removed""",
    },
]


def body(index: int) -> dict:
    return MUTATIONS[index - 1]


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: alpha97-mutations.py count|name <n>|file <n>|expect <n>|apply <n>")
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    m = body(n)
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
        print("unknown verb " + verb)
        return 2

    path = pathlib.Path(m["file"])
    text = path.read_text()
    found = text.count(m["old"])
    if found != 1:
        # NOT a silent skip. A mutation that does not apply measured nothing,
        # and that is indistinguishable from a guard that cannot be broken.
        print(f"REFUSED: snippet occurs {found} time(s) in {m['file']}", file=sys.stderr)
        return 3
    path.write_text(text.replace(m["old"], m["new"]))
    print("applied: " + m["name"])
    print("sha256 after: " + hashlib.sha256(path.read_bytes()).hexdigest())
    return 0


if __name__ == "__main__":
    sys.exit(main())
