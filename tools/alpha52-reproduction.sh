#!/usr/bin/env bash
# Two decisions about one product — reproduced on the `alpha.51` bytes, then fixed.
#
# WHAT THE OWNER NAMED, and it is three statements about one shape:
#
#   * `proposeStock()` keeps ALL the details, the brand among them, in the
#     proposal — deliberately, so a stock edit does not throw away a request
#     the vendor is still waiting on (`alpha.48`);
#   * `ManageBrands::finish()` applies an approved brand on its own, from its
#     own table, writing the row, the term id and the shop's attribute;
#   * `ReviewProducts::detailsFromPayload()` then reads `brand` out of that
#     payload.
#
# So the payload's brand is a SNAPSHOT, and approving the stock replays it over
# a brand decision a manager made afterwards. «تصمیم دربارهٔ یک فیلد نباید
# تصمیم جدیدتر دربارهٔ فیلد دیگر را برگرداند؛ اگر تعارض واقعی است، صریح نمایش
# بده.»
#
# WHAT IS MEASURED, and in how many places. Three, because the owner named
# three: the marketplace row, WooCommerce's own Brand attribute, and the brand
# TERM ID — the identity, which is what every later comparison asks and the one
# a screenshot would not show. Both orders are measured, and a description
# proposal open at the same time, and the controls that must answer identically
# on both trees.
#
# HOW THE TWO TREES ARE PUT IN PLACE. The INSTALLED plugin's `src/` is swapped,
# never the repository's: the old bytes come from `git archive <base> src` into
# a temporary directory and are rsynced in, and the «after» half rsyncs this
# checkout back from a `trap`. The repository is not written to, so there is
# nothing there for a failed restore to leave mixed — the hazard `alpha.39`
# paid for with `git checkout <commit> -- <path>`.
#
# THE PROBE IS CONSTANT AND COMES FROM THE REPOSITORY, and it loads on both
# trees (`alpha.32`): `approveRevision()` is called through reflection so the
# two arguments this round adds are sent only to a build that has them, and the
# payload's new baseline map is read with `??`.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha52-reproduction.sh docs/evidence/alpha52-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha52-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_COMMIT="${BASE_COMMIT:-5985957}"
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
      eval-file tests/Support/alpha51-probe.php "$verb" ) >"$PROBE_FILE" 2>"$PROBE_FILE.err"
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

BRAND_A='آریا_طب_۵۲'
BRAND_B='سینا_طب_۵۲'
BRAND_C='مدلاین_۵۲'

