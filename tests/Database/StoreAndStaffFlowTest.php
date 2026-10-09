<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Vendor\Application\AcceptStaffInvitation;
use Tecteb\Marketplace\Modules\Vendor\Application\ManageStaff;
use Tecteb\Marketplace\Modules\Vendor\Application\MobileVerification;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewChangeRequests;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\UpdateStoreSettings;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequest;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbChangeRequestRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStoreRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Infrastructure\Otp\NullOtpProvider;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeStaffUserDirectory;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;

/**
 * Store settings, the manager's change queue, and staff — on real MariaDB.
 *
 * The rules under test are the ones that would be embarrassing to get wrong
 * on a live marketplace: a vendor cannot rename themselves, cannot move their
 * own bank account, cannot reach another shop's staff, and a suspended member
 * loses access the moment the switch is thrown.
 */
final class StoreAndStaffFlowTest extends DatabaseTestCase
{
    private const VENDOR = 21;
    private const OTHER_VENDOR = 22;
    private const MANAGER = 7;

    private DbStoreRepository $stores;
    private DbStaffRepository $staff;
    private DbChangeRequestRepository $changes;
    private DbVendorRepository $vendors;
    private StaffAccess $access;
    private ManageStaff $manageStaff;
    private AcceptStaffInvitation $invitations;
    private UpdateStoreSettings $storeSettings;
    private FakeCapabilityChecker $capabilities;
    private FakeStaffUserDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([...M0002CreateVendorTables::TABLES, ...M0003CreateStoreAndStaffTables::TABLES] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $this->stores = new DbStoreRepository($db, $clock);
        $this->staff = new DbStaffRepository($db, $clock);
        $this->changes = new DbChangeRequestRepository($db, $clock);
        $this->vendors = new DbVendorRepository($db, $clock);
        $this->directory = new FakeStaffUserDirectory();
        $this->capabilities = new FakeCapabilityChecker(self::MANAGER, [VendorCapabilities::REVIEW]);
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);

        $this->access = new StaffAccess($this->staff, $this->vendors);
        $this->manageStaff = new ManageStaff($this->staff, $this->directory, $this->access, new SettingsService($options), $audit);
        $this->invitations = new AcceptStaffInvitation($this->staff, $this->directory, $audit);
        $this->storeSettings = new UpdateStoreSettings(
            $this->stores,
            $this->changes,
            $this->access,
            $audit,
            new MobileVerification(new NullOtpProvider())
        );

