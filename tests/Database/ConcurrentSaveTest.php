<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRowVersion;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;

/**
 * WHAT THIS PROVES: two editors saving the same product cannot both win, and
 * the loser is told.
 *
 * `alpha.13` compared the version in PHP between a read and a write. Every
 * test it had passed, because every test saved once. The race only appears
 * when two writers interleave, and the only way to show that is to interleave
 * them against a real database — which is what this does.
 *
 * The second property here is the one a clock hides: `updated_at` is a
 * `DATETIME`, so two saves inside one second carry an identical stamp. A check
 * built on it cannot see a conflict that happens quickly, which is exactly
 * when conflicts happen.
 */
final class ConcurrentSaveTest extends DatabaseTestCase
{
    private DbProductRepository $products;

    private const VENDOR = 41;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        $this->resetSchema($db);
        $this->products = new DbProductRepository($db, new SystemClock());
    }

    public function testANewRowStartsAtVersionZeroAndEveryWriteMovesIt(): void
    {
        $id = $this->create();
        self::assertSame('0', $this->products->rowVersion($id));

        self::assertTrue($this->products->updateDetails($id, $this->details('یک'), '0'));
        self::assertSame('1', $this->products->rowVersion($id));

        self::assertTrue($this->products->updateDetails($id, $this->details('دو'), '1'));
        self::assertSame('2', $this->products->rowVersion($id));
    }

    public function testTwoEditorsHoldingTheSameVersionCannotBothWin(): void
    {
        $id = $this->create();
        // Both read the page at the same moment, so both forms carry version 0.
        $held = $this->products->rowVersion($id);

        self::assertTrue($this->products->updateDetails($id, $this->details('کارِ اول'), $held));
        // The second arrives believing the same thing, and the WHERE clause —
        // not a comparison in PHP — is what refuses it.
        self::assertFalse(
            $this->products->updateDetails($id, $this->details('کارِ دوم'), $held),
            'the second writer must lose, not silently replace the first'
        );

        $stored = $this->products->findOwned($id, self::VENDOR);
        self::assertNotNull($stored);
        self::assertSame('کارِ اول', $stored->details->title, "the first writer's work survived");
    }

    /**
     * The whole reason the version is a counter rather than the timestamp.
     *
     * Both saves happen inside one second, so `updated_at` is identical for
     * both and a check built on it sees no conflict at all.
     */
    public function testAConflictInsideOneSecondIsStillSeen(): void
    {
        $id = $this->create();
        $before = $this->products->findOwned($id, self::VENDOR);
        self::assertNotNull($before);

        $this->products->updateDetails($id, $this->details('سریع اول'), '0');
        $after = $this->products->findOwned($id, self::VENDOR);
        self::assertNotNull($after);

        // Demonstrate the trap: on a fast machine the two stamps are equal, so
        // anything comparing them would have let the second write through.
        if ($before->updatedAt === $after->updatedAt) {
            self::assertNotSame(
                $before->rowVersion,
                $after->rowVersion,
                'the counter must move even when the clock does not'
            );
        }
        self::assertFalse($this->products->updateDetails($id, $this->details('سریع دوم'), '0'));
    }

    /**
     * A form with no stamp is REFUSED, not written unguarded.
     *
     * Until alpha.15 this test asserted the opposite, and the reasoning looked
     * sound: refusing would break saving for anybody mid-edit across an
     * upgrade. But the cost of being kind was that the one submission the
     * counter could not check was the one submission it let through — and a
     * concurrency guard with a door in it is not a guard. The vendor is not
     * abandoned either: nothing they typed is lost, and the form comes back
     * with the row's real counter so the next press goes through.
     */
    public function testAFormWithNoVersionIsRefusedRatherThanWrittenUnchecked(): void
    {
        $id = $this->create();
        $this->products->updateDetails($id, $this->details('یک'), '0');

        self::assertFalse(
            $this->products->updateDetails($id, $this->details('بدون نسخه'), ''),
            'an empty token is not a licence to skip the check'
        );
        self::assertSame('یک', $this->products->find($id)?->details->title, 'and nothing was written');
    }

    public function testAVersionThatIsNotANumberIsRefusedToo(): void
    {
        $id = $this->create();
        $this->products->updateDetails($id, $this->details('یک'), '0');

        // `alpha.13` put the `updated_at` STAMP in this field, and a form
        // cached in somebody's browser across the upgrade still sends one.
        // It is refused — with a code that tells them to refresh and resubmit —
        // rather than silently overwriting whatever happened in between.
        self::assertFalse(
            $this->products->updateDetails($id, $this->details('مهر قدیمی'), '2026-09-16 11:24:05'),
            'a token this layer cannot compare is a refusal, never a waiver'
        );
        self::assertSame('یک', $this->products->find($id)?->details->title);
    }

    /**
     * The one write that may skip the check says so in a word.
     *
     * `UNGUARDED` is how the manager applies a revision they have already
     * approved: one actor, no competing form, nothing to compare against. It
     * is a constant and not an omission so that every unchecked write in this
     * codebase can be found by searching for it.
     */
    public function testTheOnlyUncheckedWriteIsTheOneThatNamesItself(): void
    {
        $id = $this->create();
        $this->products->updateDetails($id, $this->details('یک'), '0');

        self::assertTrue(
            $this->products->updateDetails($id, $this->details('تأیید مدیر'), ProductRowVersion::UNGUARDED)
        );
        self::assertSame('تأیید مدیر', $this->products->find($id)?->details->title);
        self::assertTrue($this->products->bumpVersion($id, ProductRowVersion::UNGUARDED));
    }
    public function testBumpVersionTakesItAtomicallyForThePathsThatDoNotWriteDetails(): void
    {
        $id = $this->create();
        self::assertTrue($this->products->bumpVersion($id, '0'));
        self::assertSame('1', $this->products->rowVersion($id));
        // Same version offered twice: the second call is the loser.
        self::assertFalse($this->products->bumpVersion($id, '0'));
    }

    private function create(): int
    {
        return $this->products->create(self::VENDOR, $this->details('اولیه'), ProductStatus::Draft);
    }

    private function details(string $title): ProductDetails
    {
        return new ProductDetails(title: $title, categoryKey: 'general', priceMinor: 120000, sku: '', stock: 5);
    }
}
