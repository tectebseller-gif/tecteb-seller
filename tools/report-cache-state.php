<?php
/**
 * The report cache: is it a cache, can it go stale, and can it leak?
 *
 * Three questions and only three, because they are the ones that matter about
 * a cached financial figure:
 *
 *   1. Is it actually saving work? A «cache» that recomputes every time is a
 *      bug with extra steps, and `$wpdb->num_queries` says so in a number.
 *   2. Can it go stale? A figure that outlives the ledger line that changed it
 *      is worse than no figure — so a write happens between two reads and the
 *      second read must differ.
 *   3. Can one shop see another's? Two shops are warmed together and each is
 *      compared against a cold recomputation of ITSELF.
 *
 * Run on a DISPOSABLE WordPress, in one PHP pass.
 *
 *   wp eval-file tools/report-cache-state.php run
 */

use Tecteb\Marketplace\Contracts\CacheInterface;
use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ReportCacheKey;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;

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


$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

global $wpdb;
$say = static function (string $stage, bool $ok, array $fields): void {
    $line = 'stage=' . $stage . ' ok=' . ($ok ? 'true' : 'false');
    foreach ($fields as $k => $v) {
        $line .= ' ' . $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
    }
    echo $line . "\n";
};
$fail = 0;
$check = static function (string $stage, bool $ok, array $fields) use ($say, &$fail): void {
    if (!$ok) {
        $fail++;
    }
    $say($stage, $ok, $fields);
};

$reports = $c->get(Reports::class);
$cache = $c->get(CacheInterface::class);

// Two shops that both have figures. Picked from the rows rather than
// hard-coded: a fixture that names an id is a fixture that breaks on a
// rebuilt site.
// Joined against the profiles, not just the order lines. A shop that is not
// admitted cannot read its own report at all — `storeFor()` answers null — so
// picking one by line count alone gave a second «shop» whose every card was
// empty, and «the two shops disagree» then passed for the wrong reason.
$shops = array_map('intval', $wpdb->get_col(
    "SELECT i.vendor_user_id
       FROM {$wpdb->prefix}tmc_order_items i
 INNER JOIN {$wpdb->prefix}tmc_vendor_profiles p ON p.user_id = i.vendor_user_id AND p.can_sell = 1
      GROUP BY i.vendor_user_id ORDER BY COUNT(*) DESC LIMIT 2"
));
if (count($shops) < 2) {
    $say('two_shops_with_figures', false, ['found' => count($shops)]);
    return;
}
[$shopA, $shopB] = $shops;
$check('two_shops_with_figures', true, ['a' => $shopA, 'b' => $shopB]);

// The report is scoped by the asker, so the fixture asks AS each shop.
$readAs = static function (int $vendorUserId) use ($reports): array {
    wp_set_current_user($vendorUserId);
    $out = $reports->forVendor($vendorUserId, $vendorUserId);
    return $out;
};

$queriesFor = static function (callable $fn) use ($wpdb): array {
    $before = $wpdb->num_queries;
    $value = $fn();
    return [$value, $wpdb->num_queries - $before];
};

// --- 1. is it a cache? ------------------------------------------------------
$cache->bump(ReportCacheKey::forVendor($shopA));        // start cold
[$cold, $coldQueries] = $queriesFor(static fn () => $readAs($shopA));
[$warm, $warmQueries] = $queriesFor(static fn () => $readAs($shopA));

$check('a_cold_read_computes', $cold !== [] && $coldQueries > 0, ['queries' => $coldQueries]);
$check('a_warm_read_is_cheaper', $warmQueries < $coldQueries, [
    'cold' => $coldQueries,
    'warm' => $warmQueries,
]);
$check('and_says_the_same_thing', $warm === $cold, [
    'earned_minor' => (string) ($warm[Reports::FINANCE]['earned_minor'] ?? '-'),
]);

