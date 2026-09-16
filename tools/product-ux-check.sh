#!/usr/bin/env bash
# Autosave drafts, revision conflict and bulk operations, on the plugin
# installed from the ZIP.
#
# The assertion that matters most is the one about losing work: two people
# editing the same product must not silently overwrite each other, and the
# second one has to be TOLD rather than quietly winning.
#
#   tools/product-ux-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/product-ux}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
ux() { wp eval-file product-ux-state.php "$@"; }
# One field from one line. `cut -d' ' -f1` matters: a line like
# «ok=1 code=bulk_done» carries two fields, and without it the first one
# swallows the second and every comparison against it fails.
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
# Everything after the «=» to the end of the line. Needed where the value is a
# sentence: `field` stops at the first space, which is right for
# «ok=1 code=bulk_done» and wrong for a Persian phrase with a space in it.
rest() { grep -oE "^$1=.*$" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/product-ux-state.php "$WPROOT/" 2>/dev/null || true

IDS="$(ux ids)"
VENDOR="$(printf '%s' "$IDS" | field vendor)"
PRODUCTS="$(printf '%s' "$IDS" | field products)"
P1="$(printf '%s' "$PRODUCTS" | cut -d, -f1 | cut -d: -f1)"
P2="$(printf '%s' "$PRODUCTS" | cut -d, -f2 | cut -d: -f1)"
{ echo "=== the package these numbers come from ==="; wp plugin list --fields=name,status,version;
  echo; echo "vendor=$VENDOR products=$PRODUCTS"; } > "$EV/00-header.txt"

# ------------------------------------------------------------ 1. the draft
D="$(ux draft-put "$VENDOR" "$P1")"
printf '%s\n' "$D" > "$EV/01-draft.txt"
check "a draft is kept"                        "1"    "$(printf '%s' "$D" | field stored)"
check "and comes back with its text intact"    "عنوانِ نیمه‌تمام" "$(printf '%s' "$D" | rest read_back)"
check "carrying the revision it was typed against" "rev-a" "$(printf '%s' "$D" | field revision)"
# One owner. A shared draft would be the silent overwrite, one step earlier.
check "another member of the shop cannot see it" "no"  "$(printf '%s' "$D" | field visible_to_another_user)"

F="$(ux draft-forget "$VENDOR" "$P1")"
printf '%s\n' "$F" >> "$EV/01-draft.txt"
check "a successful save clears the draft"     "absent" "$(printf '%s' "$F" | field after)"
check "and clearing it twice is not an error"  "yes"    "$(printf '%s' "$F" | field forget_is_idempotent)"

# ------------------------------------------------------ 2. the conflict
C="$(ux conflict "$VENDOR" "$VENDOR" "$P1")"
printf '%s\n' "$C" > "$EV/02-revision-conflict.txt"
check "the first editor's save goes through"   "saved" "$(printf '%s' "$C" | field editor_a)"
# The one that matters: the second editor is REFUSED, not merged and not
# silently victorious. A merge would have to guess which of two prices is
# right, and a wrong guess about a price is money.
check "the second is refused, not merged"      "refused:stale_revision" "$(printf '%s' "$C" | field editor_b)"
check "and both stamps come back so a person can decide" "yes" "$(printf '%s' "$C" | field both_stamps_reported)"
# A form rendered by an older build carries no stamp; refusing it would break
# saving for anybody mid-edit across an upgrade.
check "a form with no stamp still saves"       "saved" "$(printf '%s' "$C" | field empty_stamp)"

# ---------------------------------------------------------- 3. the batch
B="$(ux bulk "$VENDOR" "$VENDOR" archive "$P1" "$P2" 99999)"
printf '%s\n' "$B" > "$EV/03-bulk.txt"
check "the batch runs"                         "1" "$(printf '%s' "$B" | field ok)"
check "the rows that could go, went"           "2" "$(printf '%s' "$B" | field succeeded)"
# A refused row stops neither the batch nor the report of itself.
check "the row that could not is counted"      "1" "$(printf '%s' "$B" | field refused)"
check "and named, with its own reason"         "1" "$(printf '%s' "$B" | grep -c '^row product=99999 ok=0 code=not_found')"
check "an empty selection has its own code"    "nothing_selected"   "$(printf '%s' "$B" | field empty)"
check "an unknown verb has its own code"       "unknown_bulk_action" "$(printf '%s' "$B" | field unknown_verb)"
# Refused, not truncated: half a batch is worse than none.
check "an oversized batch is refused outright" "bulk_too_large"      "$(printf '%s' "$B" | field too_large)"

# Put the shop back the way it was found.
ux bulk "$VENDOR" "$VENDOR" restore "$P1" "$P2" > "$EV/04-restored.txt"
check "the products are back"                  "2" "$(printf '%s' "$(cat "$EV/04-restored.txt")" | field succeeded)"

# ------------------------------------------- 4. the form carries what it needs
FORM="$(wp eval 'wp_set_current_user('"$VENDOR"');
$html = (string) file_get_contents(WP_PLUGIN_DIR . "/tecteb-marketplace-core/src/Modules/Product/Presentation/ProductFormView.php");
echo "revision_field=", (str_contains($html, "name=\"revision\"") ? "yes" : "no"), "\n";
echo "status_region=", (str_contains($html, "role=\"status\"") ? "yes" : "no"), "\n";
$js = (string) file_get_contents(WP_PLUGIN_DIR . "/tecteb-marketplace-core/assets/vendor/tmc-product-form.js");
echo "js_shipped=", ($js !== "" ? "yes" : "no"), "\n";
echo "beforeunload=", (str_contains($js, "beforeunload") ? "yes" : "no"), "\n";
echo "submit_clears_dirty=", (str_contains($js, "addEventListener(\x27submit\x27") ? "yes" : "no"), "\n";
echo "no_cdn=", (preg_match("#https?://#", $js) ? "NO" : "yes"), "\n";
$autosave = (string) file_get_contents(WP_PLUGIN_DIR . "/tecteb-marketplace-core/src/Modules/Product/Infrastructure/WordPress/ProductAutosave.php");
echo "nopriv_absent=", (str_contains($autosave, "wp_ajax_nopriv") ? "NO" : "yes"), "\n";
echo "superglobals_absent=", (str_contains($autosave, "\$_POST") ? "NO" : "yes"), "\n";')"
printf '%s\n' "$FORM" > "$EV/05-form-wiring.txt"
check "the form carries its revision"          "yes" "$(printf '%s' "$FORM" | field revision_field)"
check "and a polite status region"             "yes" "$(printf '%s' "$FORM" | field status_region)"
check "the script is in the package"           "yes" "$(printf '%s' "$FORM" | field js_shipped)"
check "it warns before an unsaved exit"        "yes" "$(printf '%s' "$FORM" | field beforeunload)"
check "and submitting is not treated as leaving" "yes" "$(printf '%s' "$FORM" | field submit_clears_dirty)"
check "no CDN, no external script"             "yes" "$(printf '%s' "$FORM" | field no_cdn)"
# `wp_ajax_` only: a logged-out caller is refused by WordPress before our code.
check "autosave has no logged-out variant"     "yes" "$(printf '%s' "$FORM" | field nopriv_absent)"
check "and reads through Request, not a superglobal" "yes" "$(printf '%s' "$FORM" | field superglobals_absent)"

printf '\nchecks: %d passed, %d failed\n' "$pass" "$fail" | tee "$EV/summary.txt"
[ "$fail" -eq 0 ]
