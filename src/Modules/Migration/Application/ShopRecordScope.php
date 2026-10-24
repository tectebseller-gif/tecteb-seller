<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * The one list of shops the record preview and the record import both work on.
 *
 * ### Why this class exists
 *
 * Measured on staging, on `alpha.77`: before the import the preview listed
 * two shops — vendor 40 and vendor 102 — and said one unimported balance row
 * belonged to 102. The run then wrote **fourteen** rows, owned by 2 (three),
 * 4 (six), 6 (one), 8 (one), 16 (two) and 102 (one), and the owners of those
 * records appeared on the page only AFTERWARDS.
 *
 * Neither half was lying. They were answering different questions:
 *
 *  - the preview asked `MigrationCompleteness::vendorUserIds()` — every shop
 *    with an IMPORT TRACE (a history row, a product stamp, a profile stamp);
 *  - the import walks `vendorsAfter()`, `balanceRowsAfter()` and
 *    `withdrawalsAfter()` — Dokan's own tables, which hold **every** shop at
 *    once and know nothing about whether we ever imported one.
 *
 * So a Dokan seller that was never imported here was outside the preview and
 * inside the walk, and the first time anybody saw it was after its records
 * had been written. «هیچ صاحب سابقه‌ای نباید تازه بعد از ورود آشکار شود» is
 * not a display bug: it is two scopes, and the fix is one scope that both
 * read.
 *
 * ### What the scope is, exactly
 *
 * Every vendor id the import **can touch**, plus every shop the page is
 * already about:
 *
 *   sellers      every user carrying Dokan's `seller` role — the staff walk
 *   balance      every owner named in `dokan_vendor_balance`
 *   withdrawals  every owner named in `dokan_withdraw`
 *   imported     every shop with any import trace
 *
 * Union, not intersection, and in that direction on purpose: an id left out
 * of this list is an id whose records can arrive unannounced, which is the
 * defect. An id in the list that turns out to have nothing is a row saying
 * «صفر سابقه», which is an answer.
 *
 * ⛔ **It is deliberately NOT narrowed to the imported shops.** Narrowing the
 * WALK instead would have been the other way to make the two agree, and it
 * would change which records this marketplace keeps — the records already
 * written for ids 2, 4, 6, 8 and 16 would become rows nothing on any page
 * admits to, and the owner's instruction is that existing records are neither
 * deleted nor rewritten. So the scope widens to what the import does, and the
 * page says which ids are shops and which are only owners of records.
 *
 * ### Having records is not being a shop
 *
 * `origin` separates the two, because the whole reason those six ids were a
 * surprise is that nothing distinguished them:
 *
 *   `imported`     this shop has an import trace here
 *   `source_only`  Dokan names it; this marketplace has not imported it
 *
 * and `has_user` / `has_profile` / `can_sell` say what is actually behind the
 * id. An id with records, no WordPress user and no vendor profile is a
 * historical owner and nothing more — it is listed so its records are not a
 * surprise, and it is labelled so it is never read as a shop ready to be
 * admitted. Admission is `ReviewApplication`'s and this class writes nothing.
 *
 * There is no write method here of any kind, which is how «فقط‌خواندنی» is
 * enforced rather than promised — the same shape as `ShopRecordPreview`.
 */
final class ShopRecordScope
{
    /** This shop has an import trace in this marketplace. */
    public const IMPORTED = 'imported';

    /** Dokan names this id; nothing here has imported it. */
    public const SOURCE_ONLY = 'source_only';

    public function __construct(
        private readonly MigrationCompleteness $completeness,
        private readonly DokanReaderInterface $dokan,
        private readonly VendorRepositoryInterface $vendors,
        /**
         * Optional, and the answer is `null` rather than `false` when it is
         * not bound: «there is no user» and «I could not ask» are two facts
         * and the second one must not be printed as the first.
         */
        private readonly ?StaffUserDirectoryInterface $users = null
    ) {
    }

    /**
     * Dokan's side, asked once per instance.
     *
     * Per INSTANCE and not `static`: a `static` memo lasts the whole process
     * and a test that changes what Dokan holds and asks again would get the
     * stale answer — the `alpha.67` rule, measured there on
     * `ProductTableShape`.
     *
     * @var array{ids:list<int>, readable:bool, sources:array<string, array{ids:list<int>, readable:bool, present:bool}>}|null
     */
    private ?array $owners = null;

    /** @return array{ids:list<int>, readable:bool, sources:array<string, array{ids:list<int>, readable:bool, present:bool}>} */
    private function sourceOwners(): array
    {
        return $this->owners ??= $this->dokan->recordOwnerIds();
    }

    /**
     * The scope, as ids, sorted — what both the preview and the import's
     * reporting iterate.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = $this->completeness->vendorUserIds();
        foreach ($this->sourceOwners()['ids'] as $id) {
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * The scope with every id's provenance — the page's own table.
     *
     * @return list<array{vendor_user_id:int, origin:string, imported:bool, in_source:bool, has_user:bool|null, has_profile:bool, can_sell:bool}>
     */
    public function rows(): array
    {
        $imported = array_map('intval', $this->completeness->vendorUserIds());
        $source = array_map('intval', $this->sourceOwners()['ids']);

        $out = [];
        foreach ($this->ids() as $id) {
            $profile = $this->vendors->findProfileByUser($id);
            $out[] = [
                'vendor_user_id' => $id,
                'origin' => in_array($id, $imported, true) ? self::IMPORTED : self::SOURCE_ONLY,
                'imported' => in_array($id, $imported, true),
                'in_source' => in_array($id, $source, true),
                'has_user' => $this->users?->exists($id),
                'has_profile' => $profile !== null,
                // Read, never written here. Admission happens in
                // `ReviewApplication`; this is the column that stops a row
                // with records from looking like a shop that may trade.
                'can_sell' => $profile?->canSell === true,
            ];
        }
        return $out;
    }

    /**
     * Whether Dokan's side of the scope could be enumerated at all.
     *
     * `false` means the list below may be short, and the page says so: a
     * narrowed scope that looks complete is how the two scopes went unnoticed
     * for a round, so «I could not finish looking» is printed rather than
     * folded into the list.
     */
    public function sourceReadable(): bool
    {
        return $this->sourceOwners()['readable'] === true;
    }

    /**
     * The ids Dokan's record sources name but this marketplace never
     * imported — named, so they can never be a surprise again.
     *
     * @return list<int>
     */
    public function sourceOnlyIds(): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            if ($row['origin'] === self::SOURCE_ONLY) {
                $out[] = (int) $row['vendor_user_id'];
            }
        }
        return $out;
    }
}
