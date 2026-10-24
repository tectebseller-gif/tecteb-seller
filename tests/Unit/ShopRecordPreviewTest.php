<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordPreview;
use Tecteb\Marketplace\Modules\Migration\Domain\RecordSource;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;
use Tecteb\Marketplace\Tests\Support\InMemoryShopRecords;

/**
 * What a shop's records look like before anybody imports them — four answers,
 * and the fourth is «I could not look».
 *
 * «خطای خواندن نباید صفر تعبیر شود» is the rule this whole class exists for,
 * and it has two halves that are easy to confuse: a source that answered
 * «nought rows» is a FACT, and a source whose statement failed is the absence
 * of a fact. Collapsing them is how a manager is told there is nothing to
 * import when there is a whole shop's history sitting behind a broken read.
 */
final class ShopRecordPreviewTest extends TestCase
{
    private const SHOP = 401;

    private FakeDokanReader $dokan;

    private InMemoryShopRecords $records;

    protected function setUp(): void
    {
        $this->dokan = new FakeDokanReader();
        $this->dokan->vendorRows = [
            ['user_id' => self::SHOP, 'store_name' => 'داروخانه دکان', 'email' => 's@example.test', 'enabled' => true],
        ];
        $this->records = new InMemoryShopRecords();
    }

    private function preview(): ShopRecordPreview
    {
        return new ShopRecordPreview($this->dokan, $this->records);
    }

    /** §10, case one: an imported shop with no records at all. */
    public function testAnImportedShopWithNoRecordsReportsZeroAndNotAnError(): void
    {
        $row = $this->preview()->forVendor(self::SHOP);

        foreach (ShopRecordPreview::KINDS as $kind) {
            self::assertSame(
                ShopRecordPreview::EMPTY,
                $row['kinds'][$kind]['verdict'],
                $kind . ': Dokan was asked and answered nought'
            );
            self::assertSame(0, $row['kinds'][$kind]['source']['count']);
            self::assertSame('source_has_none', $row['kinds'][$kind]['reason']);
        }
        self::assertFalse($row['anything_to_import'], 'nothing to import is not an error');
        self::assertFalse($row['anything_unreadable']);
    }

    /** §10, case two: a shop with all three kinds. */
    public function testAShopWithAllThreeKindsIsPendingUntilTheyAreImported(): void
    {
        $this->seedAllThree();

        $row = $this->preview()->forVendor(self::SHOP);

        self::assertSame(ShopRecordPreview::PENDING, $row['kinds']['staff']['verdict']);
        self::assertSame(ShopRecordPreview::PENDING, $row['kinds']['balance']['verdict']);
        self::assertSame(ShopRecordPreview::PENDING, $row['kinds']['withdrawals']['verdict']);
        self::assertSame(1, $row['kinds']['staff']['source']['count']);
        self::assertSame(2, $row['kinds']['balance']['source']['count']);
        self::assertSame(1, $row['kinds']['withdrawals']['source']['count']);
        self::assertSame(0, $row['kinds']['balance']['imported']);
        self::assertTrue($row['anything_to_import']);
    }

    public function testHoldingEveryRowTheSourceHasReadsAsImported(): void
    {
        $this->seedAllThree();
        $this->records->recordStaff('run-1', [
            'staff_user_id' => 7001,
            'vendor_user_id' => self::SHOP,
            'display_name' => 'مریم رضایی',
            'user_email' => 'm@example.test',
            'dokan_role' => 'vendor_staff',
        ]);

        $row = $this->preview()->forVendor(self::SHOP);

        self::assertSame(ShopRecordPreview::IMPORTED, $row['kinds']['staff']['verdict']);
        self::assertSame('all_rows_held', $row['kinds']['staff']['reason']);
        // …and the other two are still waiting, which is the whole point of
        // asking per kind rather than per shop.
        self::assertSame(ShopRecordPreview::PENDING, $row['kinds']['balance']['verdict']);
        self::assertTrue($row['anything_to_import']);
    }

    public function testHoldingSomeOfWhatTheSourceHasReadsAsPartial(): void
    {
        $this->seedAllThree();
        $this->records->recordBalance('run-1', [
            'trn_id' => 5501,
            'vendor_user_id' => self::SHOP,
            'trn_type' => 'dokan_orders',
            'particulars' => '',
            'debit' => '700000.0000',
            'credit' => '0.0000',
            'status' => 'approved',
            'trn_date' => '',
        ]);

        $row = $this->preview()->forVendor(self::SHOP);

        self::assertSame(ShopRecordPreview::PARTIAL, $row['kinds']['balance']['verdict']);
        self::assertSame(1, $row['kinds']['balance']['imported']);
        self::assertSame(2, $row['kinds']['balance']['source']['count']);
    }

    /** §10, case three: the source read fails. */
    public function testAFailedSourceReadIsNeverReportedAsZero(): void
    {
        $this->seedAllThree();
        $this->dokan->unreadableSources = ['balance'];

        $row = $this->preview()->forVendor(self::SHOP);
        $cell = $row['kinds']['balance'];

        self::assertSame(ShopRecordPreview::UNREADABLE, $cell['verdict']);
        self::assertSame('source_unreadable', $cell['reason']);
        // ⛔ The assertion that matters: NOT nought. A count of nought here
        // would read as «Dokan has no balance ledger for this shop», which is
        // the opposite of what happened.
        self::assertNull($cell['source']['count']);
        self::assertSame(RecordSource::UNREADABLE, $cell['source']['state']);
        self::assertTrue($row['anything_unreadable']);
        // And the two readable kinds still answer, because one broken read is
        // not a broken shop.
        self::assertSame(ShopRecordPreview::PENDING, $row['kinds']['staff']['verdict']);
    }

