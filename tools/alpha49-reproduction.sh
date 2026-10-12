#!/usr/bin/env bash
# Three findings the owner read in `alpha.48`, measured on the `alpha.48` bytes
# and then on the fix — with controls that must answer the same on both trees.
#
# WHAT THIS SCRIPT IS. It is the evidence for «ابتدا روی بایت‌های alpha.48
# بازتولید کن». Ten source files are put back to the bytes of the delivered
# `alpha.48` package (commit 19d735f) and the probe is run against them; then
# they are put back to this checkout's bytes and the SAME probe is run again.
# Nothing is asserted about the fix that was not first asserted about the
# defect.
#
# THE RULES THIS SCRIPT FOLLOWS, each of them bought in an earlier round:
#   - the hash list is taken BEFORE anything is touched, and the restore is
#     checked against it — `git diff` is not evidence that a file came back
#     (`alpha.29`), and `git checkout <commit> -- <path>` writes the index as
#     well (`alpha.39`), so the old bytes are fetched with `git show`.
#   - the restore runs from a `trap`, with absolute paths (`alpha.37`).
#   - every verb is its OWN `wp` invocation, because the subject is what a
#     request sees (`alpha.46`).
#   - each verb's output is checked for `probe=ran`; a probe that did not load
#     reports nothing and «reproduced» would be a sentence about nothing
#     (`alpha.32`).
#   - a control that declines to measure is a FAILURE, not a pass (`alpha.46`).
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha49-reproduction.sh docs/evidence/alpha49-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha49-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_COMMIT="${BASE_COMMIT:-19d735f}"
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

# The ten files this round changes. Nothing else is moved.
FILES=(
  src/Modules/Product/Application/ManageProducts.php
  src/Modules/Product/Application/ProductRepositoryInterface.php
  src/Modules/Product/Application/ProductRevisionRepositoryInterface.php
  src/Modules/Product/Application/ReviewProducts.php
  src/Modules/Product/Application/SyncCatalog.php
  src/Modules/Product/Infrastructure/DbProductRepository.php
  src/Modules/Product/Infrastructure/DbProductRevisionRepository.php
  src/Modules/Product/Infrastructure/ProductTableShape.php
  src/Modules/Product/Presentation/Admin/ProductReviewPage.php
  src/Modules/Product/Presentation/ProductMessages.php
)

# --- the hash list, taken before anything is touched ----------------------
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

