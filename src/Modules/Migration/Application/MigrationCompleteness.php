<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * Whether a shop has actually been migrated — as opposed to filed.
 *
 * The rule this class exists for is one sentence from the owner: «ثبت آرشیوی
 * به‌تنهایی مهاجرت کامل اعلام نشود». Importing a shop writes rows into five
 * history tables and produces a very convincing-looking report: staff found,
 * balance rows found, orders found, products found. None of it means the shop
 * can trade here. A reader who sees those counts and concludes «done» has been
 * misled by a true statement, which is the worst kind.
 *
 * So the archive is **gate zero**: necessary, and worth exactly nothing on its
 * own. Above it sit five gates that are each an act somebody performed, and
 * the verdict is `complete` only when every one of them is true. Anything else
 * is `incomplete` and names what is missing — a list, not an investigation.
 *
 *   records_imported   the archive has nothing left to bring over, and
 *                      a verified zero counts as nothing left
 *   categories_mapped  no product is sitting as a draft for want of a category
 *   ownership_taken    the rows are the marketplace's to run, not notes —
 *                      or there are no rows at all on a shop that was
 *                      imported, which is finished too (`OwnershipState`)
 *   vendor_can_sell    admission happened, through review, not through import
 *   staff_decided      every imported Dokan role has an answer, yes or no
 *   finance_decided    somebody said who owes the imported balance
 *
 * Note what is NOT a gate: how much was imported. A shop with one product and
 * a shop with nine hundred pass and fail identically, because the question is
 * whether the decisions were taken, and a decision is not more taken for
 * having more rows under it.
 *
 * And note what decides WHICH shops are asked at all: every trace an import
 * left ON THIS SIDE — a product row or a vendor profile stamped with a run —
 * and not a copy of Dokan's past. See `all()`: the list is the part that was
 * wrong twice. First it was too narrow, so a shop imported with nothing but a
 * catalogue was not judged lenient, it was not judged at all; then it was too
 * wide, and an id that owns nothing but a balance row we copied walked into
 * the table that decides whether a shop may be admitted.
 */
final class MigrationCompleteness
{
    public const COMPLETE = 'complete';
    public const INCOMPLETE = 'incomplete';
    /** The archive is there, and nothing above it has been done. The trap. */
    public const RECORDED_ONLY = 'recorded_only';

    public const GATES = [
        'records_imported',
        'categories_mapped',
        'ownership_taken',
        'vendor_can_sell',
        'staff_decided',
        'finance_decided',
    ];

    public function __construct(
        private readonly ShopRecordRepositoryInterface $records,
        private readonly StaffRoleMap $roles,
        private readonly ReconcileDokanFinance $finance,
        private readonly VendorRepositoryInterface $vendors,
        private readonly ProductRepositoryInterface $products,
        private readonly ?DokanReaderInterface $dokan = null,
        private readonly ?CategoryMap $categories = null,
        /**
         * The record verdicts, so the archive gate can tell a VERIFIED ZERO
         * from an import that never happened. Optional, and without it the
         * gate keeps the old count — a missing dependency must not be allowed
         * to invent a verdict about somebody's shop.
         */
        private readonly ?ShopRecordPreview $recordsPreview = null,
        /**
         * The manager's explicit confirmation that a shop's records came over
         * — read ONLY where Dokan cannot be asked. Optional, and its absence
         * means this build behaves exactly as `alpha.90` shipped: with Dokan
         * gone the gate stays open. See `withoutDokan()`.
         */
        private readonly ?ShopRecordConfirmationRepositoryInterface $confirmations = null
    ) {
    }

    /** Every kind read, every kind as complete here as it can be. */
    public const RECORDS_SETTLED = 'settled';

    /** At least one kind has rows in Dokan that we do not hold. */
    public const RECORDS_OUTSTANDING = 'outstanding';

    /** At least one read FAILED. Never nought, and never «settled». */
    public const RECORDS_UNREADABLE = 'unreadable';

