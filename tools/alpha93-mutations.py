#!/usr/bin/env python3
"""The mutations `alpha93-falsification.sh` applies, one at a time.

This round has ONE change with two halves: a blocking preservation risk may
be released when the row's `mirrored_status` stamp makes the projection's
write unreachable (§3 of the owner's instruction), and the release must be
exactly one key wide and exactly one direction deep.

Every mutation below puts one half of that defect back — either by blocking a
row the projector cannot touch, or by releasing one it can.

A mutation names `suite:filter`. «The mutation was applied and nothing
failed» is itself a failure rather than a reassuring silence (`alpha.77`), and
the data lives here rather than in the shell script because every snippet is
full of `$variable` and single quotes.

⛔ TWO MUTATIONS ARE EXPECTED IN AN ARCHITECTURE ASSERTION, and that is
written down per mutation: the real `WooCommerceProjector` is unreachable
from the database suite, so the fact that its visibility write sits INSIDE
`if (!$statusHeld)` — the whole measurement the release rests on — can only
be asserted from source (`alpha.77`).
"""
import io
import sys

RISK = 'src/Modules/Migration/Domain/PreservationRisk.php'
TRANSFER = 'src/Modules/Migration/Application/TransferOwnership.php'
PLAN = 'src/Modules/Migration/Application/OwnershipTransferPlan.php'
PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php'

RK_ARCH = 'architecture:PreservationRiskClassificationTest'
MIR_DB = 'database:MirroredStatusAndSnapshotBatchTest'

MUTATIONS = [
    # --------------------------------- the released list, and the predicate
    dict(
        name='no key is ever released, so a stamped row blocks on a write that cannot happen',
        file=RISK,
        # The behaviour, not the list: four tests in the DB suite stop being
        # able to transfer shop 102's shape (`alpha.90` — name the suite that
        # actually covers the guard).
        expect=MIR_DB + ':4',
        old="""    public const HELD_WITH_STATUS = [
        'catalog_visibility',
    ];""",
        new="""    public const HELD_WITH_STATUS = [
    ];""",
    ),
    dict(
        name='the stamp is inverted, so held rows block and unstamped rows are released',
        file=RISK,
        # BOTH directions at once, which is why this is the strongest of the
        # three on this file: the control test fails beside the four.
        expect=MIR_DB + ':5',
        old="        if ($statusHeld && in_array($key, self::HELD_WITH_STATUS, true)) {",
        new="        if (!$statusHeld && in_array($key, self::HELD_WITH_STATUS, true)) {",
    ),
    dict(
        name='manage_stock is released by the stamp, though its setter is unconditional',
        file=RISK,
        expect=MIR_DB + ':1',
        old="""    public const HELD_WITH_STATUS = [
        'catalog_visibility',
    ];""",
        new="""    public const HELD_WITH_STATUS = [
        'catalog_visibility',
        'manage_stock',
    ];""",
    ),
    dict(
        name='a released key is dropped from the report instead of disclosed',
        file=RISK,
        expect=MIR_DB + ':2',
        old="""        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => !self::isBlockingGiven($key, $statusHeld)
        ));""",
        new="""        return self::disclosuresIn($risks);""",
    ),
    # ------------------------------- the two callers, which must not diverge
    dict(
        name='the planner goes stamp-blind, so it never offers what the guard would accept',
        file=PLAN,
        expect=MIR_DB + ':1',
        old="        $blocking = PreservationRisk::blockingGiven($risks, $statusHeld);",
        new="        $blocking = PreservationRisk::blockingIn($risks);",
    ),
    dict(
        name='the guard goes stamp-blind, so it refuses the rows the planner offered',
        file=TRANSFER,
        # Caught in BOTH suites — the architecture test pins the exact call
        # text — and named on the behaviour one on purpose (`alpha.90`).
        expect=MIR_DB + ':3',
        old="""        $unacknowledged = array_values(array_diff(
            PreservationRisk::blockingGiven($risks, $statusHeld),
            $acknowledged
        ));""",
        new="""        $unacknowledged = array_values(array_diff(
            PreservationRisk::blockingIn($risks),
            $acknowledged
        ));""",
    ),
    dict(
        name='the guard treats every row as held, so an unstamped row is released too',
        file=TRANSFER,
        expect=MIR_DB + ':1',
        old="        $statusHeld = $status !== null && $status['verdict'] === ProjectedStatus::HELD;",
        new="        $statusHeld = true;",
    ),
    # ------------------------- THE MEASUREMENT the whole release rests on
    dict(
        name='the visibility write leaves the stamp guard, so a held row could lose it after all',
        file=PROJECTOR,
        # ⛔ ARCHITECTURE, and it has to be: the real projector is never
        # constructed by the database suite, so only the source assertion can
        # see this move. It is the reason the release is sound, so if this
        # line ever moves the release must be withdrawn — not the test
        # changed.
        expect=RK_ARCH + ':1',
        old="""        if (!$statusHeld) {
            $wcProduct->set_status(ProjectedStatus::statusOf($product->status));
            $wcProduct->set_catalog_visibility(ProjectedStatus::visibilityOf($product->status));
        }""",
        new="""        if (!$statusHeld) {
            $wcProduct->set_status(ProjectedStatus::statusOf($product->status));
        }
        $wcProduct->set_catalog_visibility(ProjectedStatus::visibilityOf($product->status));""",
    ),
]


def main() -> int:
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    if len(sys.argv) < 2:
        print('usage: alpha93-mutations.py count|name <n>|file <n>|expect <n>|apply <n>')
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    try:
        index = int(sys.argv[2]) - 1
        mutation = MUTATIONS[index]
    except (IndexError, ValueError):
        print('no such mutation', file=sys.stderr)
        return 2
    if verb in ('name', 'file', 'expect'):
        print(mutation[verb])
        return 0
    if verb != 'apply':
        print('unknown verb', file=sys.stderr)
        return 2

    path = mutation['file']
    with open(path, encoding='utf-8') as handle:
        source = handle.read()
    occurrences = source.count(mutation['old'])
    if occurrences != 1:
        # ⛔ Not «unbreakable»: unmeasured. A snippet that matches twice, or
        # not at all, is a mutation nobody applied (`alpha.77`).
        print(
            'mutation %d matched %d times in %s — expected exactly 1'
            % (index + 1, occurrences, path),
            file=sys.stderr,
        )
        return 1
    with open(path, 'w', encoding='utf-8') as handle:
        handle.write(source.replace(mutation['old'], mutation['new'], 1))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
