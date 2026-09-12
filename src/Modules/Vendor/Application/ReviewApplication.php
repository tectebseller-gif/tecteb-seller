<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
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
        private readonly CapabilityCheckerInterface $capabilities
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
