<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Migration\Application\GrantImportedStaff;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\StaffRoleMap;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeStaffUserDirectory;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;
use Tecteb\Marketplace\Tests\Support\InMemoryShopRecords;
use Tecteb\Marketplace\Tests\Support\InMemoryStaffRepository;
use Tecteb\Marketplace\Tests\Support\RecordingAuditRepository;

/**
 * The line between «Dokan had this» and «this works here».
 *
 * Every test below is about one sentence: an imported record grants nothing
 * and owes nothing until a person says otherwise. The tests are written
 * against the outcomes a caller sees, not the internals, because the risk here
 * is not a wrong branch — it is a right-looking default that quietly hands
 * somebody access.
 */
final class ImportedHistoryIsNotCapabilityTest extends TestCase
{
    private InMemoryOptionStore $options;
    private InMemoryShopRecords $records;
    private InMemoryStaffRepository $staff;
    private FakeStaffUserDirectory $users;
    private FakeCapabilityChecker $caps;

    protected function setUp(): void
    {
        $this->options = new InMemoryOptionStore();
        $this->records = new InMemoryShopRecords();
        $this->staff = new InMemoryStaffRepository();
        $this->users = new FakeStaffUserDirectory();
        $this->caps = new FakeCapabilityChecker(9, [Capabilities::REVIEW_VENDOR]);
    }

    // ---------------------------------------------------------------- roles

    public function testAnUnmappedDokanRoleGrantsNothingAndIsNotSilent(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');

        $plan = $this->grantService()->plan(7);

        self::assertCount(1, $plan);
        self::assertSame(GrantImportedStaff::AWAITING_DECISION, $plan[0]['verdict']);
        self::assertSame('', $plan[0]['preset'], 'no preset may be guessed for an unmapped role');
        self::assertSame(
            [['role' => 'vendor_staff', 'people' => 1]],
            (new StaffRoleMap($this->options))->undecided($this->records->staffForVendor(7)),
            'the person must be nameable, not lost'
        );
    }

    public function testGrantingWritesNoMembershipForAnUnmappedRole(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');

        $result = $this->grantService()->grant(7);

        self::assertSame(1, $result[GrantImportedStaff::AWAITING_DECISION]);
        self::assertSame(0, $result[GrantImportedStaff::GRANTED]);
        self::assertSame([], $this->staff->members, 'an undecided role must leave no row at all');
    }

    public function testADeclinedRoleIsToldApartFromAnUndecidedOne(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');
        $this->map(['vendor_staff' => StaffRoleMap::DECLINED]);

        $result = $this->grantService()->grant(7);

        self::assertSame(1, $result[GrantImportedStaff::DECLINED]);
        self::assertSame(0, $result[GrantImportedStaff::AWAITING_DECISION]);
        self::assertSame([], $this->staff->members);
        self::assertTrue((new StaffRoleMap($this->options))->isDecided('vendor_staff'));
    }

    public function testAMappedRoleArrivesInvitedAndCannotActYet(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');
        $this->map(['vendor_staff' => StaffRolePreset::OrderAndShipping->value]);
        $this->users->users[501] = ['username' => 'kept', 'email' => 'a@b.test', 'password' => ''];

        $result = $this->grantService()->grant(7);

        self::assertSame(1, $result[GrantImportedStaff::GRANTED]);
        $member = $this->staff->findByUser(501);
        self::assertNotNull($member);
        self::assertSame(StaffStatus::Invited, $member->status);
        self::assertFalse($member->status->canAct(), 'an imported member must not be live on arrival');
        self::assertSame(StaffRolePreset::OrderAndShipping, $member->preset);
    }

    public function testNoTokenExistsThatCouldActivateAnImportedMember(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');
        $this->map(['vendor_staff' => StaffRolePreset::CustomerSupport->value]);
        $this->users->users[501] = ['username' => 'kept', 'email' => 'a@b.test', 'password' => ''];
        $this->grantService()->grant(7);

        self::assertNull($this->staff->findByInviteHash(''), 'an empty hash must match nothing');
        self::assertNull($this->staff->findByInviteHash(str_repeat('0', 64)));
    }

    public function testConfirmingIsWhatMakesAnImportedMemberAbleToAct(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');
        $this->map(['vendor_staff' => StaffRolePreset::OrderAndShipping->value]);
        $this->users->users[501] = ['username' => 'kept', 'email' => 'a@b.test', 'password' => ''];
        $service = $this->grantService();
        $service->grant(7);
        $staffId = $this->staff->findByUser(501)?->id ?? 0;

        self::assertSame(GrantImportedStaff::GRANTED, $service->confirm($staffId));
        self::assertTrue($this->staff->find($staffId)?->status->canAct());
    }

    public function testAPersonAlreadyAttachedToAShopIsRefusedRatherThanMoved(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');
        $this->map(['vendor_staff' => StaffRolePreset::StoreManager->value]);
        $this->users->users[501] = ['username' => 'kept', 'email' => 'a@b.test', 'password' => ''];
        $this->staff->adopt(99, 501, 'Elsewhere', 'elsewhere', 'a@b.test', StaffRolePreset::Accountant, StaffRolePreset::Accountant->permissions());

        $result = $this->grantService()->grant(7);

        self::assertSame(1, $result[GrantImportedStaff::ALREADY_STAFF]);
        self::assertSame(99, $this->staff->findByUser(501)?->vendorUserId, 'the existing membership must not be re-pointed');
    }

