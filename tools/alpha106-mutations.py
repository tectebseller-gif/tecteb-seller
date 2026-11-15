#!/usr/bin/env python3
"""The mutations for `alpha.106`, as DATA rather than as shell heredocs.

Every snippet below is copied from the tree as it stands and must occur
EXACTLY ONCE in its file. A snippet that matches twice, or not at all, is a
failure and not a guard that cannot be broken (`alpha.77`): «هیچ‌چیز سنجیده
نشد» is byte for byte the same report as «این شکستنی نیست».

Run with `python3 -I` so nothing is imported from the working directory.
"""
import io
import sys

# (name, path, old, new, expected red suites)
MUTATIONS = [
    (
        "a failed read becomes permission to write",
        "src/Modules/Product/Domain/PriceAndStockOrigin.php",
        "            default => new self(self::UNKNOWN, 'unreadable'),",
        "            default => self::ours('unreadable'),",
        "unit,database",
    ),
    (
        "a confirmed-empty shop reads as unknown (the over-reach control)",
        "src/Modules/Product/Domain/PriceAndStockOrigin.php",
        "            return new self(self::OURS, 'shop_has_no_children');",
        "            return new self(self::UNKNOWN, 'shop_has_no_children');",
        "unit,database",
    ),
    (
        "writeAndVerifyStock drops the refusal",
        "src/Modules/Product/Application/SyncCatalog.php",
        "        if ($product !== null && !$origin->mayWriteParentFigures()) {",
        "        if (false) {",
        "database",
    ),
    (
        "pushPrice drops the refusal",
        "src/Modules/Product/Application/SyncCatalog.php",
        "        $origin = $this->priceAndStockOrigin($productId);\n        if (!$origin->mayWriteParentFigures()) {",
        "        $origin = $this->priceAndStockOrigin($productId);\n        if (false) {",
        "database",
    ),
    (
        "parentFiguresRefusal always answers null",
        "src/Modules/Product/Application/ManageProducts.php",
        "        $figures = $this->parentFiguresRefusal($product, $details);",
        "        $figures = null;",
        "database",
    ),
    (
        "typeChangeRefusal in save() always answers null",
        "src/Modules/Product/Application/ManageProducts.php",
        "        $typeChange = $this->typeChangeRefusal($product, $details);",
        "        $typeChange = null;",
        "database",
    ),
    (
        "updateInventory pushes the stock regardless of where it lives",
        "src/Modules/Product/Application/ManageProducts.php",
        "        if (!$stockProposed && $stockBelongsToUs) {",
        "        if (!$stockProposed) {",
        "database",
    ),
    (
        "saveAsRevision pushes the stock regardless",
        "src/Modules/Product/Application/ManageProducts.php",
        "        if (!$stockProposed && $this->catalog->priceAndStockOrigin($product->id)->mayWriteParentFigures()) {",
        "        if (!$stockProposed) {",
        "database",
    ),
    (
        "the projector loses its own guard",
        "src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php",
        "        if ($this->shopHoldsTheCombinations($wcProduct)) {\n            return false;\n        }",
        "",
        "database",
    ),
    (
        "readiness keeps `price` missing for an imported parent",
        "src/Modules/Product/Application/ProductReadiness.php",
        "        if (!$origin->parentFiguresAreReal()) {",
        "        if (false) {",
        "database",
    ),
    (
        "approveRevision drops the type refusal",
        "src/Modules/Product/Application/ReviewProducts.php",
        "        $typeChange = $this->typeChangeRefusal($product, $revision->payload, $revisionId);",
        "        $typeChange = null;",
        "database",
    ),
    (
        "the form offers the editable figures regardless",
        "src/Modules/Product/Presentation/ProductFormView.php",
        "        $figuresAreOurs = $origin === null || $origin->offersParentFigures();",
        "        $figuresAreOurs = true;",
        "contract",
    ),
    (
        "the form offers the type chooser regardless",
        "src/Modules/Product/Presentation/ProductFormView.php",
        "        if ($origin === null || $origin->isOurs()) {",
        "        if (true) {",
        "contract",
    ),
    (
        "the shop page never prints the range",
        "src/Modules/Vendor/Presentation/StorePageView.php",
        "            if ($shopPrice !== null) {",
        "            if (false) {",
        "contract",
    ),
    (
        "the range is grouped with the other separator",
        "src/Modules/Vendor/Presentation/StorePageView.php",
        "        if ($exponent === 0) {\n            return $fa(number_format($minor));\n        }",
        "",
        "contract",
    ),
    (
        "the REST row always claims the figures are its own",
        "src/Modules/Operations/Infrastructure/Rest/MarketplaceController.php",
        "                    !$variable => 'row',",
        "                    true => 'row',",
        "database",
    ),
    (
        "the wholesale ceiling guard is removed",
        "src/Modules/Marketplace/Application/ManageWholesale.php",
        "        if ($steps !== [] && $product->details->priceMinor <= 0) {",
        "        if (false) {",
        "database",
    ),
    (
        "the wholesale guard also refuses an empty ladder (the dead end)",
        "src/Modules/Marketplace/Application/ManageWholesale.php",
        "        if ($steps !== [] && $product->details->priceMinor <= 0) {",
        "        if ($product->details->priceMinor <= 0) {",
        "database",
    ),
    (
        "the label decodes in a loop",
        "src/Modules/Product/Presentation/AttributeLabel.php",
        "        $decoded = rawurldecode($stored);",
        "        $decoded = $stored;\n        while (($once = rawurldecode($decoded)) !== $decoded) {\n            $decoded = $once;\n        }",
        "unit",
    ),
    (
        "the label accepts broken bytes",
        "src/Modules/Product/Presentation/AttributeLabel.php",
        "        if (!mb_check_encoding($decoded, 'UTF-8') || preg_match('/[\\x00-\\x1F\\x7F]/', $decoded) === 1) {",
        "        if (false) {",
        "unit",
    ),
    (
        "the panel prints the stored key",
        "src/Modules/Product/Presentation/VariationsView.php",
        "                $parts[] = AttributeLabel::forDisplay((string) $label)\n                    . ': ' . AttributeLabel::forDisplay($value);",
        "                $parts[] = $label . ': ' . $value;",
        "contract",
    ),
]


def main() -> int:
    verb = sys.argv[1] if len(sys.argv) > 1 else "count"
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2]) - 1
    name, path, old, new, expect = MUTATIONS[n]
    if verb == "name":
        print(name)
        return 0
    if verb == "file":
        print(path)
        return 0
    if verb == "expect":
        print(expect)
        return 0
    if verb == "apply":
        body = io.open(path, encoding="utf-8").read()
        hits = body.count(old)
        if hits != 1:
            print(f"REFUSED: snippet occurs {hits} times in {path}", file=sys.stderr)
            return 2
        io.open(path, "w", encoding="utf-8").write(body.replace(old, new, 1))
        return 0
    print(f"unknown verb {verb}", file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
