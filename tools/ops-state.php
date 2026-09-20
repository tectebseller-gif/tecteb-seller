<?php
/**
 * Exercises the queue, the outbox, the audit read path and the setup
 * checklist on the disposable WordPress — through the plugin's own services,
 * never with hand-written SQL.
 *
 * Usage (wp eval-file tools/ops-state.php <command> [args]):
 *
 *   schema                 what the migration left behind
 *   queue-seed <n>         queue n jobs of a resumable test type
 *   queue-run [batches]    run batches and print each outcome
 *   queue-census           the counts the admin page shows
 *   queue-race             two workers claim; exactly one wins
 *   queue-kill             claim, checkpoint, then abandon the lease
 *   queue-resume           a fresh worker picks up the abandoned job
 *   outbox-record          record one event of every published type
 *   outbox-verify          re-derive each signature and compare
 *   outbox-deliver         run the delivery job; every row must end blocked
 *   audit-search [event]   read the trail back through the repository
 *   setup-state            the wizard's steps, computed from real state
 *   reader-page            Dokan reader paging, with no silent truncation
 *   reports-scale          the aggregate figures and how many queries
 *   reset                  remove everything this tool created
 *
 * Nothing here touches a real site: it refuses unless the database is the
 * disposable `tmc_wp_test`.
 */

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Contracts\OutboxRepositoryInterface;
use Tecteb\Marketplace\Core\Events\EventSigner;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobBatch;
use Tecteb\Marketplace\Core\Jobs\JobHandlerInterface;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Jobs\JobStatus;
use Tecteb\Marketplace\Core\Migration\Migrations\M0013JobsAndOutbox as T;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler;
use Tecteb\Marketplace\Modules\Operations\Application\RecordEvent;
use Tecteb\Marketplace\Modules\Operations\Application\SetupChecklist;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file WRITES test data — shops, products, orders, refunds. On the
// owner's site that is not a seeding tool, it is damage. A docblock saying
// «DISPOSABLE» is documentation, not a guard: it stops nobody who pastes the
// command at the wrong shell.
//
// Two independent facts, the same pair tools/disposable-site.sh already
// trusts: the database must be the disposable one by name, and the site must
// be on a host nobody outside the container can reach. Deliberately NOT
// wp_get_environment_type(), which reports `production` on the disposable
// container itself because nobody set the constant.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


if (DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refusing: not the disposable database (DB_NAME=" . DB_NAME . ")\n");
    exit(2);
}

global $wpdb;
// wp-cli hands eval-file its positional arguments as $args.
$command = (string) ($args[0] ?? 'schema');
$container = Bootstrap::container();

/** @var JobRepositoryInterface $jobs */
$jobs = $container->get(JobRepositoryInterface::class);
/** @var OutboxRepositoryInterface $outbox */
$outbox = $container->get(OutboxRepositoryInterface::class);

/**
 * A handler that exists only for the evidence: it walks to a number, one step
 * per batch, so «resumes from the checkpoint» is something that can be
 * MEASURED rather than described.
 */
final class TmcEvidenceWalkJob implements JobHandlerInterface
{
    public const TYPE = 'evidence_walk';

    public function type(): string
    {
        return self::TYPE;
    }

    public function batchSize(): int
    {
        return 1;
    }

    public function step(Job $job): JobBatch
    {
        $at = (int) ($job->cursor['at'] ?? 0);
        $target = (int) ($job->payload['to'] ?? 3);
        $at++;
        return $at >= $target
            ? JobBatch::finished(['at' => $at], 1, 0, $target)
            : JobBatch::progress(['at' => $at], 1, 0, $target);
    }
}

function tmc_runner(JobRepositoryInterface $jobs): JobRunner
{
    $runner = new JobRunner($jobs, static fn (): string => bin2hex(random_bytes(8)));
    $runner->register(new TmcEvidenceWalkJob());
    return $runner;
}

