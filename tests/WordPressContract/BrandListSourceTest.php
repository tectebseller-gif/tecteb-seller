<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceBrandDirectory;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductBrandFieldView;
use TmcWpStubs\State;

/**
 * WHERE THE BRAND LIST COMES FROM — asked of WordPress, not typed.
 *
 * §5 said «منبع واقعی برندهای WooCommerce را شناسایی کن. ابتدا علت خرابی
 * autocomplete فعلی را بررسی کن», and the cause is here rather than in the
 * script: `WooCommerceBrandDirectory` gated every method on
 * `taxonomy_exists('product_brand')`, a key for data this plugin does not own.
 * On a shop whose brands are a WooCommerce ATTRIBUTE there is no
 * `product_brand`, so the list was empty, the `<datalist>` had no options, and
 * the endpoint answered nothing to every keystroke — with no screen saying
 * why.
 *
 * ⛔ The permission hypothesis was ruled out before any of this was written:
 * the endpoint's nonce and capability are the same pair the form itself is
 * rendered behind, so a vendor who can see the field can always call it. The
 * empty answer was data, not access.
 *
 * ⛔ AND THE FOLDING WAS NEVER THE PROBLEM EITHER. `BrandName::key()` already
 * folds ی/ي, ک/ك, ZWNJ, every space and the diacritics, and
 * `BrandNameTest` has pinned that for twenty-nine rows since `alpha.48`. What
 * was missing was a list to fold against.
 */
final class BrandListSourceTest extends ContractTestCase
{
    /**
     * The control, and it comes first: a shop with WooCommerce's own taxonomy
     * is answered exactly as it was. The discovery may only ADD an answer
     * where there was none — a rule that reordered the preferences would be a
     * change to every shop that works today.
     */
    public function testWooCommercesOwnBrandTaxonomyIsStillThePreferredAnswer(): void
    {
        State::$terms['product_brand'] = [
            11 => self::term('آریا طب'),
            12 => self::term('سینا طب'),
        ];
        $directory = new WooCommerceBrandDirectory();

        self::assertSame('product_brand', $directory->source());
        self::assertTrue($directory->isAvailable());
        self::assertCount(2, $directory->search(''));
        self::assertSame('آریا طب', $directory->search('اریا')[0]->name ?? null, 'the folding still works on the list it finds');
    }

    /**
     * ⛔ AND WITH BOTH, WOOCOMMERCE'S OWN TAXONOMY STILL WINS.
     *
     * This is the control the owner's working shops depend on, and the one the
     * first control could not give: without an attribute present, reordering
     * the preferences changes nothing, so that test passes on a rule that
     * prefers attributes. A shop with both is the only fixture where the order
     * is observable — and on it the answer must be the one every release
     * before this gave.
     */
    public function testAShopWithBothIsAnsweredWithWooCommercesOwnTaxonomy(): void
    {
        State::$attributeTaxonomies = [['name' => 'brand', 'label' => 'برند']];
        State::$terms['pa_brand'] = [61 => self::term('از ویژگی')];
        State::$terms['product_brand'] = [62 => self::term('از تاکسونومی ووکامرس')];
        $directory = new WooCommerceBrandDirectory();

        self::assertSame('product_brand', $directory->source());
        self::assertSame(['از تاکسونومی ووکامرس'], array_map(
            static fn (object $b): string => $b->name,
            $directory->search('')
        ), 'and the list is that taxonomy\'s, not the attribute\'s');
    }

    /**
     * ⛔ THE SHOP THE OWNER HAS: brands are a global WooCommerce attribute,
     * labelled «برند», living at `pa_brand`. Before this round `source()` did
     * not exist and `isAvailable()` was false here, which is the measured
     * cause of «autocomplete does nothing».
     */
    public function testAGlobalAttributeLabelledBrandIsFoundAndReadable(): void
    {
        State::$attributeTaxonomies = [['name' => 'brand', 'label' => 'برند']];
        State::$terms['pa_brand'] = [
            21 => self::term('آریا طب'),
            22 => self::term('آمبو بگ ایران'),
        ];
        $directory = new WooCommerceBrandDirectory();

        self::assertSame('pa_brand', $directory->source());
        self::assertTrue($directory->isAvailable());
        self::assertCount(2, $directory->search(''));

        // And the names come back through the SAME folding every other door
        // uses: a space the vendor did not type, and the Arabic ی.
        self::assertSame('آمبو بگ ایران', $directory->search('آمبوبگ')[0]->name ?? null);
        self::assertSame('آریا طب', $directory->search('آريا')[0]->name ?? null);
    }

    /** The label is read, not the slug, because the slug is not a word anybody chose. */
    public function testTheLabelDecidesAndTheSlugDoesNot(): void
    {
        State::$attributeTaxonomies = [['name' => 'marque-x1', 'label' => 'Brand']];
        State::$terms['pa_marque-x1'] = [31 => self::term('Acme')];

        self::assertSame('pa_marque-x1', (new WooCommerceBrandDirectory())->source());
    }

