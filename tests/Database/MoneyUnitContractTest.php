<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\MoneyUnitClaim;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: a vendor's books hold ONE kind of money, and the five ways
 * of answering «may this unit be recorded» stay five.
 *
 * `alpha.39` asked the ledger with `SELECT DISTINCT currency, exponent` and
 * turned the answer into a `bool`, which collapsed three situations into «yes»
 * — empty books, matching books, and a FAILED READ, because `getResults()`
 * answers an empty array for all of «no rows» and «the query broke» — and two
 * into «no», losing the difference between «your books hold another unit» and
 * «your books hold two, a person decides».
 *
 * It also accepted a line in ANY unit the books already held, so a ledger that
 * had become mixed stayed mixed and nothing said so.
 */
final class MoneyUnitContractTest extends DatabaseTestCase
{
    private const VENDOR = 91;
    private const OTHER = 92;

    private WpDatabase $db;
    private DbLedgerRepository $ledger;
    private DbVendorMoneyUnitRegistry $units;
    private SystemClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $this->clock = new SystemClock();
        $this->ledger = new DbLedgerRepository($this->db, $this->clock);
        $this->units = new DbVendorMoneyUnitRegistry($this->db, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    public function testEmptyBooksTakeTheFirstUnitAndKeepIt(): void
    {
        self::assertNull($this->units->unitOf(self::VENDOR), 'nothing recorded yet');
        self::assertFalse($this->units->isMixed(self::VENDOR));

        $first = $this->units->claim(self::VENDOR, 'IRT', 0);
        self::assertTrue($first->isAgreed());
        self::assertSame(['currency' => 'IRT', 'exponent' => 0], $this->units->unitOf(self::VENDOR));

        // The same unit again is still agreed — a second sale is not a second
        // decision.
        self::assertTrue($this->units->claim(self::VENDOR, 'IRT', 0)->isAgreed());
        // And lower case is the same unit, not a new one.
        self::assertTrue($this->units->claim(self::VENDOR, 'irt', 0)->isAgreed());
    }

    public function testADifferentUnitIsRefusedAndNamesWhatTheBooksHold(): void
    {
        self::assertTrue($this->units->claim(self::VENDOR, 'IRT', 0)->isAgreed());

        $claim = $this->units->claim(self::VENDOR, 'USD', 2);
        self::assertFalse($claim->isAgreed());
        self::assertSame(MoneyUnitClaim::DIFFERENT, $claim->state);
        // Named, so a message can say «your books are in IRT» rather than
        // «no».
        self::assertSame(['currency' => 'IRT', 'exponent' => 0], $claim->storedUnit());
        // And the stored unit did not move.
        self::assertSame(['currency' => 'IRT', 'exponent' => 0], $this->units->unitOf(self::VENDOR));
    }

    public function testEachVendorHasTheirOwnUnit(): void
    {
        self::assertTrue($this->units->claim(self::VENDOR, 'IRT', 0)->isAgreed());
        self::assertTrue($this->units->claim(self::OTHER, 'USD', 2)->isAgreed());
        self::assertSame(['currency' => 'IRT', 'exponent' => 0], $this->units->unitOf(self::VENDOR));
        self::assertSame(['currency' => 'USD', 'exponent' => 2], $this->units->unitOf(self::OTHER));
    }

    /**
     * WHAT THIS PROVES: books that already hold two units refuse EVERY new
     * accrual — including one in a unit they hold.
     *
     * This is the case `alpha.39` got wrong in the other direction: its check
     * returned true if any stored unit matched, so a mixed ledger went on
     * accepting writes in either of them for ever.
     */
    public function testBooksThatAlreadyHoldTwoUnitsRefuseEveryNewAccrual(): void
    {
        // Written straight to the ledger, because `alpha.40` cannot produce
        // this state — the point is to meet the rows `alpha.39` could leave.
        $this->accrue(self::VENDOR, 'order:1', 'IRT', 0, 100000);
        $this->accrue(self::VENDOR, 'order:2', 'USD', 2, 10050);
        self::assertTrue($this->units->isMixed(self::VENDOR));

        foreach ([['IRT', 0], ['USD', 2], ['EUR', 2]] as [$currency, $exponent]) {
            $claim = $this->units->claim(self::VENDOR, $currency, $exponent);
            self::assertFalse($claim->isAgreed(), $currency . ' must be refused on mixed books');
            self::assertSame(MoneyUnitClaim::MIXED, $claim->state);
        }
        // Nothing was fixed on the way: a claim that refuses writes no row.
        self::assertNull($this->units->unitOf(self::VENDOR));

        // And the mixed books are REPORTED, with the units named, so a manager
        // meeting a blocked order can see why. No conversion is offered.
        $mixed = $this->ledger->vendorsWithMixedUnits();
        self::assertCount(1, $mixed);
        self::assertSame(self::VENDOR, $mixed[0]['vendor_user_id']);
        self::assertSame('IRT/0,USD/2', $mixed[0]['units']);
        // LEDGER ROWS, not events: two accruals of three lines each. The row
        // count is what somebody reconciling this would work through.
        self::assertSame(6, $mixed[0]['entries']);
    }

    /**
     * WHAT THIS PROVES: a read that FAILED is not «the books are empty».
     *
     * The exact shape of `alpha.39`'s bug: `getResults()` answers `[]` for a
     * broken query, `[]` was «no units recorded», and «no units recorded» was
     * «the first sale sets the unit». A database hiccup could therefore fix
     * the unit of a vendor's whole ledger — in whatever unit that one order
     * happened to carry.
     */
    public function testAFailedReadIsNotAnEmptyLedger(): void
    {
        self::assertTrue($this->units->claim(self::VENDOR, 'IRT', 0)->isAgreed());

        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['COUNT(DISTINCT CONCAT']);
        $blind = new DbVendorMoneyUnitRegistry($failing, $this->clock);

        $claim = $blind->claim(self::VENDOR, 'USD', 2);
        self::assertFalse($claim->isAgreed(), 'a question nobody could answer is not a yes');
        self::assertSame(MoneyUnitClaim::UNREADABLE, $claim->state);
        self::assertNotSame([], $failing->refused, 'the injected failure has to have fired');

        // The three-answer read, directly: null is «could not ask», not false.
        self::assertNull($blind->isMixed(self::VENDOR));
        self::assertFalse($this->units->isMixed(self::VENDOR), 'and on a working gateway it answers');

        // The stored unit is untouched by the failed attempt.
        self::assertSame(['currency' => 'IRT', 'exponent' => 0], $this->units->unitOf(self::VENDOR));
    }

