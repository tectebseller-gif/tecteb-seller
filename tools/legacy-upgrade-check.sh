#!/usr/bin/env bash
# alpha.25 makes the product. A manager edits it. alpha.29 takes over.
#
# The two defects this measures are both about products that ALREADY EXIST,
# and a test that starts by creating one cannot see either of them: the
# product it creates carries the very stamps whose absence is the problem. So
# nothing here is simulated. The previous package is installed from
# `dist/`, it projects the product with its own code, the manager edits that
# product in WooCommerce, and only then is the new package put in place.
#
# It ends by doing the same run with the guard reverted to the previous rule,
# and REQUIRES that run to destroy the manager's text. A check that passes
# either way is not a check.
#
#   bash tools/legacy-upgrade-check.sh docs/evidence/legacy-upgrade
set -u
OUT="${1:-docs/evidence/legacy-upgrade}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$WPROOT/wp-content/plugins"
OLD_ZIP="${TMC_OLD_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.25.zip}"
NEW_ZIP="${TMC_NEW_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.30.zip}"

mkdir -p "$OUT"
LOG="$OUT/legacy-upgrade-check.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-56s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-56s' "$name") expected=$expected actual=$actual"
  fi
}

for zip in "$OLD_ZIP" "$NEW_ZIP"; do
  [ -f "$zip" ] || { say "missing package: $zip"; exit 2; }
done

install_zip() {
  rm -rf "$PLUGINS/tecteb-marketplace-core"
  (cd "$PLUGINS" && unzip -q "$1" -d .)
}
state() {
  # `wp eval-file` reads from the WordPress root, not from this repository —
  # a stale copy there answers a new command with «usage:», which reads like
  # a missing feature rather than an old file. Copy every time.
  cp "$REPO/tools/legacy-ownership-state.php" "$WPROOT/legacy-ownership-state.php"
  (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file legacy-ownership-state.php "$@" 2>/dev/null)
}
field() { printf '%s\n' "$1" | grep -o "$2=[^ ]*" | head -1 | cut -d= -f2-; }

# =========================================================== the real upgrade
run_upgrade() {
  local label="$1"

  say ""
  say "=== $label: the product is made by the previous package ==="
  install_zip "$NEW_ZIP"                     # only to create the ROW
  SEED="$(state seed)"
  say "$SEED"
  PRODUCT="$(field "$SEED" product)"
  [ -n "$PRODUCT" ] || { say "seed failed"; return 1; }

  # The WooCommerce product — the thing that carries the stamps, or does not
  # — is created by the OLD package, with the old package's own code.
  install_zip "$OLD_ZIP"
  OLDV="$(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root plugin get tecteb-marketplace-core --field=version 2>/dev/null)"
  PROJECTED="$(state project "$PRODUCT")"
  say "$PROJECTED"
  WC="$(field "$PROJECTED" wc)"

  say ""
  say "--- the manager edits it in WooCommerce, before any upgrade ---"
  EDITED="$(state manager-edit "$PRODUCT")"
  say "$EDITED"
  BEFORE="$(state read "$PRODUCT")"
  say "$BEFORE"

  # What the manager put there, captured before the upgrade so the assertions
  # below compare against a recorded fact rather than a re-derived guess.
  M_TITLE="$(field "$BEFORE" title)"
  M_SHORT="$(field "$BEFORE" short)"
  M_LONG="$(field "$BEFORE" long)"
  M_IMAGES="$(field "$BEFORE" images)"
  M_SEO="$(field "$BEFORE" seo_title)"

  say ""
  say "--- upgrade, then the vendor saves and it is synced ---"
  install_zip "$NEW_ZIP"
  NEWV="$(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root plugin get tecteb-marketplace-core --field=version 2>/dev/null)"
  say "installed: $OLDV -> $NEWV  (product=$PRODUCT wc=$WC)"
  say "$(state vendor-save "$PRODUCT")"
  AFTER="$(state read "$PRODUCT")"
  say "$AFTER"
  say "$(state fields "$PRODUCT")"

  A_TITLE="$(field "$AFTER" title)"
  A_SHORT="$(field "$AFTER" short)"
  A_LONG="$(field "$AFTER" long)"
  A_IMAGES="$(field "$AFTER" images)"
  A_SEO="$(field "$AFTER" seo_title)"

  LAST_TITLE="$A_TITLE"; LAST_IMAGES="$A_IMAGES"
  EXPECT_TITLE="$M_TITLE"; EXPECT_SHORT="$M_SHORT"; EXPECT_LONG="$M_LONG"
  EXPECT_IMAGES="$M_IMAGES"; EXPECT_SEO="$M_SEO"
  ACTUAL_SHORT="$A_SHORT"; ACTUAL_LONG="$A_LONG"; ACTUAL_SEO="$A_SEO"
  UPGRADE_PRODUCT="$PRODUCT"
  return 0
}

run_upgrade "guard in place" || exit 2

check "the manager's title survived the upgrade"        "$LAST_TITLE"    "$EXPECT_TITLE"
check "and the short description"                       "$ACTUAL_SHORT"  "$EXPECT_SHORT"
check "and the full description"                        "$ACTUAL_LONG"   "$EXPECT_LONG"
check "the featured picture and gallery order survived" "$LAST_IMAGES"   "$EXPECT_IMAGES"
check "and the SEO fields were never touched"           "$ACTUAL_SEO"    "$EXPECT_SEO"

