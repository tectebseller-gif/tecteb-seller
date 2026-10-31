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
    /**
     * The vendor paperwork for a set of users, in ONE read each table.
     *
     * The unified product list decides which bucket a row is in from its
     * AUTHOR's paperwork, and a page of twenty products has up to twenty
     * authors. Asked one at a time that is forty statements for one screen —
     * measured at twenty-four selects for a page of twenty on the disposable
     * site before this method existed. Asked here it is two, whatever the
     * page size.
     *
     * The answer is the status STRING rather than the objects, because that
     * is all a bucket decision uses, and `profile_without_application` is a
     * real value: a shop that arrived through an import has a profile and no
     * application, and reading that as «not a vendor» would file somebody's
     * live shop under «مبهم». A user with neither is absent from the answer.
     *
     * @param list<int> $userIds
     * @return array<int,string> user id => application status, or
     *         `profile_without_application`
     */
    public function profileStatusesFor(array $userIds): array;


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

    /**
     * Create or update one shop profile, in one statement.
     *
     * `$importRunId` is a parameter and not a follow-up write for the same
     * reason as on the product side: a shop stamped a statement after it was
     * created is a shop a crash can leave nameless.
     */
    public function upsertProfile(
        int $userId,
        string $storeName,
        bool $canSell,
        bool $canPublishDirectly,
        string $importRunId = ''
    ): int;

    /**
     * Removes a store profile that has nothing behind it.
     *
     * Refuses — and answers false — when the vendor has an application, any
     * product, or any staff. Only a profile a migration trial created and
     * nobody has used can go, and the WordPress USER is never touched: they
     * were Dokan's before the trial and are Dokan's after it.
     */
    public function deleteEmptyProfile(int $userId): bool;

    /**
     * Write the import run that created this profile onto the profile itself.
     *
     * The same reason as the product side: a manifest written beside the rows
     * is a second write that a crash can lose, and a rollback that cannot name
     * what it created leaves a shop nobody can undo.
     */
    public function stampImportRun(int $userId, string $runId): bool;

    /** @return list<int> the user ids of profiles this run created */
    public function idsFromImportRun(string $runId): array;

    /** @return list<string> every run id any profile carries, newest first */
    public function importRunIds(): array;

    /**
     * The user ids of shops whose application is APPROVED, ascending.
     *
     * Paged, because the sitemap asks for a page at a time and a site with
     * three thousand shops must not load three thousand rows to print fifty.
     *
     * @return list<int>
     */
    public function approvedVendorUserIds(int $limit = 50, int $offset = 0): array;

    public function countApprovedVendors(): int;

    /**
     * Shops whose public page a crawler can actually fetch.
     *
     * Approved is necessary and not sufficient: the page also needs the
     * shop's own settings row, and without one `StorePage` answers 404. The
     * sitemap used `approvedVendorUserIds()` and therefore advertised shops
     * that were approved but never set up — measured on the disposable site,
     * where two of three listed URLs answered 404. A sitemap entry pointing at
     * a 404 is not a missing entry; it is a crawl budget spent on nothing and
     * a quality signal against the whole index.
     *
     * @return list<int>
     */
    public function listableVendorUserIds(int $limit = 50, int $offset = 0): array;

    /** How many shops `listableVendorUserIds()` would return in total. */
    public function countListableVendors(): int;

    /**
     * EVERY REAL SHOP, one page at a time — the profile table, not the
     * application table.
     *
     * The shop list used to ask `approvedVendorUserIds()`, which is
     * `status = 'approved'` on the APPLICATIONS table. A shop imported from
     * Dokan never applied: the import writes a profile with `can_sell = 0`
     * and a run stamp and touches no application row anywhere (measured: zero
     * references to that table under `src/Modules/Migration/`). So the page
     * asked «who has approved paperwork» while meaning «who is a shop», and
     * two imported shops were invisible on the only screen that lists shops.
     * Measured on the disposable database: 40 profiles, 14 listed.
     *
     * `imported` comes from the run stamp, and there is no third answer to
     * derive: the profile table has exactly two writers, `ReviewApplication`
     * and the import, so a stamp means «came from Dokan» and its absence
     * means «came through the application route».
     *
     * One query for the page rather than one per row: the caller used to
     * follow its id list with a `findProfileByUser()` each, which is 51
     * statements for 50 shops.
     *
     * @return list<array{user_id:int, store_name:string, can_sell:bool, imported:bool}>
     */
    public function shopRows(int $limit = 50, int $offset = 0): array;

    /** How many profiles `shopRows()` would walk in total. */
    public function countShops(): int;

    /**
     * Shops whose NAME matches, read from the same table the list reads.
     *
     * Not `StoreRepositoryInterface::searchByShopName()`: that one reads the
     * shop's own settings row, which only `ReviewApplication::seedName()`
     * ever creates — so an imported shop could not be found by the very name
     * the page was printing for it. The name shown and the name searched now
     * come from one place.
     *
     * @return list<array{user_id:int, store_name:string, can_sell:bool, imported:bool}>
     */
    public function searchShopsByName(string $term, int $limit = 50): array;

    /**
     * Flip ONE shop's selling permission, with the value it is expected to
     * hold right now carried INSIDE the statement.
     *
     * Not `upsertProfile()`, which also writes the store name and the
     * direct-publish switch: a permission change that quietly rewrote two
     * other fields would be a data change nobody asked for. And the expected
     * value is a `WHERE` term rather than a read followed by a write, because
     * a check that returns cannot guarantee anything about the write after it
     * (`alpha.50`).
     *
     * Returns the number of rows changed, or `null` when the statement
     * failed — so «nothing to do» and «could not write» stay apart
     * (`alpha.8`). Zero rows is not an error here and not a success either:
     * the caller re-reads the row and says which it was.
     */
    public function setSellingPermission(int $userId, bool $canSell, bool $expectedCurrent): ?int;

    /** The import run a profile carries, `''` when it carries none. */
    public function importRunIdFor(int $userId): string;
}
