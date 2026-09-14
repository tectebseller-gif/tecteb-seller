<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequest;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequestStatus;

interface ChangeRequestRepositoryInterface
{
    public function open(int $vendorUserId, string $field, string $currentValue, string $requestedValue): int;

    public function find(int $id): ?ChangeRequest;

    /** @return list<ChangeRequest> */
    public function pending(int $limit = 50): array;

    /** @return list<ChangeRequest> one vendor's own history */
    public function forVendor(int $vendorUserId, int $limit = 20): array;

    public function pendingFor(int $vendorUserId, string $field): ?ChangeRequest;

    public function decide(int $id, ChangeRequestStatus $status, int $reviewerId, string $note): bool;
}
