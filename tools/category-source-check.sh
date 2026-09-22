#!/usr/bin/env bash
# Does the vendor's «دسته» really come from WooCommerce, and does the product
# really land in the term that was chosen?
#
# Measured on the demo WordPress, through WP-CLI — the SITE's own tool, not
# ours. Nothing here loads the plugin, so there is no seeding script of ours
# to trust: the categories are created the way the owner's team creates them,
# and the answer is read back from `wp_term_relationships`.
#
# Three shapes, because all three exist on the owner's shop and each one used
# to be a way to lose: a category three levels deep, a category with no
# products, and a category with no spec template.
#
#   bash tools/category-source-check.sh docs/evidence/category-source
set -uo pipefail

OUT="${1:-docs/evidence/category-source}"
ROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
WP="${TMC_WP:-/usr/local/bin/wp} --allow-root --path=${ROOT}"
mkdir -p "$OUT"
LOG="${OUT}/checks.txt"
: > "$LOG"

pass=0; fail=0
check() {                    # check <name> <actual> <expected>
  if [ "$2" = "$3" ]; then
    printf 'ok:   %-58s %s\n' "$1" "$2" | tee -a "$LOG"; pass=$((pass+1))
  else
    printf 'FAIL: %-58s expected=%s actual=%s\n' "$1" "$3" "$2" | tee -a "$LOG"; fail=$((fail+1))
  fi
}
note() { printf '      %s\n' "$1" | tee -a "$LOG"; }

# --- refuse to run anywhere but the disposable demo -------------------------
HOME_URL="$($WP option get home 2>/dev/null)"
case "$HOME_URL" in
  http://127.0.0.1*|http://localhost*) : ;;
  *) echo "refused: home is ${HOME_URL}, not the local demo" >&2; exit 2 ;;
esac

# --- a three-level tree, built with the site's own command ------------------
term() {                     # term <name> <slug> <parent-id> -> prints term id
  local id
  id="$($WP term list product_cat --slug="$2" --field=term_id --format=ids 2>/dev/null | tr -d '[:space:]')"
  if [ -z "$id" ]; then
    id="$($WP term create product_cat "$1" --slug="$2" --parent="$3" --porcelain 2>/dev/null | tr -d '[:space:]')"
  fi
  echo "$id"
}

MEDICAL="$(term 'تجهیزات پزشکی' tmc-demo-medical 0)"
ANES="$(term 'بیهوشی و تنفسی' tmc-demo-anesthesia "$MEDICAL")"
AMBO="$(term 'آمبوبگ' tmc-demo-ambobag "$ANES")"
ACC_A="$(term 'لوازم جانبی' tmc-demo-accessories-anes "$ANES")"
URO="$(term 'اورولوژی' tmc-demo-urology "$MEDICAL")"
ACC_B="$(term 'لوازم جانبی' tmc-demo-accessories-uro "$URO")"
note "terms: medical=${MEDICAL} anesthesia=${ANES} ambobag=${AMBO} acc-anes=${ACC_A} urology=${URO} acc-uro=${ACC_B}"

check "a three-level category exists" \
  "$($WP term get product_cat "$AMBO" --field=parent 2>/dev/null | tr -d '[:space:]')" "$ANES"
check "its own parent has a parent" \
  "$($WP term get product_cat "$ANES" --field=parent 2>/dev/null | tr -d '[:space:]')" "$MEDICAL"
check "the deep leaf has no products at all" \
  "$($WP term get product_cat "$AMBO" --field=count 2>/dev/null | tr -d '[:space:]')" "0"
check "two different branches end in the same name" \
  "$($WP term list product_cat --name='لوازم جانبی' --format=count 2>/dev/null | tr -d '[:space:]')" "2"

# No template is created for ANY of them: «نبود الگو، دسته را پنهان نکند».
PREFIX="$($WP db prefix 2>/dev/null | tr -d '[:space:]')"
check "no spec template exists for these categories" \
  "$($WP db query "SELECT COUNT(*) FROM ${PREFIX}tmc_spec_templates WHERE category_key IN ('${AMBO}','${ACC_A}','${ACC_B}')" --skip-column-names 2>/dev/null | tr -d '[:space:]')" \
  "0"

# --- what the vendor's form is offered --------------------------------------
TOTAL="$($WP term list product_cat --format=count 2>/dev/null | tr -d '[:space:]')"
note "product_cat terms on this site: ${TOTAL}"
check "the taxonomy is not empty" "$([ "${TOTAL:-0}" -gt 0 ] && echo yes || echo no)" "yes"

# --- the proof: the product carries the term the vendor chose ---------------
PRODUCT_ID="${TMC_PRODUCT_ID:-}"
EXPECT_TERM="${TMC_EXPECT_TERM:-}"
if [ -n "$PRODUCT_ID" ] && [ -n "$EXPECT_TERM" ]; then
  ACTUAL="$($WP post term list "$PRODUCT_ID" product_cat --format=ids 2>/dev/null | tr -d '[:space:]')"
  note "product ${PRODUCT_ID} carries product_cat ids: ${ACTUAL:--}"
  check "the WooCommerce product is filed under the chosen term" "$ACTUAL" "$EXPECT_TERM"
  check "and under exactly one term, not a duplicate beside it" \
    "$($WP post term list "$PRODUCT_ID" product_cat --format=count 2>/dev/null | tr -d '[:space:]')" "1"
  check "no tmc- category was invented for it" \
    "$($WP post term list "$PRODUCT_ID" product_cat --fields=slug --format=csv 2>/dev/null | grep -c '^tmc-[a-z]' || true)" "0"
else
  note "TMC_PRODUCT_ID / TMC_EXPECT_TERM not given — the projection half is Not Run"
fi

printf '\npass=%d fail=%d\n' "$pass" "$fail" | tee -a "$LOG"
[ "$fail" -eq 0 ]
