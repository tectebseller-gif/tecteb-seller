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
check "the stamp is a counter, not a clock"     "yes"   "$(printf '%s' "$C" | field revision_is_a_counter)"
check "the first editor's save goes through"   "saved" "$(printf '%s' "$C" | field editor_a)"
# The one that matters: the second editor is REFUSED, not merged and not
# silently victorious. A merge would have to guess which of two prices is
# right, and a wrong guess about a price is money.
check "the second is refused, not merged"      "refused:stale_revision" "$(printf '%s' "$C" | field editor_b)"
check "the counter moved exactly once"         "yes" "$(printf '%s' "$C" | field counter_moved_once)"
# And this is WHY the stamp stopped being a timestamp. Two saves inside one
# second — retried until two of them are, so the result does not depend on
# where the second boundary happened to fall.
check "two saves can land inside one second"   "yes" "$(printf '%s' "$C" | field two_saves_in_one_second)"
check "…where a timestamp cannot tell them apart" "no"  "$(printf '%s' "$C" | field timestamp_could_tell_them_apart)"
check "…and the counter still can"                "yes" "$(printf '%s' "$C" | field counter_could_tell_them_apart)"
check "and both stamps come back so a person can decide" "yes" "$(printf '%s' "$C" | field both_stamps_reported)"
# A form rendered by an older build carries no stamp; refusing it would break
# saving for anybody mid-edit across an upgrade.
check "a form with no stamp still saves"       "saved" "$(printf '%s' "$C" | field empty_stamp)"
# Neither does a stamp in the PREVIOUS format lock the vendor out.
check "…and neither does last version's stamp" "saved" "$(printf '%s' "$C" | field old_format_stamp)"

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

# --------------------------------------------- 5. the forecast before the batch
# «۴۰ مورد انتخاب شد» hides the fact the vendor needs, and «ارسال برای بررسی»
# has no undo. So the preview must say what would happen — and must itself
# happen to nothing.
PV="$(ux preview "$VENDOR" "$VENDOR" archive "$P1" "$P2" 99999)"
printf '%s\n' "$PV" > "$EV/06-bulk-preview.txt"
check "the preview answers"                    "1" "$(printf '%s' "$PV" | field preview_ok)"
check "…with its own code"                     "bulk_previewed" "$(printf '%s' "$PV" | field code)"
check "it forecasts the rows that would go"    "2" "$(printf '%s' "$PV" | field forecast_go)"
check "…and the one that would not"            "1" "$(printf '%s' "$PV" | field forecast_stay)"
# The two measurements a returned value cannot fake.
check "the preview moved no product"           "0" "$(printf '%s' "$PV" | field rows_moved_by_preview)"
check "…and wrote no audit line"               "0" "$(printf '%s' "$PV" | field audit_lines_by_preview)"
# One decision, not two implementations.
check "every row's forecast matched the outcome" "1" "$(printf '%s' "$PV" | field forecast_matched_outcome)"
check "…and the batch then did what was shown"   "2" "$(printf '%s' "$PV" | field actual_go)"
# A row is named and dated, so the vendor recognises it without looking it up.
check "a forecast row carries its title"       "1" \
  "$(printf '%s' "$PV" | grep -m1 -oE 'forecast product='"$P1"'.* titled=[01]' | field titled)"
check "…and both ends of the move"             "draft:archived" \
  "$(printf '%s' "$PV" | grep -m1 'forecast product='"$P1" \
     | sed -E 's/.* from=([a-z_]+) to=([a-z_]*).*/\1:\2/')"
# The request-level refusals are the SAME on both sides.
check "an empty selection is refused alike"    "nothing_selected:nothing_selected"   "$(printf '%s' "$PV" | field refusal_empty)"
check "an unknown verb is refused alike"       "unknown_bulk_action:unknown_bulk_action" "$(printf '%s' "$PV" | field refusal_unknown)"
check "an oversized batch is refused alike"    "bulk_too_large:bulk_too_large"       "$(printf '%s' "$PV" | field refusal_too_large)"

ux bulk "$VENDOR" "$VENDOR" restore "$P1" "$P2" > "$EV/07-restored-after-preview.txt"
check "the products are back again"            "2" "$(printf '%s' "$(cat "$EV/07-restored-after-preview.txt")" | field succeeded)"

# ------------------------------------------- 6. an upload that fails, out loud
# Until this version a failed upload vanished: the save succeeded, no picture
# appeared, and nothing anywhere said why. Silence was the worst of the three
# possible answers.
IMG="$(ux image-refusals)"
printf '%s\n' "$IMG" > "$EV/08-image-refusals.txt"
check "the cap that applies is the smaller of the two" "1" "$(printf '%s' "$IMG" | field effective_is_the_smaller)"
# Each platform error gets its own name. A vendor told «فایلی انتخاب نشده بود»
# about a file they can see on their desktop tries the same file again.
check "php's own size refusal is «too large»"  "image_too_large" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal ini_size ' | field code)"
check "a half-arrived upload is not"           "transfer_failed" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal partial ' | field code)"
check "…nor is a missing temp directory"       "transfer_failed" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal no_tmp_dir ' | field code)"
check "no file at all is named as such"        "no_file" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal absent ' | field code)"
check "a zero-byte file has its own answer"    "empty_file" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal empty ' | field code)"
check "a file over the cap is refused"         "image_too_large" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal over_cap ' | field code)"
# The extension is never believed: the type is read from the bytes.
check "a .jpg whose bytes are PHP is refused"  "image_mime_not_allowed" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal php_in_a_jpg ' | field code)"
check "and an acceptable picture is accepted"  "-" \
  "$(printf '%s' "$IMG" | grep -m1 '^refusal good ' | field code)"
# Every refusal is said in Persian, and every one ends with something to DO.
check "no refusal reaches the vendor as a bare key" "0" \
  "$(printf '%s' "$IMG" | grep -c 'said=NOTHING')"
check "every refusal ends with an instruction" "0" \
  "$(printf '%s' "$IMG" | grep -E '^refusal ' | grep -c 'ends_with_instruction=0')"

# The form states the limit the server enforces, rather than a number typed
# into a sentence years ago.
UP="$(wp eval '
$view = (string) file_get_contents(WP_PLUGIN_DIR . "/tecteb-marketplace-core/src/Modules/Product/Presentation/ProductFormView.php");
echo "limit_on_the_control=", (str_contains($view, "data-max-bytes") ? "yes" : "no"), "\n";
echo "hint_is_computed=", (str_contains($view, "تا ۳ مگابایت") ? "NO" : "yes"), "\n";
$js = (string) file_get_contents(WP_PLUGIN_DIR . "/tecteb-marketplace-core/assets/vendor/tmc-product-form.js");
echo "preflight_shipped=", (str_contains($js, "preflightPicture") ? "yes" : "no"), "\n";')"
printf '%s\n' "$UP" > "$EV/09-upload-wiring.txt"
check "the file chooser carries the real cap"  "yes" "$(printf '%s' "$UP" | field limit_on_the_control)"
check "the hint quotes it rather than a literal" "yes" "$(printf '%s' "$UP" | field hint_is_computed)"
check "the browser-side check is in the package" "yes" "$(printf '%s' "$UP" | field preflight_shipped)"

printf '\nchecks: %d passed, %d failed\n' "$pass" "$fail" | tee "$EV/summary.txt"
[ "$fail" -eq 0 ]
