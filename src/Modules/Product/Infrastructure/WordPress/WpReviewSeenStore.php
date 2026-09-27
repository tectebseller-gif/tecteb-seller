<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeenStoreInterface;

/**
 * The marks in user meta — one row per manager.
 *
 * **User meta and not a new table, on purpose.** A view state has exactly one
 * owner and WordPress already indexes by user, so the whole feature adds no
 * table, no column and no schema version: the direct `alpha.32 → alpha.36`
 * upgrade path is untouched and there is nothing to roll back but a meta row.
 * The precedent is `WpProductDraftStore`, which keeps a person's unfinished
 * forms the same way and for the same reason.
 *
 * **Not a transient.** A transient expires, and an expired mark makes a badge
 * come back red about a submission somebody already read.
 *
 * **Bounded, and the oldest goes first.** Without a cap this is one meta value
 * read on every wp-admin request that grows with everything the manager has ever
 * opened. Five hundred is far more than a review queue anybody works through in
 * a sitting; past it the oldest marks are dropped, and a dropped mark makes its
 * product read as UNSEEN again. That is the safe direction — the failure is a
 * manager being shown something twice, not a submission nobody is told about —
 * and it is stated here rather than discovered.
 *
 * **A lost write leaves the item unseen.** Two tabs recording views at the same
 * time are a read-modify-write on one meta row, so one of them can lose its
 * marks. That, too, fails towards «still unseen». It is the reason this is meta
 * and not a decision: a business decision could not be allowed to work this way.
 */
final class WpReviewSeenStore implements ReviewSeenStoreInterface
{
    public const META_KEY = 'tmc_review_seen';

    /** How many marks one manager may carry. */
    public const MAX_MARKS = 500;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function seenBy(int $userId): array
    {
        $out = [];
        foreach ($this->all($userId) as $productId => $mark) {
            $token = is_array($mark) ? ($mark['token'] ?? null) : null;
            if (!is_string($token) || $token === '') {
                continue;
            }
            $out[(int) $productId] = $token;
        }
        return $out;
    }

    public function markSeen(int $userId, array $seen): bool
    {
        if ($userId <= 0 || $seen === []) {
            return false;
        }
        $marks = $this->all($userId);
        $at = $this->clock->now()->format('Y-m-d H:i:s');
        foreach ($seen as $productId => $token) {
            $productId = (int) $productId;
            if ($productId <= 0 || !is_string($token) || $token === '') {
                continue;
            }
            // Written even when the token is the one already stored, so `at`
            // moves: the cap drops the OLDEST, and «oldest» has to mean «looked
            // at longest ago» rather than «first ever recorded».
            $marks[(string) $productId] = ['token' => $token, 'at' => $at];
        }
        if (count($marks) > self::MAX_MARKS) {
            uasort($marks, static fn (array $a, array $b): int => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));
            $marks = array_slice($marks, -self::MAX_MARKS, null, true);
        }
        return update_user_meta($userId, self::META_KEY, $marks) !== false;
    }

    public function forget(int $userId): bool
    {
        return delete_user_meta($userId, self::META_KEY) !== false;
    }

    /** @return array<string,mixed> */
    private function all(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $raw = get_user_meta($userId, self::META_KEY, true);
        return is_array($raw) ? $raw : [];
    }
}
