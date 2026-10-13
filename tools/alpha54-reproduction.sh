#!/usr/bin/env bash
# The four `alpha.53` findings — reproduced on its own bytes, then fixed.
#
# WHAT THE OWNER NAMED, and all four are one shape: the first half of a job
# done alone and reported as the whole.
#
#   §1 `MigrateMinMax::decide()` ran the destination write although `begin()`
#      had been refused, and discarded the results of the history write and of
#      `commit()`. And the ledger row — the ONLY record of what the
#      destination held before — went in AFTER the meta rows, so a unique key
#      on it could not arbitrate a race it was already too late for.
#   §2 `migrateBatch()` advanced the cursor to the end of the page even when a
#      product in the middle had not been written, and the admin page stored
#      that cursor.
#   §3 `rollback()` and `WpShopQuantityPort::restore()` were several separate
#      writes, the ownership record was not verified at all, and the «has a
#      manager changed this since?» comparison was a separate statement from
#      the write it guarded.
#   §4 any existing history row answered `already_decided`, `rolled_back`
#      included, so a product that had been put back could never be migrated
#      again — and the only way out would have been deleting its history.
#
# HOW THE TWO TREES ARE PUT IN PLACE. The INSTALLED plugin's `src/` is
# swapped, never the repository's: the old bytes come from `git archive
# <base> src` into a temporary directory and are rsynced in, and a `trap`
# rsyncs this checkout back. The repository is never written to, so a failed
# restore has nothing there to leave mixed — the hazard `alpha.39` paid for.
#
# AND THE SCHEMA IS SWAPPED WITH THEM, because this round has a migration.
# `alpha.53` keys the ledger `UNIQUE (post_id)`; this tree keys it
# `UNIQUE (live_key)` and that is the difference §1 and §4 turn on. So the
# «before» half puts the table back into the 27 shape and the «after» half
# lets the UPGRADE GATE move it, from a real logged-in admin request — the
# owner's own path, not a direct call to the runner.
#
# THE PROBE IS CONSTANT AND COMES FROM THE REPOSITORY, and it loads on both
# trees (`alpha.32`): its ledger decorator declares BOTH interfaces' methods
# and forwards through `method_exists()`, `release()` is called only when the
# service has it, and every key this round adds is read with `??`.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha54-reproduction.sh docs/evidence/alpha54-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha54-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
SITE="${SITE:-http://127.0.0.1:8081}"
ADMIN="${ADMIN:-tmcowner}"
ADMIN_PASS="${ADMIN_PASS:-demo-owner-2026}"
BASE_COMMIT="${BASE_COMMIT:-8b49faf}"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"

mkdir -p "$OUT"
RUN="$OUT/run.txt"
: >"$RUN"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$RUN" >/dev/null; }
check() { # check <label> <expected> <actual>
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }

[[ -d "$INSTALLED" ]] || { say "FAIL  the plugin is not installed at $INSTALLED"; echo "PASS=0 FAIL=1" | tee -a "$RUN"; exit 1; }

OLD_TREE="$(mktemp -d)"
( cd "$REPO" && git archive "$BASE_COMMIT" src | tar -x -C "$OLD_TREE" ) || {
  say "FAIL  could not read src/ at $BASE_COMMIT"; echo "PASS=0 FAIL=1" | tee -a "$RUN"; exit 1
}
say "old bytes read from $BASE_COMMIT: $(find "$OLD_TREE/src" -name '*.php' | wc -l) php files"
say "this checkout:                    $(find "$REPO/src" -name '*.php' | wc -l) php files"

install_tree() { rsync -a --delete "$1/" "$INSTALLED/src/"; }
trap 'install_tree "$REPO/src"; rm -rf "$OLD_TREE"' EXIT

NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
OLD_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$OLD_TREE/src/Core/Migration/SchemaVersion.php" | head -1)"
say "schema: $OLD_SCHEMA (base) -> $NEW_SCHEMA (this tree)"

TABLE="$(wpx eval 'global $wpdb; echo $wpdb->prefix . "tmc_minmax_handover";' 2>/dev/null)"

