<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbReviewSeenStore;
use Tecteb\Marketplace\Modules\Product\Infrastructure\ReviewQueueSql;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the one claim `ReviewSeenLateWriteTest` deliberately does
 * not make — that the guard holds between two REAL connections.
 *
 * `markSeen()` takes no transaction, and the reason it does not need one is an
 * assumption about the database: the guard is in the same statement as the
 * write (`INSERT … SELECT … WHERE <identity>` with `ON DUPLICATE KEY UPDATE`),
 * and one statement is atomic under InnoDB. That assumption cannot be measured
 * on one connection, because there is nothing to contend for — so it was an
 * assumption with nothing behind it, which is what §7 of this round asked to be
 * closed or admitted.
 *
 * It is closed, with a bound: a second OS process with its own connection
 * (`tests/Support/concurrent-review-seen.php`), launched after the other
 * connection's change has COMMITTED. That is the state a slow request meets. It
 * is still not a claim about two statements executing in the same instant —
 * nothing here can schedule that, and this file does not pretend to.
 *
 * Both directions are measured, because «declined» alone is also what a broken
 * script answers.
 */
final class ReviewSeenConcurrencyTest extends DatabaseTestCase
{
    private const ANA = 51;
    private const VENDOR = 7;

    private const EARLY = '2030-04-01 09:00:00.000000';
    private const MIDDLE = '2030-04-01 09:00:10.000000';
    private const LATE = '2030-04-01 09:00:20.000000';

    private WpDatabase $db;
    private DbProductRepository $products;
    private DbProductDecisionRepository $decisions;
    private DbReviewSeenStore $store;
    private ReviewSeen $seen;

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        $this->decisions = new DbProductDecisionRepository($this->db, $clock);
        $this->store = new DbReviewSeenStore($this->db, $clock);
        $this->seen = new ReviewSeen($this->store);
    }

    /** The contract suite's stub wpdb is a real PDO on this database. */
    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    public function testALateWriteFromAnotherConnectionCannotPullTheMarkBackwards(): void
    {
        $productId = $this->submit('دستکش');
        self::assertSame('s1.r0', $this->identity($productId), 'the first submission');

        // The vendor resubmits, so the identity moves. Committed on THIS
        // connection before the other process starts, which is the state a
        // slow request finds.
        $this->resubmit($productId);
        self::assertSame('s2.r0', $this->identity($productId));

        // The fast request records the NEW view.
        self::assertTrue($this->storeAt(self::MIDDLE)->markSeen(self::ANA, [$productId => 's2.r0']));
        self::assertSame('s2.r0', $this->mark($productId));
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'nothing is waiting on Ana');

        // And now the slow request finishes — another process, its own
        // connection, a LATER clock, and the OLDER token. Under `alpha.37`'s
        // `seen_at` guard this won, because it wrote last.
        self::assertSame(
            'declined',
            $this->secondConnection(self::ANA, $productId, 's1.r0', self::LATE),
            'the older view must not be recorded'
        );
        self::assertSame('s2.r0', $this->mark($productId), 'the mark did not move backwards');
        self::assertSame(0, $this->seen->unseenCount(self::ANA), 'and no notification came back');
    }

    public function testTheSameConnectionlessWriteSucceedsWhenItCarriesTheCurrentIdentity(): void
    {
        // The positive half. Without it, «declined» above would also be the
        // answer a broken script, a missing table or a wrong user id gives —
        // «بررسی‌ای که نمی‌تواند رد شود، رد هم نشده است», the other way round.
        $productId = $this->submit('ماسک');
        self::assertSame([], $this->store->marksFor(self::ANA, [$productId]));

        self::assertSame(
            'wrote',
            $this->secondConnection(self::ANA, $productId, 's1.r0', self::EARLY),
            'the current identity, from the other connection, is recorded'
        );
        self::assertSame('s1.r0', $this->mark($productId));
        self::assertSame(0, $this->seen->unseenCount(self::ANA));
    }

    public function testAnIdentityThatMovedAfterTheOtherConnectionReadItIsDeclined(): void
    {
        // The reverse interleaving: the OTHER connection is the slow one and
        // this one moves the identity. Same guard, opposite roles, so the
        // result cannot be an artefact of which process wrote first.
        $productId = $this->submit('گاز استریل');
        self::assertSame(
            'wrote',
            $this->secondConnection(self::ANA, $productId, 's1.r0', self::EARLY)
        );

        $this->resubmit($productId);
        self::assertSame('s2.r0', $this->identity($productId));
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'the resubmission is unseen again');

        // The other connection writes the stale token once more, later.
        self::assertSame(
            'declined',
            $this->secondConnection(self::ANA, $productId, 's1.r0', self::LATE)
        );
        self::assertSame('s1.r0', $this->mark($productId), 'unchanged, not advanced');
        self::assertSame(1, $this->seen->unseenCount(self::ANA), 'and it is still waiting');
    }

    // ------------------------------------------------------------- fixture

    private function secondConnection(int $userId, int $productId, string $token, string $when): string
    {
        $command = escapeshellcmd(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__) . '/Support/concurrent-review-seen.php')
            . ' mark ' . escapeshellarg((string) $userId)
            . ' ' . escapeshellarg((string) $productId)
            . ' ' . escapeshellarg($token)
            . ' ' . escapeshellarg($when);
        $out = [];
        $status = 0;
        exec($command . ' 2>&1', $out, $status);
        self::assertSame(0, $status, 'second connection failed: ' . implode("\n", $out));
        return trim(implode('', $out));
    }

    private function storeAt(string $when): DbReviewSeenStore
    {
        return new DbReviewSeenStore($this->db, new class ($when) implements \Tecteb\Marketplace\Contracts\ClockInterface {
            public function __construct(private readonly string $when)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->when);
            }
        });
    }

    /** The identity the queue would show, read the way the page reads it. */
    private function identity(int $productId): string
    {
        [$columns, $columnParams] = ReviewQueueSql::submissionColumns($this->db);
        $row = $this->db->getRow(
            'SELECT p.id, ' . $columns . ' FROM `' . $this->db->prefix() . 'tmc_products` p WHERE p.id = %d',
            array_merge($columnParams, [$productId])
        );
        self::assertIsArray($row, 'product ' . $productId . ' should exist');
        return ReviewQueueSql::token($row);
    }

    private function mark(int $productId): string
    {
        return $this->store->marksFor(self::ANA, [$productId])[$productId] ?? '';
    }

    private function submit(string $title): int
    {
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 200000, sku: 'SEEN-' . $title, stock: 5),
            ProductStatus::Draft
        );
        self::assertGreaterThan(0, $id);
        $this->resubmit($id);
        return $id;
    }

    private function resubmit(int $id): void
    {
        self::assertTrue($this->products->updateStatus($id, ProductStatus::Submitted, ''));
        self::assertTrue($this->decisions->record($id, self::VENDOR, self::VENDOR, ProductDecision::SUBMITTED, ''));
    }
}
