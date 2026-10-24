<?php
/**
 * One live-locked import job carrying a step record — for the queue screen.
 *
 * ⛔ DISPOSABLE INSTALL ONLY. It claims a job and writes a cursor.
 *
 * `tools/browser/alpha75-run.mjs` needs the screen in the state the owner's
 * report describes: a job that is running, whose lease is live, whose worker
 * is inside the expensive step, and whose last batch ended ITSELF for time.
 * Arranging it here rather than in the browser is the point — every figure the
 * page prints is then compared against one this script already wrote, and
 * «رندر کردن یک صفحه دربارهٔ تصمیم‌هایش هیچ نمی‌گوید» (`alpha.13`).
 *
 *   wp eval-file tools/seed-queue-step-state.php
 */
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    echo "refused=1\n";
    return;
}
$c = \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$jobs = $c->get(\Tecteb\Marketplace\Contracts\JobRepositoryInterface::class);
$verb = (string) ($args[0] ?? 'seed');
if ($verb === 'reset') {
    global $wpdb;
    echo 'left=' . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_jobs") . "\n";
    return;
}
$queued = $jobs->enqueue('dokan_import', ['run_id' => 'ui-check', 'total' => 186], 'ui-check-' . time(), 1);
$id = (int) $queued['id'];
$token = 'ui-check-token';
$claimed = $jobs->claim(['dokan_import'], $token, 300);
echo 'job=' . $id . ' claimed=' . ($claimed === null ? 'no' : 'yes') . "\n";
echo 'checkpoint=' . ($jobs->checkpoint($id, $token, [
    'phase' => 'products',
    'after' => 9343,
    'at' => 9344,
    'tries' => 2,
    'page_tries' => 0,
    'refused' => ['9340:sku_already_used_in_this_shop'],
    'stopped' => 'time_budget_spent',
    'running' => 'image_metadata',
    'running_unit' => 9344,
    'running_since' => time() - 42,
    'timings' => ['0:page_read:7', '9343:judge:3', '9343:row_and_manifest:6', '9343:image_file_copy:1', '9343:image_metadata:4089', '9343:attach:3'],
    'worst' => 'image_metadata:4089',
], 10, 1, 186, 300) ? 'ok' : 'refused') . "\n";
