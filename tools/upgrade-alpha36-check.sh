#!/usr/bin/env bash
# The owner's own upgrade: `alpha.36` installed, the new package dropped on top —
# the way back, the way forward again, and a migration that FAILS in the middle.
#
# «ارتقای مستقیم alpha.35 → alpha.37 و alpha.36 → alpha.37 از خودِ ZIP نهایی» و
# «اگر ساختار داده تغییر کند، مسیر شکست/ازسرگیری migration و محدودیت‌های بازگشت
# بررسی شود». Schema 20 → 21 adds ONE table — `tmc_review_seen` — and carries
# the `alpha.36` view marks into it from user meta.
#
# Five things this measures that an assertion in PHP cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing the
#    files is what an upload does, and it fires no activation hook at all;
#  - the carry-over reaches the marks that were really there, written by the OLD
#    build through its own code — not rows this script typed into a table;
#  - a migration that fails leaves the site on schema 20, which is `alpha.36`
#    and works, records WHY, and the next admin request finishes the job;
#  - going BACK to `alpha.36` needs no database work: the table is additive and
#    nothing older names it. What it DOES cost is stated and measured — the old
#    build reads its user meta again, so views recorded since the upgrade are
#    not in the number it shows;
#  - and the `alpha.35` path, where there are no marks at all to carry.
#
#   bash tools/upgrade-alpha36-check.sh docs/evidence/upgrade-alpha36
set -u
OUT="${1:-docs/evidence/upgrade-alpha36}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SITE="${TMC_DEMO_SITE:-http://127.0.0.1:8081}"
ADMIN="${TMC_DEMO_ADMIN:-tmcowner}"
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$WPROOT/wp-content/plugins"

# The two bases the owner named, and the NEWEST package in `dist/` — derived
# rather than written here. A hand-written default locked two upgrade scripts to
# `alpha.31` for three rounds and reported green checks about a package that was
# not being delivered (`alpha.34`).
newest_zip() {
  ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.*.zip 2>/dev/null \
    | sed 's/.*alpha\.\([0-9]*\)\.zip/\1 &/' | sort -n | tail -1 | cut -d' ' -f2-
}
NEW_ZIP="${TMC_NEW_ZIP:-$(newest_zip)}"
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.36.zip $REPO/dist/tecteb-marketplace-core-0.1.0-alpha.35.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha36-check.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-60s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-60s' "$name") expected=$expected actual=$actual"
  fi
}
for zip in $BASES "$NEW_ZIP"; do
  [ -f "$zip" ] || { say "missing package: $zip"; exit 2; }
done

wpx() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@"); }
dbq() { wpx db query "$1" --skip-column-names 2>/dev/null; }
# Replace the payload, then WAIT OUT the opcache revalidation window.
#
# **Why the wait is load-bearing.** The web server here is one long-lived PHP
# process with opcache on and `opcache.revalidate_freq=2`, so for up to two
# seconds after a swap it can serve cached opcodes for a file that has been
# replaced while a file that file references no longer exists. Measured: an
# `alpha.35` install answered wp-admin with
# «Uncaught Error: Class "…AdminNavigation" not found in MenuRegistrar.php»,
# and three checks reported a failure about a tree that was complete and
# correct on disk.
#
# This is not only a test-harness quirk: a real site replacing plugin files
# has the same window, which is why the upgrade order says to do it at a quiet
# moment (docs/upgrade-and-rollback.md). Here it is simply waited out, so what
# is measured afterwards is the build that is actually on disk.
install_zip() {
  rm -rf "$PLUGINS/tecteb-marketplace-core"
  (cd "$PLUGINS" && unzip -q "$1" -d .)
  local freq
  freq="$("$PHPBIN" -r 'echo (int) ini_get("opcache.revalidate_freq");' 2>/dev/null || echo 2)"
  sleep $(( freq + 1 ))
}
zip_version() {
  unzip -p "$1" 'tecteb-marketplace-core/tecteb-marketplace-core.php' \
    | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)[[:space:]]*$/\1/p' | head -1 | tr -d '\r'
}
# The schema number from the SOURCE, never written here.
NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
OLD_SCHEMA=$((NEW_SCHEMA - 1))
# And each base's OWN target, read out of its own ZIP.
#
# `NEW_SCHEMA - 1` is a guess about the base, and for `alpha.37 -> alpha.38` it
# is the wrong one: both builds target 21, so forcing the option to 20 and then
# asking the old build to serve a page let the OLD build's gate migrate to 21,
# and «replacing the files ran no migration» failed about a site that was
# behaving exactly as designed. A base's schema is a property of that base.
zip_schema() {
  unzip -p "$1" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' \
    | sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' | head -1
}
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
say "new package: $NEW_EXPECT (schema $OLD_SCHEMA -> $NEW_SCHEMA)"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] || { say "FAIL: could not read a version or a schema number"; exit 2; }

PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
SEEN="${PREFIX}tmc_review_seen"
has_table() {
  dbq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1'"
}
# Rows in the marks table — 0 when it is not there, never a dash. A reader that
# answers «-» makes every later comparison a string mismatch and buries the one
# number the stage is about.
seen_rows() {
  if [ "$(has_table "$SEEN")" = "1" ]; then dbq "SELECT COUNT(*) FROM \`$SEEN\`"; else echo 0; fi
}
# How many marks the OLD build holds, counted out of its own serialised value.
# Not through this repository's state tool: that tool is `alpha.37`'s and calls
# a method `alpha.36`'s store does not have, so against the old build it fatals
# and the count comes back empty — which is how four checks in the first run of
# this script compared one empty string with another.
legacy_marks() {
  wpx eval '$v = get_user_meta(1, "tmc_review_seen", true); echo is_array($v) ? count($v) : 0;' 2>/dev/null || echo 0
}
# One admin request to the OLD build's review list, which is how `alpha.36`
# records views: through its own code, in its own shape.
record_with_old_build() {
  curl -s -o /dev/null -w '%{http_code}' -b "$1" --max-time 120 \
       "$SITE/wp-admin/admin.php?page=tmc-product-review&status=submitted&per_page=100"
}

admin_session() {
  JAR="$(mktemp -d)/admin.jar"
  curl -s -c "$JAR" -o /dev/null --data-urlencode "log=$ADMIN" --data-urlencode "pwd=$ADMIN_PASS" \
       -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
  echo "$JAR"
}
# One wp-admin request, which is where the upgrade gate lives.
admin_request() {
  curl -s -o "${2:-/dev/null}" -w '%{http_code}' -b "$1" --max-time 120 \
       "$SITE/wp-admin/admin.php?page=tmc-dashboard"
}
# Only ever called while the NEW build is installed: it is `alpha.37`'s tool.
state() { cp "$REPO/tools/review-badge-state.php" "$WPROOT/"; wpx eval-file "$WPROOT/review-badge-state.php" "$@" 2>/dev/null; }
# `\(^\|[[:space:]]\)` and not `.*[[:space:]]`: the state tool prints
# `waiting=20` as the FIRST field of its line, and a pattern that demands a
# space before the key matches nothing for it — which made «below the queue» compare
# a number with an empty string.
field() { printf '%s\n' "$1" | sed -n "s/.*\(^\|[[:space:]]\)$2=\([^[:space:]]*\).*/\2/p" | head -1; }

# However this ends, the install goes back to the package under test: a check
# script that leaves an old build on the disk breaks the next run.
# However this ends, the site is left CONSISTENT, not merely on the new files.
#
# The first version wrote the option to `$NEW_SCHEMA` and stopped there, and a
# run that had dropped the marks table left «the schema says 21 and the table
# is not there» behind — which is a state no upgrade produces, and the next run
# measured six failures from it. A fixture that spends something gives it back:
# when the table is missing, the option is put one BELOW the target so the
# gate rebuilds it on the next admin request, rather than claiming work that
# was not done.
restore_new() {
  install_zip "$NEW_ZIP"
  if [ "$(has_table "$SEEN")" = "1" ]; then
    wpx option update tmc_schema_version "$NEW_SCHEMA" >/dev/null 2>&1
  else
    wpx option update tmc_schema_version "$((NEW_SCHEMA - 1))" >/dev/null 2>&1
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    curl -s -o /dev/null -b "${JAR:-/dev/null}" --max-time 120 \
      "$SITE/wp-admin/admin.php?page=tmc-dashboard" >/dev/null 2>&1 || true
  fi
}
trap restore_new EXIT

JAR="$(admin_session)"
check "the admin session really opened" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$SITE/wp-admin/")" "200"
[ -s "$JAR" ] || { say "FAIL: no login cookie — every later check would be about a login page"; exit 2; }

