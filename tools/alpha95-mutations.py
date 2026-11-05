#!/usr/bin/env python3
"""This round's defects, one at a time, as data rather than shell.

The mutation lives in a Python table and not in a shell heredoc, for the
reason `alpha.77` records: every PHP snippet here is full of `$variable` and
single quotes, and an unquoted heredoc would silently blank them so the
mutation would not apply at all.

A mutation that does not apply is a FAILURE, never an unbreakable guard: the
`apply` verb exits non-zero and the runner counts it.
"""
import io
import sys

RULE = 'src/Modules/Product/Domain/StorefrontStatusReconciliation.php'
REPO = 'src/Modules/Product/Infrastructure/DbProductRepository.php'
SERVICE = 'src/Modules/Migration/Application/ReconcileStorefrontStatus.php'
PAGE = 'src/Modules/Migration/Presentation/Admin/MigrationPage.php'
HANDOVER = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php'
CHECK = 'src/Modules/Migration/Application/MigrationPlanChecklist.php'
SNAP = 'src/Modules/Migration/Application/MigrationPlanSnapshot.php'
NAV = 'src/Modules/Admin/Presentation/AdminNavigation.php'

MUTATIONS = [
    # ---------------------------------------------- the rule that picks rows
    dict(
        name="the stamp guard goes: an unstamped row becomes repairable",
        file=RULE,
        expect="unit:StorefrontStatusReconciliationTest:2",
        old="""        if ($mirroredStatus === null || trim($mirroredStatus) === '') {
            return ['verdict' => self::NOT_HELD, 'target' => null];
        }""",
        new="""        if (false) {
            return ['verdict' => self::NOT_HELD, 'target' => null];
        }""",
    ),
    dict(
        name="the in-flight guard goes: a submission can be overwritten",
        file=RULE,
        expect="unit:StorefrontStatusReconciliationTest:1",
        old="""        if (!self::inScope($rowStatus)) {
            return ['verdict' => self::IN_FLIGHT, 'target' => null];
        }""",
        new="""        if (false) {
            return ['verdict' => self::IN_FLIGHT, 'target' => null];
        }""",
    ),
    dict(
        name="an unreadable shop status reads as agreement",
        file=RULE,
        expect="unit:StorefrontStatusReconciliationTest:1",
        old="""        if ($target === null) {
            return ['verdict' => self::UNREADABLE, 'target' => null];
        }""",
        new="""        if ($target === null) {
            return ['verdict' => self::AGREED, 'target' => null];
        }""",
    ),
    dict(
        name="the scope widens to every status",
        file=RULE,
        expect="unit:StorefrontStatusReconciliationTest:1",
        old="        return $rowStatus === ProductStatus::Draft || $rowStatus === ProductStatus::Published;",
        new="        return true;",
    ),
    # ------------------------------------------- the guards in the statement
    dict(
        name="the status guard leaves the WHERE",
        file=REPO,
        expect="architecture:StatusReconciliationIsNarrowTest:1",
        old="""              WHERE id = %d
                AND status = %s
                AND `' . M0032ProductMirroredStatus::MIRRORED . '` = %s',""",
        new="""              WHERE id = %d
                AND `' . M0032ProductMirroredStatus::MIRRORED . '` = %s',""",
    ),
    dict(
        name="the stamp guard leaves the WHERE",
        file=REPO,
        expect="database:StatusReconciliationFlowTest:1",
        old="                AND `' . M0032ProductMirroredStatus::MIRRORED . '` = %s',",
        new="                AND %s IS NOT NULL',",
    ),
    dict(
        name="the row_version bump goes, so nought rows becomes ambiguous",
        file=REPO,
        expect="architecture:StatusReconciliationIsNarrowTest:1",
        old="                SET status = %s, updated_at = %s, row_version = row_version + 1",
        new="                SET status = %s, updated_at = %s",
    ),
    dict(
        name="the SET widens to the review note",
        file=REPO,
        expect="architecture:StatusReconciliationIsNarrowTest:1",
        old="                SET status = %s, updated_at = %s, row_version = row_version + 1",
        new="                SET status = %s, review_note = '', updated_at = %s, row_version = row_version + 1",
    ),
    dict(
        name="a blank stamp expectation is sent rather than refused",
        file=REPO,
        # Architecture, not database: this is a DOUBLE GUARD whose other half
        # is the statement, so no behavioural test can tell the two trees
        # apart. Measured, not assumed — it survived the database suite in
        # this round's first loop (`alpha.82`).
        expect="architecture:StatusReconciliationIsNarrowTest:1",
        old="""        if ($expectedStamp === '') {""",
        new="""        if (false) {""",
    ),
    # ------------------------------------------------- the batch's own guards
    dict(
        name="the digest compare goes",
        file=SERVICE,
        expect="database:StatusReconciliationFlowTest:1",
        old="        if (!hash_equals(OwnershipTransferPlan::digestOf($offered), trim($seenDigest))) {",
        new="        if (false) {",
    ),
    dict(
        name="a subset is filtered instead of refused",
        file=SERVICE,
        expect="database:StatusReconciliationFlowTest:1",
        old="""        if ($requested !== $offered) {
            return $this->answer(false, 'selection_not_offered', [], [], 0, '', count($requested));
        }""",
        new="""        $requested = array_values(array_intersect($requested, $offered));""",
    ),
    dict(
        name="the re-read inside the loop goes: the preview's verdict is trusted",
        file=SERVICE,
        # Architecture: the RE-PLAN refuses a moved row before the loop ever
        # reaches it, so this layer covers only the window between the two and
        # a single-threaded test cannot enter it.
        expect="architecture:StatusReconciliationIsNarrowTest:1",
        old="""        if ($verdict['verdict'] !== Rule::REPAIR || $verdict['target'] === null) {""",
        new="""        if (false) {""",
    ),
    dict(
        name="the trail is written only when the whole run succeeded",
        file=SERVICE,
        expect="database:StatusReconciliationFlowTest:1",
        old="""                $this->record($vendorUserId, $repaired, $changes, $productId, $outcome['code'], count($requested));
""",
        new="",
    ),
    dict(
        name="a caller without the permission is told the walk is over",
        file=SERVICE,
        expect="database:StatusReconciliationFlowTest:1",
        old="            'finished' => false,\n            'digest' => OwnershipTransferPlan::digestOf([]),",
        new="            'finished' => true,\n            'digest' => OwnershipTransferPlan::digestOf([]),",
    ),
    # ----------------------------------------------------- the admin surfaces
    dict(
        name="the repair card is never drawn",
        file=PAGE,
        expect="database:StatusReconciliationFlowTest:1",
        old="        echo $this->statusRepairCard($shop, $after, $fa);\n",
        new="",
    ),
    dict(
        name="the wizard card is never drawn",
        file=HANDOVER,
        expect="database:StatusReconciliationFlowTest:1",
        old="        $this->renderWizard($shops, $request, $fa);\n",
        new="",
    ),
    # ------------------------------------------------------- the twelve steps
    dict(
        name="readiness means «nothing blocked» rather than «all done»",
        file=CHECK,
        expect="unit:MigrationPlanChecklistTest:1",
        old="            'ready' => count($steps) > 0 && $counts[self::DONE] === count($steps),",
        new="            'ready' => count($steps) > 0 && $counts[self::BLOCKED] === 0,",
    ),
    dict(
        name="an unmeasured rollback reads as safe",
        file=CHECK,
        expect="unit:MigrationPlanChecklistTest:1",
        old="            default => [self::UNKNOWN, 'rollback_unknown', ''],",
        new="            default => [self::DONE, '', 'safe'],",
    ),
    dict(
        name="Dokan not running stops being a blocker",
        file=CHECK,
        expect="unit:MigrationPlanChecklistTest:1",
        old="""        return $dokan === true
            ? [self::DONE, '', (string) $version]
            : [self::BLOCKED, 'dokan_not_running', (string) $version];""",
        new="""        return [self::DONE, '', (string) $version];""",
    ),
    dict(
        name="a structure ahead of this package reads as at the target",
        file=CHECK,
        expect="unit:MigrationPlanChecklistTest:1",
        old="        if ($version !== $target) {",
        new="        if ($version < $target) {",
    ),
    dict(
        name="a short scan's figures are reported as the whole shop's",
        file=SNAP,
        # Architecture: `ShopTransferTally` walks forty pages of fifty, so no
        # fixture here can make the scan stop short — two thousand products
        # would be needed. The mutation changes nothing a three-product
        # fixture can see.
        expect="architecture:MigrationWizardIsReadOnlyTest:1",
        old="        $finished = $tally['finished'] === true && $tally['checked'] === true;",
        new="        $finished = true;",
    ),
    # ---------------------------------------------------------- the menu
    dict(
        name="the migration tools lose their own section",
        file=NAV,
        expect="contract:NavigationGroupsTest:1",
        old="""        // and last, apart, and temporary.
        self::MIGRATION => [
            'tmc-import',
            'tmc-handover',
            'tmc-minmax-exit',""",
        new="""        // and last, apart, and temporary.
        self::HEALTH => [
            'tmc-import',
            'tmc-handover',
            'tmc-minmax-exit',""",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: count | name <n> | file <n> | expect <n> | apply <n>', file=sys.stderr)
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    if n < 1 or n > len(MUTATIONS):
        print('no such mutation: %d' % n, file=sys.stderr)
        return 2
    m = MUTATIONS[n - 1]
    if verb == 'name':
        print(m['name'])
        return 0
    if verb == 'file':
        print(m['file'])
        return 0
    if verb == 'expect':
        print(m['expect'])
        return 0
    if verb != 'apply':
        print('unknown verb: %s' % verb, file=sys.stderr)
        return 2
    text = io.open(m['file'], encoding='utf-8').read()
    hits = text.count(m['old'])
    if hits != 1:
        # ⛔ Not once is a FAILURE. Zero means the snippet has gone stale and
        # nothing was measured; more than one means the tool would mutate a
        # sibling as well and the measurement is about neither (`alpha.77`).
        print('mutation %d: snippet occurs %d times in %s' % (n, hits, m['file']), file=sys.stderr)
        return 1
    io.open(m['file'], 'w', encoding='utf-8').write(text.replace(m['old'], m['new'], 1))
    return 0


if __name__ == '__main__':
    sys.exit(main())
