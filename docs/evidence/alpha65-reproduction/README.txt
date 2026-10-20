alpha.66 — the three things the owner's manual staging test ran into
====================================================================

What is in here
---------------

  before-alpha65.txt   the first pass, taken by hand on the INSTALLED bytes
                       of 0.1.0-alpha.65 (commit bb02a96) before a line of
                       this round was written. Kept because it is the only
                       record of the state the owner was looking at.

  run.txt              tools/alpha65-reproduction.sh — 113 checks, 0 failures.
                       BEFORE on alpha.65's bytes (checked out of git into the
                       installed plugin), then AFTER on this tree, then five
                       controls that must answer the SAME on both.

How to reproduce
----------------

  WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
    bash tools/alpha65-reproduction.sh docs/evidence/alpha65-reproduction

The script resolves alpha.65's commit from the git log rather than from a
number typed into it, swaps src/ under the installed plugin, measures, and
puts the original bytes back in a trap with an absolute path — then checks the
hash afterwards rather than asking git whether the tree is clean.

What this is NOT
----------------

  * Dokan Lite 5.1.1 was NOT activated and cannot be in this environment: the
    copy on this machine is the owner's, whose first line require_once's a
    SourceGuardian-encrypted abzarwp/abzarwp.php and needs a loader that is
    not here (alpha.53). What the fixture builds is exactly what
    WpDokanReader::isAvailable() asks for — the dokan_orders table — plus a
    user with the `seller` role, so OUR reader and OUR plan run. Dokan itself
    is Not Run.

  * No browser checkout was run. The order in section 19 of run.txt is created
    programmatically and completed with payment_complete(), and the capture
    hook fired is woocommerce_checkout_order_processed — the same hook
    WC_Checkout::process_checkout() fires at the same point, with the same
    arguments. The hook a checkout fires is not a checkout. Not Run.

  * PHP 8.1 is not installed in this environment. Not Run.

  * The real theme and a persistent object cache are Not Run.

None of the three is reported as a pass anywhere.
