<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;

/**
 * Replacing a pending proposal is ONE unit of work — `alpha.49`'s first finding.
 *
 * **What it was.** Every caller superseded the pending row and then inserted
 * the new one, in two statements. A refused insert left the first half done:
 * no pending proposal at all, the title or brand the vendor was still waiting
 * on gone, the product out of the review queue, and «ذخیره نشد» the only thing
 * anybody was told. Measured on the `alpha.48` bytes with a real refusal
 * injected through `wpdb`'s own `query` filter: `pending_after=0`,
 * `earlier_status=superseded`, `in_needs_review_after=no`.
 *
 * **Why the test is here and not in the unit suite.** The question is what is
 * in the table afterwards, with the real transaction semantics underneath.
 * `FakeDatabase` can say which SQL was written; only MariaDB can say whether
 * the supersede came back.
 *
 * Every refusal is measured against the ROW, never against the return value.
 */
final class AtomicProposalReplacementTest extends DatabaseTestCase
{
    private const PRODUCT = 4901;
    private const VENDOR = 91;

    private WpDatabase $db;
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $this->table = M0005CreateProductTables::table($this->db, M0005CreateProductTables::REVISIONS);
    }

    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    /** Nothing to replace: one insert, and it is already atomic. */
    public function testWithNothingToReplaceItSimplyCreates(): void
    {
        $outcome = $this->repository()->replacePending(self::PRODUCT, self::VENDOR, $this->payload('اول'), 0);

        self::assertSame('created', $outcome['code']);
        self::assertGreaterThan(0, $outcome['id']);
        self::assertSame([$outcome['id']], $this->pendingIds());
    }

    /** The ordinary replacement: one pending proposal, and it is the new one. */
    public function testItReplacesThePendingProposalAndKeepsTheOldOneOnRecord(): void
    {
        $repository = $this->repository();
        $first = $repository->create(self::PRODUCT, self::VENDOR, $this->payload('اول'));

        $outcome = $repository->replacePending(self::PRODUCT, self::VENDOR, $this->payload('دوم'), $first);

        self::assertSame('replaced', $outcome['code']);
        self::assertSame([$outcome['id']], $this->pendingIds(), 'exactly one pending, and it is the new one');
        self::assertSame(ProductRevision::SUPERSEDED, $this->statusOf($first), 'and the old one is on record');
        self::assertSame('دوم', $repository->pendingFor(self::PRODUCT)?->payload['details']['title']);
    }

    /**
     * THE FINDING: the insert is refused, and the pending proposal survives.
     *
     * The refusal is real — `FailingDatabase` hands every other statement to
     * MariaDB and refuses this one by name — so what is asserted afterwards is
     * what the database actually holds after a rollback, not what a fake said
     * it would hold.
     */
    public function testARefusedInsertLeavesThePendingProposalExactlyWhereItWas(): void
    {
        $first = $this->repository()->create(self::PRODUCT, self::VENDOR, $this->payload('اول'));

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['INSERT INTO', 'tmc_product_revisions']);
        $outcome = (new DbProductRevisionRepository($failing, new SystemClock()))
            ->replacePending(self::PRODUCT, self::VENDOR, $this->payload('دوم'), $first);

        self::assertSame('storage_failed', $outcome['code']);
        self::assertSame(0, $outcome['id']);
        self::assertSame([$first], $this->pendingIds(), 'the vendor still has their proposal');
        self::assertSame(ProductRevision::PENDING, $this->statusOf($first));
        self::assertSame('اول', $this->repository()->pendingFor(self::PRODUCT)?->payload['details']['title']);
    }

    /**
     * NO UNIT OF WORK, SO NOTHING IS ATTEMPTED.
     *
     * `alpha.39`'s rule, and the reason it is a refusal rather than a
     * best-effort: closing the proposal on the hope that the insert lands is
     * precisely the failure this round exists to remove, and a build that
     * cannot hold two statements together has no way to take it back.
     */
    public function testWithoutATransactionNothingIsAttemptedAtAll(): void
    {
        $first = $this->repository()->create(self::PRODUCT, self::VENDOR, $this->payload('اول'));

        $failing = new FailingDatabase($this->db);
        $failing->refuseTransactions();
        $outcome = (new DbProductRevisionRepository($failing, new SystemClock()))
            ->replacePending(self::PRODUCT, self::VENDOR, $this->payload('دوم'), $first);

        self::assertSame('not_atomic', $outcome['code']);
        self::assertSame([$first], $this->pendingIds());
        self::assertSame(ProductRevision::PENDING, $this->statusOf($first));
        self::assertSame(1, $this->countRows(), 'and no second row was inserted either');
    }

    /**
     * ZERO ROWS FROM THE SUPERSEDE IS A LOST RACE, not «it already said that».
     *
     * The state is arranged directly rather than through a second actor, and
     * that is deliberate: a second writer on THIS connection is not a second
     * writer at all — its write lands inside the same transaction and comes
     * back with the rollback (`alpha.40`). What the loser of a real race meets
     * is this exact statement answering zero rows, so the test puts the row in
     * that state and asks the one question that matters: is a second pending
     * proposal queued behind theirs?
     */
    public function testAProposalDecidedInBetweenIsNotSupersededTwice(): void
    {
        $repository = $this->repository();
        $first = $repository->create(self::PRODUCT, self::VENDOR, $this->payload('اول'));
        self::assertTrue($repository->decide($first, ProductRevision::REJECTED, 7, 'مدیر رد کرد'));

        $outcome = $repository->replacePending(self::PRODUCT, self::VENDOR, $this->payload('دوم'), $first);

        self::assertSame('superseded_elsewhere', $outcome['code']);
        self::assertSame(0, $outcome['id']);
        self::assertSame([], $this->pendingIds(), 'no second pending proposal was queued');
        self::assertSame(ProductRevision::REJECTED, $this->statusOf($first), 'and the decision stands');
        self::assertSame(1, $this->countRows());
    }

    /** Two replacements in a row leave one pending proposal, not three. */
    public function testRepeatedReplacementsLeaveOnePendingProposal(): void
    {
        $repository = $this->repository();
        $first = $repository->create(self::PRODUCT, self::VENDOR, $this->payload('اول'));
        $second = $repository->replacePending(self::PRODUCT, self::VENDOR, $this->payload('دوم'), $first);
        $third = $repository->replacePending(self::PRODUCT, self::VENDOR, $this->payload('سوم'), $second['id']);

        self::assertSame('replaced', $third['code']);
        self::assertSame([$third['id']], $this->pendingIds());
        self::assertSame(3, $this->countRows(), 'and the history is append-only');
    }

    // ---------------------------------------------------------------- helpers

    private function repository(): DbProductRevisionRepository
    {
        return new DbProductRevisionRepository($this->db, new SystemClock());
    }

    /** @return array<string,mixed> */
    private function payload(string $title): array
    {
        return [
            'details' => ['title' => $title, 'sku' => 'SKU-49', 'stock' => 9],
            'specs' => [],
            'images' => [],
            'main_image_id' => 0,
        ];
    }

    /** @return list<int> */
    private function pendingIds(): array
    {
        $rows = $this->db->getResults(
            'SELECT id FROM `' . $this->table . '` WHERE product_id = %d AND status = %s ORDER BY id ASC',
            [self::PRODUCT, ProductRevision::PENDING]
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function statusOf(int $revisionId): string
    {
        return (string) $this->db->getVar(
            'SELECT status FROM `' . $this->table . '` WHERE id = %d',
            [$revisionId]
        );
    }

    private function countRows(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table . '` WHERE product_id = %d',
            [self::PRODUCT]
        );
    }
}
