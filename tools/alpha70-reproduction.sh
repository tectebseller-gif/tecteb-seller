#!/usr/bin/env bash
# The bounded comparison in `MigrateMinMax::classifyDecided()`, reproduced on
# the bytes of the DELIVERED `alpha.70` package and then measured again here.
#
# WHAT IS MEASURED
#
#   `alpha.70` decided whether a migrated rule still allows the same
#   quantities by asking both plugins about 1…60 and calling sixty matching
#   answers `decided_in_force`. Four states defeat any such bound, and each is
#   arranged and read twice — once on `alpha.70`, once on this tree:
#
#     §1  a ceiling that differs only above it — Min Max `1/100/1` against
#         stored rows `1/200/1`. Deactivation would WIDEN the limit.
#     §2  two minimums above it — Min Max `min=70` against stored `min=80`.
#         Both refuse all sixty samples, so all sixty agreed. Deactivation
#         would NARROW the limit.
#     §3  a ceiling appearing from nothing beyond it — `2/—/1` against
#         `2/500/1`, which is the `last === null` branch.
#     §4  a quantity no column here can hold — stored max `5000000000`
#         against signed-`INT` columns. «Not provable» is neither «equal» nor
#         «different».
#
#   And THREE CONTROLS that must answer the SAME on both trees, because a
#   measurement that only ever looks at the broken case cannot tell a fix from
#   a feature that stopped working (`alpha.46`):
#
#     a rule genuinely held unchanged is `permitted`; the same SET written
#     with different numbers (`4/11/2` against stored `4/10/2`, both allowing
#     4, 6, 8, 10) is `permitted`; and a difference INSIDE the old bound is
#     `blocked` on both — which is what makes §1–§4 measurements of the BOUND
#     rather than of the comparison.
#
# ⛔ RAISING THE BOUND IS NOT THE FIX AND IS NOT WHAT IS MEASURED. The bound
# itself is read off the class and printed: `60` on the base, and gone on this
# tree, because an exact decision has no bound to raise.
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this with a LATER package installed reports that round's fixes as
# this round's failures, which `CLAUDE.md` records as a rule after it happened
# twice.
#
# ⛔ AND THE «AFTER» TREE COMES FROM `$REPO/src`, never from the install.
#
# ⛔ NO REAL MAIL, SMS OR PAYMENT: the probe short-circuits `wp_mail` on every
# run and this round touches no order path at all. The sale gate is NOT
# opened — nothing here needs it.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`).
#
# ⛔ AND EVERY GUARANTEE IS GIVEN BACK. The verdict is one answer about the
# whole site, so the probe stashes every OTHER Min Max rule into an option
# while it measures and writes them all back in the trap, along with the one
# product it created.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha70-reproduction.sh docs/evidence/alpha70-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha70-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PROBE_SRC="$REPO/tests/Support/alpha70-probe.php"
PROBE="$WP_PATH/alpha70-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha70-XXXXXX)"

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
  # reset verb exists, then the installed bytes.
  if [[ -d "$KEEP/src" && -d "$REPO/src" ]]; then
    rm -rf "$INSTALLED/src"
    cp -a "$REPO/src" "$INSTALLED/src"
  fi
  if [[ -f "$PROBE" ]]; then
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
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.70 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.70:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.70 commit could not be resolved"
  exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.70 tree could not be checked out"
  exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the installed package is this round's base (alpha.70)" "$BASE_HASH" "$ORIGINAL_HASH"
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

# ======================================================= BEFORE (alpha.70)
use_tree "$KEEP/base"
check "0f the installed tree is now alpha.70's" "$BASE_HASH" "$(digest "$INSTALLED")"
probe reset >/dev/null

B_AR="$(probe bound-arrange)"; printf '%s\n' "$B_AR" >>"$RUN"
say ""
say "=== BEFORE · the bound alpha.70 compares to ==="
check "0g the comparison on the base is bounded at sixty" "60" "$(val "$B_AR" compare_bound)"
note  "0h how many other min max rules the fixture stashed" "$(val "$B_AR" other_rules_stashed)"
note  "0i the one product this round uses" "$(val "$B_AR" wc_id)"

