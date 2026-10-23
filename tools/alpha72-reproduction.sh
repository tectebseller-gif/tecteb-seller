#!/usr/bin/env bash
# The owner's three source findings on `alpha.72`, reproduced on the bytes of
# that DELIVERED package and then measured again on this tree.
#
# WHAT IS MEASURED
#
#   `alpha.72` fixed the staging stall and left three holes the owner then read
#   out of the source. All three are the same mistake from different sides —
#   the bookkeeping was not attached to the unit — and all three are arranged
#   and read twice, once on `alpha.72` and once here:
#
#     §1  THE HAND-OVER EXPIRED A LEASE AND ASKED NOTHING ELSE. Worker A is
#         parked INSIDE a product's image work, by a blocking read on a FIFO
#         held from the hooks WordPress itself calls while building the
#         metadata. The manager presses «ادامه از همین نقطه», worker B takes
#         the job and runs the import out, and only then does A carry on.
#         On `alpha.72` A's own checkpoint was refused — and it had already
#         written a product row, copied an attachment and attached it, into an
#         import it no longer owned: `a_wrote_after_losing_the_job=yes`.
#         «صرف رد checkpoint کارگر قبلی اثبات کافی نیست.»
#     §2  A MAPPED PRODUCT WAS COUNTED AS A FINISHED PRODUCT. The row and its
#         mapping are written before the picture, so a worker SIGKILLed in
#         between leaves a row that exists and has no image — and the skip
#         path on the next pass counted it `done`. Measured: the import
#         reported every unit done with `products_without_image=1` and nothing
#         anywhere naming it.
#     §3  THE ATTEMPT COUNTER WAS PER BATCH, NOT PER UNIT. `tries` was raised
#         once at the top and every unit that landed set it back to nought, so
#         the same interruption read `tries:1` inside the FIRST product of a
#         batch and `tries:0` inside the second — the second product's
#         attempts were never written down at all, and `GIVE_UP_AFTER` could
#         not fire for it however many workers it killed.
#
#   On THIS tree every unit is claimed by its own id before its row, its copy
#   and its manifest; the slow half asks the lease again before attaching
#   anything and hands its copy back if the answer is no; a mapped-but-unfinished
#   product is COMPLETED or named; the count follows the unit and the unit that
#   keeps killing workers is refused by name; and the completion report answers
#   four questions separately instead of one percentage.
#
#   And FIVE CONTROLS that must answer the SAME on both trees, because a
#   measurement that only looks at the broken case cannot tell a fix from a
#   feature that stopped working (`alpha.46`): the dry run reads 2 shops and N
#   products with zero conflicts; the vendors phase records exactly 2; the real
#   reader sees Dokan-shaped data; the same two processes with NO hand-over
#   leave B with nothing to do and A finishing its own page; and the
#   WooCommerce products are byte-for-byte unchanged throughout.
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this with a LATER package installed reports that round's fixes as
# this round's failures, which `CLAUDE.md` records as a rule after it happened
# twice.
#
# ⛔ AND THE «AFTER» TREE COMES FROM `$REPO/src`, never from the install.
#
# ⛔ THE KILL IS A REAL SIGKILL ON A REAL SECOND PROCESS, and the PAUSE is a
# real blocking read in a real second process. An exception is caught and
# recorded by `JobRunner` and an `exit` still runs shutdown functions, so
# neither is a request the host gave up on. There is no `sleep` anywhere in
# this script.
#
# ⛔ NO REAL MAIL, SMS OR PAYMENT, and no order path at all: the sale gate is
# NOT opened and nothing here needs it.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`):
# `abzarwp/` is SourceGuardian-encrypted. The fixture is Dokan-SHAPED data —
# two sellers carrying the `seller` role, their products, their pictures, and
# an empty `wp_dokan_orders` — read by the REAL `WpDokanReader`. Only the data
# is ours.
#
# ⛔ AND EVERY GUARANTEE IS GIVEN BACK: the probe's `reset` rolls the import
# back through the documented `rollback()`, then deletes exactly the users,
# posts, attachments and table it created. Nothing of the shop's own media is
# touched.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha72-reproduction.sh docs/evidence/alpha72-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha72-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PRODUCTS="${TMC_PRODUCTS:-6}"
PX="${TMC_PX:-900}"
PROBE_SRC="$REPO/tests/Support/alpha72-probe.php"
WORKER_SRC="$REPO/tests/Support/alpha72-worker.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha72-XXXXXX)"

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
# site, the CLI and the repo are — a second process cannot inherit those.
probe() {
  TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" TMC_REPO="$REPO" \
    wpx eval-file "$PROBE_SRC" "$@" 2>>"$RUN"
}
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
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.72 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.72:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.72 commit could not be resolved"; exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.72 tree could not be checked out"; exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the installed package is this round's base (alpha.72)" "$BASE_HASH" "$ORIGINAL_HASH"
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
check "0e the worker the kill and the pause need is in this checkout" "yes" \
  "$([[ -f "$WORKER_SRC" ]] && echo yes || echo no)"

