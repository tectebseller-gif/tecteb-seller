#!/usr/bin/env python3
"""The mutations for `alpha.96` — one per guard this round added or relies on.

Each entry names the file, the exact bytes to replace, the replacement, and
the suite that must go red. A mutation that does not APPLY is a failure and
not an unbreakable guard (`alpha.77`): "nothing was measured" is byte for byte
the same as "this cannot be broken".
"""
import sys

MUTATIONS = [
    # ---- the defect this round fixed ---------------------------------------
    {
        "name": "the sync figure goes back to reading page one",
        "file": "src/Modules/Migration/Application/MigrationPlanSnapshot.php",
        "old": "$statusTally = $this->status->tally($vendorUserId);",
        "new": "$statusTally = $this->status->plan($vendorUserId);",
        "expect": "architecture, database",
    },
    {
        "name": "the whole-shop walk stops after its first page",
        "file": "src/Modules/Migration/Application/ReconcileStorefrontStatus.php",
        "old": "            if ($pages >= self::MAX_PAGES) {",
        "new": "            if ($pages >= 1) {",
        "expect": "database",
    },
    {
        "name": "the ceiling is removed, so an admin request may loop unbounded",
        "file": "src/Modules/Migration/Application/ReconcileStorefrontStatus.php",
        "old": """            if ($pages >= self::MAX_PAGES) {
                $stoppedForPages = true;
                break;
            }
""",
        "new": "",
        "expect": "architecture",
    },
    {
        "name": "a walk that could not read says it finished",
        "file": "src/Modules/Migration/Application/ReconcileStorefrontStatus.php",
        "old": """            if ($moved <= $cursor) {
                break;
            }""",
        "new": """            if ($moved <= $cursor) {
                $finished = true;
                break;
            }""",
        "expect": "architecture, database",
    },
    {
        "name": "the shop figure inherits the button's batch limit",
        "file": "src/Modules/Migration/Application/ReconcileStorefrontStatus.php",
        "old": "            'repairable' => (int) ($counts[Rule::REPAIR] ?? 0),",
        "new": "            'repairable' => min(self::BATCH_LIMIT, (int) ($counts[Rule::REPAIR] ?? 0)),",
        "expect": "database",
    },
    {
        "name": "a page nobody could read counts as nothing to repair",
        "file": "src/Modules/Migration/Application/MigrationPlanSnapshot.php",
        "old": """'status_repairable' => $statusTally['finished'] === true
                && $statusTally['readable'] === true
                ? (int) $statusTally['repairable']""",
        "new": """'status_repairable' => $statusTally['finished'] === true
                ? (int) $statusTally['repairable']""",
        "expect": "architecture, database",
    },
    {
        "name": "the page claims it could read whatever came back",
        "file": "src/Modules/Migration/Application/ReconcileStorefrontStatus.php",
        "old": "            'readable' => $statuses !== null,",
        "new": "            'readable' => true,",
        "expect": "database",
    },
    # ---- the bulk read ----------------------------------------------------
    # ⛔ NOT mutated: `$statuses === null ? null : ($statuses[$wcId] ?? null)`.
    #
    # Turning the `null` into `[]` changes NOTHING a test can see, and that is
    # measured rather than assumed: an absent id yields `null` either way, and
    # `Rule::decide()` answers `UNREADABLE` for `null` AND for `''` (its own
    # `$shopStatus === null` branch plus `forIntakeOf('')` returning `null`).
    # It is a DOUBLE GUARD whose other half is the rule itself, and a mutation
    # whose name does not match its work is worse than no mutation
    # (`alpha.82`). The reader-side guards below are where this is measurable.
    {
        "name": "the status map is keyed by position instead of by product id",
        "file": "src/Modules/Migration/Infrastructure/WordPress/WpCatalogueReader.php",
        "old": "            $out[(int) ($row['ID'] ?? 0)] = (string) ($row['post_status'] ?? '');",
        "new": "            $out[] = (string) ($row['post_status'] ?? '');",
        "expect": "database",
    },
    {
        "name": "the write reads the status through its own door",
        "file": "src/Modules/Migration/Application/ReconcileStorefrontStatus.php",
        "old": "        $statuses = $this->statusesFor([$product]);",
        "new": "        $statuses = $this->reader->statusesOf([(int) ($product->wcProductId ?? 0)]);",
        "expect": "architecture",
    },
    {
        "name": "a short bulk read is reported as a map with holes in it",
        "file": "src/Modules/Migration/Infrastructure/WordPress/WpCatalogueReader.php",
        "old": "        if (count($rows) !== (int) $expected) {\n            return null;\n        }",
        "new": "        if (false) {\n            return null;\n        }",
        "expect": "database",
    },
    {
        "name": "the bulk read trusts an id of any post type",
        "file": "src/Modules/Migration/Infrastructure/WordPress/WpCatalogueReader.php",
        "old": "$where = 'WHERE ID IN (' . $in . \") AND post_type IN ('product','product_variation')\";",
        "new": "$where = 'WHERE ID IN (' . $in . ')';",
        "expect": "database",
    },
    # ---- the link --------------------------------------------------------
    {
        "name": "step 7 goes back to the bare import page",
        "file": "src/Modules/Migration/Presentation/MigrationWizardText.php",
        "old": "            Plan::STEP_SYNC => $transfer . '#' . MigrationPage::ANCHOR_SYNC,",
        "new": "            Plan::STEP_SYNC => 'admin.php?page=' . MigrationPage::SLUG,",
        "expect": "contract, database",
    },
    {
        "name": "the shop stops travelling with the link",
        "file": "src/Modules/Migration/Presentation/Admin/HandoverPage.php",
        "old": "$where = MigrationWizardText::target($key, $shop);",
        "new": "$where = MigrationWizardText::target($key);",
        "expect": "database",
    },
    {
        "name": "the sync controls lose the anchor the wizard points at",
        "file": "src/Modules/Migration/Presentation/Admin/MigrationPage.php",
        "old": """        echo '<div id="' . esc_attr(self::ANCHOR_SYNC) . '">'
            . $this->refreshBatchForm($plan, $shop, $after, $fa)
            . $this->statusRepairCard($shop, $after, $fa)
            . '</div>';""",
        "new": """        echo $this->refreshBatchForm($plan, $shop, $after, $fa)
            . $this->statusRepairCard($shop, $after, $fa);""",
        "expect": "database",
    },
    {
        "name": "the staged-transfer section loses its anchor",
        "file": "src/Modules/Migration/Presentation/Admin/MigrationPage.php",
        "old": "echo '<section class=\"tmc-card\" id=\"' . esc_attr(self::ANCHOR_TRANSFER) . '\">'\n            . '<h2 class=\"tmc-card__title\">'",
        "new": "echo '<section class=\"tmc-card\">'\n            . '<h2 class=\"tmc-card__title\">'",
        "expect": "database",
    },
]


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: count | name <n> | file <n> | expect <n> | apply <n>")
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2]) - 1
    if n < 0 or n >= len(MUTATIONS):
        print("no mutation " + sys.argv[2])
        return 2
    m = MUTATIONS[n]
    if verb == "name":
        print(m["name"])
        return 0
    if verb == "file":
        print(m["file"])
        return 0
    if verb == "expect":
        print(m["expect"])
        return 0
    if verb == "apply":
        with open(m["file"], encoding="utf-8") as fh:
            body = fh.read()
        hits = body.count(m["old"])
        if hits != 1:
            print("REFUSED: the snippet occurs %d times in %s" % (hits, m["file"]))
            return 1
        with open(m["file"], "w", encoding="utf-8") as fh:
            fh.write(body.replace(m["old"], m["new"], 1))
        print("applied")
        return 0
    print("unknown verb " + verb)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
