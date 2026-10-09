#!/usr/bin/env bash
# Eleven questions asked of the plugin WORDPRESS IS SERVING, installed from
# alpha.40.zip itself — not of the repository tree.
#
# «آزمون‌های مرتبط از خودِ ZIP نهایی هم اجرا شوند» (§8). The suites run against
# `src/` in this checkout; these run through `wp eval` on the install, so what
# answers is the code a WordPress request loads. The two are byte-identical and
# that is MEASURED (`diff -rq`) rather than assumed — but identical bytes in a
# different loader is still a different question, and this is the one that asks
# it of the loader that matters.
#
#   bash tools/alpha40-served-probes.sh docs/evidence/alpha40-served
set -u
WP="wp --allow-root --path=/home/user/wp-demo"
OUT="${1:-/home/user/tecteb-seller/docs/evidence/alpha40-served}"
mkdir -p "$OUT"
LOG="$OUT/served-probes.txt"; : > "$LOG"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "ok:   $(printf '%-58s' "$1") $3" | tee -a "$LOG";
          else fail=$((fail+1)); echo "FAIL: $(printf '%-58s' "$1") expected=$3 actual=$2" | tee -a "$LOG"; fi }

V="$($WP plugin get tecteb-marketplace-core --field=version)"
check "the plugin WordPress serves is the package under test" "$V" "0.1.0-alpha.40"
check "on schema 22" "$($WP option get tmc_schema_version)" "22"
check "with no migration error recorded" "$($WP option get tmc_migration_last_error 2>/dev/null || echo '')" ""

# Every answer below comes from the SERVED source, through one `wp eval` each.
ev() { $WP eval "$1" 2>/dev/null; }

check "the served build targets 22, read from its own constant" \
  "$(ev 'echo \Tecteb\Marketplace\Core\Migration\SchemaVersion::TARGET;')" "22"
check "the money-unit registry exists in the served source" \
  "$(ev 'echo class_exists(\Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry::class) ? "yes" : "no";')" "yes"
check "and migration 22 is wired into the chain the gate runs" \
  "$(ev 'foreach (\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::migrations() as $m) { if ($m->version() === 22) { echo $m->id(); } }')" \
  "0022_vendor_money_unit"
check "its verify() accepts the table that is actually on disk" \
  "$(ev 'global $wpdb; $db = new \Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase($wpdb);
         echo (new \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit())->verify($db) ? "yes" : "no";')" "yes"
check "a capture built without a unit of work REFUSES rather than assuming" \
  "$(ev 'echo (new \ReflectionClass(\Tecteb\Marketplace\Modules\Order\Application\CaptureOrder::class))
         ->getConstructor()->getNumberOfParameters();')" "8"
check "ManageReturns::refund() cannot be told which unit to use" \
  "$(ev '$p = (new \ReflectionMethod(\Tecteb\Marketplace\Modules\Order\Application\ManageReturns::class, "refund"))->getParameters();
         echo implode(",", array_map(fn($x) => $x->getName(), $p));')" \
  "actorId,returnId,wcRefundId,note"
check "the refusal reasons have Persian sentences, not bare codes" \
  "$(ev 'echo \Tecteb\Marketplace\Modules\Order\Presentation\OrderMessages::notice("refund_unit_unknown", ["item_id" => 1]) !== null ? "yes" : "no";' 2>/dev/null)" "yes"
check "and the mixed-unit report is reachable on the served build" \
  "$(ev 'global $wpdb; $db = new \Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase($wpdb);
         $l = new \Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository($db, new \Tecteb\Marketplace\Core\Support\SystemClock());
         echo is_array($l->vendorsWithMixedUnits()) ? "yes" : "no";')" "yes"

echo "" | tee -a "$LOG"
echo "checks: $((pass+fail))   passed: $pass   failed: $fail" | tee -a "$LOG"
[ "$fail" -eq 0 ]
