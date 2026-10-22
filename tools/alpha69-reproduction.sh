#!/usr/bin/env bash
# The three `exitVerdict()` gaps a code review found in `alpha.69`, each
# reproduced on the bytes of the DELIVERED `alpha.69` package and then
# measured again on this tree.
#
# WHAT IS MEASURED, and why each one is a before/after rather than a claim
#
#   §1 — `preview()` counted every product with a LIVE ledger row as
#        `already_decided` and skipped it, and `exitVerdict()` never read the
#        ledger's live `refused` / `held_by_manager` counts. So ATTEMPTING the
#        migration of the site's only incompatible rule — which writes
#        nothing and changes nothing Min Max enforces — turned the verdict
#        from `blocked` into `permitted`. Measured in that order: blocked,
#        attempt, permitted.
#
#   §2 — a `migrated` row is a record that the rule was taken ONCE. Three
#        things move under it afterwards: the source rule, the destination
#        rows, and ownership plus the enforcement grant. Each is measured by
#        changing it and re-reading the verdict, with the two SETS of allowed
#        quantities printed side by side (`alpha.53`: for a step rule «the
#        numbers are the same» and «the rule is the same» are different
#        questions).
#
#   §3 — `staleGrants()` read ONE page of fifty and `exitVerdict()` called it
#        with that default. Fifty-five live grants are created, the
#        FIFTY-FIFTH is made stale, and the same function is then asked with
#        a wider bound — which finds it. That is what makes the bound the
#        defect rather than the comparison.
#
#   Plus the COVERAGE the instruction asks for — a live global rule and a
#   real variation rule — and CONTROLS that must answer the SAME on both
#   trees: every current rule migrated, equal and actually enforced is
#   `permitted`; a RELEASED refusal is history and does not block as a live
#   decision; and a drift put back stops blocking. A measurement that only
#   ever looks at the broken case cannot tell a fix from a feature that
#   stopped working (`alpha.46`).
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this with a LATER package installed reports that round's fixes as
# this round's failures, which `CLAUDE.md` records as a rule after it
# happened twice.
#
# ⛔ AND THE «AFTER» TREE COMES FROM `$REPO/src`, never from the install.
#
# ⛔ NO REAL MAIL, SMS OR PAYMENT: the probe short-circuits `wp_mail` on
# every run and this round touches no order path at all. The sale gate is
# NOT opened — nothing here needs it.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`).
#
# ⛔ AND EVERY GUARANTEE IS GIVEN BACK. The verdict is one answer about the
# whole site, so the probe stashes every OTHER Min Max rule into an option
# while it measures and writes them all back in the trap, along with the
# global option, the variation product and the fifty-five throwaway ones.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha69-reproduction.sh docs/evidence/alpha69-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha69-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PROBE_SRC="$REPO/tests/Support/alpha69-probe.php"
PROBE="$WP_PATH/alpha69-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha69-XXXXXX)"

