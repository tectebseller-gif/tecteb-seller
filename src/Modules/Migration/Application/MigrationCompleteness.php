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
 *   records_imported   the archive exists at all
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
 * leaves, not only the financial one. See `all()` — the list is the part that
 * was wrong, and a shop left off it is not judged lenient, it is not judged.
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
        private readonly ?CategoryMap $categories = null
    ) {
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

        $gates = [
            'records_imported' => ($summary['balance_rows'] + $summary['withdrawals'] + $summary['staff']) > 0
                || $tally['marketplace'] + $tally['observed'] > 0,
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
            ],
        ];
    }

    /**
     * Every imported shop, from every trace an import leaves.
     *
     * Asking only `vendorsWithRecords()` was wrong, and wrong in the direction
     * that hides work. That method unions the staff, balance and withdrawal
     * tables, so a shop imported with nothing but a catalogue — no Dokan
     * balance, no withdrawal, no staff, which is an ordinary shop and not an
     * edge case — never appeared here at all. It was not reported incomplete;
     * it was absent. `allComplete()` then answered about the shops it could
     * see and declared the migration finished over the top of it, which is
     * precisely the true-but-misleading sentence this class exists to refuse.
     *
     * So the universe is the union of all three traces, for the same reason
     * `ImportFromDokan::runs()` reads both stamp tables: migration 16 added
     * the profile stamp and deliberately did NOT backfill, so a shop imported
     * by an earlier build carries a stamp on its PRODUCTS and none on its
     * profile. Ask only the profiles and those shops vanish; ask only the
     * products and a run that died before writing one vanishes instead.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $ids = $this->importedVendorUserIds();
        sort($ids);
        $out = [];
        foreach ($ids as $vendorUserId) {
            $out[] = $this->forVendor($vendorUserId);
        }
        return $out;
    }

    /**
     * The three traces, merged: Dokan history rows, the product stamp
     * (migration 14) and the profile stamp (migration 16).
     *
     * The profile stamp is read run by run because that is the shape the
     * vendor repository exposes, and the run list is short — the same walk
     * `runs()` makes.
     *
     * @return list<int>
     */
    private function importedVendorUserIds(): array
    {
        $ids = array_map('intval', $this->records->vendorsWithRecords());
        foreach ($this->products->vendorsFromImportRuns() as $vendorUserId) {
            $ids[] = (int) $vendorUserId;
        }
        foreach ($this->vendors->importRunIds() as $runId) {
            foreach ($this->vendors->idsFromImportRun($runId) as $vendorUserId) {
                $ids[] = (int) $vendorUserId;
            }
        }
        return array_values(array_unique($ids));
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