    public function testAUnitMoneyCannotCarryIsRefusedBeforeTheDatabaseIsTouched(): void
    {
        foreach ([['', 0], ['RIAL', 0], ['IR', 0], ['IRT', 9], ['IRT', -1]] as [$currency, $exponent]) {
            $claim = $this->units->claim(self::VENDOR, $currency, $exponent);
            self::assertSame(
                MoneyUnitClaim::UNSUPPORTED,
                $claim->state,
                var_export($currency, true) . '/' . $exponent . ' is not a unit'
            );
        }
        // And nothing was recorded by any of them.
        self::assertNull($this->units->unitOf(self::VENDOR));
    }

    /**
     * WHAT THIS PROVES: of two FIRST claims arriving at once from independent
     * connections, exactly one agrees — and the loser is told which unit won.
     *
     * This is the guarantee the table exists for, and the reason it had to be
     * a table: the ledger holds many rows per vendor, so two concurrent first
     * writers had no index to collide on. Here the primary key on the vendor
     * decides, and the loser's claim reads back the STORED row rather than its
     * own intention.
     *
     * The second claim runs in a process of its own
     * (`tests/Support/concurrent-money-unit.php`), scheduled and waited for,
     * so its work is COMMITTED before this one asks. Nothing sleeps, and this
     * is not presented as two statements in the same instant.
     */
    public function testTwoFirstClaimsFromIndependentConnectionsLeaveOneUnit(): void
    {
        self::assertNull($this->units->unitOf(self::VENDOR), 'both of them start from nothing');

        $other = $this->secondConnection('claim', (string) self::VENDOR, 'USD', '2');
        self::assertSame('agreed', $other, 'the other connection got there first');

        $mine = $this->units->claim(self::VENDOR, 'IRT', 0);
        self::assertFalse($mine->isAgreed(), 'and this one must lose');
        self::assertSame(MoneyUnitClaim::DIFFERENT, $mine->state);
        self::assertSame(['currency' => 'USD', 'exponent' => 2], $mine->storedUnit());

        // ONE unit on record, and it is the winner's.
        self::assertSame(['currency' => 'USD', 'exponent' => 2], $this->units->unitOf(self::VENDOR));
        self::assertSame(
            1,
            (int) $this->wpdb->pdo()
                ->query('SELECT COUNT(*) FROM `' . $this->wpdb->prefix . M0022VendorMoneyUnit::UNITS . '`')
                ->fetchColumn()
        );
    }

