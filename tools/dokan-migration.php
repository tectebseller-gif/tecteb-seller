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
 *   wp eval-file tools/dokan-migration.php reset         پاک‌کردن باقیماندهٔ اجرای قبلی
 *   wp eval-file tools/dokan-migration.php stamps        مهرِ اجرا روی خود ردیف‌ها
 *   wp eval-file tools/dokan-migration.php forget-manifest <run>  شبیه‌سازی قطع میانه
 *   wp eval-file tools/dokan-migration.php import-orders <run>    تاریخچهٔ سفارش، صفحه‌به‌صفحه
 *   wp eval-file tools/dokan-migration.php history <vendor>       تاریخچهٔ سفارش دکان
 *   wp eval-file tools/dokan-migration.php rollback <id> بازگرداندن یک اجرا
 *   wp eval-file tools/dokan-migration.php fingerprint   اثر انگشت دادهٔ دکان
 *   wp eval-file tools/dokan-migration.php observed      ردیف‌های نگاشت‌شده
 *   wp eval-file tools/dokan-migration.php ownership <tmc-product-id>
 *   wp eval-file tools/dokan-migration.php take-ownership <tmc-product-id>
 *   wp eval-file tools/dokan-migration.php give-back-ownership <tmc-product-id>
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

    case 'audit-trail':
        // The trail is read through the same `search()` the audit PAGE uses,
        // not with hand-written SQL — so this asks the question a manager can
        // actually ask afterwards, rather than a question only a script can.
        $audit = $c->get(\Tecteb\Marketplace\Contracts\AuditRepositoryInterface::class);
        $runId = (string) ($args[1] ?? '');
        $of = static function (string $event) use ($audit, $runId): int {
            $filters = ['event' => $event];
            if ($runId !== '') {
                $filters['object_id'] = $runId;
            }
            return $audit->count($filters);
        };
        printf(
            "audit-trail run=%s dry_run=%d imported=%d rolled_back=%d\n",
            $runId === '' ? '<any>' : $runId,
            $of(\Tecteb\Marketplace\Core\Audit\AuditEventCatalog::DOKAN_DRY_RUN),
            $of(\Tecteb\Marketplace\Core\Audit\AuditEventCatalog::DOKAN_IMPORTED),
            $of(\Tecteb\Marketplace\Core\Audit\AuditEventCatalog::DOKAN_ROLLED_BACK)
        );
        break;

    case 'dokan-untouched':
        // Dokan's OWN rows, read straight from its tables rather than through
        // our reader — so «we changed nothing of theirs» is measured against
        // the database and not against the interface that promises it.
        //
        // The standing rule is «هیچ افزونه موجودی (از جمله دکان)
        // حذف/غیرفعال/ویرایش نمی‌شود», and a cutover is exactly the moment
        // somebody would find out the hard way that it had been broken.
        global $wpdb;
        $counts = [];
        foreach (['dokan_orders', 'dokan_vendor_balance', 'dokan_withdraw', 'dokan_refund'] as $name) {
            $table = $wpdb->prefix . $name;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            $counts[$name] = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") : -1;
        }
        // The sellers themselves are users, and their Dokan flags are meta.
        $counts['sellers'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$wpdb->usermeta}` WHERE meta_key = 'dokan_enable_selling'"
        );
        $counts['dokan_products'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$wpdb->posts}` p
             INNER JOIN `{$wpdb->usermeta}` m ON m.user_id = p.post_author AND m.meta_key = 'dokan_enable_selling'
             WHERE p.post_type = 'product'"
        );
        $line = '';
        foreach ($counts as $key => $value) {
            $line .= $key . '=' . $value . ' ';
        }
        printf("dokan-untouched %sdigest=%s\n", $line, substr(hash('sha256', json_encode($counts)), 0, 16));
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

    case 'observed':
        // What the trial mapped, and what that mapping means: nothing, until
        // somebody transfers ownership on purpose.
        $products = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
        $rows = $products->observed();
        printf("observed count=%d\n", count($rows));
        foreach ($rows as $product) {
            printf(
                "  tmc=%d wc=%d ownership=%s status=%s title=%s\n",
                $product->id,
                (int) ($product->wcProductId ?? 0),
                $products->linkOwnership($product->id)->value,
                $product->status->value,
                $product->details->title
            );
        }
        break;

    case 'ownership':
        $products = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
        printf("ownership product=%d value=%s\n", (int) ($args[1] ?? 0),
            $products->linkOwnership((int) ($args[1] ?? 0))->value);
        break;

    case 'take-ownership':
    case 'give-back-ownership':
        $transfer = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership::class);
        $productId = (int) ($args[1] ?? 0);
        $result = $command === 'take-ownership'
            ? $transfer->take($productId)
            : $transfer->giveBack($productId);
        printf(
            "%s ok=%s code=%s product=%s wc=%s\n",
            $command,
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['product_id'] ?? '-'),
            (string) ($result->context['wc_product_id'] ?? '-')
        );
        break;

    case 'runs':
        $runs = $service->runs();
        printf("runs count=%d\n", count($runs));
        foreach ($runs as $runId => $run) {
            printf(
                "  run=%s at=%s vendors=%s products=%s source=%s\n",
                $runId,
                (string) ($run['at'] ?? ''),
                implode(',', $run['vendors'] ?? []) ?: '-',
                implode(',', $run['products'] ?? []) ?: '-',
                (string) ($run['source'] ?? '-')
            );
        }
        break;

    case 'forget-manifest':
        // The crash this fix is about, reproduced: the rows were created and
        // the process died before the manifest option beside them was written.
        // Deleting the option is the same end state — and the rows must still
        // be findable, because each one carries the run that made it.
        $options = $c->get(\Tecteb\Marketplace\Contracts\OptionStoreInterface::class);
        $before = $options->get(\Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan::RUNS_OPTION, []);
        $options->set(\Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan::RUNS_OPTION, []);
        $after = $service->runs();
        $runId = (string) ($args[1] ?? '');
        printf(
            "forget-manifest run=%s was_in_manifest=%s still_listed=%s source=%s rows=%d vendors=%d\n",
            $runId,
            isset($before[$runId]) ? 'true' : 'false',
            isset($after[$runId]) ? 'true' : 'false',
            (string) ($after[$runId]['source'] ?? '-'),
            count($after[$runId]['products'] ?? []),
            count($after[$runId]['vendors'] ?? [])
        );
        break;

    case 'stamps':
        // Which run each row says made it. Read straight off the column, so
        // «the manifest agrees with itself» cannot pass for evidence.
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT import_run_id, COUNT(*) AS n FROM `' . $wpdb->prefix . 'tmc_products`'
            . " WHERE import_run_id <> '' GROUP BY import_run_id",
            ARRAY_A
        );
        printf("stamps runs=%d\n", count($rows));
        foreach ($rows as $row) {
            printf("  stamp run=%s rows=%d\n", (string) $row['import_run_id'], (int) $row['n']);
        }
        break;

    case 'reset':
        // «آزمونی که چیزی را خرج می‌کند باید اول reset کند.» An import that was
        // rolled back BEFORE the vendor stamp existed left its shop profile
        // behind, and the next run then imported nothing and had no vendor to
        // look at — evidence that measured an empty set and called it a pass.
        //
        // The rule used here is the rollback's own: a profile goes only if the
        // shop is empty and has no application of its own, so a real
        // marketplace vendor is never touched.
        $vendors = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class);
        $reader = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface::class);
        $productRepo = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);

        // The products FIRST, or the shop is not empty and the profile stays.
        // `observed` is precisely «this row came from an import and the
        // marketplace does not run it» — a marketplace product is `marketplace`
        // and is never in this list, so nothing real can be caught here.
        $removedProducts = 0;
        foreach ($productRepo->observed() as $product) {
            if ($productRepo->deleteDraft($product->id)) {
                $removedProducts++;
            }
        }
        // …and the rows this suite's OTHER fixtures took ownership of.
        //
        // `observed()` is «imported and not ours to run», and the continuity
        // path deliberately stops being that: it transfers ownership and
        // publishes. So after a continuity run there is nothing observed
        // left, this reset swept an empty floor, and the next import found the
        // Dokan product already linked — six checks then failed about a
        // migration that had simply already happened. Same shape as the
        // continuity fixture's own reset, and the same justification: a
        // fixture doing fixture things on a disposable site. There is
        // deliberately no «delete a published product» service, and this is
        // not one.
        global $wpdb;
        $transfer = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership::class);
        foreach ($reader->products() as $dokanProduct) {
            $wcId = (int) ($dokanProduct['wc_product_id'] ?? 0);
            if ($wcId <= 0) {
                continue;
            }
            $row = $productRepo->findByWcProduct($wcId) ?? $productRepo->findObservedByWcProduct($wcId);
            if ($row === null) {
                continue;
            }
            if ($productRepo->linkOwnership((int) $row->id)
                === \Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership::Marketplace) {
                $transfer->giveBack((int) $row->id);
            }
            if ($productRepo->deleteDraft((int) $row->id)) {
                $removedProducts++;
                continue;
            }
            foreach (['tmc_product_images', 'tmc_product_specs', 'tmc_product_revisions'] as $table) {
                $wpdb->delete($wpdb->prefix . $table, ['product_id' => (int) $row->id]);
            }
            $wpdb->delete($wpdb->prefix . 'tmc_products', ['id' => (int) $row->id]);
            $removedProducts++;
        }
        $removedProfiles = 0;
        foreach ($reader->vendors() as $vendor) {
            if ($vendors->deleteEmptyProfile((int) $vendor['user_id'])) {
                $removedProfiles++;
            }
        }
        $history = $wpdb->query(
            "DELETE FROM `{$wpdb->prefix}tmc_dokan_order_history` WHERE source = 'dokan'"
        );
        $options = $c->get(\Tecteb\Marketplace\Contracts\OptionStoreInterface::class);
        $options->set(\Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan::RUNS_OPTION, []);
        printf(
            "reset products_removed=%d profiles_removed=%d history_removed=%d runs_cleared=true\n",
            $removedProducts,
            $removedProfiles,
            (int) $history
        );
        break;

    case 'import-orders':
        // The path the JOB drives. Orders are the one thing a trial import
        // does not do inline — a shop with ten thousand of them would time out
        // mid-page — so the resumable page method is what the evidence runs,
        // exactly as `DeliverEventsJob`'s sibling does.
        $runId = (string) ($args[1] ?? '');
        $after = 0;
        $done = 0;
        $skipped = 0;
        $failed = 0;
        $pages = 0;
        do {
            $page = $service->importOrderPage($runId, $after, 50);
            $done += (int) $page['done'];
            $skipped += (int) $page['skipped'];
            $failed += (int) ($page['failed'] ?? 0);
            $after = (int) $page['last'];
            $pages++;
        } while (($page['more'] ?? false) && $pages < 100);
        printf("import-orders run=%s pages=%d recorded=%d already_there=%d failed=%d\n", $runId, $pages, $done, $skipped, $failed);
        break;

    case 'history':
        // Dokan's past orders, as records. FIN-02: they carry Dokan's own
        // figures, apply no rate of ours and write no ledger line — this
        // marketplace has one financial engine per order and it is not this.
        $vendorId = (int) ($args[1] ?? 0);
        $summary = $service->historyFor($vendorId);
        global $wpdb;
        $ledgerLines = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'tmc_ledger_entries`'
        );
        $table = $wpdb->prefix . 'tmc_dokan_order_history';
        $allRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        // The seller a Dokan ORDER belongs to is not always the seller the
        // plan imported — the shop whose catalogue moved need not be the shop
        // with the sales. So the busiest one in the table is named too, or the
        // evidence asks a shop with no orders whether its orders arrived and
        // is told «no» by a page that is working perfectly.
        $top = $wpdb->get_row(
            "SELECT vendor_user_id, COUNT(*) AS n FROM `{$table}`
             GROUP BY vendor_user_id ORDER BY n DESC LIMIT 1",
            ARRAY_A
        );
        $topVendor = (int) ($top['vendor_user_id'] ?? 0);
        $topSummary = $topVendor > 0
            ? $service->historyFor($topVendor)
            : ['orders' => 0, 'total_minor' => 0, 'net_minor' => 0];
        printf(
            "history vendor=%d available=%s orders=%d total_minor=%d net_minor=%d ledger_lines=%d all_rows=%d"
            . " top_vendor=%d top_orders=%d top_total_minor=%d top_net_minor=%d\n",
            $vendorId,
            ($summary['available'] ?? false) ? 'true' : 'false',
            (int) ($summary['orders'] ?? 0),
            (int) ($summary['total_minor'] ?? 0),
            (int) ($summary['net_minor'] ?? 0),
            $ledgerLines,
            $allRows,
            $topVendor,
            (int) ($topSummary['orders'] ?? 0),
            (int) ($topSummary['total_minor'] ?? 0),
            (int) ($topSummary['net_minor'] ?? 0)
        );
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
        echo "usage: plan | import | runs | rollback <run-id> | fingerprint | forget-manifest <run-id> | stamps | history <vendor>\n";
        break;
}
