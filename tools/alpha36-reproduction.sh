#!/usr/bin/env bash
# The three notification defects, reproduced on the bytes that shipped as
# `alpha.36` — on a real WordPress, not in a double.
#
# The owner asked for it in those words: «ابتدا هر سه نقص را روی alpha.36
# بازتولید کن، سپس همان آزمون‌ها را روی اصلاح اجرا کن». So the plugin directory
# of the disposable install is filled with `alpha.36`'s payload — read out of
# git, never retyped — and `tools/alpha36-probe.php` is run against it. The
# probe lives OUTSIDE the payload, so both runs use the same file.
#
# **Why a probe rather than this round's PHPUnit tests.** They name classes
# `alpha.36` does not have (`DbReviewSeenStore`, `M0021ReviewSeen`,
# `ReviewQueueSql`, `findWithSubmission()`, `prepare()`). Against the old tree
# they would fatal at load, zero tests would run, and every «reproduced» line
# would be reporting that nothing ran — the `alpha.32` trap. The probe asks the
# API each build HAS and measures behaviour. Those PHPUnit tests are run
# against the FIXED tree by `run-all-tests.sh`; this script is the other half.
#
# The three defects:
#
#   1. The badge was built while the menu was built and the view was recorded
#      in the render callback — and core prints the menu between the two. So
#      the number on the screen that cleared the notification was the number
#      from before the visit.
#   2. The detail page read the product, the proposal and the token in three
#      statements, so a submission landing between the first and the last was
#      recorded as seen by somebody looking at the previous one.
#   3. The marks lived in one capped value, so a queue bigger than the cap
#      could not be read down to nought.
#
#   bash tools/alpha36-reproduction.sh docs/evidence/alpha36-reproduction
set -u
OUT="${1:-docs/evidence/alpha36-reproduction}"
BASE="${TMC_BASE_REF:-cbc3fd4}"          # the commit that built alpha.36
BULK="${TMC_BULK:-600}"
WP="${WP:-/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo}"
PLUGIN="${TMC_PLUGIN_DIR:-/home/user/wp-demo/wp-content/plugins/tecteb-marketplace-core}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"

mkdir -p "$OUT"
LOG="$OUT/alpha36-reproduction.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-62s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-62s' "$name") expected=$expected actual=$actual"
  fi
}
field() { printf '%s\n' "$1" | sed -n "s/.*[[:space:]]$2=\([^[:space:]]*\).*/\1/p" | head -1; }

# The payload, and only the payload. `tools/` is not in the install ZIP, so the
# probe is never part of what is swapped.
PAYLOAD="src assets languages readme.txt tecteb-marketplace-core.php uninstall.php"

probe() {
  cp tools/alpha36-probe.php /home/user/wp-demo/
  $WP eval-file /home/user/wp-demo/alpha36-probe.php "$@" 2>&1
}

say "base: $BASE ($(git log -1 --format=%s "$BASE" | cut -c1-58))"
INSTALLED="$($WP plugin get tecteb-marketplace-core --field=version)"
say "installed before this script: $INSTALLED"
check "the install starts on the build under test" \
  "$(grep -oP '(?<=^ \* Version: ).*' tecteb-marketplace-core.php | tr -d '\r')" "$INSTALLED"

# A snapshot of exactly what is on disk, so «put it back» is compared with the
# bytes this script found rather than with a commit — the `alpha.32` rule.
#
# ABSOLUTE, because the archive is written from inside the plugin directory: a
# relative path put the tar INSIDE the plugin, where the restore could not find
# it, and the hash check then compared one empty string with another and passed.
# A check that cannot fail has not passed.
SNAP="$(mktemp -d)/installed-before.tar"
( cd "$PLUGIN" && tar -cf "$SNAP" . )
SNAP_HASH="$(sha256sum "$SNAP" | cut -d' ' -f1)"
[ -n "$SNAP_HASH" ] || { say "FAIL: could not snapshot the installed plugin"; exit 2; }
# However this script ends — a failed check, a Ctrl-C, a fatal in a probe — the
# install goes back to what was there. A reproduction that leaves `alpha.36` on
# the disk is a reproduction that broke the next run.
restore() { [ -f "$SNAP" ] || return 0; for item in $PAYLOAD; do rm -rf "${PLUGIN:?}/$item"; done; tar -xf "$SNAP" -C "$PLUGIN"; }
trap restore EXIT

say ""
say "=== the plugin directory filled with alpha.36's payload ==="
for item in $PAYLOAD; do
  rm -rf "${PLUGIN:?}/$item"
done
git archive "$BASE" -- $PAYLOAD | tar -x -C "$PLUGIN"
OLD_VERSION="$($WP plugin get tecteb-marketplace-core --field=version)"
check "the install is now alpha.36" "$OLD_VERSION" "0.1.0-alpha.36"
# A tree that does not load would make every «reproduced» line below a report
# about nothing having run.
check "and the old tree loads" "$($WP eval 'echo "loaded";' 2>&1 | tail -1)" "loaded"
check "with no fatal in the probe's own bootstrap" \
  "$(probe badge | grep -c 'Fatal error')" "0"