switch ($command) {
    case 'schema':
        echo 'schema=', get_option('tmc_schema_version'), "\n";
        foreach (T::TABLES as $suffix) {
            $table = $wpdb->prefix . $suffix;
            echo $suffix, '=', ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") ? 'present' : 'MISSING'), "\n";
        }
        foreach ([T::JOBS => 'tmc_job_live', T::OUTBOX => 'tmc_outbox_event'] as $suffix => $index) {
            $found = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                $wpdb->prefix . $suffix,
                $index
            ));
            echo 'index_', $index, '=', ((int) $found > 0 ? 'present' : 'MISSING'), "\n";
        }
        do_action('init');
        echo 'cron_binding=', WpJobScheduler::binding(), "\n";
        $status = WpJobScheduler::status();
        echo 'cron_scheduled=', ($status['next'] > 0 ? 'yes' : 'no'), "\n";
        break;

    case 'queue-seed':
        $n = max(1, (int) ($args[1] ?? 1));
        for ($i = 1; $i <= $n; $i++) {
            $queued = $jobs->enqueue(TmcEvidenceWalkJob::TYPE, ['to' => 3], 'walk-' . $i, 1);
            echo 'queued id=', $queued['id'], ' created=', $queued['created'] ? '1' : '0', "\n";
        }
        // The same key again: one LIVE job per key, decided by the index.
        $again = $jobs->enqueue(TmcEvidenceWalkJob::TYPE, ['to' => 3], 'walk-1', 1);
        echo 'duplicate id=', $again['id'], ' created=', $again['created'] ? '1' : '0', "\n";
        break;

    case 'queue-run':
        $batches = max(1, (int) ($args[1] ?? 1));
        foreach (tmc_runner($jobs)->run($batches) as $result) {
            echo 'job=', $result['job'], ' outcome=', $result['outcome'],
                 ' done=', $result['done'], ' error=', ($result['error'] !== '' ? $result['error'] : '-'), "\n";
        }
        break;

    case 'queue-census':
        foreach ($jobs->census() as $state => $count) {
            echo $state, '=', $count, "\n";
        }
        break;

    case 'queue-race':
        // The property is «two workers cannot take THE SAME job», not «only one
        // worker ever gets work» — a queue with other jobs in it is supposed to
        // hand a second worker a different one. The first version of this check
        // measured the latter and reported a failure that was not one, on a
        // queue that simply had a leftover pending job. So: clear the table,
        // queue exactly one, and compare the ids.
        $wpdb->query('DELETE FROM `' . $wpdb->prefix . T::JOBS . '`');
        $queued = $jobs->enqueue(TmcEvidenceWalkJob::TYPE, ['to' => 9], 'race', 1);
        $a = $jobs->claim([TmcEvidenceWalkJob::TYPE], 'token-a', 300);
        $b = $jobs->claim([TmcEvidenceWalkJob::TYPE], 'token-b', 300);
        echo 'job=', $queued['id'], "\n";
        echo 'only_job_in_queue=', (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $wpdb->prefix . T::JOBS . '`'), "\n";
        echo 'worker_a=', $a === null ? 'lost' : ('won job ' . $a->id), "\n";
        echo 'worker_b=', $b === null ? 'lost' : ('won job ' . $b->id), "\n";
        echo 'winners=', (int) ($a !== null) + (int) ($b !== null), "\n";
        echo 'same_job_twice=', ($a !== null && $b !== null && $a->id === $b->id) ? 'yes' : 'no', "\n";
        echo 'returned_token=', $a?->lockToken ?? ($b?->lockToken ?? '-'), "\n";
        // And the token the row actually holds is the winner's.
        echo 'row_token=', (string) $wpdb->get_var($wpdb->prepare(
            'SELECT lock_token FROM `' . $wpdb->prefix . T::JOBS . '` WHERE id = %d',
            $queued['id']
        )), "\n";
        break;

    case 'queue-kill':
        $queued = $jobs->enqueue(TmcEvidenceWalkJob::TYPE, ['to' => 5], 'killed', 1);
        $claimed = $jobs->claim([TmcEvidenceWalkJob::TYPE], 'doomed-worker', 300);
        $jobs->checkpoint($claimed->id, 'doomed-worker', ['at' => 2], 2, 0, 5, 300);
        // The process dies HERE: no release, no finish, just a lease that will
        // run out. Forced into the past so expiry is measured, not waited for.
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . $wpdb->prefix . T::JOBS . '` SET locked_until = %s WHERE id = %d',
            gmdate('Y-m-d H:i:s', time() - 3600),
            $claimed->id
        ));
        echo 'job=', $queued['id'], "\n";
        echo 'cursor_written=', json_encode($jobs->find($queued['id'])->cursor), "\n";
        echo 'status_after_death=', $jobs->find($queued['id'])->status->value, "\n";
        echo 'stalled=', $jobs->census()['stalled'], "\n";
        break;

    case 'queue-resume':
        $runner = tmc_runner($jobs);
        $before = null;
        foreach ($jobs->recent([JobStatus::Running->value], 10) as $job) {
            if ($job->type === TmcEvidenceWalkJob::TYPE) {
                $before = $job;
                break;
            }
        }
        if ($before === null) {
            echo "resume=no_stalled_job\n";
            break;
        }
        echo 'resumed_job=', $before->id, "\n";
        echo 'cursor_before=', json_encode($before->cursor), "\n";
        echo 'done_before=', $before->done, "\n";
        $result = $runner->runOne();
        $after = $jobs->find($before->id);
        echo 'outcome=', $result['outcome'] ?? '-', "\n";
        echo 'cursor_after=', json_encode($after->cursor), "\n";
        echo 'done_after=', $after->done, "\n";
        echo 'restarted_from_zero=', ((int) ($after->cursor['at'] ?? 0) <= (int) ($before->cursor['at'] ?? 0) ? 'yes' : 'no'), "\n";
        break;

    case 'outbox-record':
        /** @var RecordEvent $recorder */
        $recorder = $container->get(RecordEvent::class);
        foreach (RecordEvent::SCHEMA as $type => $fields) {
            $data = [];
            foreach ($fields as $field) {
                $data[$field] = str_contains($field, '_id') || str_contains($field, 'minor')
                    ? 7
                    : 'نمونه';
            }
            // A field nobody declared, to prove the allowlist drops it.
            $data['bank_account'] = 'IR000000000000000000000000';
            $result = $recorder->record((string) $type, 7, 'evidence', '7', $data);
            echo $type, ' ok=', $result['ok'] ? '1' : '0', ' reason=', ($result['reason'] ?: '-'), "\n";
        }
        $unknown = $recorder->record('not.a.real.event', 7, 'evidence', '7', []);
        echo 'unknown_type ok=', $unknown['ok'] ? '1' : '0', ' reason=', $unknown['reason'], "\n";
        break;

    case 'outbox-verify':
        $secret = (string) get_option('tmc_event_secret', '');
        echo 'secret_stored=', ($secret !== '' ? 'yes' : 'no'), "\n";
        echo 'secret_length=', strlen($secret), "\n";
        $bad = 0;
        $leaked = 0;
        foreach ($outbox->recent([], 50) as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $timestamp = strtotime((string) ($payload['occurred_at'] ?? ''));
            $ok = EventSigner::verify($payload, (int) $timestamp, $secret, (string) $row['signature']);
            if (!$ok) {
                $bad++;
                echo 'BAD_SIGNATURE id=', $row['id'], "\n";
            }
            if (str_contains((string) $row['payload'], 'bank_account')) {
                $leaked++;
            }
        }
        echo 'signatures_checked=', count($outbox->recent([], 50)), "\n";
        echo 'signatures_bad=', $bad, "\n";
        echo 'undeclared_fields_on_the_wire=', $leaked, "\n";
        break;

    case 'outbox-deliver':
        $runner = new JobRunner($jobs, static fn (): string => bin2hex(random_bytes(8)));
        $runner->register(new Tecteb\Marketplace\Modules\Operations\Application\DeliverEventsJob(
            $outbox,
            $container->get(Tecteb\Marketplace\Core\Environment\EnvironmentResolver::class)
        ));
        $queued = $jobs->enqueue('event_delivery', [], 'event_delivery', 1);
        echo 'job=', $queued['id'], "\n";
        foreach ($runner->run(5) as $result) {
            echo 'outcome=', $result['outcome'], ' done=', $result['done'], "\n";
        }
        foreach ($outbox->census() as $state => $count) {
            echo 'state_', $state, '=', $count, "\n";
        }
        $sample = $outbox->recent(['blocked'], 1);
        echo 'blocked_reason=', $sample === [] ? '-' : $sample[0]['delivery_reason'], "\n";
        echo 'payload_unchanged=', $sample === [] ? '-'
            : (str_starts_with((string) $sample[0]['payload'], '{"event"') ? 'yes' : 'no'), "\n";
        echo 'signature_unchanged=', $sample === [] ? '-'
            : (str_starts_with((string) $sample[0]['signature'], 'v1=') ? 'yes' : 'no'), "\n";
        break;

    case 'audit-search':
        /** @var AuditRepositoryInterface $audit */
        $audit = $container->get(AuditRepositoryInterface::class);
        $event = (string) ($args[1] ?? '');
        echo 'total=', $audit->count([]), "\n";
        echo 'types=', count($audit->eventTypes()), "\n";
        $filtered = $audit->search($event === '' ? [] : ['event' => $event], 5, 0);
        echo 'filtered=', count($filtered), "\n";
        foreach ($filtered as $record) {
            echo 'row event=', $record->eventType,
                 ' actor=', $record->actorId ?? 0,
                 ' object=', ($record->objectType ?? '-') . ':' . ($record->objectId ?? '-'),
                 ' at=', $record->createdAtUtc->format('Y-m-d H:i'), "\n";
        }
        if ($event !== '') {
            $wrong = array_filter($filtered, static fn ($r): bool => $r->eventType !== $event);
            echo 'filter_leak=', count($wrong), "\n";
        }
        // The date filter must actually exclude.
        echo 'future_window=', $audit->count(['from' => '2099-01-01']), "\n";
        break;

    case 'setup-state':
        $settings = $container->get(Tecteb\Marketplace\Core\Config\SettingsService::class)->load();
        $state = [
            'woocommerce_active' => class_exists('WooCommerce'),
            'commission_rate_bp' => $settings->commissionRateBp,
            'commission_rules' => count($container->get(
                Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface::class
            )->all()),
            'document_types' => count($container->get(
                Tecteb\Marketplace\Modules\Vendor\Application\DocumentTypeRepositoryInterface::class
            )->requirementSet()->types()),
            'settlement_delay_days' => $settings->settlementDelayDays,
            'max_staff' => $settings->maxStaff,
            'vendor_page_reachable' => get_option('permalink_structure', '') !== '',
            'queue_binding' => WpJobScheduler::binding(),
            'decision_open' => true,
        ];
        $steps = SetupChecklist::steps($state);
        foreach ($steps as $step) {
            echo 'step=', $step['key'], ' status=', $step['status'],
                 ' blocker=', ($step['blocker'] !== '' ? $step['blocker'] : '-'), "\n";
        }
        foreach (SetupChecklist::summary($steps) as $key => $value) {
            echo 'summary_', $key, '=', $value, "\n";
        }
        break;

    case 'reader-page':
        $reader = $container->get(Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface::class);
        $counts = $reader->counts();
        foreach ($counts as $kind => $n) {
            echo 'count_', $kind, '=', $n, "\n";
        }
        // Walk in pages of two and prove the union equals the whole.
        $walked = [];
        $after = 0;
        for ($guard = 0; $guard < 500; $guard++) {
            $page = $reader->productsAfter($after, 2);
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $walked[] = (int) $row['wc_product_id'];
                $after = max($after, (int) $row['wc_product_id']);
            }
            if (count($page) < 2) {
                break;
            }
        }
        $whole = array_map(static fn (array $r): int => (int) $r['wc_product_id'], $reader->products());
        sort($walked);
        sort($whole);
        echo 'paged_products=', count($walked), "\n";
        echo 'whole_products=', count($whole), "\n";
        echo 'paged_equals_whole=', ($walked === $whole ? 'yes' : 'no'), "\n";
        echo 'strictly_ascending=', ($walked === array_values(array_unique($walked)) ? 'yes' : 'no'), "\n";
        echo 'orders_paged=', count($reader->ordersAfter(0, 2)), "\n";
        // The old bug: a hard LIMIT with nothing after it. The docblock now
        // EXPLAINS that bug and therefore contains the string, so the check
        // reads the code with the comments stripped — a grep that its own
        // explanation can fail is a grep that will be deleted.
        $source = (string) file_get_contents(WP_PLUGIN_DIR . '/tecteb-marketplace-core/src/Modules/Migration/Infrastructure/WordPress/WpDokanReader.php');
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
        echo 'hardcoded_limit_in_code=', (preg_match('/LIMIT\s+\d+/', $code) ? 'STILL THERE' : 'gone'), "\n";
        echo 'limit_is_a_placeholder=', (str_contains($code, 'LIMIT %d') ? 'yes' : 'no'), "\n";
        break;

    case 'reports-scale':
        $vendor = (int) ($args[1] ?? 0);
        if ($vendor === 0) {
            $vendor = (int) $wpdb->get_var('SELECT vendor_user_id FROM `' . $wpdb->prefix . 'tmc_products` LIMIT 1');
        }
        // The report asks StaffAccess whether the ACTOR may read this shop, so
        // the evidence has to act as somebody who may. Reading it as nobody
        // would return an empty array and prove nothing about the numbers.
        wp_set_current_user($vendor);
        $products = $container->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
        $wpdb->queries = [];
        $before = defined('SAVEQUERIES') && SAVEQUERIES ? count($wpdb->queries) : -1;
        $summary = $products->stockSummary($vendor, 5);
        $counts = $products->countsByStatus($vendor);
        echo 'vendor=', $vendor, "\n";
        foreach ($summary as $key => $value) {
            echo 'stock_', $key, '=', $value, "\n";
        }
        echo 'statuses=', count($counts), "\n";
        echo 'total_from_counts=', array_sum($counts), "\n";
        // And the report itself agrees with the repository.
        $report = $container->get(Tecteb\Marketplace\Modules\Marketplace\Application\Reports::class)
            ->forVendor($vendor, $vendor);
        $productCard = $report[Tecteb\Marketplace\Modules\Marketplace\Application\Reports::PRODUCTS] ?? [];
        $stockCard = $report[Tecteb\Marketplace\Modules\Marketplace\Application\Reports::STOCK] ?? [];
        echo 'report_readable=', ($report === [] ? 'no (actor may not read this shop)' : 'yes'), "\n";
        echo 'report_total=', $productCard['total'] ?? -1, "\n";
        echo 'report_on_sale=', $stockCard['on_sale'] ?? -1, "\n";
        // The report and the repository must reach the same numbers: if they
        // disagree, the aggregation replaced the loop with something else.
        echo 'agrees=', ((int) ($productCard['total'] ?? -1) === array_sum($counts)
            && (int) ($stockCard['on_sale'] ?? -1) === $summary['on_sale'] ? 'yes' : 'no'), "\n";
        $source = (string) file_get_contents(WP_PLUGIN_DIR . '/tecteb-marketplace-core/src/Modules/Marketplace/Application/Reports.php');
        echo 'reports_still_walk_the_catalogue=', (str_contains($source, 'allForVendor') ? 'yes' : 'no'), "\n";
        break;

    case 'reset':
        $wpdb->query('DELETE FROM `' . $wpdb->prefix . T::JOBS . '`');
        $wpdb->query('DELETE FROM `' . $wpdb->prefix . T::OUTBOX . '`');
        delete_option(SetupChecklist::SKIPPED_OPTION);
        echo "reset=ok\n";
        break;

    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(2);
}
