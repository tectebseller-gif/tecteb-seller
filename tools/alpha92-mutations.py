#!/usr/bin/env python3
"""The mutations `alpha92-falsification.sh` applies, one at a time.

This round has four halves — the mirrored status on the Dokan path and its
backfill (§1), the batched re-snapshot (§2), the risk split (§3) and the
whole-shop tally (§4). Every mutation below puts one of those defects back.

A mutation names `suite:filter:count`. «The mutation was applied and nothing
failed» is itself a failure rather than a reassuring silence (`alpha.77`), and
the data lives here rather than in the shell script because every snippet is
full of `$variable` and single quotes.

⛔ Some mutations are expected to be caught by an ARCHITECTURE assertion
rather than a behaviour one, and that is written down per mutation. Two
reasons, both measured: `if (false)` leaves the text of a call in place, so a
containment test alone would stay green and the CONDITION has to be pinned
(`alpha.79`); and the real `WooCommerceProjector` is unreachable from the
database suite, so the hold on its two writes is asserted in source
(`alpha.77`).
"""
import io
import sys

STATUS = 'src/Modules/Product/Domain/ProjectedStatus.php'
RISK = 'src/Modules/Migration/Domain/PreservationRisk.php'
PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php'
DOUBLE = 'tests/Support/FakeCatalogProjector.php'
TRANSFER = 'src/Modules/Migration/Application/TransferOwnership.php'
PLAN = 'src/Modules/Migration/Application/OwnershipTransferPlan.php'
BATCH = 'src/Modules/Migration/Application/ApplyRefreshSnapshot.php'
TALLY = 'src/Modules/Migration/Application/ShopTransferTally.php'
DOKAN = 'src/Modules/Migration/Application/ImportFromDokan.php'
STEP = 'src/Modules/Product/Infrastructure/Migrations/M0038ObservedMirroredStatusBackfill.php'
REPO = 'src/Modules/Product/Infrastructure/DbProductRepository.php'
INTAKE = 'src/Modules/Migration/Application/ImportExistingProducts.php'

ST_UNIT = 'unit:ProjectedStatusTest'
RK_UNIT = 'unit:PreservationRiskTest'
ST_ARCH = 'architecture:ProjectedStatusIsOneRuleTest'
RK_ARCH = 'architecture:PreservationRiskClassificationTest'
BT_ARCH = 'architecture:SnapshotBatchWritesNothingToTheShopTest'
MIR_DB = 'database:MirroredStatusAndSnapshotBatchTest'
FILL_DB = 'database:MirroredStatusBackfillTest'
DOKAN_DB = 'database:DokanMigrationTest'

