The three code-review findings of `alpha.60`, reproduced on its own delivered
bytes and then measured again on this round's package.

  alpha61-probe.php                  the probe, run on each tree
  concurrent-supplier-payment.php    the SECOND PROCESS, for the one race
  before-alpha60-commission.txt      §1 on the installed alpha.60 ZIP
  before-alpha60-payments.txt        §2 and §3 on the same
  after-alpha61-commission.txt       §1 on the installed alpha.61 ZIP
  after-alpha61-payments.txt         §2, §3 and the race on the same

Both trees are the INSTALLED plugin in a disposable WordPress 7.1 with real
WooCommerce 11.0.1 — `wp plugin get … --field=version` is printed in each
file's header, so «the delivered bytes» is a measurement and not a claim.
Every failure here is an INJECTED failure of a collaborator, through
WordPress's own `update_post_metadata` short-circuit (the `alpha.47` method),
never a spontaneous one.

-------------------------------------------------------------------- §1

ALL THREE FINDINGS REPRODUCED. §1 reproduced in BOTH directions, and the
second direction is worse than the report said:

  AMOUNT write refused, previous rule percent/12.50, request fixed/20000
    alpha.60   db_type=fixed    db_value=12.50   rule=fixed/12.50
    alpha.61   db_type=percent  db_value=12.50   rule=percent/12.50

  TYPE write refused, same previous rule and request
    alpha.60   db_type=percent  db_value=20000   rule=none/''
    alpha.61   db_type=percent  db_value=12.50   rule=percent/12.50

On `alpha.60` the first case stored «۱۲٫۵۰ به ازای هر عدد» — neither the old
rule nor the new one — and the second left a pair `TectebCommission` reads as
NO COMMISSION AT ALL, so a 12.5% commission silently disappeared. Both times
the message was «کمیسیون تک‌طب ذخیره نشد. مقدار قبلی سر جایش است».

On `alpha.61` the rows are the previous pair and the message names it:
«… کمیسیون این محصول همان قبلی است (درصدی 12.50٪)». The outcome has its own
name, `not_stored_restored`, and the one that cannot say that —
`not_stored_unrestored` — prints what is really stored instead. The rows are
read with SQL on the locked connection, never `get_post_meta()`.

CONTROLS, identical on both trees: four ordinary saves (percent, the same
percent again, fixed, none) all `saved` with the rows to match; a title-only
save (no nonce in the POST) leaves `percent/12.50` untouched and raises no
notice; and an actor with `edit_post` but without the financial permission
writes nothing.

-------------------------------------------------------------------- §2

  (a) item PAID through withdrawal #N, then a manual payment for it
      alpha.60   manual_code=supplier_payment_recorded      posted, 2 ledger lines
      alpha.61   manual_code=supplier_item_held_by_withdrawal
                 context: order_item_id, withdrawal_id, withdrawal_paid=1
                 payment_rows_for_item=0

  (b) item RESERVED in an open request, then a manual payment
      alpha.60   manual_code=supplier_payment_recorded      posted
      alpha.61   manual_code=supplier_item_held_by_withdrawal  withdrawal_paid=0

  (c) manual payment first, then the vendor's own cycle
      alpha.60   balance_eligible_has_c=yes   reserve_result=6   (claimed!)
      alpha.61   balance_eligible_has_c=no    reserve_result=0   item_withdrawal_id=__null__

  (d) both at once, TWO PROCESSES on two connections
      alpha.61   child_holding=yes · the payment's own
                 `INSERT … SELECT … WHERE oi.withdrawal_id IS NULL`
                 BLOCKED on the row the other process held, waited_ms=50012,
                 then `Lock wait timeout exceeded` → code=storage_failed,
                 payment_rows_for_item=0.

(d) is the one that shows the guard is the STATEMENT and not the read before
it. Read it with its cost: the 50 seconds are the server's
`innodb_lock_wait_timeout`, spent because the test holds a transaction open on
purpose. No code path in this plugin holds that row across a request, so the
wait a real manager could see is bounded by the other actor's transaction —
but that is an argument about our own code, not a limit this build enforces,
and it is named in the delivery note rather than fixed by widening the round.

NOTHING IS RELEASED and nothing is erased: in (a) and (b) the request still
holds its lines afterwards, and the refusal points at the request by number.

-------------------------------------------------------------------- §3

  payment over two items, the second with NO recorded share, amount equal to
  the FIRST item's share alone
    alpha.60   code=supplier_payment_recorded   db_posting=posted
               ledger_lines=2                  item_shares_stored=18:0|19:0
    alpha.61   code=supplier_payment_recorded_share_unknown
               db_posting=share_unknown        ledger_lines=0
               item_shares_stored=66:900000|67:__null__

The unknown item hid inside the arithmetic of the other one, and the payment
was declared to settle both. Now the comparison is refused, the payment is
still RECORDED (the money did leave), and which item could not be computed is
on the row — `NULL`, in a column migration 30 made nullable, rather than the
literal `0` that `alpha.60` wrote for every item.

-------------------------------------------------------------------- how to run

  WP="php /usr/local/bin/wp --allow-root --path=/path/to/disposable/wp"
  $WP eval-file tests/Support/alpha61-probe.php commission-arrange
  $WP eval-file tests/Support/alpha61-probe.php commission-value-fails
  $WP eval-file tests/Support/alpha61-probe.php commission-type-fails
  $WP eval-file tests/Support/alpha61-probe.php commission-regression
  $WP eval-file tests/Support/alpha61-probe.php commission-no-permission
  $WP eval-file tests/Support/alpha61-probe.php pay-arrange
  $WP eval-file tests/Support/alpha61-probe.php withdraw-and-pay
  $WP eval-file tests/Support/alpha61-probe.php reserve-and-pay
  $WP eval-file tests/Support/alpha61-probe.php pay-then-withdraw
  $WP eval-file tests/Support/alpha61-probe.php pay-null-share
  TMC_REPO=$PWD TMC_WP_PATH=/path/to/wp TMC_WPCLI=/usr/local/bin/wp \
    $WP eval-file tests/Support/alpha61-probe.php pay-concurrent
  $WP eval-file tests/Support/alpha61-probe.php reset

`reset` gives back everything the fixture spends — the product's two meta
rows, the order lines, the ledger events, the money unit, the withdrawals and
the payments — because a fixture that spends something and does not give it
back makes the NEXT run report a defect about a build that works
(`alpha.33`).

A MISTAKE OF MINE, recorded because the rule it broke is this repository's
own: the first version of the `reset` verb named the withdrawal tables from
memory (`M0005WithdrawalTables`), and that class does not exist — the real one
is `M0007SettlementTables`. The verb died half-way, the fixture was not given
back, and the next scenario measured the leftovers of the previous one.
«نام جدول را از کلاس migration اش بخوانید» (`alpha.10`), paid for again.
