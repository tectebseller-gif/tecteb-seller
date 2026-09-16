<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
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

    public function testAFormWithNoVersionStillSaves(): void
    {
        $id = $this->create();
        $this->products->updateDetails($id, $this->details('یک'), '0');
        // A form rendered by a build from before the column existed. Refusing
        // it would break saving for anybody mid-edit across the upgrade.
        self::assertTrue($this->products->updateDetails($id, $this->details('بدون نسخه'), ''));
    }

    public function testAVersionThatIsNotANumberIsIgnoredRatherThanRefusing(): void
    {
        $id = $this->create();
        // `alpha.13` put the `updated_at` STAMP in this field. A form cached in
        // somebody's browser across the upgrade still sends one, and it must
        // not lock them out of their own product.
        self::assertTrue($this->products->updateDetails($id, $this->details('مهر قدیمی'), '2026-09-16 11:24:05'));
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