    /**
     * ⛔ AND AN ATTRIBUTE THAT IS NOT A BRAND IS NOT ONE. A guard wider than
     * the defect is the bigger defect (`alpha.60`): answering with «رنگ» would
     * offer the vendor colours as brand names and let a colour be recorded as
     * an approved brand.
     */
    public function testAnAttributeThatIsNotABrandIsNotOfferedAsOne(): void
    {
        State::$attributeTaxonomies = [
            ['name' => 'color', 'label' => 'رنگ'],
            ['name' => 'size', 'label' => 'اندازه'],
        ];
        State::$terms['pa_color'] = [41 => self::term('آبی')];
        State::$terms['pa_size'] = [42 => self::term('بزرگ')];
        $directory = new WooCommerceBrandDirectory();

        self::assertNull($directory->source());
        self::assertFalse($directory->isAvailable());
        self::assertSame([], $directory->search(''));
    }

    /**
     * An attribute the shop DEFINED but WordPress did not register this
     * request is a row nothing can read terms from. Answering with it would
     * trade an empty list for an error.
     */
    public function testAnAttributeThatIsNotRegisteredThisRequestIsRefused(): void
    {
        State::$attributeTaxonomies = [['name' => 'brand', 'label' => 'برند']];
        State::$productTaxonomies = ['product_cat', 'product_tag'];

        self::assertNull((new WooCommerceBrandDirectory())->source());
    }

    /** Asked once per request, and the answer is kept — including «none». */
    public function testTheQuestionIsAskedOnceAndNoneIsRemembered(): void
    {
        $directory = new WooCommerceBrandDirectory();
        self::assertNull($directory->source());

        // A taxonomy appearing mid-request does not change the answer: the
        // `false`/`null` pair exists so «asked, and there is none» is not
        // re-asked by every method of every call.
        State::$terms['product_brand'] = [51 => self::term('آریا طب')];
        self::assertNull($directory->source(), 'the memo holds «none», rather than treating it as «not asked»');
    }

    /**
     * One term row in the shape the stub's `get_terms()` reads — keyed by term
     * id, with the `slug`, `parent` and `count` a real `WP_Term` carries.
     * Written once so a fixture cannot describe a term WordPress could not
     * build.
     *
     * @return array{name:string,slug:string,parent:int,count:int}
     */
    private static function term(string $name, int $count = 1): array
    {
        return ['name' => $name, 'slug' => rawurlencode($name), 'parent' => 0, 'count' => $count];
    }

    // ------------------------------------------------- what the vendor reads

    /**
     * ⛔ TWO WAYS TO HAVE NO LIST, AND THE OLD SENTENCE SAID THE WRONG ONE.
     *
     * «ووکامرس فعال نیست» was printed for both, and on the owner's shop it was
     * simply false — WooCommerce is running and the page the vendor is reading
     * came from it. A sentence that names the wrong cause sends somebody to
     * fix the wrong thing.
     */
    public function testWithWooCommerceOffTheSentenceSaysSo(): void
    {
        $html = ProductBrandFieldView::render('', [], true, false);

        self::assertStringContainsString('ووکامرس فعال نیست', $html);
        self::assertStringNotContainsString('هنوز فهرست برند ندارد', $html);
    }

    public function testWithWooCommerceOnAndNoListTheSentenceSaysThatInstead(): void
    {
        $html = ProductBrandFieldView::render('', [], true, true);

        self::assertStringContainsString('هنوز فهرست برند ندارد', $html);
        self::assertStringNotContainsString('ووکامرس فعال نیست', $html);
        // And it says what the vendor can still do, because they can: typing a
        // name records a request either way.
        self::assertStringContainsString('تایپ کنید', $html);
    }

    /** With a list, neither sentence appears — the ordinary hint does. */
    public function testWithAListNeitherWarningAppears(): void
    {
        $html = ProductBrandFieldView::render('آریا طب', [], false, true);

        self::assertStringNotContainsString('ووکامرس فعال نیست', $html);
        self::assertStringNotContainsString('هنوز فهرست برند ندارد', $html);
        self::assertStringContainsString('نام‌های موجود هنگام تایپ پیشنهاد می‌شوند', $html);
    }

    /**
     * And the parameter that was inserted did not displace the one after it:
     * an open request still shows its own sentence, which is the regression a
     * new positional argument invites.
     */
    public function testAnOpenRequestStillShowsItsOwnSentence(): void
    {
        $html = ProductBrandFieldView::render(
            'برند تازه',
            [],
            false,
            true,
            new BrandProposal(1, 7, 5, 'برند تازه', 'برند تازه', BrandProposal::PENDING, 0, 0, '', '', '')
        );

        self::assertStringContainsString('در انتظار تأیید مدیر است', $html);
    }
}