    public function testAMissingWordPressAccountIsNamedNotInvented(): void
    {
        $this->recordStaff(7, 501, 'vendor_staff');
        $this->map(['vendor_staff' => StaffRolePreset::StoreManager->value]);

        $result = $this->grantService()->grant(7);

        self::assertSame(1, $result[GrantImportedStaff::NO_ACCOUNT]);
        self::assertSame([], $this->staff->members);
        self::assertSame([], $this->users->users, 'no account may be created to fill the gap');
    }

    public function testNoMappedPresetCanEditMoney(): void
    {
        foreach (StaffRoleMap::targets() as $target) {
            if ($target === StaffRoleMap::DECLINED) {
                continue;
            }
            $preset = StaffRolePreset::from($target);
            self::assertFalse(
                $preset->permissions()->allows(StaffArea::Finance, StaffLevel::Edit),
                $target . ' must not be offerable as a target that can change money'
            );
        }
    }

    public function testCustomIsNotOfferableAsAMapTarget(): void
    {
        self::assertNotContains(StaffRolePreset::Custom->value, StaffRoleMap::targets());
        self::assertFalse((new StaffRoleMap($this->options))->save(['x' => StaffRolePreset::Custom->value]) && (new StaffRoleMap($this->options))->isDecided('x'));
    }

    // -------------------------------------------------------------- finance

    public function testAnImportedBalanceOwesNothingUntilSomebodySays(): void
    {
        $this->balance(7, '5000.0000', '0');

        $report = $this->financeService()->forVendor(7);

        self::assertSame(ReconcileDokanFinance::OPEN, $report['handover']['decision']);
        self::assertFalse($report['complete']);
        self::assertSame([7], $this->financeService()->stillOpen());
    }

    public function testClosingBalanceIsDokansArithmeticAndNotRestated(): void
    {
        // 5,000 earned, 2,000 already withdrawn: Dokan books the withdrawal as
        // a debit, so its own closing figure is 3,000.
        $this->balance(7, '5000.0000', '0', 'dokan_orders');
        $this->balance(7, '0', '2000.0000', ReconcileDokanFinance::WITHDRAW_TYPE);

        $report = $this->financeService()->forVendor(7);

        self::assertSame('3000.0000', $report['dokan']['closing']);
        self::assertSame(1, $report['already_counted_once']['rows']);
        self::assertSame('2000.0000', $report['already_counted_once']['debit']);
    }

    public function testTheWithdrawalTotalIsNeverAddedToTheClosingBalance(): void
    {
        $this->balance(7, '5000.0000', '0', 'dokan_orders');
        $this->balance(7, '0', '2000.0000', ReconcileDokanFinance::WITHDRAW_TYPE);
        $this->withdrawal(7, '2000.0000', 'approved');

        $report = $this->financeService()->forVendor(7);

        // The two figures appear side by side and the overlap is named. What
        // must never appear anywhere is 5,000 — closing plus withdrawn.
        self::assertSame('3000.0000', $report['dokan']['closing']);
        self::assertSame('2000.0000', $report['dokan']['withdrawals_by_status']['approved']['total']);
        self::assertSame('withdraw_debits_are_inside_closing', $report['already_counted_once']['note']);
    }

    public function testAcceptingResponsibilityPaysNothingAndCountsNothingTwice(): void
    {
        $this->balance(7, '5000.0000', '0', 'dokan_orders');
        $this->withdrawal(7, '750.0000', 'pending');
        $service = $this->financeService();

        $result = $service->decide(7, ReconcileDokanFinance::ACCEPTED, 'تسویه خارج از دفترکل', $service->figuresToken(7));

        self::assertTrue($result['ok']);
        self::assertSame('5000.0000', $result['handover']['closing_at_decision']);
        self::assertSame(1, $result['handover']['pending_requests_untouched']);
        // And the balance itself is untouched by the decision: nothing was
        // recomputed, added or cleared.
        self::assertSame('5000.0000', $service->forVendor(7)['dokan']['closing']);
    }

    public function testTheFrozenFigureDoesNotDriftWhenMoreHistoryArrives(): void
    {
        $this->balance(7, '5000.0000', '0', 'dokan_orders');
        $service = $this->financeService();
        $service->decide(7, ReconcileDokanFinance::ACCEPTED, '', $service->figuresToken(7));

        $this->balance(7, '1000.0000', '0', 'dokan_orders');
        $report = $service->forVendor(7);

        self::assertSame('5000.0000', $report['handover']['closing_at_decision'], 'what was agreed must not be re-derived');
        self::assertSame('6000.0000', $report['dokan']['closing'], 'what Dokan recorded must stay current');
    }