    public function testASecondClaimOfTheSameUnitFromAnotherConnectionAgrees(): void
    {
        // The positive half: the refusal above must be about the UNIT, not
        // about being second.
        self::assertTrue($this->units->claim(self::VENDOR, 'IRT', 0)->isAgreed());
        self::assertSame('agreed', $this->secondConnection('claim', (string) self::VENDOR, 'IRT', '0'));
        self::assertSame('unit_changed', $this->secondConnection('claim', (string) self::VENDOR, 'USD', '2'));
    }

    /**
     * WHAT THIS PROVES: a site upgrading from schema 21 keeps the unit its
     * ledger already proves, and a vendor whose ledger is mixed gets no row.
     *
     * Migration 22 seeds from the ledger with `HAVING COUNT(DISTINCT …) = 1`,
     * which is the whole rule: one unit on disk is a fact to record, two is a
     * decision that is not a migration's to make.
     */
    public function testTheMigrationSeedsOneUnitPerVendorAndSkipsMixedBooks(): void
    {
        // Books written BEFORE the table exists, the way an upgrade finds them.
        $table = $this->wpdb->prefix . M0022VendorMoneyUnit::UNITS;
        $this->accrue(self::VENDOR, 'order:10', 'IRT', 0, 100000);
        $this->accrue(self::VENDOR, 'order:11', 'IRT', 0, 200000);
        $this->accrue(self::OTHER, 'order:12', 'IRT', 0, 100000);
        $this->accrue(self::OTHER, 'order:13', 'USD', 2, 10050);
        $this->wpdb->pdo()->exec("TRUNCATE TABLE `{$table}`");

        (new M0022VendorMoneyUnit())->up($this->db);

        self::assertSame(['currency' => 'IRT', 'exponent' => 0], $this->units->unitOf(self::VENDOR));
        self::assertNull(
            $this->units->unitOf(self::OTHER),
            'mixed books get no row: picking a winner between two kinds of money is not a migration'
        );
        self::assertTrue((new M0022VendorMoneyUnit())->verify($this->db), 'and the shape is right');

        // The mixed vendor is refused and reported rather than converted.
        self::assertSame(MoneyUnitClaim::MIXED, $this->units->claim(self::OTHER, 'IRT', 0)->state);
        self::assertSame([self::OTHER], array_column($this->ledger->vendorsWithMixedUnits(), 'vendor_user_id'));
    }

    public function testVerifyRefusesATableOfTheRightNameAndTheWrongShape(): void
    {
        // `alpha.37`'s rule: `CREATE TABLE IF NOT EXISTS` is satisfied by any
        // table of that name, so a step that only checks existence moves the
        // schema onto something nothing can be written to.
        $table = $this->wpdb->prefix . M0022VendorMoneyUnit::UNITS;
        $this->wpdb->pdo()->exec("DROP TABLE IF EXISTS `{$table}`");
        $this->wpdb->pdo()->exec("CREATE TABLE `{$table}` (`vendor_user_id` BIGINT UNSIGNED NOT NULL)");

        self::assertFalse((new M0022VendorMoneyUnit())->verify($this->db));

        $this->wpdb->pdo()->exec("DROP TABLE `{$table}`");
        (new M0022VendorMoneyUnit())->up($this->db);
        self::assertTrue((new M0022VendorMoneyUnit())->verify($this->db));
    }

    // ------------------------------------------------------------- fixture

    private function accrue(int $vendorUserId, string $eventKey, string $currency, int $exponent, int $baseMinor): void
    {
        $money = static fn (int $minor): Money => Money::of($minor, $currency, $exponent);
        $share = (int) ($baseMinor * 0.9);
        self::assertTrue($this->ledger->record(
            (new LedgerTransaction($eventKey, $vendorUserId, $eventKey, '1'))
                ->add(LedgerAccount::CentralPayment, $money($baseMinor), 'item_paid')
                ->add(LedgerAccount::Commission, $money($baseMinor - $share)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, $money($share)->negate(), 'vendor_earned')
        ));
    }

    private function secondConnection(string ...$args): string
    {
        $command = escapeshellcmd(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__) . '/Support/concurrent-money-unit.php');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $out = [];
        $status = 0;
        exec($command . ' 2>&1', $out, $status);
        self::assertSame(0, $status, 'second connection failed: ' . implode("\n", $out));
        return trim(implode('', $out));
    }
}
