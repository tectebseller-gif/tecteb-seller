<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Events\EventBus;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStateMachine;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

/**
 * The manager's decision. Three things are non-negotiable here:
 * the reviewer's capability is checked, the transition is legal, and the
 * decision lands in the audit log with who and when (master spec §4.1).
 *
 * Approval grants the right to sell. It does NOT grant direct publishing:
 * that is a second, separate switch and it stays off unless asked for.
 */
final class ReviewApplication
{
    public function __construct(
        private readonly VendorRepositoryInterface $applications,
        private readonly ApplicationStateMachine $states,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly ?EventBus $events = null,
        private readonly ?StoreRepositoryInterface $stores = null
    ) {
    }

    public function approve(int $applicationId, bool $canPublishDirectly = false): OperationResult
    {
        return $this->decide($applicationId, ApplicationStatus::Approved, null, $canPublishDirectly);
    }

    public function requestChanges(int $applicationId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->decide($applicationId, ApplicationStatus::ChangesRequested, $note, false);
    }

    public function reject(int $applicationId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->decide($applicationId, ApplicationStatus::Rejected, $note, false);
    }

    /**
     * Stops a vendor trading, without erasing anything they did.
     *
     * The profile's selling permission is what every access question reads,
     * so clearing it here is what cuts the vendor AND all of their staff off
     * at once: staff rights are derived from the shop, and a shop that may
     * not sell derives nothing. The membership rows, the products, the
     * documents and the audit trail all stay exactly where they are.
     */
    public function suspend(int $applicationId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->decide($applicationId, ApplicationStatus::Suspended, $note, false);
    }

    /** Back to approved. Direct publishing is NOT restored silently. */
    public function reinstate(int $applicationId, bool $canPublishDirectly = false): OperationResult
    {
        return $this->decide($applicationId, ApplicationStatus::Approved, null, $canPublishDirectly);
    }

    private function decide(int $applicationId, ApplicationStatus $to, ?string $note, bool $canPublishDirectly): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::REVIEW)) {
            return OperationResult::failure('forbidden');
        }
        $application = $this->applications->findApplication($applicationId);
        if ($application === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->states->canTransition($application->status, $to)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $application->status->value,
                'to' => $to->value,
            ]);
        }
        $reviewer = $this->capabilities->currentUserId();
        if (!$this->applications->updateStatus($applicationId, $to, $reviewer, $note)) {
            return OperationResult::failure('storage_failed');
        }
        if ($to === ApplicationStatus::Approved) {
            $this->applications->upsertProfile(
                $application->userId,
                $application->details->storeName,
                true,
                $canPublishDirectly
            );
            // The approved name belongs to the SHOP, not only to the file.
            //
            // Until `alpha.23` it was written to the profile and nowhere
            // else, and the store settings row — the one the public page
            // reads — was created empty by the vendor's first save. So a
            // freshly approved shop's public page was titled «فروشگاه
            // بازارگاه تک‌طب» for every vendor, and the only way to put the
            // real name on it was to ask the manager to approve a rename to
            // the name the manager had just approved.
            //
            // Seeded, never overwritten: `renameStore` stays the one path
            // that CHANGES a name, so this cannot undo a rename.
            $this->stores?->seedName($application->userId, $application->details->storeName);
        }
        if ($to === ApplicationStatus::Suspended) {
            // Both permissions go. Leaving direct publishing on would let a
            // suspended shop keep pushing products live through a path that
            // never asks whether it may still sell.
            $this->applications->upsertProfile(
                $application->userId,
                $application->details->storeName,
                false,
                false
            );
        }
        // Announced AFTER the decision is stored, so a listener that takes
        // the shop's products out of the storefront is acting on a suspension
        // that is already true.
        if ($to === ApplicationStatus::Suspended) {
            $this->events?->emit(EventBus::VENDOR_SUSPENDED, [
                'vendor_user_id' => $application->userId,
                'reason' => (string) $note,
            ]);
        }
        if ($to === ApplicationStatus::Approved && $application->status === ApplicationStatus::Suspended) {
            $this->events?->emit(EventBus::VENDOR_REINSTATED, ['vendor_user_id' => $application->userId]);
        }
        $this->audit->log(AuditEventCatalog::VENDOR_APPLICATION_REVIEWED, $reviewer, 'vendor_application', (string) $applicationId, [
            'application_id' => $applicationId,
            'from' => $application->status->value,
            'to' => $to->value,
            'decision' => $to->value,
            'has_note' => $note !== null && trim($note) !== '',
        ]);
        return OperationResult::success('reviewed', ['application_id' => $applicationId, 'to' => $to->value]);
    }
}