for BASE_ZIP in $BASES; do
  OLD_EXPECT="$(zip_version "$BASE_ZIP")"
  BASE_SCHEMA="$(zip_schema "$BASE_ZIP")"
  [ -n "$BASE_SCHEMA" ] || { say "FAIL: could not read the schema target out of $BASE_ZIP"; exit 2; }
  # Does this round add structure for THIS base? The answer decides what
  # stages 3 and 4 may claim, and it is read, not assumed.
  if [ "$BASE_SCHEMA" -lt "$NEW_SCHEMA" ]; then STRUCTURAL=yes; else STRUCTURAL=no; fi
  say ""
  say "================================================================"
  say "=== $OLD_EXPECT (schema $BASE_SCHEMA) -> $NEW_EXPECT (schema $NEW_SCHEMA) · structural=$STRUCTURAL"
  say "================================================================"

  # ---- stage 1: a site genuinely on the old build ------------------------
  install_zip "$BASE_ZIP"
  dbq "DROP TABLE IF EXISTS \`$SEEN\`" >/dev/null
  wpx user meta delete 1 tmc_review_seen >/dev/null 2>&1
  if [ "$STRUCTURAL" = "yes" ]; then
    wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
  else
    # This base already targets the new schema, so «a site genuinely on it»
    # means a site this base has MIGRATED ITSELF — built by its own gate, not
    # by a table this script typed. Built from one below, with one admin
    # request, which is exactly how a real install of it got there.
    wpx option update tmc_schema_version "$((BASE_SCHEMA - 1))" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    admin_request "$JAR" >/dev/null
  fi
  check "1 the install is $OLD_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "1b on schema $BASE_SCHEMA" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "1c the marks table is $([ "$STRUCTURAL" = "yes" ] && echo absent || echo present)" \
    "$(has_table "$SEEN")" "$([ "$STRUCTURAL" = "yes" ] && echo 0 || echo 1)"
  check "1d and the old build serves wp-admin" "$(admin_request "$JAR")" "200"

  # ---- stage 2: marks written BY THE OLD BUILD, through its own code -----
  #
  # Not rows typed into a table: the carry-over has to be measured against what
  # `alpha.36` really wrote, in the shape it really wrote it.
  check "2 the old build starts with no marks" "$(legacy_marks)" "0"
  check "2b it serves its own review list" "$(record_with_old_build "$JAR")" "200"
  OLD_MARKS="$(legacy_marks)"
  say "  the old build recorded $OLD_MARKS mark(s) in user meta, through its own code"
  check "2c and recorded views, which is what the carry-over has to find" \
    "$([ "${OLD_MARKS:-0}" -gt 0 ] && echo yes || echo no)" \
    "$([ "$OLD_EXPECT" = "0.1.0-alpha.36" ] && echo yes || echo no)"

  # ---- stage 3: the files replaced, and NOTHING else --------------------
  install_zip "$NEW_ZIP"
  check "3 the files are $NEW_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
  check "3b and replacing files ran no migration at all" \
    "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "3c the table is $([ "$STRUCTURAL" = "yes" ] && echo still absent || echo untouched)" \
    "$(has_table "$SEEN")" "$([ "$STRUCTURAL" = "yes" ] && echo 0 || echo 1)"

  # ---- stage 4: one admin request ---------------------------------------
  check "4 one wp-admin request is served" "$(admin_request "$JAR" "$OUT/after-upgrade-$OLD_EXPECT.html")" "200"
  check "4b the schema is $NEW_SCHEMA$([ "$STRUCTURAL" = "yes" ] && echo " (the gate moved it)" || echo " (nothing to move)")" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "4c the marks table exists" "$(has_table "$SEEN")" "1"
  check "4d keyed on (user_id, product_id)" \
    "$(dbq "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$SEEN' AND INDEX_NAME = 'PRIMARY'")" \
    "user_id,product_id"
  check "4e with microsecond precision on seen_at" \
    "$(dbq "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$SEEN' AND COLUMN_NAME = 'seen_at'")" \
    "datetime(6)"
  check "4f no migration error was recorded" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""
  NEW_ROWS="$(seen_rows)"
  say "  carried over: $OLD_MARKS mark(s) in meta -> $NEW_ROWS row(s) in $SEEN"
  check "4g every mark the old build held is a row now" "$NEW_ROWS" "$OLD_MARKS"
  check "4h and the user meta is LEFT in place, for the way back" \
    "$([ "$(legacy_marks)" -ge 0 ] 2>/dev/null && echo kept || echo gone)" "kept"

  # ---- stage 5: the notification works on the new build -----------------
  AFTER_VIEW="$(state report)"
  check "5 the new build answers the count" \
    "$([ -n "$(field "$AFTER_VIEW" unseen_ana)" ] && echo yes || echo no)" "yes"
  if [ "${OLD_MARKS:-0}" -gt 0 ]; then
    # The marks that were carried are REAL: the new build's count is lower than
    # the queue by exactly the number of waiting products it found marked.
    say "  new build: unseen_ana=$(field "$AFTER_VIEW" unseen_ana) waiting=$(field "$AFTER_VIEW" waiting)"
    check "5b and it is below the queue, because the carried marks count" \
      "$([ "$(field "$AFTER_VIEW" unseen_ana)" -lt "$(field "$AFTER_VIEW" waiting)" ] && echo yes || echo no)" "yes"
  else
    say "  5b not applicable on $OLD_EXPECT: it holds no marks to carry"
  fi

  # ---- stage 6: the migration FAILS, and resumes ------------------------
  #
  # A table of the right NAME and the wrong shape: `CREATE TABLE IF NOT EXISTS`
  # is satisfied by it and `verify()` finds a table, so the step gets as far as
  # the carry-over and the INSERT is what fails. That is the interesting
  # failure: the one that happens after something has already succeeded.
  wpx option update tmc_schema_version "$OLD_SCHEMA" >/dev/null
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  dbq "DROP TABLE IF EXISTS \`$SEEN\`" >/dev/null
  dbq "CREATE TABLE \`$SEEN\` (\`wrong\` INT NOT NULL PRIMARY KEY)" >/dev/null
  check "6 a malformed table of the same name is in the way" \
    "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$SEEN'")" "1"
  check "6b the admin request is still served" "$(admin_request "$JAR")" "200"
  check "6c the schema did NOT move" "$(wpx option get tmc_schema_version)" "$OLD_SCHEMA"
  check "6d and the reason was recorded, naming the step" \
    "$(wpx option get tmc_migration_last_error 2>/dev/null | grep -q '0021_review_seen' && echo yes || echo no)" "yes"

  # The obstacle removed — and the very next request DECLINES, on purpose.
  # `UpgradeGate::RETRY_COOLDOWN_SECONDS` is five minutes: a step that keeps
  # failing must not hammer the database on every admin request. Measured
  # rather than assumed, because a script that expected an immediate retry
  # would report this designed behaviour as a defect.
  dbq "DROP TABLE IF EXISTS \`$SEEN\`" >/dev/null
  check "6e the next request is served" "$(admin_request "$JAR")" "200"
  check "6f and within the retry cooldown it does NOT retry" \
    "$(wpx option get tmc_schema_version)" "$OLD_SCHEMA"
  check "6g with the failure still visible, not hidden" \
    "$(wpx option get tmc_migration_last_error 2>/dev/null | grep -q '0021_review_seen' && echo yes || echo no)" "yes"

  # Clearing the recorded failure is the one operator action, and it is what
  # the health page's own guidance amounts to. (Waiting out the five minutes
  # does the same thing without touching anything.)
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  check "6h then one request finishes the job" "$(admin_request "$JAR")" "200"
  check "6i and the schema is $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "6j with every mark carried over, exactly once" "$(seen_rows)" "$OLD_MARKS"
  check "6k and nothing left recorded as failed" \
    "$(wpx option get tmc_migration_last_error 2>/dev/null | grep -c '0021_review_seen')" "0"

  # ---- stage 7: the way back -------------------------------------------
  RECORDED_SINCE="$(seen_rows)"
  install_zip "$BASE_ZIP"
  check "7 the old package is back" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "7b it serves wp-admin against the NEW table, with no database work" "$(admin_request "$JAR")" "200"
  check "7c and left the schema where it was: nothing downgrades it" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "7d the new table is untouched by the old build" "$(seen_rows)" "$RECORDED_SINCE"
  # The cost, measured rather than asserted away: the old build reads its own
  # user meta, so a view recorded after the upgrade is not in its number.
  say "  after the rollback: $(legacy_marks) mark(s) in the old build's meta, $RECORDED_SINCE row(s) in the new table"
  check "7e and it reads its own meta, which the upgrade left alone" \
    "$(legacy_marks)" "$OLD_MARKS"

  # ---- stage 8: forward again ------------------------------------------
  install_zip "$NEW_ZIP"
  check "8 forward again, served" "$(admin_request "$JAR")" "200"
  check "8b the schema is still $NEW_SCHEMA and nothing ran twice" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "8c the rows are the ones that were there" "$(seen_rows)" "$RECORDED_SINCE"
done

say ""
say "checks: $((pass+fail))   passed: $pass   failed: $fail"
[ "$fail" -eq 0 ]
