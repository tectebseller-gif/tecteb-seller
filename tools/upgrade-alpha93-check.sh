#!/usr/bin/env bash
# alpha.93 → alpha.94: is this upgrade structural, and what does a rollback
# need to undo?
#
# ⛔ WHAT THIS IS AND IS NOT. It reads the two DELIVERED ZIPs and compares
# them. It does NOT install anything, does not touch a database, and cannot
# tell you how the upgrade behaves on a running site — that is `Not Run` and
# is reported as such, never as a pass (`alpha.70`).
#
# It exists because the honest claim for this round is narrow and checkable:
# the data structure does not move, so a rollback needs no database work. A
# sentence like that must be measured from the packages rather than typed
# from memory (`alpha.34`), and the numbers must come from the ZIPs rather
# than from the live checkout, or the answer drifts to «whatever the tree
# says today» (`alpha.56`).
set -uo pipefail
cd "$(dirname "$0")/.."
REPO="$PWD"
OUT="${1:-docs/evidence/upgrade-alpha94}"
mkdir -p "$OUT"
RUN="$OUT/run.txt"
: > "$RUN"

OLD_ZIP="$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.93.zip"
NEW_ZIP="$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.94.zip"

PASS=0
FAIL=0
say() { echo "$*" | tee -a "$RUN"; }
check() {
  local what="$1" want="$2" got="$3"
  if [ "$want" = "$got" ]; then
    PASS=$((PASS + 1)); say "PASS $what — $got"
  else
    FAIL=$((FAIL + 1)); say "FAIL $what — expected=$want actual=$got"
  fi
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

say "alpha.93 → alpha.94, read from the two delivered packages"
say "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
say ""

for z in "$OLD_ZIP" "$NEW_ZIP"; do
  [ -f "$z" ] || { say "FATAL missing $z"; exit 1; }
done
check "0a both packages exist" "yes" "yes"
say "    old: $(sha256sum "$OLD_ZIP" | cut -d' ' -f1)"
say "    new: $(sha256sum "$NEW_ZIP" | cut -d' ' -f1)"

unzip -q "$OLD_ZIP" -d "$WORK/old"
unzip -q "$NEW_ZIP" -d "$WORK/new"
OLD="$WORK/old/tecteb-marketplace-core"
NEW="$WORK/new/tecteb-marketplace-core"

# ---- 1. the declared structure version, from each package's own source ----
ver_of() { grep -oE 'TARGET = [0-9]+' "$1/src/Core/Migration/SchemaVersion.php" | grep -oE '[0-9]+'; }
OLD_V="$(ver_of "$OLD")"
NEW_V="$(ver_of "$NEW")"
say ""
check "1a alpha.93 declares structure" "38" "$OLD_V"
check "1b alpha.94 declares structure" "38" "$NEW_V"
check "1c the structure does not move" "same" "$([ "$OLD_V" = "$NEW_V" ] && echo same || echo moved)"

# ---- 2. the migration set is identical, file for file AND byte for byte ----
mig_list() { (cd "$1" && find src -path '*/Migrations/M00*.php' | sort); }
say ""
check "2a migration file count" "$(mig_list "$OLD" | wc -l)" "$(mig_list "$NEW" | wc -l)"
check "2b the same migration files" "identical" \
  "$(diff <(mig_list "$OLD") <(mig_list "$NEW") >/dev/null && echo identical || echo differs)"
MIG_DIFF=0
while IFS= read -r m; do
  if ! cmp -s "$OLD/$m" "$NEW/$m"; then
    MIG_DIFF=$((MIG_DIFF + 1)); say "    changed migration: $m"
  fi
done < <(mig_list "$NEW")
check "2c no migration's bytes changed" "0" "$MIG_DIFF"

# ---- 3. so a rollback needs no database work -------------------------------
say ""
say "NOTE 3a no step was added, so going back to alpha.93 runs no migration"
say "     and undoes none: MigrationRunner never lowers a structure version."
say "     The cost of a rollback this round is the GUARD plus two WRITES:"
say "     alpha.93 asks for a stock-management tick again on the rows"
say "     alpha.94 releases — and those rows leave the re-snapshot offer with"
say "     it, because risk_real is not in REFRESHABLE. And alpha.93's first"
say "     projection switches stock management back on and clears a sale"
say "     price nobody recorded, so a rollback AFTER a transfer restores the"
say "     very hazard this package removes. Nothing this round WRITES has to"
say "     be reversed; what comes back is a write."

# ---- 4. exactly which source files moved ----------------------------------
say ""
say "source files that differ between the two packages:"
CHANGED=0
while IFS= read -r f; do
  if ! cmp -s "$OLD/$f" "$NEW/$f" 2>/dev/null; then
    CHANGED=$((CHANGED + 1)); say "    $f"
  fi
done < <(cd "$NEW" && find src -name '*.php' | sort)
check "4a only the risk and projection paths moved" "3" "$CHANGED"

# ---- 5. and the gate's own text, in both packages --------------------------
say ""
PROJ="src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php"
check "5a alpha.93 writes the stock flag unconditionally" "yes" \
  "$(grep -qE '^            \$wcProduct->set_manage_stock\(true\);' "$OLD/$PROJ" && echo yes || echo no)"
check "5b alpha.94 writes it only when it creates the product" "yes" \
  "$(grep -qE '^                \$wcProduct->set_manage_stock\(true\);' "$NEW/$PROJ" \
     && ! grep -qE '^            \$wcProduct->set_manage_stock\(true\);' "$NEW/$PROJ" && echo yes || echo no)"
check "5c alpha.93 clears a sale price with no freshness question" "yes" \
  "$(grep -q 'private function applySale(mixed $wcProduct, Product $product): void' "$OLD/$PROJ" && echo yes || echo no)"
check "5d alpha.94 asks before clearing, and the two callers disagree" "yes" \
  "$(grep -q 'applySale(mixed $wcProduct, Product $product, bool $oursIsFresh)' "$NEW/$PROJ" \
     && grep -q 'applySale($wcProduct, $product, false)' "$NEW/$PROJ" \
     && grep -q 'applySale($wcProduct, $product, true)' "$NEW/$PROJ" && echo yes || echo no)"
check "5e alpha.94 releases manage_stock on a readable product" "yes" \
  "$(grep -q 'HELD_BY_EXISTING_PRODUCT' "$NEW/src/Modules/Migration/Domain/PreservationRisk.php" \
     && ! grep -q 'HELD_BY_EXISTING_PRODUCT' "$OLD/src/Modules/Migration/Domain/PreservationRisk.php" && echo yes || echo no)"
check "5f and alpha.94 keeps the four protected keys blocking" "yes" \
  "$(grep -qE "^        'manage_stock'," "$NEW/src/Modules/Migration/Domain/PreservationRisk.php" \
     && grep -qE "^        'catalog_visibility'," "$NEW/src/Modules/Migration/Domain/PreservationRisk.php" \
     && grep -qE "^        'price_scale'," "$NEW/src/Modules/Migration/Domain/PreservationRisk.php" \
     && grep -qE "^        'type'," "$NEW/src/Modules/Migration/Domain/PreservationRisk.php" && echo yes || echo no)"

say ""
say "⛔ Not Run, and not a pass: installing either package, running the"
say "   upgrade on a site, the batched re-snapshot, the transfer, and reading"
say "   the five product fields afterwards. This script compares packages."
say ""
say "checks: $((PASS + FAIL)) · passed: $PASS · failed: $FAIL"
[ "$FAIL" = "0" ] || exit 1
