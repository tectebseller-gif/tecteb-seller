<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

interface StoreRepositoryInterface
{
    public function find(int $vendorUserId): ?StoreSettings;

    public function save(int $vendorUserId, StoreSettings $settings): bool;

    /** Renaming goes through the manager, so this is called only on approval. */
    public function renameStore(int $vendorUserId, string $storeName): bool;

    /** @return array{iban:string, holder:string, document_id:int, status:string, on_hold:bool} */
    public function bank(int $vendorUserId): array;

    public function saveBank(int $vendorUserId, string $iban, string $holder, int $documentId, string $status, bool $onHold): bool;
}
