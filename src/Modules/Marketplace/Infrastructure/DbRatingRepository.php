<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\RatingRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Domain\RatingStatus;
use Tecteb\Marketplace\Modules\Marketplace\Domain\VendorRating;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0012VendorRatings as T;

/**
 * Vendor ratings, on the plugin's own schema.
 *
 * Two shapes worth naming, both of which put a rule in the SQL rather than in
 * a caller that can forget it:
 *
 *  - **`INSERT IGNORE`** for a new rating, so «one rating per purchase» is the
 *    unique index's answer. A `SELECT` then `INSERT` is how two requests both
 *    pass the check — the same lesson notifications learned.
 *  - **`AND vendor_user_id = %d` on the reply**, so a shop answering a guessed
 *    id answers nothing. The service checks ownership too; one of the two is a
 *    race, both of them is not.
 *
 * And one absence: nothing here deletes a rating. `moderate()` moves a status
 * and records who and why.
 *
 * The average is computed in SQL and returned in HUNDREDTHS of a star, not as a
 * float. A float would be rounded differently by the screen, the report and the
 * storefront badge, and «۴٫۳۳» has to mean one number in all three.
 */
final class DbRatingRepository implements RatingRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function add(VendorRating $rating): bool
    {
        $now = $rating->createdAt !== '' ? $rating->createdAt : $this->now();
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->t() . '`
             (vendor_user_id, buyer_user_id, order_item_id, stars, body, status,
              reply, replied_at, moderated_by, moderated_at, moderation_reason, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, %s, NULL, NULL, NULL, %s, %s, %s)',
            [
                $rating->vendorUserId,
                $rating->buyerUserId,
                $rating->orderItemId,
                $rating->stars,
                $rating->body,
                $rating->status->value,
                '',
                '',
                $now,
                $now,
            ]
        );
        return $written !== null && $written > 0;
    }

    public function find(int $ratingId): ?VendorRating
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->t() . '` WHERE id = %d', [$ratingId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findByOrderItem(int $orderItemId): ?VendorRating
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->t() . '` WHERE order_item_id = %d', [$orderItemId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function forVendor(int $vendorUserId, ?RatingStatus $status = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT * FROM `' . $this->t() . '` WHERE vendor_user_id = %d';
        $params = [$vendorUserId];
        if ($status !== null) {
            $sql .= ' AND status = %s';
            $params[] = $status->value;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);
        return array_map([$this, 'hydrate'], $this->db->getResults($sql, $params));
    }

    public function queue(?RatingStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM `' . $this->t() . '`';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = %s';
            $params[] = $status->value;
        }
        // Oldest first, unlike every other list here: a moderation queue is
        // work to get through, and the thing waiting longest is the thing
        // somebody is waiting on.
        $sql .= ' ORDER BY id ASC LIMIT %d OFFSET %d';
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);
        return array_map([$this, 'hydrate'], $this->db->getResults($sql, $params));
    }

    public function reply(int $ratingId, int $vendorUserId, string $reply, string $at): bool
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->t() . '`
             SET reply = %s, replied_at = %s, updated_at = %s
             WHERE id = %d AND vendor_user_id = %d AND (reply IS NULL OR reply = %s)',
            [$reply, $at, $at, $ratingId, $vendorUserId, '']
        );
        return $written !== null && $written > 0;
    }

    public function moderate(int $ratingId, RatingStatus $status, int $actorId, string $reason, string $at): bool
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->t() . '`
             SET status = %s, moderated_by = %d, moderated_at = %s, moderation_reason = %s, updated_at = %s
             WHERE id = %d',
            [$status->value, $actorId, $at, $reason, $at, $ratingId]
        );
        return $written !== null;
    }

    public function summaryFor(int $vendorUserId): array
    {
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS c, COALESCE(ROUND(AVG(stars) * 100), 0) AS avg_h
             FROM `' . $this->t() . '` WHERE vendor_user_id = %d AND status = %s',
            [$vendorUserId, RatingStatus::Approved->value]
        );
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($this->db->getResults(
            'SELECT stars, COUNT(*) AS c FROM `' . $this->t() . '`
             WHERE vendor_user_id = %d AND status = %s GROUP BY stars',
            [$vendorUserId, RatingStatus::Approved->value]
        ) as $bucket) {
            $star = (int) $bucket['stars'];
            if (isset($distribution[$star])) {
                $distribution[$star] = (int) $bucket['c'];
            }
        }
        return [
            'count' => (int) ($row['c'] ?? 0),
            'average_hundredths' => (int) ($row['avg_h'] ?? 0),
            'distribution' => $distribution,
        ];
    }

    public function summaries(array $vendorUserIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $vendorUserIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        // The ids are cast to int above, so the IN list is built from integers
        // and never from anything a caller typed.
        $in = implode(',', $ids);
        $out = [];
        foreach ($this->db->getResults(
            'SELECT vendor_user_id, COUNT(*) AS c, COALESCE(ROUND(AVG(stars) * 100), 0) AS avg_h
             FROM `' . $this->t() . '`
             WHERE status = %s AND vendor_user_id IN (' . $in . ')
             GROUP BY vendor_user_id',
            [RatingStatus::Approved->value]
        ) as $row) {
            $out[(int) $row['vendor_user_id']] = [
                'count' => (int) $row['c'],
                'average_hundredths' => (int) $row['avg_h'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): VendorRating
    {
        return new VendorRating(
            (int) $row['id'],
            (int) $row['vendor_user_id'],
            (int) $row['buyer_user_id'],
            (int) $row['order_item_id'],
            (int) $row['stars'],
            (string) ($row['body'] ?? ''),
            RatingStatus::fromString((string) $row['status']) ?? RatingStatus::Pending,
            (string) ($row['reply'] ?? ''),
            $row['replied_at'] !== null ? (string) $row['replied_at'] : null,
            $row['moderated_by'] !== null ? (int) $row['moderated_by'] : null,
            $row['moderated_at'] !== null ? (string) $row['moderated_at'] : null,
            (string) ($row['moderation_reason'] ?? ''),
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }

    private function t(): string
    {
        return T::table($this->db, T::VENDOR_RATINGS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