    /**
     * Dokan answered, and the answer is that the source does not exist here.
     *
     * Dokan Lite has no vendor staff at all, so its table is simply not on the
     * install. Nothing can ever write such a row, so nothing is outstanding —
     * and this is a PRESENT fact read from `information_schema`, not an
     * inference about a past. It is kept apart from `settled` because
     * «شمردیم و کامل است» and «منبعی وجود ندارد» are two different sentences
     * and the page says which one it is.
     */
    public const RECORDS_NO_SOURCE = 'no_source';

    /**
     * Dokan is not running, so the question cannot be asked at all.
     *
     * ⛔ **And it is not answered from a count.** Until this round the gate
     * fell back to `(balance + withdrawals + staff) > 0 || products > 0` here,
     * on the reasoning that a shop whose archive landed before Dokan was
     * switched off must not become permanently incomplete. The owner refused
     * that reasoning and is right: holding a product, or some of the records,
     * is not proof that the rest ever arrived. So this state closes nothing.
     * What would close it is the verification itself — a recorded statement
     * that the three kinds were walked to the end while Dokan was readable —
     * and nothing in this build records one, which is said out loud on the
     * page and listed as an open decision rather than guessed at.
     */
    public const RECORDS_DOKAN_ABSENT = 'dokan_absent';

    /** Our own record store is unestablished, or nothing said anything. */
    public const RECORDS_UNVERIFIABLE = 'unverifiable';

    /**
     * Dokan cannot be asked, and a manager confirmed the reconciliation while
     * it still could be — the owner's option ب,
     * `docs/open-decisions-alpha79.md` §1.
     *
     * Kept apart from `RECORDS_SETTLED` because the two are different
     * sentences: «شمردیم و کامل است» is a read of the source, and this is «یک
     * انسان نامش را پای تطبیق گذاشت». Both open the gate; only one of them
     * can name a person and a date, and the page says which.
     */
    public const RECORDS_CONFIRMED = 'confirmed';

    /**
     * A confirmation exists and the imported past has MOVED since.
     *
     * The gate stays open, and that is the whole reason the confirmation
     * stores a version: a re-import, a rollback or an extension bumps
     * `records_version` in its own transaction, so a decision taken against
     * the old number is about an archive that is no longer the one on disk.
     * «تأیید قدیمی نباید بی‌صدا معتبر بماند» is this constant.
     */
    public const RECORDS_CONFIRMATION_STALE = 'confirmation_stale';

    /**
     * Dokan cannot be asked AND the confirmation could not be read either.
     *
     * Not folded into `RECORDS_DOKAN_ABSENT`: both leave the gate open, but
     * one of them means «nobody has confirmed» and the other means «I could
     * not find out», and they send a person to two different places. Two
     * «don't know»s under one label make a guard too wide (`alpha.68`).
     */
    public const RECORDS_CONFIRMATION_UNREADABLE = 'confirmation_unreadable';

