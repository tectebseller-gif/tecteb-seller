#!/usr/bin/env python3
"""The mutations `alpha89-falsification.sh` applies, one at a time.

Each one removes a guard this round added, or puts back the behaviour the
owner's option ج replaced. The data lives here rather than in the shell
script because every snippet is full of `$variable` and single quotes, and a
heredoc without a quoted delimiter eats them silently (`alpha.77`).

A mutation names `suite:filter:count` — the suite that MUST go red and, for
the database suite, the one class that owns the claim, because a full run of
nine hundred tests takes twelve minutes and measures the same thing. «The
mutation was applied and nothing failed» is itself a failure rather than a
reassuring silence.
"""
import io
import sys

REPO = 'src/Modules/Product/Infrastructure/DbProductRepository.php'
SERVICE = 'src/Modules/Product/Application/AcceptDefaultCategory.php'
STORE = 'src/Modules/Product/Infrastructure/DbDefaultCategoryAcceptanceRepository.php'
STEP = 'src/Modules/Product/Infrastructure/Migrations/M0036DefaultCategoryAcceptance.php'
PLAN = 'src/Modules/Product/Application/CategoryLinkPlan.php'
MAPPING = 'src/Modules/Migration/Application/ApplyCategoryMapping.php'

DB = 'database:DefaultCategoryAcceptanceTest'

MUTATIONS = [
    # ---- §3 the shared predicate -------------------------------------------
    dict(
        name='drop the acceptance clause entirely (gate never closes)',
        file=REPO,
        expect=DB + ':1',
        old="        } elseif ($accepted && $defaultTermId > 0) {\n",
        new="        } elseif (false) {\n",
    ),
    dict(
        name='stop comparing the accepted term with the shop default',
        file=REPO,
        expect=DB + ':1',
        old="                   AND d.term_id = %d\n",
        new="                   AND (d.term_id = %d OR 1 = 1)\n",
    ),
    dict(
        name='stop requiring that the product carries only that term',
        file=REPO,
        expect=DB + ':1',
        old="                            AND tt2.term_id <> d.term_id\n",
        new="                            AND tt2.term_id = 0\n",
    ),
    dict(
        name='stop requiring the accepted term to still be attached',
        file=REPO,
        expect=DB + ':1',
        old="""                   AND EXISTS (
                         SELECT 1 FROM `' . $rel . '` tr
                         INNER JOIN `' . $tax . '` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                          WHERE tr.object_id = ' . $alias . '.wc_product_id
                            AND tt.taxonomy = \\'product_cat\\'
                            AND tt.term_id = d.term_id
                       )
""",
        new="                   AND 1 = 1\n",
    ),
    dict(
        name='let the counter keep its own copy of the condition',
        file=REPO,
        expect='architecture:OneWithoutCategoryPredicateTest:1',
        old="""        $sql = 'SELECT COUNT(*) FROM `' . $this->products() . '` p
                 WHERE ' . $predicate['sql'];
""",
        new="""        $this->hasCategoryTable();
        $sql = 'SELECT COUNT(*) FROM `' . $this->products() . '` p
                 WHERE ' . $predicate['sql'];
""",
    ),
    dict(
        name='read the shop default a second way, in the repository',
        file=REPO,
        expect='architecture:OneWithoutCategoryPredicateTest:2',
        old="        $defaultTermId = $accepted === true ? WpProductCategoryLinkReader::readDefaultTermId() : 0;\n",
        new="        $defaultTermId = $accepted === true ? (int) get_option('default_product_cat', 0) : 0;\n",
    ),
    # ---- §2 the service's guards -------------------------------------------
    dict(
        name='accept a product whose category is already recorded',
        file=SERVICE,
        expect=DB + ':1',
        old="        if (trim((string) $row->details->categoryKey) !== '' || $row->details->categoryIds !== []) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='accept a product that is not default-only',
        file=SERVICE,
        expect=DB + ':1',
        old="        if ($link->state !== CategoryLink::DEFAULT_ONLY) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='drop the consent guard on the term the page printed',
        file=SERVICE,
        expect=DB + ':1',
        old="        if ($seenTermId !== $defaultTermId) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='accept a row the marketplace created itself',
        file=SERVICE,
        expect=DB + ':1',
        old="        if (!$row->imported) {\n",
        new="        if (false) {\n",
    ),
    # ---- the store ---------------------------------------------------------
    dict(
        name='infer «already accepted» from a failed write instead of reading back',
        file=STORE,
        expect=DB + ':1',
        old="""        $live = $this->liveFor([$productId]);
        if ($live === null) {
            return 'acceptance_table_unreadable';
        }
        return array_key_exists($productId, $live) ? 'already_accepted' : 'storage_failed';
""",
        new="        return 'already_accepted';\n",
    ),
    dict(
        name='revoke without clearing the live key',
        file=STORE,
        expect=DB + ':1',
        old="                SET live_product_id = NULL, revoked_by = %d, revoked_at = %s\n",
        new="                SET revoked_by = %d, revoked_at = %s\n",
    ),
    # ---- §1 the migration --------------------------------------------------
    dict(
        name='backfill an acceptance in the migration',
        file=STEP,
        expect='architecture:DefaultCategoryAcceptanceIsIndependentTest:1',
        old="        // ⛔ AND THERE IS NO BACKFILL, on purpose and not by omission.\n",
        new="""        $db->execute("INSERT IGNORE INTO `{$table}` (product_id, live_product_id, term_id, decided_at)
                      SELECT 1, 1, 1, NOW()");
        // ⛔ AND THERE IS NO BACKFILL, on purpose and not by omission.
""",
    ),
    # ---- §4 the preview ----------------------------------------------------
    dict(
        name='let the preview call a stale decision accepted',
        file=PLAN,
        expect=DB + ':1',
        old="            $holds = $acceptedTerm > 0 && $defaultTermId > 0 && $acceptedTerm === $defaultTermId;\n",
        new="            $holds = $acceptedTerm > 0;\n",
    ),
    dict(
        name='drop the named refusal from the mapping path',
        file=MAPPING,
        expect=DB + ':1',
        old="""        $live = $this->acceptances?->liveFor([$productId]);
        if ($live !== null && array_key_exists($productId, $live)) {
            return 'default_category_accepted';
        }
""",
        new="",
    ),
    # ---- the SHAPE question, which the migration check found ---------------
    dict(
        name='ask whether the table exists instead of whether it carries the columns',
        file=STEP,
        expect=DB + ':1',
        old="""            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME IN (%s, %s)',
            [$table, 'live_product_id', 'term_id']
        );
        if ($answer !== null) {
            return (int) $answer === 2;
        }""",
        new="""            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($answer !== null) {
            return (int) $answer === 1;
        }""",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha89-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
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
    if verb in ('name', 'file', 'expect'):
        print(mutation[verb])
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
