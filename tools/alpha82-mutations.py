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

# ⛔ EVERY ENTRY HERE MUST GO RED, and «red» includes WARNINGS.
#
# The round's first run reported two entries as «survived» and both readings
# were wrong about something. One removed a fast-path guard whose absence the
# suites report as PHP warnings, not failures — and the tally only counted
# failures. The other claimed to remove «both halves» of a doubled guard and
# in fact removed the same half again, under a name that said otherwise. A
# mutation whose name does not match what it changes is worse than no mutation:
# it is a measurement nobody can check.
#
# name, file, old, new, what must happen
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
        # ⛔ NOT A CONTROL, and the first version of this entry called it one.
        # Measured: with the PHP refusal gone the statement still refuses, and
        # the method still answers `category_set_decided` — from the
        # `$claimed < 1` branch, which reads `import_run_id` and names the same
        # outcome. So nothing about the recorded SET changes. What DOES change
        # is that `$missing` is empty and `$missing[0]` is read anyway: two PHP
        # warnings, which the suites report and which this falsification now
        # counts. The guard is a fast path AND the thing that keeps that read
        # in range; the guarantee about the set is the statement (entry below).
        'the-php-half-of-the-narrowing-refusal',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        'if (CategorySet::lost($recorded, $ids) !== []) {',
        'if (false) {',
        'the fast-path refusal goes and $missing[0] is read on an empty array',
    ),
    (
        'the-claim-stops-guarding-against-a-second-writer',
        'src/Modules/Product/Infrastructure/DbProductRepository.php',
        "                     WHERE t.product_id = %d AND t.term_id NOT IN (' . $placeholders . ')",
        "                     WHERE t.product_id = %d AND t.term_id IN (' . $placeholders . ') AND 1 = 0",
        'two writers with different sets merge into a set nobody asked for',
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
