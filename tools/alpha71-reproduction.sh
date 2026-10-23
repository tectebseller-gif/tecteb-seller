#!/usr/bin/env bash
# The Dokan import that stopped at «۲ از ۱۸۶», reproduced on the bytes of the
# DELIVERED `alpha.71` package and then measured again here.
#
# WHAT IS MEASURED
#
#   On staging, `alpha.71` queued the import (2 vendors, 184 products, zero
#   conflicts), finished the vendors phase, and then never moved: three
#   attempts, the same `2/186`, status «در حال اجرا», «بی‌صاحب مانده ۰», and
#   «اجرای دستی یک دسته» answering «چیزی در صف نبود». No fatal, no database
#   error and no memory message in `debug.log`.
#
#   Both halves of that are arranged and read twice — once on `alpha.71`, once
#   on this tree:
#
#     §1  THE CHECKPOINT FOLLOWED THE PAGE, NOT THE WORK. One page is fifty
#         products, each a file copy and a full
#         `wp_generate_attachment_metadata()`; measured here at 24.4 s for the
#         fifty. A worker SIGKILLed part way through had created products and
#         recorded NONE of them: the cursor did not move, `done` did not move,
#         only `attempts` did — so the next worker started at the same place.
#     §2  A DEAD WORKER'S LEASE HID THE JOB FROM THE ONLY BUTTON THAT COULD
#         MOVE IT. `claim()` skips a `running` row whose `locked_until` is in
#         the future, which is right while a worker is alive and useless once
#         the process is gone. For 300 s after each death the page says «در
#         حال اجرا ۱ / بی‌صاحب ۰» and a manual run says «چیزی در صف نبود» —
#         both true, and no action offered.
#     §3  AND A NEW IMPORT CANNOT BE STARTED, because the live key is held by
#         the stuck job. The only way out in `alpha.71` is «لغو», which throws
#         the position away.
#
#   On THIS tree the same kill leaves at most ONE unit unrecorded, the cursor
#   moves, a live-lease orphan is handed over on request, a position that has
#   killed three workers is NAMED and stepped over, and the import runs to
#   `done` with 184 products and no duplicate.
#
#   And FOUR CONTROLS that must answer the SAME on both trees, because a
#   measurement that only looks at the broken case cannot tell a fix from a
#   feature that stopped working (`alpha.46`): the dry run reads 2 and 184 with
#   zero conflicts; the vendors phase completes and records exactly 2; a second
#   import is refused while one is live (that is the dedupe working, not the
#   defect); and the WooCommerce products are byte-for-byte unchanged by the
#   vendors phase.
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this with a LATER package installed reports that round's fixes as
# this round's failures, which `CLAUDE.md` records as a rule after it happened
# twice.
#
# ⛔ AND THE «AFTER» TREE COMES FROM `$REPO/src`, never from the install.
#
# ⛔ THE KILL IS A REAL SIGKILL ON A REAL SECOND PROCESS. An exception is
# caught and recorded by `JobRunner`, and an `exit` still runs shutdown
# functions; neither is a request the host gave up on, which is what leaves no
# fatal in any log.
#
# ⛔ NO REAL MAIL, SMS OR PAYMENT, and no order path at all: the sale gate is
# NOT opened and nothing here needs it.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`):
# `abzarwp/` is SourceGuardian-encrypted. The fixture is Dokan-SHAPED data —
# two sellers carrying the `seller` role, their products, their images, and an
# empty `wp_dokan_orders` — read by the REAL `WpDokanReader`. Only the data is
# ours.
#
# ⛔ AND EVERY GUARANTEE IS GIVEN BACK: the probe's `reset` rolls the import
# back through the documented `rollback()`, then deletes exactly the users,
# posts, attachments and table it created.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha71-reproduction.sh docs/evidence/alpha71-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha71-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PRODUCTS="${TMC_PRODUCTS:-184}"
KILL_AT="${TMC_KILL_AT:-6}"
PROBE_SRC="$REPO/tests/Support/alpha71-probe.php"
WORKER_SRC="$REPO/tests/Support/alpha71-worker.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha71-XXXXXX)"

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
# The probe runs from the REPO, and the worker it launches is told where the
# site and the CLI are — a second process cannot inherit those.
probe() { TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" wpx eval-file "$PROBE_SRC" "$@" 2>>"$RUN"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
digest() { (cd "$1" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16); }

restore() {
  if [[ -d "$KEEP/src" && -d "$REPO/src" ]]; then
    rm -rf "$INSTALLED/src"; cp -a "$REPO/src" "$INSTALLED/src"
  fi
  probe reset >/dev/null 2>&1
  if [[ -d "$KEEP/src" ]]; then
    rm -rf "$INSTALLED/src"; cp -a "$KEEP/src" "$INSTALLED/src"
  fi
}
trap restore EXIT

# --------------------------------------------------------------- the trees
cp -a "$INSTALLED/src" "$KEEP/src"
ORIGINAL_HASH="$(digest "$KEEP")"
NOW_HASH="$(digest "$REPO")"
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.71 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.71:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.71 commit could not be resolved"; exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.71 tree could not be checked out"; exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the installed package is this round's base (alpha.71)" "$BASE_HASH" "$ORIGINAL_HASH"
check "0b this checkout differs from the base" "differ" \
  "$([[ "$NOW_HASH" == "$BASE_HASH" ]] && echo same || echo differ)"

use_tree() { rm -rf "$INSTALLED/src"; cp -a "$1/src" "$INSTALLED/src"; }

# ⛔ ONE DATABASE, TWO SETS OF CODE BYTES. This round adds NO migration —
# asserted, not assumed, because a structure that moved in the middle would
# make the two halves two sites.
SCHEMA="$(wpx option get tmc_schema_version 2>/dev/null | head -1)"
say "schema_for_both_halves=$SCHEMA"
check "0c the structure is 34 and this round does not move it" "34" "$SCHEMA"
check "0d no migration file changed in this round" "0" \
  "$(cd "$REPO" && git diff --name-only "$BASE_REF" -- 'src/**/Migrations/' | wc -l | tr -d ' ')"
check "0e the worker the kill needs is in this checkout" "yes" \
  "$([[ -f "$WORKER_SRC" ]] && echo yes || echo no)"

# One half of the measurement: arrange the fixture, walk the vendors phase, and
# leave the job exactly where staging's was — at the first products page.
#   $1 the check prefix
arrange_to_the_products_page() {
  probe reset >/dev/null
  local ar pl qu rn
  ar="$(probe arrange "$PRODUCTS" 1400)"; printf '%s\n' "$ar" >>"$RUN"
  check "$1-1 the real reader sees two Dokan shops" "2" "$(val "$ar" reader_vendors)"
  check "$1-2 …and their $PRODUCTS products" "$PRODUCTS" "$(val "$ar" reader_products)"
  pl="$(probe plan)"; printf '%s\n' "$pl" >>"$RUN"
  check "$1-3 the dry run reads two shops" "2" "$(val "$pl" plan_vendors)"
  check "$1-4 …and $PRODUCTS products" "$PRODUCTS" "$(val "$pl" plan_products)"
  check "$1-5 …with zero conflicts, as staging reported" "0" "$(val "$pl" plan_conflicts)"
  check "$1-6 …so the job's total is $((PRODUCTS + 2))" "$((PRODUCTS + 2))" "$(val "$pl" plan_total)"
  qu="$(probe queue)"; printf '%s\n' "$qu" >>"$RUN"
  check "$1-7 the import is queued" "yes" "$(val "$qu" queued_created)"
  UNTOUCHED_BEFORE="$(val "$(probe untouched)" wc_digest)"
  rn="$(probe run)"; printf '%s\n' "$rn" >>"$RUN"
  check "$1-8 the vendors phase completes in one batch" "more" "$(val "$rn" run_says)"
  check "$1-9 …recording exactly the two shops" "2" "$(val "$rn" job_done)"
  check "$1-10 …and leaving the cursor at the first products page" "products" \
    "$(printf '%s\n' "$(val "$rn" job_cursor)" | sed -n 's/.*"phase":"\([a-z]*\)".*/\1/p')"
  check "$1-11 …which is staging's «۲ از ۱۸۶»" "1%" "$(val "$rn" job_progress)"
  check "$1-12 no product has been imported yet" "0" "$(val "$rn" observed_rows)"
  check "$1-13 and the vendors phase changed no WooCommerce product" \
    "$UNTOUCHED_BEFORE" "$(val "$(probe untouched)" wc_digest)"
  # How many times that batch recorded its position INSIDE itself. Asked here
  # because a batch definitely ran — asking it after a kill answers about a
  # run that found nothing, which is a different question.
  VENDOR_CHECKPOINTS="$(val "$rn" run_checkpoints)"
  note "$1-14 checkpoints written inside the vendors batch" "$VENDOR_CHECKPOINTS"
}

# ======================================================= BEFORE (alpha.71)
use_tree "$KEEP/base"
check "0f the installed tree is now alpha.71's" "$BASE_HASH" "$(digest "$INSTALLED")"
say ""
say "=== BEFORE · the import, up to where staging stopped ==="
arrange_to_the_products_page "1a"

K="$(probe kill "$KILL_AT")"; printf '%s\n' "$K" >>"$RUN"
say ""
say "=== BEFORE · §1 a worker killed inside the products page ==="
check "1b-1 the worker really was killed, not finished" "yes" "$(val "$K" worker_was_killed)"
note  "1b-2 how far it got before the kill" "$(val "$K" worker_said)"
check "1b-3 ⛔ the cursor did not move" "no" "$(val "$K" cursor_moved)"
check "1b-4 ⛔ …and it is still the first products page" '{"phase":"products","after":0}' \
  "$(val "$K" after_cursor)"
check "1b-5 ⛔ the recorded figure did not move either" "2" "$(val "$K" after_done)"
check "1b-6 …only the attempt count did" "2" "$(val "$K" after_attempts)"
check "1b-7 …and the row still says it is running" "running" "$(val "$K" after_status)"
B_CREATED="$(val "$K" created_this_batch)"
note  "1b-8 ⛔ products that ARRIVED in that batch" "$B_CREATED"
check "1b-9 ⛔ …products the row counted for them" "0" "$(val "$K" counted_this_batch)"
check "1b-10 ⛔ …so this many units of work reached the site and not the row" \
  "$B_CREATED" "$(val "$K" unrecorded_units)"
check "1b-11 ⛔ the base tree cannot checkpoint inside a batch at all" "__absent__" \
  "$VENDOR_CHECKPOINTS"

# Twice more, five minutes apart, which is what staging's «تلاش ۳» is made of.
probe expire-lease >/dev/null
K1="$(probe kill "$KILL_AT")"; printf '%s\n' "$K1" >>"$RUN"
check "1c-1 a second death at the same place" "yes" "$(val "$K1" worker_was_killed)"
check "1c-2 ⛔ …and the cursor still has not moved" "no" "$(val "$K1" cursor_moved)"
check "1c-3 ⛔ …nor the recorded figure" "2" "$(val "$K1" after_done)"
check "1c-4 …three attempts now, exactly as staging reported" "3" "$(val "$K1" after_attempts)"
note  "1c-5 ⛔ products on the site by now, none of them counted" "$(val "$K1" after_observed)"

S="$(probe state)"; printf '%s\n' "$S" >>"$RUN"
say ""
say "=== BEFORE · §2 the queue page, and §3 the way out ==="
check "2a-1 one job «در حال اجرا»" "1" "$(val "$S" census_running)"
check "2a-2 ⛔ and «بی‌صاحب مانده» is nought — staging's screen" "0" "$(val "$S" census_stalled)"
check "2a-3 nothing is «در صف»" "0" "$(val "$S" census_pending)"
check "2a-4 the row says three attempts have been made" "3" "$(val "$S" job_attempts)"
check "2a-5 …and still «۲ از ۱۸۶»" "2" "$(val "$S" job_done)"
check "2a-6 …with no reason recorded anywhere" "__empty__" "$(val "$S" job_last_error)"
check "2a-7 ⛔ a manual run answers «چیزی در صف نبود»" "nothing_was_in_the_queue" \
  "$(val "$(probe run)" run_says)"
check "2a-8 the page cannot count a stuck job at all" "__absent__" "$(val "$S" census_stuck)"
check "3a-1 ⛔ and a new import is refused while this one is live" "no" \
  "$(val "$S" second_import_created)"
note  "3a-2 …pointing back at the stuck job" "$(val "$S" second_import_points_at)"
check "3a-3 the base tree has no hand-over at all" "__absent__" \
  "$(val "$(probe hand-over)" handed_over)"

# ======================================================== AFTER (this tree)
use_tree "$REPO"
check "0g the installed tree is now this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"
say ""
say "=== AFTER · the import, up to where staging stopped ==="
arrange_to_the_products_page "4a"

K2="$(probe kill "$KILL_AT")"; printf '%s\n' "$K2" >>"$RUN"
say ""
say "=== AFTER · §1 the same kill, inside the same page ==="
check "4b-1 the worker really was killed, not finished" "yes" "$(val "$K2" worker_was_killed)"
check "4b-2 the cursor MOVED" "yes" "$(val "$K2" cursor_moved)"
check "4b-3 …past the first products page" "no" \
  "$([[ "$(val "$K2" after_cursor)" == *'"after":0'* ]] && echo yes || echo no)"
check "4b-4 the recorded figure moved too" "yes" \
  "$([[ "$(val "$K2" after_done)" -gt 2 ]] && echo yes || echo no)"
check "4b-5 …and at most ONE unit was in flight and lost" "yes" \
  "$([[ "$(val "$K2" unrecorded_units)" -le 1 ]] && echo yes || echo no)"
note  "4b-6 products that arrived in that batch" "$(val "$K2" created_this_batch)"
note  "4b-7 …units the row counted for them" "$(val "$K2" counted_this_batch)"
note  "4b-8 …unrecorded" "$(val "$K2" unrecorded_units)"

W="$(probe why)"; printf '%s\n' "$W" >>"$RUN"
H="$(probe hand-over)"; printf '%s\n' "$H" >>"$RUN"
say ""
say "=== AFTER · §2 the dead worker's lease, and the way past it ==="
check "5a-1 the job is still held by a lease nobody is using" "1" \
  "$(val "$W" jobs_holding_a_live_lease)"
check "5a-2 …so a run finds nothing, exactly as on staging" "no" \
  "$(val "$H" claim_finds_anything)"
# ⛔ UPDATED FOR `alpha.73`. The hand-over now has three answers and refuses a
# row that has just been written to; this scenario is always that, because the
# kill happened a second earlier. The probe sends the manager's explicit
# override, which is what a person pressing the button after a real death does,
# and the answer is normalised so this same check still reads `alpha.71`.
check "5a-3 the hand-over ends that lease, on the manager's explicit say-so" "yes" \
  "$(val "$H" handed_over)"
check "5a-4 …and the very next batch runs" "1" "$(val "$H" after_hand_over_batches)"
check "5a-5 …carrying on rather than finishing" "more" "$(val "$H" after_hand_over_says)"
check "5a-6 …having recorded its position once per unit" "yes" \
  "$([[ "$(val "$H" after_hand_over_checkpoints)" -ge 2 ]] && echo yes || echo no)"
note  "5a-7 checkpoints written inside that one batch" "$(val "$H" after_hand_over_checkpoints)"

P2="$(probe poison 2)"; printf '%s\n' "$P2" >>"$RUN"
say ""
say "=== AFTER · §1 a position that has killed workers ==="
check "6a-1 two deaths at one place is still tried again" "more" "$(val "$P2" poison_says)"
check "6a-2 …and nothing is blamed for them" "0" "$(val "$P2" poison_failed_delta)"
check "6a-3 …the batch did its work" "yes" \
  "$([[ "$(val "$P2" poison_created)" -gt 0 ]] && echo yes || echo no)"
P3="$(probe poison 3)"; printf '%s\n' "$P3" >>"$RUN"
check "6b-1 three deaths at one place is not tried a fourth time" "more" "$(val "$P3" poison_says)"
# ⛔ UPDATED FOR `alpha.73`, and both of these read the ROW now rather than the
# batch's leftovers, because that is where the figures live once every unit
# records itself.
#
#  - `poison_failed_delta` is what the batch RETURNED, and a refusal recorded by
#    the unit that caused it is already on the row — so the residual is
#    correctly nought and `poison_job_failed` is the number to read.
#  - And the batch no longer stops at the unit it gave up on: that one item is
#    named and the other forty-nine of the page arrive in the SAME batch, which
#    is what «دقیقاً همان قلم رد شود» asks for. The assertion that «nothing is
#    created» described a batch that returned early, not a guarantee.
check "6b-2 …the one unit there is counted as refused, on the row" "1" \
  "$(val "$P3" poison_job_failed)"
check "6b-3 …and exactly one unit of the page did not arrive" "yes" \
  "$([[ "$(val "$P3" poison_created)" -eq 49 ]] && echo yes || echo no)"
check "6b-4 …the cursor steps past it" "yes" "$(val "$P3" poison_cursor_moved)"
check "6b-5 …and it is NAMED, with its own id" "yes" \
  "$([[ "$(val "$P3" poison_cursor_after)" == *'stalled_after_3_attempts'* ]] && echo yes || echo no)"
note  "6b-6 the cursor afterwards" "$(val "$P3" poison_cursor_after)"

C="$(probe complete 40)"; printf '%s\n' "$C" >>"$RUN"
U="$(probe untouched)"; printf '%s\n' "$U" >>"$RUN"
say ""
say "=== AFTER · §1 the interrupted import runs to the end ==="
check "7a-1 the job reaches «انجام شد»" "done" "$(val "$C" job_status)"
check "7a-2 …at a hundred per cent" "100%" "$(val "$C" job_progress)"
check "7a-3 …having counted every unit it was given" "$((PRODUCTS + 2))" \
  "$(( $(val "$C" job_done) + $(val "$C" job_failed) ))"
note  "7a-4 of those, counted as refused" "$(val "$C" job_failed)"
note  "7a-5 batches it took after the kill" "$(val "$C" complete_batches)"
check "7a-6 ⛔ no WooCommerce product was mapped twice" "0" "$(val "$U" duplicate_mappings)"
check "7a-7 ⛔ no shop got a second profile" "0" "$(val "$U" duplicate_vendor_profiles)"
check "7a-8 ⛔ the WooCommerce products are byte-for-byte what they were" \
  "$UNTOUCHED_BEFORE" "$(val "$U" wc_digest)"
check "7a-9 ⛔ and Dokan's own rows are untouched" "2" "$(val "$U" dokan_sellers)"
check "7a-10 the products that arrived, plus the ones refused" "$PRODUCTS" \
  "$(( $(val "$U" wc_products) ))"

# ============================================ CONTROLS, on BOTH trees
# A fixture reset and one vendors phase, measured the same way on each tree.
say ""
say "=== CONTROLS · the same answer on both trees ==="
for tree in base now; do
  if [[ "$tree" == base ]]; then use_tree "$KEEP/base"; else use_tree "$REPO"; fi
  probe reset >/dev/null
  AR="$(probe arrange "$PRODUCTS" 1400)"
  PL="$(probe plan)"
  QU="$(probe queue)"
  RN="$(probe run)"
  ST="$(probe state)"
  check "8a-$tree-1 the dry run reads two shops and $PRODUCTS products with no conflict" \
    "2/$PRODUCTS/0" "$(val "$PL" plan_vendors)/$(val "$PL" plan_products)/$(val "$PL" plan_conflicts)"
  check "8a-$tree-2 the vendors phase records exactly two" "2" "$(val "$RN" job_done)"
  check "8a-$tree-3 a second import is refused while one is live" "no" \
    "$(val "$ST" second_import_created)"
  check "8a-$tree-4 the reader is the real one and sees Dokan-shaped data" "yes" \
    "$(val "$AR" reader_available)"
  # NOT a control: the one thing that must DIFFER, asked in the one place a
  # batch certainly ran on both trees.
  if [[ "$tree" == base ]]; then
    check "8b-base the base tree records a batch and nothing inside it" "__absent__" \
      "$(val "$RN" run_checkpoints)"
  else
    check "8b-now this tree records every unit inside the batch" "yes" \
      "$([[ "$(val "$RN" run_checkpoints)" -ge 1 ]] && echo yes || echo no)"
    note "8b-now-count checkpoints inside the vendors batch" "$(val "$RN" run_checkpoints)"
  fi
done

say ""
say "=== result ==="
say "checks=$((PASS + FAIL)) pass=$PASS fail=$FAIL"
say "base_src_digest=$BASE_HASH"
say "this_checkout_src_digest=$NOW_HASH"
[[ $FAIL -eq 0 ]]
