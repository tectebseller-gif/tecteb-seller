#!/usr/bin/env bash
# `alpha.89` → `alpha.90` on the disposable WordPress: structure 36 → 36.
#
# WHAT THIS MEASURES
#
#   1. THERE IS NO MIGRATION, and the claim is read from the two ZIPs rather
#      than from this checkout: a hand-typed number locks a script to one
#      round, and three of them did exactly that (`alpha.34`).
#   2. Swapping the files runs nothing, and after the next admin request the
#      structure is STILL 36 with no failed step.
#   3. The ownership transfer this round fixed, driven on a REAL WordPress
#      through the service the page uses: take → the three stamps land and the
#      row says `marketplace`; give back → the stamps go and the row says
#      `observed`; and at every point there is exactly ONE WooCommerce product
#      with that title. ⛔ The duplicate this round is about appears only when
#      the ROW write is refused, which is injected in
#      `OwnershipTransferUnitOfWorkTest` on real MariaDB and NOT here — this
#      half measures the healthy round trip the manager will do 184 times.
#   4. Nothing else moves: the product's price, status and title are the same
#      before and after, and the plugin writes no second post.
#   5. ROLLBACK to `alpha.89` needs NO database work, and the cost is printed
#      rather than argued: the older build does not know the new audit event,
#      so it stops naming the half-written outcome — the rows it already
#      wrote stay and are harmless, because the audit table is append-only.
#
# ⛔ One fixture product and one row are created and given back. No import is
# re-run, no Dokan table is read or touched, and no price, stock, order,
# commission or settlement is written.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha89-check.sh docs/evidence/upgrade-alpha90
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha90}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
NEW_ZIP="${TMC_NEW_ZIP:-}"
OLD_ZIP="${TMC_OLD_ZIP:-}"
STATE_SRC="$REPO/tools/upgrade-alpha89-state.php"
STATE="$WP_PATH/upgrade-alpha89-state.php"
WORK="$(mktemp -d /tmp/tmc-upg90-XXXXXX)"

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
state() { wpx eval-file "$STATE" "$@" 2>>"$RUN"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
admin_request() {
  wpx eval '
    if (file_exists(WP_PLUGIN_DIR . "/woocommerce/includes/admin/wc-admin-functions.php")) {
        require_once WP_PLUGIN_DIR . "/woocommerce/includes/admin/wc-admin-functions.php";
    }
    do_action("admin_init");
    echo "ran=yes\n";
  ' 2>>"$RUN"
}
schema() { wpx option get tmc_schema_version 2>>"$RUN" | tr -d '[:space:]'; }

cleanup() {
  state reset >/dev/null 2>&1
  rm -f "$STATE"
  rm -rf "$WORK"
}
trap cleanup EXIT

# ------------------------------------------------------- the two packages
if [[ -z "$NEW_ZIP" ]]; then
  NEW_ZIP="$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.90.zip"
fi
if [[ -z "$OLD_ZIP" ]]; then
  OLD_ZIP="$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.89.zip"
fi
say "=== the packages ==="
say "new_zip=$NEW_ZIP"
say "old_zip=$OLD_ZIP"
if [[ ! -f "$NEW_ZIP" || ! -f "$OLD_ZIP" ]]; then
  say "FAIL  0a both packages have to exist; build the new one first"
  exit 1
fi
schema_of() {
  rm -rf "$WORK/peek"
  mkdir -p "$WORK/peek"
  unzip -qo "$1" -d "$WORK/peek"
  sed -n 's/.*public const TARGET = \([0-9]\+\);.*/\1/p' \
    "$WORK/peek/tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php" | head -1
}
NEW_TARGET="$(schema_of "$NEW_ZIP")"
OLD_TARGET="$(schema_of "$OLD_ZIP")"
say "new_target=$NEW_TARGET"
say "old_target=$OLD_TARGET"
check "0a this round adds no migration step" "$OLD_TARGET" "$NEW_TARGET"
check "0b and both target structure 36" "36" "$NEW_TARGET"

install_zip() {
  wpx plugin install "$1" --force >>"$RUN" 2>&1
  wpx plugin activate tecteb-marketplace-core >>"$RUN" 2>&1
}

# ========================================================= the old package
say ""
say "=== 1 · the site on alpha.89 ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
state reset >>"$RUN" 2>&1
admin_request >>"$RUN" 2>&1
check "1a structure is 36 before anything" "36" "$(schema)"
OLD_AUDIT="$(state audit)"
check "1b alpha.89 knows the ownership event" "yes" "$(val "$OLD_AUDIT" changed_known)"
check "1c and does NOT know the half-written one" "no" "$(val "$OLD_AUDIT" half_known)"

# ========================================================= the new package
say ""
say "=== 2 · swap to alpha.90, then one admin request ==="
install_zip "$NEW_ZIP"
check "2a swapping the files alone changes no structure" "36" "$(schema)"
admin_request >>"$RUN" 2>&1
check "2b and the request runs no step either" "36" "$(schema)"
MIG="$(wpx option get tmc_migration_status --format=json 2>/dev/null || echo '{}')"
note "2c migration status" "$MIG"
check "2d no failed step is recorded" "0" "$(printf '%s' "$MIG" | grep -o 'failed' | wc -l | tr -d '[:space:]')"
NEW_AUDIT="$(state audit)"
check "2e alpha.90 knows the half-written event" "yes" "$(val "$NEW_AUDIT" half_known)"
check "2f and still knows the old one" "yes" "$(val "$NEW_AUDIT" changed_known)"

# ================================================ the real transfer, both ways
say ""
say "=== 3 · one ownership transfer, on real WordPress ==="
ARR="$(state arrange)"
check "3a the fixture is in place" "yes" "$(val "$ARR" arranged)"
ROW="$(val "$ARR" row)"
WC="$(val "$ARR" wc)"
say "row=$ROW wc=$WC"
BEFORE="$(state rows)"
check "3b it starts observed" "observed" "$(val "$BEFORE" owner)"
check "3c with no stamp on the post" "__absent__" "$(val "$BEFORE" stamp)"
check "3d and exactly one product carries that title" "1" "$(val "$BEFORE" posts_named)"
PRICE_BEFORE="$(val "$BEFORE" price)"
STATUS_BEFORE="$(val "$BEFORE" post_status)"

TAKE="$(state take)"
note "3e take" "$(printf '%s' "$TAKE" | tr '\n' ' ')"
check "3f the transfer succeeds" "ownership_taken" "$(val "$TAKE" code)"
MID="$(state rows)"
check "3g the row says marketplace" "marketplace" "$(val "$MID" owner)"
check "3h the post carries OUR row id" "$ROW" "$(val "$MID" stamp)"
check "3i and the status it was found in" "$STATUS_BEFORE" "$(val "$MID" stamp_from)"
check "3j still exactly one product with that title" "1" "$(val "$MID" posts_named)"
check "3k the price was not touched" "$PRICE_BEFORE" "$(val "$MID" price)"
check "3l nor the post status" "$STATUS_BEFORE" "$(val "$MID" post_status)"

BACK="$(state give-back)"
note "3m give back" "$(printf '%s' "$BACK" | tr '\n' ' ')"
check "3n the hand-back succeeds" "ownership_given_back" "$(val "$BACK" code)"
AFTER="$(state rows)"
check "3o the row is observed again" "observed" "$(val "$AFTER" owner)"
check "3p the stamp is gone" "__absent__" "$(val "$AFTER" stamp)"
check "3q so is the remembered status" "__absent__" "$(val "$AFTER" stamp_from)"
check "3r and STILL exactly one product — no duplicate" "1" "$(val "$AFTER" posts_named)"
check "3s the price survived the round trip" "$PRICE_BEFORE" "$(val "$AFTER" price)"
check "3t and so did the post status" "$STATUS_BEFORE" "$(val "$AFTER" post_status)"

AUD="$(state audit)"
check "3u two ownership rows were audited" "2" "$(val "$AUD" changed_rows)"
check "3v and no half-written row, because nothing was half written" "0" "$(val "$AUD" half_rows)"

# ===================================================== rollback to alpha.89
say ""
say "=== 4 · back to alpha.89 ==="
install_zip "$OLD_ZIP"
check "4a the structure is untouched by the rollback" "36" "$(schema)"
admin_request >>"$RUN" 2>&1
check "4b and the next request runs nothing" "36" "$(schema)"
ROLLED="$(state rows)"
check "4c the fixture row reads the same" "observed" "$(val "$ROLLED" owner)"
check "4d and still one product" "1" "$(val "$ROLLED" posts_named)"
BACK_AUDIT="$(state audit)"
note "4e the cost of the rollback" "half_known=$(val "$BACK_AUDIT" half_known) — alpha.89 stops naming the half-written outcome"
check "4f the audit rows already written are still readable" "2" "$(val "$BACK_AUDIT" changed_rows)"

# ===================================================== and forward again
say ""
say "=== 5 · forward to alpha.90 once more ==="
install_zip "$NEW_ZIP"
admin_request >>"$RUN" 2>&1
check "5a structure 36, still" "36" "$(schema)"
check "5b and the event is known again" "yes" "$(val "$(state audit)" half_known)"

say ""
say "checks: $((PASS + FAIL))  pass: $PASS  fail: $FAIL"
[[ "$FAIL" == "0" ]] || exit 1
