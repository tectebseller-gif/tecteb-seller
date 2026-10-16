<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;

/**
 * The three questions the one product list is filtered by, and the rule that
 * an unrecognised answer is «همه» rather than an error.
 *
 * A stale bookmark and a hand-edited URL should show the list. What must not
 * happen is an unvalidated value reaching the query as a filter nothing
 * honours — or, worse, a chip the control offers that the query ignores,
 * which is the drift this class exists to prevent.
 */
final class CatalogueFacetsTest extends TestCase
{
    public function testAnUnknownSourceIsEverything(): void
    {
        self::assertSame('', CatalogueFacets::source('dokan'), 'not a source this list knows');
        self::assertSame('', CatalogueFacets::source(''));
        self::assertSame(
            CatalogueFacets::SOURCE_MARKETPLACE,
            CatalogueFacets::source(CatalogueFacets::SOURCE_MARKETPLACE)
        );
    }

    public function testAnUnknownManagementValueIsEverything(): void
    {
        self::assertSame('', CatalogueFacets::management('maybe'));
        self::assertSame(
            CatalogueFacets::MANAGED_OBSERVED,
            CatalogueFacets::management(CatalogueFacets::MANAGED_OBSERVED)
        );
    }

    /**
     * A publish status is accepted BY SHAPE, not from a list of names.
     *
     * `post_status` is extensible: a plugin on the owner's site can register
     * one this file has never heard of, and a hand-written list would leave
     * its products reachable only by typing a URL. A status nothing has is an
     * empty result, which is the truthful answer to the question asked.
     */
    public function testAPublishStatusIsAcceptedByShape(): void
    {
        self::assertSame('publish', CatalogueFacets::wcStatus('publish'));
        self::assertSame('wc-custom_1', CatalogueFacets::wcStatus('wc-custom_1'), 'a status we have never seen');
        self::assertSame(
            CatalogueFacets::STATUS_ABSENT,
            CatalogueFacets::wcStatus(CatalogueFacets::STATUS_ABSENT),
            'and «there is no post to have a status» is a value of its own'
        );
    }

    /** Anything that is not shaped like a status key is «همه». */
    public function testSomethingThatIsNotAStatusKeyIsRefused(): void
    {
        foreach (["publish' OR 1=1", 'PUBLISH', 'منتشرشده', str_repeat('a', 33), ''] as $raw) {
            self::assertSame('', CatalogueFacets::wcStatus($raw), $raw);
        }
    }

    /**
     * Every value the control offers has a label, and every label belongs to a
     * value the query honours.
     *
     * Two lists are two rules («دو فهرست یعنی دو قاعده»), so the labels and
     * the validator read the same map — and this is the test that says so.
     */
    public function testEveryOfferedValueIsHonouredAndLabelled(): void
    {
        foreach (array_keys(CatalogueFacets::SOURCES) as $value) {
            self::assertSame($value, CatalogueFacets::source((string) $value));
            self::assertNotSame('', CatalogueFacets::sourceLabel((string) $value));
        }
        foreach (array_keys(CatalogueFacets::MANAGEMENT) as $value) {
            self::assertSame($value, CatalogueFacets::management((string) $value));
            self::assertNotSame('', CatalogueFacets::managementLabel((string) $value));
        }
    }

    /** An unknown value is shown as itself rather than as an empty cell. */
    public function testAnUnknownValueIsLabelledAsItself(): void
    {
        self::assertSame('whatever', CatalogueFacets::sourceLabel('whatever'));
        self::assertSame('whatever', CatalogueFacets::managementLabel('whatever'));
    }
}