# ---- the schema, both shapes -------------------------------------------
to_old_schema() {
  # `alpha.53`'s shape: no `live_key`, and the unique key on `post_id`. The
  # ledger is emptied first, because a row whose `live_key` is NULL would
  # collide with nothing and a row whose `post_id` repeats would collide with
  # everything — neither is a state `alpha.53` could ever have produced.
  wpx db query "DELETE FROM \`$TABLE\`" >/dev/null 2>&1
  wpx db query "ALTER TABLE \`$TABLE\` DROP INDEX \`one_live_per_post\`" >/dev/null 2>&1
  wpx db query "ALTER TABLE \`$TABLE\` DROP INDEX \`by_post\`" >/dev/null 2>&1
  wpx db query "ALTER TABLE \`$TABLE\` DROP COLUMN \`live_key\`" >/dev/null 2>&1
  wpx db query "ALTER TABLE \`$TABLE\` ADD UNIQUE KEY \`one_per_post\` (\`post_id\`)" >/dev/null 2>&1
  wpx option update tmc_schema_version "$OLD_SCHEMA" >/dev/null
}
index_count() { # index_count <name>
  wpx db query "SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$TABLE' AND INDEX_NAME = '$1'" --skip-column-names 2>/dev/null | tr -d '[:space:]'
}
has_live_key() {
  wpx db query "SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$TABLE' AND COLUMN_NAME = 'live_key'" --skip-column-names 2>/dev/null | tr -d '[:space:]'
}
JAR="$(mktemp)"
login() {
  curl -s -c "$JAR" -o /dev/null --data-urlencode "log=$ADMIN" --data-urlencode "pwd=$ADMIN_PASS" \
       --data-urlencode "redirect_to=$SITE/wp-admin/" "$SITE/wp-login.php" || true
}
admin_request() {
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR" --max-time 180 \
       "$SITE/wp-admin/admin.php?page=tmc-dashboard"
}

PROBE_FILE=""
probe() { # probe <verb> <tree>
  local verb="$1" tree="$2"
  PROBE_FILE="$OUT/$tree-$verb.txt"
  ( cd "$REPO" && TMC_REPO="$REPO" TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" \
      "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
      --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file tests/Support/alpha54-probe.php "$verb" ) >"$PROBE_FILE" 2>"$PROBE_FILE.err"
  if grep -qx 'probe=ran' "$PROBE_FILE"; then
    PASS=$((PASS + 1)); say "PASS  $tree/$verb  the probe ran"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $tree/$verb  the probe did not run"
  fi
}
value() { # value <file> <key>
  local v
  v="$(grep -m1 "^$2=" "$1" 2>/dev/null | cut -d= -f2-)"
  printf '%s' "${v:-__missing__}"
}
dump() { say "--- $1 ---"; grep -v '^WordPress database error' "$2" >>"$RUN" 2>/dev/null || true; }

