<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as T;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0019BaselineAndDecisions;

/**
 * The two SQL fragments that define «what is waiting» and «which submission».
 *
 * They exist as one thing because they are read by two classes that must not be
 * able to disagree: `DbProductRepository` lists the queue and `DbReviewSeenStore`
 * counts what is unseen in it. `alpha.36` had the same `WHERE` written out in
 * three places and the same pair of correlated subselects in three more — which
 * worked, and which is exactly the shape this repository has already been
 * bitten by twice («دو فهرست یعنی دو قاعده»): the day one of them learns about
 * a new waiting state, the badge and the list stop describing the same set and
 * nothing fails.
 *
 * Each method returns the fragment AND its parameters, in order, because the
 * placeholders are positional: a caller that composed the strings itself would
 * have to remember that the columns bind before the `WHERE`, and that is the
 * kind of thing one is remembered wrongly.
 */
final class ReviewQueueSql
{
    /**
     * «این محصول منتظر تصمیم است» — submitted, or carrying an unanswered
     * proposal.
     *
     * `OR EXISTS` rather than a union of two counts: a product that is both
     * submitted and carries a proposal is still ONE thing to look at, and
     * counting it twice would send the manager looking for a decision that
     * does not exist.
     *
     * @return array{0:string,1:list<string>} fragment (parenthesised), params
     */
    public static function waiting(DatabaseInterface $db, string $alias = 'p'): array
    {
        return [
            '(' . $alias . '.status = %s'
                . ' OR EXISTS (SELECT 1 FROM `' . T::table($db, T::REVISIONS) . '` tmc_q'
                . ' WHERE tmc_q.product_id = ' . $alias . '.id AND tmc_q.status = %s))',
            [ProductStatus::Submitted->value, ProductRevision::PENDING],
        ];
    }

    /**
     * The identity of the submission on a row: `tmc_submission_id` and
     * `tmc_revision_id`.
     *
     * The newest `submitted` row in the append-only decision trail, and the id
     * of the unanswered proposal. Neither is a timestamp and neither is the
     * status, so an ordinary edit — a price, a stock number, a title — does not
     * move it, and a resubmission always does.
     *
     * Both are correlated and indexed, so they cost one lookup per row of
     * whatever the enclosing statement already limited itself to.
     *
     * @return array{0:string,1:list<string>} fragment (two columns), params
     */
    public static function submissionColumns(DatabaseInterface $db, string $alias = 'p'): array
    {
        return [
            '(SELECT MAX(tmc_d.id) FROM `'
                . M0019BaselineAndDecisions::table($db, M0019BaselineAndDecisions::DECISIONS) . '` tmc_d'
                . ' WHERE tmc_d.product_id = ' . $alias . '.id AND tmc_d.decision = %s) AS tmc_submission_id,'
                . ' (SELECT MAX(tmc_r.id) FROM `' . T::table($db, T::REVISIONS) . '` tmc_r'
                . ' WHERE tmc_r.product_id = ' . $alias . '.id AND tmc_r.status = %s) AS tmc_revision_id',
            [ProductDecision::SUBMITTED, ProductRevision::PENDING],
        ];
    }

    /**
     * The token, from a row that selected `submissionColumns()`.
     *
     * `s0.r0` is a real answer, not a missing one — a product submitted before
     * the decision trail existed (`alpha.29`) has no `submitted` row to point
     * at. It reads as unseen until a manager opens it and seen afterwards,
     * which is the documented behaviour for data that predates this feature. It
     * moves the moment the vendor submits again, because that writes a row.
     *
     * @param array<string,mixed> $row
     */
    public static function token(array $row): string
    {
        return 's' . (int) ($row['tmc_submission_id'] ?? 0) . '.r' . (int) ($row['tmc_revision_id'] ?? 0);
    }

    /**
     * `s12.r34` back into its two integers, or null when it is not a token.
     *
     * @return array{0:int|null,1:int}
     */
    public static function split(string $token): array
    {
        if (preg_match('/^s(\d+)\.r(\d+)$/', $token, $m) !== 1) {
            return [null, 0];
        }
        return [(int) $m[1], (int) $m[2]];
    }
}