        // Two approved vendors, so "another shop" is a real shop.
        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', true, false);
        $this->vendors->upsertProfile(self::OTHER_VENDOR, 'داروخانه دو', true, false);
    }

    /**
     * A vendor saves their own settings, one tab at a time, and the name is
     * still not theirs to change.
     *
     * Rewritten in `alpha.41`, and the rewrite is the point. The old version
     * put `city` and `preparation_days` in ONE save with no tab — which is the
     * very shape that cannot happen through the page (five separate `<form>`s,
     * one posted) and the shape whose acceptance let the defect live. Each tab
     * is saved on its own now, and the second save is checked for not having
     * eaten the first.
     */
    public function testAVendorSavesTheirOwnSettingsButCannotRenameTheShop(): void
    {
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings('داروخانه یک'));

        $saved = $this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'general',
            'city' => 'تهران',
            'store_name' => 'نام دزدیده‌شده',
        ], [], []);
        self::assertTrue($saved->ok, $saved->code);

        $shipping = $this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'shipping',
            'preparation_days' => 2,
        ], [], []);
        self::assertTrue($shipping->ok, $shipping->code);

        $settings = $this->stores->find(self::VENDOR);
        self::assertSame('تهران', $settings?->city, 'the shipping save must not have emptied it');
        self::assertSame(2, $settings?->preparationDays);
        self::assertSame('داروخانه یک', $settings?->storeName, 'a save must never move the name');
    }

    /**
     * THE ACCEPTANCE TEST for §1: every tab filled with something distinct,
     * each tab saved on its own, and the row read back from MariaDB after
     * each one.
     *
     * The owner's reproduction, in one method: general saved, then shipping
     * saved, and the city and the introduction gone. On the `alpha.40` bytes
     * this fails at the first assertion after the shipping save.
     */
    public function testSavingOneTabLeavesEveryOtherTabExactlyWhereItWas(): void
    {
        $networks = ['instagram', 'telegram'];
        $carriers = ['post', 'tipax'];
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings('داروخانه یک'));

        // Four savable tabs, each with values nothing else would produce.
        $tabs = [
            ['tab' => 'general', 'city' => 'اصفهان', 'intro' => 'معرفی فروشگاه ما', 'logo_id' => 811, 'banner_id' => 812],
            ['tab' => 'shipping', 'preparation_days' => 4, 'origin_warehouse' => 'انبار مرکزی', 'carriers' => ['post']],
            ['tab' => 'closure', 'closed' => true, 'closed_from' => '2026-11-01', 'closed_to' => '2026-11-05',
             'reopen_message' => 'پنجم آبان باز می‌شویم'],
            ['tab' => 'social', 'social' => ['instagram' => 'https://instagram.test/shop']],
        ];
        foreach ($tabs as $input) {
            $saved = $this->storeSettings->save(self::VENDOR, self::VENDOR, $input, $networks, $carriers);
            self::assertTrue($saved->ok, $input['tab'] . ': ' . $saved->code);
        }

        // Read from the database, not from anything this test is holding.
        $s = $this->stores->find(self::VENDOR);
        self::assertSame('اصفهان', $s?->city, 'general survived three later saves');
        self::assertSame('معرفی فروشگاه ما', $s?->intro);
        self::assertSame(811, $s?->logoId, 'the logo is not cleared by another tab');
        self::assertSame(812, $s?->bannerId);
        self::assertSame(4, $s?->preparationDays, 'four, not back to nought');
        self::assertSame('انبار مرکزی', $s?->originWarehouse);
        self::assertSame(['post'], $s?->carriers, 'the carrier list is not emptied');
        self::assertTrue($s?->closed, 'the closure tick is not unticked by another tab');
        self::assertSame('2026-11-01', $s?->closedFrom);
        self::assertSame('2026-11-05', $s?->closedTo);
        self::assertSame('پنجم آبان باز می‌شویم', $s?->reopenMessage);
        self::assertSame(['instagram' => 'https://instagram.test/shop'], $s?->social, 'the social map is not emptied');
        self::assertSame('داروخانه یک', $s?->storeName);

        // And saving each tab AGAIN, in a different order, still changes only
        // its own fields — the state a vendor who revisits the page is in.
        foreach (array_reverse($tabs) as $input) {
            self::assertTrue($this->storeSettings->save(self::VENDOR, self::VENDOR, $input, $networks, $carriers)->ok);
        }
        $again = $this->stores->find(self::VENDOR);
        self::assertSame('اصفهان', $again?->city);
        self::assertSame(4, $again?->preparationDays);
        self::assertSame(['post'], $again?->carriers);
        self::assertSame(['instagram' => 'https://instagram.test/shop'], $again?->social);
    }

    /**
     * Clearing on purpose still clears — within the tab that owns the field.
     *
     * This is the half a «just keep everything that is missing» fix would
     * break, and it is why the tab has to be the discriminator: an unticked
     * checkbox and an empty text input send NOTHING, exactly like a field
     * belonging to another tab. Only the tab tells those two apart.
     */
    public function testClearingAFieldOnItsOwnTabStillClearsIt(): void
    {
        $networks = ['instagram', 'telegram'];
        $carriers = ['post', 'tipax'];
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings(
            'داروخانه یک',
            'اصفهان',
            'معرفی',
            0,
            0,
            4,
            'انبار مرکزی',
            ['post', 'tipax'],
            true,
            '2026-11-01',
            '2026-11-05',
            'پیام',
            ['instagram' => 'https://instagram.test/shop']
        ));

        // An empty text field on its own tab is a clear.
        self::assertTrue($this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'general', 'city' => '', 'intro' => '',
        ], $networks, $carriers)->ok);
        self::assertSame('', $this->stores->find(self::VENDOR)?->city);
        self::assertSame('', $this->stores->find(self::VENDOR)?->intro);

        // Every carrier unticked sends no `carriers` key at all, and on the
        // shipping tab that means none — not «leave them».
        self::assertTrue($this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'shipping', 'preparation_days' => 4, 'origin_warehouse' => 'انبار مرکزی',
        ], $networks, $carriers)->ok);
        self::assertSame([], $this->stores->find(self::VENDOR)?->carriers, 'unticking every box empties the list');

        // The closure tick, likewise — it is simply absent when unticked. The
        // two dates are text controls, so an emptied one arrives as `''`,
        // which is exactly what the route posts and what a clear means.
        self::assertTrue($this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'closure', 'closed_from' => '', 'closed_to' => '', 'reopen_message' => 'پیام',
        ], $networks, $carriers)->ok);
        $after = $this->stores->find(self::VENDOR);
        self::assertFalse($after?->closed, 'unticking «تعطیل است» reopens the shop');
        self::assertNull($after?->closedFrom);
        self::assertNull($after?->closedTo);

        // And an emptied URL on the social tab.
        self::assertTrue($this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'social', 'social' => ['instagram' => ''],
        ], $networks, $carriers)->ok);
        self::assertSame([], $this->stores->find(self::VENDOR)?->social);
    }

    /**
     * A field belonging to another tab is dropped even when it IS posted.
     *
     * The request is hand-made — a browser cannot produce it — and that is the
     * case worth refusing: the vendor's own page never showed `city` on the
     * shipping form, so a shipping save may not write it.
     */
    public function testAFieldInjectedFromAnotherTabIsIgnored(): void
    {
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings(
            'داروخانه یک', 'اصفهان', 'معرفی', 0, 0, 4
        ));

        $saved = $this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'shipping',
            'preparation_days' => 6,
            'origin_warehouse' => 'انبار دو',
            // Not on this tab's form. Posted anyway, and dropped by the
            // service rather than by the route — so the guard holds for any
            // caller, not only for the one that remembers to filter.
            'city' => 'شهر تزریقی',
            'intro' => '',
        ], [], []);

        self::assertTrue($saved->ok, $saved->code);
        $s = $this->stores->find(self::VENDOR);
        self::assertSame(6, $s?->preparationDays, 'its own field changed');
        self::assertSame('اصفهان', $s?->city, 'and the injected one did not');
        self::assertSame('معرفی', $s?->intro);
    }

    /**
     * Two browser tabs, each on a different section, saving one after the
     * other — and neither puts the other's fields back.
     *
     * This is the case input filtering alone does NOT fix. Both saves read the
     * row first; the second one holds a `$current` from before the first one
     * landed. While the write named all twelve columns, its own stale general
     * values went back on disk. It names only its own columns now, so the
     * ordering cannot matter.
     */
    public function testTwoTabsOpenAtOnceDoNotPutEachOthersFieldsBack(): void
    {
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings(
            'داروخانه یک', 'شهر قدیمی', 'معرفی قدیمی', 0, 0, 1
        ));

        // SCHEDULED, not raced, and named as such: the general save runs
        // immediately before the shipping save's own write, on the same
        // connection, so the shipping save is holding a `$current` from before
        // it — which is precisely what two browser tabs produce and what a
        // sequential pair of calls cannot.
        $interfering = new InterferingDatabase(new WpDatabase($this->wpdb));
        $shipping = new UpdateStoreSettings(
            new DbStoreRepository($interfering, new SystemClock()),
            $this->changes,
            $this->access,
            $this->auditLoggerFor(),
            new MobileVerification(new NullOtpProvider())
        );
        $interfering->before(['INSERT INTO', 'tmc_vendor_stores'], 1, function (): void {
            self::assertTrue($this->storeSettings->save(self::VENDOR, self::VENDOR, [
                'tab' => 'general', 'city' => 'شهر تازه', 'intro' => 'معرفی تازه',
            ], [], [])->ok);
        });

        self::assertTrue($shipping->save(self::VENDOR, self::VENDOR, [
            'tab' => 'shipping', 'preparation_days' => 7, 'origin_warehouse' => 'انبار',
        ], [], [])->ok);
        self::assertNotSame([], $interfering->fired, 'the interleaving has to have happened');

        $s = $this->stores->find(self::VENDOR);
        self::assertSame('شهر تازه', $s?->city, 'the shipping save did not restore the old city');
        self::assertSame('معرفی تازه', $s?->intro);
        self::assertSame(7, $s?->preparationDays);
    }

    /** A tab the page does not have writes nothing and says so. */
    public function testASaveNamingATabThatDoesNotExistIsRefused(): void
    {
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings('داروخانه یک', 'تهران'));

        $refused = $this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'tab' => 'invented',
            'city' => '',
        ], [], []);

        self::assertFalse($refused->ok);
        self::assertSame('bad_tab', $refused->code);
        self::assertSame('تهران', $this->stores->find(self::VENDOR)?->city, 'and nothing was written');
    }

    public function testRenamingGoesThroughTheManagerAndOnlyThenTakesEffect(): void
    {
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings('داروخانه یک'));

        $asked = $this->storeSettings->requestRename(self::VENDOR, self::VENDOR, 'داروخانه نو');
        self::assertTrue($asked->ok);
        self::assertSame('داروخانه یک', $this->stores->find(self::VENDOR)?->storeName);

        $again = $this->storeSettings->requestRename(self::VENDOR, self::VENDOR, 'یک نام سوم');
        self::assertFalse($again->ok);
        self::assertSame('change_already_pending', $again->code);

        $pending = $this->changes->pending();
        self::assertCount(1, $pending);
        $decision = (new ReviewChangeRequests($this->changes, $this->stores, $this->auditLoggerFor(), $this->capabilities))
            ->approve($pending[0]->id);

        self::assertTrue($decision->ok, $decision->code);
        self::assertSame('داروخانه نو', $this->stores->find(self::VENDOR)?->storeName);
    }

    public function testChangingTheBankAccountStopsSettlementUntilTheManagerDecides(): void
    {
        $asked = $this->storeSettings->requestBankChange(self::VENDOR, self::VENDOR, 'IR062960000000100324200001', 'علی رضایی', 0);

        self::assertTrue($asked->ok, $asked->code);
        self::assertSame('no', $asked->context['otp_available'], 'no provider, so no SMS step may be claimed');
        $bank = $this->stores->bank(self::VENDOR);
        self::assertTrue($bank['on_hold'], 'settlement stops the moment an IBAN is asked to change');
        self::assertSame('', $bank['iban'], 'the new IBAN is not written before approval');

        $pending = $this->changes->pending();
        (new ReviewChangeRequests($this->changes, $this->stores, $this->auditLoggerFor(), $this->capabilities))
            ->approve($pending[0]->id);

        $bank = $this->stores->bank(self::VENDOR);
        self::assertSame('IR062960000000100324200001', $bank['iban']);
        self::assertFalse($bank['on_hold'], 'approval releases the hold');
    }

    public function testARejectedBankChangeLeavesTheHoldInPlace(): void
    {
        $this->storeSettings->requestBankChange(self::VENDOR, self::VENDOR, 'IR062960000000100324200001', 'علی رضایی', 0);
        $pending = $this->changes->pending();

        $review = new ReviewChangeRequests($this->changes, $this->stores, $this->auditLoggerFor(), $this->capabilities);
        self::assertFalse($review->reject($pending[0]->id, '')->ok, 'a rejection without a reason is refused');
        self::assertTrue($review->reject($pending[0]->id, 'مدرک حساب خوانا نیست')->ok);

        $bank = $this->stores->bank(self::VENDOR);
        self::assertSame('', $bank['iban']);
        self::assertTrue($bank['on_hold']);
    }

    public function testAMalformedIbanIsRefusedBeforeAnythingIsWritten(): void
    {
        $result = $this->storeSettings->requestBankChange(self::VENDOR, self::VENDOR, 'IR12', 'علی رضایی', 0);

        self::assertFalse($result->ok);
        self::assertSame('bad_iban', $result->code);
        self::assertSame([], $this->changes->pending());
    }

    public function testInvitingSuspendingAndReinstatingOneColleague(): void
    {
        $invite = $this->manageStaff->invite(
            self::VENDOR,
            self::VENDOR,
            'سارا',
            'محمدی',
            'sara',
            'sara@example.test',
            '09120000001',
            StaffRolePreset::OrderAndShipping
        );
        self::assertTrue($invite->ok, $invite->code);
        $staffId = (int) $invite->context['staff_id'];
        $token = (string) $invite->context['token'];

        $member = $this->staff->find($staffId);
        self::assertSame(StaffStatus::Invited, $member?->status);
        self::assertNotSame($token, $this->rawStaffColumn($staffId, 'invite_hash'), 'only the hash is stored');

        // Invited is not access.
        self::assertFalse($this->access->can($member->staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit));

        $accepted = $this->invitations->accept($token, 'a-long-enough-password');
        self::assertTrue($accepted->ok, $accepted->code);
        self::assertSame(StaffStatus::Active, $this->staff->find($staffId)?->status);
        self::assertTrue($this->access->can($member->staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit));
        self::assertFalse($this->access->can($member->staffUserId, self::VENDOR, StaffArea::Finance, StaffLevel::View));

        // The same link cannot be used twice.
        self::assertFalse($this->invitations->accept($token, 'another-long-password')->ok);

        self::assertTrue($this->manageStaff->suspend(self::VENDOR, $staffId)->ok);
        self::assertFalse(
            $this->access->can($member->staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit),
            'suspension is immediate'
        );
        self::assertNotNull($this->staff->find($staffId), 'suspension must not delete the membership');

        self::assertTrue($this->manageStaff->reinstate(self::VENDOR, $staffId)->ok);
        self::assertTrue($this->access->can($member->staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit));
    }

    public function testStaffOfOneShopCanDoNothingInAnother(): void
    {
        $invite = $this->manageStaff->invite(self::VENDOR, self::VENDOR, 'سارا', 'محمدی', 'sara', 'sara@example.test', '09120000001', StaffRolePreset::StoreManager);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $staffUserId = $this->staff->find((int) $invite->context['staff_id'])?->staffUserId ?? 0;

        self::assertTrue($this->access->can($staffUserId, self::VENDOR, StaffArea::Product, StaffLevel::Edit));
        self::assertFalse($this->access->can($staffUserId, self::OTHER_VENDOR, StaffArea::Product, StaffLevel::View));
        self::assertSame(self::VENDOR, $this->access->storeFor($staffUserId));
    }

    public function testStaffCannotManageStaffOrStoreSettings(): void
    {
        $invite = $this->manageStaff->invite(self::VENDOR, self::VENDOR, 'سارا', 'محمدی', 'sara', 'sara@example.test', '09120000001', StaffRolePreset::StoreManager);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $staffUserId = $this->staff->find((int) $invite->context['staff_id'])?->staffUserId ?? 0;

        // The store-manager preset is the strongest one, and still stops here.
        self::assertFalse($this->access->canManageStore($staffUserId, self::VENDOR));
        $selfInvite = $this->manageStaff->invite($staffUserId, self::VENDOR, 'خود', 'ارتقا', 'selfup', 'self@example.test', '09120000009', StaffRolePreset::StoreManager);
        self::assertFalse($selfInvite->ok, 'no self-enrollment');
        self::assertSame('forbidden', $selfInvite->code);

        $save = $this->storeSettings->save($staffUserId, self::VENDOR, ['city' => 'شیراز'], [], []);
        self::assertFalse($save->ok);
    }

    public function testOnePersonBelongsToOneShop(): void
    {
        $first = $this->manageStaff->invite(self::VENDOR, self::VENDOR, 'سارا', 'محمدی', 'sara', 'sara@example.test', '09120000001', StaffRolePreset::Accountant);
        self::assertTrue($first->ok);

        // Same username, and the directory hands back the same user id.
        $second = $this->manageStaff->invite(self::OTHER_VENDOR, self::OTHER_VENDOR, 'سارا', 'محمدی', 'sara', 'sara2@example.test', '09120000002', StaffRolePreset::Accountant);

        self::assertFalse($second->ok);
        self::assertSame('username_taken', $second->code);
    }

    public function testTheSeatLimitCountsInvitationsToo(): void
    {
        $this->settingsWithMaxStaff(2);

        self::assertTrue($this->inviteNumbered(1)->ok);
        self::assertTrue($this->inviteNumbered(2)->ok);
        $third = $this->inviteNumbered(3);

        self::assertFalse($third->ok);
        self::assertSame('staff_limit', $third->code);
        self::assertSame(2, (int) $third->context['limit']);

        // A suspended seat frees one: suspension is how a vendor makes room.
        $members = $this->staff->forVendor(self::VENDOR);
        self::assertTrue($this->manageStaff->suspend(self::VENDOR, $members[0]->id)->ok);
        self::assertTrue($this->inviteNumbered(4)->ok);
    }

    public function testARoleCanBeChangedButNeverToNothing(): void
    {
        $invite = $this->inviteNumbered(1);
        $staffId = (int) $invite->context['staff_id'];

        self::assertTrue($this->manageStaff->changeRole(self::VENDOR, $staffId, StaffRolePreset::Accountant)->ok);
        self::assertSame(StaffRolePreset::Accountant, $this->staff->find($staffId)?->preset);

        $empty = $this->manageStaff->changeRole(self::VENDOR, $staffId, StaffRolePreset::Custom, []);
        self::assertFalse($empty->ok);
        self::assertSame('no_permissions', $empty->code);
    }

    public function testAnExpiredOrUnknownInvitationOpensNothing(): void
    {
        self::assertFalse($this->invitations->inspect('not-a-token')->ok);
        self::assertFalse($this->invitations->inspect(str_repeat('a', 48))->ok);
    }

    public function testSuspendingAVendorCutsTheVendorAndEveryStaffMemberOffAtOnce(): void
    {
        $invite = $this->inviteNumbered(1);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $staffUserId = $this->staff->find((int) $invite->context['staff_id'])?->staffUserId ?? 0;

        self::assertTrue($this->access->can($staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit));
        self::assertTrue($this->access->canManageStore(self::VENDOR, self::VENDOR));

        // What the manager's «تعلیق فروشنده» button does.
        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', false, false);

        self::assertFalse($this->access->vendorCanTrade(self::VENDOR));
        self::assertFalse($this->access->canManageStore(self::VENDOR, self::VENDOR), 'the vendor is out');
        self::assertFalse(
            $this->access->can($staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit),
            'and so is everyone who derived access from them'
        );
        self::assertNull($this->access->storeFor($staffUserId));

        // Nothing was deleted: the membership and its role are still there.
        $member = $this->staff->find((int) $invite->context['staff_id']);
        self::assertNotNull($member);
        self::assertSame(StaffStatus::Active, $member->status, 'the staff member was never the one suspended');

        // Reinstating the shop brings everyone back, without re-inviting.
        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', true, false);
        self::assertTrue($this->access->can($staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit));
    }

    /**
     * WHAT THIS PROVES: a suspended shop's colleague is still a colleague — and
     * still may do nothing.
     *
     * `storeFor()` says `null` for them, which is right and is what every gate
     * reads. But `null` there was also read as «this person belongs to no shop»,
     * and the only screen for somebody who belongs to no shop invites them to
     * register one: the owner found an employee of a suspended shop being
     * offered a vendor application for the shop they work in.
     *
     * So membership and permission are separate questions now, and this asserts
     * BOTH answers at once — the row is found, and not one gate moved. A fix that
     * widened access to make the screen work would fail here, which is the point
     * of asserting them together rather than in two tests.
     */
    public function testASuspendedShopsStaffAreStillMembersAndStillMayDoNothing(): void
    {
        $invite = $this->inviteNumbered(1);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $staffUserId = $this->staff->find((int) $invite->context['staff_id'])?->staffUserId ?? 0;

        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', false, false);

        // The display answer: who they are.
        $membership = $this->access->membershipFor($staffUserId);
        self::assertNotNull($membership, 'a suspended shop does not un-employ anybody');
        self::assertSame(self::VENDOR, $membership->vendorUserId, 'and it names which shop');
        self::assertSame(StaffStatus::Active, $membership->status, 'their own standing was never touched');

        // The operational answers: all of them still no.
        self::assertNull($this->access->storeFor($staffUserId), 'nothing to act in');
        self::assertFalse($this->access->can($staffUserId, self::VENDOR, StaffArea::Product, StaffLevel::View));
        self::assertFalse($this->access->can($staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit));
        self::assertFalse($this->access->canManageStore($staffUserId, self::VENDOR));
        self::assertSame([], $this->access->activeMembersOf(self::VENDOR), 'and nobody is addressable');

        // And membership answers about ONE shop, never about a neighbour's.
        self::assertNotSame(self::OTHER_VENDOR, $membership->vendorUserId);
        self::assertFalse($this->access->can($staffUserId, self::OTHER_VENDOR, StaffArea::Product, StaffLevel::View));

        // Reinstating is one field, and it restores the operational answer
        // without touching the membership that was there all along.
        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', true, false);
        self::assertSame(self::VENDOR, $this->access->storeFor($staffUserId));
        self::assertTrue($this->access->can($staffUserId, self::VENDOR, StaffArea::Product, StaffLevel::View));
        self::assertSame(
            self::VENDOR,
            $this->access->membershipFor($staffUserId)?->vendorUserId,
            'the same row, before and after'
        );
    }

    /**
     * WHAT THIS PROVES: membership is not a back door.
     *
     * A member whose OWN standing is suspended is found by `membershipFor()` —
     * so the screen can say «your access here is paused» instead of «you work
     * nowhere» — and refused by every gate.
     */
    public function testASuspendedMembersRowIsReadableAndGrantsNothing(): void
    {
        $invite = $this->inviteNumbered(1);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $staffId = (int) $invite->context['staff_id'];
        $staffUserId = $this->staff->find($staffId)?->staffUserId ?? 0;

        self::assertTrue($this->manageStaff->suspend(self::VENDOR, $staffId)->ok);

        $membership = $this->access->membershipFor($staffUserId);
        self::assertNotNull($membership);
        self::assertSame(StaffStatus::Suspended, $membership->status, 'and the row says which state they are in');
        self::assertSame(self::VENDOR, $membership->vendorUserId);

        self::assertNull($this->access->storeFor($staffUserId));
        self::assertFalse($this->access->can($staffUserId, self::VENDOR, StaffArea::Product, StaffLevel::View));
        self::assertNotContains($staffUserId, $this->access->activeMembersOf(self::VENDOR));
    }

    public function testASuspendedVendorsStaffCannotReachAnotherShopEither(): void
    {
        $invite = $this->inviteNumbered(1);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $staffUserId = $this->staff->find((int) $invite->context['staff_id'])?->staffUserId ?? 0;
        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', false, false);

        self::assertFalse($this->access->can($staffUserId, self::OTHER_VENDOR, StaffArea::Product, StaffLevel::View));
    }

    // ------------------------------------------------------------- helpers

    // ------------------------------------------------ staff activity report

    /**
     * WHAT THIS PROVES: the owner can see what each of their staff has
     * actually DONE, and can see it only for their own shop.
     *
     * Until now Master §۵ was answered by `last_seen_at` alone — a stamp that
     * says somebody opened a page. «Logged in» and «did the job» are
     * different questions, and the audit log already holds the second one, so
     * the report reads it rather than keeping a second copy of the count.
     */
    public function testTheOwnerSeesWhatEachStaffMemberActuallyDid(): void
    {
        // Invited AND accepted: only an accepted invitation has a WordPress
        // user behind it, and only a user can be an actor in the trail.
        $invite = $this->inviteNumbered(1);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $member = $this->staff->find((int) $invite->context['staff_id']);
        self::assertNotNull($member);
        $actor = $member->staffUserId;
        self::assertGreaterThan(0, $actor);

        // Three real audit lines for this person, one for somebody else.
        // Real catalogue events on purpose: AuditEventSanitizer DROPS any
        // event type not on the allowlist, so an invented name writes nothing
        // and the report would be counting an empty table.
        $audit = $this->auditLoggerFor();
        $audit->log(AuditEventCatalog::PRODUCT_SAVED, $actor, 'product', '11', []);
        $audit->log(AuditEventCatalog::PRODUCT_SAVED, $actor, 'product', '12', []);
        $audit->log(AuditEventCatalog::PRODUCT_SUBMITTED, $actor, 'product', '13', []);
        $audit->log(AuditEventCatalog::PRODUCT_SAVED, self::MANAGER, 'product', '99', []);
        $mine = $this->activityReport()->forVendor(self::VENDOR, self::VENDOR)['rows'][0]['actions'];

        $report = $this->activityReport()->forVendor(self::VENDOR, self::VENDOR);

        self::assertTrue($report['allowed']);
        self::assertSame('ok', $report['reason']);
        self::assertCount(1, $report['rows']);
        self::assertSame($actor, $report['rows'][0]['staff_user_id']);
        // At least the three written here. Accepting the invitation also logs
        // a line as this same person, so an exact total would be asserting
        // the invitation flow rather than the report.
        self::assertGreaterThanOrEqual(3, $mine);
        self::assertSame(
            AuditEventCatalog::PRODUCT_SUBMITTED,
            $report['rows'][0]['last_event'],
            'newest first'
        );
        self::assertNotSame('', $report['rows'][0]['last_at']);

        // And the manager's own line is not in this shop's total: the report
        // counts only actors who are this shop's staff.
        $everything = (new WpAuditRepository($this->wpdb))->count([]);
        self::assertGreaterThan(
            $mine,
            $everything,
            'the site-wide trail has more in it than this shop\'s staff did — so the report really is filtering'
        );
    }

    /**
     * And it is scoped: another shop's owner sees nothing of this one.
     *
     * The audit log is site-wide. An unscoped read here would hand a vendor
     * the manager's activity and every other shop's, which is the failure
     * that matters more than any counting bug.
     */
    public function testTheActivityReportIsRefusedToAnotherShopAndToStaff(): void
    {
        $invite = $this->inviteNumbered(1);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $member = $this->staff->find((int) $invite->context['staff_id']);
        self::assertNotNull($member);
        self::assertGreaterThan(0, $member->staffUserId);
        $this->auditLoggerFor()->log(AuditEventCatalog::PRODUCT_SAVED, $member->staffUserId, 'product', '11', []);

        // The other shop's owner: allowed to ask, told nothing about us.
        $other = $this->activityReport()->forVendor(self::OTHER_VENDOR, self::OTHER_VENDOR);
        self::assertTrue($other['allowed']);
        self::assertSame([], $other['rows'], 'another shop has no staff of its own and must see none of ours');

        // The other shop's owner asking about OUR shop: refused outright.
        $reach = $this->activityReport()->forVendor(self::OTHER_VENDOR, self::VENDOR);
        self::assertFalse($reach['allowed']);
        self::assertSame('forbidden', $reach['reason']);
        self::assertSame([], $reach['rows']);

        // A staff member asking about the shop they work in: also refused —
        // the same gate that keeps them off the staff page.
        $asStaff = $this->activityReport()->forVendor($member->staffUserId, self::VENDOR);
        self::assertFalse($asStaff['allowed']);
        self::assertSame('forbidden', $asStaff['reason']);
    }

    /**
     * A shop whose invitations are all unaccepted gets «no activity», not an
     * unfiltered read.
     *
     * An invited row has no WordPress user yet, so the actor list is empty —
     * and an empty `IN ()` list means «no restriction», which would return
     * the whole site's trail. Saying so explicitly is the guard.
     */
    /**
     * WHAT THIS PROVES: the counts are exact past the page size, for several
     * people at once.
     *
     * `search()` caps its page at 200 rows. The first version of this report
     * asked it for 2000 and tallied the result in PHP, so a shop busier than
     * 200 lines was counted at 200 — and the «this is only a floor» warning,
     * written as `count($rows) >= 2000`, could never fire. It understated the
     * work and reported itself complete, which is worse than either alone.
     *
     * So this writes MORE than that cap, spread over two people with
     * different totals, and asserts both exactly. A test that stayed under
     * 200 would pass against the broken version and prove nothing.
     */
    public function testCountsAreExactWellPastTheSearchPageSize(): void
    {
        $first = $this->acceptedStaffUser(1);
        $second = $this->acceptedStaffUser(2);

        // Accepting an invitation is itself a line in the trail, written by
        // the person accepting — so «before» is not zero, and assuming it was
        // is how the first version of this test expected 260 and met 261.
        // Measure the delta instead of asserting an absolute nobody set.
        $before = [];
        foreach ($this->activityReport()->forVendor(self::VENDOR, self::VENDOR)['rows'] as $row) {
            $before[$row['staff_user_id']] = $row['actions'];
        }

        // 260 and 45: both sides of the 200 cap, and different from each
        // other so one cannot be mistaken for the other.
        $audit = $this->auditLoggerFor();
        for ($i = 0; $i < 260; $i++) {
            $audit->log(AuditEventCatalog::PRODUCT_SAVED, $first, 'product', (string) $i, []);
        }
        for ($i = 0; $i < 45; $i++) {
            $audit->log(AuditEventCatalog::PRODUCT_SUBMITTED, $second, 'product', (string) $i, []);
        }
        // And somebody who is not this shop's staff, doing more than either.
        for ($i = 0; $i < 300; $i++) {
            $audit->log(AuditEventCatalog::PRODUCT_SAVED, self::MANAGER, 'product', (string) $i, []);
        }

        $report = $this->activityReport()->forVendor(self::VENDOR, self::VENDOR);
        self::assertTrue($report['allowed']);

        $byUser = [];
        foreach ($report['rows'] as $row) {
            $byUser[$row['staff_user_id']] = $row;
        }

        // The point of the whole test: 260 more, not «up to 200 in total».
        self::assertSame(
            $before[$first] + 260,
            $byUser[$first]['actions'] ?? -1,
            'the count stopped at the search page size instead of counting the trail'
        );
        self::assertSame($before[$second] + 45, $byUser[$second]['actions'] ?? -1);
        self::assertGreaterThan(
            200,
            $byUser[$first]['actions'],
            'sanity: this test is worthless unless it really crosses the page size'
        );

        // Busiest first, and the manager's 300 lines are in neither total.
        self::assertSame($first, $report['rows'][0]['staff_user_id'], 'busiest first');
        self::assertArrayNotHasKey(self::MANAGER, $byUser);

        // Each person's last action is their OWN last one, not the newest in
        // the table — the manager wrote after both of them.
        self::assertSame(AuditEventCatalog::PRODUCT_SAVED, $byUser[$first]['last_event']);
        self::assertSame(AuditEventCatalog::PRODUCT_SUBMITTED, $byUser[$second]['last_event']);
    }

    /** An invited-and-accepted staff member, returned as their WordPress user id. */
    private function acceptedStaffUser(int $n): int
    {
        $invite = $this->inviteNumbered($n);
        self::assertTrue($invite->ok, (string) $invite->code);
        $this->invitations->accept((string) $invite->context['token'], 'a-long-enough-password');
        $member = $this->staff->find((int) $invite->context['staff_id']);
        self::assertNotNull($member);
        self::assertGreaterThan(0, $member->staffUserId);
        return $member->staffUserId;
    }

    public function testAShopWithNoStaffReadsNobodyElsesTrail(): void
    {
        // Nobody invited at all. The guard that matters is that an empty
        // actor set becomes «no rows» rather than «no restriction»: an empty
        // `IN ()` list would match the whole site's trail.
        //
        // Note the WordPress account here is created at INVITE time, not at
        // acceptance — so an invited-but-unaccepted member already has a user
        // id, and the emptiness this guards has to come from having no staff.
        $this->auditLoggerFor()->log(AuditEventCatalog::PRODUCT_SAVED, self::MANAGER, 'product', '99', []);

        $report = $this->activityReport()->forVendor(self::VENDOR, self::VENDOR);

        self::assertTrue($report['allowed']);
        self::assertSame('no_staff', $report['reason']);
        self::assertSame([], $report['rows'], 'an empty actor list must not read the site-wide log');
    }

    private function activityReport(): \Tecteb\Marketplace\Modules\Vendor\Application\StaffActivityReport
    {
        return new \Tecteb\Marketplace\Modules\Vendor\Application\StaffActivityReport(
            $this->staff,
            new WpAuditRepository($this->wpdb),
            $this->access,
            new SystemClock()
        );
    }

    private function inviteNumbered(int $n): \Tecteb\Marketplace\Modules\Vendor\Application\OperationResult
    {
        return $this->manageStaff->invite(
            self::VENDOR,
            self::VENDOR,
            'همکار',
            (string) $n,
            'staff' . $n,
            'staff' . $n . '@example.test',
            '0912000000' . $n,
            StaffRolePreset::OrderAndShipping
        );
    }

    private function settingsWithMaxStaff(int $max): void
    {
        $service = new SettingsService(new WpOptionStore());
        $current = $service->load();
        $service->save(new \Tecteb\Marketplace\Core\Config\Settings(
            $current->commissionRateBp,
            $current->settlementDelayDays,
            $max,
            $current->environmentOverride
        ));
    }

    private function auditLoggerFor(): AuditLogger
    {
        return new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock());
    }

    private function rawStaffColumn(int $staffId, string $column): string
    {
        $table = $this->wpdb->prefix . M0003CreateStoreAndStaffTables::STAFF;
        $stmt = $this->wpdb->pdo()->prepare("SELECT `{$column}` FROM `{$table}` WHERE id = ?");
        $stmt->execute([$staffId]);
        return (string) $stmt->fetchColumn();
    }
}
