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
}