MUTATIONS = [
    # ------------------------------------------- §1 the one status rule
    dict(
        name='the stamp is not checked, so a held row is judged on its value',
        file=STATUS,
        expect=ST_UNIT + ':1',
        old="        if ($mirroredStatus !== null && trim($mirroredStatus) !== '') {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='an unreadable shop status reads as agreement',
        file=STATUS,
        expect=ST_UNIT + ':1',
        old="            return self::UNREADABLE;\n",
        new="            return self::AGREES;\n",
    ),
    dict(
        name='«would change» counts as settled',
        file=STATUS,
        expect=MIR_DB + ':1',
        old="        return $verdict === self::HELD || $verdict === self::AGREES;\n",
        new="        return $verdict !== self::UNREADABLE;\n",
    ),
    dict(
        # ⛔ Architecture, on purpose: the real projector is not reachable from
        # the database suite, which binds `FakeCatalogProjector`.
        name='the real projector writes the status and visibility unconditionally',
        file=PROJECTOR,
        expect=ST_ARCH + ':1',
        old="        if (!$statusHeld) {\n",
        new="        if (true) {\n",
    ),
    dict(
        name='the test double is more generous than the projector it stands for',
        file=DOUBLE,
        expect=MIR_DB + ':1',
        old="        if (!$product->storefrontStatusIsHeld()) {\n",
        new="        if (true) {\n",
    ),
    dict(
        # ⛔ `if (false)` keeps the call text, so containment alone stays green
        # and only the pinned CONDITION catches this (`alpha.79`).
        name='the transfer never asks about the publish state',
        file=TRANSFER,
        expect=ST_ARCH + ':1',
        old="        if ($status !== null && !ProjectedStatus::settled($status['verdict'])) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='only an unreadable status stops the transfer; a change goes through',
        file=TRANSFER,
        expect=MIR_DB + ':1',
        old="        if ($status !== null && !ProjectedStatus::settled($status['verdict'])) {\n",
        new="        if ($status !== null && $status['verdict'] === ProjectedStatus::UNREADABLE) {\n",
    ),
    dict(
        name='a tick waives the publish state after all',
        file=TRANSFER,
        expect=MIR_DB + ':1',
        old="        if ($status !== null && !ProjectedStatus::settled($status['verdict'])) {\n",
        new="        if ($status !== null && !ProjectedStatus::settled($status['verdict'])\n"
            "            && !in_array('status', $acknowledged, true)) {\n",
    ),
    # ------------------------------------------- §1 the Dokan intake path
    dict(
        name='the Dokan intake records a draft whatever the shop holds',
        file=DOKAN,
        expect=DOKAN_DB + ':1',
        old="\n                $mirrored,\n",
        new="\n                ProductStatus::Draft,\n",
    ),
    dict(
        name='the Dokan intake writes no stamp',
        file=DOKAN,
        expect=DOKAN_DB + ':1',
        old="                // none, which is «no stamp» rather than a stamp of nothing.\n"
            "                $shopStatus\n",
        new="                // none, which is «no stamp» rather than a stamp of nothing.\n"
            "                ''\n",
    ),
    dict(
        name='a reader that names no status has its products silently skipped',
        file=DOKAN,
        expect=DOKAN_DB + ':1',
        old="            $mirrored = $toldTheStatus\n"
            "                ? ProductStatus::forIntakeOf($shopStatus)\n"
            "                : ProductStatus::Draft;\n"
            "            if ($mirrored === null) {\n"
            "                // A status this marketplace has no field for.",
        new="            $mirrored = ProductStatus::forIntakeOf($shopStatus);\n"
            "            if ($mirrored === null) {\n"
            "                // A status this marketplace has no field for.",
    ),
    dict(
        name='the rollback deletes by the row status again, so a mirrored publish survives',
        file=DOKAN,
        expect=DOKAN_DB + ':1',
        old="            $notRemoved = $this->products->deleteObservedFromRun($productId, $runId);\n",
        new="            $notRemoved = $this->products->deleteDraft($productId) ? '' : 'not_this_run';\n",
    ),
    dict(
        name='a kept row loses a stamp that belongs to a later run',
        file=DOKAN,
        expect=DOKAN_DB + ':1',
        old="                if (in_array($productId, $stamped, true)) {\n"
            "                    $this->products->stampImportRun($productId, '');\n"
            "                }\n"
            "                continue;",
        new="                $this->products->stampImportRun($productId, '');\n"
            "                continue;",
    ),
    # ------------------------------------------- §1 the backfill, step 38
    dict(
        name='the backfill invents a status instead of reading the product',
        file=STEP,
        expect=FILL_DB + ':1',
        old="LEFT(o.post_status, 20)",
        new="'draft'",
    ),
    dict(
        name='the backfill overwrites a stamp that is already there',
        file=STEP,
        expect=FILL_DB + ':1',
        old="                SET p.`' . M0032ProductMirroredStatus::MIRRORED . '` = LEFT(o.post_status, 20)\n"
            "              WHERE p.`' . M0032ProductMirroredStatus::MIRRORED . '` IS NULL\n",
        new="                SET p.`' . M0032ProductMirroredStatus::MIRRORED . '` = LEFT(o.post_status, 20)\n"
            "              WHERE 1 = 1\n",
    ),
    dict(
        name='the backfill stamps a marketplace-owned row too',
        file=STEP,
        expect=FILL_DB + ':1',
        old="                AND p.`' . M0010LinkOwnership::COLUMN . '` = %s',\n"
            "            ['product', LinkOwnership::Observed->value]\n"
            "        );\n"
            "        // Nought rows is a SUCCESS",
        new="                AND 1 = 1',\n"
            "            ['product']\n"
            "        );\n"
            "        // Nought rows is a SUCCESS",
    ),
    dict(
        # ⛔ Without the JOIN, `verify()` waits for rows `up()` cannot fill
        # and the chain locks on 37 for ever, retried every five minutes
        # (`alpha.46`). The same condition on both sides, or neither
        # (`alpha.89`).
        name='verify() counts rows the backfill cannot reach',
        file=STEP,
        expect=FILL_DB + ':1',
        old="            'SELECT COUNT(*) FROM `' . $products . '` p\n"
            "               JOIN `' . $posts . '` o\n"
            "                 ON o.ID = p.wc_product_id AND o.post_type = %s\n",
        new="            'SELECT COUNT(*) FROM `' . $products . '` p\n"
            "               LEFT JOIN `' . $posts . '` o\n"
            "                 ON o.ID = p.wc_product_id AND o.post_type = %s\n",
    ),
    dict(
        name='an absent posts table becomes a failure that blocks the chain',
        file=STEP,
        expect=FILL_DB + ':1',
        old="        if ($carried === false) {\n            return;                     // nothing to backfill; see `hasPosts()`\n",
        new="        if ($carried === false) {\n"
            "            throw new MigrationException('no posts table');\n",
    ),
    # ------------------------------------------- §2 the re-snapshot batch
    dict(
        name='the stamp write loses its «only when absent» guard',
        file=REPO,
        expect=MIR_DB + ':1',
        old="              WHERE id = %d\n"
            "                AND `' . M0032ProductMirroredStatus::MIRRORED . '` IS NULL',\n",
        new="              WHERE id = %d',\n",
    ),
    dict(
        name='the refresh does not record the stamp at all',
        file=INTAKE,
        expect=MIR_DB + ':1',
        old="        if (!$this->products->recordMirroredStatusWhenAbsent($productId, (string) ($detail['status'] ?? ''))) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='the batch does not compare the digest the page printed',
        file=BATCH,
        expect=MIR_DB + ':1',
        old="        if (!hash_equals(OwnershipTransferPlan::digestOf($offered), trim($seenDigest))) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='the batch refreshes a row the page never offered',
        file=BATCH,
        expect=MIR_DB + ':1',
        old="        if ($requested !== $offered) {\n",
        new="        if (false) {\n",
    ),
    dict(
        name='the batch carries on past the first failure',
        file=BATCH,
        expect=MIR_DB + ':1',
        old="            if (!$result->ok) {\n"
            "                $this->record($vendorUserId, $refreshed, $productId, $result->code, count($requested));\n"
            "                return $this->answer(false, 'stopped', $refreshed, $productId, $result->code, count($requested));\n"
            "            }\n",
        new="            if (!$result->ok) {\n                continue;\n            }\n",
    ),
    dict(
        name='the offer is not capped at twenty five',
        file=PLAN,
        expect=MIR_DB + ':1',
        old="        $refreshable = array_slice($refreshable, 0, self::BATCH_LIMIT);\n",
        new="",
    ),
    dict(
        # A refresh cannot clear a real risk, so offering such a row would
        # promise a batch that leaves it exactly where it was.
        name='a row with a real risk is offered for a re-snapshot',
        file=PLAN,
        expect=BT_ARCH + ':1',
        old="    public const REFRESHABLE = [self::DRIFT_FIXABLE, self::STATUS_BLOCKED];\n",
        new="    public const REFRESHABLE = [self::DRIFT_FIXABLE, self::STATUS_BLOCKED, self::RISK_REAL];\n",
    ),
    # ------------------------------------------- §3 the risk split
    dict(
        name='a key in neither list becomes waivable',
        file=RISK,
        expect=RK_UNIT + ':1',
        old="        return !in_array($key, self::DISCLOSURE, true);\n",
        new="        return in_array($key, self::BLOCKING, true);\n",
    ),
    dict(
        name='manage_stock is treated as a disclosure, which a projection overwrites',
        file=RISK,
        expect=RK_ARCH + ':1',
        old="    public const DISCLOSURE = [\n        'backorders',\n",
        new="    public const DISCLOSURE = [\n        'manage_stock',\n        'backorders',\n",
    ),
    dict(
        name='the transfer demands a tick for every key again',
        file=TRANSFER,
        expect=MIR_DB + ':1',
        old="        $unacknowledged = array_values(array_diff(PreservationRisk::blockingIn($risks), $acknowledged));\n",
        new="        $unacknowledged = array_values(array_diff(array_keys($risks), $acknowledged));\n",
    ),
    dict(
        name='the disclosures are dropped from the transfer audit row',
        file=TRANSFER,
        expect=RK_ARCH + ':1',
        old="PreservationRisk::disclosuresIn($risks)",
        new="[]",
    ),
    # ------------------------------------------- §4 the whole-shop tally
    dict(
        name='the tally stops after the first page, so 184 reads as 50',
        file=TALLY,
        expect=MIR_DB + ':1',
        old="            if ((bool) $page['finished']) {\n",
        new="            if (true) {\n",
    ),
    dict(
        name='the tally groups on the bucket alone, so the stop reasons are lost',
        file=TALLY,
        expect=MIR_DB + ':1',
        old='                $key = $bucket . "\\0" . $why;\n',
        new='                $key = $bucket;\n',
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha92-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    index = int(sys.argv[2]) - 1
    if index < 0 or index >= len(MUTATIONS):
        print('no such mutation', file=sys.stderr)
        return 2
    m = MUTATIONS[index]
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
        print('unknown verb', file=sys.stderr)
        return 2
    with io.open(m['file'], encoding='utf-8') as handle:
        body = handle.read()
    found = body.count(m['old'])
    if found != 1:
        # ⛔ A mutation that does not apply — or applies twice — measured
        # NOTHING, and that is a failure rather than an unbreakable guard
        # (`alpha.77`).
        print('mutation %d matched %d times in %s' % (index + 1, found, m['file']), file=sys.stderr)
        return 1
    with io.open(m['file'], 'w', encoding='utf-8') as handle:
        handle.write(body.replace(m['old'], m['new'], 1))
    return 0


if __name__ == '__main__':
    sys.exit(main())
