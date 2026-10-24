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
 * `origin` separates them, because the whole reason those six ids were a
 * surprise is that nothing distinguished them:
 *
 *   `imported`      this marketplace created something for this shop
 *   `records_only`  we hold its records and nothing else
 *   `source_only`   Dokan names it; we hold nothing of it at all
 *
 * and `has_user` / `has_profile` / `can_sell` say what is actually behind the
 * id. An id with records, no WordPress user and no vendor profile is a
 * historical owner and nothing more — it is listed so its records are not a
 * surprise, and it is labelled so it is never read as a shop ready to be
 * admitted. Admission is `ReviewApplication`'s and this class writes nothing.
 *
 * ⛔ **`records_only` is the label `alpha.78` did not have, and its absence
 * was a defect the owner found in the shipped ZIP.** `IMPORTED` was derived
 * from `MigrationCompleteness::vendorUserIds()`, whose first trace was
 * `vendorsWithRecords()` — so before the run ids 2, 4, 6, 8 and 16 read
 * `source_only`, and the instant the run wrote their records they read
 * `imported` and entered the migration-completeness table. The criterion for
 * being a shop was a row the import itself created. The shop criterion is now
 * `MigrationCompleteness::shopVendorUserIds()`, which no record can satisfy,
 * and holding records gets a name of its own.
 *
 * There is no write method here of any kind, which is how «فقط‌خواندنی» is
 * enforced rather than promised — the same shape as `ShopRecordPreview`. That
 * stays true now that the record import holds this object through
 * `RecordScopeInterface`: what it can ask for is two booleans.
 */
final class ShopRecordScope implements RecordScopeInterface
{
    /** This marketplace created something for this shop: a product or a profile. */
    public const IMPORTED = 'imported';

    /** We hold this id's records, and nothing else here is about it. */
    public const RECORDS_ONLY = 'records_only';

    /** Dokan names this id; we hold nothing of it at all. */
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
     * Our own two lists, memoised per instance for the same reason as Dokan's.
     *
     * ⛔ Memoised because `admits()` is now asked once per UNIT on the import's
     * write path — two hundred times a batch — and each miss would re-read the
     * product stamps, the run list and the three record tables. Per instance
     * and never `static`: a test that writes a stamp and asks again must get
     * the new answer (`alpha.67`).
     *
     * @var list<int>|null
     */
    private ?array $shopIds = null;

    /** @var list<int>|null */
    private ?array $ownerIds = null;

    /** @var list<int>|null */
    private ?array $scopeIds = null;

    /** @return list<int> */
    private function shops(): array
    {
        return $this->shopIds ??= array_map('intval', $this->completeness->shopVendorUserIds());
    }

    /** @return list<int> */
    private function recordOwners(): array
    {
        return $this->ownerIds ??= array_map('intval', $this->completeness->recordOwnerVendorUserIds());
    }

    /**
     * The scope, as ids, sorted — what the preview, the button and the walk
     * all read.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        if ($this->scopeIds !== null) {
            return $this->scopeIds;
        }
        $ids = array_merge($this->shops(), $this->recordOwners());
        foreach ($this->sourceOwners()['ids'] as $id) {
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $this->scopeIds = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * Whether a record may be written for this owner.
     *
     * ⛔ **An unreadable scope admits nobody**, and that is deliberate rather
     * than defensive: a short enumeration is exactly the case where the owners
     * it failed to name are the ones nobody has been shown, so letting it
     * through would permit the one thing this method exists to stop. The
     * handover page refuses to queue the import in that state as well, so this
     * is the second of two defences and not the only one.
     */
    public function admits(int $vendorUserId): bool
    {
        if (!$this->readable()) {
            return false;
        }
        return in_array($vendorUserId, $this->ids(), true);
    }

    /** `RecordScopeInterface`'s name for `sourceReadable()`. */
    public function readable(): bool
    {
        return $this->sourceReadable();
    }

    /**
     * The scope with every id's provenance — the page's own table.
     *
     * @return list<array{vendor_user_id:int, origin:string, imported:bool, has_records:bool, in_source:bool, has_user:bool|null, has_profile:bool, can_sell:bool}>
     */
    public function rows(): array
    {
        $shops = $this->shops();
        $owners = $this->recordOwners();
        $source = array_map('intval', $this->sourceOwners()['ids']);

        $out = [];
        foreach ($this->ids() as $id) {
            $profile = $this->vendors->findProfileByUser($id);
            // THREE answers, and the order is the point: a marketplace trace
            // makes it a shop, records alone never do, and neither does being
            // named by Dokan.
            $origin = match (true) {
                in_array($id, $shops, true) => self::IMPORTED,
                in_array($id, $owners, true) => self::RECORDS_ONLY,
                default => self::SOURCE_ONLY,
            };
            $out[] = [
                'vendor_user_id' => $id,
                'origin' => $origin,
                'imported' => $origin === self::IMPORTED,
                'has_records' => in_array($id, $owners, true),
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
     * The ids Dokan's record sources name and we hold nothing of — named, so
     * they can never be a surprise again.
     *
     * @return list<int>
     */
    public function sourceOnlyIds(): array
    {
        return $this->withOrigin(self::SOURCE_ONLY);
    }

    /**
     * The ids whose records we hold and which are not shops here.
     *
     * These are the ones `alpha.78` turned into shops by importing them. They
     * keep their records, they keep their row on the page, and they stay out
     * of the completeness table.
     *
     * @return list<int>
     */
    public function recordOnlyIds(): array
    {
        return $this->withOrigin(self::RECORDS_ONLY);
    }

    /**
     * Everything in scope that is not a shop of this marketplace.
     *
     * @return list<int>
     */
    public function nonShopIds(): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            if ($row['origin'] !== self::IMPORTED) {
                $out[] = (int) $row['vendor_user_id'];
            }
        }
        return $out;
    }

    /** @return list<int> */
    private function withOrigin(string $origin): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            if ($row['origin'] === $origin) {
                $out[] = (int) $row['vendor_user_id'];
            }
        }
        return $out;
    }
}
