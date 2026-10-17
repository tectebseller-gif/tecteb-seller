alpha.63 — two findings, reproduced on the bytes of the delivered alpha.62
and then measured again on this tree.

run.txt            41 checks, 0 failures. Produced by
                   tools/alpha63-reproduction.sh on the disposable WordPress
                   7.1 / WooCommerce 11.0.1 site, with the alpha.62 package
                   INSTALLED as the base (check 0a asserts that), its src/
                   swapped for this checkout's, and the original bytes put
                   back and hash-checked (9b).

What the numbers are, so that nobody has to take the sentences on trust:

  §1  BEFORE, on alpha.62:  needs_payment=no
                            classic_branch=without_payment
                            store_api_branch=without_payment
                            pay_action_would_charge=yes
                            store_api_order_processed=passed
      AFTER:                needs_payment=yes
                            classic_branch=payment
                            store_api_branch=payment
                            pay_action_would_charge=no  (+ the Persian notice)
                            store_api_order_processed=threw:RouteException
      CONTROL (a rule the line satisfies): everything sells, nothing is said.

      `classic_branch` and `store_api_branch` are not opinions about a hook.
      They are the expressions WooCommerce 11.0.1 evaluates at
      includes/class-wc-checkout.php:1444 and
      src/StoreApi/Routes/V1/Checkout.php:682, whose false branches are
      process_order_without_payment() and process_without_payment() — and the
      latter is $order->payment_complete() (CheckoutTrait.php:73).

  §2  BEFORE, on alpha.62:  rows_for_item=1   live_claims=0
                            holder=__none__   withdrawal_reserved=TAKEN:23
      AFTER:                rows_for_item=2   live_claims=1
                            holder=surviving_payment
                            withdrawal_reserved=refused
      CONTROL (both payments voided): live_claims=0, and TAKEN — release
      still happens, just not while somebody has been paid.

The sale gate is opened for the §1 measurements and put back by the probe.
Without that, both trees answer `pay_action_errors=1` — a true sentence about
CartGuard refusing a closed marketplace and nothing at all about the
commission rule (`alpha.46`: a control that cannot tell the trees apart is
not a control).

upgrade-alpha62-check.txt   22 checks, 0 failures: alpha.62 -> alpha.63 with
                            the data structure staying at 31 and no migration
                            step running, the data hashing the same before
                            and after, a rollback needing no database work,
                            and the cost of that rollback written down.
upgrade-alpha61-check.txt   91 checks, 0 failures: the two STRUCTURAL bases
                            (alpha.61 at 30 and alpha.56 at 28) upgraded from
                            this package.
