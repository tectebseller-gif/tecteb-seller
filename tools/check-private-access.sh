#!/usr/bin/env bash
# Can a vendor's document be fetched over HTTP without signing in?
#
# The answer must be no for every URL a person could construct, and yes only
# through the guarded route. Both halves are asserted, plus a CONTROL: a
# canary file written inside uploads/ must be fetchable, otherwise a passing
# run would prove nothing except that the web server is asleep.
#
#   tools/check-private-access.sh <evidence-dir>
set -o pipefail
EV="${1:?evidence dir}"; mkdir -p "$EV"; EV="$(readlink -f "$EV")"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
DB="${DB:-tmc_wp_test}"
SOCK="${SOCK:-/run/mysqld/mysqld.sock}"
SCRATCH="${SCRATCH:-/tmp/claude-0/private-access}"
mkdir -p "$SCRATCH"

wpx() { "$PHPBIN" /usr/local/bin/wp --allow-root --path="$WPROOT" "$@" 2>/dev/null; }
dbq() { mariadb --socket="$SOCK" -uroot --default-character-set=utf8mb4 "$DB" -N -B -e "$1"; }

FAILED=0
pass() { echo "ok:   $*" | tee -a "$EV/private-access.log"; }
fail() { echo "FAIL: $*" | tee -a "$EV/private-access.log"; FAILED=$((FAILED+1)); }
: > "$EV/private-access.log"

STORED="$(dbq "SELECT stored_path FROM wp_tmc_vendor_documents ORDER BY id DESC LIMIT 1")"
[ -n "$STORED" ] || { echo "no stored document to test with — upload one first" >&2; exit 2; }
BASE="$(wpx eval 'echo (new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\PrivateUploadStorage())->baseDir();')"
UPLOADS="$(wpx eval 'echo wp_upload_dir()["basedir"];')"
FILE="$BASE/$STORED"
MARKER="tmc-canary-$(date -u +%s)"

{ echo "site         = $SITE"
  echo "stored_path  = $STORED"
  echo "storage_base = $BASE"
  echo "uploads_base = $UPLOADS"
  echo "abspath      = $(wpx eval 'echo ABSPATH;')"
  echo "document     = $FILE ($(wc -c < "$FILE" 2>/dev/null || echo missing) bytes)"
} > "$EV/private-access-environment.txt"

# --- the file is where we think it is, and not where it must not be ---------
[ -f "$FILE" ] && pass "the document exists at $FILE" || fail "no document at $FILE"
case "$BASE/" in
  "$UPLOADS"/*) fail "storage base is inside uploads: $BASE" ;;
  *) pass "storage base is outside uploads ($BASE)" ;;
esac
ABS="$(wpx eval 'echo rtrim(ABSPATH, "/");')"
case "$BASE/" in
  "$ABS"/*) fail "storage base is inside the WordPress directory: $BASE" ;;
  *) pass "storage base is outside the WordPress directory" ;;
esac
[ -z "$(find "$UPLOADS" -name '*.pdf' -o -name '*.jpg' -o -name '*.png' -path '*tmc-private*' 2>/dev/null)" ] \
  && pass "no document left behind in uploads/tmc-private" \
  || fail "documents still sit in uploads/tmc-private"

# --- CONTROL: something inside uploads IS reachable, so a 404 means something
CANARY="$UPLOADS/$MARKER.txt"
printf '%s' "$MARKER" > "$CANARY"
CODE=$(curl -s -o "$SCRATCH/canary.out" -w '%{http_code}' --max-time 15 "$SITE/wp-content/uploads/$MARKER.txt")
if [ "$CODE" = "200" ] && grep -q "$MARKER" "$SCRATCH/canary.out"; then
  pass "control: a file inside uploads/ IS served ($CODE) — the negative results below mean something"
else
  fail "control: uploads/ did not serve the canary ($CODE); the rest of this run proves nothing"
fi
rm -f "$CANARY"

# --- every URL a person could construct for the real document ---------------
try() {                       # <label> <url>
  local code body
  code=$(curl -s -o "$SCRATCH/try.out" -w '%{http_code}' --max-time 15 --path-as-is "$2")
  body=$(head -c 20 "$SCRATCH/try.out" | tr -d '\0')
  echo "$1 $2 -> $code" >> "$EV/private-access-attempts.txt"
  if [ "$code" = "200" ] && printf '%s' "$body" | grep -q '%PDF'; then
    fail "$1 returned the document ($code)"
  else
    pass "$1 refused ($code)"
  fi
}
: > "$EV/private-access-attempts.txt"
try "legacy uploads path"     "$SITE/wp-content/uploads/tmc-private/$STORED"
try "new name under uploads"  "$SITE/wp-content/uploads/tecteb-private/$STORED"
try "storage dir at web root" "$SITE/tecteb-private/$STORED"
try "traversal from uploads"  "$SITE/wp-content/uploads/../../../tecteb-private/$STORED"
try "traversal from root"     "$SITE/../tecteb-private/$STORED"
try "guessing under plugins"  "$SITE/wp-content/plugins/tecteb-marketplace-core/$STORED"

# --- the guarded route: the owner may, an anonymous visitor may not ---------
VENDOR_PASS="${TMC_VENDOR_PASS:-TmcVendor!2026}"
curl -s -c "$SCRATCH/vendor.jar" -o /dev/null --data-urlencode "log=${TMC_VENDOR_USER:-tmcvendor}" \
     --data-urlencode "pwd=$VENDOR_PASS" -d "wp-submit=Log+In&testcookie=1" "$SITE/wp-login.php"
curl -s -b "$SCRATCH/vendor.jar" -o "$SCRATCH/app.html" "$SITE/vendor/application/"
# WordPress escapes the ampersand as &#038; in attributes, not only as &amp;.
# Missing that leaves the nonce glued to the previous parameter, and the
# request then looks unauthenticated for the wrong reason.
LINK=$(grep -oE 'href="[^"]*tmc_doc=[0-9]+[^"]*"' "$SCRATCH/app.html" | head -1 \
       | sed 's/^href="//;s/"$//' | sed 's/&amp;/\&/g; s/&#0*38;/\&/g')
if [ -z "$LINK" ]; then
  fail "no download link on the applicant's own page"
else
  CODE=$(curl -s -b "$SCRATCH/vendor.jar" -o "$SCRATCH/owner.pdf" -w '%{http_code}' --max-time 20 "$LINK")
  if [ "$CODE" = "200" ] && head -c 5 "$SCRATCH/owner.pdf" | grep -q '%PDF'; then
    pass "the owner CAN download through the guarded route ($CODE)"
  else
    fail "the owner could not download their own document ($CODE)"
  fi
  CODE=$(curl -s -o "$SCRATCH/anon.out" -w '%{http_code}' --max-time 20 "$LINK")
  if [ "$CODE" = "200" ] && head -c 5 "$SCRATCH/anon.out" | grep -q '%PDF'; then
    fail "an anonymous visitor got the document through the guarded route"
  else
    pass "the same link signed out does not return the document ($CODE)"
  fi
  echo "$LINK" > "$EV/private-access-guarded-url.txt"
fi

echo "checks failed: $FAILED" | tee -a "$EV/private-access.log"
exit $(( FAILED > 0 ))
