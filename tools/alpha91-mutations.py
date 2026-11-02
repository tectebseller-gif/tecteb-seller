#!/usr/bin/env python3
"""The mutations `alpha91-falsification.sh` applies, one at a time.

This round has two new paths — the manager's records confirmation (§2) and
the staged ownership batch (§4) — plus one audit key that was being dropped
in silence. Every mutation below puts one of those back.

A mutation names `suite:filter:count`. «The mutation was applied and nothing
failed» is itself a failure rather than a reassuring silence (`alpha.77`), and
the data lives here rather than in the shell script because every snippet is
full of `$variable` and single quotes.
"""
import io
import sys

GATE = 'src/Modules/Migration/Application/MigrationCompleteness.php'
CONFIRM = 'src/Modules/Migration/Application/ConfirmShopRecords.php'
REPO = 'src/Modules/Migration/Infrastructure/DbShopRecordConfirmationRepository.php'
STEP = 'src/Modules/Migration/Infrastructure/Migrations/M0037ShopRecordConfirmation.php'
PLAN = 'src/Modules/Migration/Application/OwnershipTransferPlan.php'
APPLY = 'src/Modules/Migration/Application/ApplyOwnershipTransfer.php'
CATALOG = 'src/Core/Audit/AuditEventCatalog.php'
PAGE = 'src/Modules/Migration/Presentation/Admin/MigrationPage.php'

CONF_DB = 'database:ShopRecordConfirmationTest'
CONF_ARCH = 'architecture:ShopRecordConfirmationIsNeverAutomaticTest'
GATE_ARCH = 'architecture:BeingAShopIsNotOwningRecordsTest'
BATCH_DB = 'database:StagedOwnershipTransferTest'
BATCH_ARCH = 'architecture:StagedOwnershipTransferIsSafeTest'
MONEY_DB = 'database:HandoverConcurrencyTest'