    /**
     * What the three record sources add up to for one shop.
     *
     * ⛔ **This is the measurement the `records_imported` gate used to get
     * wrong, twice.** The gate was first `(balance + withdrawals + staff) > 0
     * || products > 0`: a count, and therefore a gate that cannot distinguish
     * «nobody imported the records» from «there are none to import». Measured
     * on staging for vendor 40: all three sources read successfully, all three
     * are empty, nothing is missing and nothing can ever arrive — and the
     * completeness table named «ورود سابقه» as a blocker, with the only way to
     * clear it being to invent a record. A gate whose only exit is a lie is
     * worse than no gate.
     *
     * The second mistake was keeping that count for the one state where the
     * question cannot be asked. «نمی‌دانم» was one word covering three facts —
     * Dokan gone, that source absent, our own store unestablished — and the
     * first of them then answered from rows. Now they are three states, and
     * only the one that is a successful read of «there is no such source»
     * settles anything.
     *
     * Six answers, because each asks something different of a person: fix the
     * read, press the button, nothing to do, there is no source, Dokan is gone
     * and this can no longer be checked, or this install cannot say.
     *
     * @return array{state:string, kinds:array<string,string>, reasons:array<string,string>}
     */
    public function recordsState(int $vendorUserId): array
    {
        if ($this->recordsPreview === null) {
            return ['state' => self::RECORDS_UNVERIFIABLE, 'kinds' => [], 'reasons' => []];
        }
        $row = $this->recordsPreview->forVendor($vendorUserId);
        $verdicts = [];
        $reasons = [];
        foreach ($row['kinds'] as $kind => $cell) {
            $verdicts[(string) $kind] = (string) $cell['verdict'];
            $reasons[(string) $kind] = (string) $cell['reason'];
        }

        $has = static fn (array $wanted): bool => array_intersect($wanted, array_values($verdicts)) !== [];

        // WHY each unchecked kind is unchecked. `ShopRecordPreview` has
        // carried the reason since it was written; what was missing was
        // anybody reading it, so one label stood for three facts.
        $unchecked = [];
        foreach ($verdicts as $kind => $verdict) {
            if ($verdict === ShopRecordPreview::UNCHECKED) {
                $unchecked[] = $reasons[$kind] ?? '';
            }
        }

        // The ladder's order is the order a person should act in, and the
        // first two come before everything else for the same reason
        // `ShopRecordPreview::verdict()` tests unreadability first: a verdict
        // read off a number nobody could check is the collapse both classes
        // exist to refuse.
        $state = match (true) {
            $has([ShopRecordPreview::UNREADABLE]) => self::RECORDS_UNREADABLE,
            $has([ShopRecordPreview::PENDING, ShopRecordPreview::PARTIAL]) => self::RECORDS_OUTSTANDING,
            // ⛔ THE ONE BRANCH THE CONFIRMATION IS READ FROM, and it is
            // this one on purpose. While Dokan answers, the LIVE read is the
            // reference — so a confirmation given over an incomplete archive
            // can never cover a present `outstanding` or `unreadable`, both
            // of which are decided above this line.
            in_array('dokan_not_running', $unchecked, true) => $this->withoutDokan($vendorUserId),
            in_array('record_store_not_installed', $unchecked, true) => self::RECORDS_UNVERIFIABLE,
            $verdicts === [] => self::RECORDS_UNVERIFIABLE,
            // Every kind unchecked, and by now the only reason left is «that
            // source is not on this install». Some kinds absent and the rest
            // read is `settled`: nothing is outstanding anywhere.
            count($unchecked) === count($verdicts) => self::RECORDS_NO_SOURCE,
            default => self::RECORDS_SETTLED,
        };

        return ['state' => $state, 'kinds' => $verdicts, 'reasons' => $reasons];
    }

    /**
     * What closes the record question once Dokan can no longer be asked.
     *
     * Four answers, and every one of them leaves the gate open except the
     * first:
     *
     *  - `RECORDS_CONFIRMED` — a live confirmation, taken against the version
     *    the archive is still on.
     *  - `RECORDS_CONFIRMATION_STALE` — a live confirmation, and the archive
     *    has moved since.
     *  - `RECORDS_CONFIRMATION_UNREADABLE` — the confirmation table could not
     *    be read, which is not «nobody confirmed».
     *  - `RECORDS_DOKAN_ABSENT` — nobody confirmed, or this build has no such
     *    table (schema 36 after a rollback), which is exactly `alpha.90`'s
     *    behaviour.
     *
     * ⛔ **And the stored version 0 is disambiguated rather than trusted.**
     * `recordsVersion()` casts its read, so a failed statement and «no
     * version row» both answer `0` — the `alpha.8` family. A confirmation
     * taken at version 0 is legitimate (a shop with no record writes at all),
     * so 0 against 0 would read as confirmed even where the read had failed
     * over a shop that does hold rows. The held counts settle it: if we hold
     * any row at all, a version of 0 is not a number this shop can be on, and
     * the conservative answer is `stale`.
     */
    private function withoutDokan(int $vendorUserId): string
    {
        if ($this->confirmations === null) {
            return self::RECORDS_DOKAN_ABSENT;
        }
        $supported = $this->confirmations->supported();
        if ($supported === null) {
            return self::RECORDS_CONFIRMATION_UNREADABLE;
        }
        if ($supported === false) {
            return self::RECORDS_DOKAN_ABSENT;
        }
        $live = $this->confirmations->liveFor($vendorUserId);
        if ($live === null) {
            return self::RECORDS_DOKAN_ABSENT;
        }
        $stored = (int) ($live['records_version'] ?? 0);
        $now = $this->records->recordsVersion($vendorUserId);
        if ($stored !== $now) {
            return self::RECORDS_CONFIRMATION_STALE;
        }
        if ($stored === 0) {
            $held = $this->records->importedCountsFor($vendorUserId);
            foreach ($held as $count) {
                if ($count === null || (int) $count > 0) {
                    return self::RECORDS_CONFIRMATION_STALE;
                }
            }
        }
        return self::RECORDS_CONFIRMED;
    }

