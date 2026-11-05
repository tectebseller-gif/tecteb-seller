#!/usr/bin/env python3
"""The mutations `alpha94-falsification.sh` applies, one at a time.

This round removes the two UNCONDITIONAL writes `project()` still had — the
stock-management flag and the clearing of a sale price — and releases one more
preservation-risk key on the fact that makes its write impossible. So every
mutation below puts one of those writes back, or releases a key the write can
still reach.

A mutation names `suite:filter`. «The mutation was applied and nothing failed»
is itself a failure rather than a reassuring silence (`alpha.77`), and the data
lives here rather than in the shell script because every snippet is full of
`$variable` and single quotes.

⛔ WHICH SUITE SEES WHAT, and it is written down per mutation. The real
`WooCommerceProjector` IS reachable from the contract suite (it runs against
the WooCommerce stub), so this round's projector mutations are expected there —
behaviour, not source. Three are expected in the architecture suite
instead, and each says why in its own entry: two because no behaviour in this
repository can reach the code they move (the stub has no
`WC_Product_Variation`, and the sale guard's two readings cannot differ
through `project()`), which is what source assertions are for (`alpha.77`).
"""
import io
import sys

RISK = 'src/Modules/Migration/Domain/PreservationRisk.php'
PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php'
BUCKET = 'src/Modules/Migration/Domain/CatalogueBucket.php'

RK_UNIT = 'unit:PreservationRiskTest'
RK_ARCH = 'architecture:PreservationRiskClassificationTest'
FACTS = 'contract:ProjectedFactsTest'
MIR_DB = 'database:MirroredStatusAndSnapshotBatchTest'
INTAKE_DB = 'database:CatalogueIntakeTest'

