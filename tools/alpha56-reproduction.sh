#!/usr/bin/env bash
# `alpha.56` on real WooCommerce: the four intake defects of `alpha.55`, the
# one product list, and the account-page button.
#
# WHAT IS MEASURED, before and after, on `alpha.55`'s own bytes:
#
#   §2-الف AN INTAKE THAT REPORTED SUCCESS WITH HALF OF ITSELF WRITTEN.
#        `intake()` created the row and then wrote the agreement baseline, the
#        brand term and the gallery — and answered `imported` whichever of the
#        three failed. The baseline is the one that hurts most alone: it is
#        what the approval path compares against, so a row carrying an empty
#        one makes every field read as somebody else's change for ever. And
#        the next run SKIPPED the product, because the link already existed:
#        `already_linked_observed`, for ever, with no page saying so.
#   §2-ب  A ROLLBACK THAT THREW AWAY WHAT A RETRY NEEDED. When the row delete
#        was refused, `alpha.55` had already cleared the import stamp and
#        deleted the run manifest — so the run that had not finished could
#        never be named again: `intake_run_unknown`.
#   §2-ج  ACCEPTANCE ON A SNAPSHOT THAT HAD GONE STALE. The intake records
#        WooCommerce's values; acceptance is a step a manager sleeps on; and
#        nothing asked whether the shop had moved in between. Measured: a
#        price changed from 450000 to 480000 after the intake was back at
#        450000 after the first projection, and no screen said a word.
#   §2-د  AND A FOURTH, WHICH ONLY MEASUREMENT FOUND: projecting an OBSERVED
#        row created a second WooCommerce product. `SyncCatalog` never asked
#        whether the row owned its storefront product, so a path that is
#        supposed to decide nothing about somebody else's product made a new
#        one beside it.
#   §3   ONE LIST. Both halves of the catalogue in one page, each related
#        product exactly once — with a duplicate `_sku` row planted, because
#        `wp_postmeta` has no unique index on `(post_id, meta_key)` and a list
#        that joined it would show the product twice. Plus the three facets as
#        three independent questions, real paging, and the cost of a page in
#        statements.
#   §3-ه  THE 189/262 COUNT. The owner read 189 products beside
#        `simple=262 variable=32`. The type count was asked of the
#        `product_type` taxonomy with NO join to `posts`, so it counted rows
#        belonging to variations, to other post types and to no post at all.
#        Reproduced by PLANTING one of each of those three shapes.
#   §4   THE ACCOUNT BUTTON. The owner saw only Dokan's red button. Separated
#        into its two possible causes: the hook not running (which this
#        environment cannot answer for the owner's theme, and which the site
#        now records for itself) and this plugin correctly drawing nothing for
#        a MANAGER — who from this round gets an administrative link instead.
#
# THIS ROUND HAS NO MIGRATION. `SchemaVersion::TARGET` is 28 on both trees, so
# unlike `alpha.54` there is no table to swap: only `src/` moves.
#
# THE TREES. The INSTALLED plugin's `src/` is swapped, never the repository's:
# the old bytes come from `git archive <base> src` and a `trap` puts this
# checkout back. The repository is never written to.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha56-reproduction.sh docs/evidence/alpha56-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha56-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_COMMIT="${BASE_COMMIT:-fd008a0}"
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
trap 'install_tree "$REPO/src"; rm -rf "$OLD_TREE"' EXIT

NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
OLD_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$OLD_TREE/src/Core/Migration/SchemaVersion.php" | head -1)"
say "schema: $OLD_SCHEMA (base) -> $NEW_SCHEMA (this tree)"
check "0a this round adds no migration" "$OLD_SCHEMA" "$NEW_SCHEMA"

