#!/usr/bin/env bash
# Imported history is not operational capability — measured, not asserted.
#
# The import fills five history tables and produces a convincing report. None
# of it means a person may touch an order, or that this marketplace owes
# anybody a rial. This runs that distinction on the DISPOSABLE WordPress with
# Dokan's real tables, in ONE PHP pass (`tools/handover-state.php`), and asks
# the same `StaffAccess` every operational screen asks rather than reading the
# membership row.
#
#   tools/handover-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/handover}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
stage() { grep -E "^stage=$1 " "$EV/01-run.txt" | head -1; }
ok_of() { stage "$1" | grep -oE ' ok=[a-z]+' | head -1 | cut -d= -f2; }
field() { stage "$1" | grep -oE " $2=[^ ]*" | head -1 | cut -d= -f2-; }

# `wp eval-file` reads from the WordPress root, not the repository. A stale
# copy there answers `usage:` to a command this version added, which looks
# like «the feature is missing» and is «the file is old».
cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== imported history vs operational capability, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version | grep -E 'tecteb|dokan|woocommerce'
} | tee "$EV/00-header.txt"

wp eval-file handover-state.php reset > "$EV/00-reset.txt" 2>&1
wp eval-file handover-state.php run > "$EV/01-run.txt" 2>&1
sed -n 's/^stage=/  /p' "$EV/01-run.txt"
echo

echo "--- 1. the archive lands, and lands alone ---"
check "a Dokan past exists to import"                          true "$(ok_of dokan_past_exists)"
check "records arrive: staff, balance, withdrawals"            true "$(ok_of records_imported)"
check "…two balance rows"                                    2 "$(field records_imported balance_rows)"
check "…two withdrawal rows"                                 2 "$(field records_imported withdrawals)"
# FIN-02, measured as a DELTA: the disposable ledger already holds hundreds of
# lines, so «۰ خط» as an absolute would be a fact about the fixture.
check "the import wrote NO ledger line"                        0 "$(field import_wrote_no_ledger_line delta)"
check "the import created NO withdrawal"                       0 "$(field import_created_no_withdrawal delta)"

echo
echo "--- 2. an unmapped Dokan role grants nothing, and is not lost ---"
check "an unmapped role awaits a decision"                     true "$(ok_of unmapped_role_awaits_a_decision)"
check "…and no preset is guessed for it"                     "(none)" "$(field unmapped_role_awaits_a_decision preset)"
check "no membership row is written"                           0 "$(field no_membership_without_a_decision rows)"
check "…nothing granted"                                     0 "$(field no_membership_without_a_decision granted)"
check "…one person waiting, by name"                         1 "$(field no_membership_without_a_decision awaiting)"
check "the imported person can touch nothing"                  true "$(ok_of imported_person_can_touch_nothing)"
check "…and belongs to no store here"                        "(none)" "$(field imported_person_can_touch_nothing store)"

echo
echo "--- 3. the decision is the map; arriving is not the decision ---"
check "a mapped role creates an INVITED membership"            true "$(ok_of mapped_role_creates_an_invited_membership)"
check "…status invited"                                      invited "$(field mapped_role_creates_an_invited_membership status)"
check "…with the mapped preset and no other"                 order_shipping "$(field mapped_role_creates_an_invited_membership preset)"
check "invited still cannot act"                               true "$(ok_of invited_still_cannot_act)"
check "…not even read an order"                              false "$(field invited_still_cannot_act order_view)"
check "no token exists that could activate the row"            "(empty)" "$(field no_token_exists_for_the_adopted_row invite_hash)"

echo
echo "--- 4. two gates, not one: the person, and the shop ---"
check "confirming activates the membership"                    active "$(field confirmed_but_the_shop_is_not_admitted_yet membership)"
check "…and the shop still may not trade"                    false "$(field confirmed_but_the_shop_is_not_admitted_yet shop_may_trade)"
check "…so the confirmed member still cannot act"            false "$(field confirmed_but_the_shop_is_not_admitted_yet order_edit)"
check "after admission the member works"                       true "$(ok_of confirmed_member_has_exactly_the_mapped_role)"
check "…orders: edit"                                        true "$(field confirmed_member_has_exactly_the_mapped_role order_edit)"
check "…inventory: read"                                     true "$(field confirmed_member_has_exactly_the_mapped_role inventory_view)"
check "…inventory: NOT edit"                                 false "$(field confirmed_member_has_exactly_the_mapped_role inventory_edit)"
check "…finance: nothing at all"                             false "$(field confirmed_member_has_exactly_the_mapped_role finance_view)"
check "and only in their own shop"                             false "$(field member_is_scoped_to_their_own_shop other_shop_order_view)"

echo
echo "--- 5. the reconciliation report: two columns, never added ---"
check "closing is Dokan's own arithmetic"                      3000000.0000 "$(field closing_is_dokans_own_arithmetic closing)"
check "…credit as Dokan recorded it"                         5000000.0000 "$(field closing_is_dokans_own_arithmetic credit)"
check "…debit as Dokan recorded it"                          2000000.0000 "$(field closing_is_dokans_own_arithmetic debit)"
# Dokan books a paid withdrawal twice by design. closing + withdrawn would
# overstate the debt by exactly the amount already paid.
check "the paid withdrawal is already inside closing"          1 "$(field paid_withdrawal_is_already_inside_closing rows)"
check "…and is named, with its amount"                       2000000.0000 "$(field paid_withdrawal_is_already_inside_closing debit)"
check "withdrawals keep Dokan's own status token"              "dokan:0,dokan:1" "$(field withdrawals_keep_dokans_own_status statuses)"

echo
echo "--- 6. handing over responsibility, without paying anything twice ---"
check "the hand-over is recorded"                              handover_recorded "$(field handover_recorded reason)"
check "…as an explicit decision"                             accepted "$(field handover_recorded decision)"
check "it wrote NO ledger line"                                0 "$(field handover_wrote_no_ledger_line delta)"
check "it created NO withdrawal"                               0 "$(field handover_created_no_second_payment withdrawal_delta)"
check "the unpaid Dokan request stays unpaid, and named"       1 "$(field handover_created_no_second_payment pending_untouched)"
check "no rate was applied to the balance"                     3000000.0000 "$(field no_rate_was_applied closing)"
check "…and the agreed figure is frozen"                     3000000.0000 "$(field no_rate_was_applied frozen)"
check "Dokan's own tables are byte-identical throughout"       true "$(ok_of dokan_tables_untouched)"

echo
echo "--- 7. «مهاجرت کامل» is not something the archive can say ---"
check "a filed shop is NOT complete"                           incomplete "$(field archive_alone_is_not_complete verdict)"
check "…and the report names what is missing"                ownership_taken "$(field archive_alone_is_not_complete missing)"
check "the staff decision closes its own gate"                 true "$(field finance_gate_closed_by_the_decision staff_decided)"
check "the finance decision closes its own gate"               true "$(field finance_gate_closed_by_the_decision finance_decided)"
check "the marketplace is not declared migrated"               false "$(field marketplace_is_not_declared_migrated all_complete)"

echo
echo "--- 8. the whole pass ---"
check "no stage failed"                                        0 "$(field summary failures)"

echo
echo "pass=${pass} fail=${fail}"
{ echo "pass=${pass} fail=${fail}"; date -u +'generated=%Y-%m-%dT%H:%M:%SZ'; } > "$EV/99-summary.txt"
[ "$fail" -eq 0 ] || exit 1
