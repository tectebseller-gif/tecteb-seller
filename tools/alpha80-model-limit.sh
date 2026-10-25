#!/usr/bin/env bash
# §0 of the category-relationship round: MEASURE the one-key model limit on
# `alpha.80`'s own installed bytes, before a line of production code is written.
#
# The owner made this a precondition: «محدودیت مدل و راه حفظ ارتباط‌های موجود را
# پیش از پیاده‌سازی مشخص کن.» So nothing here is reasoned from reading the
# source; every number comes from a real WooCommerce product with real terms.
#
# ⛔ Ordering is not cosmetic. `take-then-accept` DELETES the product's extra
# term relationships — that is the finding — so it runs LAST, and the fixture
# is regenerated first. The earlier run of this evidence re-measured AFTER that
# verb and reported `wc_term_count=1` / `terms_not_recorded_anywhere=0`: true
# about the product as it then stood, and nothing at all about the defect
# (`alpha.45`'s rule, in a new place).
#
# Read-only about staging and production: it refuses to run anywhere but the
# disposable site, and the probe refuses again on its own.
#
#   WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#     bash tools/alpha80-model-limit.sh docs/evidence/alpha81-model-limit
set -euo pipefail

OUT="${1:-docs/evidence/alpha81-model-limit}"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROBE="$REPO/tests/Support/alpha80-probe.php"

W=("$PHPBIN" "$WPCLI" --allow-root "--path=$WP_PATH")
db="$("${W[@]}" eval 'echo DB_NAME;' 2>/dev/null || echo '?')"
case "$db" in
  tmc_wp_test|tmc_wp_demo) ;;
  *) echo "refused: DB_NAME=$db is not a disposable database" >&2; exit 1 ;;
esac

mkdir -p "$OUT"
report="$OUT/before-alpha80.txt"
probe() { "${W[@]}" eval-file "$PROBE" "$@"; }

{
  echo "# §0 model-limit evidence, measured on the INSTALLED alpha.80 bytes."
  echo "# One fixture generation; the destructive verb runs last."
  echo "# date=$(date -u +%Y-%m-%dT%H:%M:%SZ)  db=$db"
  "${W[@]}" plugin list --format=csv 2>/dev/null | grep -E '^(tecteb-marketplace-core|woocommerce),' | sed 's/^/# plugin: /'

  echo; echo "## fixture: a fresh vendor, six terms, five products"
  probe reset >/dev/null; probe arrange; echo; probe intake

  echo; echo "## 1. first-wins — the intake narrows three categories to one"
  probe first-wins

  echo; echo "## 2. multi-detail — what a comma key resolves to, and the pre-accept verdict"
  probe multi-detail

  echo; echo "## 3. waiting — alpha.80's category-mapping path does not cover these rows"
  probe waiting

  echo; echo "## 4. search — the reported defect, against the real directory"
  probe search

  echo; echo "## 5. accept-drops — an observed row refuses the write, so nothing is lost"
  probe accept-drops

  echo; echo "## 6. project-drops — an ORDINARY projection holds it instead of writing"
  echo "#    (this verb takes ownership, so the fixture is regenerated before step 7)"
  probe project-drops

  echo; echo "## 7. take-then-accept — DESTRUCTIVE, and the finding itself"
  probe reset >/dev/null; probe arrange >/dev/null; probe intake >/dev/null
  probe take-then-accept
} >"$report" 2>&1

echo "wrote $report"
fail=0
check() { if grep -qF "$2" "$report"; then echo "ok   $1"; else echo "FAIL $1 (expected: $2)"; fail=$((fail + 1)); fi; }

check "intake stores the first of three terms"        "stored_is_the_first_term=yes"
check "two term relationships are recorded nowhere"   "terms_not_recorded_anywhere=2"
check "WooCommerce's Uncategorized is stored as real" "row_none_category_key=15"
# ⛔ Not written as literal term ids. Every `arrange` creates new terms, so a
# hand-typed id answers about the PREVIOUS run (`alpha.34`). These two are
# derived from the ids this run actually produced.
multi="$(sed -n 's/^multi_terms=//p' "$report" | head -1)"
expect_comma="find_comma=$(echo "$multi" | tr -d '[]') => __null__"
# ⛔ From step 7's OWN `terms_before`, not from step 2: that step
# regenerates the fixture, so the ids differ, and a value carried over from
# earlier in the same report is the `alpha.34` mistake one file further in.
last="$(sed -n '/^## 7\./,$p' "$report" | sed -n 's/^terms_before=//p' | head -1)"
expect_lost="terms_lost=$(echo "$last" | sed 's/^\[[0-9]*,/[/')"
check "a comma key resolves to nothing"               "$expect_comma"
check "an observed row refuses the category write"    "accept_outcome=storage_failed"
check "ownership is taken through the documented path" "take=ok"
check "the accepted write loses the other two terms"  "$expect_lost"
check "and it is silent about it"                     "other_categories_silently_dropped=yes"
check "a plain projection writes nothing"             "plain_projection_drops_them=no"
check "it holds the narrowed id as a proposal"        "held_as_a_proposal=yes"
check "and the field carries no ownership stamp"      "stamp_category=__empty__"
check "the three-word query finds nothing"            "q[ت۸۰ چسب تزریق پزشکی]_matched=0"
check "its first word finds the category meant"       "q[ت۸۰ چسب]_matched=1"
check "words spread over name and ancestry find none" "q[ت۸۰ تجهیزات چسب]_matched=0"
check "no row is waiting, so the mapping path is blind" "waiting_total=0"

echo "checks failed: $fail"
exit "$fail"