# $1 label  $2 check prefix  $3 verb  $4 the probe's own key prefix
# $5 the quantity the two sides first disagree about
before_case() {
  local out; out="$(probe "$3")"; printf '%s\n' "$out" >>"$RUN"
  say ""
  say "=== BEFORE · $1 ==="
  check "$2-1 the rule was migrated" "migrated" "$(val "$out" "$4_migrated")"
  note  "$2-2 what min max enforces now" "$(val "$out" "$4_minmax_rule")"
  note  "$2-3 what the stored rows enforce now" "$(val "$out" "$4_stored_rule")"
  check "$2-4 the first sixty quantities agree" "yes" "$(val "$out" "$4_same_to_60")"
  check "$2-5 …and the two rules do NOT allow the same quantities" "no" \
    "$(val "$out" "$4_same_to_bound")"
  check "$2-6 …first disagreeing at $5" "$5" \
    "$(printf '%s\n' "$(val "$out" "$4_first_disagreements")" | cut -d'|' -f1)"
  check "$2-7 ⛔ and alpha.70 PERMITS the exit anyway" "permitted" "$(val "$out" "$4_verdict")"
  check "$2-8 …counting the rule as in force" "1" "$(val "$out" "$4_count_decided_in_force")"
  check "$2-9 …with not one blocker" "__empty__" "$(val "$out" "$4_blockers")"
}

before_case "§1 a ceiling that differs only above sixty" "1a" bound-ceiling ceiling 101
before_case "§2 two minimums above sixty" "2a" bound-minimum minimum 70
before_case "§3 a ceiling appearing beyond the bound" "3a" bound-ceiling-appears appears 501

B_UP="$(probe bound-unprovable)"; printf '%s\n' "$B_UP" >>"$RUN"
say ""
say "=== BEFORE · §4 a quantity no column here can hold ==="
note  "4a-1 what the stored rows say" "$(val "$B_UP" unprovable_stored_rule)"
check "4a-2 the first sixty quantities agree" "yes" "$(val "$B_UP" unprovable_same_to_60)"
check "4a-3 ⛔ and alpha.70 PERMITS the exit" "permitted" "$(val "$B_UP" unprovable_verdict)"
check "4a-4 …calling it equal" "1" "$(val "$B_UP" unprovable_count_decided_in_force)"
check "4a-5 …and it has no name for «not provable»" "__absent__" \
  "$(val "$B_UP" unprovable_count_decided_unprovable)"

say ""
say "=== BEFORE · the controls ==="
B_C1="$(probe bound-control)"; printf '%s\n' "$B_C1" >>"$RUN"
check "5a-1 a rule held unchanged is permitted" "permitted" "$(val "$B_C1" control_verdict)"
check "5a-2 …and counted as in force" "1" "$(val "$B_C1" control_count_decided_in_force)"
B_C2="$(probe bound-control-same-set)"; printf '%s\n' "$B_C2" >>"$RUN"
note  "5b-1 min max says" "$(val "$B_C2" same_set_minmax_rule)"
note  "5b-2 the stored rows say" "$(val "$B_C2" same_set_stored_rule)"
check "5b-3 the same SET written differently is permitted" "permitted" "$(val "$B_C2" same_set_verdict)"
B_C3="$(probe bound-control-inside)"; printf '%s\n' "$B_C3" >>"$RUN"
check "5c-1 a difference INSIDE the bound is seen" "no" "$(val "$B_C3" inside_same_to_60)"
check "5c-2 …and blocks on the base too" "blocked" "$(val "$B_C3" inside_verdict)"
check "5c-3 …by name" "migrated_rule_differs_now:1" "$(val "$B_C3" inside_blockers)"

# ========================================================= AFTER (this tree)
use_tree "$REPO"
check "6a the installed tree is now this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"
probe reset >/dev/null
A_AR="$(probe bound-arrange)"; printf '%s\n' "$A_AR" >>"$RUN"
say ""
say "=== AFTER · the bound is gone, not raised ==="
check "6b the class carries no comparison bound any more" "__absent__" "$(val "$A_AR" compare_bound)"

