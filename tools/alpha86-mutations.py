#!/usr/bin/env python3
"""The mutations `alpha86-falsification.sh` applies, one at a time.

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

SHOPS = 'src/Modules/Vendor/Presentation/Admin/VendorShopsPage.php'
SERVICE = 'src/Modules/Vendor/Application/DecideImportedShopSelling.php'
REPO = 'src/Modules/Vendor/Infrastructure/DbVendorRepository.php'
CAPS = 'src/Core/Lifecycle/Capabilities.php'

MUTATIONS = [
    # ---- §1 the list ------------------------------------------------------
    dict(
        name='ask the applications table again, as alpha.85 did',
        file=SHOPS,
        expect='database:ImportedShopSellingTest:1',
        old="""            $rows = $vendors->shopRows(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $total = $vendors->countShops();""",
        new="""            $rows = [];
            foreach ($vendors->approvedVendorUserIds(self::PER_PAGE, ($page - 1) * self::PER_PAGE) as $id) {
                $profile = $vendors->findProfileByUser((int) $id);
                $rows[] = [
                    'user_id' => (int) $id,
                    'store_name' => $profile?->storeName ?? '',
                    'can_sell' => $profile?->canSell === true,
                    'imported' => false,
                ];
            }
            $total = $vendors->countApprovedVendors();""",
    ),
    dict(
        name='read the shop list one row at a time again',
        file=REPO,
        expect='database:ImportedShopSellingTest:1',
        old="""                'SELECT user_id, store_name, can_sell, import_run_id FROM `' . $this->profiles() . '`
                  ORDER BY user_id ASC LIMIT %d OFFSET %d',""",
        new="""                'SELECT user_id FROM `' . $this->profiles() . '`
                  ORDER BY user_id ASC LIMIT %d OFFSET %d',""",
    ),
    dict(
        name='stop deriving origin from the run stamp',
        file=REPO,
        expect='database:ImportedShopSellingTest:1',
        old="""                'imported' => trim((string) $row['import_run_id']) !== '',
            ],
            $this->db->getResults(
                'SELECT user_id, store_name, can_sell, import_run_id FROM `' . $this->profiles() . '`
                  ORDER BY user_id ASC LIMIT %d OFFSET %d',""",
        new="""                'imported' => false,
            ],
            $this->db->getResults(
                'SELECT user_id, store_name, can_sell, import_run_id FROM `' . $this->profiles() . '`
                  ORDER BY user_id ASC LIMIT %d OFFSET %d',""",
    ),
    dict(
        name='search the shop-settings table again, where an imported shop has no row',
        file=REPO,
        expect='database:ImportedShopSellingTest:1',
        old="""                'SELECT user_id, store_name, can_sell, import_run_id FROM `' . $this->profiles() . '`
                  WHERE store_name LIKE %s ORDER BY user_id ASC LIMIT %d',""",
        new="""                'SELECT p.user_id, p.store_name, p.can_sell, p.import_run_id FROM `' . $this->profiles() . '` p
             INNER JOIN `' . $this->stores() . '` s ON s.user_id = p.user_id
                  WHERE p.store_name LIKE %s ORDER BY p.user_id ASC LIMIT %d',""",
    ),

    # ---- §2/§3 the decision ----------------------------------------------
    dict(
        name='drop the «this shop was never imported» refusal',
        file=SERVICE,
        expect='database:ImportedShopSellingTest:1',
        old="""        if ($this->vendors->importRunIdFor($vendorUserId) === '') {
            return ['eligible' => false, 'reason' => 'not_an_imported_shop', 'can_sell' => $canSell];
        }""",
        new="",
    ),
    dict(
        name='let this route decide a shop that has an application too',
        file=SERVICE,
        expect='database:ImportedShopSellingTest:1',
        old="""        if ($this->vendors->findApplicationByUser($vendorUserId) !== null) {
            return ['eligible' => false, 'reason' => 'application_exists', 'can_sell' => $canSell];
        }""",
        new="",
    ),
    dict(
        name='grant selling to a shop with no WordPress account',
        file=SERVICE,
        expect='database:ImportedShopSellingTest:1',
        old="""        if (!$this->users->exists($vendorUserId)) {
            return ['eligible' => false, 'reason' => 'no_user_account', 'can_sell' => $canSell];
        }""",
        new="",
    ),
    dict(
        name='write and audit again when there is nothing to do',
        file=SERVICE,
        expect='database:ImportedShopSellingTest:1',
        old="""        if ($gate['can_sell'] === $canSell) {""",
        new="""        if (false) {""",
    ),
    dict(
        name='take the expected value out of the statement',
        file=REPO,
        expect='database:ImportedShopSellingTest:1',
        old="""            'UPDATE `' . $this->profiles() . '` SET can_sell = %d, updated_at = %s
              WHERE user_id = %d AND can_sell = %d',
            [$canSell ? 1 : 0, $this->clock->now()->format('Y-m-d H:i:s'), $userId, $expectedCurrent ? 1 : 0]""",
        new="""            'UPDATE `' . $this->profiles() . '` SET can_sell = %d, updated_at = %s
              WHERE user_id = %d',
            [$canSell ? 1 : 0, $this->clock->now()->format('Y-m-d H:i:s'), $userId]""",
    ),
    dict(
        name='audit the decision before knowing the write landed',
        file=SERVICE,
        expect='architecture::1',
        old="""        $changed = $this->vendors->setSellingPermission($vendorUserId, $canSell, !$canSell);""",
        new="""        $this->audit->log(
            AuditEventCatalog::VENDOR_SELLING_DECIDED,
            $this->capabilities->currentUserId() ?? 0,
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'actor_id' => $this->capabilities->currentUserId() ?? 0,
                'decision' => $canSell ? self::GRANT : self::WITHDRAW,
                'origin' => 'imported',
            ]
        );
        $changed = $this->vendors->setSellingPermission($vendorUserId, $canSell, !$canSell);""",
    ),
    dict(
        name='write the whole profile instead of the one column',
        file=SERVICE,
        expect='architecture::1',
        old="""        $changed = $this->vendors->setSellingPermission($vendorUserId, $canSell, !$canSell);
        if ($changed === null) {""",
        new="""        $changed = $this->vendors->upsertProfile($vendorUserId, '', $canSell, false) > 0 ? 1 : null;
        if ($changed === null) {""",
    ),
    dict(
        name='reuse a different capability for the decision',
        file=SERVICE,
        expect='architecture::1',
        old="""        if (!$this->capabilities->can(VendorCapabilities::REVIEW)) {""",
        new="""        if (!$this->capabilities->can(\\Tecteb\\Marketplace\\Core\\Lifecycle\\Capabilities::VIEW_VENDOR_SHOPS)) {""",
    ),

    # ---- §4 the money split ----------------------------------------------
    dict(
        name='show the figures to anybody who can list shops',
        file=SHOPS,
        expect='database:ImportedShopSellingTest:1',
        old="""        if (current_user_can(self::FINANCE_CAPABILITY)) {""",
        new="""        if (current_user_can(self::CAPABILITY)) {""",
    ),
    dict(
        name='stop creating the finance capability at all',
        file=CAPS,
        expect='unit::1',
        old="""            self::VIEW_VENDOR_FINANCE,
""",
        new="",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha86-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
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