MUTATIONS = [
    # ------------------------------------------------- §2 the confirmation
    dict(
        name='a live confirmation closes the gate whatever version it was taken against',
        file=GATE,
        expect=CONF_DB + ':1',
        old="""        $stored = (int) ($live['records_version'] ?? 0);
        $now = $this->records->recordsVersion($vendorUserId);
        if ($stored !== $now) {
""",
        new="""        $stored = (int) ($live['records_version'] ?? 0);
        $now = $this->records->recordsVersion($vendorUserId);
        if (false) {
""",
    ),
    dict(
        name='a version of zero is trusted without asking whether anything is held',
        file=GATE,
        expect=CONF_DB + ':1',
        old="        if ($stored === 0) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='the gate opens on a stale confirmation too',
        file=GATE,
        expect=GATE_ARCH + ':1',
        old="                self::RECORDS_SETTLED, self::RECORDS_NO_SOURCE, self::RECORDS_CONFIRMED => true,\n",
        new="                self::RECORDS_SETTLED, self::RECORDS_NO_SOURCE, self::RECORDS_CONFIRMED,\n"
            "                self::RECORDS_CONFIRMATION_STALE => true,\n",
    ),
    dict(
        name='the confirmation is read even while Dokan can still be asked',
        file=GATE,
        expect=GATE_ARCH + ':1',
        old="            in_array('dokan_not_running', $unchecked, true) => $this->withoutDokan($vendorUserId),\n",
        new="            $unchecked !== [] => $this->withoutDokan($vendorUserId),\n",
    ),
    dict(
        name='an unsettled reconciliation may be confirmed anyway',
        file=CONFIRM,
        expect=CONF_DB + ':2',
        old="        if (!in_array($state, [MigrationCompleteness::RECORDS_SETTLED, MigrationCompleteness::RECORDS_NO_SOURCE], true)) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='a record owner who is not a shop may be confirmed',
        file=CONFIRM,
        expect=CONF_DB + ':1',
        old="        if (!in_array($vendorUserId, $this->completeness->shopVendorUserIds(), true)) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='confirm() trusts the eligibility the render computed',
        file=CONFIRM,
        expect=CONF_ARCH + ':1',
        old="""        $gate = $this->eligibility($vendorUserId);
        if (!$gate['eligible']) {
""",
        new="""        $gate = ['eligible' => true, 'reason' => '', 'state' => ''];
        if (!$gate['eligible']) {
""",
    ),
    dict(
        name='the confirmation is audited before the write, not after',
        file=CONFIRM,
        expect=CONF_ARCH + ':1',
        old="""        $code = $this->confirmations->confirm($vendorUserId, $version, $reconciliation, $actorId);
        if ($code !== '') {
            return OperationResult::failure($code, ['vendor_id' => $vendorUserId]);
        }

        $this->audit?->log(
            AuditEventCatalog::DOKAN_RECORDS_CONFIRMED,
""",
        new="""        $this->audit?->log(
            AuditEventCatalog::DOKAN_RECORDS_CONFIRMED,
""",
    ),
    dict(
        name='revoking clears the live key without asking which row holds it',
        file=REPO,
        expect=CONF_DB + ':1',
        old="              WHERE live_vendor_user_id = %d',\n            [max(0, $actorId), $this->now(), $vendorUserId]\n",
        new="              WHERE vendor_user_id = %d',\n            [max(0, $actorId), $this->now(), $vendorUserId]\n",
    ),
    dict(
        name='carriedBy() asks whether a table of this name exists',
        file=STEP,
        expect=CONF_DB + ':1',
        old="""               AND COLUMN_NAME IN (%s, %s)',
            [$table, 'live_vendor_user_id', 'records_version']
        );
        if ($answer !== null) {
            return (int) $answer === 2;
        }
""",
        new="""               AND COLUMN_NAME IS NOT NULL',
            [$table, 'live_vendor_user_id', 'records_version']
        );
        if ($answer !== null) {
            return (int) $answer > 0;
        }
""",
    ),
    dict(
        name='the migration backfills a confirmation for every imported shop',
        file=STEP,
        expect=CONF_ARCH + ':1',
        old="    public function up(DatabaseInterface $db): void\n    {\n        $table = self::table($db);\n",
        new="    public function up(DatabaseInterface $db): void\n    {\n        $table = self::table($db);\n"
            "        $db->execute('INSERT IGNORE INTO `' . $table . '` (vendor_user_id) VALUES (0)');\n",
    ),
    # -------------------------------------------------- the dropped audit key
    dict(
        name='records_version is dropped from the financial decision again',
        file=CATALOG,
        expect=MONEY_DB + ':1',
        old="                'pending_total', 'decision', 'records_version',\n",
        new="                'pending_total', 'decision',\n",
    ),
    # ------------------------------------------------------ §4 the staged batch
    dict(
        name='the batch trusts the ids the form carried',
        file=APPLY,
        expect=BATCH_DB + ':1',
        old="        if (!hash_equals(OwnershipTransferPlan::digestOf($ready), trim($seenDigest))) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='the batch filters the submission down instead of refusing it',
        file=APPLY,
        expect=BATCH_DB + ':2',
        old="        if ($requested !== $offered) {\n",
        new="        $requested = array_values(array_intersect($requested, $offered));\n        if ($requested === [] && false) {\n",
    ),
    dict(
        name='the batch carries on past the first failure',
        file=APPLY,
        expect=BATCH_DB + ':1',
        old="""                $this->record($vendorUserId, $moved, $productId, $result->code, count($requested));
                return $this->answer(false, 'stopped', $moved, $productId, $result->code, count($requested));
""",
        new="""                continue;
""",
    ),
    dict(
        name='only a finished batch is audited',
        file=APPLY,
        expect=BATCH_DB + ':1',
        old="                $this->record($vendorUserId, $moved, $productId, $result->code, count($requested));\n",
        new="",
    ),
    dict(
        name='a risk or a drift no longer excludes a row',
        file=PLAN,
        expect=BATCH_DB + ':3',
        old="            'bucket' => $risks === [] && $drift === [] ? self::READY : self::NEEDS_DECISION,\n",
        new="            'bucket' => self::READY,\n",
    ),
    dict(
        name='a product that could not be read counts as agreeing',
        file=PLAN,
        expect=BATCH_DB + ':1',
        old="""        $detail = $this->catalogue->detail($wcProductId);
        if ($detail === []) {
            return $base + ['bucket' => self::UNREADABLE];
        }
""",
        new="""        $detail = $this->catalogue->detail($wcProductId);
        if ($detail === []) {
            return $base + ['bucket' => self::READY];
        }
""",
    ),
    dict(
        name='a build that cannot ask either question says it checked',
        file=PLAN,
        expect=BATCH_DB + ':1',
        old="            'checked' => $this->catalogue !== null && $this->intake !== null,\n",
        new="            'checked' => true,\n",
    ),
    dict(
        name='a full page is reported as the end of the walk',
        file=PLAN,
        expect=BATCH_DB + ':1',
        old="            'finished' => count($rows) < $limit,\n",
        new="            'finished' => count($rows) <= $limit,\n",
    ),
    dict(
        name='the batch acknowledges the risks on the manager\'s behalf',
        file=APPLY,
        expect=BATCH_ARCH + ':1',
        old="            $result = $this->transfer->take($productId, [], []);\n",
        new="            $result = $this->transfer->take($productId, array_keys($this->transfer->risksFor($productId)), []);\n",
    ),
    dict(
        name='the page acts on a batch without asking for a nonce',
        file=PAGE,
        expect=BATCH_ARCH + ':1',
        old="        if (!$request->nonceOk('tmc_migration_nonce', self::NONCE)) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='the offered list is not capped at the batch limit',
        file=PLAN,
        expect=BATCH_DB + ':1',
        old="            'ready' => array_slice($ready, 0, self::BATCH_LIMIT),\n",
        new="            'ready' => $ready,\n",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha91-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
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
