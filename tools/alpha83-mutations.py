#!/usr/bin/env python3
"""
The mutations for the round that stopped a stale copy overwriting a person's
edit — as DATA rather than as shell heredocs.

⛔ Kept out of the shell for the reason `alpha.77` paid for: every PHP
fragment here is full of `$variable` and single quotes, and an unquoted
heredoc expands `$details` to nothing — so the mutation applies to a line that
no longer matches and the script reports «survived» about a mutation that was
never made.

Each entry puts ONE of this round's fixes back the way `alpha.82` had it, or
inverts one of its decisions. Every one of them must go RED, and «red»
includes WARNINGS (`alpha.82`'s own correction: a counter that does not count
a suite's own signal reads green on a defect it cannot see).

The owner's acceptance rules are what they are all measured against:
«تغییر جدید باید اعمال شود، اما همگام‌سازی نباید قیمت ویرایش‌شده در WooCommerce
را با نسخه قدیمی ذخیره‌شده در افزونه جایگزین کند» and «در صورت تغییر هم‌زمان و
تعارض واقعی، تعارض باید ثبت و گزارش شود».
"""
import io
import sys

ROOT = __file__.rsplit('/tools/', 1)[0]

PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php'
MERGE = 'src/Modules/Product/Domain/ShopEditMerge.php'
FIELDS = 'src/Modules/Product/Infrastructure/WooCommerce/ProjectedShopFields.php'
SENSITIVE = 'src/Modules/Product/Domain/SensitiveChange.php'
PAGE = 'src/Modules/Product/Presentation/Admin/ProductReviewPage.php'
MANAGE = 'src/Modules/Product/Application/ManageProducts.php'

