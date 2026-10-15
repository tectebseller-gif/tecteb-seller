#!/usr/bin/env bash
# `alpha.55` on real WooCommerce: the two gaps `alpha.54` left, the Min Max
# enforcement grant, and the intake of products this shop already has.
#
# WHAT IS MEASURED, and the first two are before/after on `alpha.54`'s own
# bytes:
#
#   §6-ب THE HEADLINE. `alpha.54` locked the three destination rows and then
#        compared `get_post_meta()`, which never looks at a row:
#        `get_metadata_raw()` answers from `wp_cache_get($id, 'post_meta')`.
#        So with a WARM cache and a manager's edit committed behind it by
#        another process, the rollback compared a value that was no longer
#        there, reported success, and DELETED the manager's new value.
#   §6-الف `decide()` read the baseline, judged ownership and wrote, with no
#        lock. A manager's edit landing in that window was overwritten with a
#        `dest_*_before` recorded from the value read a moment earlier — so
#        even the rollback target was wrong. The second writer is injected
#        from a decorator on the ledger's `claim()`, which runs between the
#        read and the write on BOTH trees, which is what makes it repeatable
#        instead of a coin toss.
#   §5   a rule written onto a product the marketplace does not own is read by
#        nothing. Measured three ways: Min Max on; Min Max OFF with no grant
#        (the rule is gone — «۱۲ محصول» on the owner's site are in exactly
#        this state); and Min Max OFF with the grant, where the allowed set is
#        byte-identical to Min Max's own.
#   §1–§4 the inventory writes nothing, the intake keeps the product's id, URL,
#        status, author and past order lines, a second run makes no second
#        row, and the rollback removes its own rows and no WooCommerce
#        product.
#
# THIS ROUND HAS NO MIGRATION. `SchemaVersion::TARGET` is 28 on both trees, so
# unlike `alpha.54` there is no table to swap: only `src/` moves.
#
# THE TREES. The INSTALLED plugin's `src/` is swapped, never the repository's:
# the old bytes come from `git archive <base> src` and a `trap` puts this
# checkout back. The repository is never written to.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha55-reproduction.sh docs/evidence/alpha55-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha55-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_COMMIT="${BASE_COMMIT:-51f65e4}"
MINMAX="${MINMAX:-woo-min-max-quantity-step-control-single}"
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
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }

[[ -d "$INSTALLED" ]] || { say "FAIL  the plugin is not installed at $INSTALLED"; echo "PASS=0 FAIL=1" | tee -a "$RUN"; exit 1; }

OLD_TREE="$(mktemp -d)"
( cd "$REPO" && git archive "$BASE_COMMIT" src | tar -x -C "$OLD_TREE" ) || {
  say "FAIL  could not read src/ at $BASE_COMMIT"; echo "PASS=0 FAIL=1" | tee -a "$RUN"; exit 1
}
say "old bytes read from $BASE_COMMIT: $(find "$OLD_TREE/src" -name '*.php' | wc -l) php files"
say "this checkout:                    $(find "$REPO/src" -name '*.php' | wc -l) php files"

install_tree() { rsync -a --delete "$1/" "$INSTALLED/src/"; }
# Whatever happens, this checkout goes back on disk and Min Max goes back on.
trap 'install_tree "$REPO/src"; wpx plugin activate "$MINMAX" >/dev/null 2>&1; rm -rf "$OLD_TREE"' EXIT

NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
OLD_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$OLD_TREE/src/Core/Migration/SchemaVersion.php" | head -1)"
say "schema: $OLD_SCHEMA (base) -> $NEW_SCHEMA (this tree)"
check "0a this round adds no migration" "$OLD_SCHEMA" "$NEW_SCHEMA"

