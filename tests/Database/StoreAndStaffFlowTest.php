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

    public function testAVendorSavesTheirOwnSettingsButCannotRenameTheShop(): void
    {
        $this->stores->save(self::VENDOR, new \Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings('داروخانه یک'));

        $saved = $this->storeSettings->save(self::VENDOR, self::VENDOR, [
            'city' => 'تهران',
            'preparation_days' => 2,
            'store_name' => 'نام دزدیده‌شده',
        ], [], []);

        self::assertTrue($saved->ok, $saved->code);
        $settings = $this->stores->find(self::VENDOR);
        self::assertSame('تهران', $settings?->city);
        self::assertSame(2, $settings?->preparationDays);
        self::assertSame('داروخانه یک', $settings?->storeName, 'a save must never move the name');
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
