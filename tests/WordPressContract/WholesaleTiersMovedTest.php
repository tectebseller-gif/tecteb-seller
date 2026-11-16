<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Marketplace\Domain\PriceTier;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress\SupportArea;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\WholesaleTiersView;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductFormView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * §6: THE LADDER MOVED, AND NOTHING ELSE DID.
 *
 * «رابط موجود و سالم قیمت‌گذاری پلکانی … به فرم ویرایش محصول منتقل شود» —
 * moved, not rebuilt, and the only thing removed from «پشتیبانی» is that one
 * interface. So this file has two halves and the second is what makes the
 * first safe: the ladder is on the product form, and the support page is
 * otherwise byte-identical in what it offers.
 *
 * ⛔ `ManageWholesale` IS NOT TOUCHED by any of this, which is why there is no
 * data-compatibility question to answer: the verb name, the service, the three
 * rows and the stored rows are the same. What changed is which page renders
 * the form and where the save comes back to.
 */
final class WholesaleTiersMovedTest extends ContractTestCase
{
    public function testTheProductFormDeclaresTheTabAndItIsTheLastOne(): void
    {
        $steps = ProductFormView::steps();

        self::assertArrayHasKey('5', $steps);
        self::assertSame('۵. فروش پلکانی', $steps['5']);
        self::assertSame('5', (string) array_key_last($steps), 'the ladder is the last tab, after «بازبینی و ارسال»');
    }

    /**
     * ⛔ ITS OWN `<form>`, OUTSIDE THE PRODUCT'S — the objection the old place
     * recorded, answered by placement.
     *
     * `SupportArea` said the ladder belonged on a shop-wide page because a
     * ladder is replaced WHOLE and the product form saves field by field, so a
     * whole-replacement control inside a field-by-field form is how somebody
     * deletes a step they never looked at. That hazard is about being inside
     * the form. So step 5 renders before the product form opens and carries
     * its own verb — and a `save_product` can therefore never reach these
     * boxes, which is the thing that had to be true.
     */
    public function testStepFiveRendersTheLadderAndNotTheProductForm(): void
    {
        $html = $this->form('5', [
            'tiers' => [self::tier(2, 180000), self::tier(10, 150000)],
        ]);

        self::assertStringContainsString('id="tmc-wholesale-tiers"', $html);
        self::assertStringContainsString('value="set_tiers"', $html);
        self::assertStringContainsString('name="tier_qty[0]"', $html);
        self::assertStringContainsString('name="tier_price[2]"', $html, 'three rows, as the support page offered');
        self::assertStringNotContainsString('name="tier_qty[3]"', $html);

        // The product form is not here at all, so nothing it posts can touch
        // the ladder and nothing the ladder posts can touch a product field.
        self::assertStringNotContainsString('value="save_product"', $html);
        self::assertStringNotContainsString('name="title"', $html);
        self::assertStringNotContainsString('name="price"', $html);

        // And the ladder it shows is the ladder it was given.
        self::assertStringContainsString('value="180000"', $html);
        self::assertStringContainsString('value="150000"', $html);
    }

    /** The nav still reaches every step from step 5, so the tab is not a dead end. */
    public function testTheNavIsStillThereOnTheLadderStep(): void
    {
        $html = $this->form('5', ['tiers' => []]);

        self::assertSame(
            count(ProductFormView::steps()),
            preg_match_all('/class="tv-tab[ "]/', $html),
            'every step is still reachable from the ladder'
        );
        self::assertStringContainsString('aria-current="step"', $html);
    }

    /**
     * A product that is not saved yet has no id to hang a ladder on, and says
     * so — rather than painting boxes whose save would be refused.
     */
    public function testAnUnsavedProductIsToldToSaveFirst(): void
    {
        $html = $this->form('5', ['tiers' => []], productId: 0);

        self::assertStringContainsString('ابتدا محصول را ذخیره کنید', $html);
        self::assertStringNotContainsString('name="tier_qty[0]"', $html);
    }

    /** A reader sees the ladder and no boxes, like every other vendor screen. */
    public function testAReaderSeesTheLadderWithoutTheBoxes(): void
    {
        $html = $this->form('5', [
            'tiers' => [self::tier(2, 180000)],
            'may_edit' => false,
        ]);

        // ⛔ ASSERTED AS THE SUPPORT PAGE RENDERED IT, separator included.
        // `number_format()` puts an ASCII comma there and `PersianDigits`
        // rewrites only the digits, so the string is «۱۸۰,۰۰۰» — not the
        // «٬» this repository's `MoneyText` uses elsewhere. That difference
        // is older than this round and §6 is a move: changing it here would
        // be a change to the ladder's output smuggled in under «منتقل شود».
        // Reported, not fixed.
        self::assertStringContainsString('۱۸۰,۰۰۰', $html, 'the current ladder is readable');
        self::assertStringNotContainsString('name="tier_qty[0]"', $html);
        self::assertStringContainsString('دسترسی ویرایش محصول لازم است', $html);
    }

