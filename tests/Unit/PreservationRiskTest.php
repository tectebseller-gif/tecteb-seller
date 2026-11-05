<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Domain\PreservationRisk;

/**
 * Which preservation risks stop a transfer, and which are only reported.
 *
 * Both directions, because the two halves of this split are two different
 * mistakes: a disclosure treated as blocking is the defect `alpha.92` fixes,
 * and a blocking risk treated as a disclosure would be a product losing a
 * setting with nobody asked.
 */
final class PreservationRiskTest extends TestCase
{
    /** The owner's §3 names four to keep protected. All four are blocking. */
    public function testTheFourTheOwnerNamedAreBlocking(): void
    {
        foreach (['manage_stock', 'catalog_visibility', 'price_scale', 'type'] as $key) {
            self::assertTrue(PreservationRisk::isBlocking($key), $key);
            self::assertContains($key, PreservationRisk::BLOCKING, $key);
        }
    }

    /** A failed read is blocking: «نمی‌دانم» is not «they agree». */
    public function testAnUnreadableProductIsBlocking(): void
    {
        self::assertTrue(PreservationRisk::isBlocking('product'));
    }

    public function testTheFieldsNoProjectionWritesAreDisclosures(): void
    {
        foreach (['dimensions', 'shipping_class', 'tags', 'related', 'backorders', 'sold_individually'] as $key) {
            self::assertFalse(PreservationRisk::isBlocking($key), $key);
        }
    }

    /**
     * ⛔ A key in neither list is BLOCKING.
     *
     * A risk added to the reader and forgotten here must not become waivable
     * in silence. The architecture test asserts the lists are complete, so
     * this is the second line of defence rather than the plan.
     */
    public function testAnUnknownKeyIsBlocking(): void
    {
        self::assertTrue(PreservationRisk::isBlocking('something_nobody_classified'));
        self::assertTrue(PreservationRisk::isBlocking(''));
    }

    public function testNoKeyIsInBothLists(): void
    {
        self::assertSame(
            [],
            array_intersect(PreservationRisk::BLOCKING, PreservationRisk::DISCLOSURE)
        );
    }

    public function testTheTwoHalvesOfOneAnswerAreSplitAndNothingIsLost(): void
    {
        $risks = [
            'manage_stock' => 'یک',
            'tags' => 'دو',
            'price_scale' => 'سه',
            'dimensions' => 'چهار',
        ];
        self::assertSame(['manage_stock', 'price_scale'], PreservationRisk::blockingIn($risks));
        self::assertSame(['tags', 'dimensions'], PreservationRisk::disclosuresIn($risks));
        // Every key is in exactly one half: a split that dropped one would
        // hide a risk, and one that duplicated it would ask twice.
        //
        // ⛔ As a SET, and the first draft of this assertion got that wrong:
        // each half keeps the answer's relative order inside itself, so
        // concatenating them interleaves nothing and the sequence
        // `manage_stock, price_scale, tags, dimensions` is not the input's
        // `manage_stock, tags, price_scale, dimensions`. Both are correct
        // partitions; only one of them is a statement about order, and order
        // is not what «nothing is lost» means. The two assertions above
        // already pin the order inside each half.
        $union = array_merge(PreservationRisk::blockingIn($risks), PreservationRisk::disclosuresIn($risks));
        sort($union);
        $keys = array_keys($risks);
        sort($keys);
        self::assertSame($keys, $union, 'the two halves must partition the answer');
        self::assertSame($union, array_values(array_unique($union)), 'and no key is in both');
    }

    public function testADisclosureOnlyAnswerBlocksNothing(): void
    {
        $risks = ['tags' => 'یک', 'shipping_class' => 'دو', 'sold_individually' => 'سه'];
        self::assertSame([], PreservationRisk::blockingIn($risks));
        self::assertCount(3, PreservationRisk::disclosuresIn($risks));
    }

    public function testAnEmptyAnswerHasNeitherHalf(): void
    {
        self::assertSame([], PreservationRisk::blockingIn([]));
        self::assertSame([], PreservationRisk::disclosuresIn([]));
    }

    // ---------------------------------------------------------------------
    // The row's context: one mechanism, one question per key, and every fact
    // defaulting to the conservative answer.
    // ---------------------------------------------------------------------

    /** `alpha.93`: the stamp releases the catalogue visibility and nothing else. */
    public function testTheStatusStampReleasesTheCatalogueVisibilityOnly(): void
    {
        $risks = [
            'catalog_visibility' => 'یک',
            'manage_stock' => 'دو',
            'price_scale' => 'سه',
        ];
        self::assertSame(
            ['price_scale'],
            PreservationRisk::blockingGiven($risks, true),
            'the stamp releases the visibility; the product being readable releases the stock flag'
        );
        self::assertSame(
            ['catalog_visibility', 'manage_stock'],
            PreservationRisk::disclosuresGiven($risks, true),
            'a released key is REPORTED, never removed'
        );
    }