BEFORE_BADGE="$(probe badge)"; say "  $BEFORE_BADGE"
BEFORE_SNAP="$(probe snapshot)"; say "  $BEFORE_SNAP"
BEFORE_CAP="$(probe cap "$BULK")"; say "  $BEFORE_CAP"
printf '%s\n%s\n%s\n' "$BEFORE_BADGE" "$BEFORE_SNAP" "$BEFORE_CAP" > "$OUT/before-the-fix.txt"

say ""
say "--- item 1 on alpha.36: the number the header printed ---"
b_before="$(field "$BEFORE_BADGE" before)"
b_after="$(field "$BEFORE_BADGE" after)"
check "1-1 the visit really lowered the count" \
  "$([ "${b_after:-0}" -lt "${b_before:-0}" ] && echo yes || echo no)" "yes"
check "1-2 but the label the header printed was the OLD number" \
  "$(field "$BEFORE_BADGE" header_label)" "$b_before"
check "1-3 and it only caught up after the page had rendered" \
  "$([ "$(field "$BEFORE_BADGE" render_label)" = "$b_before" ] && echo stale || echo fresh)" "stale"
check "1-4 the page did render, so this is not a report about an empty screen" \
  "$(field "$BEFORE_BADGE" page_rendered)" "yes"

say ""
say "--- item 2 on alpha.36: three reads, and a gap between them ---"
check "2-1 the detail path read the product and the token separately" \
  "$(field "$BEFORE_SNAP" reads)" "three"
check "2-2 the content on screen was the older one" "$(field "$BEFORE_SNAP" content)" "old"
check "2-3 and the identity recorded as seen was the NEWER one" \
  "$(field "$BEFORE_SNAP" recorded)" "current"
check "2-4 so the newer submission counted as read by somebody who never saw it" \
  "$(field "$BEFORE_SNAP" this_seen)" "yes"

say ""
say "--- item 3 on alpha.36: the cap ---"
c_seeded="$(field "$BEFORE_CAP" seeded)"
check "3-1 the queue really was bigger than the cap" \
  "$([ "${c_seeded:-0}" -gt 500 ] && echo yes || echo no)" "yes"
check "3-2 reading every page could not reach nought" \
  "$([ "$(field "$BEFORE_CAP" unseen_after)" -gt 0 ] && echo stuck || echo zero)" "stuck"
check "3-3 because the marks were capped at five hundred" "$(field "$BEFORE_CAP" marks)" "500"

say ""
say "=== alpha.37 put back, byte for byte ==="
check "the snapshot was not touched while it was being used" \
  "$(sha256sum "$SNAP" | cut -d' ' -f1)" "$SNAP_HASH"
restore
RESTORED="$($WP plugin get tecteb-marketplace-core --field=version)"
check "the install is the build under test again" "$RESTORED" "$INSTALLED"
# Stop here if it is not. Running the probes against a half-restored plugin
# produces ten failures about the restore and nothing about the fix.
[ "$RESTORED" = "$INSTALLED" ] || { say "FAIL: refusing to measure a half-restored install"; exit 2; }

AFTER_BADGE="$(probe badge)"; say "  $AFTER_BADGE"
AFTER_SNAP="$(probe snapshot)"; say "  $AFTER_SNAP"
AFTER_CAP="$(probe cap "$BULK")"; say "  $AFTER_CAP"
printf '%s\n%s\n%s\n' "$AFTER_BADGE" "$AFTER_SNAP" "$AFTER_CAP" > "$OUT/after-the-fix.txt"

say ""
say "--- item 1 after the fix ---"
a_before="$(field "$AFTER_BADGE" before)"
a_after="$(field "$AFTER_BADGE" after)"
check "1-5 the visit lowers the count" \
  "$([ "${a_after:-0}" -lt "${a_before:-0}" ] && echo yes || echo no)" "yes"
check "1-6 and the label the header prints is the NEW number" \
  "$(field "$AFTER_BADGE" header_label)" "$([ "${a_after:-0}" -eq 0 ] && echo none || echo "$a_after")"
check "1-7 the page still renders exactly once" "$(field "$AFTER_BADGE" page_rendered)" "yes"

say ""
say "--- item 2 after the fix ---"
check "2-5 the detail path reads the product and the token together" \
  "$(field "$AFTER_SNAP" reads)" "one"
check "2-6 the content on screen is the older one, as before" "$(field "$AFTER_SNAP" content)" "old"
check "2-7 and the identity recorded is the one that was SHOWN" \
  "$(field "$AFTER_SNAP" recorded)" "older"
check "2-8 so the newer submission is still a notification" \
  "$(field "$AFTER_SNAP" this_seen)" "no"

say ""
say "--- item 3 after the fix ---"
check "3-4 the same queue, the same size" "$(field "$AFTER_CAP" seeded)" "$c_seeded"
check "3-4b and it is still past the cap" \
  "$([ "$(field "$AFTER_CAP" queue)" -gt 500 ] && echo yes || echo no)" "yes"
check "3-5 reading every page reaches nought" "$(field "$AFTER_CAP" unseen_after)" "0"
check "3-6 and every waiting product has a mark, none dropped" \
  "$(field "$AFTER_CAP" marks)" "$(field "$AFTER_CAP" queue)"

say ""
say "checks: $((pass+fail))   passed: $pass   failed: $fail"
[ "$fail" -eq 0 ]
