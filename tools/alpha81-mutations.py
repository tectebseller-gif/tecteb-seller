#!/usr/bin/env python3
"""
The mutations for this round, as DATA rather than as shell heredocs.

⛔ Kept out of the shell on purpose. Every PHP fragment here is full of
`$variable` and single quotes, and an unquoted heredoc expands `$plan` to
nothing — so the mutation applies to a line that no longer matches and the
script reports «survived» about a mutation that was never made (`alpha.77`).
"""
import io
import sys

ROOT = __file__.rsplit('/tools/', 1)[0]

# name, file, old, new, what must go red
MUTATIONS = [
    (
        'definite-key-returns-the-first-of-several',
        'src/Modules/Product/Domain/CategoryLink.php',
        "return $this->isDefinite() ? (string) $this->validIds[0] : '';",
        "return $this->validIds === [] ? '' : (string) $this->validIds[0];",
        'alpha.80 behaviour: one of several picked arbitrarily',
    ),
    (
        'drop-the-freshness-guard',
        'src/Modules/Product/Application/ApplyCategoryLinks.php',
        "if (array_key_exists($productId, $seen) && trim($seen[$productId]) !== $key) {",
        'if (false) {',
        'a stale consent writes whatever the shop says now',
    ),
    (
        'overwrite-a-category-somebody-chose',
        'src/Modules/Product/Application/CategoryLinkPlan.php',
        "                $stored === $link->definiteKey() => self::SETTLED,\n                default => self::DECIDED,",
        "                $stored === $link->definiteKey() => self::SETTLED,\n                default => self::APPLY,",
        "a manager's explicit category is offered for replacement",
    ),
    (
        'merge-the-narrowed-group-into-multiple',
        'src/Modules/Product/Application/CategoryLinkPlan.php',
        "CategoryLink::MULTIPLE => $stored === '' ? self::MULTIPLE : self::NARROWED,",
        'CategoryLink::MULTIPLE => self::MULTIPLE,',
        'the rows the intake already narrowed lose their own name',
    ),
    (
        'the-default-term-counts-as-a-category',
        'src/Modules/Product/Domain/CategoryLink.php',
        "            if ($defaultTermId > 0 && $id === $defaultTermId) {",
        '            if (false) {',
        'WooCommerce\'s placeholder is stored as a real decision',
    ),
    (
        'drop-the-short-read-guard',
        'src/Modules/Product/Infrastructure/WooCommerce/WpProductCategoryLinkReader.php',
        'if (count($rows) !== $expected) {',
        'if (false) {',
        'a truncated read looks like a product with fewer categories',
    ),
    (
        'drop-the-taxonomy-filter',
        'src/Modules/Product/Infrastructure/WooCommerce/WpProductCategoryLinkReader.php',
        '" WHERE tt.taxonomy = \'product_cat\' AND tr.object_id IN ({$placeholders})"',
        '" WHERE tr.object_id IN ({$placeholders})"',
        'tags and brands are read as categories',
    ),
    (
        'remove-the-all-words-rank',
        'src/Modules/Product/Domain/CategoryMatcher.php',
        "        $words = self::words($query);\n        if (count($words) > 1 && self::missingWords($words, $candidate . ' ' . $extra) === []) {\n            return self::ALL_WORDS;\n        }\n",
        '',
        'the reported multi-word Persian search finds nothing again',
    ),
    (
        'any-word-instead-of-all-words',
        'src/Modules/Product/Domain/CategoryMatcher.php',
        "self::missingWords($words, $candidate . ' ' . $extra) === []",
        'count(self::missingWords($words, $candidate . \' \' . $extra)) < count($words)',
        'one common word matches most of a 1,070-term catalogue',
    ),
    (
        'the-empty-result-stops-naming-the-missing-word',
        'src/Modules/Product/Infrastructure/WooCommerce/WpProductCategoryDirectory.php',
        "        return ['words' => $words, 'missing' => $missing, 'found' => $found];",
        "        return ['words' => $words, 'missing' => [], 'found' => $found];",
        'an empty search says nothing a person can act on',
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