    /** And on an UNSTAMPED row it blocks exactly as it did before `alpha.93`. */
    public function testWithoutTheStampTheCatalogueVisibilityStillBlocks(): void
    {
        self::assertSame(
            ['catalog_visibility'],
            PreservationRisk::blockingGiven(['catalog_visibility' => 'یک'], false)
        );
    }

    /**
     * `alpha.94`: stock management is released once the product can be READ,
     * because the write now lives in `project()`'s `$isNew` arm.
     */
    public function testStockManagementIsReleasedOnceTheProductCanBeRead(): void
    {
        $risks = ['manage_stock' => 'یک'];
        self::assertTrue(PreservationRisk::storefrontReadable($risks));
        self::assertSame([], PreservationRisk::blockingGiven($risks, false));
        self::assertSame(['manage_stock'], PreservationRisk::disclosuresGiven($risks, false));
    }

    /**
     * ⛔ The control, and the whole reason the fact is derived from the answer
     * rather than passed in: an answer that names `product` is an answer
     * about a product NOBODY could read, so nothing in it is released.
     */
    public function testAnUnreadableProductReleasesNothing(): void
    {
        $risks = ['product' => 'محصول خوانده نشد'];
        self::assertFalse(PreservationRisk::storefrontReadable($risks));
        self::assertSame(['product'], PreservationRisk::blockingGiven($risks, true));
        self::assertSame([], PreservationRisk::disclosuresGiven($risks, true));

        // And a hand-built answer carrying both keys — which the reader never
        // produces, because it returns early — still releases neither.
        $both = ['product' => 'یک', 'manage_stock' => 'دو'];
        self::assertSame(['product', 'manage_stock'], PreservationRisk::blockingGiven($both, true));
    }

    /**
     * The width control: no context releases the three keys that have no
     * release.
     *
     * Without it, a release that spilled to every key would pass every test
     * above (`alpha.46`), and the four keys the owner's §3 protects would
     * stop protecting anything.
     */
    public function testNoContextReleasesThePriceTheTypeOrAFailedRead(): void
    {
        foreach (['price_scale', 'type', 'product'] as $key) {
            self::assertTrue(
                PreservationRisk::isBlockingGiven($key, true, true),
                $key . ' must be released by nothing'
            );
        }
        self::assertSame(['price_scale', 'type'], PreservationRisk::blockingGiven(
            ['price_scale' => 'یک', 'type' => 'دو', 'manage_stock' => 'سه'],
            true
        ));
    }

    /**
     * Every fact defaults to the conservative answer, so a caller that cannot
     * tell gets the behaviour of the round before the release (`alpha.89`).
     */
    public function testEveryContextDefaultsToBlocking(): void
    {
        self::assertTrue(PreservationRisk::isBlockingGiven('manage_stock', false));
        self::assertTrue(PreservationRisk::isBlockingGiven('catalog_visibility', false));
        self::assertTrue(PreservationRisk::isBlockingGiven('manage_stock', true));
        self::assertFalse(PreservationRisk::isBlockingGiven('manage_stock', false, true));
        self::assertFalse(PreservationRisk::isBlockingGiven('catalog_visibility', true, false));
        // An empty answer has nothing to read and nothing to release.
        self::assertFalse(PreservationRisk::storefrontReadable([]));
        self::assertSame([], PreservationRisk::blockingGiven([], true));
    }

    /** A disclosure stays a disclosure whatever the context says. */
    public function testADisclosureIsNeverTurnedIntoABlocker(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$held, $readable]) {
            foreach (PreservationRisk::DISCLOSURE as $key) {
                self::assertFalse(PreservationRisk::isBlockingGiven($key, $held, $readable), $key);
            }
        }
    }

    /** Neither release list contains a key that is not blocking to start with. */
    public function testTheReleaseListsOnlyNameBlockingKeys(): void
    {
        foreach (PreservationRisk::HELD_WITH_STATUS as $key) {
            self::assertContains($key, PreservationRisk::BLOCKING, $key);
        }
        foreach (PreservationRisk::HELD_BY_EXISTING_PRODUCT as $key) {
            self::assertContains($key, PreservationRisk::BLOCKING, $key);
        }
        self::assertSame([], array_intersect(
            PreservationRisk::HELD_WITH_STATUS,
            PreservationRisk::HELD_BY_EXISTING_PRODUCT
        ), 'one key, one release — two would make «which fact freed it» unanswerable');
    }
}
