<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
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
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeStaffUserDirectory;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;

/**
 * THE TWO POINTS WHERE A STAFF ACCOUNT AND ITS MEMBERSHIP CAN COME APART.
 *
 * `StoreAndStaffFlowTest` already proves the happy path and the permission
 * boundaries — invite, accept, suspend, reinstate, one shop's staff never
 * reaching another's. This file is about the two failures §7 claim 9 names,
 * and only those: a creation that gets half way, and an activation that two
 * callers both think they won.
 *
 * Both are measured against the rows that are really in the table afterwards,
 * because both defects looked like successes from inside the service.
 */
final class StaffAccountIntegrityTest extends DatabaseTestCase
{
    private const VENDOR = 41;

    private DbStaffRepository $staff;
    private DbVendorRepository $vendors;
    private StaffAccess $access;
    private FakeStaffUserDirectory $directory;
    private AuditLogger $logger;
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        foreach ([...M0002CreateVendorTables::TABLES, ...M0003CreateStoreAndStaffTables::TABLES] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->staff = new DbStaffRepository($this->db, $clock);
        $this->vendors = new DbVendorRepository($this->db, $clock);
        $this->directory = new FakeStaffUserDirectory();
        $this->access = new StaffAccess($this->staff, $this->vendors);
        $this->logger = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);

        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه چهل‌ویک', true, false);
    }

    // ---------------------------------------------------------------- claim 9

    /**
     * ⛔ THE ACCOUNT IS TAKEN BACK WHEN ITS MEMBERSHIP CANNOT BE WRITTEN.
     *
     * Two writes, in this order: the WordPress user, then the membership row.
     * Before this round the second one failing returned `storage_failed` and
     * left the first one standing — a user with no shop access, a password
     * nobody holds, and an email address that `emailTaken()` now answers true
     * for, so the person it was made for could never be invited again.
     */
    public function testAFailedMembershipWriteTakesTheAccountBackAgain(): void
    {
        $db = new FailingDatabase($this->db);
        $db->failWhen(['INSERT', 'tmc_vendor_staff']);
        $service = $this->serviceOn($db);

        $result = $service->invite(
            self::VENDOR,
            self::VENDOR,
            'سارا',
            'محمدی',
            'sara',
            'sara@example.test',
            '09120000001',
            StaffRolePreset::OrderAndShipping
        );

        self::assertFalse($result->ok);
        self::assertSame('storage_failed', $result->code);
        self::assertSame([], $this->staff->forVendor(self::VENDOR), 'no membership row');
        self::assertSame([], $this->directory->users, 'and no account left behind');
        self::assertFalse($this->directory->emailTaken('sara@example.test'), 'so the same person can be invited again');
        self::assertCount(1, $this->directory->deleteAttempts, 'the compensation ran exactly once');
    }

    /**
     * ⛔ AND WHEN THE COMPENSATION ITSELF FAILS, IT IS SAID BY NAME.
     *
     * `alpha.90`'s rule: a compensation is a write and can fail, so its answer
     * is read. The two outcomes are different facts and get different codes —
     * `storage_failed` means nothing was left, this one means a WordPress user
     * is still on the site with no way in, and the sentence carries the id a
     * manager needs to finish it in wp-admin.
     */
    public function testAnAccountThatCouldNotBeTakenBackIsNamedRatherThanHidden(): void
    {
        $db = new FailingDatabase($this->db);
        $db->failWhen(['INSERT', 'tmc_vendor_staff']);
        $this->directory->refuseDelete = true;
        $service = $this->serviceOn($db);

        $result = $service->invite(
            self::VENDOR,
            self::VENDOR,
            'رضا',
            'کریمی',
            'reza',
            'reza@example.test',
            '09120000002',
            StaffRolePreset::OrderAndShipping
        );

        self::assertFalse($result->ok);
        self::assertSame('storage_failed_account_left', $result->code);
        self::assertSame('reza', $result->context['username'] ?? null);
        self::assertGreaterThan(0, (int) ($result->context['user_id'] ?? 0));
        self::assertSame([], $this->staff->forVendor(self::VENDOR), 'still no membership row');
        self::assertCount(1, $this->directory->users, 'the account really is still there');

        // ⛔ AND THE ID REACHES THE AUDIT ROW, read back out of the table.
        // `AuditEventCatalog::allowlist()` drops keys it does not name WITHOUT
        // saying so (`alpha.91`), so «we wrote it» proves nothing; this asks
        // the row.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT payload FROM ' . $this->wpdb->prefix . M0001CreateAuditTable::TABLE_SUFFIX . ' WHERE event_type = %s',
                AuditEventCatalog::VENDOR_STAFF_INVITED
            ),
            'ARRAY_A'
        );
        self::assertCount(1, $rows);
        $payload = json_decode((string) $rows[0]['payload'], true);
        self::assertSame('account_left', $payload['status'] ?? null);
        self::assertSame((int) $result->context['user_id'], (int) ($payload['staff_user_id'] ?? 0));
        self::assertArrayNotHasKey('username', $payload, 'the login name is on the screen, not in the row');
    }

    /**
     * The control, and it is the half that keeps the compensation narrow: a
     * membership write that SUCCEEDS must leave the account alone. A guard
     * that removed accounts on the happy path would be far worse than the
     * defect it replaced (`alpha.60`).
     */
    public function testASuccessfulInvitationNeverTouchesTheAccountItMade(): void
    {
        $service = $this->serviceOn($this->db);

        $result = $service->invite(
            self::VENDOR,
            self::VENDOR,
            'مینا',
            'احمدی',
            'mina',
            'mina@example.test',
            '09120000003',
            StaffRolePreset::OrderAndShipping
        );

        self::assertTrue($result->ok, $result->code);
        self::assertCount(1, $this->staff->forVendor(self::VENDOR));
        self::assertCount(1, $this->directory->users);
        self::assertSame([], $this->directory->deleteAttempts, 'nothing was asked to be removed');
    }

    // --------------------------------------------------------------- claim 2

    /**
     * ⛔ ONE LINK, ONE WINNER — AND THE LOSER IS TOLD.
     *
     * `accept()` reads the invitation, sets a password, then activates. Two
     * accepts of the same link that both get past the read is a window of two
     * statements, and `activate()` used to answer `!== null`, which `alpha.8`
     * records as «a zero-row UPDATE is a success». So both callers set a
     * password and both were told «activated», while only the second password
     * works — and the token is single-use only AFTER one of them lands.
     *
     * The interleaving is scheduled rather than raced for: the second accept
     * runs in the gap immediately before the first one's UPDATE, every run, in
     * the same order.
     */
    public function testTwoAcceptsOfOneLinkLeaveExactlyOneWinner(): void
    {
        $service = $this->serviceOn($this->db);
        $invite = $service->invite(
            self::VENDOR,
            self::VENDOR,
            'کاوه',
            'نوری',
            'kaveh',
            'kaveh@example.test',
            '09120000004',
            StaffRolePreset::OrderAndShipping
        );
        self::assertTrue($invite->ok, $invite->code);
        $staffId = (int) $invite->context['staff_id'];
        $token = (string) $invite->context['token'];
        $userId = $this->staff->find($staffId)?->staffUserId ?? 0;
        self::assertGreaterThan(0, $userId);

        $interfering = new InterferingDatabase($this->db);
        $second = null;
        $interfering->before(['UPDATE', 'tmc_vendor_staff', 'activated_at'], 1, function () use (&$second, $token): void {
            // The other caller, all the way through, on the untouched gateway.
            $second = $this->invitationsOn($this->db)->accept($token, 'second-caller-password');
        });

        $first = $this->invitationsOn($interfering)->accept($token, 'first-caller-password');

        // `fired` records the SQL the hook fired on, not the needles. Asked
        // for by name, because a hook that never fires turns every assertion
        // below into a statement about the ordinary single accept.
        self::assertCount(1, $interfering->fired, 'the window was really entered');
        self::assertStringContainsString('activated_at', $interfering->fired[0]);
        self::assertNotNull($second);
        self::assertTrue($second->ok, 'the caller that got there first wins: ' . $second->code);
        self::assertFalse($first->ok, 'and the other one is told, rather than told it worked');
        self::assertSame('storage_failed', $first->code);

        // One activation, and the password that works is the winner's.
        self::assertSame(StaffStatus::Active, $this->staff->find($staffId)?->status);
        self::assertSame('second-caller-password', $this->directory->users[$userId]['password'] ?? null);
        self::assertSame('', $this->rawInviteHash($staffId), 'the token is burnt exactly once');
    }

    /**
     * And the same guard from the other side: a membership that is no longer
     * invited — suspended between the click and the post, say — cannot be
     * activated by a link that is still in somebody's inbox.
     */
    public function testAMembershipThatIsNoLongerInvitedCannotBeActivated(): void
    {
        $service = $this->serviceOn($this->db);
        $invite = $service->invite(
            self::VENDOR,
            self::VENDOR,
            'نیما',
            'شریفی',
            'nima',
            'nima@example.test',
            '09120000005',
            StaffRolePreset::OrderAndShipping
        );
        $staffId = (int) $invite->context['staff_id'];
        $member = $this->staff->find($staffId);
        self::assertNotNull($member);

        self::assertTrue($service->suspend(self::VENDOR, $staffId)->ok);
        self::assertFalse($this->staff->activate($staffId), 'a suspended seat is not an invitation');
        self::assertSame(StaffStatus::Suspended, $this->staff->find($staffId)?->status);
        self::assertFalse(
            $this->access->can($member->staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit),
            'and no access came out of the refused activation'
        );
    }

    /** The ordinary single accept still works, unchanged. */
    public function testOneAcceptActivatesExactlyAsBefore(): void
    {
        $service = $this->serviceOn($this->db);
        $invite = $service->invite(
            self::VENDOR,
            self::VENDOR,
            'الهام',
            'رستمی',
            'elham',
            'elham@example.test',
            '09120000006',
            StaffRolePreset::OrderAndShipping
        );
        $staffId = (int) $invite->context['staff_id'];
        $member = $this->staff->find($staffId);
        self::assertNotNull($member);

        $accepted = $this->invitationsOn($this->db)->accept((string) $invite->context['token'], 'a-long-enough-password');

        self::assertTrue($accepted->ok, $accepted->code);
        self::assertSame(StaffStatus::Active, $this->staff->find($staffId)?->status);
        self::assertTrue($this->access->can($member->staffUserId, self::VENDOR, StaffArea::Order, StaffLevel::Edit));
        self::assertFalse($this->access->can($member->staffUserId, self::VENDOR, StaffArea::Finance, StaffLevel::View));
    }

    // --------------------------------------------------------------- claim 8

    /**
     * ⛔ A DUPLICATE EMAIL IS REFUSED BEFORE ANYTHING IS WRITTEN.
     *
     * `username_taken` had a test; `email_taken` did not, and the two are not
     * the same refusal — a person can be invited under a second username with
     * the same address, and then WordPress has two accounts for one inbox and
     * the password-reset link is a coin toss. It is also the half that the
     * compensation above exists to protect: an orphaned account makes
     * `emailTaken()` answer true forever, so this refusal is the one that
     * would have locked somebody out.
     */
    public function testASecondInvitationToTheSameAddressIsRefusedAndWritesNothing(): void
    {
        $service = $this->serviceOn($this->db);
        self::assertTrue($service->invite(
            self::VENDOR,
            self::VENDOR,
            'شیما',
            'عباسی',
            'shima',
            'shared@example.test',
            '09120000007',
            StaffRolePreset::OrderAndShipping
        )->ok);

        $second = $service->invite(
            self::VENDOR,
            self::VENDOR,
            'شیما',
            'عباسی',
            'shima-two',
            'shared@example.test',
            '09120000008',
            StaffRolePreset::OrderAndShipping
        );

        self::assertFalse($second->ok);
        self::assertSame('email_taken', $second->code);
        self::assertCount(1, $this->staff->forVendor(self::VENDOR), 'one membership row');
        self::assertCount(1, $this->directory->users, 'and no second account was made before the refusal');
    }

    // ------------------------------------------------------------- fixtures

    private function serviceOn(\Tecteb\Marketplace\Contracts\DatabaseInterface $db): ManageStaff
    {
        return new ManageStaff(
            new DbStaffRepository($db, new SystemClock()),
            $this->directory,
            $this->access,
            new SettingsService(new WpOptionStore()),
            $this->logger
        );
    }

    private function invitationsOn(\Tecteb\Marketplace\Contracts\DatabaseInterface $db): AcceptStaffInvitation
    {
        return new AcceptStaffInvitation(
            new DbStaffRepository($db, new SystemClock()),
            $this->directory,
            $this->logger
        );
    }

    private function rawInviteHash(int $staffId): string
    {
        return (string) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT invite_hash FROM ' . $this->wpdb->prefix . M0003CreateStoreAndStaffTables::STAFF . ' WHERE id = %d',
                $staffId
            )
        );
    }
}