# Arrange the fixture and walk the vendors phase, leaving the job at the first
# products page — where every one of these three findings lives.
#   $1 the check prefix
arrange_to_the_products_page() {
  probe reset >/dev/null
  local ar pl qu rn
  ar="$(probe arrange "$PRODUCTS" "$PX")"; printf '%s\n' "$ar" >>"$RUN"
  check "$1-1 the real reader sees two Dokan shops" "2" "$(val "$ar" reader_vendors)"
  check "$1-2 …and their $PRODUCTS products" "$PRODUCTS" "$(val "$ar" reader_products)"
  check "$1-3 …each with a picture on the WooCommerce product" "$PRODUCTS" \
    "$(val "$ar" products_with_a_picture)"
  pl="$(probe plan)"; printf '%s\n' "$pl" >>"$RUN"
  check "$1-4 the dry run reads two shops" "2" "$(val "$pl" plan_vendors)"
  check "$1-5 …and $PRODUCTS products with zero conflicts" "$PRODUCTS/0" \
    "$(val "$pl" plan_products)/$(val "$pl" plan_conflicts)"
  qu="$(probe queue)"; printf '%s\n' "$qu" >>"$RUN"
  check "$1-6 the import is queued" "yes" "$(val "$qu" queued_created)"
  UNTOUCHED_BEFORE="$(val "$(probe untouched)" wc_digest)"
  rn="$(probe run)"; printf '%s\n' "$rn" >>"$RUN"
  check "$1-7 the vendors phase completes in one batch" "more" "$(val "$rn" run_says)"
  check "$1-8 …recording exactly the two shops" "2" "$(val "$rn" job_done)"
  check "$1-9 …and leaving the cursor at the first products page" "products" \
    "$(printf '%s\n' "$(val "$rn" job_cursor)" | sed -n 's/.*"phase":"\([a-z]*\)".*/\1/p')"
  check "$1-10 no product has been imported yet" "0" "$(val "$rn" mapped)"
  check "$1-11 and the vendors phase changed no WooCommerce product" \
    "$UNTOUCHED_BEFORE" "$(val "$(probe untouched)" wc_digest)"
}

# ======================================================= BEFORE (alpha.72)
use_tree "$KEEP/base"
check "0f the installed tree is now alpha.72's" "$BASE_HASH" "$(digest "$INSTALLED")"

say ""
say "=== BEFORE · §1 two live workers and a hand-over ==="
arrange_to_the_products_page "1a"
R="$(probe race)"; printf '%s\n' "$R" >>"$RUN"
check "1b-1 worker A really reached the image work and parked there" "yes" \
  "$(val "$R" a_reached_the_image_work)"
note  "1b-2 the lease was handed over" "$(val "$R" handed_over)"
check "1b-3 ⛔ nothing was asked about the worker still writing" "handed_over" \
  "$(val "$R" hand_over_without_override)"
check "1b-4 ⛔ nor about the lease having changed since the page was drawn" "handed_over" \
  "$(val "$R" hand_over_with_a_stale_fingerprint)"
