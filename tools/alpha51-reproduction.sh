#!/usr/bin/env bash
# The brand as ONE field — reproduced on the `alpha.50` bytes, then built.
#
# WHAT THE OWNER REJECTED. `alpha.48` made the brand a picker and `alpha.50`
# shipped it: a radio list of approved names, a search box with its own submit
# button, a separate «my brand is missing» text box with its own submit button,
# and three paragraphs of prose. Typing a name that was not in the list was
# REFUSED, so the only way to ask for one was the second button. The owner read
# that screen and asked for one field, one save, and the manager able to attach,
# correct or reject in the product's own review path.
#
# WHAT IS MEASURED, and where. At the POST, through `ProductArea::handle()`,
# because the dialect the form speaks is exactly what changed — a service-level
# measurement would see neither the removed buttons nor the save that now
# carries a request. Each verb builds `$_POST` as the rendered form sends it.
#
# HOW THE TWO TREES ARE PUT IN PLACE. The INSTALLED plugin's `src/` is swapped,
# not the repository's: the old bytes come from `git archive <base> src` into a
# temporary directory and are rsynced into the install, and the «after» half
# rsyncs this checkout back. The repository is never written to, so there is
# nothing to restore there and no way for a failed restore to leave a mixed
# tree behind — the hazard `alpha.39` paid for with
# `git checkout <commit> -- <path>`.
#
# THE PROBE IS CONSTANT AND COMES FROM THE REPOSITORY. It names nothing that
# only this round has: `ManageBrands::approve()` is called with two arguments
# because the second has a default on `alpha.50` too, and both POST dialects
# (`brand` and `brand_term`) are sent on every save, so the old build reads
# «leave the brand alone» rather than «the field is missing» (`alpha.32`: a
# probe that cannot load reports nothing, and «reproduced» would then be a
# sentence about nothing).
#
# THE STRUCTURE IS LEFT AT 26 FOR BOTH HALVES, deliberately and stated:
# `MigrationRunner` never touches a structure that is AHEAD of its target
# (measured in `alpha.46`), so `alpha.50`'s code reads the two columns it knows
# and ignores the third. The badge verb therefore measures the CODE and not the
# column's absence — which is the honest comparison, because the column alone
# changes nothing.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha51-reproduction.sh docs/evidence/alpha51-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha51-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_COMMIT="${BASE_COMMIT:-dd30281}"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"

mkdir -p "$OUT"
RUN="$OUT/run.txt"
: >"$RUN"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$RUN" >/dev/null; }
check() { # check <label> <expected> <actual>
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}

[[ -d "$INSTALLED" ]] || { say "FAIL  the plugin is not installed at $INSTALLED"; echo "PASS=0 FAIL=1" | tee -a "$RUN"; exit 1; }

# ---- the two trees, both as directories on disk -------------------------
OLD_TREE="$(mktemp -d)"
( cd "$REPO" && git archive "$BASE_COMMIT" src | tar -x -C "$OLD_TREE" ) || {
  say "FAIL  could not read src/ at $BASE_COMMIT"; echo "PASS=0 FAIL=1" | tee -a "$RUN"; exit 1
}
say "old bytes read from $BASE_COMMIT: $(find "$OLD_TREE/src" -name '*.php' | wc -l) php files"
say "this checkout:                    $(find "$REPO/src" -name '*.php' | wc -l) php files"

install_tree() { rsync -a --delete "$1/" "$INSTALLED/src/"; }
trap 'install_tree "$REPO/src"; rm -rf "$OLD_TREE"' EXIT

PROBE_FILE=""
probe() { # probe <verb> <tree>
  local verb="$1" tree="$2"
  PROBE_FILE="$OUT/$tree-$verb.txt"
  ( cd "$REPO" && "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
      --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file tests/Support/alpha50-probe.php "$verb" ) >"$PROBE_FILE" 2>"$PROBE_FILE.err"
  if grep -qx 'probe=ran' "$PROBE_FILE"; then
    PASS=$((PASS + 1)); say "PASS  $tree/$verb  the probe ran"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $tree/$verb  the probe did not run"
  fi
}

value() { # value <file> <key>
  local v
  v="$(grep -m1 "^$2=" "$1" 2>/dev/null | cut -d= -f2-)"
  printf '%s' "${v:-__missing__}"
}

dump() { say "--- $1 ---"; cat "$2" >>"$RUN"; }

