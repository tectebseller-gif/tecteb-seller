#!/usr/bin/env bash
# The owner's remaining finding on `alpha.73`, reproduced on the bytes of that
# DELIVERED package and then measured again on this tree.
#
# WHAT IS MEASURED
#
#   `alpha.73` claimed every unit before its writes and asked the lease again
#   between the slow half of the image work and the attach. The owner read the
#   source and found that the asking is still CHECK and then WRITE:
#
#     duplicateForVendor() → progress->stillOurs() → products->saveImages()
#
#   Two round trips sit between the answer and the write, and the scenario the
#   report names walks straight through them:
#
#     §1  THE ATTACH WINDOW. Worker A prepares its own copy, `stillOurs()`
#         answers yes for it, and A is parked at the very next statement — the
#         `DELETE` that begins `saveImages()`. With the hand-over permitted, B
#         takes the job, attaches ITS own picture and records progress. Only
#         then is A released. On `alpha.73` A's own checkpoint WAS refused
#         (`outcome:lease_lost`) and that refusal took nothing back: the
#         picture B attached had already been replaced and B's copy was left in
#         the shop's media library with nothing pointing at it.
#         «رد checkpoint نهایی A، نوشتن قبلی تصویر را پس نمی‌گیرد.»
#     §2  THE CREATE WINDOW, which the report also named: between
#         `claim(unit)` and `create()`/`rememberRun()`. Measured on the same
#         bytes it produces NO duplicate — and `create-gap-mechanism` names
#         why rather than inferring it: `wc_product_id` carries the UNIQUE key
#         `tmc_product_wc` (migration 6), so the stale worker's insert is
#         refused and `create()` answers `0`. ⛔ That window is held by the
#         SCHEMA, not by the lease. On this tree the lease holds it too, and
#         the index goes back to being the second line of defence.
#
#   On THIS tree the ownership check and the writes it governs are ONE unit of
#   work: a transaction whose FIRST statement is a locking read of this job's
#   row, `hash_equals` on the token, then the dependent writes, then commit.
#   Everything that can end a lease needs that same row — `claim()` writes the
#   next worker's token, `handOver()` expires the lease — so a hand-over and a
#   unit's write phase cannot interleave. One of them waits.
#
#   ⛔ AND THE SLOW HALF STAYS OUTSIDE IT. The file copy and
#   `wp_generate_attachment_metadata()` run before the hold, so one import
#   cannot stall every other worker of its type. `a_write_phase_ms` and
#   `clean_write_phase_ms` are printed rather than asserted, because the
#   guarantee is about WHAT is inside the hold and the number is the evidence
#   that nothing slow is.
#
#   AND SIX CONTROLS that must answer the SAME on both trees, because a
#   measurement that only looks at the broken case cannot tell a fix from a
#   feature that stopped working (`alpha.46`): the real reader sees
#   Dokan-shaped data; the dry run reads two shops with zero conflicts; the
#   vendors phase records exactly two; an UNINTERRUPTED import maps everything
#   with no orphan, no duplicate and a complete manifest; a resume whose lease
#   went while the worker was still in the COPY is refused on both trees
#   (`alpha.73` already refused that one, and a fix that works only because an
#   earlier one stopped working is not a fix); and the WooCommerce products are
#   byte-for-byte unchanged throughout.
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this with a LATER package installed reports that round's fixes as
# this round's failures, which `CLAUDE.md` records as a rule after it happened
# twice.
#
# ⛔ AND THE «AFTER» TREE COMES FROM `$REPO/src`, never from the install.
#
# ⛔ THE PARK IS A REAL BLOCKING READ IN A REAL SECOND PROCESS and the
# hand-over is a THIRD process on its own connection. There is no `sleep`
# anywhere in this script, and nothing here is a request the host gave up on.
#
# ⛔ NO REAL MAIL, SMS OR PAYMENT, and no order path at all: the sale gate is
# NOT opened and nothing here needs it.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`):
# `abzarwp/` is SourceGuardian-encrypted. The fixture is Dokan-SHAPED data —
# two sellers carrying the `seller` role, their products, their pictures, and
# an empty `wp_dokan_orders` — read by the REAL `WpDokanReader`.
#
# ⛔ AND EVERY GUARANTEE IS GIVEN BACK: `alpha72-probe.php reset` rolls the
# import back through the documented `rollback()` and then deletes exactly the
# users, posts, attachments and table it created. Nothing of the shop's own
# media is touched, and no row of the shop's own is deleted.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha73-reproduction.sh docs/evidence/alpha73-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha73-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PRODUCTS="${TMC_PRODUCTS:-6}"
PX="${TMC_PX:-900}"
P72="$REPO/tests/Support/alpha72-probe.php"
P73="$REPO/tests/Support/alpha73-probe.php"
WORKER="$REPO/tests/Support/alpha73-worker.php"
HANDOVER="$REPO/tests/Support/concurrent-job-handover.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha73-XXXXXX)"

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
# Both probes run from the REPO, and the processes they launch are told where
# the site, the CLI and the repo are — a second process cannot inherit those.
probe72() {
  TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" TMC_REPO="$REPO" \
    wpx eval-file "$P72" "$@" 2>>"$RUN"
}
probe() {
  TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" TMC_REPO="$REPO" \
    wpx eval-file "$P73" "$@" 2>>"$RUN"
}
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
digest() { (cd "$1" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16); }