check "1b-5 worker B then ran the import out" "8" "$(val "$R" job_done_after_b)"
note  "1b-6 the import as B left it" "$(val "$R" import_digest_after_b)"
check "1b-7 ⛔ …and A, released afterwards, WROTE INTO IT" "yes" \
  "$(val "$R" a_wrote_after_losing_the_job)"
note  "1b-8 the import after A came back" "$(val "$R" import_digest_after_a_resumed)"
check "1b-9 ⛔ A's own checkpoint was refused all the same" "yes" \
  "$([[ "$(val "$R" a_output)" == *lease_lost* ]] && echo yes || echo no)"
check "1b-10 ⛔ and its orphan copy was not handed back" "no" \
  "$(val "$R" a_handed_its_own_copy_back)"
note  "1b-11 products left with no picture" "$(val "$R" missing_image_after_b)"

say ""
say "=== BEFORE · §2 a mapped product counted as a finished one ==="
arrange_to_the_products_page "2a"
H="$(probe half-made 1)"; printf '%s\n' "$H" >>"$RUN"
check "2b-1 the worker was killed inside the first product's picture" "yes" \
  "$([[ "$(val "$H" worker_output)" == *worker_killing_itself_at=1* ]] && echo yes || echo no)"
check "2b-2 the row and its mapping are there" "1" "$(val "$H" mapped)"
check "2b-3 …and its picture is not" "1" "$(val "$H" products_without_image)"
RS="$(probe resume)"; printf '%s\n' "$RS" >>"$RUN"
check "2b-4 the import then runs to the end" "8" "$(val "$RS" done_after)"
check "2b-5 ⛔ counting the half-made product as done" "yes" \
  "$(val "$RS" the_half_made_one_was_counted_done)"
check "2b-6 ⛔ …which still has no picture" "1" "$(val "$RS" missing_image_after)"
check "2b-7 ⛔ and the base package cannot report that at all" "__absent__" \
  "$(val "$(probe completion)" import_completion)"

say ""
say "=== BEFORE · §3 the attempt counter ==="
arrange_to_the_products_page "3a"
S="$(probe stuck-unit 4)"; printf '%s\n' "$S" >>"$RUN"
check "3b-1 the first product lands and the second is killed" "yes" "$(val "$S" pass_1_killed)"
note  "3b-2 the unit the cursor says it was attempting" "$(val "$S" pass_1_at)"
check "3b-3 ⛔ the attempt on the SECOND product is not written down" "0" \
  "$(val "$S" pass_1_tries)"
check "3b-4 ⛔ …nor on the second pass" "0" "$(val "$S" pass_2_tries)"
check "3b-5 ⛔ …nor the third" "0" "$(val "$S" pass_3_tries)"
check "3b-6 ⛔ so no unit is ever refused by name" "__empty__" "$(val "$S" final_refused)"
check "3b-7 ⛔ and nothing is counted as failed" "0" "$(val "$S" final_failed)"

# ======================================================== AFTER (this tree)
use_tree "$REPO"
check "0g the installed tree is now this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"

say ""
say "=== AFTER · §1 the same two workers, the same hand-over ==="
arrange_to_the_products_page "4a"
R2="$(probe race)"; printf '%s\n' "$R2" >>"$RUN"
check "4b-1 worker A reached the image work and parked there" "yes" \
  "$(val "$R2" a_reached_the_image_work)"
check "4b-2 the hand-over REFUSES while the row is still being written to" \
  "worker_still_writing" "$(val "$R2" hand_over_without_override)"
note  "4b-3 seconds since that row was last written" "$(val "$R2" seconds_since_write)"
check "4b-4 …and refuses a form carrying a lease it was not shown" "lock_changed" \
  "$(val "$R2" hand_over_with_a_stale_fingerprint)"
check "4b-5 the manager's explicit override hands it over" "handed_over" \
  "$(val "$R2" handed_over)"
