#!/usr/bin/env python3
"""The mutations for alpha.105 — each one a fix of this round put back wrong.

Run through `tools/alpha105-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` must occur EXACTLY ONCE in its file (`alpha.88`), and each name
says what it BREAKS rather than what it edits (`alpha.82`).
"""
import hashlib
import pathlib
import sys

AREA = "src/Modules/Product/Infrastructure/WordPress/ProductArea.php"
MANAGE = "src/Modules/Product/Application/ManageVariations.php"
LINK = "src/Modules/Product/Domain/CatalogueLink.php"
REPO = "src/Modules/Product/Infrastructure/DbProductRepository.php"
COMPLETE = "src/Modules/Migration/Application/MigrationCompleteness.php"
CHECKLIST = "src/Modules/Migration/Application/MigrationPlanChecklist.php"
WIZARD = "src/Modules/Migration/Presentation/MigrationWizardText.php"
MENU = "src/Modules/Admin/Presentation/MenuRegistrar.php"
PROJECTOR = "src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php"

MUTATIONS = [
    # ── §3 the P0: the imported variable product's combinations ─────────────
    {
        "name": "the panel stops asking WooCommerce about an imported parent",
        "file": AREA,
        "expect": "database",
        "old": "            'storefront' => $attributes === [] && $variations === []\n"
               "                ? $this->storefrontVariations()->setFor($wcProductId)\n"
               "                : null,",
        "new": "            'storefront' => null,",
    },
    {
        "name": "the list stops asking for the parent's real price range",
        "file": AREA,
        "expect": "database",
        "old": "        $ranges = $this->storefrontVariations()->rangesFor(array_values(array_unique($wanted)));",
        "new": "        $ranges = [];",
    },
    {
        "name": "the four editing verbs stop refusing the shop's own combinations",
        "file": MANAGE,
        "expect": "database",
        "old": "        // After the other refusals, because «you may not edit this at all» is\n"
               "        // a different sentence from «these combinations live in the shop», and\n"
               "        // the first one is the one to say when both are true.\n"
               "        if (\n"
               "            $product->details->type === ProductType::VARIABLE\n"
               "            && $this->shopOwnsTheCombinations($productId, $product->wcProductId)\n"
               "        ) {\n"
               "            return OperationResult::failure('variations_live_in_shop', ['product_id' => $productId]);\n"
               "        }\n",
        "new": "",
    },
    {
        "name": "the stock verb alone stops refusing them (the fifth door)",
        "file": MANAGE,
        "expect": "database",
        "old": "        if (\n"
               "            $product->details->type === ProductType::VARIABLE\n"
               "            && $this->shopOwnsTheCombinations($productId, $product->wcProductId)\n"
               "        ) {\n"
               "            return OperationResult::failure('variations_live_in_shop', ['product_id' => $productId]);\n"
               "        }\n"
               "        if ($stock < 0) {",
        "new": "        if ($stock < 0) {",
    },

    # ── §4 the reconciliation, by id ───────────────────────────────────────
    {
        "name": "a shop side nobody could read is reported as linked",
        "file": LINK,
        "expect": "unit,database",
        "old": "        if (!$shopReadable) {\n            return self::UNREADABLE;\n        }\n",
        "new": "",
    },
    {
        "name": "a page that came up short is reported as the whole page",
        "file": REPO,
        "expect": "database",
        "old": "        if (count($rows) !== $expected) {\n            return null;\n        }\n\n",
        "new": "",
    },
    {
        "name": "the report drops the imported rows it exists to reconcile",
        "file": REPO,
        "expect": "database",
        "old": "              WHERE p.vendor_user_id = %d AND p.id > %d\n              ORDER BY p.id ASC",
        "new": "              WHERE p.vendor_user_id = %d AND p.id > %d\n"
               "                AND p." + "' . M0010LinkOwnership::COLUMN . '" + " = \"marketplace\"\n"
               "              ORDER BY p.id ASC",
    },
    {
        "name": "the other direction reads post_author instead of our own mark",
        "file": REPO,
        "expect": "architecture,database",
        "old": "                   WHERE m.meta_key = %s AND m.meta_value = %s AND t.id IS NULL';\n"
               "        $params = ['product', WooCommerceProjector::VENDOR_META, (string) $vendorUserId];",
        "new": "                   WHERE m.meta_key = %s AND wp.post_author = %s AND t.id IS NULL';\n"
               "        $params = ['product', WooCommerceProjector::VENDOR_META, (string) $vendorUserId];",
    },

    # ── §5 the records-only shop, and the menu it unblocks ─────────────────
    {
        "name": "any shop that does not sell reads as a finished migration",
        "file": COMPLETE,
        "expect": "database",
        "old": "                'records_only_settled' => $gates['records_imported']\n"
               "                    && $gates['categories_mapped']\n"
               "                    && $ownershipState === OwnershipState::NOTHING_TO_TRANSFER\n"
               "                    && $profile?->canSell !== true,",
        "new": "                'records_only_settled' => $profile?->canSell !== true,",
    },
    {
        "name": "an archive nobody could read still finishes the migration",
        "file": COMPLETE,
        "expect": "database",
        "old": "                'records_only_settled' => $gates['records_imported']\n"
               "                    && $gates['categories_mapped']\n",
        "new": "                'records_only_settled' => $gates['categories_mapped']\n",
    },
    {
        "name": "a second open gate hides behind the records-only answer",
        "file": CHECKLIST,
        "expect": "unit,database",
        "old": "        return ($state['records_only_settled'] ?? false) === true\n"
               "            && array_values(array_map('strval', $missing)) === ['vendor_can_sell'];",
        "new": "        return ($state['records_only_settled'] ?? false) === true\n"
               "            && in_array('vendor_can_sell', array_map('strval', $missing), true);",
    },
    {
        "name": "the identifier reaches the admin screen instead of a sentence",
        "file": WIZARD,
        "expect": "database",
        "old": "            if ($detail === Plan::RECORDS_ONLY) {\n"
               "                return __('سوابق این فروشگاه آمده و محصولی ندارد؛ اجازهٔ فروش هم به آن داده نشده و همان‌طور می‌مانَد. کار دیگری نمانده.', 'tecteb-marketplace-core');\n"
               "            }\n",
        "new": "",
    },
    {
        "name": "post_author is rewritten on a product that was already there",
        "file": PROJECTOR,
        "expect": "contract",
        "old": "        if ($isNew) {\n"
               "            wp_update_post(['ID' => $wcProductId, 'post_author' => $product->vendorUserId]);\n"
               "        }",
        "new": "        wp_update_post(['ID' => $wcProductId, 'post_author' => $product->vendorUserId]);",
    },
    {
        "name": "the sidebar is printed in registration order, not the defined one",
        "file": MENU,
        "expect": "contract",
        "old": "            $ordered[] = [AdminNavigation::position((string) $row['slug']), $index, $row];",
        "new": "            $ordered[] = [0, $index, $row];",
    },
]


def digest(path: str) -> str:
    return hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: alpha105-mutations.py count|name <n>|file <n>|expect <n>|apply <n>", file=sys.stderr)
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    index = int(sys.argv[2]) - 1
    if index < 0 or index >= len(MUTATIONS):
        print("no mutation " + sys.argv[2], file=sys.stderr)
        return 2
    mutation = MUTATIONS[index]
    if verb == "name":
        print(mutation["name"])
        return 0
    if verb == "file":
        print(mutation["file"])
        return 0
    if verb == "expect":
        print(mutation["expect"])
        return 0
    if verb != "apply":
        print("unknown verb " + verb, file=sys.stderr)
        return 2

    path = pathlib.Path(mutation["file"])
    text = path.read_text(encoding="utf-8")
    seen = text.count(mutation["old"])
    if seen != 1:
        print("the target text occurs " + str(seen) + " times in " + mutation["file"]
              + " — a mutation must name exactly one place (alpha.88)", file=sys.stderr)
        return 1
    path.write_text(text.replace(mutation["old"], mutation["new"]), encoding="utf-8")
    print("    applied to " + mutation["file"] + " (now " + digest(mutation["file"])[:12] + ")")
    return 0


if __name__ == "__main__":
    sys.exit(main())
