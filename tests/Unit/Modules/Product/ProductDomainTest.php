<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\SensitiveChange;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;

/**
 * §4.2, §6.1, A.1 and MED-01 as assertions.
 *
 * These are the rules that would be expensive to get wrong later: who may
 * publish, what counts as a sensitive change, and what happens to an answer
 * when the question is retired.
 */
final class ProductDomainTest extends TestCase
{
    public function testAVendorCannotPublishWithoutTheSecondPermission(): void
    {
        $states = new ProductStateMachine();
        self::assertFalse(
            $states->vendorMayMove(ProductStatus::Draft, ProductStatus::Published, false),
            'selling permission alone never publishes (§4.1: the two grants are separate)'
        );
        self::assertTrue($states->vendorMayMove(ProductStatus::Draft, ProductStatus::Published, true));
        self::assertTrue($states->vendorMayMove(ProductStatus::Draft, ProductStatus::Submitted, false));
    }

    public function testAVendorCannotSuspendOrUnsuspendTheirOwnLiveProduct(): void
    {
        $states = new ProductStateMachine();
        self::assertTrue($states->canTransition(ProductStatus::Published, ProductStatus::Suspended));
        self::assertFalse(
            $states->vendorMayMove(ProductStatus::Published, ProductStatus::Suspended, true),
            'suspension is the marketplace\'s decision, not the shop\'s'
        );
    }

    public function testTheLifecycleRefusesToSkipStates(): void
    {
        $states = new ProductStateMachine();
        self::assertFalse($states->canTransition(ProductStatus::Archived, ProductStatus::Published));
        self::assertTrue($states->canTransition(ProductStatus::Archived, ProductStatus::Draft));
        self::assertTrue($states->canTransition(ProductStatus::Submitted, ProductStatus::ChangesRequested));
    }

    public function testStockAndPriceAreOnOppositeSidesOfTheReviewLine(): void
    {
        // A.1: inventory is immediate; anything that changes what the product
        // claims to be — price included — waits for the manager.
        self::assertContains('priceMinor', SensitiveChange::SENSITIVE);
        self::assertContains('stock', SensitiveChange::IMMEDIATE);
        self::assertNotContains('stock', SensitiveChange::SENSITIVE);

        $current = new ProductDetails(title: 'دستکش', priceMinor: 100000, stock: 5);
        $sameClaimNewStock = new ProductDetails(title: 'دستکش', priceMinor: 100000, stock: 2);
        self::assertSame([], SensitiveChange::between($current, $sameClaimNewStock));

        $newPrice = new ProductDetails(title: 'دستکش', priceMinor: 120000, stock: 5);
        self::assertSame(['priceMinor'], SensitiveChange::between($current, $newPrice));
    }

    public function testSpecComparisonIgnoresKeyOrder(): void
    {
        self::assertFalse(SensitiveChange::specsChanged(['a' => '1', 'b' => '2'], ['b' => '2', 'a' => '1']));
        self::assertTrue(SensitiveChange::specsChanged(['a' => '1'], ['a' => '2']));
    }

    public function testZeroStockStopsThePurchaseAndTheSaleWindowDecidesThePrice(): void
    {
        $details = new ProductDetails(
            title: 'ماسک',
            priceMinor: 200000,
            salePriceMinor: 150000,
            saleFrom: '2026-09-01',
            saleTo: '2026-09-30',
            stock: 0
        );
        self::assertSame(150000, $details->effectivePriceMinor('2026-09-14'));
        self::assertSame(200000, $details->effectivePriceMinor('2026-10-01'));
        self::assertSame(200000, $details->effectivePriceMinor('2026-08-31'));
        self::assertFalse($details->isPurchasable('2026-09-14'), 'zero stock stops the sale (A.1)');
    }

    public function testASubmittableProductNeedsTitleCategoryAndPriceButNotStock(): void
    {
        $empty = new ProductDetails();
        self::assertSame(['title', 'category', 'price'], $empty->missingFields());

        $outOfStock = new ProductDetails(title: 'سرنگ', categoryKey: 'syringe', priceMinor: 50000, stock: 0);
        self::assertSame([], $outOfStock->missingFields());
        self::assertTrue($outOfStock->isComplete());
    }

