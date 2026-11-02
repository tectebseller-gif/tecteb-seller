#!/usr/bin/env python3
"""The mutations `alpha90-falsification.sh` applies, one at a time.

This round has ONE defect and it is a pair of writes that was not a unit of
work, so every mutation below puts some part of that pair back — including
the two guards in the test double without which the broken state could not be
expressed at all (`alpha.59`).

A mutation names `suite:filter:count`. «The mutation was applied and nothing
failed» is itself a failure rather than a reassuring silence (`alpha.77`), and
the data lives here rather than in the shell script because every snippet is
full of `$variable` and single quotes.
"""
import io
import sys

MOVE = 'src/Modules/Migration/Application/TransferOwnership.php'
DOUBLE = 'tests/Support/FakeCatalogProjector.php'
PAGE = 'src/Modules/Migration/Presentation/Admin/MigrationPage.php'

DB = 'database:OwnershipTransferUnitOfWorkTest'

MUTATIONS = [
    dict(
        name='put the unguarded pair back (no compensation at all)',
        file=MOVE,
        expect=DB + ':1',
        old="            $restored = !$postMoved || (\n",
        new="            $restored = true || (\n",
    ),
    dict(
        name='compensate, but always report «nothing changed»',
        file=MOVE,
        expect=DB + ':1',
        old="                $restored ? 'storage_failed' : 'ownership_stamp_unrestored',\n",
        new="                'storage_failed',\n",
    ),
    dict(
        name='compensate in the wrong direction',
        file=MOVE,
        expect=DB + ':1',
        old="""                $to === LinkOwnership::Marketplace
                    ? $this->catalog->releaseStorefrontPost($product, $wcProductId)
                    : $this->catalog->claimStorefrontPost($product, $wcProductId)
""",
        new="""                $to === LinkOwnership::Marketplace
                    ? $this->catalog->claimStorefrontPost($product, $wcProductId)
                    : $this->catalog->releaseStorefrontPost($product, $wcProductId)
""",
    ),
    dict(
        name='leave the disagreement unrecorded (flash only)',
        file=MOVE,
        expect=DB + ':1',
        old="            if (!$restored) {\n",
        new="            if (false) {\n",
    ),
    dict(
        name='compensate a row that never had a stamp to put back',
        file=MOVE,
        expect=DB + ':1',
        old="            $restored = !$postMoved || (\n",
        new="            $restored = (\n",
    ),
    dict(
        name='let the double answer true to every release (alpha.59)',
        file=DOUBLE,
        expect=DB + ':1',
        old="        if ($this->refuseRelease || !$this->owns($wcProductId, $product->id)) {\n            return false;\n        }\n        unset($this->links[$product->id]);\n        return !$this->owns($wcProductId, $product->id);\n",
        new="        unset($this->links[$product->id]);\n        return true;\n",
    ),
    dict(
        name='stop naming the half-written outcome on the page',
        file=PAGE,
        expect='architecture:OwnershipTransferIsOneUnitOfWorkTest:1',
        old="                if ($result->code === 'ownership_stamp_unrestored') {\n",
        new="                if (false) {\n",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha90-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
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