run_tree() { # run_tree <label>
  local tree="$1" f
  say ""
  say "=================== $tree ==================="

  # --- §2: one field, an unapproved name, the ordinary save --------------
  probe typed-unapproved "$tree"; f="$PROBE_FILE"; dump "$tree typed-unapproved" "$f"
  if [[ "$tree" == before ]]; then
    # THE TYPED NAME IS NOT READ AT ALL. `alpha.50`'s picker ignores `brand`
    # and reads `brand_term`, so the save is an ordinary one that changed
    # nothing sensitive — `inventory_saved` on a published product — and the
    # vendor's typing reaches nobody. Measured rather than assumed: the first
    # version of this script expected `brand_not_approved`, which is what the
    # SERVICE answers to free text and not what the FORM does with it.
    check "$tree/1 the typed name reaches nobody" "inventory_saved" "$(value "$f" save_code)"
    check "$tree/1 so nothing is recorded"             "no" "$(value "$f" request_recorded)"
    # A delta, not an absolute: superseded requests are kept on purpose, so
    # the table legitimately holds rows from earlier verbs.
    check "$tree/1 and no row is written" "$(value "$f" rows_before)" "$(value "$f" rows_after)"
    check "$tree/1 the product's brand does not move" "__empty__" "$(value "$f" brand_after)"
  else
    check "$tree/1 the product saves"        "brand_requested" "$(value "$f" save_code)"
    check "$tree/1 the name is recorded for the manager" "yes" "$(value "$f" request_recorded)"
    check "$tree/1 under the name that was typed" "سینا_طب_۵۱" "$(value "$f" request_name)"
    check "$tree/1 the product's brand does not move" "__empty__" "$(value "$f" brand_after)"
    check "$tree/1 and «ذخیره و ادامه» continues"     "step_2" "$(value "$f" lands_on)"
  fi

  # --- a double click asks once ------------------------------------------
  probe typed-unapproved-twice "$tree"; f="$PROBE_FILE"; dump "$tree typed-unapproved-twice" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/2 the typed name still reaches nobody" "inventory_saved" "$(value "$f" save_code)"
  else
    check "$tree/2 a re-save is not an error" "brand_request_unchanged" "$(value "$f" save_code)"
    check "$tree/2 it is the same request"                      "yes" "$(value "$f" same_request)"
    check "$tree/2 and no second row was written"               "yes" "$(value "$f" rows_unchanged)"
  fi

  # --- retyping replaces, atomically ------------------------------------
  probe typed-retyped "$tree"; f="$PROBE_FILE"; dump "$tree typed-retyped" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/3 nothing to replace: nothing was ever asked" "inventory_saved" "$(value "$f" save_code)"
  else
    check "$tree/3 the request is replaced" "brand_request_replaced" "$(value "$f" save_code)"
    check "$tree/3 under the new name"      "آمبو_بگ_۵۱" "$(value "$f" request_name)"
    check "$tree/3 as a new request"               "yes" "$(value "$f" is_a_new_request)"
    check "$tree/3 the earlier one is superseded, not deleted" "superseded" "$(value "$f" earlier_status)"
    check "$tree/3 and the manager has exactly one to decide"  "1" "$(value "$f" exactly_one_open)"
  fi

  # --- the old dialect: moved, not deleted -------------------------------
  probe old-dialect "$tree"; f="$PROBE_FILE"; dump "$tree old-dialect" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/1b the second button was the only way" "yes" "$(value "$f" request_recorded)"
    check "$tree/1b and it worked"              "سینا_طب_۵۱" "$(value "$f" request_name)"
  else
    check "$tree/1b the second field and button are gone" "no" "$(value "$f" request_recorded)"
    check "$tree/1b and the save is an ordinary one" "inventory_saved" "$(value "$f" save_code)"
  fi

  # --- typing an approved name -------------------------------------------
  probe typed-approved "$tree"; f="$PROBE_FILE"; dump "$tree typed-approved" "$f"
  check "$tree/4 the brand really is in WooCommerce's list" "yes" \
    "$([[ "$(value "$f" term_in_list)" == "__none__" ]] && echo no || echo yes)"
  if [[ "$tree" == before ]]; then
    # The picker ignores `brand`, so the old build reads `brand_term=keep`:
    # «leave it alone». Stated rather than hidden — it is why the owner could
    # not type a brand at all.
    check "$tree/4 the typed name is not read" "inventory_saved" "$(value "$f" save_code)"
    check "$tree/4 so the brand stays empty" "__empty__" "$(value "$f" row_brand_now)"
  else
    check "$tree/4 it becomes a proposal on a live product" "revision_requested" "$(value "$f" save_code)"
    check "$tree/4 under THE LIST's spelling" "آریا_طب_۵۱" "$(value "$f" revision_brand)"
    check "$tree/4 not as a request"                  "no" "$(value "$f" request_recorded)"
    check "$tree/4 and the live brand waits for approval" "__empty__" "$(value "$f" row_brand_now)"
  fi

  # --- §2(الف): attach to an existing brand ------------------------------
  probe manager-attach "$tree"; f="$PROBE_FILE"; dump "$tree manager-attach" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/5 there is nothing to attach" "__none__" "$(value "$f" asked)"
  else
    check "$tree/5 the manager attaches it"  "brand_approved" "$(value "$f" approve_code)"
    check "$tree/5 to the brand that exists"          "yes" "$(value "$f" attached_to_existing)"
    check "$tree/5 the list did not grow"               "0" "$(value "$f" list_grew)"
    check "$tree/5 under the list's name"      "آریا_طب_۵۱" "$(value "$f" row_brand)"
    check "$tree/5 and WooCommerce has it"     "آریا_طب_۵۱" "$(value "$f" shop_attribute_brand)"
    check "$tree/5 nothing is left open"              "yes" "$(value "$f" nothing_left_open)"
  fi

  # --- §2(ب): correct the name and approve it ----------------------------
  probe manager-correct "$tree"; f="$PROBE_FILE"; dump "$tree manager-correct" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/6 there is nothing to correct" "__none__" "$(value "$f" asked)"
  else
    check "$tree/6 the corrected name is approved" "brand_approved" "$(value "$f" approve_code)"
    check "$tree/6 the manager's spelling is in the list" "yes" "$(value "$f" managers_spelling_in_list)"
    check "$tree/6 the vendor's is NOT"                    "no" "$(value "$f" vendors_spelling_in_list)"
    check "$tree/6 the product carries the manager's" "مدلاین_۵۱" "$(value "$f" row_brand)"
    check "$tree/6 and so does WooCommerce"           "مدلاین_۵۱" "$(value "$f" shop_attribute_brand)"
  fi

  # --- §2(ج): rejecting changes nothing ---------------------------------
  probe manager-reject "$tree"; f="$PROBE_FILE"; dump "$tree manager-reject" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/7 there is nothing to reject" "__not_run__" "$(value "$f" reject_code)"
  else
    check "$tree/7 the request is rejected" "brand_rejected" "$(value "$f" reject_code)"
    check "$tree/7 the product's brand is untouched" "yes" "$(value "$f" brand_untouched)"
    check "$tree/7 and the name did not reach the list" "yes" "$(value "$f" not_in_list)"
    check "$tree/7 nothing is left open"             "yes" "$(value "$f" nothing_left_open)"
  fi

  # --- the notification -------------------------------------------------
  # A DELTA, not an absolute: the queue holds whatever other fixtures left in
  # it, so «0» would be an expectation about the rest of the database.
  probe badge "$tree"; f="$PROBE_FILE"; dump "$tree badge" "$f"
  if [[ "$tree" == before ]]; then
    # `alpha.50` has no brand request on this product to put in the queue —
    # the typed name reached nobody — so there is nothing for the badge to
    # miss here. What the queue DOES hold is measured by the old dialect's
    # own verb; this one records that the count did not move.
    check "$tree/8 nothing was asked, so nothing is waiting" "no" "$(value "$f" in_queue)"
    check "$tree/8 and the count does not move"               "0" "$(value "$f" unseen_delta)"
  else
    check "$tree/8 the request is in the review queue" "yes" "$(value "$f" in_queue)"
    check "$tree/8 the badge sees it"                  "yes" "$(value "$f" badge_saw_it)"
    check "$tree/8 by exactly one"                       "1" "$(value "$f" unseen_delta)"
  fi

  # --- controls: the same answer on BOTH trees, or this script is wrong --
  probe control-unchanged "$tree"; f="$PROBE_FILE"; dump "$tree control-unchanged" "$f"
  check "$tree/C1 saving an unchanged brand succeeds" "inventory_saved" "$(value "$f" save_code)"
  check "$tree/C1 the brand is still there"     "آریا_طب_۵۱" "$(value "$f" brand_after)"
  check "$tree/C1 nothing is asked for"                "yes" "$(value "$f" no_request)"
  check "$tree/C1 and no proposal is made"             "yes" "$(value "$f" no_revision)"

  probe control-cleared "$tree"; f="$PROBE_FILE"; dump "$tree control-cleared" "$f"
  check "$tree/C2 an empty box clears the brand" "__empty__" "$(value "$f" brand_after)"

  probe control-not-ours "$tree"; f="$PROBE_FILE"; dump "$tree control-not-ours" "$f"
  check "$tree/C3 another shop may not ask for this product" "forbidden" "$(value "$f" propose_code)"
  check "$tree/C3 and nothing is recorded"                        "yes" "$(value "$f" no_request)"
}

# ============================== before =====================================
install_tree "$OLD_TREE/src"
say "installed: the $BASE_COMMIT bytes"
run_tree before

# ============================== after ======================================
install_tree "$REPO/src"
say ""
say "installed: this checkout"
# `diff -rq` rather than a hash list: the repository was never written to, so
# what has to be proved is that the INSTALL is this checkout again.
if diff -rq "$REPO/src" "$INSTALLED/src" >/dev/null 2>&1; then
  PASS=$((PASS + 1)); say "PASS  the install is this checkout again, byte for byte"
else
  FAIL=$((FAIL + 1)); say "FAIL  the install does not match this checkout"
fi
run_tree after

say ""
say "PASS=$PASS FAIL=$FAIL"
printf 'PASS=%d FAIL=%d\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