    /**
     * The reader keeps Dokan's own token — `dokan:0`, `dokan:1`, `dokan:2` —
     * rather than translating 0/1/2 into one of our withdrawal states. Read
     * naively, every imported row then looks outstanding, including the ones
     * Dokan already paid. This is the assertion that found that.
     */
    public function testDokansOwnStatusTokenIsUnderstoodNotJustTheBareInteger(): void
    {
        $this->balance(7, '9000.0000', '0', 'dokan_orders');
        $this->withdrawal(7, '2000.0000', 'dokan:1');   // approved: money already left
        $this->withdrawal(7, '500.0000', 'dokan:2');    // cancelled: closed, never paid
        $this->withdrawal(7, '750.0000', 'dokan:0');    // pending: nobody has paid it

        $service = $this->financeService();
        $result = $service->decide(7, ReconcileDokanFinance::ACCEPTED, '', $service->figuresToken(7));

        self::assertSame(1, $result['handover']['pending_requests_untouched'], 'only the pending one is outstanding');
    }

    /**
     * A balance is never taken on by somebody who was not reading the report.
     *
     * The receipt is a hash of the very figures the page printed, so it cannot
     * be produced without having been served them — and it stops matching the
     * moment the imported past moves. A «seen» checkbox would do neither: it
     * records a click, and it stays true after the numbers change.
     */
    public function testAcceptanceWithoutTheReportsReceiptIsRefused(): void
    {
        $this->balance(7, '5000.0000', '0', 'dokan_orders');
        $service = $this->financeService();

        $blind = $service->decide(7, ReconcileDokanFinance::ACCEPTED);
        self::assertFalse($blind['ok']);
        self::assertSame('report_not_seen', $blind['reason']);

        $stale = $service->figuresToken(7);
        $this->balance(7, '1000.0000', '0', 'dokan_orders');
        $moved = $service->decide(7, ReconcileDokanFinance::ACCEPTED, '', $stale);
        self::assertFalse($moved['ok']);
        self::assertSame('figures_changed', $moved['reason']);

        self::assertSame(
            ReconcileDokanFinance::OPEN,
            $service->decisionFor(7)['decision'],
            'two refusals later the shop is still nobody\'s obligation'
        );
    }

    public function testAnUnknownDecisionIsRefused(): void
    {
        $this->balance(7, '1.0000', '0');

        $result = $this->financeService()->decide(7, 'paid');

        self::assertFalse($result['ok']);
        self::assertSame('unknown_decision', $result['reason']);
        self::assertSame(ReconcileDokanFinance::OPEN, $this->financeService()->decisionFor(7)['decision']);
    }

    public function testADecisionRequiresTheCapability(): void
    {
        $this->balance(7, '1.0000', '0');
        $this->caps = new FakeCapabilityChecker(9, []);

        $result = $this->financeService()->decide(7, ReconcileDokanFinance::ACCEPTED);

        self::assertFalse($result['ok']);
        self::assertSame('forbidden', $result['reason']);
    }

    // --------------------------------------------------------------- setup

    private function grantService(): GrantImportedStaff
    {
        return new GrantImportedStaff(
            $this->records,
            new StaffRoleMap($this->options),
            $this->staff,
            $this->users,
            $this->auditLogger(),
            $this->caps
        );
    }

    private function financeService(): ReconcileDokanFinance
    {
        return new ReconcileDokanFinance(
            $this->records,
            $this->options,
            $this->auditLogger(),
            self::clock(),
            $this->caps
        );
    }

    private function auditLogger(): AuditLogger
    {
        return new AuditLogger(new RecordingAuditRepository(), new AuditEventSanitizer(), self::clock());
    }

    private static function clock(): \Tecteb\Marketplace\Contracts\ClockInterface
    {
        return new class implements \Tecteb\Marketplace\Contracts\ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-20 10:00:00');
            }
        };
    }

    /** @param array<string,string> $map */
    private function map(array $map): void
    {
        (new StaffRoleMap($this->options))->save($map);
    }

    private function recordStaff(int $vendorUserId, int $staffUserId, string $role): void
    {
        $this->records->recordStaff('run-1', [
            'staff_user_id' => $staffUserId,
            'vendor_user_id' => $vendorUserId,
            'display_name' => 'کاربر ' . $staffUserId,
            'user_email' => 'a@b.test',
            'dokan_role' => $role,
        ]);
    }

    private function balance(int $vendorUserId, string $credit, string $debit, string $type = 'dokan_orders'): void
    {
        $this->records->recordBalance('run-1', [
            'trn_id' => count($this->records->balance) + 1,
            'vendor_user_id' => $vendorUserId,
            'trn_type' => $type,
            'particulars' => '',
            'debit' => $debit,
            'credit' => $credit,
            'status' => 'approved',
            'trn_date' => '2026-01-01 00:00:00',
        ]);
    }

    private function withdrawal(int $vendorUserId, string $amount, string $status): void
    {
        $this->records->recordWithdrawal('run-1', [
            'withdraw_id' => count($this->records->withdrawals) + 1,
            'vendor_user_id' => $vendorUserId,
            'amount' => $amount,
            'status' => $status,
            'method' => 'bank',
            'note' => '',
            'requested_at' => '2026-01-02 00:00:00',
        ]);
    }
}
