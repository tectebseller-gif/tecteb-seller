#!/usr/bin/env bash
# What this round starts from: the mapping can be DECIDED in code and cannot be
# decided by a person. Every line below is read from this checkout's own source
# — no WordPress, no database, nothing installed — so it answers about the
# bytes in `src/`, which are the bytes the ZIP carries.
#
# ⛔ It is a confirmation, not a test: it prints what it finds and fails only
# when a count it asserts is wrong. Run it BEFORE the fix to see the gap and
# AFTER to see it closed; the two runs are kept side by side in the evidence.
set -uo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-docs/evidence/alpha80-confirmation}"
mkdir -p "$OUT"
LABEL="${TMC_LABEL:-run}"
F="$OUT/$LABEL.txt"
: > "$F"
pass=0; fail=0
say() { printf '%s\n' "$*" | tee -a "$F" >/dev/null; }
check() { # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then pass=$((pass+1)); say "PASS $1 = $3";
  else fail=$((fail+1)); say "FAIL $1 expected=$2 actual=$3"; fi
}

say "== tecteb-marketplace-core: category mapping, read from src/ =="
say "commit: $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
say "version: $(grep -m1 '^ \* Version:' tecteb-marketplace-core.php | awk '{print $3}')"
say ""

say "-- 1. CategoryMap::save() exists --"
check "save_is_defined" 1 "$(grep -c 'public function save(array \$map): bool' src/Modules/Migration/Application/CategoryMap.php)"

say ""
say "-- 2. who calls it, anywhere under src/ --"
grep -rn 'categories\?*->save(\|categoryMap->save(\|CategoryMap::SETTING' src/ | tee -a "$F"
check "save_callers_in_src" "${TMC_EXPECT_SAVE_CALLERS:-0}" "$(grep -rn 'categories\?*->save(\|categoryMap->save(\|map->save(' src/ | wc -l | tr -d ' ')"

say ""
say "-- 3. every file under src/ that so much as names the class --"
grep -rln 'CategoryMap' src/ | tee -a "$F"
check "presentation_files_naming_it" "${TMC_EXPECT_PRESENTATION:-0}" "$(grep -rln 'CategoryMap' src/ | grep -c Presentation || true)"

say ""
say "-- 4. every admin action the migration module answers --"
grep -rhn "\$action === '" src/Modules/Migration/Presentation/Admin/*.php \
  | grep -o "=== '[a-z_]*'" | sort -u | tee -a "$F"
check "actions_mentioning_category" "${TMC_EXPECT_ACTIONS:-0}" "$(grep -rhn "\$action === '" src/Modules/Migration/Presentation/Admin/*.php | grep -o "=== '[a-z_]*'" | sort -u | grep -ci categ || true)"

say ""
say "-- 5. what the handover page does say about it --"
grep -n 'categories_mapped' src/Modules/Migration/Presentation/Admin/HandoverPage.php | tee -a "$F"

say ""
say "-- 6. and the second half: what the gate reads --"
sed -n "/'categories_mapped' =>/p" src/Modules/Migration/Application/MigrationCompleteness.php | tee -a "$F"
say "   (\`unmappedFor()\` asks DOKAN. It never looks at our own rows, so a"
say "    saved mapping empties that list and the gate closes while every"
say "    imported row still carries category_key = ''.)"
say "-- 7. does the gate read our own rows at all --"
check "gate_reads_our_own_uncategorised_rows" "${TMC_EXPECT_OWN_ROWS:-0}" \
  "$(grep -c 'products_without_category' src/Modules/Migration/Application/MigrationCompleteness.php || true)"

say ""
say "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
