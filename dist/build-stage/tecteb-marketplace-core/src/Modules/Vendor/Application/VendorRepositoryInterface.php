<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorProfile;

/** Persistence for applications and the profiles approval creates. */
interface VendorRepositoryInterface
{
    public function findApplicationByUser(int $userId): ?VendorApplication;

    public function findApplication(int $applicationId): ?VendorApplication;

    /** @return list<VendorApplication> */
    public function listApplications(?ApplicationStatus $status = null, int $limit = 50): array;

    /** @return array<string,int> status value => count */
    public function countByStatus(): array;

    /** Creates or updates the draft owned by $userId and returns its id. */
    public function saveDraft(int $userId, ApplicantDetails $details): int;

    public function updateStatus(
        int $applicationId,
        ApplicationStatus $status,
        ?int $reviewerId = null,
        ?string $note = null
    ): bool;

    public function findProfileByUser(int $userId): ?VendorProfile;

    /** Idempotent: approving twice must not create two vendors. */
    public function upsertProfile(int $userId, string $storeName, bool $canSell, bool $canPublishDirectly): int;
}
