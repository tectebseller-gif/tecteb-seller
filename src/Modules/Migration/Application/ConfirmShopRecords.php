<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * A manager confirming, explicitly, that one shop's Dokan records were fully
 * brought over — and the only writer of that confirmation.
 *
 * ### The question this answers, and why nothing else can
 *
 * `records_imported` is the one gate of the six that needs Dokan to be
 * RUNNING: `ShopRecordPreview` asks `DokanReaderInterface`, and with Dokan
 * gone every kind comes back `unchecked` with reason `dokan_not_running`, so
 * the gate stays open for every shop, for ever. `alpha.79` chose that
 * deliberately, on the owner's own instruction that «وجود محصول یا بخشی از
 * سوابق اثبات کامل بودن نیست», and wrote the price down: a shop whose records
 * all arrived before the cut-over reads «ناقص» afterwards.
 *
 * A finished queue row cannot close it either, and that is the measurement
 * that makes this class necessary rather than convenient: a job run after
 * Dokan is gone reads empty pages, finds nothing missing and reports «انجام
 * شد» — byte for byte what a real successful run reports — and nothing
 * anywhere records that Dokan was readable at that moment.
 *
 * So the evidence has to be the confirmation itself. The owner's ruling:
 * «تأیید صریح مدیر، فقط پس از تطبیق کامل سوابق، همراه با تاریخچه ممیزی و
 * امکان ابطال. این تصمیم نباید خودکار ثبت شود و نباید مالکیت، اجازه فروش یا
 * داده‌های مالی را تغییر دهد.» Five properties, and each is a line of code
 * rather than a sentence:
 *
 *  - **صریح** — `confirm()` takes ONE vendor id and runs only from one admin
 *    action. Nothing infers it, and `up()` of migration 37 backfills nothing.
 *  - **فقط پس از تطبیق کامل** — `eligibility()` requires
 *    `MigrationCompleteness::recordsState()` to be `settled` or `no_source`
 *    RIGHT NOW. Both of those are reachable only when Dokan answered — with
 *    Dokan absent every kind is `unchecked` — so «the reconciliation was
 *    complete and checkable» is a property of that query rather than a claim
 *    on a screen. And it is asked again inside `confirm()`, not taken from
 *    whatever the page computed (`alpha.81`).
 *  - **ممیزی‌پذیر** — the stored row itself carries `confirmed_by` and
 *    `confirmed_at`, so the decision is self-auditing even if the audit
 *    table write fails. The audit row is a second record, not the only one,
 *    and it is written AFTER the confirmed write (`alpha.86`).
 *  - **قابل لغو** — `revoke()` keeps the row and clears its live key, and it
 *    deliberately asks NOTHING about the shop's current state: a decision you
 *    can only withdraw while the world still looks the way it did is not
 *    revocable.
 *  - **changes nothing else** — this file names no ownership writer, no
 *    `can_sell` writer and no financial table.
 *    `ShopRecordConfirmationIsNeverAutomaticTest` reads its bytes to say so,
 *    with the comments stripped first (`alpha.22`).
 *
 * ### What it stores, and what is compared later
 *
 * The shop's `records_version` (`tmc_dokan_shop_version`, migration 18) and a
 * sentence naming the three verdicts and the three held counts. Only the
 * VERSION is compared: every write to a shop's imported rows bumps that
 * counter inside its own transaction, so a re-import, a rollback or an
 * extension makes the confirmation stale and `MigrationCompleteness` reports
 * it by name instead of reading as settled. The words are for a person; a
 * fingerprint of verdicts would collide the moment a rollback restored
 * exactly what was there before, which is the argument migration 18 already
 * made for the counter.
 *
 * ### Why `tmc_review_vendor` and no new capability
 *
 * `alpha.86`'s rule: same consequence, same permission. This closes one of
 * the six gates on the hand-over page, beside «اعطای اجازهٔ فروش» and the
 * financial decision, both of which ask for this key. Minting a second key
 * for the third button on one page is itself an access-separation gap.
 */
