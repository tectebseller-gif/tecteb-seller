#!/usr/bin/env bash
# The window between checking the stock and writing it — reproduced on the
# `alpha.49` bytes with a REAL second writer, then closed.
#
# WHAT `alpha.49` GOT WRONG, and this script is the correction. That round made
# the approval read its stock write back, and its delivery document said the
# read-back covered this window. It does not: `writeStock()` writes the
# approved number unconditionally, so a read-back that finds that very number
# proves the write landed and says nothing about what it replaced. Two units
# sold between the check and the write were overwritten in silence.
#
# THE SECOND WRITER IS REAL, and that is the whole value of this script. It is
# a separate `wp` process on a separate MySQL connection
# (`tests/Support/concurrent-stock-sale.php`), and it reduces the stock the way
# WooCommerce does — an order moving to `processing`, which is what
# `wc_maybe_reduce_stock_levels()` listens to, and `wc_update_product_stock(…,
# 'decrease')` underneath it. It is let in on `woocommerce_update_product`,
# WooCommerce's own hook at the end of `WC_Product_Data_Store_CPT::update()`,
# which `approveRevision()` reaches through `publish()` → `project()` →
# `save()`: after the stock has been checked and before it is written.
#
# DETERMINISTIC, not a race: the second process runs to completion
# synchronously inside that hook, so the window is entered on every run. There
# is no sleep anywhere in this script and nothing is retried to make it happen.
#
# The rules this script follows, each bought in an earlier round:
#   - the hash list is taken BEFORE anything is touched and the restore is
#     checked against it; the old bytes come from `git show`, because
#     `git checkout <commit> -- <path>` writes the index too (`alpha.39`);
#   - the restore runs from a `trap`, with absolute paths (`alpha.37`);
#   - every verb is its OWN `wp` invocation (`alpha.46`);
#   - each verb's output is checked for `probe=ran`, because a probe that did
#     not load reports nothing and «reproduced» would be a sentence about
#     nothing (`alpha.32`);
#   - a control that declines to measure is a FAILURE, not a pass (`alpha.46`).
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha50-reproduction.sh docs/evidence/alpha50-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha50-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_COMMIT="${BASE_COMMIT:-3e9d251}"
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

# The six files this round changes. Nothing else is moved.
FILES=(
  src/Modules/Product/Application/CatalogProjectorInterface.php
  src/Modules/Product/Application/ReviewProducts.php
  src/Modules/Product/Application/SyncCatalog.php
  src/Modules/Product/Infrastructure/WooCommerce/NullCatalogProjector.php
  src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php
  src/Modules/Product/Presentation/ProductMessages.php
)

HASHES="$OUT/fixed-bytes.sha256"
( cd "$REPO" && sha256sum "${FILES[@]}" ) >"$HASHES"
say "fixed bytes recorded: $(wc -l <"$HASHES") files"

KEEP="$(mktemp -d)"
for f in "${FILES[@]}"; do
  mkdir -p "$KEEP/$(dirname "$f")"
  cp "$REPO/$f" "$KEEP/$f"
done

install_src() { rsync -a --delete "$REPO/src/" "$INSTALLED/src/"; }

restore_fixed() {
  for f in "${FILES[@]}"; do
    cp "$KEEP/$f" "$REPO/$f"
  done
  install_src
}
trap 'restore_fixed; rm -rf "$KEEP"' EXIT