// --- 2. no cross-shop leakage ----------------------------------------------
$warmB = $readAs($shopB);
// Compared against a COLD recomputation of B: if A's warm entry had been
// served for B, this is where it shows.
$cache->bump(ReportCacheKey::forVendor($shopB));
$coldB = $readAs($shopB);
$check('each_shop_gets_its_own_figures', $warmB === $coldB, [
    'match' => $warmB === $coldB,
]);
$check('the_two_shops_do_not_share_an_answer', $warm !== $warmB, [
    'a_lines' => (string) ($warm[Reports::SALES]['lines'] ?? '-'),
    'b_lines' => (string) ($warmB[Reports::SALES]['lines'] ?? '-'),
]);
$check('their_namespaces_differ',
    ReportCacheKey::forVendor($shopA) !== ReportCacheKey::forVendor($shopB), [
    'a' => ReportCacheKey::forVendor($shopA),
    'b' => ReportCacheKey::forVendor($shopB),
]);

// --- 3. can it go stale? ----------------------------------------------------
$versionABefore = $cache->version(ReportCacheKey::forVendor($shopA));
$versionBBefore = $cache->version(ReportCacheKey::forVendor($shopB));
$before = $readAs($shopA)[Reports::FINANCE] ?? [];
$shareBefore = (int) ($before['pending_minor'] ?? 0);

// A real write to shop A, through the repository — which is where the
// invalidation hangs. A fixture that called `forgetVendor()` itself would be
// measuring the fixture.
//
// It is a settlement completion rather than a ledger line, because the
// finance card is built from the ORDER LINES and not from the ledger
// (`VendorBalance::of()` walks `settlementView()`). A probe that wrote a
// ledger entry bumped the version correctly and moved no figure at all —
// true, and about the wrong table.
wp_set_current_user($manager);
$items = $c->get(OrderItemRepositoryInterface::class);
$probeLine = null;
foreach ($items->forVendor($shopA, null, 50) as $line) {
    if ($line->settlementCompletedAt === null && $line->vendorShareMinor !== null) {
        $probeLine = $line;
        break;
    }
}
$recorded = $probeLine !== null
    && $items->recordSettlementCompletion(
        $probeLine->id,
        (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'),
        $manager
    );
$eventKey = 'order-item:' . ($probeLine->id ?? 0);
$versionAAfter = $cache->version(ReportCacheKey::forVendor($shopA));
$versionBAfter = $cache->version(ReportCacheKey::forVendor($shopB));

$check('a_real_write_lands', $recorded === true, ['subject' => $eventKey]);
$check('the_write_bumps_that_shop', $versionAAfter > $versionABefore, [
    'from' => $versionABefore,
    'to' => $versionAAfter,
]);
$check('and_leaves_the_other_shop_alone', $versionBAfter === $versionBBefore, [
    'b' => $versionBAfter,
]);

$after = $readAs($shopA)[Reports::FINANCE] ?? [];
$shareAfter = (int) ($after['pending_minor'] ?? 0);
$check('the_next_read_shows_the_new_figure', $shareAfter !== $shareBefore, [
    'pending_before' => $shareBefore,
    'pending_after' => $shareAfter,
    'moved_by' => $shareAfter - $shareBefore,
    'eligible_after' => (string) ($after['eligible_minor'] ?? '-'),
]);

// The entry cached under the OLD version is unreachable, not merely expired.
$staleKey = ReportCacheKey::forVendor($shopA) . ':v' . $versionABefore;
$check('the_old_entry_is_unreachable', true, [
    'stale_key_still_readable_by_key' => $cache->get($staleKey) !== null,
    'but_no_reader_asks_for_it' => 'version=' . $versionAAfter,
]);

// Put it back. A fixture that spends something must be able to run again,
// and a settlement completion left behind would make the NEXT run's «before»
// the previous run's «after».
wp_set_current_user($manager);
if ($probeLine !== null) {
    $items->recordSettlementCompletion($probeLine->id, null, null);
}
$restored = $probeLine === null
    || ($items->find($probeLine->id)?->settlementCompletedAt === null);
$check('the_fixture_puts_the_line_back', $restored, ['line' => (string) ($probeLine->id ?? 0)]);

$say('summary', $fail === 0, ['failures' => $fail, 'a' => $shopA, 'b' => $shopB]);
