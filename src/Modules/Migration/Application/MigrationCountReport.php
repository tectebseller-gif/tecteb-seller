<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\JobStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * Where each of the migration's numbers comes from — read only.
 *
 * ⛔ **Why this exists.** The owner read two pairs of figures that did not
 * agree and asked for the cause rather than a correction: «۱۹۰ از ۱۸۶» on the
 * queue page, and «۲ فروشنده و ۱۷۹ محصول» in the run ledger beside a
 * completion report saying ۱۸۴ products were imported. Both pairs are made of
 * numbers that are individually true and are answers to DIFFERENT questions,
 * which is exactly the shape that gets «fixed» by editing one of them. So
 * nothing here edits anything: it names the question each figure answers and
 * the read it came from, and leaves the arithmetic to a person.
 *
 * **It writes nothing, and cannot.** No write method, and the repositories it
 * holds are asked only for lists and counts. `ManageProducts` is nowhere near
 * it, and neither is the job repository's `checkpoint()` — the figures on a
 * finished job row are the record of what that job did, and a report that
 * could change them would be destroying the evidence it was built to explain.
 *
 * ### The two questions behind «۱۸۴» and «۱۷۹»
 *
 *  - **۱۸۴ is catalogue-wide.** `importCompletion('')` walks
 *    `observedFromImportRuns()` — every observed product row carrying ANY
 *    `import_run_id` — so it includes rows written by earlier attempts at the
 *    same shop. The owner's own report says the import was tried three times.
 *  - **۱۷۹ is one run's manifest.** `runs()` reads the stamp the run itself
 *    wrote, so it counts only what THAT run created.
 *
 * Two further reasons a mapped product can be missing from a manifest, both
 * real in this codebase:
 *
 *  - the SKIP branch of `importProductPage()` counts an already-mapped
 *    product as done and does NOT call `rememberRun()` — correct, because the
 *    run did not create it, and a reason for the manifest to be smaller; and
 *  - a product created in a dying attempt, before its checkpoint ran, has its
 *    row and its mapping and no manifest entry.
 *
 * ⛔ So a gap is NOT «five products are missing». It is «five mapped products
 * are not claimed by this run», and the list of their ids is printed so the
 * difference can be looked at rather than assumed.
 */
final class MigrationCountReport
{
    /** How many ids to name per bucket. A report, not a log. */
    public const NAMED = 50;

    public function __construct(
        private readonly ImportFromDokan $import,
        private readonly ProductRepositoryInterface $products,
        private readonly VendorRepositoryInterface $vendors,
        /**
         * Optional: without it the job half of the report says the row was
         * not read rather than printing nought, because «I could not ask» is
         * not «the job declared nothing».
         */
        private readonly ?JobRepositoryInterface $jobs = null
    ) {
    }