PROBE_FILE=""
probe() { # probe <verb> <tree> [arg]
  local verb="$1" tree="$2" extra="${3:-}"
  PROBE_FILE="$OUT/$tree-$verb${extra:+-$extra}.txt"
  ( cd "$REPO" && TMC_REPO="$REPO" TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" \
      "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
      --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file tests/Support/alpha55-probe.php "$verb" $extra ) >"$PROBE_FILE" 2>"$PROBE_FILE.err"
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
dump() { say "--- $1 ---"; grep -v '^WordPress database error' "$2" >>"$RUN" 2>/dev/null || true; }

run_tree() { # run_tree <label>
  local tree="$1" f wcid
  say ""
  say "=================== $tree ==================="
  probe reset "$tree" >/dev/null

  # -------------------------------- §6-ب the read under the lock
  probe rollback-warm-cache "$tree"; f="$PROBE_FILE"; dump "$tree rollback-warm-cache" "$f"
  check "$tree/6b the product was migrated"            "migrated" "$(value "$f" migrated)"
  check "$tree/6b and the rule is on it"                "4/10/2"  "$(value "$f" rows_after_migration)"
  check "$tree/6b a second process changed the row"        "yes"  "$(value "$f" second_writer)"
  check "$tree/6b the row now holds the manager's value"  "4/8/2" "$(value "$f" row_now)"
  # The whole premise: the cache and the row disagree.
  check "$tree/6b the warm cache IS stale"                 "yes"  "$(value "$f" cache_is_stale)"
  if [[ "$tree" == before ]]; then
    check "$tree/6b THE DEFECT: it rolled back anyway"  "minmax_rolled_back" "$(value "$f" rollback_code)"
    check "$tree/6b DELETED the manager's value" "__absent__/__absent__/__absent__" "$(value "$f" db_rows_after_rollback)"
    check "$tree/6b so it is gone"                              "no" "$(value "$f" manager_value_kept)"
  else
    check "$tree/6b it refuses and names the field" "minmax_rollback_changed" "$(value "$f" rollback_code)"
    check "$tree/6b the field"                                     "max" "$(value "$f" rollback_field)"
    check "$tree/6b and what is really there"                        "8" "$(value "$f" rollback_now)"
    check "$tree/6b the manager's value is untouched"            "4/8/2" "$(value "$f" db_rows_after_rollback)"
    check "$tree/6b kept"                                           "yes" "$(value "$f" manager_value_kept)"
  fi

  # -------------------------------- §6-الف the window inside decide()
  probe decide-window "$tree"; f="$PROBE_FILE"; dump "$tree decide-window" "$f"
  check "$tree/6a the destination had a value"  "__absent__/6/__absent__" "$(value "$f" destination_before)"
  if [[ "$tree" == before ]]; then
    check "$tree/6a THE DEFECT: the edit landed mid-migration" "yes" "$(value "$f" second_writer_landed)"
    check "$tree/6a and was written over"                   "4/10/2" "$(value "$f" db_rows_after)"
  else
    check "$tree/6a the edit could not land in the window"      "no" "$(value "$f" second_writer_landed)"
    check "$tree/6a the migration completed"              "migrated" "$(value "$f" outcome)"
    check "$tree/6a and the rollback target is the real value" \
          "migrated_before=6_wrote=10" "$(value "$f" ledger_after)"
  fi

  # -------------------------------- §5 the grant
  probe unenforced-arrange "$tree"; f="$PROBE_FILE"; dump "$tree unenforced-arrange" "$f"
  wcid="$(value "$f" wc_id)"
  check "$tree/5a written onto a product we do not own" "written_unenforced" "$(value "$f" outcome)"
  check "$tree/5a the rule is on it"                            "4/10/2" "$(value "$f" rows_after)"
  check "$tree/5a and the marketplace does not own it"               "no" "$(value "$f" marketplace_owns)"

  probe enforce "$tree" "$wcid"; f="$PROBE_FILE"; dump "$tree enforce" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/5b there was no way to enforce it"   "1" "$(value "$f" absent)"
  else
    check "$tree/5b the grant is recorded" "minmax_rule_enforced" "$(value "$f" enforce_code)"
    check "$tree/5b the product carries it"              "yes" "$(value "$f" granted)"
    # AND NOTHING ELSE MOVED. The grant is about one rule.
    check "$tree/5b ownership did not move"               "no" "$(value "$f" marketplace_owns)"
    check "$tree/5b the publish status did not move" "publish" "$(value "$f" wc_status)"
    check "$tree/5b the rule itself did not move"    "4/10/2" "$(value "$f" rows_after)"
  fi
}

# ===================================================================== before
install_tree "$OLD_TREE/src"
say "installed: $BASE_COMMIT"
run_tree before

# ====================================================================== after
install_tree "$REPO/src"
say "installed: this checkout"
run_tree after

# ========================== §5 the measurement that needs Min Max switched off
say ""
say "=================== Min Max off and on ==================="
MM_WC="$(value "$OUT/after-unenforced-arrange.txt" wc_id)"
probe cart after "$MM_WC"
# Copied out at once, because the next `probe cart` writes the SAME path — and
# the first version of this script compared the «off» file with itself after
# `mv` had taken the «on» one away, which answered `__missing__` about six
# measurements that were right there in the log.
CART_ON="$OUT/after-cart-minmax-on.txt"; cp "$PROBE_FILE" "$CART_ON"
dump "after cart, Min Max ON, granted" "$CART_ON"
check "5c with Min Max ON, the quantities are the rule's" "refused" "$(value "$CART_ON" qty_3)"
check "5c and a multiple is allowed"                      "allowed" "$(value "$CART_ON" qty_4)"

wpx plugin deactivate "$MINMAX" >/dev/null 2>&1
say "Min Max deactivated"
probe cart after "$MM_WC"
CART_OFF="$OUT/after-cart-minmax-off.txt"; cp "$PROBE_FILE" "$CART_OFF"
dump "after cart, Min Max OFF, granted" "$CART_OFF"
# THE INVARIANT §5 ASKS FOR: the same allowed set with Min Max gone.
for q in 1 3 4 8 11; do
  check "5d Min Max off, granted: qty $q" "$(value "$CART_ON" "qty_$q")" "$(value "$CART_OFF" "qty_$q")"
done
check "5d and the Store API agrees"  "$(value "$CART_ON" store_api_qty_3)" "$(value "$CART_OFF" store_api_qty_3)"

# And the control: without the grant, switching Min Max off loses the rule.
probe unenforce after "$MM_WC" >/dev/null
probe cart after "$MM_WC"
CART_NONE="$OUT/after-cart-minmax-off-no-grant.txt"; cp "$PROBE_FILE" "$CART_NONE"
dump "after cart, Min Max OFF, grant withdrawn" "$CART_NONE"
check "5e without the grant the rule is gone: qty 1"  "allowed" "$(value "$CART_NONE" qty_1)"
check "5e qty 3"                                       "allowed" "$(value "$CART_NONE" qty_3)"
check "5e qty 11"                                      "allowed" "$(value "$CART_NONE" qty_11)"

# ---------------- §5 THE GRANT IS ABOUT ONE RULE, NOT ABOUT THESE THREE ROWS
# The first cut of this round stored the grant as `'1'`, which says «enforce
# whatever these rows hold next week» — a permission nobody gave, and this
# repository's own `alpha.49` rule broken one object down. It is stored as a
# fingerprint of the rule it was granted for, so a rule somebody changes
# afterwards is simply not enforced: back to where the product was before any
# grant existed, rather than enforcing numbers no manager read.
probe enforce after "$MM_WC" >/dev/null
probe drift after "$MM_WC"; f="$PROBE_FILE"; dump "after drift, Min Max OFF" "$f"
check "5f the grant was a fingerprint, not a flag" "yes" "$(value "$f" granted_before)"
check "5f the grant row is still on the product"   "yes" "$(value "$f" grant_row_unchanged)"
check "5f and it is no longer live"                 "no" "$(value "$f" granted_after)"
check "5f the rule on the product moved"         "4/8/2" "$(value "$f" rows_after)"
probe cart after "$MM_WC"
CART_DRIFT="$OUT/after-cart-minmax-off-drifted.txt"; cp "$PROBE_FILE" "$CART_DRIFT"
dump "after cart, Min Max OFF, rule drifted" "$CART_DRIFT"
for q in 1 3 11; do
  check "5f a drifted rule is not enforced: qty $q" "allowed" "$(value "$CART_DRIFT" "qty_$q")"
done

# ---------------- §5 AND A PERMISSION CAN ALWAYS BE GIVEN BACK
# The same first cut ran the drift check on the way OUT too, so a rule changed
# after the grant left the grant stuck on: `minmax_unenforce_changed`, and the
# manager had no way to withdraw it.
probe unenforce after "$MM_WC"; f="$PROBE_FILE"; dump "after unenforce, rule drifted" "$f"
check "5g the grant comes back even after the rule moved"       "minmax_rule_unenforced" "$(value "$f" unenforce_code)"
check "5g and the newer value is untouched" "4/8/2" "$(value "$f" rows_after)"

wpx plugin activate "$MINMAX" >/dev/null 2>&1
say "Min Max reactivated"

# ============================================ §1–§4 the intake, on this tree
say ""
say "=================== the intake ==================="
probe intake-arrange after; f="$PROBE_FILE"; dump "after intake-arrange" "$f"
SHOP_WC="$(value "$f" wc_id)"
check "1a the fixture is the shop's own product" "$(wpx eval 'echo (int) (get_users(["role"=>"administrator","number"=>1,"fields"=>"ID"])[0] ?? 1);' 2>/dev/null)" "$(value "$f" author)"
check "1a and the marketplace has no row for it"  "0" "$(value "$f" marketplace_rows)"

probe inventory after; f="$PROBE_FILE"; dump "after inventory" "$f"
ROWS_BEFORE="$(value "$f" rows_in_products)"
check "1b the inventory reads the catalogue"            "yes" "$(value "$f" available)"
check "1b the shop's own products are their own bucket" "2" "$(value "$f" bucket_shop)"
check "1b no duplicate links"                           "0" "$(value "$f" duplicate_links)"

probe intake after "$SHOP_WC"; f="$PROBE_FILE"; dump "after intake" "$f"
check "3a it needs a named vendor and got one"  "intake_done" "$(value "$f" code)"
check "3a one row created"                                "1" "$(value "$f" imported)"
check "3a the SAME WooCommerce id"                      "yes" "$(value "$f" same_wc_id)"
check "3a the URL is unchanged"                         "yes" "$(value "$f" url_unchanged)"
check "3a the publish status is unchanged"              "yes" "$(value "$f" status_unchanged)"
check "3a the author is unchanged"                      "yes" "$(value "$f" author_unchanged)"
check "3a earlier order lines are unchanged"            "yes" "$(value "$f" order_lines_unchanged)"
check "3a the row carries WooCommerce's price"      "450000" "$(value "$f" row_price)"
check "3a and an agreement baseline"                    "yes" "$(value "$f" row_has_baseline)"
check "3a and it owns nothing yet"                       "no" "$(value "$f" owns_storefront)"

probe intake-twice after "$SHOP_WC"; f="$PROBE_FILE"; dump "after intake-twice" "$f"
check "4a the second run imports nothing"                 "0" "$(value "$f" imported)"
check "4a and says why"            "already_linked_observed" "$(value "$f" reason)"
check "4a one row for the product"                        "1" "$(value "$f" rows_for_this_product)"

probe intake-rollback after "$SHOP_WC"; f="$PROBE_FILE"; dump "after intake-rollback" "$f"
check "4b the rollback removed its own row" "intake_rolled_back" "$(value "$f" rollback_code)"
check "4b exactly one"                                      "1" "$(value "$f" removed)"
check "4b and the WooCommerce product is still there"     "yes" "$(value "$f" wc_product_still_there)"
check "4b still published"                            "publish" "$(value "$f" wc_status)"

probe inventory after; f="$PROBE_FILE"
check "4c the catalogue is back where it started" "$ROWS_BEFORE" "$(value "$f" rows_in_products)"

probe reset after >/dev/null
install_tree "$REPO/src"
say ""
say "PASS=$PASS FAIL=$FAIL"
echo "PASS=$PASS FAIL=$FAIL" | tee -a "$RUN"
[[ "$FAIL" -eq 0 ]]