# The fields must say «unknown» rather than picking a side, and must ask.
FIELDS="$(state fields "$UPGRADE_PRODUCT")"
for f in title short_description description images; do
  check "$f is unsettled and asks" "$(field "$FIELDS" "$f")" "unknown/asks"
done

say ""
say "--- the manager settles the product, and it stops asking ---"
say "$(state settle "$UPGRADE_PRODUCT" keep)"
SETTLED="$(state fields "$UPGRADE_PRODUCT")"
say "$SETTLED"
check "after «نسخهٔ ووکامرس بماند» nothing is unsettled" \
  "$(printf '%s' "$SETTLED" | grep -c 'unknown' || true)" "0"
# And it stops ASKING, which is not the same sentence. `description` is the
# field that proves it: the projector builds that text, so the two sides go
# on differing after the decision — and a question that survives its own
# answer is one people learn to click past.
check "and nothing is still asking" \
  "$(printf '%s' "$SETTLED" | grep -c '/asks' || true)" "0"
KEPT="$(state read "$UPGRADE_PRODUCT")"
check "and the record now agrees with the shop" \
  "$(field "$KEPT" record_title)" "$(field "$KEPT" title)"
check "including which picture is featured" \
  "$(field "$KEPT" record_main)" \
  "$(printf '%s' "$(field "$KEPT" images)" | sed 's/^main://; s/|.*//')"

say ""
say "--- a genuinely new product is still filled in normally ---"
install_zip "$NEW_ZIP"
FRESH="$(state seed)"
FRESH_ID="$(field "$FRESH" product)"
say "$(state project "$FRESH_ID")"
FRESH_READ="$(state read "$FRESH_ID")"
say "$FRESH_READ"
check "a new product gets the vendor's title" "$(field "$FRESH_READ" title)" "محصول_نسخهٔ_قدیمی"
FRESH_FIELDS="$(state fields "$FRESH_ID")"
say "$FRESH_FIELDS"
check "and nothing about it is unsettled" \
  "$(printf '%s' "$FRESH_FIELDS" | grep -c 'unknown' || true)" "0"

# ============================================================ falsification
say ""
say "=== the same run with the guard reverted to the previous rule ==="
install_zip "$NEW_ZIP"
# `alpha.29` moved the WRITE decision out of `ProjectedFieldOwnership` and
# into the pure merge rule, so this is the file the guard now lives in. The
# guard itself is unchanged in meaning: no stamp ⇒ nobody knows ⇒ write
# nothing.
MERGE="$PLUGINS/tecteb-marketplace-core/src/Modules/Product/Domain/FieldMerge.php"
python3 - "$MERGE" <<'PYEOF'
import sys, pathlib
path = pathlib.Path(sys.argv[1])
src = path.read_text()
# The `if` now carries a paragraph of its own explaining why a recorded
# agreement does not settle a field older than the stamps, so the needle is the
# ANSWER rather than the whole block — and at this indentation it appears once.
needle = """            return self::UNSETTLED;
        }"""
if src.count(needle) != 1:
    sys.stderr.write("guard line not found exactly once\n")
    sys.exit(2)
# Exactly what alpha.26 did: an absent stamp meant «ours».
path.write_text(src.replace(needle, """            return self::WRITE;
        }""", 1))
PYEOF
if [ $? -ne 0 ]; then
  check "the guard line was found and reverted" "no" "yes"
else
  say "reverted: an absent stamp means «ours» again"
  # Rebuild the same situation and see what the old rule does to it.
  SEED2="$(state seed)"
  P2="$(field "$SEED2" product)"
  install_zip "$OLD_ZIP"
  state project "$P2" >/dev/null
  state manager-edit "$P2" >/dev/null
  B2="$(state read "$P2")"
  MT2="$(field "$B2" title)"; MI2="$(field "$B2" images)"
  install_zip "$NEW_ZIP"
  # The SECOND patch, and the one the measurement below depends on: the two
  # `install_zip` calls above have replaced the whole plugin directory, so the
  # first patch is long gone. It counts its needle and FAILS LOUDLY on a miss —
  # a silent no-op here would leave the guard in place and report «the old rule
  # kept the manager's text», which is the one answer this script must never
  # give by accident.
  if ! python3 - "$MERGE" <<'PYEOF'
import sys, pathlib
path = pathlib.Path(sys.argv[1])
src = path.read_text()
needle = """            return self::UNSETTLED;
        }"""
if src.count(needle) != 1:
    sys.stderr.write("guard line not found exactly once in the reinstalled package\n")
    sys.exit(2)
path.write_text(src.replace(needle, """            return self::WRITE;
        }""", 1))
PYEOF
  then
    check "the guard line was found in the reinstalled package" "no" "yes"
  fi
  state vendor-save "$P2" >/dev/null
  A2="$(state read "$P2")"
  say "$A2"
  check "without the guard the manager's title is destroyed" \
    "$([ "$(field "$A2" title)" = "$MT2" ] && echo kept || echo destroyed)" "destroyed"
  check "and the picture arrangement with it" \
    "$([ "$(field "$A2" images)" = "$MI2" ] && echo kept || echo destroyed)" "destroyed"
fi

# The installed package is left as the delivered one, and that is CHECKED.
install_zip "$NEW_ZIP"
check "the installed plugin is the delivered package" \
  "$(grep -c 'return self::UNSETTLED;' "$MERGE" || true)" "1"
rm -f "$WPROOT/legacy-ownership-state.php"

say ""
say "checks: $((pass+fail))  pass: $pass  fail: $fail"
[ "$fail" -eq 0 ]
