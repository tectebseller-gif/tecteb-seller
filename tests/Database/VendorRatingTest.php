<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Marketplace\Domain\RatingStatus;
use Tecteb\Marketplace\Modules\Marketplace\Domain\VendorRating;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\DbRatingRepository;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0012VendorRatings;

/**
 * The three rules `tmc_vendor_ratings` puts in the SCHEMA rather than in a
 * caller, measured on a real MariaDB.
 *
 * Each of them is here because the alternative — a check in PHP — is a check
 * two requests can both pass.
 */
final class VendorRatingTest extends DatabaseTestCase
{
    private DbRatingRepository $ratings;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach (M0012VendorRatings::TABLES as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);
        $this->ratings = new DbRatingRepository($db, new SystemClock());
    }

    public function testOnePurchaseIsRatedOnce(): void
    {
        self::assertTrue($this->ratings->add($this->rating(7, 501, 5)));
        // Not an exception: a double-clicked form is an ordinary thing to
        // survive, so the second attempt is a plain false.
        self::assertFalse($this->ratings->add($this->rating(7, 501, 1)));
        $stored = $this->ratings->findByOrderItem(501);
        self::assertNotNull($stored);
        self::assertSame(5, $stored->stars, 'the FIRST rating stands; the second changed nothing');
    }

    public function testOnlyApprovedRatingsCount(): void
    {
        $this->ratings->add($this->rating(7, 601, 5));
        $this->ratings->add($this->rating(7, 602, 3));
        self::assertSame(
            ['count' => 0, 'average_hundredths' => 0],
            array_intersect_key($this->ratings->summaryFor(7), ['count' => 0, 'average_hundredths' => 0]),
            'nothing counts before a manager has looked'
        );

        $first = $this->ratings->findByOrderItem(601);
        self::assertNotNull($first);
        self::assertTrue($this->ratings->moderate($first->id, RatingStatus::Approved, 9, '', '2026-09-16 10:00:00'));
        $summary = $this->ratings->summaryFor(7);
        self::assertSame(1, $summary['count']);
        self::assertSame(500, $summary['average_hundredths'], 'hundredths, so every screen rounds the same way');
        self::assertSame(1, $summary['distribution'][5]);

        // A rejection is kept, explained, and counted nowhere.
        $second = $this->ratings->findByOrderItem(602);
        self::assertNotNull($second);
        $this->ratings->moderate($second->id, RatingStatus::Rejected, 9, 'خارج از موضوع', '2026-09-16 10:05:00');
        self::assertSame(1, $this->ratings->summaryFor(7)['count']);
        $rejected = $this->ratings->find($second->id);
        self::assertNotNull($rejected);
        self::assertSame('خارج از موضوع', $rejected->moderationReason);
        self::assertSame('نظر آزمایشی', $rejected->body, 'a rejected rating keeps what was written');
    }

    public function testAShopCannotAnswerAnotherShopsRating(): void
    {
        $this->ratings->add($this->rating(7, 701, 4));
        $rating = $this->ratings->findByOrderItem(701);
        self::assertNotNull($rating);

        // The shop id is in the WHERE clause, not only in a caller's check.
        self::assertFalse($this->ratings->reply($rating->id, 8, 'نه مال من', '2026-09-16 10:00:00'));
        self::assertTrue($this->ratings->reply($rating->id, 7, 'ممنون', '2026-09-16 10:00:00'));
        // One answer, not a thread: a second reply finds no unanswered row.
        self::assertFalse($this->ratings->reply($rating->id, 7, 'و یک چیز دیگر', '2026-09-16 10:01:00'));

        $answered = $this->ratings->find($rating->id);
        self::assertNotNull($answered);
        self::assertSame('ممنون', $answered->reply);
    }

    public function testTheQueueIsOldestFirstAndTheShopsListIsNewestFirst(): void
    {
        foreach ([801, 802, 803] as $i => $orderItemId) {
            $this->ratings->add($this->rating(7, $orderItemId, $i + 3));
        }
        $queue = $this->ratings->queue(RatingStatus::Pending);
        self::assertSame(801, $queue[0]->orderItemId, 'a queue is work to get through, oldest first');
        $mine = $this->ratings->forVendor(7);
        self::assertSame(803, $mine[0]->orderItemId, 'a shop reads its own newest first');
    }

    private function rating(int $vendorUserId, int $orderItemId, int $stars): VendorRating
    {
        return new VendorRating(
            0,
            $vendorUserId,
            42,
            $orderItemId,
            $stars,
            'نظر آزمایشی',
            RatingStatus::Pending,
            '',
            null,
            null,
            null,
            '',
            '2026-09-16 09:00:00',
            '2026-09-16 09:00:00'
        );
    }
}