    public function testOnlySimpleAndVariableExist(): void
    {
        self::assertTrue(ProductType::isValid('simple'));
        self::assertTrue(ProductType::isValid('variable'));
        self::assertFalse(ProductType::isValid('external'), 'A.1 puts external/grouped out of v1');
        self::assertFalse(ProductType::isValid('grouped'));
    }

    public function testOwnershipIsAPropertyOfTheRow(): void
    {
        $product = new Product(1, 7, new ProductDetails(title: 'باند'), ProductStatus::Draft);
        self::assertTrue($product->belongsTo(7));
        self::assertFalse($product->belongsTo(8));
    }

    public function testARetiredFieldIsNeitherAskedNorRejected(): void
    {
        $template = new SpecTemplate(1, 'gloves', 'دستکش', 3, [
            new SpecField(1, 'material', 'جنس', SpecFieldType::Text, true),
            new SpecField(2, 'old_code', 'کد قدیمی', SpecFieldType::Text, true, deprecated: true),
        ]);
        self::assertCount(1, $template->askedFields());
        self::assertCount(2, $template->allFields(), 'MED-01: a retired field is kept, not deleted');

        // The answer to the retired question is still carried, and its former
        // "required" does not block anything.
        $verdict = $template->validate(['material' => 'لاتکس', 'old_code' => 'X-1']);
        self::assertSame([], $verdict['missing']);
        self::assertSame([], $verdict['invalid']);
    }

    public function testValidationSeparatesMissingFromImpossible(): void
    {
        $template = new SpecTemplate(1, 'gloves', 'دستکش', 1, [
            new SpecField(1, 'material', 'جنس', SpecFieldType::Text, true),
            new SpecField(2, 'size_mm', 'اندازه', SpecFieldType::Number),
            new SpecField(3, 'sterile', 'استریل', SpecFieldType::Boolean),
            new SpecField(4, 'grade', 'رده', SpecFieldType::Choice, options: ['A', 'B']),
            new SpecField(5, 'expires', 'انقضا', SpecFieldType::Date),
        ]);
        $verdict = $template->validate([
            'material' => '',
            'size_mm' => 'بزرگ',
            'sterile' => '2',
            'grade' => 'C',
            'expires' => '1404/01/01',
        ]);
        self::assertSame(['جنس'], $verdict['missing']);
        self::assertSame(['اندازه', 'استریل', 'رده', 'انقضا'], $verdict['invalid']);
    }

    public function testFieldTypesAcceptWhatTheySayAndNothingElse(): void
    {
        self::assertTrue(SpecFieldType::Number->accepts('12.5'));
        self::assertFalse(SpecFieldType::Number->accepts('12٫5'), 'a Persian decimal mark is converted on input, not here');
        self::assertTrue(SpecFieldType::Boolean->accepts('0'));
        self::assertFalse(SpecFieldType::Boolean->accepts('yes'));
        self::assertTrue(SpecFieldType::Choice->accepts('A', ['A', 'B']));
        self::assertFalse(SpecFieldType::Choice->accepts('C', ['A', 'B']));
        self::assertTrue(SpecFieldType::Date->accepts('2026-09-14'));
        self::assertFalse(SpecFieldType::Date->accepts('14-09-2026'));
        // Emptiness is `required`'s business, so every type accepts it.
        foreach (SpecFieldType::cases() as $type) {
            self::assertTrue($type->accepts(''), $type->value . ' leaves emptiness to required');
        }
    }

    public function testOnlyDraftAndChangesRequestedAreEditableByTheVendor(): void
    {
        self::assertTrue(ProductStatus::Draft->isEditableByVendor());
        self::assertTrue(ProductStatus::ChangesRequested->isEditableByVendor());
        self::assertFalse(ProductStatus::Submitted->isEditableByVendor());
        self::assertFalse(ProductStatus::Published->isEditableByVendor());
        self::assertTrue(ProductStatus::Published->isLive());
        self::assertFalse(ProductStatus::Suspended->isLive());
    }
}