PROBE_FILE=""
probe() { # probe <verb> <tree>
  local verb="$1" tree="$2"
  PROBE_FILE="$OUT/$tree-$verb.txt"
  ( cd "$REPO" && TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" TMC_PHPBIN="$PHPBIN" TMC_REPO="$REPO" \
      "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
      --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file tests/Support/alpha49-probe.php "$verb" ) >"$PROBE_FILE" 2>"$PROBE_FILE.err"
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

run_tree() { # run_tree <label>
  local tree="$1" f verb
  say ""
  say "=================== $tree ==================="

  # ---- the finding, with both second writers -----------------------------
  for verb in sale-between-order sale-between-decrease; do
    probe "$verb" "$tree"; f="$PROBE_FILE"
    say "--- $tree $verb ---"; cat "$f" >>"$RUN"
    # The window is entered on EVERY run, and this is the check that says so:
    # a measurement taken without the second writer having run would be about
    # nothing at all.
    check "$tree/$verb the second writer ran, in its own process" "yes" "$(value "$f" second_writer_ran)"
    check "$tree/$verb and it really reduced the shop"              "9" "$(value "$f" second_writer_before)"
    check "$tree/$verb from nine to seven"                          "7" "$(value "$f" second_writer_after)"
    if [[ "$tree" == before ]]; then
      check "$tree/$verb the approval reports success" "revision_approved" "$(value "$f" approve_code)"
      check "$tree/$verb the approved number is written over theirs" "5" "$(value "$f" shop_after)"
      check "$tree/$verb so two sold units are gone"              "YES" "$(value "$f" overwritten_invalidly)"
      check "$tree/$verb their change did not survive"             "NO" "$(value "$f" second_writer_kept)"
      check "$tree/$verb and the proposal is closed"          "approved" "$(value "$f" revision_status)"
    else
      check "$tree/$verb the approval is refused" "stock_moved_during_write" "$(value "$f" approve_code)"
      check "$tree/$verb naming the number it was checked against" "9" "$(value "$f" expected_stock)"
      check "$tree/$verb their change survives"                    "7" "$(value "$f" shop_after)"
      check "$tree/$verb said so"                                "yes" "$(value "$f" second_writer_kept)"
      check "$tree/$verb nothing was overwritten"                 "no" "$(value "$f" overwritten_invalidly)"
      check "$tree/$verb the mirror shows the shop's number"       "7" "$(value "$f" mirror_after)"
      check "$tree/$verb the proposal stays open"            "pending" "$(value "$f" revision_status)"
    fi
  done

  # ---- the two spellings of one number -----------------------------------
  probe dialects "$tree"; f="$PROBE_FILE"
  say "--- $tree dialects ---"; cat "$f" >>"$RUN"
  if [[ "$tree" == before ]]; then
    check "$tree/dialects there is no guarded write to ask" "absent_in_this_build" "$(value "$f" guarded_write)"
  else
    check "$tree/dialects the row can say «7»"                   "7" "$(value "$f" dialect_object)"
    check "$tree/dialects the guard accepts it"            "written" "$(value "$f" guard_on_object_dialect)"
    check "$tree/dialects and it can say «7.000000»"      "7.000000" "$(value "$f" dialect_direct)"
    check "$tree/dialects the guard accepts that too"      "written" "$(value "$f" guard_on_direct_dialect)"
    check "$tree/dialects a stale expectation is refused"    "moved" "$(value "$f" guard_on_stale_expectation)"
    check "$tree/dialects and the row is untouched"     "4.000000" "$(value "$f" row_after_stale)"
  fi

  # ---- an unconfirmed write is still not recorded ------------------------
  probe readback-row-blinded "$tree"; f="$PROBE_FILE"
  say "--- $tree readback-row-blinded ---"; cat "$f" >>"$RUN"
  if [[ "$tree" == before ]]; then
    # On `alpha.49` the read-back reads the OBJECT, so blinding the row does
    # not reach it: the approval goes through. That is not a defect of that
    # round — it is the reason the read-back could not see a lost value either.
    check "$tree/4 blinding the row does not reach an object read" "revision_approved" "$(value "$f" approve_code)"
  else
    check "$tree/4 a write it cannot confirm is refused" "stock_moved_during_write" "$(value "$f" approve_code)"
    check "$tree/4 and the proposal stays open"                       "pending" "$(value "$f" revision_status)"
  fi

  # ---- the controls: identical on both trees -----------------------------
  probe control-approve "$tree"; f="$PROBE_FILE"
  say "--- $tree control-approve ---"; cat "$f" >>"$RUN"
  check "$tree/C1 an undisturbed approval succeeds" "revision_approved" "$(value "$f" approve_code)"
  check "$tree/C1 the shop takes the approved number"            "5" "$(value "$f" shop_after)"
  check "$tree/C1 the mirror agrees"                             "5" "$(value "$f" mirror_after)"
  check "$tree/C1 the proposal is closed"                 "approved" "$(value "$f" revision_status)"

  probe control-override "$tree"; f="$PROBE_FILE"
  say "--- $tree control-override ---"; cat "$f" >>"$RUN"
  check "$tree/C2 the page showed six"                           "6" "$(value "$f" shop_when_page_rendered)"
  check "$tree/C2 «apply it anyway» over it works" "revision_approved" "$(value "$f" approve_code)"
  check "$tree/C2 the shop takes the proposal"                   "5" "$(value "$f" shop_after)"
  check "$tree/C2 the proposal is closed"                 "approved" "$(value "$f" revision_status)"

  probe control-order-reduces "$tree"; f="$PROBE_FILE"
  say "--- $tree control-order-reduces ---"; cat "$f" >>"$RUN"
  check "$tree/C3 WooCommerce's own order still reduces stock" "yes" "$(value "$f" order_stock_reduced)"
  check "$tree/C3 from nine"                                     "9" "$(value "$f" shop_at_start)"
  check "$tree/C3 to seven"                                      "7" "$(value "$f" shop_after)"

  probe control-draft "$tree"; f="$PROBE_FILE"
  say "--- $tree control-draft ---"; cat "$f" >>"$RUN"
  check "$tree/C4 a draft's stock still applies at once" "inventory_saved" "$(value "$f" save_code)"
  check "$tree/C4 and the row takes it"                            "31" "$(value "$f" row_stock)"
}

# ===================== the alpha.49 bytes =================================
say "putting six files back to $BASE_COMMIT (the delivered alpha.49)"
for f in "${FILES[@]}"; do
  ( cd "$REPO" && git show "$BASE_COMMIT:$f" ) >"$REPO/$f" || {
    say "FAIL  could not fetch $f from $BASE_COMMIT"; FAIL=$((FAIL + 1));
  }
done
install_src
( cd "$REPO" && sha256sum "${FILES[@]}" ) >"$OUT/before-bytes.sha256"
DIFFERING=$(diff <(cut -d' ' -f1 "$HASHES") <(cut -d' ' -f1 "$OUT/before-bytes.sha256") | grep -c '^<' || true)
check "the six files really are other bytes now" "6" "$DIFFERING"

run_tree before

# ===================== this checkout's bytes ==============================
say ""
say "putting the fix back"
restore_fixed
( cd "$REPO" && sha256sum --quiet -c "$HASHES" ) \
  && check "the fixed bytes came back, byte for byte" "ok" "ok" \
  || check "the fixed bytes came back, byte for byte" "ok" "NO"

run_tree after

say ""
say "PASS=$PASS FAIL=$FAIL"
printf 'PASS=%s FAIL=%s\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