check "4b-6 worker B runs the import out" "8" "$(val "$R2" job_done_after_b)"
check "4b-7 …with every picture carried over" "0" "$(val "$R2" missing_image_after_b)"
check "4b-8 ⛔ A, released afterwards, WRITES NOTHING" "no" \
  "$(val "$R2" a_wrote_after_losing_the_job)"
check "4b-9 …the import is byte-for-byte as B left it" \
  "$(val "$R2" import_digest_after_b)" "$(val "$R2" import_digest_after_a_resumed)"
check "4b-10 …it adds no media either" "no" "$(val "$R2" a_added_media_after_losing_the_job)"
check "4b-11 …and hands back the copy it had already made" "yes" \
  "$(val "$R2" a_handed_its_own_copy_back)"
check "4b-12 no product is mapped twice" "0" "$(val "$R2" duplicate_mappings)"
check "4b-13 and no history is lost: every unit is counted once" "6" \
  "$(val "$R2" mapped_after_a_resumed)"

say ""
say "=== AFTER · §2 a mapped product is not a finished product ==="
arrange_to_the_products_page "5a"
H2="$(probe half-made 1)"; printf '%s\n' "$H2" >>"$RUN"
check "5b-1 the worker was killed inside the first product's picture" "yes" \
  "$([[ "$(val "$H2" worker_output)" == *worker_killing_itself_at=1* ]] && echo yes || echo no)"
check "5b-2 the row is there and its picture is not" "1/1" \
  "$(val "$H2" mapped)/$(val "$H2" products_without_image)"
check "5b-3 …and the cursor names the unit it was attempting" "yes" \
  "$([[ "$(val "$H2" job_cursor)" == *'"tries":1'* ]] && echo yes || echo no)"
RS2="$(probe resume)"; printf '%s\n' "$RS2" >>"$RUN"
check "5b-4 the import runs to the end" "8" "$(val "$RS2" done_after)"
check "5b-5 ⛔ the half-made product is NOT counted done" "no" \
  "$(val "$RS2" the_half_made_one_was_counted_done)"
check "5b-6 ⛔ …it is FINISHED: its picture arrived" "0" "$(val "$RS2" missing_image_after)"
check "5b-7 …without creating a second row for it" "0" "$(val "$RS2" duplicate_mappings)"
CM="$(probe completion)"; printf '%s\n' "$CM" >>"$RUN"
check "5c-1 the completion report counts what is mapped" "$PRODUCTS" "$(val "$CM" service_mapped)"
check "5c-2 …what is finished" "$PRODUCTS" "$(val "$CM" service_complete)"
check "5c-3 …what is still missing a picture" "0" "$(val "$CM" service_without_image)"
check "5c-4 …what could not be judged" "0" "$(val "$CM" service_source_unreadable)"
check "5c-5 …and what is mapped twice" "0" "$(val "$CM" service_duplicate_mappings)"
check "5c-6 the service and the rows agree about the pictures" \
  "$(val "$CM" rows_without_image)" "$(val "$CM" service_without_image)"
check "5c-7 …and about the duplicates" \
  "$(val "$CM" rows_duplicate_mappings)" "$(val "$CM" service_duplicate_mappings)"
check "5c-8 the scan says whether it saw everything" "yes" "$(val "$CM" service_finished)"

say ""
say "=== AFTER · §3 the count follows the unit, and the unit is named ==="
arrange_to_the_products_page "6a"
S2="$(probe stuck-unit 4)"; printf '%s\n' "$S2" >>"$RUN"
check "6b-1 the first product lands and the second is killed" "yes" "$(val "$S2" pass_1_killed)"
STUCK_AT="$(val "$S2" pass_1_at)"
note  "6b-2 the unit being attempted" "$STUCK_AT"
check "6b-3 its first attempt is written down" "1" "$(val "$S2" pass_1_tries)"
check "6b-4 the SAME unit on the second pass" "$STUCK_AT" "$(val "$S2" pass_2_at)"
check "6b-5 …and ITS count rises" "2" "$(val "$S2" pass_2_tries)"
check "6b-6 …and again" "3" "$(val "$S2" pass_3_tries)"
check "6b-7 the first product stays done throughout" "yes" \
  "$([[ "$(val "$S2" pass_3_after)" == "$(val "$S2" pass_1_after)" ]] && echo yes || echo no)"
