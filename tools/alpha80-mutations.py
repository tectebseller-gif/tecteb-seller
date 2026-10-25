#!/usr/bin/env python3
"""The mutations `alpha.80`'s fixes are falsified with.

⛔ The mutation lives in DATA, never in a shell heredoc: every PHP fragment
below is full of `$variable` and single quotes, and an unquoted heredoc empties
them so the mutation applies silently to nothing (`alpha.77`).

Each entry says which file, the exact text to replace, what to put there, and
which test filter must FAIL once it is applied. `apply` refuses when the needle
is absent or not unique — «nothing was measured» is byte-for-byte identical to
«this guard cannot be broken», so it has to be a failure.
"""
import io
import sys

MIG = 'src/Modules/Migration/Application/'
PAGE = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php'
REPO = 'src/Modules/Product/Infrastructure/DbProductRepository.php'

MUTATIONS = [
    {
        # THE finding: the gate closed on the option alone.
        'name': 'the category gate reads only the mapping again',
        'file': MIG + 'MigrationCompleteness.php',
        'old': """            'categories_mapped' => $mappableUnmapped === [] && $withoutCategory === 0,""",
        'new': """            'categories_mapped' => $mappableUnmapped === [],""",
        'expect': 'CategoryMappingFlowTest|CategoryMappingHasOneWriterTest',
    },
    {
        # A failed count must not read as «nothing left».
        'name': 'a failed count closes the gate',
        'file': MIG + 'MigrationCompleteness.php',
        'old': """            'categories_mapped' => $mappableUnmapped === [] && $withoutCategory === 0,""",
        'new': """            'categories_mapped' => $mappableUnmapped === [] && $withoutCategory <= 0,""",
        'expect': 'CategoryMappingHasOneWriterTest',
    },
    {
        'name': 'a failed count is reported as nought',
        'file': REPO,
        'old': """        return $value === null ? -1 : (int) $value;""",
        'new': """        return (int) $value;""",
        'expect': 'MalformedColumnGuardTest|CategoryMappingFlowTest',
    },
    {
        # The guard that keeps a human's choice.
        'name': 'the category write stops asking whether the row is empty',
        'file': REPO,
        'old': """              WHERE id = %d AND import_run_id <> %s AND category_key = %s',
            [$categoryKey, $this->now(), $productId, '', '']""",
        'new': """              WHERE id = %d',
            [$categoryKey, $this->now(), $productId]""",
        'expect': 'CategoryMappingFlowTest',
    },
    {
        'name': 'a row no import made becomes writable',
        'file': REPO,
        'old': """              WHERE id = %d AND import_run_id <> %s AND category_key = %s',
            [$categoryKey, $this->now(), $productId, '', '']""",
        'new': """              WHERE id = %d AND category_key = %s',
            [$categoryKey, $this->now(), $productId, '']""",
        'expect': 'CategoryMappingFlowTest',
    },
    {
        # «the manager approves what was shown».
        'name': 'a target that moved is written anyway',
        'file': MIG + 'ApplyCategoryMapping.php',
        'old': """            if ($target === '' || $target !== (string) $row['target']) {
                $outcomes[$productId] = 'target_moved';
                continue;
            }""",
        'new': """            if (false) {
                $outcomes[$productId] = 'target_moved';
                continue;
            }""",
        'expect': 'CategoryMappingFlowTest',
    },
    {
        # «نمی‌دانم» is not «nothing to do».
        'name': 'an unreadable source reads as nothing to map',
        'file': MIG + 'CategoryMapping.php',
        'old': """            foreach ($waiting as $row) {
                $hold('source_unreadable', $row, '');
            }
            return $this->summarise($readable, $waiting, $total, $apply, $held);""",
        'new': """            return $this->summarise($readable, $waiting, $total, $apply, $held);""",
        'expect': 'CategoryMappingFlowTest',
    },
    {
        'name': 'the writer runs with an unreadable source',
        'file': MIG + 'ApplyCategoryMapping.php',
        'old': """        if (!$plan['source_readable']) {
            return ['written' => 0, 'outcomes' => [], 'refused' => 'source_unreadable'];
        }""",
        'new': """        if (false) {
            return ['written' => 0, 'outcomes' => [], 'refused' => 'source_unreadable'];
        }""",
        'expect': 'CategoryMappingFlowTest',
    },
    {
        # An invalid target must never be written.
        'name': 'a target that resolves to nothing is applied',
        'file': MIG + 'CategoryMapping.php',
        'old': """            $path = $this->targetPath($target);
            if ($path === '') {
                $hold('invalid_target', $row, $sourceKey);
                continue;
            }""",
        'new': """            $path = $this->targetPath($target);""",
        'expect': 'CategoryMappingFlowTest',
    },
    {
        # A product with no source category is never auto-assigned.
        'name': 'the empty source key becomes a mappable category',
        'file': MIG + 'CategoryMapping.php',
        'old': """            if ($sourceKey === self::NO_SOURCE) {
                $hold('no_source_category', $row, '');
                continue;
            }""",
        'new': """            if (false) {
                $hold('no_source_category', $row, '');
                continue;
            }""",
        'expect': 'CategoryMappingFlowTest',
    },
    {
        # The whole first finding: nothing called save().
        'name': 'no admin path saves the mapping',
        'file': PAGE,
        'old': """            if (!$map->save($merged)) {""",
        'new': """            if (false) {""",
        'expect': 'CategoryMappingHasOneWriterTest',
    },
    {
        # The door out of the gate: a group nobody can map must not hold it.
        'name': 'the no-source group holds the decision half open',
        'file': MIG + 'MigrationCompleteness.php',
        'old': """        $mappableUnmapped = array_values(array_filter(
            $unmappedCategories,
            static fn (array $row): bool => trim((string) ($row['key'] ?? '')) !== ''
        ));""",
        'new': """        $mappableUnmapped = $unmappedCategories;""",
        'expect': 'CategoryMappingFlowTest|CategoryMappingHasOneWriterTest',
    },
    {
        # The transport trap: a percent-encoded slug through a sanitiser.
        'name': 'the source key travels as text',
        'file': PAGE,
        'old': """            $ref = $request->postKey('cat_ref');""",
        'new': """            $ref = $request->postText('cat_ref');""",
        'expect': 'CategoryMappingHasOneWriterTest',
    },
]


def main() -> int:
    verb = sys.argv[1] if len(sys.argv) > 1 else ''
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    m = MUTATIONS[n]
    if verb == 'name':
        print(m['name'])
        return 0
    if verb == 'file':
        print(m['file'])
        return 0
    if verb == 'expect':
        print(m['expect'])
        return 0
    if verb == 'apply':
        s = io.open(m['file'], encoding='utf-8').read()
        if s.count(m['old']) != 1:
            print('NEEDLE NOT UNIQUE (%d) in %s' % (s.count(m['old']), m['file']))
            return 2
        io.open(m['file'], 'w', encoding='utf-8').write(s.replace(m['old'], m['new'], 1))
        return 0
    print('usage: count | name <n> | file <n> | expect <n> | apply <n>')
    return 2


if __name__ == '__main__':
    raise SystemExit(main())
