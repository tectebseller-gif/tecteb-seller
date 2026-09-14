<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Vendor;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Support\FilesystemPath;
use Tecteb\Marketplace\Modules\Vendor\Domain\PrivateStoragePlacement;

/**
 * The rule that decides where a licence scan may be written.
 *
 * Every case here is one a real host produces: WordPress at the document
 * root, WordPress in a subdirectory, a constant pointing somewhere clever,
 * a parent directory nobody may write to.
 */
final class PrivateStoragePlacementTest extends TestCase
{
    private const ALWAYS_USABLE = [self::class, 'yes'];

    public static function yes(string $path): bool
    {
        return true;
    }

    public function testASiblingOfTheWordPressDirectoryIsAccepted(): void
    {
        $placement = PrivateStoragePlacement::choose(
            ['/home/site/tecteb-private'],
            ['/home/site/public_html', '/home/site/public_html/wp-content', '/home/site/public_html/wp-content/uploads'],
            self::ALWAYS_USABLE
        );

        self::assertTrue($placement->isUsable());
        self::assertSame('/home/site/tecteb-private', $placement->path());
        self::assertSame(PrivateStoragePlacement::OK, $placement->reason());
    }

    public function testADirectoryInsideUploadsIsRefused(): void
    {
        $placement = PrivateStoragePlacement::choose(
            ['/home/site/public_html/wp-content/uploads/tmc-private'],
            ['/home/site/public_html'],
            self::ALWAYS_USABLE
        );

        self::assertFalse($placement->isUsable());
        self::assertNull($placement->path());
        self::assertSame(PrivateStoragePlacement::INSIDE_WEB_ROOT, $placement->reason());
    }

    public function testTheWebRootItselfIsRefused(): void
    {
        $placement = PrivateStoragePlacement::choose(['/var/www/html'], ['/var/www/html'], self::ALWAYS_USABLE);

        self::assertSame(PrivateStoragePlacement::INSIDE_WEB_ROOT, $placement->reason());
    }

    public function testATraversalBackIntoTheWebRootIsRefused(): void
    {
        // `/srv/site/public/../public/uploads/x` IS inside the web root; a
        // check that compared raw strings would have missed it.
        $placement = PrivateStoragePlacement::choose(
            ['/srv/site/public/../public/uploads/x'],
            ['/srv/site/public'],
            self::ALWAYS_USABLE
        );

        self::assertSame(PrivateStoragePlacement::INSIDE_WEB_ROOT, $placement->reason());
    }

    public function testTheFirstUsableCandidateWinsAndLaterOnesAreNotProbed(): void
    {
        $probed = [];
        $placement = PrivateStoragePlacement::choose(
            ['/opt/secrets', '/home/site/tecteb-private'],
            ['/home/site/public_html'],
            static function (string $path) use (&$probed): bool {
                $probed[] = $path;
                return true;
            }
        );

        self::assertSame('/opt/secrets', $placement->path());
        self::assertSame(['/opt/secrets'], $probed);
    }

    public function testAnUnwritableCandidateFallsThroughToTheNextOne(): void
    {
        $placement = PrivateStoragePlacement::choose(
            ['/read-only/tecteb-private', '/home/site/tecteb-private'],
            ['/home/site/public_html'],
            static fn (string $path): bool => $path !== '/read-only/tecteb-private'
        );

        self::assertSame('/home/site/tecteb-private', $placement->path());
    }

    public function testNothingWritableIsReportedAsSuchAndStoresNothing(): void
    {
        $placement = PrivateStoragePlacement::choose(
            ['/read-only/tecteb-private'],
            ['/home/site/public_html'],
            static fn (): bool => false
        );

        self::assertFalse($placement->isUsable());
        self::assertSame(PrivateStoragePlacement::NOT_WRITABLE, $placement->reason());
    }

    public function testNoCandidatesAtAllIsItsOwnReason(): void
    {
        $placement = PrivateStoragePlacement::choose([], ['/var/www'], self::ALWAYS_USABLE);

        self::assertSame(PrivateStoragePlacement::NO_CANDIDATE, $placement->reason());
    }

    public function testTheFilesystemRootIsNeverChosen(): void
    {
        $placement = PrivateStoragePlacement::choose(['/'], [], self::ALWAYS_USABLE);

        self::assertFalse($placement->isUsable());
    }

    public function testPathsAreComparedAfterNormalisation(): void
    {
        self::assertSame('/home/site', FilesystemPath::normalize('/home//site/'));
        self::assertSame('/home', FilesystemPath::normalize('/home/site/..'));
        self::assertSame('/', FilesystemPath::normalize('/..'));
        self::assertTrue(FilesystemPath::isInside('/a/b/c', '/a/b'));
        self::assertTrue(FilesystemPath::isInside('/a/b', '/a/b'));
        self::assertFalse(FilesystemPath::isInside('/a/bc', '/a/b'), 'a prefix is not a parent directory');
        self::assertFalse(FilesystemPath::isInside('/a/b', '/a/b/c'));
    }
}
