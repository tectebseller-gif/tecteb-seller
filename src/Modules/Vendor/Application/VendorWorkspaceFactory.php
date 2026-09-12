<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

/** Assembles one vendor's whole picture in a single place. */
final class VendorWorkspaceFactory
{
    public function __construct(
        private readonly VendorRepositoryInterface $applications,
        private readonly DocumentTypeRepositoryInterface $types,
        private readonly DocumentRepositoryInterface $documents,
        private readonly MobileVerification $mobile
    ) {
    }

    public function forUser(int $userId): VendorWorkspace
    {
        $application = $this->applications->findApplicationByUser($userId);
        $documents = $application === null ? [] : $this->documents->forApplication($application->id);
        return new VendorWorkspace(
            $application,
            $this->applications->findProfileByUser($userId),
            $this->types->requirementSet(),
            $documents,
            $this->mobile->identityFor($application?->details->contactMobile ?? ''),
            $this->mobile->available()
        );
    }
}