    /** @return array<string,mixed> */
    public function forVendor(int $vendorUserId): array
    {
        $summary = $this->records->summaryForVendor($vendorUserId);
        $staffRows = $this->records->staffForVendor($vendorUserId);
        $tally = $this->products->ownershipTallyForVendor($vendorUserId);
        $profile = $this->vendors->findProfileByUser($vendorUserId);
        $undecidedRoles = $this->roles->undecided($staffRows);
        $unmappedCategories = $this->unmappedFor($vendorUserId);
        // ⛔ The OTHER half of «نگاشت دسته‌ها», and the half that is readable
        // with nothing else installed: our own imported rows whose
        // `category_key` is still empty. See the gate below.
        $withoutCategory = $this->products->countImportedWithoutCategory($vendorUserId);
        // ⛔ The no-source group is reported but does NOT hold the decision
        // half open. `unmapped()` groups products carrying no source category
        // at all under the empty key — on purpose, so the report can name them
        // — and no mapping can ever empty that group, because there is nothing
        // to map. A gate that waited for it would have one exit: inventing a
        // mapping for «» and letting it be written on every such product. That
        // is the dead end `alpha.60` named, and it is worse than the defect it
        // would be guarding. Those products are counted by the OTHER half
        // instead, which closes when a human picks a category on the product
        // page — a door that exists.
        $mappableUnmapped = array_values(array_filter(
            $unmappedCategories,
            static fn (array $row): bool => trim((string) ($row['key'] ?? '')) !== ''
        ));

        $records = $this->recordsState($vendorUserId);

        // Whether the staff LIST is the whole list. `importedCountsFor()` is
        // already read by `withoutDokan()`, so this costs one indexed COUNT
        // per shop through `tmc_staff_vendor` (migration 17) and nothing else.
        $heldStaff = $this->records->importedCountsFor($vendorUserId)['staff'] ?? null;
        $staffReadable = $heldStaff !== null && (int) $heldStaff === count($staffRows);

        // The import stamp on the PROFILE, which is what makes a measured
        // nought mean «nothing to transfer» rather than «who is this shop».
        // Read through `importRunIdFor()` because it asks with `getRow()`
        // rather than `getVar()`: an empty stamp and an absent profile must
        // not arrive as one answer (`alpha.82`).
        //
        // The PROFILE stamp is the only one that can be asked here, and that
        // is not an omission: a product stamp lives ON a product row, so a
        // shop whose counts are both nought cannot be carrying one. Asking
        // for it would be a method on `ProductRepositoryInterface` that every
        // double would have to grow in order to answer «no» (`alpha.55`).
        $ownershipState = OwnershipState::of(
            $tally,
            $this->vendors->importRunIdFor($vendorUserId) !== ''
        );

        $gates = [
            // ⛔ **No count opens this gate, in any state.** Two things close
            // it and both are successful READS of the source: every kind read
            // and complete, or every kind answering that there is no such
            // source on this install. Everything else — a failed read, rows
            // waiting, Dokan gone, our own store unestablished — leaves it
            // open and named, because «این‌طرف محصولی هست» and «سابقه‌ها کامل
            // آمده‌اند» are not the same statement and the first was standing
            // in for the second.
            'records_imported' => match ($records['state']) {
                // And `RECORDS_CONFIRMED` from `alpha.91`: the owner's option
                // ب. A stale confirmation is NOT here, and neither is
                // `confirmation_unreadable` — both leave this open and named.
                self::RECORDS_SETTLED, self::RECORDS_NO_SOURCE, self::RECORDS_CONFIRMED => true,
                default => false,
            },
            // ⛔ ONE question, and it is about PRODUCTS.
            //
            // `countImportedWithoutCategory()` asks OUR table how many
            // imported rows carry neither a category key nor a recorded
            // WooCommerce set. Saving a mapping moves not one of them — a row
            // only changes when a manager applies the mapping on purpose
            // (`ApplyCategoryMapping`) or records the product's own
            // relationship (`ApplyCategoryLinks`) — so «صرف ذخیرهٔ option و
            // سبز شدن وضعیت کافی نیست» stays true of this gate, which is what
            // `alpha.80` came for. And a failed count arrives as `-1`, which
            // keeps the gate OPEN: a statement that never ran must not read as
            // «nothing left».
            //
            // ⛔ And `$mappableUnmapped` is REPORTED and no longer asked here.
            // Until `alpha.87` it was the other half, and it cannot be
            // satisfied by the thing that actually answers the question:
            // `unmappedFor()` walks DOKAN's live products and groups them by
            // source key, a list that recording a WooCommerce set moves not
            // one row of. So a shop whose every imported product carried its
            // full, correct WooCommerce relationship stayed «ناقص» pending a
            // decision no product was waiting for. The owner's ruling settles
            // which side is the reference: «دسته‌بندی‌های فعلی WooCommerce
            // مرجع اصلی ما هستند.» If no row lacks both, nothing needs the old
            // mapping, and an unmapped source category is then a note on the
            // screen rather than a blocker. The mapping itself is NOT removed:
            // for a product WooCommerce gives no category at all it is the one
            // path left, and it is still counted by the half above.
            'categories_mapped' => $withoutCategory === 0,
            // Observed rows are catalogue notes. A shop whose products are all
            // still notes has been read, not moved — and one that holds no
            // products at all has nothing to move, which is a THIRD answer
            // and not a failure. `OwnershipState` is the one place that rule
            // is written; steps 9 and 10 of the wizard read the same answer,
            // so the gate and the checklist cannot drift apart (`alpha.78`).
            'ownership_taken' => OwnershipState::settled($ownershipState),
            // ⛔ Written by ReviewApplication **and** by
            // `DecideImportedShopSelling` since `alpha.86` — an imported shop
            // has no application to approve, so that route is the only door
            // it has. Both write `can_sell` and nothing else, under the same
            // capability. (This comment said «only ReviewApplication» for ten
            // rounds after that stopped being true.)
            'vendor_can_sell' => $profile?->canSell === true,
            // ⛔ And an UNREADABLE staff list is not «every role answered».
            // `staffForVendor()` answers `[]` for a shop with no staff and
            // `[]` for a statement that failed, and `undecided([])` is `[]`,
            // so a broken read used to make this gate say DONE — the one
            // direction a readiness gate must never be wrong in. The count
            // comes from `importedCountsFor()`, which carries its `null`
            // uncast for exactly this purpose, and a list shorter than the
            // count is a read that came up short (`alpha.81`).
            'staff_decided' => $staffReadable && $undecidedRoles === [],
            'finance_decided' => $this->finance->decisionFor($vendorUserId)['decision'] !== ReconcileDokanFinance::OPEN,
        ];

        $missing = array_keys(array_filter($gates, static fn (bool $ok): bool => !$ok));
        $verdict = match (true) {
            $missing === [] => self::COMPLETE,
            // The specific shape the owner warned about: the archive landed,
            // and not one decision above it was taken.
            $gates['records_imported'] && count($missing) === count(self::GATES) - 1 => self::RECORDED_ONLY,
            default => self::INCOMPLETE,
        };

        return [
            'vendor_user_id' => $vendorUserId,
            'verdict' => $verdict,
            'gates' => $gates,
            'missing' => $missing,
            'detail' => [
                'staff_rows' => count($staffRows),
                'undecided_roles' => $undecidedRoles,
                'products_marketplace' => $tally['marketplace'],
                'products_observed' => $tally['observed'],
                // WHICH of the four answers, so the page can print «چیزی
                // برای انتقال نبود» rather than «۰ ردیف هنوز مشاهده‌ای است»,
                // and «شمارش خوانده نشد» rather than a nought.
                'ownership_state' => $ownershipState,
                // Whether the staff list above is the whole list. Printed so
                // that «۰ نقش بی‌تصمیم» can never be read off a failed read.
                'staff_readable' => $staffReadable,
                'unmapped_categories' => $unmappedCategories,
                // The mappable ones, separately: the page explains which half
                // of the gate is open and «۳ دستهٔ مبدأ نگاشت نشده» must not
                // count a group nobody can map.
                'unmapped_mappable' => $mappableUnmapped,
                // `-1` means the count could not be read, and the page says
                // so rather than printing a number nobody measured.
                'products_without_category' => $withoutCategory,
                'balance_rows' => (int) $summary['balance_rows'],
                'withdrawal_rows' => (int) $summary['withdrawals'],
                // Why the gate answered what it did. A zero that was READ is
                // not the same row as a zero that could not be, and the page
                // prints the difference instead of one warning for both.
                'records_state' => $records['state'],
                'records_kinds' => $records['kinds'],
                'records_reasons' => $records['reasons'],
                // Who confirmed the reconciliation and when, or `null`. Read
                // for the SCREEN and never for the gate: the gate's answer is
                // `records_state` above, which already took the version
                // comparison into account. A page that re-derived «is this
                // confirmation still good» from this array would be a second
                // implementation of `withoutDokan()` (`alpha.78`).
                'records_confirmation' => $this->confirmations?->liveFor($vendorUserId),
                // The selling permission as a FACT, not only as a gate. The
                // page needs it in both directions: a gate that is open says
                // «grant», and a gate that is closed still needs a way back,
                // because a decision with no reverse is not a decision.
                'can_sell' => $profile?->canSell === true,
            ],
        ];
    }

