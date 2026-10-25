#!/usr/bin/env python3
"""
The mutations for the multi-category round, as DATA rather than as shell
heredocs.

⛔ Kept out of the shell on purpose. Every PHP fragment here is full of
`$variable` and single quotes, and an unquoted heredoc expands `$ids` to
nothing — so the mutation applies to a line that no longer matches and the
script reports «survived» about a mutation that was never made (`alpha.77`).

Each entry puts ONE of this round's fixes back the way `alpha.81` had it. The
owner's acceptance rule is what every one of them is measured against:
«برابری مجموعهٔ شناسه‌ها را قبل و بعد از اعمال، ویرایش و پذیرش اندازه بگیر؛
برابری تعداد کافی نیست.»
"""
import io
import sys

ROOT = __file__.rsplit('/tools/', 1)[0]

# ⛔ SOME ENTRIES ARE CONTROLS, and they are marked.
#
# A control is a defect put back that must leave every suite GREEN, because a
# SECOND guard still holds — and saying so is the only honest way to report it.
# The round's first run called two of these «survived», which reads as «this
# guard cannot be broken» and is the `alpha.77` trap from the other side: the
# guard was doing its job and the measurement was describing the wrong one. A
# control is paired with a mutation that removes BOTH halves, and THAT one has
# to go red — otherwise the pair is not measured at all.
#
# name, file, old, new, what must happen, control?
MUTATIONS = [
    (
        'intake-stores-the-first-category-again',
        'src/Modules/Migration/Application/ImportExistingProducts.php',
        'categoryKey: CategorySet::soleKey($this->categoryIdsFrom($detail)),',
        "categoryKey: (string) ($this->categoryIdsFrom($detail)[0] ?? ''),",
        'alpha.81 behaviour: the first of several lands in the one key',
    ),
    (
        'intake-records-no-relationship',
        'src/Modules/Migration/Application/ImportExistingProducts.php',
        'categoryIds: $this->categoryIdsFrom($detail)',
        'categoryIds: []',
        'the set is read and then thrown away, so nothing records it',
    ),
    (
        'the-projector-writes-only-the-single-key',
        'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php',
        'return CategorySet::of(array_merge($current, $wanted));',
        'return $wanted;',
        'projection REPLACES the terms and deletes the rest',
    ),
    (
        'the-projector-stops-reading-the-whole-set',
        'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php',
        'foreach ($details->categorySet() as $id) {',
        "foreach (CategorySet::of([$details->categoryKey]) as $id) {",
        'only the one key reaches WooCommerce, whatever is recorded',
    ),
    (
        'the-form-fuses-the-posted-ids-into-one',
        'src/Modules/Product/Infrastructure/WordPress/ProductArea.php',
        "CategorySet::of($request->postIdList('category'))",
        "CategorySet::of([$request->postKey('category')])",
        'sanitize_key strips the separators: 207,208,209 becomes 207208209',
    ),
    (
        'acceptance-ignores-the-carried-set',
        'src/Modules/Product/Application/ReviewProducts.php',
        """            array_key_exists('categoryIds', $d)
                ? CategorySet::of((array) $d['categoryIds'])
                : $current->categorySet()""",
        '            []',
        'approving a revision narrows the relationship back to one',
    ),
    (
        'the-keep-decision-writes-the-key-only',
        'src/Modules/Product/Application/ReviewProducts.php',
        """        return [
            'categoryIds' => $ids,
            'categoryKey' => CategorySet::soleKey($ids),
        ];""",
        "        return ['categoryKey' => CategorySet::soleKey($ids)];",
        'keeping the shop version writes the key and leaves the set stale',
    ),
    (
        # CONTROL. Removing the PHP half alone changes nothing observable,
        # because the claim statement carries the same condition inside it
        # (`alpha.74`: the check that returns is the report, the statement is
        # the guarantee). What the PHP half adds is the NAME of the refusal,
        # which `testNarrowingARecordedSetIsRefusedByNameAndByTheStatement`
        # asserts — and what it cannot add is protection, which is why this
        # must stay GREEN and the entry after next must go red.
        'control-the-php-half-of-the-narrowing-refusal',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        'if (CategorySet::lost($recorded, $ids) !== []) {',
        'if (false) {',
        'nothing: the statement-level guard still refuses, and that is the point',
        True,
    ),
    (
        'the-claim-stops-guarding-against-a-second-writer',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        "                     WHERE t.product_id = %d AND t.term_id NOT IN (' . $placeholders . ')",
        "                     WHERE t.product_id = %d AND t.term_id IN (' . $placeholders . ') AND 1 = 0",
        'two writers with different sets merge into a set nobody asked for',
    ),
    (
        # BOTH HALVES AT ONCE — the pair the control above is half of. With
        # the PHP refusal gone AND the statement's own condition gone, a set
        # somebody filed on purpose is narrowed without anybody being asked:
        # «دسته‌ای که مدیر قبلاً صریحاً تعیین کرده بدون تأیید بازنویسی نشود».
        'the-narrowing-refusal-removed-on-both-sides',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        """        if (CategorySet::lost($recorded, $ids) !== []) {
            // An id is recorded that this product does not carry: somebody
            // filed it there on purpose. Refused, named, and nothing written.
            $this->db->rollback();
            return 'category_set_decided';
        }""",
        '        // both halves of the narrowing refusal removed',
        'a recorded decision is narrowed with nothing refusing it',
    ),
    (
        'the-recorded-set-is-read-without-the-lock',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        "               FROM `' . $table . '` WHERE product_id = %d FOR UPDATE',",
        "               FROM `' . $table . '` WHERE product_id = %d',",
        'the completion decision is taken on a value read before the lock',
    ),
    (
        'an-empty-set-clears-the-relationship',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        "        if ($ids === []) {\n            return 'category_set_empty';",
        "        if (false) {\n            return 'category_set_empty';",
        'an ordinary save that carries no category deletes every link row',
    ),
    (
        'the-apply-guard-compares-the-spelling',
        'src/Modules/Product/Application/ApplyCategoryLinks.php',
        'if (!CategorySet::same($sawIds, $ids)) {',
        'if ((string) $seen[$productId] !== implode(\',\', $link->validIds)) {',
        'a reordering reads as a change, and a real change can slip through',
    ),
    (
        'the-apply-path-refuses-several-categories-again',
        'src/Modules/Product/Application/ApplyCategoryLinks.php',
        '            CategoryLink::UNREADABLE => CategoryLinkPlan::UNREADABLE,\n            default => \'\',',
        "            CategoryLink::UNREADABLE => CategoryLinkPlan::UNREADABLE,\n            CategoryLink::MULTIPLE => CategoryLinkPlan::MULTIPLE,\n            default => '',",
        "the owner's nine products are refused and recorded nowhere",
    ),
    (
        'sole-key-picks-a-winner',
        'src/Modules/Product/Domain/CategorySet.php',
        "        return count($set) === 1 ? (string) $set[0] : '';",
        "        return $set === [] ? '' : (string) $set[0];",
        'the open spec-template decision is answered by accident',
    ),
    (
        'the-set-stops-being-a-set',
        'src/Modules/Product/Domain/CategorySet.php',
        '        sort($out);\n        return $out;',
        '        return $out;',
        'equality becomes order-sensitive, so a reordering reads as a change',
    ),
    (
        'a-recorded-set-no-longer-settles-the-form',
        'src/Modules/Product/Domain/ProductDetails.php',
        "        if ($this->categorySet() === [] && trim($this->categoryKey) === '') {",
        "        if (trim($this->categoryKey) === '') {",
        'the manager is forced to pick a category that is already recorded',
    ),
    (
        # ⛔ REWRITTEN, because the first version of this mutation was
        # SEMANTICALLY INERT: `SELECT 0` and `SELECT 1` inside `EXISTS` are
        # the same statement — the subquery's value is never read, only
        # whether it returns a row. So it «survived» every suite while
        # measuring nothing, which is the `alpha.77` trap written in SQL. The
        # clause itself is what has to go.
        'the-gate-stops-counting-unrecorded-relationships',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        """            . ($carried
                ? ' AND NOT EXISTS (SELECT 1 FROM `' . $this->categoriesTable()
                    . '` c WHERE c.product_id = p.id)'
                : '');""",
        "            . '';",
        'a product whose whole relationship is recorded still counts as unmapped',
    ),
    (
        # The security half of the set becoming sensitive: approval APPLIES
        # it, so the review table has to DRAW it. Reverting this row is how a
        # manager agrees to a category move they were never shown
        # (`alpha.49`).
        'the-review-table-stops-drawing-the-set',
        'src/Modules/Product/Presentation/Admin/ProductReviewPage.php',
        'foreach (SensitiveChange::SENSITIVE_SETS as $field) {',
        'foreach ([] as $field) {',
        'the manager approves a category change that never appeared on a screen',
    ),
    (
        'a-failed-table-read-reads-as-absent',
        'src/Modules/Product/Infrastructure/Migrations/M0035ProductCategorySet.php',
        '        return $columns === [] ? null : true;',
        '        return $columns !== [];',
        'alpha.68 one table over: a failed read silently narrows the set',
    ),
]


def main() -> int:
    verb = sys.argv[1] if len(sys.argv) > 1 else 'count'
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    entry = MUTATIONS[n]
    name, path, old, new, expect = entry[:5]
    control = len(entry) > 5 and bool(entry[5])
    if verb == 'name':
        print(name)
    elif verb == 'file':
        print(path)
    elif verb == 'expect':
        print(expect)
    elif verb == 'control':
        # Printed rather than returned as an exit code, so the driver can put
        # it in the report beside the counts.
        print('yes' if control else 'no')
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
        print(
            'usage: count | name <n> | file <n> | expect <n> | control <n> | apply <n>',
            file=sys.stderr,
        )
        return 2
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
