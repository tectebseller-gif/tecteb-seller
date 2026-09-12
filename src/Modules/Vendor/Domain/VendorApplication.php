<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/** One application, as it currently stands. */
final class VendorApplication
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly ApplicationStatus $status,
        public readonly ApplicantDetails $details,
        public readonly ?string $reviewNote = null,
        public readonly ?int $reviewedBy = null,
        public readonly ?string $reviewedAt = null,
        public readonly ?string $submittedAt = null,
        public readonly string $updatedAt = ''
    ) {
    }

    public function withStatus(ApplicationStatus $status): self
    {
        return new self($this->id, $this->userId, $status, $this->details, $this->reviewNote, $this->reviewedBy, $this->reviewedAt, $this->submittedAt, $this->updatedAt);
    }
}
