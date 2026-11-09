<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;

/**
 * «How many did the stop never look at» — the rule, on its own.
 *
 * ⛔ **WHY THIS TEST EXISTS.** The behavioural half lives in
 * `CatalogProjectionTest`, and it can only reach the cases a fixture can
 * build: a handful of products, far below `SCAN_LIMIT`. The case that
 * matters most — the count could not be read AND the scan filled its cap —
 * needs five hundred and one real products to reach that way, so a mutation
 * that silently returned nought there survived the entire suite. Pulling the
 * rule out as a pure function is the remedy `alpha.77` names: make the
 * branch reachable instead of leaving it unguarded.
 */
final class UnseenProductsTest extends TestCase
{
    public function testAKnownCountIsSimplyTheDifference(): void
    {
        self::assertSame(23, StorefrontStop::unseenFrom(523, 500, 500));
        self::assertSame(0, StorefrontStop::unseenFrom(184, 184, 500));
    }

    /** A count below what was seen is not a negative number of products. */
    public function testTheAnswerIsNeverNegative(): void
    {
        self::assertSame(0, StorefrontStop::unseenFrom(3, 7, 500));
    }

    /**
     * ⛔ THE BRANCH THE MUTATION SURVIVED. An unreadable count on a scan that
     * filled its cap means «there is something I did not see» — the direction
     * that tells the manager to look.
     */
    public function testAnUnreadableCountOnAFullScanSaysSomethingWasMissed(): void
    {
        self::assertSame(1, StorefrontStop::unseenFrom(null, 500, 500));
        self::assertSame(1, StorefrontStop::unseenFrom(null, 501, 500));
    }

    /**
     * …and on a scan that did NOT fill up, nought is honest: the `LIMIT` was
     * never reached, so everything there was came back.
     */
    public function testAnUnreadableCountOnAShortScanIsNought(): void
    {
        self::assertSame(0, StorefrontStop::unseenFrom(null, 184, 500));
        self::assertSame(0, StorefrontStop::unseenFrom(null, 0, 500));
    }
}
