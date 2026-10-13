<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Tests\Support\FakeCatalogueReader;

/**
 * Whose product is this? — the classification, and the cases where the honest
 * answer is «nobody here can say».
 *
 * «نویسندهٔ وردپرس به‌تنهایی اثبات فروشنده‌بودن نیست» is the rule these tests
 * exist for. The dangerous mistake is not refusing a product that could have
 * been imported; it is quietly attributing the main shop's catalogue to
 * whichever user happens to be in `post_author`, which on a WooCommerce site
 * is often an administrator and sometimes a customer.
 */
final class CatalogueInventoryTest extends TestCase
{
    public function testAProductWithAMarketplaceRowIsTheMarketplaces(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(101, [], ['author_id' => 5]);
        $reader->link(101, [[
            'product_id' => 9,
            'vendor_user_id' => 5,
            'ownership' => LinkOwnership::Marketplace->value,
            'status' => 'published',
        ]]);

        $row = $this->classify($reader, 101);

        self::assertSame(CatalogueInventory::BUCKET_MARKETPLACE, $row['bucket']);
        self::assertSame(5, $row['vendor_user_id']);
        self::assertFalse($row['intake_ready'], 'there is nothing to take in');
    }

    public function testAnObservedRowIsItsOwnBucket(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(102, [], ['author_id' => 5]);
        $reader->link(102, [[
            'product_id' => 9,
            'vendor_user_id' => 5,
            'ownership' => LinkOwnership::Observed->value,
            'status' => 'published',
        ]]);

        self::assertSame(CatalogueInventory::BUCKET_OBSERVED, $this->classify($reader, 102)['bucket']);
    }

    /** Two rows for one product: a question, never a guess. */
    public function testTwoMarketplaceRowsForOneProductAreAmbiguous(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(103, [], ['author_id' => 5]);
        $one = ['product_id' => 9, 'vendor_user_id' => 5, 'ownership' => 'observed', 'status' => 'draft'];
        $reader->link(103, [$one, ['product_id' => 10, 'vendor_user_id' => 6] + $one]);

        $row = $this->classify($reader, 103);

        self::assertSame(CatalogueInventory::BUCKET_AMBIGUOUS, $row['bucket']);
        self::assertContains('duplicate_marketplace_link', $row['reasons']);
    }

    public function testADokanSellersProductIsDokansAndHasItsOwnPath(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(104, [], ['author_id' => 77, 'dokan_seller' => true, 'author_roles' => ['seller']]);

        $row = $this->classify($reader, 104);

        self::assertSame(CatalogueInventory::BUCKET_DOKAN, $row['bucket']);
        self::assertContains('dokan_product_has_its_own_path', $row['reasons']);
        self::assertFalse($row['intake_ready'], 'the Dokan import reads Dokan’s own tables');
    }

    /**
     * A seller in both systems is the decision this round must not take.
     */
    public function testASellerInBothSystemsIsAmbiguous(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(105, [], [
            'author_id' => 78,
            'dokan_seller' => true,
            'vendor_profile' => 'approved',
            'author_roles' => ['seller'],
        ]);

        $row = $this->classify($reader, 105);

        self::assertSame(CatalogueInventory::BUCKET_AMBIGUOUS, $row['bucket']);
        self::assertContains('seller_in_both_systems', $row['reasons']);
    }

    /**
     * THE ONE THAT MATTERS: an administrator's product is the shop's own, and
     * its vendor is reported as nobody.
     */
    public function testAnAdministratorsProductIsTheShopsAndGetsNoVendor(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(106, [], ['author_id' => 1, 'author_is_admin' => true, 'author_roles' => ['administrator']]);

        $row = $this->classify($reader, 106);

        self::assertSame(CatalogueInventory::BUCKET_SHOP, $row['bucket']);
        self::assertSame(0, $row['vendor_user_id'], 'an administrator is not a vendor');
        self::assertContains('shop_product_needs_owner_decision', $row['reasons']);
        self::assertFalse($row['intake_ready']);
    }

    public function testACustomersProductHasNoVendorEvidenceAtAll(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(107, [], ['author_id' => 42, 'author_roles' => ['customer']]);

        $row = $this->classify($reader, 107);

        self::assertSame(CatalogueInventory::BUCKET_AMBIGUOUS, $row['bucket']);
        self::assertContains('author_is_not_a_vendor', $row['reasons']);
        self::assertSame(0, $row['vendor_user_id']);
    }