    /**
     * ⛔ THE SENTENCE AFTER A SAVE HAD TO TRAVEL TOO.
     *
     * The ladder's codes live in `MarketplaceMessages`, and this form read
     * only `ProductMessages` and `VendorMessages` — so a saved ladder printed
     * its own code as raw text. And both `isErrorNotice()` implementations
     * answer «not a success code», so `wholesale_tiers_saved` asked of the
     * product catalogue alone comes back as an ERROR: a successful save
     * painted as a refusal.
     */
    public function testASavedLadderIsAnnouncedAsASuccessInPersian(): void
    {
        $html = $this->form('5', ['tiers' => []], notice: VendorNotice::of('wholesale_tiers_saved', ['steps' => 2]));

        // ⛔ THE SENTENCE, NOT «the code is absent». The first version of this
        // asserted only that `wholesale_tiers_saved` was not printed, and it
        // PASSED with the Marketplace catalogue removed from the chain —
        // because `VendorMessages::notice()` ends in a generic «انجام شد.»
        // that swallows every unknown code. An assertion that an unknown code
        // is hidden is an assertion about the fallback, not about the message.
        self::assertStringContainsString('قیمت پلکانی با ۲ پله ذخیره شد.', $html);
        self::assertStringContainsString('tv-notice--success', $html, 'and a save is not painted as a refusal');
    }

    /** A refusal is still a refusal, which is the other half of that tone. */
    public function testARefusedLadderIsStillPaintedAsOne(): void
    {
        // A REAL refusal code, for the same reason: `tier_quantity_too_low`
        // does not exist, and the generic fallback made the test pass about a
        // code the service can never emit.
        $html = $this->form('5', ['tiers' => []], notice: VendorNotice::of('tier_not_descending'));

        self::assertStringContainsString('با افزایش تعداد، قیمت هر واحد باید کمتر شود.', $html);
        self::assertStringContainsString('tv-notice--warning', $html);
    }

    // ------------------------------------------- what the support page kept

    /**
     * ⛔ THE ONLY THING REMOVED FROM «پشتیبانی» IS THE LADDER.
     *
     * «گفتگو با مدیریت، کد تخفیف و تمام امکانات دیگر پشتیبانی دقیقاً مانند
     * قبل باقی بمانند» — so the verb list is asserted whole, not just for the
     * absence. A list that only proved `set_tiers` had gone would pass on one
     * that had lost the coupons too.
     */
    public function testTheSupportPageKeepsEveryOtherVerbAndLosesOnlyTheLadder(): void
    {
        self::assertSame(
            ['create_coupon', 'disable_coupon', 'open_ticket', 'reply_ticket'],
            SupportArea::ACTIONS
        );
    }

    /** And the product area now carries that one verb, under the same name. */
    public function testTheProductAreaCarriesTheLadderVerbUnderTheSameName(): void
    {
        self::assertContains('set_tiers', ProductArea::ACTIONS);
        self::assertSame(
            1,
            count(array_filter(ProductArea::ACTIONS, static fn (string $a): bool => $a === 'set_tiers')),
            'one verb, not a second spelling of it'
        );
    }

    /**
     * The three rows are the support page's own number, and it travelled as a
     * named constant rather than as a literal, so the two cannot drift.
     */
    public function testTheLadderStillOffersThreeSteps(): void
    {
        self::assertSame(3, WholesaleTiersView::ROWS);
    }

    /**
     * ⛔ NAMED ARGUMENTS, DELIBERATELY. `render()` takes thirty-odd
     * parameters and a positional call is a count nobody can check by
     * reading — the first version of this file passed `null` into
     * `$showingProposal` and three tests failed about something that is not
     * what they are for.
     *
     * @param array<string,mixed> $wholesale
     */
    private function form(string $step, array $wholesale, int $productId = 7700, ?VendorNotice $notice = null): string
    {
        $this->bootPlugin(false);

        return ProductFormView::render(
            productId: $productId,
            details: new ProductDetails(title: 'دستکش لاتکس', priceMinor: 200000),
            specs: [],
            images: [],
            mainImageId: 0,
            template: null,
            step: $step,
            status: ProductStatus::Draft,
            categories: [],
            urls: $this->urls(),
            nonceField: '<input type="hidden" name="_wpnonce" value="n">',
            notice: $notice,
            wholesale: $wholesale
        );
    }

    /** One step of a ladder, with the ids the row carries. */
    private static function tier(int $minQuantity, int $unitPriceMinor): PriceTier
    {
        return new PriceTier(0, 7700, 21, $minQuantity, $unitPriceMinor);
    }

    private function urls(): VendorUrls
    {
        return new VendorUrls(
            'https://example.test/vendor/',
            'https://example.test/vendor/application/',
            'https://example.test/vendor/store/',
            'https://example.test/vendor/staff/',
            'https://example.test/vendor/invite/',
            'https://example.test/vendor/products/'
        );
    }
}
