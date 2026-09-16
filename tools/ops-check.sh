#!/usr/bin/env bash
# The queue, the outbox, the audit trail, the wizard and the report
# aggregation — on the plugin installed from the ZIP, on a real WordPress with
# real WooCommerce and Dokan Lite active.
#
# Every assertion here answers one of the review's findings. The two most
# important are the ones that cannot be shown by a unit test at all:
#
#   - a worker killed mid-run resumes from the checkpoint it wrote, in a
#     different PHP process;
#   - every recorded event reaches «blocked» with a named reason, and nothing
#     leaves the site.
#
#   tools/ops-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/operations}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
# A check whose expected value is «anything but this».
refute() { if [ "$2" != "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s must not be %s\n' "$1" "$2"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
ops() { wp eval-file ops-state.php "$@"; }
field() { grep -oE "^$1=.*$" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/ops-state.php "$WPROOT/" 2>/dev/null || true

# This script SPENDS what it touches: a job id is used once and an event id is
# unique, so yesterday's rows make today's run fail for reasons that have
# nothing to do with the code. Same lesson as the review evidence.
ops reset > /dev/null

# ---------------------------------------------------------------- 1. schema
SCHEMA="$(ops schema)"
printf '%s\n' "$SCHEMA" > "$EV/01-schema.txt"
{ echo "=== the package these numbers come from ==="; wp plugin list --fields=name,status,version; } >> "$EV/01-schema.txt"

check "schema version is 13"                 "13"      "$(printf '%s' "$SCHEMA" | field schema)"
check "tmc_jobs exists"                      "present" "$(printf '%s' "$SCHEMA" | field tmc_jobs)"
check "tmc_event_outbox exists"              "present" "$(printf '%s' "$SCHEMA" | field tmc_event_outbox)"
check "one-live-job-per-key index exists"    "present" "$(printf '%s' "$SCHEMA" | field index_tmc_job_live)"
check "one-row-per-event index exists"       "present" "$(printf '%s' "$SCHEMA" | field index_tmc_outbox_event)"
# WooCommerce is active here, so Action Scheduler must be what actually runs —
# and the report must say so rather than naming the one we hoped for.
check "cron binding is the real one"         "action_scheduler" "$(printf '%s' "$SCHEMA" | field cron_binding)"
check "the tick is on the calendar"          "yes"     "$(printf '%s' "$SCHEMA" | field cron_scheduled)"

# --------------------------------------------------- 2. one live job per key
SEED="$(ops queue-seed 2)"
printf '%s\n' "$SEED" > "$EV/02-queue-dedupe.txt"
check "two different keys queue two jobs"    "2" "$(printf '%s' "$SEED" | grep -c '^queued .* created=1')"
check "the same key queues nothing new"      "0" "$(printf '%s' "$SEED" | grep -oE 'duplicate id=[0-9]+ created=[01]' | grep -oE 'created=[01]' | cut -d= -f2)"
FIRST_ID="$(printf '%s' "$SEED" | grep -oE '^queued id=[0-9]+' | head -1 | grep -oE '[0-9]+')"
DUP_ID="$(printf '%s' "$SEED" | grep -oE 'duplicate id=[0-9]+' | grep -oE '[0-9]+')"
check "and the caller is told WHICH is live" "$FIRST_ID" "$DUP_ID"

# ------------------------------------------------- 3. two workers, one winner
RACE="$(ops queue-race)"
printf '%s\n' "$RACE" > "$EV/03-queue-race.txt"
check "exactly one job in the queue"         "1"  "$(printf '%s' "$RACE" | field only_job_in_queue)"
check "exactly one worker wins"              "1"  "$(printf '%s' "$RACE" | field winners)"
check "the same job is never claimed twice"  "no" "$(printf '%s' "$RACE" | field same_job_twice)"
check "the row holds the winner's token"     "$(printf '%s' "$RACE" | field returned_token)" "$(printf '%s' "$RACE" | field row_token)"

# ------------------------------------- 4. killed mid-run, resumed from the row
ops reset > /dev/null
KILL="$(ops queue-kill)"
RESUME="$(ops queue-resume)"
{ printf '%s\n' "$KILL"; echo; printf '%s\n' "$RESUME"; } > "$EV/04-queue-crash-resume.txt"
check "the checkpoint survived the death"    '{"at":2}' "$(printf '%s' "$KILL" | field cursor_written)"
check "the row still says running"           "running"  "$(printf '%s' "$KILL" | field status_after_death)"
check "and the census calls it stalled"      "1"        "$(printf '%s' "$KILL" | field stalled)"
check "a fresh worker reads that cursor"     '{"at":2}' "$(printf '%s' "$RESUME" | field cursor_before)"
check "and carries on from it"               '{"at":3}' "$(printf '%s' "$RESUME" | field cursor_after)"
check "it does NOT restart from zero"        "no"       "$(printf '%s' "$RESUME" | field restarted_from_zero)"
check "work already done is not redone"      "3"        "$(printf '%s' "$RESUME" | field done_after)"

# ------------------------------------------- 5. the event contract, and its lock
ops reset > /dev/null
REC="$(ops outbox-record)"
VER="$(ops outbox-verify)"
printf '%s\n' "$REC" > "$EV/05-outbox-record.txt"
printf '%s\n' "$VER" >> "$EV/05-outbox-record.txt"
check "every published type records"         "8" "$(printf '%s' "$REC" | grep -c 'ok=1')"
check "an unpublished type is refused"       "unknown_event_type" "$(printf '%s' "$REC" | grep '^unknown_type' | grep -oE 'reason=.*' | cut -d= -f2)"
check "the signing secret exists"            "yes" "$(printf '%s' "$VER" | field secret_stored)"
check "and is 32 bytes of randomness"        "64"  "$(printf '%s' "$VER" | field secret_length)"
check "every signature re-derives"           "0"   "$(printf '%s' "$VER" | field signatures_bad)"
# The allowlist: a field nobody declared was pushed in on purpose.
check "an undeclared field never reaches the wire" "0" "$(printf '%s' "$VER" | field undeclared_fields_on_the_wire)"

DEL="$(ops outbox-deliver)"
printf '%s\n' "$DEL" > "$EV/06-outbox-blocked.txt"
check "nothing is delivered outward"         "0" "$(printf '%s' "$DEL" | field state_sent)"
check "every event reaches blocked"          "8" "$(printf '%s' "$DEL" | field state_blocked)"
check "blocked is not counted as a failure"  "0" "$(printf '%s' "$DEL" | field state_failed)"
check "and the reason is named"              "outbound_blocked" "$(printf '%s' "$DEL" | field blocked_reason)"
check "the payload was not rewritten"        "yes" "$(printf '%s' "$DEL" | field payload_unchanged)"
check "nor the signature over it"            "yes" "$(printf '%s' "$DEL" | field signature_unchanged)"
# The batch that changed nothing must not read as a lost lease — the defect the
# first run of this very script found.
refute "a no-op batch is not a lost lease"   "lease_lost" "$(printf '%s' "$DEL" | grep -oE 'outcome=[a-z_]+' | tail -1 | cut -d= -f2)"

# ------------------------------------------------- 7. the trail, read back
AUD="$(ops audit-search vendor.application.approved)"
printf '%s\n' "$AUD" > "$EV/07-audit-search.txt"
TOTAL="$(printf '%s' "$AUD" | field total)"
refute "the trail is not empty"              "0" "$TOTAL"
refute "and it has more than one event type" "0" "$(printf '%s' "$AUD" | field types)"
check "a filter returns only its own event"  "0" "$(printf '%s' "$AUD" | field filter_leak)"
check "and a future window excludes everything" "0" "$(printf '%s' "$AUD" | field future_window)"

# ------------------------------------------------------- 8. the setup wizard
SET="$(ops setup-state)"
printf '%s\n' "$SET" > "$EV/08-setup-state.txt"
check "the checklist has seven steps"        "7" "$(printf '%s' "$SET" | field summary_total)"
check "WooCommerce is seen as active"        "done" "$(printf '%s' "$SET" | grep '^step=woocommerce ' | grep -oE 'status=[a-z]+' | cut -d= -f2)"
# Read from the site, not remembered: this install has a rate, so the step is
# done — and on an install without one it would say «waiting on DEC-01».
check "the rate step reads real state"       "done" "$(printf '%s' "$SET" | grep '^step=commission ' | grep -oE 'status=[a-z]+' | cut -d= -f2)"
check "done + todo + blocked + skipped = total" \
  "$(printf '%s' "$SET" | field summary_total)" \
  "$(( $(printf '%s' "$SET" | field summary_done) + $(printf '%s' "$SET" | field summary_todo) \
     + $(printf '%s' "$SET" | field summary_blocked) + $(printf '%s' "$SET" | field summary_skipped) ))"

# --------------------------------------------- 9. the reader, with no truncation
RD="$(ops reader-page)"
printf '%s\n' "$RD" > "$EV/09-reader-paging.txt"
check "paging reads exactly what one read does" "yes" "$(printf '%s' "$RD" | field paged_equals_whole)"
check "and never repeats a row"               "yes" "$(printf '%s' "$RD" | field strictly_ascending)"
check "the hard LIMIT is gone from the code"  "gone" "$(printf '%s' "$RD" | field hardcoded_limit_in_code)"
check "and the page size is a bound parameter" "yes" "$(printf '%s' "$RD" | field limit_is_a_placeholder)"

# ------------------------------------------- 10. reports, counted in SQL
RS="$(ops reports-scale)"
printf '%s\n' "$RS" > "$EV/10-reports-aggregate.txt"
check "the report can be read by its shop"    "yes" "$(printf '%s' "$RS" | field report_readable)"
check "report and repository agree"           "yes" "$(printf '%s' "$RS" | field agrees)"
check "reports no longer walk the catalogue"  "no"  "$(printf '%s' "$RS" | field reports_still_walk_the_catalogue)"

# ------------------------------------------- 11. the API, versioned and gated
API="$(wp eval 'do_action("rest_api_init");
$routes = rest_get_server()->get_routes();
$ours = [];
foreach ($routes as $route => $handlers) {
    if (!str_starts_with($route, "/tecteb/v1")) { continue; }
    foreach ($handlers as $handler) {
        $methods = implode(",", array_keys(array_filter($handler["methods"])));
        $callback = $handler["permission_callback"] ?? null;
        $gated = $callback !== null && $callback !== "__return_true";
        $ours[] = $route . " methods=" . $methods . " gated=" . ($gated ? "1" : "0");
    }
}
echo "routes=", count($ours), "\n";
echo implode("\n", $ours), "\n";
echo "writable=", count(array_filter($ours, fn($r) => str_contains($r, "POST") || str_contains($r, "PUT") || str_contains($r, "DELETE"))), "\n";
$ungated = array_values(array_filter($ours, fn($r) => str_contains($r, "gated=0")));
echo "ungated=", count($ungated), "\n";
// The ONLY ungated entry WordPress leaves behind is the namespace index it
// registers itself for every namespace — WooCommerce has one, so does tmc/v1.
// It is a discovery document, so the interesting question is not whether it is
// open but whether it carries anything. Ask it as nobody and look.
echo "ungated_are_index_only=", (count($ungated) === 0
    || (count($ungated) === 1 && str_starts_with($ungated[0], "/tecteb/v1 "))) ? "yes" : "no", "\n";
wp_set_current_user(0);
$index = rest_do_request(new WP_REST_Request("GET", "/tecteb/v1"));
$body = $index->get_data();
echo "index_status=", $index->get_status(), "\n";
echo "index_keys=", implode(",", array_keys(is_array($body) ? $body : [])), "\n";
// A guest asking for actual data must be refused, not given an empty list.
$data = rest_do_request(new WP_REST_Request("GET", "/tecteb/v1/vendors/4/products"));
echo "guest_products_status=", $data->get_status(), "\n";
$events = rest_do_request(new WP_REST_Request("GET", "/tecteb/v1/events"));
echo "guest_events_status=", $events->get_status(), "\n";')"
printf '%s\n' "$API" > "$EV/11-api-routes.txt"
refute "the versioned namespace is registered" "0" "$(printf '%s' "$API" | field routes)"
check "no route writes anything"               "0" "$(printf '%s' "$API" | field writable)"
# WordPress registers a discovery index for EVERY namespace and always leaves
# it open; `tmc/v1` and WooCommerce's have one too. So the assertion is not
# «nothing is open» — that would be a claim about WordPress we cannot keep —
# but «the only open thing is that index, and it carries no marketplace data».
check "the only open route is the index"       "yes" "$(printf '%s' "$API" | field ungated_are_index_only)"
check "and the index lists routes, not data"   "namespace,routes" "$(printf '%s' "$API" | field index_keys)"
check "a guest asking for products is refused" "401" "$(printf '%s' "$API" | field guest_products_status)"
check "a guest asking for events is refused"   "401" "$(printf '%s' "$API" | field guest_events_status)"

# ------------------------------------------------- 12. the OTP adapter is inert
OTP="$(wp eval '$p = Tecteb\Marketplace\Infrastructure\Otp\KamangirSmartLoginAdapter::probe();
echo "installed=", $p["installed"] ? "1" : "0", "\n";
echo "usable=", $p["usable"] ? "1" : "0", "\n";
echo "reason=", $p["reason"], "\n";
$a = new Tecteb\Marketplace\Infrastructure\Otp\KamangirSmartLoginAdapter();
echo "send=", $a->sendChallenge(new Tecteb\Marketplace\Contracts\Otp\OtpSendRequest("09120000000", "login"))->status->value, "\n";
$bound = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container()
    ->get(Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface::class);
echo "bound=", get_class($bound), "\n";')"
printf '%s\n' "$OTP" > "$EV/12-otp-adapter.txt"
check "the adapter never reports itself usable" "0" "$(printf '%s' "$OTP" | field usable)"
check "and names why"                           "no_published_php_contract" "$(printf '%s' "$OTP" | field reason)"
check "sending is unavailable"                  "unavailable" "$(printf '%s' "$OTP" | field send)"
check "and the ZIP still binds the null provider" \
  "Tecteb\\Marketplace\\Infrastructure\\Otp\\NullOtpProvider" "$(printf '%s' "$OTP" | field bound)"

ops reset > /dev/null

printf '\nchecks: %d passed, %d failed\n' "$pass" "$fail" | tee "$EV/summary.txt"
[ "$fail" -eq 0 ]
