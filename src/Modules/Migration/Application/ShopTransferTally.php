<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Core\Jobs\JobBudget;

/**
 * The whole shop in one answer: what every imported row is waiting for,
 * grouped by reason — not one page of fifty.
 *
 * ### The number this is for
 *
 * The staged-transfer screen reports its three counts PER PAGE
 * (`MigrationPage.php:352`), and `OwnershipTransferPlan::SCAN_LIMIT` is 50.
 * So on shop 102 the owner read «آماده انتقال در صفحه فعلی: ۰ · دارای ریسک یا
 * اختلاف در صفحه فعلی: ۵۰» against «کل محصولات فروشگاه: ۱۸۴» and had no way
 * to know whether the other 134 rows were the same or different — four pages
 * of clicking to find out, and nothing adding up at the end. The owner's §4:
 * «وضعیت تمام ۱۸۴ محصول، نه فقط ۵۰ محصول هر صفحه، قابل مشاهده باشد.»
 *
 * ### Grouped, because 184 rows of prose is not a report
 *
 * Rows are collapsed on `bucket` + the exact Persian sentence, the
 * `signature()` idiom `alpha.81` used for the same problem one module over.
 * A catalogue imported by one defective path produces a handful of distinct
 * reasons, so 184 rows become a few lines with a count and the ids — and
 * every product IS named, inside the group whose sentence is its reason.
 *
 * ### ⛔ Bounded, resumable, and honest about not finishing
 *
 * One `detail()` read per row is the cost `alpha.91` got the per-page screen
 * down to, and 184 of them is 184 product loads in one admin request. On a
 * site that has already shown request timeouts (`alpha.75`) this walk asks
 * the budget between rows and STOPS cleanly, exactly as the import does — and
 * then says so: `finished` is false, `scanned` says how far it got, and
 * `cursor` carries the position for a «continue from here» link.
 *
 * Nothing is stored — no option, no transient, no meta. The position travels
 * in the query string (`alpha.85`), so two managers reading the page at once
 * cannot overwrite each other's place in a catalogue neither of them is
 * changing.
 *
 * ### Read-only, enforced by the absence of a method
 *
 * There is no write of any kind here. It holds a planner and a budget, and
 * `ShopTransferTallyIsReadOnlyTest` asserts that from this file's bytes.
 */
final class ShopTransferTally
{
    /**
     * Ids listed per group before the rest are counted rather than named.
     *
     * A line of sixty numbers is already long; what the next sixty would add
     * is width, not information, and the count beside them is exact either
     * way. Stated on the screen whenever it bites.
     */
    public const IDS_PER_GROUP = 60;

    /**
     * How many pages of `SCAN_LIMIT` this walk may read in one request.
     *
     * ⛔ A DECLARED ceiling, because an admin request is not a job. Forty
     * pages of fifty is two thousand rows, which covers shop 102's 184 with
     * room to spare and still bounds a catalogue nobody has measured yet —
     * and the whole timeout history of this plugin says an unbounded loop in
     * a page render is the shape that stalls (`alpha.75`). When it is hit the
     * answer says so: `finished` is false and `stopped_for_pages` is true, so
     * a partial count is never printed as the whole (`alpha.85`).
     */
    public const MAX_PAGES = 40;

    public function __construct(
        private readonly OwnershipTransferPlan $plan,
        /**
         * Optional, so a build without it walks the whole shop in one go —
         * the behaviour before this class existed rather than a wrong new
         * one. Its absence is reported as `budgeted: false`, because a page
         * that stopped early and a page that never asked are different
         * answers (`alpha.68`).
         */
        private readonly ?JobBudget $budget = null
    ) {
    }