MUTATIONS = [
    # ------------------------------------------- §3-الف the stock-flag write
    dict(
        name='the stock-management write leaves the $isNew arm, so a transfer switches it on again',
        file=PROJECTOR,
        # ⛔ Behaviour FIRST: the stub makes this visible, so this is not an
        # argument about source. One test measures the write, one control
        # measures that creating a product still turns it on.
        expect=FACTS + ':1',
        old="""            if ($isNew) {
                // Only here: afterwards WooCommerce's numbers are the true
                // ones, the flag included.
                $wcProduct->set_manage_stock(true);""",
        new="""            $wcProduct->set_manage_stock(true);
            if ($isNew) {
                // Only here: afterwards WooCommerce's numbers are the true
                // ones, the flag included.""",
    ),
    dict(
        name='the whole stock block loses its guard, so the quantity is written over the shop too',
        file=PROJECTOR,
        expect=FACTS + ':1',
        old="            if ($isNew) {\n                // Only here: afterwards WooCommerce's numbers are the true\n                // ones, the flag included.\n                $wcProduct->set_manage_stock(true);\n                $wcProduct->set_stock_quantity($details->stock);",
        new="            if (true) {\n                // Only here: afterwards WooCommerce's numbers are the true\n                // ones, the flag included.\n                $wcProduct->set_manage_stock(true);\n                $wcProduct->set_stock_quantity($details->stock);",
    ),
    dict(
        name='the variation loop writes the stock flag unconditionally again',
        file=PROJECTOR,
        # ⛔ ARCHITECTURE, and it has to be: the contract suite's WooCommerce
        # stub has no `WC_Product_Variation` at all, so no behaviour in this
        # repository can reach `projectVariations()`. The automated review of
        # this round is what found the write still standing here, and a source
        # assertion is the only instrument that can hold it (`alpha.77`).
        expect=RK_ARCH + ':1',
        old="""            if ($isNew) {
                $wcVariation->set_manage_stock(true);""",
        new="""            $wcVariation->set_manage_stock(true);
            if ($isNew) {""",
    ),
    # ------------------------------------------- §3-ب the sale-price clearing
    dict(
        name='the sale-price guard goes, so a projection clears a sale nobody recorded',
        file=PROJECTOR,
        expect=FACTS + ':1',
        old="""            if (!$oursIsFresh && (string) $wcProduct->get_sale_price() !== '') {
                return;
            }""",
        new="",
    ),
    dict(
        name='the projection calls itself fresh, so the clearing runs after all',
        file=PROJECTOR,
        # Caught in BOTH — the architecture test pins each caller's exact
        # text — and named on the behavioural one on purpose (`alpha.90`).
        expect=FACTS + ':1',
        old="                    $this->applySale($wcProduct, $product, false);",
        new="                    $this->applySale($wcProduct, $product, true);",
    ),
    dict(
        name='the vendors own price change stops clearing their sale, which takes a right away',
        file=PROJECTOR,
        # The OTHER direction, and the reason the fix is a split rather than a
        # ban: `alpha.83`'s «این اختیار نباید محدود شود».
        expect=FACTS + ':1',
        old="        $this->applySale($wcProduct, $product, true);",
        new="        $this->applySale($wcProduct, $product, false);",
    ),
    dict(
        name='the guard reads the regular price, so it never sees the sale it is protecting',
        file=PROJECTOR,
        # ⛔ ARCHITECTURE, and the loop is what established that it has to be.
        # This mutation SURVIVED every behavioural suite on its first run, and
        # the reason is measured rather than shrugged at: through `project()`
        # the two readings cannot differ. The clearing branch is only reached
        # when `hasPrice()` is true, so the shop's regular price is non-empty
        # whenever this `if` runs — the mutant returns for the wrong reason
        # and keeps the sale price anyway. The one shape that would tell them
        # apart, a product with a sale price and no regular price, never gets
        # here: `$priceHeld` is set and the whole block is skipped.
        #
        # So the field this guard reads is a source fact (`alpha.77`), and
        # `testTheSalePriceIsClearedOnlyWhenOurValueIsFresh` now asserts it
        # with its own reason beside it.
        expect=RK_ARCH + ':1',
        old="            if (!$oursIsFresh && (string) $wcProduct->get_sale_price() !== '') {",
        new="            if (!$oursIsFresh && (string) $wcProduct->get_regular_price() !== '') {",
    ),
    # ------------------------------------ the released list, and its predicate
    dict(
        name='manage_stock is never released, so the 84 rows stay blocked and unrefreshable',
        file=RISK,
        expect=MIR_DB + ':3',
        old="""    public const HELD_BY_EXISTING_PRODUCT = [
        'manage_stock',
    ];""",
        new="""    public const HELD_BY_EXISTING_PRODUCT = [
    ];""",
    ),
    dict(
        name='price_scale is released too, so a sale price lost on the next edit stops blocking',
        file=RISK,
        expect=RK_UNIT + ':2',
        old="""    public const HELD_BY_EXISTING_PRODUCT = [
        'manage_stock',
    ];""",
        new="""    public const HELD_BY_EXISTING_PRODUCT = [
        'manage_stock',
        'price_scale',
    ];""",
    ),
    dict(
        name='an unreadable answer reads as readable, so a failed read becomes a licence',
        file=RISK,
        expect=RK_UNIT + ':2',
        old="        return $risks !== [] && !array_key_exists(self::UNREADABLE_KEY, $risks);",
        new="        return true;",
    ),
    dict(
        name='the readability fact is inverted, so the release lands on exactly the wrong rows',
        file=RISK,
        expect=RK_UNIT + ':4',
        old="        return $risks !== [] && !array_key_exists(self::UNREADABLE_KEY, $risks);",
        new="        return $risks !== [] && array_key_exists(self::UNREADABLE_KEY, $risks);",
    ),
    dict(
        name='the context defaults to released, so a caller that cannot tell gets a licence',
        file=RISK,
        expect=RK_UNIT + ':1',
        old="        bool $storefrontReadable = false\n    ): bool {",
        new="        bool $storefrontReadable = true\n    ): bool {",
    ),
    dict(
        name='the split stops asking about readability, so blockingGiven answers the old way',
        file=RISK,
        expect=MIR_DB + ':3',
        old="""        $readable = self::storefrontReadable($risks);
        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => self::isBlockingGiven($key, $statusHeld, $readable)
        ));""",
        new="""        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => self::isBlockingGiven($key, $statusHeld)
        ));""",
    ),
    dict(
        name='a released key is dropped from the report instead of disclosed',
        file=RISK,
        expect=MIR_DB + ':4',
        old="""        $readable = self::storefrontReadable($risks);
        return array_values(array_filter(
            array_keys($risks),
            static fn (string $key): bool => !self::isBlockingGiven($key, $statusHeld, $readable)
        ));""",
        new="""        return self::disclosuresIn($risks);""",
    ),
    # ------------------- §1 why no `type` risk can be among the 184 at all
    dict(
        name='an unsupported type stops being a reason, so a grouped product is taken in as simple',
        file=BUCKET,
        expect=INTAKE_DB + ':1',
        old="""        if (!$typeSupported) {
            $reasons[] = 'type_not_supported';
        }""",
        new="""        if (false) {
            $reasons[] = 'type_not_supported';
        }""",
    ),
]


def main() -> int:
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    if len(sys.argv) < 2:
        print('usage: alpha94-mutations.py count|name <n>|file <n>|expect <n>|apply <n>')
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    try:
        index = int(sys.argv[2]) - 1
        if index < 0:
            raise IndexError
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