    public function testAVendorWhoseApplicationIsNotApprovedIsNotAVendorYet(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(108, [], ['author_id' => 43, 'vendor_profile' => 'submitted']);

        $row = $this->classify($reader, 108);

        self::assertSame(CatalogueInventory::BUCKET_AMBIGUOUS, $row['bucket']);
        self::assertContains('vendor_application_submitted', $row['reasons']);
    }

    public function testAnApprovedVendorsUnlinkedProductIsTheOneReadyCase(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(109, [], ['author_id' => 44, 'vendor_profile' => 'approved']);

        $row = $this->classify($reader, 109);

        self::assertSame(CatalogueInventory::BUCKET_VENDOR, $row['bucket']);
        self::assertSame(44, $row['vendor_user_id']);
        self::assertSame([], $row['reasons']);
        self::assertTrue($row['intake_ready']);
    }

    /** A type the form has no shape for is never ready, whoever owns it. */
    public function testAnUnsupportedTypeIsNeverReady(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(110, ['type' => 'grouped'], ['author_id' => 44, 'vendor_profile' => 'approved']);

        $row = $this->classify($reader, 110);

        self::assertSame(CatalogueInventory::BUCKET_VENDOR, $row['bucket']);
        self::assertContains('type_not_supported', $row['reasons']);
        self::assertFalse($row['intake_ready']);
    }

    /** A missing author user is no evidence, not «the shop's». */
    public function testADeletedAuthorIsAmbiguous(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->add(111, [], ['author_id' => 9999, 'author_exists' => false]);

        $row = $this->classify($reader, 111);

        self::assertSame(CatalogueInventory::BUCKET_AMBIGUOUS, $row['bucket']);
        self::assertContains('author_missing', $row['reasons']);
    }

    /** Every reason the classification can produce has a sentence. */
    public function testEveryReasonHasASentenceAManagerCanRead(): void
    {
        $reader = new FakeCatalogueReader();
        $sentences = CatalogueInventory::reasonSentences();
        $cases = [
            [112, [], ['author_id' => 1, 'author_is_admin' => true]],
            [113, [], ['author_id' => 42, 'author_roles' => ['customer']]],
            [114, [], ['author_id' => 43, 'vendor_profile' => 'rejected']],
            [115, [], ['author_id' => 44, 'dokan_seller' => true]],
            [116, ['type' => 'external'], ['author_id' => 45, 'vendor_profile' => 'approved']],
            [117, [], ['author_id' => 46, 'author_exists' => false]],
            [118, [], ['author_id' => 47, 'dokan_seller' => true, 'vendor_profile' => 'approved']],
        ];
        $missing = [];
        foreach ($cases as [$id, $detail, $signals]) {
            $reader->add($id, $detail, $signals);
            foreach ($this->classify($reader, $id)['reasons'] as $reason) {
                if (!isset($sentences[$reason])) {
                    $missing[] = $reason;
                }
            }
        }
        self::assertSame([], $missing, 'a reason with no sentence is a code a manager cannot act on');
    }

    /** The page's counts come from the reader and the rows from the page. */
    public function testThePageCarriesTheCountsAndTheRowsTogether(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->counts = [
            'by_status' => ['publish' => 2],
            'by_type' => ['simple' => 2],
            'variations' => 0,
            'unsupported' => [],
            'total' => 2,
        ];
        $reader->add(120, [], ['author_id' => 44, 'vendor_profile' => 'approved']);
        $reader->add(121, [], ['author_id' => 1, 'author_is_admin' => true]);

        $page = (new CatalogueInventory($reader))->page(0, 50);

        self::assertTrue($page['available']);
        self::assertSame(2, $page['counts']['total']);
        self::assertCount(2, $page['rows']);
        self::assertSame(1, $page['buckets_on_this_page'][CatalogueInventory::BUCKET_VENDOR]);
        self::assertSame(1, $page['buckets_on_this_page'][CatalogueInventory::BUCKET_SHOP]);
        self::assertTrue($page['done']);
    }

    /** Without WooCommerce there is no catalogue, and the page says so. */
    public function testWithoutWooCommerceThePageSaysSoRatherThanReportingZero(): void
    {
        $reader = new FakeCatalogueReader();
        $reader->available = false;

        $page = (new CatalogueInventory($reader))->page();

        self::assertFalse($page['available']);
        self::assertSame([], $page['rows']);
    }

    /** @return array<string,mixed> */
    private function classify(FakeCatalogueReader $reader, int $wcId): array
    {
        return (new CatalogueInventory($reader))->classify($reader->rows[$wcId]);
    }
}