    public function testAFailedReadOfOurOwnRecordsIsAlsoNotZero(): void
    {
        $this->seedAllThree();
        $this->records->unreadableKinds = ['withdrawals'];

        $cell = $this->preview()->forVendor(self::SHOP)['kinds']['withdrawals'];

        self::assertSame(ShopRecordPreview::UNREADABLE, $cell['verdict']);
        self::assertSame('our_records_unreadable', $cell['reason']);
        self::assertNull($cell['imported']);
    }

    /**
     * «The source is not on this install» is a third thing again.
     *
     * A missing table and a failed statement both come back from SQL as
     * nothing; they call for different things from a person, so they have
     * different names (`alpha.68`).
     */
    public function testASourceThatIsNotInstalledIsUncheckedRatherThanEmpty(): void
    {
        $this->seedAllThree();
        $this->dokan->absentSources = ['staff'];

        $cell = $this->preview()->forVendor(self::SHOP)['kinds']['staff'];

        self::assertSame(ShopRecordPreview::UNCHECKED, $cell['verdict']);
        self::assertSame('source_not_on_this_install', $cell['reason']);
        self::assertNull($cell['source']['count']);
    }

    public function testWithoutDokanNothingIsCheckedAndNothingIsClaimed(): void
    {
        $this->seedAllThree();
        $this->dokan->available = false;

        $row = $this->preview()->forVendor(self::SHOP);

        foreach (ShopRecordPreview::KINDS as $kind) {
            self::assertSame(ShopRecordPreview::UNCHECKED, $row['kinds'][$kind]['verdict']);
            self::assertSame('dokan_not_running', $row['kinds'][$kind]['reason']);
        }
        self::assertFalse($row['anything_to_import'], 'nothing can be claimed about an unread source');
    }

    /** Below schema 17 there is nowhere to keep records, and that is said. */
    public function testWithoutARecordStoreTheAnswerIsUncheckedNotEmpty(): void
    {
        $this->seedAllThree();

        $row = (new ShopRecordPreview($this->dokan))->forVendor(self::SHOP);

        foreach (ShopRecordPreview::KINDS as $kind) {
            self::assertSame(ShopRecordPreview::UNCHECKED, $row['kinds'][$kind]['verdict']);
            self::assertSame('record_store_not_installed', $row['kinds'][$kind]['reason']);
            self::assertNull($row['kinds'][$kind]['imported']);
        }
    }

    /**
     * Records held for a shop whose source now shows nothing are KEPT.
     *
     * Nothing in the import deletes a record, so «the source is empty today»
     * must not read as «this shop has no history». It reads as imported.
     */
    public function testRecordsHeldWhileTheSourceIsNowEmptyStayImported(): void
    {
        $this->records->recordWithdrawal('run-1', [
            'withdraw_id' => 9001,
            'vendor_user_id' => self::SHOP,
            'amount' => '250000.0000',
            'status' => 'dokan:1',
            'method' => 'bank',
            'note' => '',
            'requested_at' => '',
        ]);

        $cell = $this->preview()->forVendor(self::SHOP)['kinds']['withdrawals'];

        self::assertSame(ShopRecordPreview::IMPORTED, $cell['verdict']);
        self::assertSame('held_but_source_now_empty', $cell['reason']);
        self::assertSame(0, $cell['source']['count']);
        self::assertSame(1, $cell['imported']);
    }

    /** An unreadable kind never answers «there is nothing to import». */
    public function testAnythingToImportIsFalseWhenTheOnlyUnknownIsUnreadable(): void
    {
        $this->dokan->unreadableSources = ['staff', 'balance', 'withdrawals'];

        $preview = $this->preview();

        self::assertFalse(
            $preview->anythingToImport([self::SHOP]),
            'an unreadable source is not a claim that there is work'
        );
        self::assertTrue($preview->forVendor(self::SHOP)['anything_unreadable']);
    }

    private function seedAllThree(): void
    {
        $this->dokan->staffRows = [
            ['staff_user_id' => 7001, 'vendor_user_id' => self::SHOP, 'display_name' => 'مریم رضایی', 'user_email' => 'm@example.test', 'dokan_role' => 'vendor_staff'],
        ];
        $this->dokan->balanceRows = [
            ['row_id' => 1, 'trn_id' => 5501, 'vendor_user_id' => self::SHOP, 'trn_type' => 'dokan_orders', 'debit' => '700000.0000', 'credit' => '0.0000', 'status' => 'approved'],
            ['row_id' => 2, 'trn_id' => 9001, 'vendor_user_id' => self::SHOP, 'trn_type' => 'dokan_withdraw', 'debit' => '0.0000', 'credit' => '250000.0000', 'status' => 'approved'],
        ];
        $this->dokan->withdrawRows = [
            ['withdraw_id' => 9001, 'vendor_user_id' => self::SHOP, 'amount' => '250000.0000', 'status' => 'dokan:1', 'method' => 'bank'],
        ];
    }
}