# Sets PROBE_FILE rather than echoing it. A command substitution runs in a
# subshell, so a `probe` that printed its path could not also count a failure —
# the «did not run» check would increment a counter in a process that is about
# to exit, and a probe that never loaded would pass silently (`alpha.32`).
PROBE_FILE=""
probe() { # probe <verb> <tree>
  local verb="$1" tree="$2"
  PROBE_FILE="$OUT/$tree-$verb.txt"
  ( cd "$REPO" && "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
      --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file tests/Support/alpha48-probe.php "$verb" ) >"$PROBE_FILE" 2>"$PROBE_FILE.err"
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
  local tree="$1" f
  say ""
  say "=================== $tree ==================="

  # ---- finding 1: the proposal closed before its replacement exists ------
  probe proposal-lost "$tree"; f="$PROBE_FILE"
  say "--- $tree proposal-lost ---"; cat "$f" >>"$RUN"
  if [[ "$tree" == before ]]; then
    check "$tree/1 the refused insert leaves no pending proposal" "0" "$(value "$f" pending_after)"
    check "$tree/1 the earlier request was closed"      "superseded" "$(value "$f" earlier_status)"
    check "$tree/1 the product left the review queue"           "no" "$(value "$f" in_needs_review_after)"
  else
    check "$tree/1 the pending proposal is still pending"      "yes" "$(value "$f" proposal_kept)"
    check "$tree/1 its status is untouched"                "pending" "$(value "$f" earlier_status)"
    check "$tree/1 the vendor's title is still asked"          "yes" "$(value "$f" earlier_title_still_asked)"
    check "$tree/1 the product is still in the review queue"   "yes" "$(value "$f" in_needs_review_after)"
  fi
  check "$tree/1 the save does not report success" "failed" "$(value "$f" save)"

  # ---- finding 1b: no transaction, so nothing is attempted ---------------
  probe proposal-not-atomic "$tree"; f="$PROBE_FILE"
  say "--- $tree proposal-not-atomic ---"; cat "$f" >>"$RUN"
  if [[ "$tree" == before ]]; then
    check "$tree/1b the question is not asked at all" "stock_revision_requested" "$(value "$f" save_code)"
    check "$tree/1b so the earlier proposal is gone"                       "NO" "$(value "$f" proposal_kept)"
  else
    check "$tree/1b the refusal is named"      "revision_not_atomic" "$(value "$f" save_code)"
    check "$tree/1b and nothing was attempted"                "yes" "$(value "$f" proposal_kept)"
  fi

  # ---- finding 2a: the structure could not be read -----------------------
  probe structure-unreadable "$tree"; f="$PROBE_FILE"
  say "--- $tree structure-unreadable ---"; cat "$f" >>"$RUN"
  check "$tree/2a the failed read is known to the class" "product_table_unreadable" "$(value "$f" problem)"
  if [[ "$tree" == before ]]; then
    check "$tree/2a unknown reads as sound"  "yes" "$(value "$f" reads_as_sound)"
    check "$tree/2a and the write lands"       "1" "$(value "$f" wrote)"
  else
    check "$tree/2a unknown is not sound"     "no" "$(value "$f" reads_as_sound)"
    check "$tree/2a the write is refused"      "0" "$(value "$f" wrote)"
    check "$tree/2a under its own name" "schema_unknown" "$(value "$f" save_code)"
  fi

  # ---- finding 2b: a column of the wrong type ----------------------------
  probe wrong-type "$tree"; f="$PROBE_FILE"
  say "--- $tree wrong-type ---"; cat "$f" >>"$RUN"
  check "$tree/2b the column really is narrowed" "tinyint(4)" "$(value "$f" column_now)"
  check "$tree/2b and it is put back"              "int(11)" "$(value "$f" column_restored)"
  if [[ "$tree" == before ]]; then
    check "$tree/2b the wrong type reads as sound"       "yes" "$(value "$f" reads_as_sound)"
    check "$tree/2b the save reports success" "inventory_saved" "$(value "$f" save_code)"
    check "$tree/2b and stores 127 for 500"              "127" "$(value "$f" row_stock)"
  else
    check "$tree/2b the type is part of the shape"        "no" "$(value "$f" reads_as_sound)"
    check "$tree/2b the column is named"               "stock" "$(value "$f" unsound_columns)"
    check "$tree/2b the save is refused"        "schema_unsound" "$(value "$f" save_code)"
  fi

  # ---- finding 3a: «apply it anyway» over a number nobody saw ------------
  probe override-unseen "$tree"; f="$PROBE_FILE"
  say "--- $tree override-unseen ---"; cat "$f" >>"$RUN"
  check "$tree/3a the shop moved after the page was rendered" "7" "$(value "$f" shop_when_submitted)"
  if [[ "$tree" == before ]]; then
    check "$tree/3a the form cannot carry what was shown"  "no" "$(value "$f" build_carries_seen_stock)"
    check "$tree/3a and it is applied over"               "YES" "$(value "$f" applied_over_unseen)"
    check "$tree/3a the shop takes the proposal"            "4" "$(value "$f" shop_after)"
  else
    check "$tree/3a the form carries what was shown"      "yes" "$(value "$f" build_carries_seen_stock)"
    check "$tree/3a the approval is refused" "stock_moved_again" "$(value "$f" approve_code)"
    check "$tree/3a the shop is untouched"                  "7" "$(value "$f" shop_after)"
    check "$tree/3a the proposal stays open"          "pending" "$(value "$f" revision_status)"
  fi

  # ---- finding 3b: the shop kept another number --------------------------
  probe readback-differs "$tree"; f="$PROBE_FILE"
  say "--- $tree readback-differs ---"; cat "$f" >>"$RUN"
  check "$tree/3b the shop clamped it to 3" "3" "$(value "$f" shop_after)"
  if [[ "$tree" == before ]]; then
    check "$tree/3b the proposal is closed anyway" "approved" "$(value "$f" revision_status)"
    check "$tree/3b on a number nobody approved"        "YES" "$(value "$f" closed_on_a_different_number)"
  else
    check "$tree/3b the refusal names both numbers" "stock_mirrored_different" "$(value "$f" approve_code)"
    check "$tree/3b the proposal stays open"                       "pending" "$(value "$f" revision_status)"
    check "$tree/3b and the mirror shows the shop's number"              "3" "$(value "$f" row_after)"
  fi

  # ---- finding 3c: the read-back failed ----------------------------------
  probe readback-failed "$tree"; f="$PROBE_FILE"
  say "--- $tree readback-failed ---"; cat "$f" >>"$RUN"
  if [[ "$tree" == before ]]; then
    check "$tree/3c closed without verification" "approved" "$(value "$f" revision_status)"
  else
    # `alpha.50` CHANGED WHAT THIS VERB REACHES, and the expectation says so
    # rather than being quietly loosened. The verb blinds
    # `woocommerce_product_get_stock_quantity` — the OBJECT's prop filter — and
    # from `alpha.50` the read-back reads the `_stock` ROW instead, because
    # `wc_get_product()` is an instance cache in WooCommerce 11 and an object
    # read can be about an earlier moment in the same request. So an
    # object-level blind no longer reaches the read-back and the approval is an
    # ordinary one.
    #
    # The question this verb used to ask — «an unconfirmed write is not
    # recorded» — is still asked, against the thing that IS read:
    # `alpha50-reproduction.sh`'s `readback-row-blinded`, where blinding the row
    # answers `stock_moved_during_write` and leaves the proposal pending.
    check "$tree/3c an object-level blind no longer reaches the read-back" "revision_approved" "$(value "$f" approve_code)"
    check "$tree/3c so this is an ordinary approval"                            "approved" "$(value "$f" revision_status)"
  fi

  # ---- the controls: identical on both trees -----------------------------
  probe control-proposal-replaced "$tree"; f="$PROBE_FILE"
  say "--- $tree control-proposal-replaced ---"; cat "$f" >>"$RUN"
  check "$tree/C1 a replacement that is not refused still replaces" "stock_revision_requested" "$(value "$f" save_code)"
  check "$tree/C1 exactly one pending proposal"                                           "1" "$(value "$f" pending_count)"
  check "$tree/C1 and it is the new one"                                                "yes" "$(value "$f" pending_is_new)"
  check "$tree/C1 the old one is on record"                                      "superseded" "$(value "$f" earlier_superseded)"
  check "$tree/C1 carrying the earlier request forward"                                 "yes" "$(value "$f" carries_title_forward)"

  probe control-sound "$tree"; f="$PROBE_FILE"
  say "--- $tree control-sound ---"; cat "$f" >>"$RUN"
  check "$tree/C2 an untouched table is sound"   "yes" "$(value "$f" reads_as_sound)"
  check "$tree/C2 with no columns named"  "__none__" "$(value "$f" unsound_columns)"
  check "$tree/C2 and the write lands"          "31" "$(value "$f" row_stock)"

  probe control-approve "$tree"; f="$PROBE_FILE"
  say "--- $tree control-approve ---"; cat "$f" >>"$RUN"
  check "$tree/C3 a clean approval succeeds" "revision_approved" "$(value "$f" approve_code)"
  check "$tree/C3 the shop moves"                            "4" "$(value "$f" shop_after)"
  check "$tree/C3 the row agrees"                            "4" "$(value "$f" row_after)"
  check "$tree/C3 the proposal is closed"             "approved" "$(value "$f" revision_status)"

  probe control-override-seen "$tree"; f="$PROBE_FILE"
  say "--- $tree control-override-seen ---"; cat "$f" >>"$RUN"
  check "$tree/C4 the override over the seen number works" "revision_approved" "$(value "$f" approve_code)"
  check "$tree/C4 the shop takes the proposal"                             "4" "$(value "$f" shop_after)"
  check "$tree/C4 the proposal is closed"                           "approved" "$(value "$f" revision_status)"

  probe control-refuses-moved "$tree"; f="$PROBE_FILE"
  say "--- $tree control-refuses-moved ---"; cat "$f" >>"$RUN"
  check "$tree/C5 a moved baseline without the box is refused" "stock_moved" "$(value "$f" approve_code)"
  check "$tree/C5 naming the baseline"                                   "9" "$(value "$f" baseline_stock)"
  check "$tree/C5 and the number now"                                    "2" "$(value "$f" current_stock)"
  check "$tree/C5 the shop is untouched"                                 "2" "$(value "$f" shop_after)"
  check "$tree/C5 the proposal stays open"                         "pending" "$(value "$f" revision_status)"
}

# ===================== the alpha.48 bytes =================================
say "putting ten files back to $BASE_COMMIT (the delivered alpha.48)"
for f in "${FILES[@]}"; do
  ( cd "$REPO" && git show "$BASE_COMMIT:$f" ) >"$REPO/$f" || {
    say "FAIL  could not fetch $f from $BASE_COMMIT"; FAIL=$((FAIL + 1));
  }
done
install_src
( cd "$REPO" && sha256sum "${FILES[@]}" ) >"$OUT/before-bytes.sha256"
DIFFERING=$(diff <(cut -d' ' -f1 "$HASHES") <(cut -d' ' -f1 "$OUT/before-bytes.sha256") | grep -c '^<' || true)
check "the ten files really are other bytes now" "10" "$DIFFERING"
( cd "$REPO" && "$PHPBIN" -l src/Modules/Product/Application/ManageProducts.php ) >/dev/null \
  && check "and the old tree still parses" "ok" "ok"

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