    /**
     * @return array{
     *     catalogue:array{source:string, mapped:int, complete:int, without_image:int, source_unreadable:int, duplicate_mappings:int, finished:bool},
     *     runs:list<array{run_id:string, source:string, vendors:int, products:int}>,
     *     manifest_total:int,
     *     mapped_in_no_manifest:array{count:int, ids:list<int>},
     *     jobs:array{read:bool, rows:list<array{id:int, type:string, status:string, declared_total:int, done:int, failed:int, accounted:int, over_declared:int}>}
     * }
     */
    public function report(int $limit = 500): array
    {
        $completion = $this->import->importCompletion('', $limit);

        $runs = [];
        $claimed = [];
        foreach ($this->import->runs() as $runId => $run) {
            $products = array_map('intval', (array) ($run['products'] ?? []));
            foreach ($products as $id) {
                $claimed[$id] = true;
            }
            $runs[] = [
                'run_id' => (string) $runId,
                // «manifest» means the run wrote its own stamp; «rows_only»
                // means the run was interrupted and the rows are all that is
                // left of it. A different fact, so a different word.
                'source' => (string) ($run['source'] ?? ''),
                'vendors' => count((array) ($run['vendors'] ?? [])),
                'products' => count($products),
            ];
        }

        // Mapped, and claimed by no run's manifest. The ids are named because
        // a count on its own invites the reading the owner refused: «پنج مورد
        // گم شده‌اند».
        $orphans = [];
        foreach ($this->products->observedFromImportRuns($limit) as $id) {
            if (!isset($claimed[(int) $id])) {
                $orphans[] = (int) $id;
            }
        }

        return [
            'catalogue' => [
                'source' => 'observedFromImportRuns',
                'mapped' => (int) $completion['mapped'],
                'complete' => (int) $completion['complete'],
                'without_image' => (int) $completion['without_image'],
                'source_unreadable' => (int) $completion['source_unreadable'],
                'duplicate_mappings' => (int) $completion['duplicate_mappings'],
                // A bounded read that stopped at its ceiling is not «no
                // more» (`alpha.69`), and this figure is the one a reader
                // would otherwise take for the whole catalogue.
                'finished' => (bool) $completion['finished'],
            ],
            'runs' => $runs,
            'manifest_total' => count($claimed),
            'mapped_in_no_manifest' => [
                'count' => count($orphans),
                'ids' => array_slice($orphans, 0, self::NAMED),
            ],
            'jobs' => $this->jobRows(),
        ];
    }

    /**
     * The import job rows, with the declared ceiling beside what was counted.
     *
     * `over_declared` is the difference the owner read as «۱۹۰ از ۱۸۶». It is
     * reported, not corrected: the row is the record of a job that has
     * finished, and the short number was the DENOMINATOR the enqueue
     * declared.
     *
     * @return array{read:bool, rows:list<array{id:int, type:string, status:string, declared_total:int, done:int, failed:int, accounted:int, over_declared:int}>}
     */
    private function jobRows(): array
    {
        if ($this->jobs === null) {
            return ['read' => false, 'rows' => []];
        }
        // ⛔ The VALUES, not the cases: `recent()` puts them straight into a
        // `status IN (...)` and an enum object cannot be a query parameter.
        // Measured, not read — the first version passed the cases and every
        // call threw «could not be converted to string».
        $statuses = array_map(
            static fn (JobStatus $status): string => $status->value,
            JobStatus::cases()
        );
        $rows = [];
        foreach ($this->jobs->recent($statuses, 50) as $job) {
            if (!in_array($job->type, [DokanImportJob::TYPE, DokanRecordImportJob::TYPE], true)) {
                continue;
            }
            $accounted = $job->done + $job->failed;
            $rows[] = [
                'id' => $job->id,
                'type' => $job->type,
                'status' => $job->status->value,
                'declared_total' => $job->total,
                'done' => $job->done,
                'failed' => $job->failed,
                'accounted' => $accounted,
                'over_declared' => $job->total > 0 ? max(0, $accounted - $job->total) : 0,
            ];
        }
        return ['read' => true, 'rows' => $rows];
    }

    /** How many shops each trace knows about, kept apart on purpose. */
    public function vendorTraces(): array
    {
        $fromRuns = [];
        foreach ($this->vendors->importRunIds() as $runId) {
            foreach ($this->vendors->idsFromImportRun($runId) as $id) {
                $fromRuns[(int) $id] = true;
            }
        }
        $fromProducts = [];
        foreach ($this->products->vendorsFromImportRuns() as $id) {
            $fromProducts[(int) $id] = true;
        }
        return [
            'profile_stamp' => count($fromRuns),
            'product_stamp' => count($fromProducts),
            // Migration 16 added the profile stamp and deliberately did not
            // backfill, so a shop imported by an earlier build carries a
            // stamp on its PRODUCTS and none on its profile. The two counts
            // differing is expected and is not a missing shop.
            'union' => count($fromRuns + $fromProducts),
        ];
    }
}
