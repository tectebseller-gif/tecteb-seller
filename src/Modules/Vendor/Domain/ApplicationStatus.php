<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * The seven states the master spec (§4.1) names for a vendor application.
 * Stored as strings, so the value is part of the data contract.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /** Whether the applicant may still edit the form in this state. */
    public function isEditableByApplicant(): bool
    {
        return $this === self::Draft || $this === self::ChangesRequested;
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected || $this === self::Suspended;
    }
}