run_tree() { # run_tree <label>
  local tree="$1" f
  say ""
  say "=================== $tree ==================="

  probe reset "$tree" >/dev/null

  # ---------------- §1 atomicity ----------------
  probe begin-failure "$tree"; f="$PROBE_FILE"; dump "$tree begin-failure" "$f"
  check "$tree/1a begin() was asked"                 "1" "$(value "$f" begin_called)"
  if [[ "$tree" == before ]]; then
    check "$tree/1a THE DEFECT: it wrote anyway"       "4/10/2" "$(value "$f" rows_after)"
    check "$tree/1a and in the database too"           "4/10/2" "$(value "$f" db_rows_after)"
    check "$tree/1a and called it migrated"                 "1" "$(value "$f" migrated_count)"
    check "$tree/1a and refused nothing"                    "0" "$(value "$f" refused_for_atomicity)"
  else
    check "$tree/1a nothing was written"   "__absent__/__absent__/__absent__" "$(value "$f" rows_after)"
    check "$tree/1a nor in the database"   "__absent__/__absent__/__absent__" "$(value "$f" db_rows_after)"
    check "$tree/1a no decision recorded"              "__none__" "$(value "$f" ledger_after)"
    check "$tree/1a and it is a named refusal"       "not_atomic" "$(value "$f" outcome)"
  fi

  probe no-transaction "$tree"; f="$PROBE_FILE"; dump "$tree no-transaction" "$f"
  if [[ "$tree" == before ]]; then
    check "$tree/1b THE DEFECT: no transaction, still wrote" "4/10/2" "$(value "$f" rows_after)"
    check "$tree/1b and called it migrated"                       "1" "$(value "$f" migrated_count)"
  else
    check "$tree/1b nothing written"   "__absent__/__absent__/__absent__" "$(value "$f" rows_after)"
    check "$tree/1b named refusal"                   "no_transaction" "$(value "$f" outcome)"
  fi

  # The caller's own transaction: `alpha.53` already left it open, so this is
  # a CONTROL on the old tree and a refusal on the new one. Neither commits it.
  probe caller-transaction "$tree"; f="$PROBE_FILE"; dump "$tree caller-transaction" "$f"
  check "$tree/1c the caller still owns its transaction" "yes" "$(value "$f" still_in_transaction_after)"
  check "$tree/1c and nothing of its was committed"       "no" "$(value "$f" caller_row_visible_elsewhere)"

  probe record-failure "$tree"; f="$PROBE_FILE"; dump "$tree record-failure" "$f"
  check "$tree/1d the history write was attempted"        "1" "$(value "$f" record_called)"
  if [[ "$tree" == before ]]; then
    check "$tree/1d THE WORST: it committed anyway"       "1" "$(value "$f" committed)"
    check "$tree/1d the rule is applied"            "4/10/2" "$(value "$f" db_rows_after)"
    check "$tree/1d with NO record of what was there" "__none__" "$(value "$f" ledger_after)"
    check "$tree/1d reported as a migration"              "1" "$(value "$f" migrated_count)"
  else
    check "$tree/1d it did not commit"                    "0" "$(value "$f" committed)"
    check "$tree/1d it went back"                         "1" "$(value "$f" rolled_back)"
    check "$tree/1d the destination is untouched" "__absent__/__absent__/__absent__" "$(value "$f" db_rows_after)"
    check "$tree/1d and nothing claims it"         "__none__" "$(value "$f" ledger_after)"
    check "$tree/1d named"            "history_not_written" "$(value "$f" outcome)"
  fi

  probe commit-failure "$tree"; f="$PROBE_FILE"; dump "$tree commit-failure" "$f"
  check "$tree/1e nothing persisted" "__absent__/__absent__/__absent__" "$(value "$f" db_rows_after)"
  if [[ "$tree" == before ]]; then
    check "$tree/1e THE DEFECT: reported as migrated"     "1" "$(value "$f" migrated_count)"
    check "$tree/1e and not as a failed commit"           "0" "$(value "$f" reported_not_committed)"
  else
    check "$tree/1e not reported as migrated"             "0" "$(value "$f" migrated_count)"
    check "$tree/1e reported as a failed commit"          "1" "$(value "$f" reported_not_committed)"
  fi

  # ---------------- §2 continuation ----------------
  probe batch-continues "$tree"; f="$PROBE_FILE"; dump "$tree batch-continues" "$f"
  check "$tree/2 the middle product failed"               "1" "$(value "$f" not_written_count)"
  if [[ "$tree" == before ]]; then
    # TWO migrated, because the walk carried on past the failure — which is
    # the defect itself, not a different count of the same behaviour.
    check "$tree/2 it carried on and migrated the one after it" "2" "$(value "$f" migrated_count)"
  else
    check "$tree/2 only the one before it went through"         "1" "$(value "$f" migrated_count)"
  fi
  if [[ "$tree" == before ]]; then
    check "$tree/2 THE DEFECT: the cursor passed it"    "yes" "$(value "$f" cursor_past_failure)"
    check "$tree/2 nothing stopped"                       "0" "$(value "$f" stopped_at)"
    check "$tree/2 and no product was named"       "__none__" "$(value "$f" failed_ids)"
  else
    check "$tree/2 the cursor stops before it"           "no" "$(value "$f" cursor_past_failure)"
    check "$tree/2 the cursor is the last FINISHED id" "$(value "$f" first_id)" "$(value "$f" cursor_after)"
    check "$tree/2 the failed product is named" "$(value "$f" middle_id)" "$(value "$f" stopped_at)"
    check "$tree/2 with its reason"  "$(value "$f" middle_id):not_written" "$(value "$f" failed_ids)"
    check "$tree/2 and the walk did not run past it" "__none__" "$(value "$f" ledger_last)"
  fi

  # ---------------- §3 rollback ----------------
  probe rollback-steps "$tree"; f="$PROBE_FILE"; dump "$tree rollback-steps" "$f"
  check "$tree/3a the destination was absent before"  "2/__absent__/2" "$(value "$f" before_migration)"
  check "$tree/3a and migrated"                              "4/10/2" "$(value "$f" rows_after_migration)"
  check "$tree/3a the restore refused"                           "no" "$(value "$f" rollback_ok)"
  if [[ "$tree" == before ]]; then
    check "$tree/3a THE DEFECT: a rule nobody wrote"  "4/__absent__/2" "$(value "$f" rows_after_failed_rollback)"
    check "$tree/3a in the database too"              "4/__absent__/2" "$(value "$f" db_rows_after_failed_rollback)"
  else
    check "$tree/3a nothing half-done"                       "4/10/2" "$(value "$f" rows_after_failed_rollback)"
    check "$tree/3a in the database too"                     "4/10/2" "$(value "$f" db_rows_after_failed_rollback)"
  fi

  probe rollback-mark-failure "$tree"; f="$PROBE_FILE"; dump "$tree rollback-mark-failure" "$f"
  check "$tree/3b named"  "minmax_rollback_unrecorded" "$(value "$f" rollback_code)"
  if [[ "$tree" == before ]]; then
    check "$tree/3b THE DEFECT: restored with the ledger saying migrated" \
      "__absent__/__absent__/__absent__" "$(value "$f" db_rows_after)"
  else
    check "$tree/3b the whole unit went back"  "4/10/2" "$(value "$f" db_rows_after)"
  fi

  probe record-stale "$tree"; f="$PROBE_FILE"; dump "$tree record-stale" "$f"
  check "$tree/3c the surviving record still has three entries" "3" "$(value "$f" record_entries)"
  check "$tree/3c of which one matches the shop"                "1" "$(value "$f" record_entries_matching_shop)"
  if [[ "$tree" == before ]]; then
    check "$tree/3c THE DEFECT: counted, so «stored»"         "yes" "$(value "$f" port_says_stored)"
  else
    check "$tree/3c compared, so not stored"                   "no" "$(value "$f" port_says_stored)"
  fi

  # A REAL second process holds the three rows `FOR UPDATE` and keeps its
  # transaction open.
  #
  # **This is a CONTROL plus one measured difference, and NOT a before/after
  # defect — because measuring it said so.** The expectation written here
  # first was «`alpha.53` deletes the manager's value»; it does not. A holder
  # that keeps the rows locked blocks `alpha.53`'s restore writes as well, so
  # on both trees the manager's value stands. What differs is WHERE each tree
  # stops: `alpha.53` starts writing and fails part way
  # (`minmax_rollback_not_stored`), this tree refuses before touching anything
  # (`minmax_rollback_not_locked`).
  #
  # The case the guard is really for — a second writer that lands inside the
  # window and COMMITS IMMEDIATELY — is not deterministically reachable from
  # outside the code under test, and is NOT claimed as measured. What is
  # measured is that the lock is taken before the comparison and that a
  # failure to take it is a named refusal rather than a write.
  probe manager-edit-locked "$tree"; f="$PROBE_FILE"; dump "$tree manager-edit-locked" "$f"
  if [[ "$(value "$f" holder_holding)" != "yes" ]]; then
    say "SKIP  $tree/3d the second process never took the rows — reported, not counted"
  else
    check "$tree/3d the manager's value stands on BOTH trees"          "yes" "$(value "$f" manager_value_kept)"
    check "$tree/3d and the ledger did not move"  "migrated_before=__absent___wrote=4" "$(value "$f" ledger_after)"
    if [[ "$tree" == before ]]; then
      check "$tree/3d it stops PART WAY THROUGH the restore" \
        "minmax_rollback_not_stored" "$(value "$f" rollback_code)"
    else
      check "$tree/3d it refuses BEFORE the first write" \
        "minmax_rollback_not_locked" "$(value "$f" rollback_code)"
    fi
  fi

  # ---------------- §4 re-migration ----------------
  probe remigrate "$tree"; f="$PROBE_FILE"; dump "$tree remigrate" "$f"
  check "$tree/4a the first migration"                   "1" "$(value "$f" first_migrated)"
  check "$tree/4a the rollback"      "minmax_rolled_back" "$(value "$f" rollback_code)"
  check "$tree/4a put the values back" "__absent__/__absent__/__absent__" "$(value "$f" rows_after_rollback)"
  if [[ "$tree" == before ]]; then
    check "$tree/4a THE DEFECT: already decided for ever" "1" "$(value "$f" second_already_decided)"
    check "$tree/4a so it cannot be migrated again"       "0" "$(value "$f" second_migrated)"
    check "$tree/4a and the destination stays empty" "__absent__/__absent__/__absent__" "$(value "$f" rows_after_second)"
    check "$tree/4a with one row of history"              "1" "$(value "$f" history_rows)"
  else
    check "$tree/4a it migrates again"                    "1" "$(value "$f" second_migrated)"
    check "$tree/4a not «already decided»"                "0" "$(value "$f" second_already_decided)"
    check "$tree/4a the rule is applied"             "4/10/2" "$(value "$f" rows_after_second)"
    check "$tree/4a and BOTH decisions are on record"     "2" "$(value "$f" history_rows)"
  fi

  probe retry-held "$tree"; f="$PROBE_FILE"; dump "$tree retry-held" "$f"
  check "$tree/4b the manager's rule holds it"           "1" "$(value "$f" held_count)"
  check "$tree/4b and removing it is not enough on its own" "1" "$(value "$f" retry_already_decided)"
  if [[ "$tree" == before ]]; then
    check "$tree/4b THE DEFECT: there is no way out" "__no_such_method__" "$(value "$f" released_code)"
  else
    check "$tree/4b the explicit path exists"         "minmax_released" "$(value "$f" released_code)"
    check "$tree/4b from the held state"             "held_by_manager" "$(value "$f" released_was)"
    check "$tree/4b the refusal stays on record"                  "1" "$(value "$f" history_rows_after_release)"
    check "$tree/4b and the next batch decides again"             "1" "$(value "$f" after_release_migrated)"
    check "$tree/4b writing the rule"                       "4/10/2" "$(value "$f" rows_after_release)"
  fi

  # ---------------- controls: identical on both trees ----------------
  probe control-convertible "$tree"; f="$PROBE_FILE"; dump "$tree control-convertible" "$f"
  check "$tree/C1 a convertible rule still migrates"      "1" "$(value "$f" migrated)"
  check "$tree/C1 to the same three values"          "4/10/2" "$(value "$f" rows)"
  check "$tree/C1 read past every cache"             "4/10/2" "$(value "$f" db_rows)"
  check "$tree/C1 owning all three rows" "3:max_purchase,min_purchase,purchase_step" "$(value "$f" record)"

  probe control-refused "$tree"; f="$PROBE_FILE"; dump "$tree control-refused" "$f"
  check "$tree/C2 a step-base rule is still refused"      "1" "$(value "$f" refused)"
  check "$tree/C2 nothing migrated"                       "0" "$(value "$f" migrated)"
  check "$tree/C2 nothing written" "__absent__/__absent__/__absent__" "$(value "$f" rows)"
  check "$tree/C2 and the rule is named" "step_base_differs" "$(value "$f" problems)"

  probe manager-edit-race "$tree"; f="$PROBE_FILE"; dump "$tree manager-edit-race" "$f"
  check "$tree/C3 a value changed BEFORE the rollback refuses it" \
    "minmax_rollback_changed" "$(value "$f" rollback_code)"
  check "$tree/C3 and is kept"                            "8" "$(value "$f" manager_value_kept)"

  probe rollback-cache "$tree"; f="$PROBE_FILE"; dump "$tree rollback-cache" "$f"
  check "$tree/C4 after a successful rollback the app and the database agree" \
    "yes" "$(value "$f" agree_same_request)"
  probe fresh-read "$tree"; f="$PROBE_FILE"; dump "$tree fresh-read" "$f"
  check "$tree/C5 and they still agree in a FRESH request" "yes" "$(value "$f" agree_fresh)"
}

