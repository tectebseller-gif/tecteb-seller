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
 *   ownership_taken    the rows are the marketplace's to run, not notes
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
        private readonly ?ShopRecordPreview $recordsPreview = null
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
            in_array('dokan_not_running', $unchecked, true) => self::RECORDS_DOKAN_ABSENT,
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

    /** @return array<string,mixed> */
    public function forVendor(int $vendorUserId): array
    {
        $summary = $this->records->summaryForVendor($vendorUserId);
        $staffRows = $this->records->staffForVendor($vendorUserId);
        $tally = $this->products->ownershipTallyForVendor($vendorUserId);
        $profile = $this->vendors->findProfileByUser($vendorUserId);
        $undecidedRoles = $this->roles->undecided($staffRows);
        $unmappedCategories = $this->unmappedFor($vendorUserId);

        $records = $this->recordsState($vendorUserId);

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
                self::RECORDS_SETTLED, self::RECORDS_NO_SOURCE => true,
                default => false,
            },
            'categories_mapped' => $unmappedCategories === [],
            // Observed rows are catalogue notes. A shop whose products are all
            // still notes has been read, not moved.
            'ownership_taken' => $tally['marketplace'] > 0 && $tally['observed'] === 0,
            // Written only by ReviewApplication. Import writes false, and that
            // is the separation this whole file is about.
            'vendor_can_sell' => $profile?->canSell === true,
            'staff_decided' => $undecidedRoles === [],
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
                'unmapped_categories' => $unmappedCategories,
                'balance_rows' => (int) $summary['balance_rows'],
                'withdrawal_rows' => (int) $summary['withdrawals'],
                // Why the gate answered what it did. A zero that was READ is
                // not the same row as a zero that could not be, and the page
                // prints the difference instead of one warning for both.
                'records_state' => $records['state'],
                'records_kinds' => $records['kinds'],
                'records_reasons' => $records['reasons'],
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
