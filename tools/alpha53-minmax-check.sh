#!/usr/bin/env bash
# Leaving «Min Max Control», measured against the real plugin.
#
# What makes this evidence rather than a demonstration: every number comes
# from Min Max 8.1.0 ACTUALLY ENFORCING on WooCommerce 11.0.1, and the same
# products are measured again with Min Max DEACTIVATED. The two states are
# never mixed, and the script says which one each number came from.
#
# The three products are chosen so that each of the four outcomes happens
# once:
#   · 4/10/2  — the step divides the minimum, so both plugins allow the same
#               quantities and the rule converts;
#   · 2/11/3  — the step counts from the minimum, so the two sets share NO
#               quantity. Refused by name;
#   · 4/10/2 with a manager's own marketplace rule already on the product —
#               held, nothing written;
#   · and a plain WooCommerce product with a Min Max rule — written, and
#               NOTHING ENFORCES IT, which is the number that decides whether
#               Min Max may be switched off at all.
#
# It deactivates and reactivates Min Max on the DISPOSABLE site only. The
# probe refuses to run anywhere else (DB_NAME and home_url are both checked).
set -u

REPO="${REPO:-$(cd "$(dirname "$0")/.." && pwd)}"
WPROOT="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
OUT="${1:-$REPO/docs/evidence/alpha53-minmax}"
MINMAX_SLUG="woo-min-max-quantity-step-control-single"

mkdir -p "$OUT"
LOG="$OUT/run.txt"
: >"$LOG"
PASS=0; FAIL=0