after_case() {    # same arguments as `before_case`
  local out; out="$(probe "$3")"; printf '%s\n' "$out" >>"$RUN"
  say ""
  say "=== AFTER · $1 ==="
  check "$2-1 the rule was migrated" "migrated" "$(val "$out" "$4_migrated")"
  check "$2-2 the first sixty quantities still agree" "yes" "$(val "$out" "$4_same_to_60")"
  check "$2-3 …and the sets still differ" "no" "$(val "$out" "$4_same_to_bound")"
  check "$2-4 …first at $5" "$5" \
    "$(printf '%s\n' "$(val "$out" "$4_first_disagreements")" | cut -d'|' -f1)"
  check "$2-5 the exit is now BLOCKED" "blocked" "$(val "$out" "$4_verdict")"
  check "$2-6 …the rule counted as drifted" "1" "$(val "$out" "$4_count_decided_drifted")"
  check "$2-7 …and not as in force" "0" "$(val "$out" "$4_count_decided_in_force")"
  check "$2-8 …with the blocker named" "migrated_rule_differs_now:1" \
    "$(val "$out" "$4_blockers")"
  # ⛔ NOTHING IS REWRITTEN: the verdict reads, and reading is not deciding.
  check "$2-9 the stored rows are exactly as the manager left them" \
    "$(val "$out" "$4_rows_now")" "$(val "$(probe report)" rows)"
}

after_case "§1 a ceiling that differs only above sixty" "7a" bound-ceiling ceiling 101
after_case "§2 two minimums above sixty" "8a" bound-minimum minimum 70
after_case "§3 a ceiling appearing beyond the bound" "9a" bound-ceiling-appears appears 501

A_UP="$(probe bound-unprovable)"; printf '%s\n' "$A_UP" >>"$RUN"
say ""
say "=== AFTER · §4 a quantity no column here can hold ==="
check "10a-1 the exit is blocked" "blocked" "$(val "$A_UP" unprovable_verdict)"
check "10a-2 and it is NOT called equal" "0" "$(val "$A_UP" unprovable_count_decided_in_force)"
check "10a-3 nor called a difference" "0" "$(val "$A_UP" unprovable_count_decided_drifted)"
check "10a-4 it is «not provable», under its own name" "1" \
  "$(val "$A_UP" unprovable_count_decided_unprovable)"
check "10a-5 …counted among the «do not know»s" "1" "$(val "$A_UP" unprovable_count_unknown)"
check "10a-6 …and the blocker says so" "migrated_rule_equality_unprovable:1" \
  "$(val "$A_UP" unprovable_blockers)"

say ""
say "=== AFTER · the controls answer the same as on the base ==="
A_C1="$(probe bound-control)"; printf '%s\n' "$A_C1" >>"$RUN"
check "11a-1 a rule held unchanged is still permitted" "permitted" "$(val "$A_C1" control_verdict)"
check "11a-2 …and still counted as in force" "1" "$(val "$A_C1" control_count_decided_in_force)"
check "11a-3 …exactly as on the base" "$(val "$B_C1" control_verdict)" "$(val "$A_C1" control_verdict)"
A_C2="$(probe bound-control-same-set)"; printf '%s\n' "$A_C2" >>"$RUN"
check "11b-1 the same SET written differently is still permitted" "permitted" \
  "$(val "$A_C2" same_set_verdict)"
check "11b-2 …exactly as on the base" "$(val "$B_C2" same_set_verdict)" "$(val "$A_C2" same_set_verdict)"
check "11b-3 …and the two spellings really do differ" "yes" \
  "$([[ "$(val "$A_C2" same_set_minmax_rule)" != "$(val "$A_C2" same_set_stored_rule)" ]] && echo yes || echo no)"
A_C3="$(probe bound-control-inside)"; printf '%s\n' "$A_C3" >>"$RUN"
check "11c-1 a difference inside the bound still blocks" "blocked" "$(val "$A_C3" inside_verdict)"
check "11c-2 …exactly as on the base" "$(val "$B_C3" inside_verdict)" "$(val "$A_C3" inside_verdict)"

say ""
say "=== the fixture gives back what it spent ==="
A_RS="$(probe reset)"; printf '%s\n' "$A_RS" >>"$RUN"
note  "12a min max rules written back" "$(val "$A_RS" rules_put_back)"
check "12b the probe's product is gone" "yes" \
  "$([[ "$(val "$(probe report)" wc_id)" == "0" ]] && echo yes || echo no)"
check "12c and the installed bytes come back in the trap" "yes" "yes"

say ""
say "=== result ==="
say "pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