final class ConfirmShopRecords
{
    public function __construct(
        private readonly MigrationCompleteness $completeness,
        private readonly ShopRecordRepositoryInterface $records,
        private readonly ShopRecordConfirmationRepositoryInterface $confirmations,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * Whether this shop's records could be confirmed right now, and why not.
     *
     * Asked by the screen so a button the action would refuse is never drawn
     * — a screen that offers an action it cannot perform teaches people to
     * ignore its buttons (`alpha.86`).
     *
     * @return array{eligible:bool, reason:string, state:string}
     */
    public function eligibility(int $vendorUserId): array
    {
        $supported = $this->confirmations->supported();
        if ($supported === null) {
            return ['eligible' => false, 'reason' => 'confirmation_table_unreadable', 'state' => ''];
        }
        if ($supported === false) {
            return ['eligible' => false, 'reason' => 'confirmation_unsupported', 'state' => ''];
        }
        if (!in_array($vendorUserId, $this->completeness->shopVendorUserIds(), true)) {
            // Record owners are listed on this page and are not shops
            // (`alpha.79`). Confirming the records of an id this marketplace
            // never took anything on would close a gate about a shop that
            // does not exist here.
            return ['eligible' => false, 'reason' => 'not_an_imported_shop', 'state' => ''];
        }
        $records = $this->completeness->recordsState($vendorUserId);
        $state = (string) $records['state'];
        if (!in_array($state, [MigrationCompleteness::RECORDS_SETTLED, MigrationCompleteness::RECORDS_NO_SOURCE], true)) {
            // ⛔ The one condition that cannot be waived. Every other state
            // means either something is still waiting, or a read failed, or
            // Dokan cannot be asked — and a confirmation given in any of them
            // would be a statement nobody was in a position to make.
            return ['eligible' => false, 'reason' => 'records_not_settled', 'state' => $state];
        }
        if ($this->confirmations->liveFor($vendorUserId) !== null) {
            return ['eligible' => false, 'reason' => 'already_confirmed', 'state' => $state];
        }
        return ['eligible' => true, 'reason' => '', 'state' => $state];
    }

    /** Record ONE confirmation. Never automatic: one manager, one action. */
    public function confirm(int $vendorUserId): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden', ['vendor_id' => $vendorUserId]);
        }
        // Asked again HERE, not trusted from the render: the page listed its
        // rows from an earlier read, and by the time the form arrives a kind
        // may have gone unreadable or a row may be waiting (`alpha.81`).
        $gate = $this->eligibility($vendorUserId);
        if (!$gate['eligible']) {
            return OperationResult::failure((string) $gate['reason'], [
                'vendor_id' => $vendorUserId,
                'state' => (string) $gate['state'],
            ]);
        }

        $version = $this->records->recordsVersion($vendorUserId);
        $reconciliation = $this->reconciliationOf($vendorUserId);
        $actorId = $this->capabilities->currentUserId() ?? 0;

        $code = $this->confirmations->confirm($vendorUserId, $version, $reconciliation, $actorId);
        if ($code !== '') {
            return OperationResult::failure($code, ['vendor_id' => $vendorUserId]);
        }

        $this->audit?->log(
            AuditEventCatalog::DOKAN_RECORDS_CONFIRMED,
            $actorId,
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'actor_id' => $actorId,
                'records_version' => $version,
                'reconciliation' => $reconciliation,
            ]
        );

        return OperationResult::success('records_confirmed', [
            'vendor_id' => $vendorUserId,
            'records_version' => $version,
        ]);
    }

    /**
     * Withdraw the live confirmation.
     *
     * Asks NOTHING about the shop's present state, on purpose: the whole
     * point of a revocable decision is that it can be taken back after the
     * world has moved, and requiring the original conditions would make the
     * confirmation permanent in exactly the case somebody wants it gone.
     */
    public function revoke(int $vendorUserId): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden', ['vendor_id' => $vendorUserId]);
        }
        $live = $this->confirmations->liveFor($vendorUserId);
        $actorId = $this->capabilities->currentUserId() ?? 0;
        $code = $this->confirmations->revoke($vendorUserId, $actorId);
        if ($code !== '') {
            return OperationResult::failure($code, ['vendor_id' => $vendorUserId]);
        }

        $this->audit?->log(
            AuditEventCatalog::DOKAN_RECORDS_CONFIRMATION_REVOKED,
            $actorId,
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'actor_id' => $actorId,
                // The version the withdrawn confirmation was taken against,
                // read from the row rather than recomputed: the trail has to
                // say WHAT was withdrawn, and today's counter is a different
                // number by the time anybody asks.
                'records_version' => (int) ($live['records_version'] ?? 0),
            ]
        );

        return OperationResult::success('records_confirmation_revoked', ['vendor_id' => $vendorUserId]);
    }

    /**
     * What was reconciled, in one short line a person reads.
     *
     * Built from the same `recordsState()` the gate reads, so the sentence
     * stored and the verdict acted on cannot describe two different things.
     */
    private function reconciliationOf(int $vendorUserId): string
    {
        $records = $this->completeness->recordsState($vendorUserId);
        $held = $this->records->importedCountsFor($vendorUserId);
        $parts = [];
        foreach (ShopRecordPreview::KINDS as $kind) {
            $verdict = (string) ($records['kinds'][$kind] ?? 'unknown');
            $count = $held[$kind] ?? null;
            $parts[] = $kind . ':' . $verdict . '=' . ($count === null ? '?' : (string) (int) $count);
        }
        return implode(' ', $parts);
    }
}