run_tree() { # run_tree <label>
  local tree="$1" f
  say ""
  say "=================== $tree ==================="

  # --- §1: the owner's own order — approve the brand, then the stock -----
  probe brand-then-stock "$tree"; f="$PROBE_FILE"; dump "$tree brand-then-stock" "$f"
  check "$tree/1 the brand request is recorded"  "brand_requested" "$(value "$f" brand_request_code)"
  check "$tree/1 the stock is a proposal" "stock_revision_requested" "$(value "$f" stock_proposal_code)"
  check "$tree/1 the brand is approved"      "brand_approved" "$(value "$f" brand_approve_code)"
  check "$tree/1 and the shop holds it"             "$BRAND_B" "$(value "$f" after_brand_shop_brand)"
  check "$tree/1 the payload still holds the old brand" "$BRAND_A" "$(value "$f" payload_brand)"
  if [[ "$tree" == before ]]; then
    # THE DEFECT, in all three places at once, with the stock approval
    # reporting success about it.
    check "$tree/1 no baseline is recorded"  "__not_recorded__" "$(value "$f" payload_brand_baseline)"
    check "$tree/1 the stock approval succeeds" "revision_approved" "$(value "$f" stock_approve_code)"
    check "$tree/1 THE ROW's brand goes back"        "$BRAND_A" "$(value "$f" after_stock_row_brand)"
    check "$tree/1 THE TERM ID goes back"                  "no" "$(value "$f" term_survived)"
    check "$tree/1 THE SHOP's attribute goes back"   "$BRAND_A" "$(value "$f" after_stock_shop_brand)"
    check "$tree/1 and nothing says so"          "__absent__" "$(value "$f" brand_kept)"
  else
    check "$tree/1 the baseline is recorded"          "$BRAND_A" "$(value "$f" payload_brand_baseline)"
    check "$tree/1 the stock approval succeeds" "revision_approved" "$(value "$f" stock_approve_code)"
    check "$tree/1 THE ROW keeps the brand"           "$BRAND_B" "$(value "$f" after_stock_row_brand)"
    check "$tree/1 THE TERM ID keeps it"                   "yes" "$(value "$f" term_survived)"
    check "$tree/1 THE SHOP keeps it"                 "$BRAND_B" "$(value "$f" after_stock_shop_brand)"
    check "$tree/1 and the result says so"            "$BRAND_B" "$(value "$f" brand_kept)"
  fi
  # THE SAME ON BOTH TREES: the stock the vendor asked for is applied. A fix
  # that protected the brand by refusing the proposal would pass every check
  # above and be useless.
  check "$tree/1 the stock still moves" "yes" "$(value "$f" stock_applied)"

  # --- the reverse order, which worked and must keep working -------------
  probe stock-then-brand "$tree"; f="$PROBE_FILE"; dump "$tree stock-then-brand" "$f"
  check "$tree/2 the stock is approved first" "revision_approved" "$(value "$f" stock_approve_code)"
  check "$tree/2 the request is still open"               "yes" "$(value "$f" request_still_open)"
  check "$tree/2 then the brand"                "brand_approved" "$(value "$f" brand_approve_code)"
  check "$tree/2 the row holds the brand"                 "yes" "$(value "$f" brand_is_b)"
  check "$tree/2 the shop holds it"                       "yes" "$(value "$f" shop_is_b)"
  check "$tree/2 and the stock stayed applied"            "yes" "$(value "$f" stock_applied)"

  # --- a description proposal open at the same time ----------------------
  probe description-and-brand "$tree"; f="$PROBE_FILE"; dump "$tree description-and-brand" "$f"
  check "$tree/3 the description is a proposal" "revision_requested" "$(value "$f" description_proposal_code)"
  check "$tree/3 approving the brand leaves it proposed" "yes" "$(value "$f" description_still_proposed)"
  check "$tree/3 the description is applied"             "yes" "$(value "$f" description_applied)"
  if [[ "$tree" == before ]]; then
    check "$tree/3 and it takes the brand with it"        "no" "$(value "$f" brand_survived)"
    check "$tree/3 in the shop too"                       "no" "$(value "$f" shop_survived)"
  else
    check "$tree/3 and the brand survives"               "yes" "$(value "$f" brand_survived)"
    check "$tree/3 in the shop too"                      "yes" "$(value "$f" shop_survived)"
  fi

  # --- a REAL conflict: two vendor requests about one field --------------
  probe real-conflict "$tree"; f="$PROBE_FILE"; dump "$tree real-conflict" "$f"
  check "$tree/4 the revision asks for a third brand" "$BRAND_C" "$(value "$f" payload_brand)"
  check "$tree/4 and a request for another is approved" "brand_approved" "$(value "$f" brand_approve_code)"
  if [[ "$tree" == before ]]; then
    check "$tree/4 whichever ran last wins, silently" "revision_approved" "$(value "$f" revision_approve_code)"
    check "$tree/4 nothing is named"              "__absent__" "$(value "$f" named_current)"
    check "$tree/4 and the proposal is closed"            "no" "$(value "$f" still_pending)"
  else
    check "$tree/4 it is REFUSED by name"              "brand_moved" "$(value "$f" revision_approve_code)"
    check "$tree/4 the baseline is named"                 "$BRAND_A" "$(value "$f" named_baseline)"
    check "$tree/4 what is there now is named"            "$BRAND_B" "$(value "$f" named_current)"
    check "$tree/4 what is asked for is named"            "$BRAND_C" "$(value "$f" named_proposed)"
    check "$tree/4 nothing is written"                    "$BRAND_B" "$(value "$f" after_refusal_row_brand)"
    check "$tree/4 and the proposal stays open"                "yes" "$(value "$f" still_pending)"
    # AND THE OVERRIDE IS FOR THE BRAND THAT WAS SHOWN (`alpha.49`).
    check "$tree/4 a blind override is refused too" "brand_moved_again" "$(value "$f" blind_override_code)"
    check "$tree/4 still writing nothing"                  "$BRAND_B" "$(value "$f" after_blind_row_brand)"
    check "$tree/4 the honest override applies it"  "revision_approved" "$(value "$f" override_code)"
    check "$tree/4 to the brand the proposal asked for"         "yes" "$(value "$f" override_applied_proposal)"
  fi

  # --- §3: the typed name after a failed request -------------------------
  probe request-failure-keeps-typing "$tree"; f="$PROBE_FILE"; dump "$tree request-failure-keeps-typing" "$f"
  check "$tree/5 the product saves" "yes" "$(value "$f" product_saved)"
  if [[ "$tree" == before ]]; then
    check "$tree/5 and the typing is gone" "__absent__" "$(value "$f" typed_kept)"
  else
    check "$tree/5 and the typing is kept"  "$BRAND_B" "$(value "$f" typed_kept)"
    check "$tree/5 so the box is filled again"  "yes" "$(value "$f" typing_survived)"
  fi

  # --- the form's own hidden field, read out of the rendered page --------
  probe carry-over-brand "$tree"; f="$PROBE_FILE"; dump "$tree carry-over-brand" "$f"
  check "$tree/6 the form renders"                        "yes" "$(value "$f" rendered)"
  check "$tree/6 the row holds the approved brand"   "$BRAND_B" "$(value "$f" row_brand_now)"
  check "$tree/6 the payload still holds the old one" "$BRAND_A" "$(value "$f" payload_brand)"
  check "$tree/6 and the hidden field is there"           "yes" "$(value "$f" hidden_present)"
  if [[ "$tree" == before ]]; then
    # THE FORM DISAGREES WITH ITSELF: the box shows the row, the hidden field
    # carries the payload, and a step-2 save of the PRICE posts the one the
    # vendor cannot see — re-asking for a brand a manager had replaced.
    check "$tree/6 it carries the payload's brand"    "$BRAND_A" "$(value "$f" hidden_carries)"
    check "$tree/6 not the row's"                           "no" "$(value "$f" form_agrees_with_row)"
    check "$tree/6 so a step-2 save re-asks for it"   "$BRAND_A" "$(value "$f" revision_payload_brand)"
  else
    check "$tree/6 it carries the brand the box shows" "$BRAND_B" "$(value "$f" hidden_carries)"
    check "$tree/6 which is the row's"                     "yes" "$(value "$f" form_agrees_with_row)"
    check "$tree/6 and not the payload's"                   "no" "$(value "$f" form_carries_payload_brand)"
    check "$tree/6 so a step-2 save asks for nothing new" "$BRAND_B" "$(value "$f" revision_payload_brand)"
    check "$tree/6 the approval goes through"  "revision_approved" "$(value "$f" approve_code)"
    check "$tree/6 and the brand survives it"              "yes" "$(value "$f" brand_survived)"
    check "$tree/6 in the shop too"                        "yes" "$(value "$f" shop_survived)"
  fi

  # ---- CONTROLS: one answer, both trees --------------------------------
  probe control-vendor-brand-change "$tree"; f="$PROBE_FILE"; dump "$tree control-vendor-brand-change" "$f"
  check "$tree/C1 a real brand request is applied"  "yes" "$(value "$f" brand_is_c)"
  check "$tree/C1 with its term id"                 "yes" "$(value "$f" term_is_c)"
  check "$tree/C1 and in the shop"                  "yes" "$(value "$f" shop_is_c)"

  probe control-stock-only "$tree"; f="$PROBE_FILE"; dump "$tree control-stock-only" "$f"
  check "$tree/C2 a stock-only proposal applies"    "yes" "$(value "$f" stock_applied)"
  check "$tree/C2 and the brand is untouched"       "yes" "$(value "$f" brand_is_a)"

  probe control-reject-keeps-revision "$tree"; f="$PROBE_FILE"; dump "$tree control-reject-keeps-revision" "$f"
  check "$tree/C3 rejecting the brand keeps the revision" "yes" "$(value "$f" after_reject_open_revision)"
  check "$tree/C3 the product's brand does not move"      "yes" "$(value "$f" brand_is_a)"
  check "$tree/C3 and the stock still applies"            "yes" "$(value "$f" stock_applied)"
}

# ============================= before ====================================
say "### installing the $BASE_COMMIT bytes into $INSTALLED"
install_tree "$OLD_TREE/src"
run_tree before

# ============================= after =====================================
say ""
say "### installing this checkout"
install_tree "$REPO/src"
run_tree after

# --- and the installed tree really is this checkout again ---------------
if diff -rq "$REPO/src" "$INSTALLED/src" >/dev/null 2>&1; then
  PASS=$((PASS + 1)); say "PASS  the installed src/ is this checkout again"
else
  FAIL=$((FAIL + 1)); say "FAIL  the installed src/ does not match this checkout"
fi

say ""
say "PASS=$PASS FAIL=$FAIL"
printf 'PASS=%s FAIL=%s\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