    /**
     * Every SHOP, judged — and a shop is not the same thing as an id that
     * owns records.
     *
     * ⛔ **This is the circularity the owner found in the `alpha.78` ZIP.**
     * The list used to be `vendorUserIds()`, whose first trace was
     * `records->vendorsWithRecords()`. So the moment the record import wrote a
     * row for an id, that id HAD an «import trace», `ShopRecordScope::rows()`
     * labelled it `IMPORTED`, and it walked into this table — the table that
     * says whether a shop has been migrated and may be admitted. Measured on
     * staging: ids 2, 4, 6, 8 and 16 own balance rows in Dokan and nothing
     * else; before the import they were correctly outside this list, and the
     * import itself put them in. The criterion was «does it have a record»,
     * and the record was ours to create, so the gate decided its own subject.
     *
     * A shop is therefore defined by a MARKETPLACE trace — something an import
     * created on THIS side: a product row stamped with a run (migration 14) or
     * a vendor profile stamped with one (migration 16). Both are rows this
     * marketplace wrote about a shop it took on. A history row is a copy of
     * somebody else's past and says nothing about whether we took anything on.
     *
     * The records are kept and shown — see `recordOwnerVendorUserIds()` and
     * the handover page's own table — and nothing of them is deleted or
     * rewritten by this change. What changes is only which ids are PUT THROUGH
     * THE GATES, and «داشتن سابقه به‌تنهایی معیار فروشگاه نباشد» is exactly
     * that.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $ids = $this->shopVendorUserIds();
        $out = [];
        foreach ($ids as $vendorUserId) {
            $out[] = $this->forVendor($vendorUserId);
        }
        return $out;
    }

    /**
     * Every id this page is about at all: the shops, plus the owners of the
     * records we already hold.
     *
     * ⛔ Public because the handover page asked
     * `ShopRecordRepositoryInterface::vendorsWithRecords()` instead, which is
     * one of the traces below and the one that is empty on every install where
     * the records have not been imported. A shop whose products and profile
     * both arrived was therefore invisible on the page about handing it over,
     * and the page's empty state told the manager to run the product import
     * again — advice that could not help, because nothing in production wrote
     * a record at all.
     *
     * ⛔ And it is NOT the list the gates run on. It deliberately includes
     * record owners, because a record owner must stay listed and visible; and
     * it is deliberately not the completeness table's subject, because being
     * listed is not being a shop. `shopVendorUserIds()` is that subject, and
     * the two were one list until this round.
     *
     * @return list<int>
     */
    public function vendorUserIds(): array
    {
        $ids = array_values(array_unique(array_merge(
            $this->shopVendorUserIds(),
            $this->recordOwnerVendorUserIds()
        )));
        sort($ids);
        return array_values($ids);
    }

