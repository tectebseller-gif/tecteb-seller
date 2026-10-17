alpha.65 — the read-before-lock in RecordSupplierPayment::void()
================================================================

WHAT IS IN HERE

  run.txt   56 checks, 0 failures. The BEFORE half runs against the bytes of
            the DELIVERED alpha.64 package as installed on the disposable
            WordPress; the AFTER half runs against this checkout's src/, swapped
            in by the script and put back in a trap with absolute paths.
            The installed digest is compared after the restore, not with
            `git diff` (`alpha.32`).

THE FINDING, AS THE OWNER STATED IT

  «در RecordSupplierPayment::void()، payment پیش از begin خوانده می‌شود و
   compensating بر اساس ledgerEvent همان خواندن تعیین می‌شود … بسنج آیا سند
   باطل و اقلام آزاد می‌شوند، ولی سند جبرانی ساخته نمی‌شود. این فعلاً یافتهٔ
   بررسی کد است، نه بازتولیدشده.»

  It is reproduced. On alpha.64, with an independent reconcile() committing in
  the gap (checks 2a–2l):

      void_code=supplier_payment_voided    compensating_event=__none__
      row_void_event=__none__              reversal_entry_exists=no
      payout_entry_exists=yes              vendor_earning_balance=0
      items_released_reported=1            live_claims=0
      withdrawal_reserved=TAKEN:<id>

  — the document voided, the items released, and no compensating document.
  On this tree the same run gives compensating_event=supplier-payment-void:<id>,
  reversal_entry_exists=yes and vendor_earning_balance=-900000, identical to
  the sequential control (checks 4a–4e and 7a–7d).

HOW THE INTERLEAVING IS PRODUCED

  Scheduled, not raced for, and not on the connection under test. A
  TransactionInterface decorator around the SAME gateway object (`alpha.56` —
  nesting is counted in the object) runs

      tests/Support/concurrent-supplier-reconcile.php <payment_id> [reconcile|void]

  in a separate process on the outermost begin() and waits for it to exit.
  There is no sleep anywhere. InterferingDatabase could not do this: its
  writes join the transaction under test and a rollback takes them away
  (`alpha.40`).

  Both orderings are measured, because the owner asked for both. The mirror —
  a void committing inside a reconcile()'s gap — was ALREADY refused on
  alpha.64 by markReconciled()'s own `voided_at IS NULL`, but as
  `storage_failed` and only after a ledger entry had been written and rolled
  back. The script PRINTS that before-code rather than asserting it (check 3),
  because a better answer is not a defect fix.

REPRODUCING IT

  # the disposable WordPress must have the DELIVERED alpha.64 installed
  wp plugin install dist/tecteb-marketplace-core-0.1.0-alpha.64.zip --force --activate
  WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
    bash tools/alpha64-reproduction.sh docs/evidence/alpha64-reproduction

  The probe can also be driven by hand (one key per line, `alpha.41`):

  TMC_REPO=$PWD TMC_WP_PATH=/home/user/wp-demo TMC_WPCLI=/usr/local/bin/wp \
    wp eval-file tests/Support/alpha64-probe.php <verb>
      arrange · void-after-reconcile · reconcile-after-void
      · reconcile-then-void · void-repeat · void-reversal-fails · report · reset

  `reset` gives back everything the run spends (`alpha.33`): the order line,
  the obligation and its history, every payment of the fixture supplier and
  their claim rows, the withdrawal requests, and that supplier's ledger
  entries.

ONE MEASUREMENT OF MINE THAT WAS WRONG, AND WAS CORRECTED

  The first version read the supplier's balance as `$row['account']` out of
  `balances()`, which returns a MAP of account => total rather than a list of
  rows. It printed nought on BOTH trees. A measurement that cannot fail is
  not a measurement (`alpha.46`); the probe now indexes the map.

WHAT IS NOT IN HERE

  No browser run (there is no new markup). PHP 8.1, Dokan, Min Max on the
  owner's site, a real theme and a persistent object cache are Not Run — not
  a pass.
