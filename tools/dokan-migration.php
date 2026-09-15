<?php
/**
 * The Dokan migration, from the command line, on a DISPOSABLE WordPress.
 *
 * Runs the same services the admin screen runs, so what the evidence shows is
 * what a manager would get — not a second implementation that happens to agree.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/dokan-migration.php plan          اجرای آزمایشی، بدون نوشتن
 *   wp eval-file tools/dokan-migration.php import        ورود آزمایشی همان نقشه
 *   wp eval-file tools/dokan-migration.php runs          اجراهای انجام‌شده
 *   wp eval-file tools/dokan-migration.php rollback <id> بازگرداندن یک اجرا
 *   wp eval-file tools/dokan-migration.php fingerprint   اثر انگشت دادهٔ دکان
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;

$command = (string) ($args[0] ?? 'plan');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
        {
        }

        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});
$service = $c->get(ImportFromDokan::class);
$planFile = sys_get_temp_dir() . '/tmc-dokan-plan.json';

/** Everything of Dokan's this run can see, hashed — so "nothing changed" is measurable. */
$fingerprint = static function () use ($c) {
    $reader = $c->get(DokanReaderInterface::class);
    return hash('sha256', json_encode([
        'vendors' => $reader->vendors(),
        'products' => $reader->products(),
        'orders' => $reader->orders(),
    ], JSON_UNESCAPED_UNICODE));
};

switch ($command) {
    case 'fingerprint':
        printf("dokan_fingerprint=%s\n", $fingerprint());
        break;

    case 'plan':
        $plan = $service->plan();
        $summary = $plan->summary();
        file_put_contents($planFile, json_encode([
            'run_id' => $plan->runId,
            'vendors' => $plan->vendors,
            'products' => $plan->products,
            'orders' => $plan->orders,
            'generated_at' => $plan->generatedAt,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        printf(
            "plan run=%s vendors=%d products=%d orders=%d conflicts=%d skipped=%d clean=%s\n",
            $plan->runId,
            $summary['vendors'],
            $summary['products'],
            count($plan->orders),
            $summary['conflicts'],
            $summary['skipped'],
            $plan->isClean() ? 'true' : 'false'
        );
        foreach (['vendors', 'products', 'orders'] as $group) {
            foreach ($plan->{$group} as $row) {
                printf(
                    "  %s dokan=%d verdict=%s reason=%s target=%s title=%s\n",
                    rtrim($group, 's'),
                    $row['dokan_id'],
                    $row['verdict'],
                    $row['reason'] === '' ? '-' : $row['reason'],
                    $row['target'],
                    $row['title']
                );
            }
        }
        break;

    case 'import':
        if (!is_file($planFile)) {
            echo "no plan on file; run `plan` first\n";
            return;
        }
        $stored = json_decode((string) file_get_contents($planFile), true);
        $plan = new \Tecteb\Marketplace\Modules\Migration\Application\DokanMigrationPlan(
            (string) $stored['run_id'],
            $stored['vendors'],
            $stored['products'],
            $stored['orders'],
            (string) $stored['generated_at']
        );
        $result = $service->import($plan);
        printf(
            "import ok=%s code=%s run=%s vendors=%s products=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['run_id'] ?? '-'),
            (string) ($result->context['vendors'] ?? '0'),
            (string) ($result->context['products'] ?? '0')
        );
        break;

    case 'runs':
        $runs = $service->runs();
        printf("runs count=%d\n", count($runs));
        foreach ($runs as $runId => $run) {
            printf(
                "  run=%s at=%s vendors=%s products=%s\n",
                $runId,
                (string) ($run['at'] ?? ''),
                implode(',', $run['vendors'] ?? []) ?: '-',
                implode(',', $run['products'] ?? []) ?: '-'
            );
        }
        break;

    case 'rollback':
        $result = $service->rollback((string) ($args[1] ?? ''));
        printf(
            "rollback ok=%s code=%s vendors=%s products=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['vendors'] ?? '0'),
            (string) ($result->context['products'] ?? '0')
        );
        break;

    default:
        echo "usage: plan | import | runs | rollback <run-id> | fingerprint\n";
        break;
}
