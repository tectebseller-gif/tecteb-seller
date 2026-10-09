<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;

interface StoreRepositoryInterface
{
    public function find(int $vendorUserId): ?StoreSettings;

    /**
     * The shop's settings, or only the fields named.
     *
     * `$fields` carries the keys of `StoreSettings::TAB_FIELDS` for the tab
     * being saved, so an implementation writes only those columns. That is
     * what stops a save of one tab from putting another tab's values back
     * from a read taken moments earlier — the defect the owner measured, where
     * saving «ارسال» emptied the city and the introduction. `null` means every
     * settings column, which is what a seed or a manager's own path wants.
     *
     * @param list<string>|null $fields
     */
    public function save(int $vendorUserId, StoreSettings $settings, ?array $fields = null): bool;

    /** Renaming goes through the manager, so this is called only on approval. */
    public function renameStore(int $vendorUserId, string $storeName): bool;

    /**
     * Give a shop the name it was approved under — once, and never over a
     * name that already exists.
     *
     * `renameStore()` is an UPDATE, and a freshly approved shop has no
     * settings row yet: the row is created by the vendor's first save, with
     * an empty name, because the name is not one of the fields that form
     * offers (changing it goes through the manager). So until `alpha.23`
     * every approved shop's PUBLIC page was titled «فروشگاه بازارگاه تک‌طب»
     * — the generic fallback — and the only way to put the real name on it
     * was to ask the manager to approve a rename to the name they had just
     * approved.
     *
     * Seeding is not renaming, so it is its own verb: it creates the row if
     * it is missing and fills the name only while it is empty.
     */
    public function seedName(int $vendorUserId, string $storeName): bool;

    /** @return array{iban:string, holder:string, document_id:int, status:string, on_hold:bool} */
    public function bank(int $vendorUserId): array;

    public function saveBank(int $vendorUserId, string $iban, string $holder, int $documentId, string $status, bool $onHold): bool;
}