mkdir -p "$OUT"
RUN="$OUT/run.txt"
: >"$RUN"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$RUN"; }
check() {
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
note() { say "NOTE  $1  $2"; }
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
probe() { wpx eval-file "$PROBE" "$@" 2>>"$RUN"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
has() { [[ "$1" == *"$2"* ]] && echo yes || echo no; }
digest() { (cd "$1" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16); }

restore() {
  # The fixture's own guarantees first, with the FIXED tree in place so the
  # reset verbs exist, then the installed bytes.
  if [[ -f "$PROBE" ]]; then
    probe coverage-restore >/dev/null 2>&1
    probe coverage-variation-reset >/dev/null 2>&1
    probe grants-reset >/dev/null 2>&1
    probe reset >/dev/null 2>&1
  fi
  if [[ -d "$KEEP/src" ]]; then
    rm -rf "$INSTALLED/src"
    cp -a "$KEEP/src" "$INSTALLED/src"
  fi
  rm -f "$PROBE"
}
trap restore EXIT

# --------------------------------------------------------------- the trees
cp -a "$INSTALLED/src" "$KEEP/src"
ORIGINAL_HASH="$(digest "$KEEP")"
NOW_HASH="$(digest "$REPO")"
cp "$PROBE_SRC" "$PROBE"

if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.69 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.69:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.69 commit could not be resolved"
  exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.69 tree could not be checked out"
  exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the installed package is this round's base (alpha.69)" "$BASE_HASH" "$ORIGINAL_HASH"
check "0b this checkout differs from the base" "differ" \
  "$([[ "$NOW_HASH" == "$BASE_HASH" ]] && echo same || echo differ)"

use_tree() {
  rm -rf "$INSTALLED/src"
  cp -a "$1/src" "$INSTALLED/src"
}

# ⛔ ONE DATABASE, TWO SETS OF CODE BYTES. This round adds NO migration —
# asserted, not assumed, because a structure that moved in the middle would
# make the two halves two sites.
SCHEMA="$(wpx option get tmc_schema_version 2>/dev/null | head -1)"
say "schema_for_both_halves=$SCHEMA"
check "0c the structure is 34 and this round does not move it" "34" "$SCHEMA"
check "0d no migration file changed in this round" "0" \
  "$(cd "$REPO" && git diff --name-only "$BASE_REF" -- 'src/**/Migrations/' | wc -l | tr -d ' ')"
check "0e min max control is active, so the source can be read" "yes" \
  "$(has "$(probe report)" "minmax_active=yes")"

# ======================================================= BEFORE (alpha.69)
use_tree "$KEEP/base"
check "0f the installed tree is now alpha.69's" "$BASE_HASH" "$(digest "$INSTALLED")"
probe grants-reset >/dev/null
probe coverage-variation-reset >/dev/null
probe coverage-restore >/dev/null
probe reset >/dev/null

say ""
say "=== BEFORE · the control: one rule, migrated, equal, enforced ==="
B_AR="$(probe exit-arrange)"; printf '%s\n' "$B_AR" >>"$RUN"
check "1a the fixture took the site's other min max rules out of the way" "yes" \
  "$([[ "$(val "$B_AR" other_rules_stashed)" =~ ^[0-9]+$ ]] && echo yes || echo no)"
note  "1b how many it stashed" "$(val "$B_AR" other_rules_stashed)"
check "1c the convertible rule was taken" "migrated" "$(val "$B_AR" ok_migrated)"
check "1d …and the destination holds it" "4/10/2" "$(val "$B_AR" ok_rows)"
B_CT="$(probe exit-control)"; printf '%s\n' "$B_CT" >>"$RUN"
check "1e the control is permitted on the base" "permitted" "$(val "$B_CT" control_verdict)"
check "1f …with no blocker at all" "__empty__" "$(val "$B_CT" control_blockers)"

say ""
say "=== BEFORE · §1 a recorded refusal stops blocking ==="
B_RF="$(probe exit-refused)"; printf '%s\n' "$B_RF" >>"$RUN"
check "1g before the attempt the incompatible rule BLOCKS" "blocked" \
  "$(val "$B_RF" before_attempt_verdict)"
check "1h …and is counted" "1" "$(val "$B_RF" before_attempt_incompatible)"
check "1i the attempt records a live refusal" "refused" "$(val "$B_RF" ledger_status)"
check "1j …naming min max's own step base" "step_base_differs" "$(val "$B_RF" ledger_problems)"
check "1k …and writes nothing at the destination" "__absent__/__absent__/__absent__" \
  "$(val "$B_RF" rows_written)"
check "1l …and the ledger counts it as live" "1" "$(val "$B_RF" tally_refused)"
# ⛔ THE FINDING, first half.
check "1m THE DEFECT: the exit is now permitted" "permitted" "$(val "$B_RF" refused_verdict)"
check "1n …with the incompatible count back to zero" "0" "$(val "$B_RF" refused_count_incompatible)"
check "1o …and not one blocker named" "__empty__" "$(val "$B_RF" refused_blockers)"
B_HD="$(probe exit-held)"; printf '%s\n' "$B_HD" >>"$RUN"
check "1p a held destination is recorded" "held_by_manager" "$(val "$B_HD" ledger_status)"
check "1q …and the manager's own rule is kept" "6/12/1" "$(val "$B_HD" manager_rows_kept)"
check "1r …and the ledger counts it as live" "1" "$(val "$B_HD" tally_held_by_manager)"
# ⛔ THE FINDING, second half: the held count the verdict prints is zero.
check "1s THE DEFECT: the verdict's held count is zero" "0" "$(val "$B_HD" held_count_held_by_manager)"
B_BT="$(probe exit-both)"; printf '%s\n' "$B_BT" >>"$RUN"
check "1t both live decisions stand" "refused" "$(val "$B_BT" step_status)"
check "1u …and the held one too" "held_by_manager" "$(val "$B_BT" held_status)"
# ⛔ THE FINDING, whole: two live limits min max is enforcing today, and a yes.
check "1v THE DEFECT: with both live, the exit is permitted" "permitted" "$(val "$B_BT" both_verdict)"
check "1w …and no blocker is named" "__empty__" "$(val "$B_BT" both_blockers)"
B_RL="$(probe exit-released)"; printf '%s\n' "$B_RL" >>"$RUN"
check "1x CONTROL: a released refusal is history" "refused" "$(val "$B_RL" history_after_release)"
check "1y …counted apart from the live ones" "1" "$(val "$B_RL" tally_refused_released)"
check "1z …and the rule blocks again as an UNDECIDED one" "blocked" "$(val "$B_RL" released_verdict)"

say ""
say "=== BEFORE · §2 a migrated row is taken for a present equality ==="
probe exit-arrange >/dev/null
B_DS="$(probe drift-source)"; printf '%s\n' "$B_DS" >>"$RUN"
check "2a the migrated product starts equal" "yes" "$(val "$B_DS" before_same_set)"
check "2b …both sides allowing the same quantities" "4|6|8|10" "$(val "$B_DS" before_minmax_allows)"
check "2c the manager changes the SOURCE rule" "6|8|10|12" "$(val "$B_DS" changed_minmax_allows)"
check "2d …and the destination still allows the old set" "4|6|8|10" \
  "$(val "$B_DS" changed_after_exit_allows)"
check "2e …so the two sides no longer agree" "no" "$(val "$B_DS" changed_same_set)"
# ⛔ THE FINDING.
check "2f THE DEFECT: the exit is permitted anyway" "permitted" "$(val "$B_DS" drift_source_verdict)"
check "2g …with no blocker named" "__empty__" "$(val "$B_DS" drift_source_blockers)"
B_DD="$(probe drift-dest)"; printf '%s\n' "$B_DD" >>"$RUN"
check "2h the manager edits the DESTINATION instead" "4/8/2" "$(val "$B_DD" manager_rows)"
check "2i …so the sets differ again" "no" "$(val "$B_DD" same_set)"
check "2j THE DEFECT: still permitted" "permitted" "$(val "$B_DD" drift_dest_verdict)"
B_DO="$(probe drift-ownership)"; printf '%s\n' "$B_DO" >>"$RUN"
check "2k the product stopped being the marketplace's" "no" "$(val "$B_DO" owns_now)"
check "2l …and carries no enforcement grant" "no" "$(val "$B_DO" granted_now)"
check "2m …so after deactivation NOTHING would limit it" "1|2|3|4|5|6|7|8|9|10|11|12|13|14" \
  "$(val "$B_DO" after_exit_allows)"
check "2n …while the ledger still says migrated" "migrated" "$(val "$B_DO" ledger_status)"
check "2o THE DEFECT: still permitted" "permitted" "$(val "$B_DO" drift_ownership_verdict)"
probe drift-restore >/dev/null

say ""
say "=== BEFORE · §3 the grant scan stops at fifty ==="
B_GA="$(probe grants-arrange 55)"; printf '%s\n' "$B_GA" >>"$RUN"
check "3a fifty-five products carry a rule" "55" "$(val "$B_GA" grant_products_wanted)"
B_GM="$(probe grants-migrate)"; printf '%s\n' "$B_GM" >>"$RUN"
check "3b each was written where nothing reads it" "55" "$(val "$B_GM" written_unenforced)"
check "3c …and each got an explicit grant" "55" "$(val "$B_GM" granted)"
check "3d …so the ledger holds fifty-five granted rows" "55" "$(val "$B_GM" tally_rule_enforced)"
B_GS="$(probe grants-stale)"; printf '%s\n' "$B_GS" >>"$RUN"
check "3e the drifted one is the fifty-fifth in ledger order" "55" \
  "$(val "$B_GS" target_position_in_ledger_order)"
check "3f its grant no longer describes its rows" "no" "$(val "$B_GS" target_grant_live)"
# ⛔ THE FINDING: the same function, two bounds, two answers.
check "3g THE DEFECT: the default bound finds nothing" "0" "$(val "$B_GS" stale_grants_default_limit)"
check "3h …and does not list it" "no" "$(val "$B_GS" target_listed_by_default)"
check "3i a wider bound finds it" "1" "$(val "$B_GS" stale_grants_wide_limit)"
check "3j …and lists it" "yes" "$(val "$B_GS" target_listed_with_wide_limit)"
check "3k …so the verdict permits with a dead purchase limit on the site" "permitted" \
  "$(val "$B_GS" stale_verdict)"
check "3l …and there is no paginated scan to ask" "__absent__" "$(val "$B_GS" scan_finished)"
probe grants-reset >/dev/null

say ""
say "=== BEFORE · coverage: the levels that cannot be taken at all ==="
B_GL="$(probe coverage-global)"; printf '%s\n' "$B_GL" >>"$RUN"
check "4a a live global rule is seen" "yes" "$(val "$B_GL" global_seen)"
check "4b …and it restricts something" "3/9/3" "$(val "$B_GL" global_effective)"
check "4c …and min max counts it" "1" "$(val "$B_GL" count_global)"
# ⛔ THE FINDING: a site-wide purchase limit with nowhere to go, unmentioned.
check "4d THE DEFECT: the verdict permits the exit" "permitted" "$(val "$B_GL" global_verdict)"
check "4e …naming no blocker" "__empty__" "$(val "$B_GL" global_blockers)"
check "4f …and carrying no count for it" "__absent__" "$(val "$B_GL" global_count_global_rules)"
probe coverage-restore >/dev/null
B_VA="$(probe coverage-variation-arrange)"; printf '%s\n' "$B_VA" >>"$RUN"
check "4g a real variation rule exists" "4/10/2" "$(val "$B_VA" variation_rule)"
B_VC="$(probe coverage-variation)"; printf '%s\n' "$B_VC" >>"$RUN"
check "4h CONTROL: the walk already counts the variation level" "1" "$(val "$B_VC" levels_variation)"
check "4i …reaches the end" "yes" "$(val "$B_VC" walk_finished)"
check "4j …and blocks, because nothing here would read that rule" "blocked" \
  "$(val "$B_VC" variation_verdict)"
probe coverage-variation-reset >/dev/null
probe reset >/dev/null

# ========================================================= AFTER (this tree)
use_tree "$REPO"
check "0g the installed tree is now this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"
probe reset >/dev/null

say ""
say "=== AFTER · the control is unchanged ==="
A_AR="$(probe exit-arrange)"; printf '%s\n' "$A_AR" >>"$RUN"
check "5a the convertible rule is still taken" "migrated" "$(val "$A_AR" ok_migrated)"
A_CT="$(probe exit-control)"; printf '%s\n' "$A_CT" >>"$RUN"
check "5b CONTROL: migrated, equal and enforced is still permitted" "permitted" \
  "$(val "$A_CT" control_verdict)"
check "5c …with no blocker" "__empty__" "$(val "$A_CT" control_blockers)"
check "5d …and the product is counted as in force" "1" "$(val "$A_CT" control_count_decided_in_force)"

say ""
say "=== AFTER · §1 a live refusal and a live hold both block ==="
A_RF="$(probe exit-refused)"; printf '%s\n' "$A_RF" >>"$RUN"
check "5e the attempt still records the refusal, unchanged" "refused" "$(val "$A_RF" ledger_status)"
check "5f …and still writes nothing" "__absent__/__absent__/__absent__" "$(val "$A_RF" rows_written)"
check "5g THE FIX: the exit is refused" "blocked" "$(val "$A_RF" refused_verdict)"
check "5h …the recorded refusal is counted" "1" "$(val "$A_RF" refused_count_refused_recorded)"
check "5i …inside the incompatible count" "1" "$(val "$A_RF" refused_count_incompatible)"
check "5j …and named" "incompatible_rules_present:1" "$(val "$A_RF" refused_blockers)"
A_HD="$(probe exit-held)"; printf '%s\n' "$A_HD" >>"$RUN"
check "5k the manager's rule is still kept" "6/12/1" "$(val "$A_HD" manager_rows_kept)"
check "5l THE FIX: the recorded hold is counted" "1" "$(val "$A_HD" held_count_held_by_manager)"
check "5m …and named" "yes" "$(has "$(val "$A_HD" held_blockers)" "rules_held_by_manager:1")"
A_BT="$(probe exit-both)"; printf '%s\n' "$A_BT" >>"$RUN"
check "5n with both live, the exit is refused" "blocked" "$(val "$A_BT" both_verdict)"
check "5o …naming the refusal" "yes" "$(has "$(val "$A_BT" both_blockers)" "incompatible_rules_present")"
check "5p …and the hold" "yes" "$(has "$(val "$A_BT" both_blockers)" "rules_held_by_manager")"
A_RL="$(probe exit-released)"; printf '%s\n' "$A_RL" >>"$RUN"
check "5q CONTROL: a released row is not a live decision" "0" \
  "$(val "$A_RL" released_count_refused_recorded)"
check "5r …it is history" "1" "$(val "$A_RL" tally_refused_released)"
check "5s …and the rule blocks as an undecided one" "blocked" "$(val "$A_RL" released_verdict)"

say ""
say "=== AFTER · §2 the comparison is made on the live state ==="
probe exit-arrange >/dev/null
A_DS="$(probe drift-source)"; printf '%s\n' "$A_DS" >>"$RUN"
check "6a the source change is still a real difference" "no" "$(val "$A_DS" changed_same_set)"
check "6b THE FIX: the exit is refused" "blocked" "$(val "$A_DS" drift_source_verdict)"
check "6c …the drift is counted" "1" "$(val "$A_DS" drift_source_count_decided_drifted)"
check "6d …and named" "yes" "$(has "$(val "$A_DS" drift_source_blockers)" "migrated_rule_differs_now:1")"
check "6e ⛔ AND NOTHING WAS REWRITTEN" "4/10/2" "$(val "$A_DS" rows_untouched)"
A_DD="$(probe drift-dest)"; printf '%s\n' "$A_DD" >>"$RUN"
check "6f the manager's own destination edit is kept" "4/8/2" "$(val "$A_DD" manager_rows)"
check "6g …and blocks" "blocked" "$(val "$A_DD" drift_dest_verdict)"
check "6h …by name" "yes" "$(has "$(val "$A_DD" drift_dest_blockers)" "migrated_rule_differs_now:1")"
A_DO="$(probe drift-ownership)"; printf '%s\n' "$A_DO" >>"$RUN"
check "6i a product nothing would read any more blocks" "blocked" \
  "$(val "$A_DO" drift_ownership_verdict)"
check "6j …counted on its own" "1" "$(val "$A_DO" drift_ownership_count_decided_unenforced)"
check "6k …and named separately from a drift" "yes" \
  "$(has "$(val "$A_DO" drift_ownership_blockers)" "migrated_rule_not_enforced_now:1")"
A_DR="$(probe drift-restore)"; printf '%s\n' "$A_DR" >>"$RUN"
check "6l CONTROL: put back, the sets agree again" "yes" "$(val "$A_DR" same_set)"
check "6m …and the drift blocker is gone" "0" "$(val "$A_DR" drift_restored_count_decided_drifted)"
check "6n …which is not a latch" "no" \
  "$(has "$(val "$A_DR" drift_restored_blockers)" "migrated_rule_differs_now")"

say ""
say "=== AFTER · §3 the grant scan is complete and says so ==="
probe grants-arrange 55 >/dev/null
A_GM="$(probe grants-migrate)"; printf '%s\n' "$A_GM" >>"$RUN"
check "7a fifty-five grants again" "55" "$(val "$A_GM" granted)"
A_GS="$(probe grants-stale)"; printf '%s\n' "$A_GS" >>"$RUN"
check "7b the fifty-fifth is still the drifted one" "55" \
  "$(val "$A_GS" target_position_in_ledger_order)"
check "7c THE FIX: the default call finds it" "1" "$(val "$A_GS" stale_grants_default_limit)"
check "7d …and lists it" "yes" "$(val "$A_GS" target_listed_by_default)"
check "7e the scan saw every row the ledger counts" "55" "$(val "$A_GS" scan_scanned)"
check "7f …which is what the ledger says exists" "55" "$(val "$A_GS" scan_expected)"
check "7g …so it reached the end" "yes" "$(val "$A_GS" scan_finished)"
check "7h the verdict counts what it examined" "55" "$(val "$A_GS" stale_count_grants_scanned)"
check "7i …is refused" "blocked" "$(val "$A_GS" stale_verdict)"
check "7j …and names the grant" "yes" \
  "$(has "$(val "$A_GS" stale_blockers)" "stale_enforcement_grants:1")"
# The id is whatever this run created, so the check is that the LISTED id is
# the one that drifted — a hard-coded number would be a check about the
# previous run's ids (`alpha.34`).
check "7k …with the post id, so it can be opened" "$(val "$A_GS" target_post_id)" \
  "$(val "$A_GS" stale_stale_grants)"
probe grants-reset >/dev/null

say ""
say "=== AFTER · coverage ==="
A_GL="$(probe coverage-global)"; printf '%s\n' "$A_GL" >>"$RUN"
check "8a the global rule is still read the same way" "3/9/3" "$(val "$A_GL" global_effective)"
check "8b THE FIX: it blocks" "blocked" "$(val "$A_GL" global_verdict)"
check "8c …is counted" "1" "$(val "$A_GL" global_count_global_rules)"
check "8d …and named" "yes" \
  "$(has "$(val "$A_GL" global_blockers)" "global_rule_has_no_destination:1")"
probe coverage-restore >/dev/null
probe coverage-variation-arrange >/dev/null
A_VC="$(probe coverage-variation)"; printf '%s\n' "$A_VC" >>"$RUN"
check "8e CONTROL: the variation level is still counted" "1" "$(val "$A_VC" levels_variation)"
check "8f …the walk still reaches the end" "yes" "$(val "$A_VC" walk_finished)"
check "8g …and it still blocks" "blocked" "$(val "$A_VC" variation_verdict)"
probe coverage-variation-reset >/dev/null

say ""
say "=== the fixture gave everything back ==="
A_RS="$(probe reset)"; printf '%s\n' "$A_RS" >>"$RUN"
check "9a the stashed min max rules are back" "yes" \
  "$([[ "$(val "$A_RS" other_rules_restored)" =~ ^[0-9]+$ ]] && echo yes || echo no)"
note  "9b how many came back" "$(val "$A_RS" other_rules_restored)"
check "9c the global option is back where it was" "yes" "$(val "$A_RS" global_restored)"
A_FIN="$(probe report)"; printf '%s\n' "$A_FIN" >>"$RUN"
note  "9d the verdict the site is left with" "$(val "$A_FIN" report_verdict)"
note  "9e …and its blockers" "$(val "$A_FIN" report_blockers)"

say ""
say "=== §10 the drift this site ALREADY had, which nobody arranged ==="
# Worth its own section: with the stashed rules back, this install turns out
# to carry a `migrated` ledger row whose destination no longer reproduces the
# rule — row 524, written `4/10/2`, on a product whose `_tmc_min_purchase` was
# deleted and whose `_tmc_max_purchase` was emptied by `alpha53-probe.php`'s
# `port` verb, which does that on purpose to measure absent-vs-empty. So this
# is a REAL state of the site, left by an earlier round's own measurement, and
# the two trees answer it differently.
check "10a the fixed tree names it" "yes" \
  "$(has "$(val "$A_FIN" report_blockers)" "migrated_rule_differs_now")"
check "10b …and refuses the exit" "blocked" "$(val "$A_FIN" report_verdict)"
use_tree "$KEEP/base"
B_FIN="$(probe report)"; printf '%s\n' "$B_FIN" >>"$RUN"
check "10c the base does NOT name it" "no" \
  "$(has "$(val "$B_FIN" report_blockers)" "migrated_rule_differs_now")"
note  "10d what the base says instead" "$(val "$B_FIN" report_blockers)"
check "10e …and it scored the product as merely decided" "__absent__" \
  "$(val "$B_FIN" report_count_decided_drifted)"
use_tree "$REPO"

say ""
say "=== totals ==="
say "pass=$PASS"
say "fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