# name, file, old, new, what must happen
MUTATIONS = [
    (
        'the-projector-writes-the-price-unconditionally',
        PROJECTOR,
        """                if ($shopFieldsMerged['may'][ShopEditMerge::PRICE]) {
                    $wcProduct->set_regular_price((string) $details->priceMinor);
                    $this->applySale($wcProduct, $product);
                }""",
        """                $wcProduct->set_regular_price((string) $details->priceMinor);
                $this->applySale($wcProduct, $product);""",
        'alpha.82 behaviour: the old copy goes back over a person\'s edit',
    ),
    (
        'the-projector-writes-the-sku-unconditionally',
        PROJECTOR,
        """        if ($shopFieldsMerged['may'][ShopEditMerge::SKU]) {
            $this->applySku($wcProduct, $details->sku);
        }""",
        '        $this->applySku($wcProduct, $details->sku);',
        'the SKU a person set in WooCommerce is overwritten',
    ),
    (
        'the-projector-writes-the-weight-unconditionally',
        PROJECTOR,
        "        if ($details->weightGrams > 0 && $shopFieldsMerged['may'][ShopEditMerge::WEIGHT]) {",
        '        if ($details->weightGrams > 0) {',
        'the weight a person set in WooCommerce is overwritten',
    ),
    (
        'a-new-product-is-treated-like-an-old-one',
        PROJECTOR,
        """            $isNew ? [] : ProjectedShopFields::record((int) $wcProduct->get_id()),
            $isNew
        );""",
        """            $isNew ? [] : ProjectedShopFields::record((int) $wcProduct->get_id()),
            false
        );""",
        'the regression this rule could most easily cause: a brand-new '
        'product never gets a price, because the empty shop field differs',
    ),
    (
        'the-record-is-canonicalised-a-second-time',
        MERGE,
        "        if ($record['shop'] === $shopValue) {",
        "        if (self::canonical($field, $record['shop']) === $shopValue) {",
        'grams converted to grams again: every weight reads as edited',
    ),
    (
        'no-record-claims-the-field-anyway',
        MERGE,
        # ⛔ REWRITTEN, because the first spelling stopped applying the moment
        # `decide()` grew its freshness split — and «did not apply» is a
        # failure, not a passing guard (`alpha.77`). The claim is the same:
        # with no record, take the field instead of leaving it alone.
        """            if ($shopValue === $ourValue) {
                // A write that changes nothing is safe either way.
                return self::WRITE;
            }""",
        '            return self::WRITE;',
        'a field with no history is taken rather than left alone',
    ),
    (
        'both-sides-moved-keeps-instead-of-applying',
        MERGE,
        '        return self::WRITE_CONFLICT;',
        '        return self::KEEP_EDITED;',
        "the vendor's fresh change is refused — «این اختیار نباید محدود شود»",
    ),
    (
        'the-comparison-becomes-textual',
        MERGE,
        """        $exponent = $field === self::WEIGHT ? 3 : 0;
        $minor = DecimalAmount::toMinor($text, $exponent);
        return $minor === null ? null : (string) $minor;""",
        '        return $text;',
        "'450000.00' and '450000' read as two prices, so an untouched "
        'product reads as edited on any shop with decimals',
    ),
    (
        'an-inexact-price-is-rounded-instead-of-refused',
        MERGE,
        '        $minor = DecimalAmount::toMinor($text, $exponent);',
        '        $minor = DecimalAmount::toMinorFloor($text, $exponent);',
        'alpha.67 reversed: a price we cannot express is rounded into '
        'agreement instead of named',
    ),
    (
        'nothing-to-write-is-reported-as-a-conflict',
        MERGE,
        "        return in_array($answer, [self::WRITE_CONFLICT, self::KEEP_EDITED, self::KEEP_UNKNOWN, self::UNREADABLE], true);",
        "        return in_array($answer, [self::WRITE_CONFLICT, self::KEEP_EDITED, self::KEEP_UNKNOWN, self::UNREADABLE, self::NO_VALUE], true);",
        'a row in front of a manager about a difference nobody will act on',
    ),
    (
        'the-record-is-written-for-a-field-that-was-kept',
        FIELDS,
        """                if (!$didWrite) {
                    continue;
                }""",
        '                unset($didWrite);',
        "the projection claims the value it DECLINED to write, so the run "
        'after it overwrites the edit with a clear conscience',
    ),
    (
        'the-price-is-sensitive-again',
        SENSITIVE,
        """        'weightGrams', 'dimensions', 'taxClass',
    ];""",
        """        'priceMinor', 'salePriceMinor', 'saleFrom', 'saleTo',
        'weightGrams', 'dimensions', 'taxClass',
    ];""",
        "the owner's decision reversed: a vendor's price waits for a manager",
    ),
    (
        'the-review-table-loops-over-the-sensitive-list',
        PAGE,
        '        foreach (SensitiveChange::APPLIED_ON_APPROVAL as $field) {',
        '        foreach (SensitiveChange::SENSITIVE as $field) {',
        "alpha.49: a pre-upgrade proposal's price becomes applied-but-invisible",
    ),
    (
        'the-immediate-price-write-is-removed',
        MANAGE,
        """        $price = null;
        if ($priceMoved) {""",
        """        $price = null;
        if (false) {""",
        'the vendor presses save, is told «ذخیره شد», and the price reaches '
        'neither the row nor the shop',
    ),
    (
        'approval-applies-the-payloads-price-again',
        'src/Modules/Product/Application/ReviewProducts.php',
        "        $priceIsARequest = !array_key_exists(ProductRevision::PRICE_MIRRORED, $payload);",
        '        $priceIsARequest = true;',
        'approving an old proposal reverts a price the vendor changed since — '
        'the hole this round would otherwise have dug for itself',
    ),
    (
        'the-price-push-is-removed',
        MANAGE,
        '            $price = $this->catalog->pushPrice($product->id);',
        '            $price = null;',
        'the row changes and the shop keeps the old price for ever',
    ),
    (
        # ⛔ The hole the UPGRADE CHECK found, not a reading of the code: on an
        # upgraded site no record exists, so an edited product answered KEEP
        # for the vendor's own change too and their price could never reach
        # the shop again.
        'an-unrecorded-edit-holds-the-vendors-own-change-back',
        MERGE,
        '            return $oursIsFresh ? self::WRITE_CONFLICT : self::KEEP_UNKNOWN;',
        '            return self::KEEP_UNKNOWN;',
        'every product taken in by an older package and then edited by hand '
        'holds its vendor\'s price back for ever',
    ),
    (
        # The other half: freshness claimed on the PROJECTION path would put
        # the old copy back over the edit, which is the whole defect again.
        'the-projection-claims-its-copy-is-fresh',
        PROJECTOR,
        """        $shopFieldsMerged = ProjectedShopFields::merge(
            $shopFields,
            $wantedFields,
            $isNew ? [] : ProjectedShopFields::record((int) $wcProduct->get_id()),
            $isNew
        );""",
        """        $shopFieldsMerged = ProjectedShopFields::merge(
            $shopFields,
            $wantedFields,
            $isNew ? [] : ProjectedShopFields::record((int) $wcProduct->get_id()),
            $isNew,
            true
        );""",
        'a projection treats its own months-old copy as fresh and overwrites',
    ),
]


def main() -> int:
    verb = sys.argv[1] if len(sys.argv) > 1 else 'count'
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    name, path, old, new, expect = MUTATIONS[n]
    if verb == 'name':
        print(name)
    elif verb == 'file':
        print(path)
    elif verb == 'expect':
        print(expect)
    elif verb == 'apply':
        full = ROOT + '/' + path
        s = io.open(full, encoding='utf-8').read()
        if s.count(old) != 1:
            # ⛔ A mutation that does not apply is NOT a passing guard: it is
            # nothing measured, and the two are byte-identical from outside
            # (`alpha.77`). So this is a failure, loudly.
            print('MUTATION DID NOT APPLY: %s (%d occurrences)' % (name, s.count(old)), file=sys.stderr)
            return 1
        io.open(full, 'w', encoding='utf-8').write(s.replace(old, new))
    else:
        print('usage: count | name <n> | file <n> | expect <n> | apply <n>', file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
