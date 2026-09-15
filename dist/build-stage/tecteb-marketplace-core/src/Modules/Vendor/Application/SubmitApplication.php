<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStateMachine;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementMode;

/**
 * Submission is the one place where "the manager has not defined documents
 * yet" must stop the flow — and stop it VISIBLY. It never approves, never
 * silently succeeds, and never treats an undefined requirement set as an
 * empty one (plan §2.1).
 */
final class SubmitApplication
{
    public function __construct(
        private readonly VendorRepositoryInterface $applications,
        private readonly DocumentTypeRepositoryInterface $types,
        private readonly DocumentRepositoryInterface $documents,
        private readonly ApplicationStateMachine $states,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    public function handle(int $userId): OperationResult
    {
        if (!$this->capabilities->can(VendorCapabilities::APPLY)) {
            return OperationResult::failure('forbidden');
        }
        $application = $this->applications->findApplicationByUser($userId);
        if ($application === null) {
            return OperationResult::failure('no_application');
        }
        if (!$this->states->canTransition($application->status, ApplicationStatus::Submitted)) {
            return OperationResult::failure('not_submittable', ['status' => $application->status->value]);
        }
        $missingFields = $application->details->missingFields();
        if ($missingFields !== []) {
            return OperationResult::failure('incomplete_form', ['fields' => implode(',', $missingFields)]);
        }

        $requirements = $this->types->requirementSet();
        if ($requirements->mode() === RequirementMode::Undefined) {
            return OperationResult::failure('requirements_undefined');
        }
        $uploaded = $this->documents->forApplication($application->id);
        $missingDocs = $requirements->missingRequired($uploaded);
        if ($missingDocs !== []) {
            return OperationResult::failure('missing_documents', [
                'types' => implode('، ', array_map(static fn ($t) => $t->label, $missingDocs)),
            ]);
        }

        if (!$this->applications->updateStatus($application->id, ApplicationStatus::Submitted)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::VENDOR_APPLICATION_SUBMITTED, $userId, 'vendor_application', (string) $application->id, [
            'application_id' => $application->id,
            'from' => $application->status->value,
            'to' => ApplicationStatus::Submitted->value,
            'documents' => count($uploaded),
        ]);
        return OperationResult::success('submitted', ['application_id' => $application->id]);
    }
}
