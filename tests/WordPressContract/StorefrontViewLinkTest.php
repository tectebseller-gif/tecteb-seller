<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceStorefrontFields;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: what «مشاهدهٔ محصول» opens, and when it opens nothing.
 *
 * The adapter is the half of this feature that asks WordPress, so this is where
 * the three answers are pinned:
 *
 *  - published → the shop address a buyer sees;
 *  - anything else → WordPress's own preview, and ONLY for somebody who may edit
 *    that post, because `preview=true` is a query parameter and not a permission;
 *  - no post → no link and a reason, and nothing created.
 *
 * `get_post_status()` returning FALSE rather than '' is what separates «the page
 * is a draft» from «there is no page», and the review screen says two different
 * things about those, so both are asserted.
 */
final class StorefrontViewLinkTest extends ContractTestCase
{
    private const WC_ID = 804;

    /**
     * The adapter under test.
     *
     * `viewLink()` asks WordPress about a post and nothing else — no category
     * directory, no projection — so the projector is constructed with nothing
     * at all. Until `alpha.41` it also took a spec-template repository, which
     * this test had to write out in full to satisfy; the projector stopped
     * reading it when «توضیحات کامل» became the vendor's own field, so the
     * whole stand-in went with it.
     */
    private function fields(): WooCommerceStorefrontFields
    {
        return new WooCommerceStorefrontFields(new WooCommerceProjector());
    }

    public function testAPublishedProductGivesItsPublicAddress(): void
    {
        State::$posts[self::WC_ID] = ['status' => 'publish'];

        $link = $this->fields()->viewLink(self::WC_ID);

        self::assertTrue($link['public'], 'a published product has a public page');
        self::assertSame('', $link['reason']);
        self::assertStringContainsString('p=' . self::WC_ID, $link['url']);
        self::assertStringNotContainsString('preview', $link['url'], 'a published page is not a preview');
    }

    /**
     * WHAT THIS PROVES: a draft gives a preview link, and only to somebody who
     * may edit the post.
     *
     * Both halves in one test, because the value of the check is the DIFFERENCE
     * between the two viewers — measured with one capability added and taken
     * away again, on the same draft.
     */
    public function testADraftGivesAPreviewOnlyToSomebodyWhoMayEditIt(): void
    {
        State::$posts[self::WC_ID] = ['status' => 'draft'];

        State::loginAs(9, ['tmc_review_products', 'edit_post']);
        $allowed = $this->fields()->viewLink(self::WC_ID);
        self::assertFalse($allowed['public'], 'a draft is not a public page');
        self::assertSame('', $allowed['reason']);
        self::assertStringContainsString('preview=true', $allowed['url'], 'WordPress\' own preview');

        // The same draft, a viewer without `edit_post`: no link at all, and a
        // reason that says it is a permission rather than a missing product.
        State::loginAs(10, ['tmc_review_products']);
        $refused = $this->fields()->viewLink(self::WC_ID);
        self::assertSame('', $refused['url'], 'no non-public address is handed out');
        self::assertSame('not_permitted', $refused['reason']);
    }

    /** WHAT THIS PROVES: a pending or private product is treated as a preview too. */
    public function testEveryNonPublishedStatusIsAPreviewAndNotAPublicPage(): void
    {
        State::loginAs(9, ['tmc_review_products', 'edit_post']);
        foreach (['draft', 'pending', 'private', 'future'] as $status) {
            State::$posts[self::WC_ID] = ['status' => $status];
            $link = $this->fields()->viewLink(self::WC_ID);
            self::assertFalse($link['public'], $status . ' is not a public page');
            self::assertStringContainsString('preview=true', $link['url'], $status);
        }
    }

    /**
     * WHAT THIS PROVES: no post, no link, and nothing is created by asking.
     *
     * The id zero case and the deleted-post case answer the same way and for the
     * same reason: there is nothing to open. `State::$posts` is empty afterwards,
     * which is the assertion that looking did not make a product.
     */
    public function testWithoutAPostThereIsNoLinkAndNothingIsCreated(): void
    {
        State::loginAs(9, ['tmc_review_products', 'edit_post']);
        State::$posts = [];

        foreach ([0, -1, self::WC_ID] as $id) {
            $link = $this->fields()->viewLink($id);
            self::assertSame('', $link['url'], 'id ' . $id);
            self::assertFalse($link['public']);
            self::assertSame('missing', $link['reason'], 'id ' . $id);
        }
        self::assertSame([], State::$posts, 'asking where to look created no product');
    }
}
