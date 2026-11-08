<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Modules\Finance\Application\MoneyScaleRollbackGate;

/**
 * Everything `MigrationPlanChecklist` needs, read ONCE, for one shop.
 *
 * ### Why a gatherer rather than twelve reads in the view
 *
 * Because the checklist is pure and must stay that way, and because a view
 * that asked each service itself would ask some of them twice — the tally and
 * the completeness both want the ownership counts, and `ShopTransferTally::of()`
 * walks a whole catalogue. One snapshot, one walk.
 *
 * ### It reads and nothing else
 *
 * No method here writes, and `MigrationWizardIsReadOnly` asserts the absent
 * verbs from this file's bytes. That is the owner's «اجرای آزمایشی بدون تغییر
 * داده‌ها» as a property of the class rather than a sentence in a docblock:
 * opening the wizard cannot start an import, move ownership, grant a permission
 * or touch a WooCommerce product, because there is no code here that could.
 *
 * ⛔ **Every absent fact arrives as `null`, never as nought.** A service that
 * is not bound, a count that failed, a scan that stopped at its ceiling — each
 * one makes its step `UNKNOWN`, and `UNKNOWN` never counts towards «آماده».
 * A snapshot that filled a gap with zero would make the checklist confident
 * about something nobody measured.
 */
final class MigrationPlanSnapshot
{
    public function __construct(
        private readonly MigrationCompleteness $completeness,
        private readonly ShopTransferTally $tally,
        private readonly ReconcileStorefrontStatus $status,
        private readonly OptionStoreInterface $options,
        private readonly ?DependencyProbeInterface $probe = null,
        private readonly ?DokanReaderInterface $dokan = null,
        private readonly ?MoneyScaleRollbackGate $rollback = null
    ) {
    }

    /**
     * The shops this wizard can report on.
     *
     * The same list the completeness table is built from, so the wizard and
     * the gates below it cannot be about different sets of shops.
     *
     * @return list<int>
     */
    public function shops(): array
    {
        return $this->completeness->shopVendorUserIds();
    }

    /**
     * One shop's whole picture.
     *
     * @return array<string,mixed> the shape `MigrationPlanChecklist::steps()` takes
     */
    public function of(int $vendorUserId): array
    {
        $tally = $this->tally->of($vendorUserId);
        $counts = (array) $tally['counts'];
        // ⛔ A scan that stopped short reports its FIGURES as unreadable
        // rather than reporting the part it reached as the whole. The tally
        // itself prints `scanned` for a manager to read; the checklist must
        // not judge on it (`alpha.75`).
        $finished = $tally['finished'] === true && $tally['checked'] === true;

        $gates = $this->completeness->forVendor($vendorUserId);
        $detail = (array) ($gates['detail'] ?? []);

        // ⛔ The WHOLE shop, not page one. `plan()` answers about fifty rows
        // and its `finished` means «the page came back short», so a shop with
        // fifty or more stamped rows made this figure `null` for ever — the
        // stamp survives the repair by design, so page one is always full.
        // Step 7 therefore read «اندازه‌گیری‌نشده» on the very shop it was
        // written for, however much repairing a manager had done.
        $statusTally = $this->status->tally($vendorUserId);

        return [
            'php_version' => $this->probe?->phpVersion() ?? '',
            'platform_version' => $this->probe?->platformVersion(),
            'woocommerce_active' => $this->probe?->woocommerceAvailable() ?? false,
            'woocommerce_version' => $this->probe?->woocommerceVersion(),
            // `null` when there is no reader bound at all, which is a
            // different answer from «Dokan is not running» and lands the step
            // on `UNKNOWN` rather than `BLOCKED`.
            'dokan_running' => $this->dokan?->isAvailable(),
            'schema_version' => (int) $this->options->get(SchemaVersion::OPTION, 0),
            'schema_target' => SchemaVersion::TARGET,
            'rollback' => (string) ($this->rollback?->check()['verdict'] ?? ''),
            'shops' => count($this->shops()),
            'observed' => (int) ($detail['products_observed'] ?? 0),
            'marketplace' => (int) ($detail['products_marketplace'] ?? 0),
            // ⛔ The ANSWER, not the two numbers it was derived from. Steps 9
            // and 10 used to re-derive «منتقل شد؟» from `observed` and
            // `marketplace` here, which is the same rule written a second
            // time — and it got two cases wrong that the gate also got wrong:
            // a shop with no products at all (permanently TODO, with the
            // self-contradicting detail «۰ ردیف هنوز مشاهده‌ای است») and a
            // tally nobody could read (reported as nought). `OwnershipState`
            // is now the only place that rule exists.
            'ownership_state' => (string) ($detail['ownership_state'] ?? OwnershipState::UNREADABLE),
            'scan_finished' => $finished,
            'ready' => $finished ? (int) ($counts[OwnershipTransferPlan::READY] ?? 0) : null,
            'drift_fixable' => $finished ? (int) ($counts[OwnershipTransferPlan::DRIFT_FIXABLE] ?? 0) : null,
            'risk_real' => $finished ? (int) ($counts[OwnershipTransferPlan::RISK_REAL] ?? 0) : null,
            // ⛔ `null` when the walk did not reach the end OR could not
            // read a page of statuses — the declared page ceiling, a missing
            // capability, a missing WooCommerce, a broken read. The
            // repair's own card still prints its PAGE figure beside its
            // button, because that is the number a press acts on; this one is
            // the number a readiness claim rests on, and they are different
            // questions about the same rows.
            'status_repairable' => $statusTally['finished'] === true
                && $statusTally['readable'] === true
                ? (int) $statusTally['repairable']
                : null,
            'transfer_runs' => count((array) $this->options->get(ImportFromDokan::RUNS_OPTION, [])),
            'gates_missing' => array_values(array_map('strval', (array) ($gates['missing'] ?? []))),
        ];
    }
}