    /**
     * The shops: every id with a MARKETPLACE trace, and nothing else.
     *
     * Two traces, and both are read for the same reason `ImportFromDokan::
     * runs()` reads both stamp tables: migration 16 added the profile stamp
     * and deliberately did NOT backfill, so a shop imported by an earlier
     * build carries a stamp on its PRODUCTS and none on its profile. Ask only
     * the profiles and those shops vanish; ask only the products and a run
     * that died before writing one vanishes instead.
     *
     * What is NOT a trace here: a row in our three history tables. Those are
     * copies of Dokan's past, written by the record import for ids the record
     * import found in Dokan — and an id can own a thousand of them without
     * this marketplace ever having created a single thing for it.
     *
     * @return list<int>
     */
    public function shopVendorUserIds(): array
    {
        $ids = [];
        foreach ($this->products->vendorsFromImportRuns() as $vendorUserId) {
            $ids[] = (int) $vendorUserId;
        }
        foreach ($this->vendors->importRunIds() as $runId) {
            foreach ($this->vendors->idsFromImportRun($runId) as $vendorUserId) {
                $ids[] = (int) $vendorUserId;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * The ids we hold records FOR — history, and nothing more.
     *
     * Named and exposed rather than folded into the shops, so the handover
     * page can keep showing a historical owner's staff, balance and withdrawal
     * records («سوابق را حفظ و نمایش بده») while the completeness table stays
     * about shops.
     *
     * @return list<int>
     */
    public function recordOwnerVendorUserIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', $this->records->vendorsWithRecords())));
        sort($ids);
        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * Whether ANY imported shop may be described as fully migrated.
     *
     * Deliberately pessimistic across shops: one shop still waiting is enough
     * for the answer to be no, because «مهاجرت کامل شد» is a sentence about
     * the marketplace and not about the best shop in it.
     */
    public function allComplete(): bool
    {
        $rows = $this->all();
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $row) {
            if ($row['verdict'] !== self::COMPLETE) {
                return false;
            }
        }
        return true;
    }

    /**
     * Source categories still holding this shop's products back.
     *
     * Asked of the Dokan reader, because the marketplace row of an unmapped
     * product carries an EMPTY category — the fact that it is empty is the
     * symptom, and the source category is the thing a person has to map. With
     * Dokan gone the question cannot be asked at all, and the gate then
     * answers from what is in front of it rather than inventing a past.
     *
     * @return list<array{key:string, label:string, products:int}>
     */
    private function unmappedFor(int $vendorUserId): array
    {
        if ($this->dokan === null || $this->categories === null || !$this->dokan->isAvailable()) {
            return [];
        }
        $mine = [];
        foreach ($this->dokan->products() as $product) {
            if ((int) $product['vendor_user_id'] === $vendorUserId) {
                $mine[] = $product;
            }
        }
        return $this->categories->unmapped($mine);
    }
}
