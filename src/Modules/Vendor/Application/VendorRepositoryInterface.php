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
    /**
     * Every shop that exists, as user ids.
     *
     * Read from the PROFILES rather than derived from approved applications:
     * the profile is what makes somebody a vendor, and a marketplace-wide
     * report built from applications silently missed any shop whose row was
     * made another way. Measured — a report said «۰ فروشگاه» on a site with
     * three of them.
     *
     * @param bool $sellingOnly true to skip suspended shops
     * @return list<int>
     */
    public function vendorUserIds(bool $sellingOnly = false, int $limit = 500): array;

    public function upsertProfile(int $userId, string $storeName, bool $canSell, bool $canPublishDirectly): int;

    /**
     * Removes a store profile that has nothing behind it.
     *
     * Refuses — and answers false — when the vendor has an application, any
     * product, or any staff. Only a profile a migration trial created and
     * nobody has used can go, and the WordPress USER is never touched: they
     * were Dokan's before the trial and are Dokan's after it.
     */
    public function deleteEmptyProfile(int $userId): bool;
}
