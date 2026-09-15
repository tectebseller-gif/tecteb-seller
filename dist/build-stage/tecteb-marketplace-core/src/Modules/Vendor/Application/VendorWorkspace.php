<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\MobileIdentity;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementMode;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementSet;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorDocument;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorProfile;

/**
 * Everything one vendor's screen needs, decided once, here.
 *
 * The dashboard is built from this: each task carries a state and, when
 * there is genuinely something to do, the route of the action that does it.
 * That is what keeps the screen free of dead text — a row either offers a
 * button or explains what it is waiting for.
 */
final class VendorWorkspace
{
    /** @param list<VendorDocument> $documents */
    public function __construct(
        public readonly ?VendorApplication $application,
        public readonly ?VendorProfile $profile,
        public readonly RequirementSet $requirements,
        public readonly array $documents,
        public readonly MobileIdentity $mobile,
        public readonly bool $mobileVerificationAvailable
    ) {
    }

    public function status(): ApplicationStatus
    {
        return $this->application?->status ?? ApplicationStatus::Draft;
    }

    public function isApprovedVendor(): bool
    {
        return $this->profile !== null && $this->profile->canSell;
    }

    public function hasDocument(string $typeSlug): bool
    {
        foreach ($this->documents as $doc) {
            if ($doc->typeSlug === $typeSlug) {
                return true;
            }
        }
        return false;
    }

    public function documentFor(string $typeSlug): ?VendorDocument
    {
        foreach ($this->documents as $doc) {
            if ($doc->typeSlug === $typeSlug) {
                return $doc;
            }
        }
        return null;
    }

    public function canSubmit(): bool
    {
        return $this->application !== null
            && $this->application->status->isEditableByApplicant()
            && $this->application->details->isComplete()
            && !$this->requirements->blocksSubmission($this->documents);
    }

    /** @return list<WorkspaceTask> */
    public function tasks(): array
    {
        $tasks = [];
        $details = $this->application?->details;

        $tasks[] = new WorkspaceTask(
            'profile',
            $details !== null && $details->isComplete() ? TaskState::Done : TaskState::Todo,
            'application'
        );

        $mode = $this->requirements->mode();
        $missing = $this->requirements->missingRequired($this->documents);
        $tasks[] = match (true) {
            $mode === RequirementMode::Undefined => new WorkspaceTask('documents', TaskState::Waiting),
            $mode === RequirementMode::ExplicitlyNone => new WorkspaceTask('documents_none', TaskState::Done),
            $missing === [] => new WorkspaceTask('documents', TaskState::Done, 'application', ['count' => count($this->documents)]),
            default => new WorkspaceTask('documents', TaskState::Todo, 'application', ['missing' => count($missing)]),
        };

        $status = $this->status();
        $tasks[] = match ($status) {
            ApplicationStatus::Submitted, ApplicationStatus::InReview => new WorkspaceTask('review', TaskState::Waiting),
            ApplicationStatus::ChangesRequested => new WorkspaceTask('fix_and_resubmit', TaskState::Todo, 'application'),
            ApplicationStatus::Approved => new WorkspaceTask('approved', TaskState::Done),
            ApplicationStatus::Rejected => new WorkspaceTask('rejected', TaskState::Done),
            ApplicationStatus::Suspended => new WorkspaceTask('suspended', TaskState::Waiting),
            ApplicationStatus::Draft => $this->canSubmit()
                ? new WorkspaceTask('submit', TaskState::Todo, 'submit')
                : new WorkspaceTask('submit_blocked', TaskState::Waiting),
        };

        // The number is on file and nothing has proved it. No button: there is
        // nothing the vendor can press that would change that today.
        $tasks[] = new WorkspaceTask(
            'mobile',
            $this->mobile->isVerified() ? TaskState::Done : TaskState::Waiting,
            null,
            ['number' => $this->mobile->number]
        );

        return $tasks;
    }
}
