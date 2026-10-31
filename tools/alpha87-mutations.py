#!/usr/bin/env python3
"""The mutations `alpha87-falsification.sh` applies, one at a time.

Each one puts back a defect this round closed, or removes a guard this round
added. The data lives here rather than in the shell script because every
snippet is full of `$variable` and single quotes, and a heredoc without a
quoted delimiter eats them silently (`alpha.77`).

A mutation names `suite:filter:count` — the suite that MUST go red and, for
the database suite, the one class that owns the claim, because a full run of
nine hundred tests takes minutes and measures the same thing. «The mutation
was applied and nothing failed» is itself a failure rather than a reassuring
silence.
"""
import io
import sys

REPO = 'src/Modules/Product/Infrastructure/DbProductRepository.php'
GATE = 'src/Modules/Migration/Application/MigrationCompleteness.php'
PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php'

MUTATIONS = [
    # ---- §1 the list and its own total ------------------------------------
    dict(
        name='ask the key alone again, as alpha.86 did',
        file=REPO,
        expect='database:CategoryMappingFlowTest:1',
        old="""            . ($carried === true
                ? ' AND NOT EXISTS (SELECT 1 FROM `' . $this->categoriesTable()
                    . '` c WHERE c.product_id = p.id)'
                : '')
            . ' ORDER BY p.id ASC LIMIT ' . max(1, min(1000, $limit));""",
        new="""            . ' ORDER BY p.id ASC LIMIT ' . max(1, min(1000, $limit));""",
    ),
    # ---- §2 the gate ------------------------------------------------------
    dict(
        name='couple the gate back to the Dokan source mapping',
        file=GATE,
        expect='database:CategoryMappingFlowTest:1',
        old="""            'categories_mapped' => $withoutCategory === 0,""",
        new="""            'categories_mapped' => $mappableUnmapped === [] && $withoutCategory === 0,""",
    ),
    dict(
        name='let a count that could not be read close the gate',
        file=GATE,
        expect='database:CategoryMappingFlowTest:1',
        old="""            'categories_mapped' => $withoutCategory === 0,""",
        new="""            'categories_mapped' => $withoutCategory <= 0,""",
    ),
    # ---- §3 the write -----------------------------------------------------
    dict(
        name='write the mapped key over a recorded set',
        file=REPO,
        expect='database:CategoryMappingFlowTest:1',
        old="""              WHERE p.id = %d AND p.import_run_id <> %s AND p.category_key = %s'
            . ($carried
                ? ' AND NOT EXISTS (SELECT 1 FROM `' . $this->categoriesTable()
                    . '` c WHERE c.product_id = p.id AND c.term_id <> ' . $asTerm . ')'
                : ''),""",
        new="""              WHERE p.id = %d AND p.import_run_id <> %s AND p.category_key = %s',""",
    ),
    dict(
        name='write through a link table that could not be read',
        file=REPO,
        expect='database:CategoryMappingFlowTest:1',
        old="""        if ($carried === null) {
            return 'category_table_unreadable';
        }
        $asTerm = ctype_digit($categoryKey) ? (int) $categoryKey : 0;""",
        new="""        $asTerm = ctype_digit($categoryKey) ? (int) $categoryKey : 0;""",
    ),
    dict(
        name='report the refusal as a storage failure instead of naming it',
        file=REPO,
        expect='database:CategoryMappingFlowTest:1',
        old="""        return (int) $recorded > 0 ? 'category_set_recorded' : 'storage_failed';""",
        new="""        return 'storage_failed';""",
    ),
    # ---- §3-ب the projector ----------------------------------------------
    dict(
        name='let an imported row project its single key to the shop',
        file=PROJECTOR,
        expect='contract:ProjectedFactsTest:1',
        old="""        $claimed = $imported ? $details->categoryIds : $details->categorySet();""",
        new="""        $claimed = $details->categorySet();""",
    ),
    dict(
        name='stop telling the projector which rows an import made',
        file=REPO,
        expect='database:CategoryMappingFlowTest:1',
        old="""            trim((string) ($row['import_run_id'] ?? '')) !== ''""",
        new="""            false""",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha87-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    index = int(sys.argv[2]) - 1
    if index < 0 or index >= len(MUTATIONS):
        print('no such mutation', file=sys.stderr)
        return 2
    mutation = MUTATIONS[index]
    if verb == 'name':
        print(mutation['name'])
        return 0
    if verb == 'file':
        print(mutation['file'])
        return 0
    if verb == 'expect':
        print(mutation['expect'])
        return 0
    if verb == 'apply':
        source = io.open(mutation['file'], encoding='utf-8').read()
        hits = source.count(mutation['old'])
        if hits != 1:
            # ⛔ A FAILURE, not an unbreakable guard. A mutation that does not
            # apply measured nothing, and «nothing was measured» looks exactly
            # like «this cannot be broken» (`alpha.77`).
            print('mutation did not apply: %d occurrences' % hits, file=sys.stderr)
            return 1
        io.open(mutation['file'], 'w', encoding='utf-8').write(
            source.replace(mutation['old'], mutation['new'])
        )
        return 0
    print('unknown verb', file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main())