    /**
     * Every observed row of one shop, or as many as the budget allowed.
     *
     * @return array{
     *     vendor_user_id:int,
     *     counts:array<string,int>,
     *     groups:list<array{bucket:string, why:string, count:int, ids:list<int>, more:int}>,
     *     scanned:int,
     *     observed_total:int,
     *     cursor:int,
     *     finished:bool,
     *     budgeted:bool,
     *     stopped_for_budget:bool,
     *     stopped_for_pages:bool,
     *     pages:int,
     *     checked:bool
     * }
     */
    public function of(int $vendorUserId, int $afterId = 0): array
    {
        $counts = array_fill_keys(OwnershipTransferPlan::BUCKETS, 0);
        $groups = [];
        $cursor = $afterId;
        $scanned = 0;
        $finished = false;
        $stoppedForBudget = false;
        $stoppedForPages = false;
        $pages = 0;
        $checked = true;
        $observedTotal = 0;

        while (true) {
            // ⛔ Asked BETWEEN pages and never inside one, for the reason the
            // import gives: stopping inside a unit is what the host already
            // does, and the whole point of asking is to not be there when it
            // happens. A page already read is a page already paid for.
            if ($this->budget !== null && $scanned > 0 && !$this->budget->roomForAnotherUnit()) {
                $stoppedForBudget = true;
                break;
            }
            if ($pages >= self::MAX_PAGES) {
                $stoppedForPages = true;
                break;
            }
            $pages++;
            $started = microtime(true);
            $page = $this->plan->plan($vendorUserId, $cursor, OwnershipTransferPlan::SCAN_LIMIT);
            $observedTotal = (int) $page['observed_total'];
            // ⛔ AND, never assignment. A later page that could ask does
            // not undo an earlier one that could not: «نمی‌دانم» about any
            // part of the shop is «نمی‌دانم» about the tally, and the
            // conservative direction for a claim about the whole is the
            // weakest answer any part gave (`alpha.89`).
            $checked = $checked && (bool) $page['checked'];
            foreach ((array) $page['rows'] as $row) {
                $bucket = (string) ($row['bucket'] ?? '');
                $why = (string) ($row['why'] ?? '');
                if (!array_key_exists($bucket, $counts)) {
                    // A bucket this class has never heard of. Counted under
                    // its own name rather than dropped: a tally that silently
                    // loses a row is worse than one that prints a word nobody
                    // recognises.
                    $counts[$bucket] = 0;
                }
                $counts[$bucket]++;
                $scanned++;
                $key = $bucket . "\0" . $why;
                if (!isset($groups[$key])) {
                    $groups[$key] = ['bucket' => $bucket, 'why' => $why, 'count' => 0, 'ids' => [], 'more' => 0];
                }
                $groups[$key]['count']++;
                if (count($groups[$key]['ids']) < self::IDS_PER_GROUP) {
                    $groups[$key]['ids'][] = (int) ($row['product_id'] ?? 0);
                } else {
                    $groups[$key]['more']++;
                }
            }
            // The planner's own «I reached the end», never a count: a read
            // that came back short for any other reason must not read as
            // «nothing left» (`alpha.85`).
            $this->budget?->noteUnit((microtime(true) - $started) * 1000.0);
            $moved = (int) $page['cursor'];
            if ((bool) $page['finished']) {
                $cursor = $moved;
                $finished = true;
                break;
            }
            // ⛔ A page that did not move the cursor ends the walk.
            //
            // It cannot happen through `plan()` as written — the cursor is the
            // largest id of a non-empty page, and an empty page answers
            // `finished` — so this guard is about the day one of those two
            // facts changes. Without it the same page would be read for ever,
            // and «the page never came back» is a worse failure than «it
            // stopped early and said so».
            if ($moved <= $cursor) {
                break;
            }
            $cursor = $moved;
        }

        return [
            'vendor_user_id' => $vendorUserId,
            'counts' => $counts,
            'groups' => array_values($groups),
            'scanned' => $scanned,
            'observed_total' => $observedTotal,
            'cursor' => $cursor,
            'finished' => $finished,
            'budgeted' => $this->budget !== null,
            'stopped_for_budget' => $stoppedForBudget,
            'stopped_for_pages' => $stoppedForPages,
            'pages' => $pages,
            'checked' => $checked,
        ];
    }
}