say()  { printf '%s\n' "$*" | tee -a "$LOG"; }
check() {
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); say "PASS $1 → $2"
  else FAIL=$((FAIL+1)); say "FAIL $1 → got [$2] want [$3]"; fi
}
wpx()  { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@"); }
probe() {
  (cd "$REPO" && "$PHPBIN" "$WPCLI" --allow-root --path="$WPROOT" \
     --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
     eval-file "$REPO/tests/Support/alpha53-probe.php" "$1" 2>&1)
}
val() { printf '%s\n' "$1" | sed -n "s/^$2=\(.*\)$/\1/p" | head -1; }

# However this ends, Min Max goes back on. The owner's site is untouched
# either way; this is about leaving the disposable site as it was found.
restore() { wpx plugin activate "$MINMAX_SLUG" >/dev/null 2>&1 || true; }
trap restore EXIT

say "# Min Max exit — $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
say "plugin under test: $(wpx plugin get "$MINMAX_SLUG" --field=version 2>/dev/null)"
say "woocommerce:       $(wpx plugin get woocommerce --field=version 2>/dev/null)"
say "marketplace:       $(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null)"
say "schema:            $(wpx eval 'echo (int) get_option("tmc_schema_version");' 2>/dev/null)"
say ""

# ---- 1. both plugins active: what Min Max really enforces ---------------
wpx plugin activate "$MINMAX_SLUG" >/dev/null 2>&1
A="$(probe both-arrange)"; printf '%s\n' "$A" >>"$LOG"
check "1a the probe ran"            "$(val "$A" probe)"          "ran"
check "1b Min Max is active"        "$(val "$A" minmax_active)"  "yes"
check "1c and it is 8.1.0.0"        "$(val "$A" minmax_version)" "8.1.0.0"
check "1d the fixture is arranged"  "$(val "$A" arranged)"       "yes"

B="$(probe both-enforce)"; printf '%s\n' "$B" >>"$LOG"
check "1e the sale gate opened for the measurement" "$(val "$B" gate)" "open"
check "1f a convertible rule is enforced as 4,6,8,10" \
      "$(val "$B" minmax_SKU-A53-OK_allowed)" "4|6|8|10"
# THE DIFFERENCE, measured on the real plugin rather than read from source.
check "1g and a step-from-the-minimum rule as 2,5,8,11" \
      "$(val "$B" minmax_SKU-A53-STEP_allowed)" "2|5|8|11"
check "1h a plain WooCommerce product is enforced too" \
      "$(val "$B" minmax_native_allowed)" "4|6|8|10"

# ---- 2. the preview writes nothing --------------------------------------
P="$(probe preview)"; printf '%s\n' "$P" >>"$LOG"
check "2a the mode is read the plugin's way"  "$(val "$P" mode)"        "plain"
check "2b with no meta prefix"                "$(val "$P" prefix)"      "__empty__"
check "2c and the plugin's own option key"    "$(val "$P" option_key)"  "wcmmq_minmaxstep"
check "2d premium is not active, so variation rules do not fire" \
      "$(val "$P" premium)" "no"
check "2e four products carry a rule"         "$(val "$P" count_product)" "4"
check "2f one converts"                       "$(val "$P" preview_convertible)" "1"
check "2g one is refused"                     "$(val "$P" preview_refused)" "1"
check "2h one is the manager's"               "$(val "$P" preview_held_by_manager)" "1"
check "2i one would not be enforced"          "$(val "$P" preview_unenforced)" "1"
check "2j and the refusal is named"           "$(val "$P" problem_step_base_differs)" "1"
check "2k the preview wrote nothing"          "$(val "$P" wrote_nothing)" "yes"

# ---- 3. the explicit migration -----------------------------------------
M="$(probe migrate)"; printf '%s\n' "$M" >>"$LOG"
check "3a the batch ran to the end"   "$(val "$M" batch_done)" "yes"
check "3b one product migrated"       "$(val "$M" migrated_migrated)" "1"
check "3c one refused"                "$(val "$M" migrated_refused)" "1"
check "3d one held"                   "$(val "$M" migrated_held_by_manager)" "1"
check "3e one written-but-unenforced" "$(val "$M" migrated_written_unenforced)" "1"
check "3f the convertible rule's rows carry the SOURCE values" \
      "$(val "$M" rows_SKU-A53-OK)" "4/10/2"
check "3g the refused rule wrote NOTHING" \
      "$(val "$M" rows_SKU-A53-STEP)" "__empty__/__empty__/__empty__"
check "3h and its refusal is recorded by name" \
      "$(val "$M" ledger_SKU-A53-STEP_problems)" "step_base_differs"
check "3i the manager's rule is untouched" \
      "$(val "$M" rows_SKU-A53-HELD)" "6/12/1"

R="$(probe migrate-again)"; printf '%s\n' "$R" >>"$LOG"
check "3j a second run writes nothing new" "$(val "$R" again_migrated)" "0"
check "3k and finds every product already decided" "$(val "$R" again_already_decided)" "4"

# ---- 4. Min Max deactivated: does the rule survive? --------------------
wpx plugin deactivate "$MINMAX_SLUG" >/dev/null 2>&1
E="$(probe only-enforce)"; printf '%s\n' "$E" >>"$LOG"
check "4a Min Max is off"  "$(val "$E" minmax_active)" "no"
# THE ONE THAT MATTERS: a converted rule allows exactly what it did before.
check "4b the converted rule still allows 4,6,8,10" \
      "$(val "$E" exit_SKU-A53-OK_allowed)" "4|6|8|10"
# AND THE TWO THAT BLOCK THE EXIT, stated as measurements.
check "4c the refused rule is GONE — every quantity passes" \
      "$(val "$E" exit_SKU-A53-STEP_allowed)" "1|2|3|4|5|6|8|10|11|12"
check "4d and the non-marketplace product's rule is GONE too" \
      "$(val "$E" exit_native_allowed)" "1|2|3|4|5|6|8|10|11|12"

# ---- 5. rollback knows what it is putting back -------------------------
RB="$(probe rollback)"; printf '%s\n' "$RB" >>"$LOG"
check "5a the rollback succeeded" "$(val "$RB" rollback_code)" "minmax_rolled_back"
check "5b and the rows are ABSENT again, not empty" \
      "$(val "$RB" rows_after_rollback)" "__absent__/__absent__/__absent__"
check "5c the ledger records it"  "$(val "$RB" ledger_after_rollback)" "rolled_back"

RC="$(probe rollback-after-change)"; printf '%s\n' "$RC" >>"$LOG"
check "5d the precondition holds: the product was migrated" \
      "$(val "$RC" arranged_status)" "migrated"
check "5e a later change makes the rollback REFUSE" \
      "$(val "$RC" changed_rollback_code)" "minmax_rollback_changed"
check "5f and it names the field"  "$(val "$RC" changed_rollback_field)" "max"
check "5g with both numbers"       "$(val "$RC" changed_rollback_wrote)/$(val "$RC" changed_rollback_now)" "10/8"
check "5h the newer value is KEPT" "$(val "$RC" manager_value_kept)" "8"

# ---- 6. the distinction the whole rollback rests on ---------------------
PO="$(probe port)"; printf '%s\n' "$PO" >>"$LOG"
check "6a an absent row reads as null"  "$(val "$PO" absent_reads)" "__null__"
check "6b an empty row reads as empty"  "$(val "$PO" empty_reads)"  "string:__empty__"
check "6c a marketplace product is ours" "$(val "$PO" owns_marketplace_product)" "yes"
check "6d a plain WooCommerce product is not" "$(val "$PO" owns_native_product)" "no"

restore
say ""
say "checks: $PASS passed, $FAIL failed"
say "Min Max left: $(wpx plugin get "$MINMAX_SLUG" --field=status 2>/dev/null)"
[ "$FAIL" -eq 0 ] || exit 1
