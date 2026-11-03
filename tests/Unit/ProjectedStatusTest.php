<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProjectedStatus;

/**
 * What a projection would write, and whether writing it changes anything.
 *
 * The verdict a staged transfer refuses on, so each of the four is asserted
 * with its own case — and the two that permit a transfer are asserted to
 * permit it, because a guard that refused everything would pass a suite that
 * only checked the refusals (`alpha.46`).
 */
final class ProjectedStatusTest extends TestCase
{
    public function testAPublishedRowWouldWritePublishAndVisible(): void
    {
        self::assertSame('publish', ProjectedStatus::statusOf(ProductStatus::Published));
        self::assertSame('visible', ProjectedStatus::visibilityOf(ProductStatus::Published));
    }

    /** Every status but «منتشرشده» — the projector's own `else`. */
    public function testEveryOtherRowStatusWouldWriteDraftAndHidden(): void
    {
        foreach (ProductStatus::cases() as $case) {
            if ($case === ProductStatus::Published) {
                continue;
            }
            self::assertSame('draft', ProjectedStatus::statusOf($case), $case->value);
            self::assertSame('hidden', ProjectedStatus::visibilityOf($case), $case->value);
        }
    }

    public function testAStampedRowIsHeldWhateverTheShopHolds(): void
    {
        // The stamp is the whole answer: a held row's projection writes
        // neither value, so what the shop holds cannot matter.
        foreach (['publish', 'draft', 'pending', 'private', 'future', ''] as $shop) {
            self::assertSame(
                ProjectedStatus::HELD,
                ProjectedStatus::decide(ProductStatus::Draft, 'publish', $shop),
                $shop
            );
        }
        self::assertTrue(ProjectedStatus::settled(ProjectedStatus::HELD));
    }

    /** A blank stamp is «no stamp», not a stamp holding the empty string. */
    public function testABlankStampIsNoStamp(): void
    {
        self::assertSame(
            ProjectedStatus::WOULD_CHANGE,
            ProjectedStatus::decide(ProductStatus::Draft, '   ', 'publish')
        );
        self::assertSame(
            ProjectedStatus::WOULD_CHANGE,
            ProjectedStatus::decide(ProductStatus::Draft, '', 'publish')
        );
    }

    public function testAnUnstampedRowThatWouldWriteWhatTheShopHasAgrees(): void
    {
        self::assertSame(
            ProjectedStatus::AGREES,
            ProjectedStatus::decide(ProductStatus::Published, null, 'publish')
        );
        self::assertSame(
            ProjectedStatus::AGREES,
            ProjectedStatus::decide(ProductStatus::Draft, null, 'draft')
        );
        self::assertTrue(ProjectedStatus::settled(ProjectedStatus::AGREES));
    }

    /**
     * ⛔ The measured case of `alpha.67`, and the reason `AGREES` compares
     * the WRITE and not the enum mapping.
     *
     * `ProductStatus::forIntakeOf('pending')` is «پیش‌نویس», so a Draft row
     * beside a `pending` product looks like agreement through the map. The
     * projector writes the literal `draft`, which took a pending product out
     * of the catalogue and out of search.
     */
    public function testADraftRowBesideAPendingProductWouldChangeIt(): void
    {
        self::assertSame(ProductStatus::Draft, ProductStatus::forIntakeOf('pending'));
        self::assertSame(
            ProjectedStatus::WOULD_CHANGE,
            ProjectedStatus::decide(ProductStatus::Draft, null, 'pending')
        );
        self::assertFalse(ProjectedStatus::settled(ProjectedStatus::WOULD_CHANGE));
    }

    /** The hazard of §2: a selling product a projection would draft. */
    public function testADraftRowBesideAPublishedProductWouldChangeIt(): void
    {
        self::assertSame(
            ProjectedStatus::WOULD_CHANGE,
            ProjectedStatus::decide(ProductStatus::Draft, null, 'publish')
        );
    }

    /**
     * A status this marketplace has no field for needs no separate verdict.
     *
     * `private` and `future` fail the same comparison for the same reason,
     * and the answer reports both strings — which is more use to a manager
     * than a taxonomy of statuses.
     */
    public function testAStatusWithNoMarketplaceFieldWouldChange(): void
    {
        foreach (['private', 'future', 'trash', 'auto-draft', 'wc-anything'] as $shop) {
            self::assertSame(
                ProjectedStatus::WOULD_CHANGE,
                ProjectedStatus::decide(ProductStatus::Draft, null, $shop),
                $shop
            );
            self::assertNull(ProductStatus::forIntakeOf($shop), $shop);
        }
    }

    public function testAnUnreadableShopIsNotAgreement(): void
    {
        self::assertSame(
            ProjectedStatus::UNREADABLE,
            ProjectedStatus::decide(ProductStatus::Draft, null, null)
        );
        self::assertSame(
            ProjectedStatus::UNREADABLE,
            ProjectedStatus::decide(ProductStatus::Published, null, '  ')
        );
        self::assertFalse(ProjectedStatus::settled(ProjectedStatus::UNREADABLE));
    }
}