restore() {
  if [[ -d "$KEEP/src" && -d "$REPO/src" ]]; then
    rm -rf "$INSTALLED/src"; cp -a "$REPO/src" "$INSTALLED/src"
  fi
  probe72 reset >/dev/null 2>&1
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
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.73 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.73:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.73 commit could not be resolved"; exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.73 tree could not be checked out"; exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the installed package is this round's base (alpha.73)" "$BASE_HASH" "$ORIGINAL_HASH"
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
check "0e the parkable worker is in this checkout" "yes" \
  "$([[ -f "$WORKER" ]] && echo yes || echo no)"
check "0f the hand-over's own process is in this checkout" "yes" \
  "$([[ -f "$HANDOVER" ]] && echo yes || echo no)"

# The source data is Dokan-shaped and tree-independent, so it is arranged ONCE
# and every scenario below rearms the import over it.
AR="$(probe72 arrange "$PRODUCTS" "$PX")"; printf '%s\n' "$AR" >>"$RUN"
check "0g the real reader sees two Dokan shops" "2" "$(val "$AR" reader_vendors)"
check "0h …and their $PRODUCTS products" "$PRODUCTS" "$(val "$AR" reader_products)"
check "0i …each with a picture on the WooCommerce product" "$PRODUCTS" \
  "$(val "$AR" products_with_a_picture)"
UNTOUCHED_BEFORE="$(val "$(probe72 untouched)" wc_digest)"
note "0j the WooCommerce products as they stand" "$UNTOUCHED_BEFORE"

#   $1 the check prefix
rearm_to_the_products_page() {
  local re pl tp
  pl="$(probe72 plan)"; printf '%s\n' "$pl" >>"$RUN"
  check "$1-1 the dry run reads two shops" "2" "$(val "$pl" plan_vendors)"
  check "$1-2 …and $PRODUCTS products with zero conflicts" "$PRODUCTS/0" \
    "$(val "$pl" plan_products)/$(val "$pl" plan_conflicts)"
  re="$(probe rearm)"; printf '%s\n' "$re" >>"$RUN"
  check "$1-3 the import is back to queued with nothing imported" "0/0" \
    "$(val "$re" rearm_mapped)/$(val "$re" rearm_copies)"
  tp="$(probe to-products)"; printf '%s\n' "$tp" >>"$RUN"
  check "$1-4 the vendors phase is done and the next batch is a product one" "products" \
    "$(val "$tp" phase)"
  check "$1-5 …with no product imported yet" "0" "$(val "$tp" mapped)"
}

# =============================================================== one tree
#   $1 = BEFORE|AFTER   $2 = tree dir   $3 = expected src digest
measure() {
  local era="$1" tree="$2" want="$3" lower
  lower="$(printf '%s' "$era" | tr 'A-Z' 'a-z')"
  use_tree "$tree"
  say ""
  say "=== $era · the installed tree ==="
  check "${lower:0:1}0 the installed tree is the $era one" "$want" "$(digest "$INSTALLED")"

  say ""
  say "=== $era · §1 the attach window ==="
  rearm_to_the_products_page "${lower:0:1}1a"
  local G
  G="$(probe attach-gap)"; printf '%s\n' "$G" >>"$RUN"
  check "${lower:0:1}1b-1 worker A really parked at the attach" "yes" "$(val "$G" a_parked)"
  note  "${lower:0:1}1b-2 the unit A was on" "$(val "$G" a_is_on_unit)"
  note  "${lower:0:1}1b-3 did the hand-over get through while A was parked" \
    "$(val "$G" hand_over_got_through_while_a_was_parked)"
  note  "${lower:0:1}1b-4 the hand-over's answer" "$(val "$G" hand_over_answer)"
  note  "${lower:0:1}1b-5 A's own batch outcome" "$(val "$G" a_outcome)"
  note  "${lower:0:1}1b-6 how long A held the job row" "$(val "$G" a_write_phase_ms)"
  ATTACH_WROTE="$(val "$G" a_wrote_after_losing_the_job)"
  ATTACH_ORPHANS="$(val "$G" orphan_copies)"
  ATTACH_ORPHAN_IDS="$(val "$G" orphan_copy_ids)"
  ATTACH_COPIES="$(val "$G" copies_at_the_end)"
  ATTACH_MAPPED="$(val "$G" mapped_at_the_end)"
  ATTACH_DUPES="$(val "$G" duplicate_mappings)"
  ATTACH_GHOSTS="$(val "$G" manifest_names_a_row_that_is_gone)"
  ATTACH_NOIMAGE="$(val "$G" products_without_image)"
  local CM
  CM="$(probe completion)"; printf '%s\n' "$CM" >>"$RUN"
  ATTACH_COMPLETE="$(val "$CM" completion_complete)"
  ATTACH_COMPLETION_MAPPED="$(val "$CM" completion_mapped)"

  say ""
  say "=== $era · §2 the create window ==="
  rearm_to_the_products_page "${lower:0:1}2a"
  local C M
  C="$(probe create-gap)"; printf '%s\n' "$C" >>"$RUN"
  check "${lower:0:1}2b-1 worker A really parked at the insert" "yes" "$(val "$C" a_parked)"
  note  "${lower:0:1}2b-2 did the hand-over get through while A was parked" \
    "$(val "$C" hand_over_got_through_while_a_was_parked)"
  note  "${lower:0:1}2b-3 A's own batch outcome" "$(val "$C" a_outcome)"
  CREATE_WROTE="$(val "$C" a_wrote_after_losing_the_job)"
  CREATE_DUPES="$(val "$C" duplicate_mappings)"
  CREATE_ORPHANS="$(val "$C" orphan_copies)"
  CREATE_GHOSTS="$(val "$C" manifest_names_a_row_that_is_gone)"
  # The MECHANISM, named rather than inferred from a zero.
  M="$(probe create-gap-mechanism)"; printf '%s\n' "$M" >>"$RUN"
  check "${lower:0:1}2c-1 ⛔ wc_product_id carries a unique key" "1" \
    "$(val "$M" wc_product_id_has_a_unique_key)"
  note  "${lower:0:1}2c-2 …and it is called" "$(val "$M" wc_product_id_unique_key)"
  check "${lower:0:1}2c-3 so a second row for the same product is refused by the SCHEMA" "refused" \
    "$(val "$M" second_row_for_the_same_wc_product)"
  check "${lower:0:1}2c-4 …and the count for it is unchanged either way" \
    "$(val "$M" rows_for_it_before)" "$(val "$M" rows_for_it_after)"

  say ""
  say "=== $era · control: a resume whose lease went during the COPY ==="
  rearm_to_the_products_page "${lower:0:1}3a"
  local S
  S="$(probe stale-resume)"; printf '%s\n' "$S" >>"$RUN"
  check "${lower:0:1}3b-1 the hand-over goes through, because A holds nothing yet" "handed_over" \
    "$(val "$S" hand_over_answer)"
  note  "${lower:0:1}3b-2 A's own batch outcome" "$(val "$S" a_outcome)"
  check "${lower:0:1}3b-3 ⛔ A wrote nothing into the import it had lost" "false" \
    "$(val "$S" a_wrote_after_losing_the_job)"
  check "${lower:0:1}3b-4 …and left no orphan copy" "0" "$(val "$S" orphan_copies)"
  check "${lower:0:1}3b-5 …and no duplicate mapping" "0" "$(val "$S" duplicate_mappings)"

  say ""
  say "=== $era · control: nothing interrupted ==="
  rearm_to_the_products_page "${lower:0:1}4a"
  local K
  K="$(probe clean)"; printf '%s\n' "$K" >>"$RUN"
  check "${lower:0:1}4b-1 every product is mapped" "$PRODUCTS" "$(val "$K" clean_mapped)"
  check "${lower:0:1}4b-2 …every one with its picture" "0" "$(val "$K" clean_products_without_image)"
  check "${lower:0:1}4b-3 …no duplicate mapping" "0" "$(val "$K" clean_duplicate_mappings)"
  check "${lower:0:1}4b-4 …no orphan copy" "0" "$(val "$K" clean_orphan_copies)"
  check "${lower:0:1}4b-5 …one copy per product" "$PRODUCTS" "$(val "$K" clean_copies)"
  check "${lower:0:1}4b-6 …and the manifest knows about every row" "$PRODUCTS" \
    "$(val "$K" clean_manifest_products)"
  note  "${lower:0:1}4b-7 how long a healthy unit holds the job row" "$(val "$K" clean_write_phase_ms)"
  local CC
  CC="$(probe completion)"; printf '%s\n' "$CC" >>"$RUN"
  check "${lower:0:1}4c-1 the completion report agrees: all mapped" "$PRODUCTS" \
    "$(val "$CC" completion_mapped)"
  check "${lower:0:1}4c-2 …all complete" "$PRODUCTS" "$(val "$CC" completion_complete)"
  check "${lower:0:1}4c-3 …none without a picture" "0" "$(val "$CC" completion_without_image)"
  check "${lower:0:1}4c-4 …and the scan reached the end" "1" "$(val "$CC" completion_finished)"

  check "${lower:0:1}5 the WooCommerce products are byte-for-byte unchanged" \
    "$UNTOUCHED_BEFORE" "$(val "$(probe72 untouched)" wc_digest)"
}

# ======================================================= BEFORE (alpha.73)
measure BEFORE "$KEEP/base" "$BASE_HASH"
B_ATTACH_WROTE="$ATTACH_WROTE"; B_ATTACH_ORPHANS="$ATTACH_ORPHANS"
B_ATTACH_IDS="$ATTACH_ORPHAN_IDS"; B_ATTACH_COPIES="$ATTACH_COPIES"
B_ATTACH_MAPPED="$ATTACH_MAPPED"; B_ATTACH_DUPES="$ATTACH_DUPES"
B_ATTACH_GHOSTS="$ATTACH_GHOSTS"; B_ATTACH_NOIMAGE="$ATTACH_NOIMAGE"
B_ATTACH_COMPLETE="$ATTACH_COMPLETE"; B_ATTACH_CMAPPED="$ATTACH_COMPLETION_MAPPED"
B_CREATE_WROTE="$CREATE_WROTE"; B_CREATE_DUPES="$CREATE_DUPES"
B_CREATE_ORPHANS="$CREATE_ORPHANS"; B_CREATE_GHOSTS="$CREATE_GHOSTS"

say ""
say "=== BEFORE · the finding, in the numbers ==="
check "b6-1 ⛔ A WROTE INTO AN IMPORT IT NO LONGER OWNED" "true" "$B_ATTACH_WROTE"
check "b6-2 ⛔ …leaving a copy nothing points at" "1" "$B_ATTACH_ORPHANS"
note  "b6-3 the orphan's attachment id" "$B_ATTACH_IDS"
check "b6-4 ⛔ …so the library holds more copies than there are products" "yes" \
  "$([[ "$B_ATTACH_COPIES" -gt "$B_ATTACH_MAPPED" ]] 2>/dev/null && echo yes || echo no)"
note  "b6-5 copies / mapped" "$B_ATTACH_COPIES/$B_ATTACH_MAPPED"
note  "b6-6 and the create window on the same bytes" \
  "wrote=$B_CREATE_WROTE duplicate_mappings=$B_CREATE_DUPES orphans=$B_CREATE_ORPHANS"

# ========================================================= AFTER (this tree)
measure AFTER "$REPO" "$NOW_HASH"

say ""
say "=== AFTER · the finding, closed ==="
check "a6-1 A wrote nothing it did not own" "false" "$ATTACH_WROTE"
check "a6-2 …and left no orphan copy" "0" "$ATTACH_ORPHANS"
check "a6-3 …one copy per mapped product" "$ATTACH_MAPPED" "$ATTACH_COPIES"
check "a6-4 …no duplicate mapping" "0" "$ATTACH_DUPES"
check "a6-5 …and no manifest entry naming a row that is gone" "0" "$ATTACH_GHOSTS"
check "a6-6 the create window stays shut too" "false/0/0" \
  "$CREATE_WROTE/$CREATE_DUPES/$CREATE_ORPHANS"
check "a6-7 …with no manifest ghost there either" "0" "$CREATE_GHOSTS"

say ""
say "=== the two trees side by side ==="
say "attach_window_wrote_after_losing_the_job   before=$B_ATTACH_WROTE   after=$ATTACH_WROTE"
say "attach_window_orphan_copies                before=$B_ATTACH_ORPHANS   after=$ATTACH_ORPHANS"
say "attach_window_copies_vs_mapped             before=$B_ATTACH_COPIES/$B_ATTACH_MAPPED   after=$ATTACH_COPIES/$ATTACH_MAPPED"
say "attach_window_products_without_image       before=$B_ATTACH_NOIMAGE   after=$ATTACH_NOIMAGE"
say "create_window_duplicate_mappings           before=$B_CREATE_DUPES   after=$CREATE_DUPES"

say ""
say "pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
