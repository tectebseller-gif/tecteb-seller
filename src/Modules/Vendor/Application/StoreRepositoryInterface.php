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
    /**
     * Shops whose name matches what somebody typed.
     *
     * **Because a manager knows a shop by its name, not by a user id.** The
     * intake form asked for «شناسهٔ کاربر» until this round, which is a number
     * nobody has at hand and the wrong number is somebody else's catalogue.
     * The instruction is explicit: «انتخاب فروشنده با جست‌وجوی نام فروشگاه
     * باشد، نه نیاز به دانستن شناسهٔ کاربر».
     *
     * All three places a shop can be named are searched, because they answer
     * at different points in a shop's life: the settings row (an approved shop
     * that has saved its settings), the application (a shop still in review,
     * which has a name nowhere else) and the profile (a shop that arrived
     * through an import and never filled a form). A shop named in more than
     * one is returned ONCE, with the settings name preferred — the same
     * precedence the review list already uses for its «فروشگاه» column.
     *
     * Bounded by `$limit`, and the bound is the point: a term that matches
     * three hundred shops is not a choice, it is a different search.
     *
     * @return list<array{vendor_user_id:int, store_name:string, source:string}>
     */
    public function searchByShopName(string $term, int $limit = 20): array;

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