PROBE_FILE=""
probe() { # probe <verb> <tree> [args...]
  local verb="$1" tree="$2"; shift 2
  PROBE_FILE="$OUT/$tree-$verb.txt"
  ( cd "$REPO" && TMC_REPO="$REPO" TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" \
      "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
      --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file tests/Support/alpha56-probe.php "$verb" "$@" ) >"$PROBE_FILE" 2>"$PROBE_FILE.err"
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
  probe arrange "$tree"; f="$PROBE_FILE"; dump "$tree arrange" "$f"
  wcid="$(value "$f" wc_id)"
  check "$tree/0b the fixture is one published product" "publish" "$(value "$f" status)"
  check "$tree/0c with no marketplace row yet"                "0" "$(value "$f" marketplace_rows)"

  # ------------------------------------- §2-الف half an intake, reported done
  probe intake-baseline-fails "$tree" "$wcid"; f="$PROBE_FILE"
  dump "$tree intake-baseline-fails" "$f"
  check "$tree/2a the baseline write was refused once" "1" "$(value "$f" refused_statements)"
  if [[ "$tree" == before ]]; then
    check "$tree/2a THE DEFECT: it reported success"  "imported" "$(value "$f" verdict)"
    check "$tree/2a with half of itself written" "partly_written:baseline" "$(value "$f" reason)"
    check "$tree/2a the row is there"                      "yes" "$(value "$f" row_exists)"
    check "$tree/2a and its baseline is empty"        "__empty__" "$(value "$f" baseline)"
  else
    check "$tree/2a it refuses"                          "failed" "$(value "$f" verdict)"
    check "$tree/2a by name"                  "intake_not_stored" "$(value "$f" reason)"
    check "$tree/2a and NOTHING was left behind"             "no" "$(value "$f" row_exists)"
  fi

  probe intake-retry "$tree" "$wcid"; f="$PROBE_FILE"; dump "$tree intake-retry" "$f"
  # The run the ROW ended up stamped with — the retry's run on a tree that
  # retried, the first attempt's on a tree that skipped. Read rather than
  # named: a script that chose the run itself would roll back a run one of
  # the two trees never created, and report a defect about the wrong thing.
  local rollback_run
  rollback_run="$(value "$f" row_run)"
  if [[ "$tree" == before ]]; then
    check "$tree/2a THE DEFECT: the retry skips it"        "skip" "$(value "$f" verdict)"
    check "$tree/2a because the link exists" "already_linked_observed" "$(value "$f" reason)"
    check "$tree/2a so the baseline stays empty FOR EVER" "__empty__" "$(value "$f" baseline_after_retry)"
  else
    check "$tree/2a the retry imports it"              "imported" "$(value "$f" verdict)"
    check "$tree/2a with its baseline"                  "present" "$(value "$f" baseline_after_retry)"
    check "$tree/2a and its gallery"                          "2" "$(value "$f" images_after_retry)"
  fi

  # The repair path, for a row that is ALREADY half-written on disk — which
  # is the state `alpha.55` could leave behind and this round has to be able
  # to finish rather than abandon.
  probe intake-incomplete "$tree" "$wcid"; f="$PROBE_FILE"; dump "$tree intake-incomplete" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/2a there was no way to even LIST them" "1" "$(value "$f" absent)"
  else
    check "$tree/2a nothing is incomplete after a clean intake" "0" "$(value "$f" incomplete_count)"
  fi

  # ----------------------------- §2-ب a rollback that threw the retry away
  probe rollback-delete-fails "$tree" "$wcid" "$rollback_run"; f="$PROBE_FILE"
  dump "$tree rollback-delete-fails" "$f"
  check "$tree/2b the run was named in the manifest" "present" "$(value "$f" manifest_before)"
  check "$tree/2b the rollback did not finish"            "no" "$(value "$f" rollback_ok)"
  check "$tree/2b and the row is still there" "$(value "$f" row_before)" "$(value "$f" row_after)"
  if [[ "$tree" == before ]]; then
    check "$tree/2b THE DEFECT: the stamp was cleared"  "__empty__" "$(value "$f" stamp_after)"
    check "$tree/2b and the manifest deleted"          "__absent__" "$(value "$f" manifest_after)"
  else
    check "$tree/2b the stamp is kept"           "$rollback_run" "$(value "$f" stamp_after)"
    check "$tree/2b the manifest is kept"                 "present" "$(value "$f" manifest_after)"
    check "$tree/2b and the refusal names the part" \
          "$(value "$f" row_before):not_removed:product_row" "$(value "$f" kept)"
  fi

  probe rollback-retry "$tree" "$wcid" "$rollback_run"; f="$PROBE_FILE"
  dump "$tree rollback-retry" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/2b THE DEFECT: the run cannot be named again" "intake_run_unknown" "$(value "$f" rollback_code)"
    check "$tree/2b so the row is stranded"                              "1" "$(value "$f" rows_for_product)"
  else
    check "$tree/2b the retry completes it"      "intake_rolled_back" "$(value "$f" rollback_code)"
    check "$tree/2b and the row is gone"                          "0" "$(value "$f" rows_for_product)"
  fi

  probe rollback-leftovers "$tree" "$wcid"; f="$PROBE_FILE"; dump "$tree rollback-leftovers" "$f"
  if [[ "$tree" == after ]]; then
    check "$tree/2b no product row left"   "__absent__" "$(value "$f" product_row)"
    check "$tree/2b no image rows left"             "0" "$(value "$f" image_rows)"
    check "$tree/2b no spec rows left"              "0" "$(value "$f" spec_rows)"
  fi

  # --------------------------------- §2-ج acceptance on a stale snapshot
  probe reset "$tree" >/dev/null
  probe arrange "$tree" >/dev/null
  wcid="$(value "$OUT/$tree-arrange.txt" wc_id)"
  probe accept-stale "$tree" "$wcid"; f="$PROBE_FILE"; dump "$tree accept-stale" "$f"
  check "$tree/2c the intake recorded the price it saw" "450000" "$(value "$f" row_price_at_intake)"
  check "$tree/2c and the shop has moved since"         "480000" "$(value "$f" shop_price_before_accept)"
  if [[ "$tree" == before ]]; then
    check "$tree/2c THE DEFECT: acceptance went through" "ownership_taken" "$(value "$f" take_code)"
    check "$tree/2c and the first sync wrote the old price" "450000" "$(value "$f" shop_price_after_sync)"
    check "$tree/2c the manager's price is gone"                 "no" "$(value "$f" manager_price_kept)"
  else
    check "$tree/2c it refuses and names the drift" "storefront_moved" "$(value "$f" take_code)"
    check "$tree/2c the row is still only observed"      "observed" "$(value "$f" ownership_after)"
    check "$tree/2c the shop keeps its price"              "480000" "$(value "$f" shop_price_after_sync)"
    check "$tree/2c kept"                                     "yes" "$(value "$f" manager_price_kept)"
  fi

  probe accept-refresh "$tree" "$wcid"; f="$PROBE_FILE"; dump "$tree accept-refresh" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/2c there was no way to refresh the baseline" "1" "$(value "$f" absent)"
  else
    check "$tree/2c the baseline can be taken again" "intake_refreshed" "$(value "$f" refresh_code)"
    check "$tree/2c it holds the shop's value now"           "480000" "$(value "$f" row_price_after_refresh)"
    check "$tree/2c and then acceptance works"       "ownership_taken" "$(value "$f" take_code)"
    check "$tree/2c with the shop's value intact"            "480000" "$(value "$f" shop_price_after_sync)"
  fi

  # ------------------- §2-د projecting an observed row made a SECOND product
  probe reset "$tree" >/dev/null
  probe arrange "$tree" >/dev/null
  wcid="$(value "$OUT/$tree-arrange.txt" wc_id)"
  probe project-observed "$tree" "$wcid"; f="$PROBE_FILE"; dump "$tree project-observed" "$f"
  check "$tree/2d the row only watches" "observed" "$(value "$f" ownership)"
  if [[ "$tree" == before ]]; then
    check "$tree/2d THE DEFECT: the sync went ahead" "projected" "$(value "$f" sync_code)"
  else
    check "$tree/2d the sync refuses by name" "not_our_product" "$(value "$f" sync_code)"
    check "$tree/2d the row still points at the same product" "yes" "$(value "$f" still_points_at_the_observed_product)"
    check "$tree/2d and no WooCommerce product was created" \
          "$(value "$f" wc_products_before)" "$(value "$f" wc_products_after)"
  fi

  # ---------------------------------------------- §3-ه the 189/262 counts
  probe counts-scope "$tree" ; f="$PROBE_FILE"; dump "$tree counts-scope" "$f"
  check "$tree/3e three stray rows were planted" "yes" "$(value "$f" planted_removed)"
  if [[ "$tree" == before ]]; then
    check "$tree/3e THE DEFECT: the type total moved" "yes" "$(value "$f" type_total_moved)"
    check "$tree/3e while the status total did not"     "no" "$(value "$f" status_total_moved)"
  else
    check "$tree/3e the type total does not move"       "no" "$(value "$f" type_total_moved)"
    check "$tree/3e nor the status total"               "no" "$(value "$f" status_total_moved)"
    check "$tree/3e and they add up"                   "yes" "$(value "$f" types_add_up_to_statuses)"
    check "$tree/3e the strays are reported, not hidden" \
          "on_variations:1,on_other_post_types:1,no_post_at_all:1" "$(value "$f" strays_reported)"
  fi

  # ------------------------------------------------------- §3 the one list
  probe reset "$tree" >/dev/null
  probe arrange "$tree" >/dev/null
  wcid="$(value "$OUT/$tree-arrange.txt" wc_id)"
  probe one-list "$tree"; f="$PROBE_FILE"; dump "$tree one-list" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/3a there was no one list"  "1" "$(value "$f" absent)"
  else
    check "$tree/3a the list is available"               "yes" "$(value "$f" available)"
    check "$tree/3a and the product appears exactly once"  "1" "$(value "$f" this_product_appears)"
    check "$tree/3a with its WooCommerce status"     "publish" "$(value "$f" wc_status)"
    check "$tree/3a and the token came with the row" "s0.r0.b0" "$(value "$f" submission)"
  fi

  probe list-draft-only "$tree"; f="$PROBE_FILE"; dump "$tree list-draft-only" "$f"
  if [[ "$tree" == after ]]; then
    check "$tree/3b a marketplace row with no product is in the list" "0" "$(value "$f" wc_product_id)"
    check "$tree/3b named as its own source"                    "draft" "$(value "$f" source)"
    check "$tree/3b with no WooCommerce status to show"    "__absent__" "$(value "$f" wc_status)"
    check "$tree/3b and its own bucket"                    "draft_only" "$(value "$f" bucket)"
  fi

  probe facets "$tree"; f="$PROBE_FILE"; dump "$tree facets" "$f"
  if [[ "$tree" == after ]]; then
    check "$tree/3c every row is counted once per facet" "yes" "$(value "$f" sums_agree)"
    check "$tree/3c published AND observed is its own question" "0" "$(value "$f" published_and_observed)"
  fi

  probe paging "$tree"; f="$PROBE_FILE"; dump "$tree paging" "$f"
  if [[ "$tree" == after ]]; then
    check "$tree/3d a page is a page"       "5" "$(value "$f" first_rows)"
    check "$tree/3d and the next one too"   "5" "$(value "$f" second_rows)"
    check "$tree/3d with no product twice"  "0" "$(value "$f" overlap)"
    check "$tree/3d and «everything» is refused" "100" "$(value "$f" ceiling)"
  fi

  probe shop-search "$tree"; f="$PROBE_FILE"; dump "$tree shop-search" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/3f a vendor could only be named by user id" "1" "$(value "$f" absent)"
  else
    check "$tree/3f a shop is found by name"            "1" "$(value "$f" matches)"
    check "$tree/3f a name nothing has finds nothing"   "0" "$(value "$f" nothing_matches)"
    check "$tree/3f and a percent sign is a percent sign" "0" "$(value "$f" percent_is_literal)"
  fi

  # ------------------------------------------------- §4 the account button
  probe account-button "$tree"; f="$PROBE_FILE"; dump "$tree account-button" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/4a THE DEFECT: a manager got nothing" "__absent__" "$(value "$f" manager_route)"
  else
    check "$tree/4a a manager gets a button"              "yes" "$(value "$f" manager_printed)"
    check "$tree/4a into wp-admin"                        "yes" "$(value "$f" manager_is_admin_route)"
    check "$tree/4a not the vendor panel"                 "yes" "$(value "$f" manager_not_vendor_panel)"
    check "$tree/4a and the manager is STILL not a vendor" "__none__" "$(value "$f" manager_profile_after)"
    check "$tree/4a a vendor still gets the panel"        "yes" "$(value "$f" vendor_is_panel)"
    check "$tree/4a and not the admin route"              "yes" "$(value "$f" vendor_not_admin_route)"
    check "$tree/4a the site recorded that the hook ran"  "yes" "$(value "$f" seen_dashboard)"
    check "$tree/4a and the shortcode is the same block"  "yes" "$(value "$f" shortcode_same_block)"
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

# ===================================================== controls on BOTH trees
#
# A measurement that differs between the trees proves a change; a CONTROL that
# does NOT differ proves the change is the one claimed and not a side effect.
say ""
say "=================== controls ==================="
for tree in before after; do
  f="$OUT/$tree-arrange.txt"
  check "control/$tree the fixture product is the same one" \
        "$(value "$OUT/before-arrange.txt" wc_id)" "$(value "$f" wc_id)"
  check "control/$tree its gallery is untouched" \
        "$(value "$OUT/before-arrange.txt" gallery)" "$(value "$f" gallery)"
  f="$OUT/$tree-accept-stale.txt"
  check "control/$tree an intake records what it saw" "450000" "$(value "$f" row_price_at_intake)"
  check "control/$tree and the risk list is the same" "__none__" "$(value "$f" risks)"
done

probe reset after >/dev/null
say ""
say "PASS=$PASS FAIL=$FAIL"
echo "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