# ============================== before ==============================
install_tree "$OLD_TREE/src"
check "0a the installed tree is the base's bytes" \
  "$(cd "$OLD_TREE" && find src -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)" \
  "$(cd "$INSTALLED" && find src -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)"
to_old_schema
check "0b the ledger is in the $OLD_SCHEMA shape: no live_key" "0" "$(has_live_key)"
check "0c with the old unique key"                             "1" "$(index_count one_per_post)"
run_tree before

# ============================== after ===============================
install_tree "$REPO/src"
check "0d the installed tree is this checkout" \
  "$(cd "$REPO" && find src -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)" \
  "$(cd "$INSTALLED" && find src -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)"
check "0e replacing the files runs no migration by itself" "$OLD_SCHEMA" "$(wpx option get tmc_schema_version)"
login
check "0f the first admin request answers 200"                  "200" "$(admin_request)"
check "0g and the gate has moved the schema to $NEW_SCHEMA" "$NEW_SCHEMA" "$(wpx option get tmc_schema_version)"
check "0h live_key is there"                                      "1" "$(has_live_key)"
check "0i with the unique key on it"                              "1" "$(index_count one_live_per_post)"
check "0j and the old key is gone"                                 "0" "$(index_count one_per_post)"
run_tree after

# Hand the Min Max source rows back. They are part of the SITE's Min Max
# state, so leaving them makes the PREVIOUS round's script count seven
# products where its own fixture made four — which it did, and it reported six
# failures about a build that was working (`alpha.33`'s rule).
probe hand-back after >/dev/null
check "Z the fixture handed its Min Max rows back" "yes" "$(value "$PROBE_FILE" hand_back)"

rm -f "$JAR"
say ""
say "PASS=$PASS FAIL=$FAIL"
printf 'PASS=%s FAIL=%s\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