check "6b-8 ⛔ after the limit, exactly that item is refused BY NAME" "yes" \
  "$([[ "$(val "$S2" final_refused)" == *"$STUCK_AT:image_missing_after_3_attempts"* ]] && echo yes || echo no)"
note  "6b-9 what the cursor says was refused" "$(val "$S2" final_refused)"
check "6b-10 …counted as one failure, not none" "1" "$(val "$S2" final_failed)"
check "6b-11 the product that worked is not blamed" "no" \
  "$([[ "$(val "$S2" final_refused)" == *"$(val "$S2" pass_1_after):"* ]] && echo yes || echo no)"
check "6b-12 and the import still finishes the rest" "$PRODUCTS" "$(val "$S2" mapped)"
check "6b-13 with the one it could not finish REPORTED, not averaged away" "1" \
  "$(val "$S2" products_without_image)"
RP="$(probe repair)"; printf '%s\n' "$RP" >>"$RUN"
check "6c-1 and a manager can finish it afterwards" "yes" "$(val "$RP" repair_ok)"
check "6c-2 …exactly one product" "1" "$(val "$RP" repaired)"
check "6c-3 …leaving none unfinished" "0" "$(val "$RP" still_incomplete)"
check "6c-4 …with nothing refused" "__empty__" "$(val "$RP" refused)"
check "6c-5 the rows agree: no product is missing a picture" "0" \
  "$(val "$RP" rows_without_image_after)"
check "6c-6 and no row was created or duplicated by the repair" "$PRODUCTS/0" \
  "$(val "$RP" mapped)/$(val "$RP" duplicate_mappings)"

# ======================================================== THE CONTROLS
# Five answers that must be the SAME on both trees.
say ""
say "=== the controls ==="
for tree in base now; do
  if [[ "$tree" == base ]]; then use_tree "$KEEP/base"; else use_tree "$REPO"; fi
  probe reset >/dev/null
  AR="$(probe arrange "$PRODUCTS" "$PX")"; printf '%s\n' "$AR" >>"$RUN"
  PL="$(probe plan)"; printf '%s\n' "$PL" >>"$RUN"
  QU="$(probe queue)"; printf '%s\n' "$QU" >>"$RUN"
  U0="$(val "$(probe untouched)" wc_digest)"
  RN="$(probe run)"; printf '%s\n' "$RN" >>"$RUN"
  check "7a-$tree-1 the dry run reads two shops and $PRODUCTS products with no conflict" \
    "2/$PRODUCTS/0" "$(val "$PL" plan_vendors)/$(val "$PL" plan_products)/$(val "$PL" plan_conflicts)"
  check "7a-$tree-2 the vendors phase records exactly two" "2" "$(val "$RN" job_done)"
  check "7a-$tree-3 the reader is the real one and sees Dokan-shaped data" "yes" \
    "$(val "$AR" reader_available)"
  RC="$(probe race-control)"; printf '%s\n' "$RC" >>"$RUN"
  check "7a-$tree-4 with NO hand-over, the second worker finds nothing" "yes" \
    "$(val "$RC" b_found_nothing)"
  check "7a-$tree-5 …and the first finishes its own page: every product, no duplicate" \
    "$PRODUCTS/0" "$(val "$RC" mapped)/$(val "$RC" duplicate_mappings)"
  check "7a-$tree-6 the WooCommerce products are unchanged by all of it" \
    "$U0" "$(val "$(probe untouched)" wc_digest)"
done

say ""
say "=== result ==="
say "checks=$((PASS + FAIL)) pass=$PASS fail=$FAIL"
say "base_src_digest=$BASE_HASH"
say "this_checkout_src_digest=$NOW_HASH"
[[ $FAIL -eq 0 ]]
