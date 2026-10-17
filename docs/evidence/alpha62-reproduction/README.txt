alpha.62 — the owner's seven decisions, measured on the disposable site
=======================================================================

WHAT IS HERE

  run.txt    tools/alpha62-reproduction.sh — 108 checks, 0 failures, on real
             WooCommerce 11.0.1, from the INSTALLED bytes of
             dist/tecteb-marketplace-core-0.1.0-alpha.62.zip.

  ../upgrade-alpha62/upgrade-alpha61-check.txt
             tools/upgrade-alpha61-check.sh — 91 checks, 0 failures, with TWO
             bases read from the packages themselves: alpha.61 (30 → 31, one
             step) and alpha.56 (28 → 31, three steps).

HOW TO RUN IT AGAIN

  WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
    bash tools/alpha62-reproduction.sh docs/evidence/alpha62-reproduction

  PHPBIN=php WPCLI=/usr/local/bin/wp \
    bash tools/upgrade-alpha61-check.sh docs/evidence/upgrade-alpha62

WHY THERE IS NO «BEFORE» TREE FOR THE SEVEN

  The three findings of alpha.61 were reproduced on alpha.60's own bytes last
  round and that evidence stands (docs/evidence/alpha61-reproduction/). This
  round implements DECISIONS the owner made, so there is no earlier build in
  which they were wrong — only one in which they did not exist. Where that
  matters the probe says so rather than being silent: its verbs answer
  `absent=1` on the older tree, and the upgrade script measures exactly that
  on both bases (`2b-d`, `7d-e`).

THE FOUR THINGS THIS RUN FOUND OUT ABOUT ITSELF

  1. The sale gate and the trial switch are REFUSED on a «production»
     environment, which is what WP-CLI reports on this site. Without
     `--exec=define('WP_ENVIRONMENT_TYPE','local');` the first cart
     measurement read «no error» — a true sentence about a closed gate in the
     clothing of a commission rule (`alpha.53`'s rule, paid for again).

  2. An earlier, broken version of `cart-arrange` made a PLAIN WooCommerce
     product with the fixture's SKU. Once the verb was corrected to build a
     real marketplace product there were two products carrying that SKU, the
     SKU lookup returned the plain one, and the guard answered `not_ours` —
     correctly — which read as «the guard did not fire». The verb now resolves
     the product through the MARKETPLACE ROW, and `cart-reset` gives both
     sides back.

  3. The audit table is `tmc_audit_events` with a column named `event_type`.
     The first version of the picker verb asked for `tmc_audit_log` and
     `event`, and reported `audit_rows_after=0` about a page that was writing
     its row perfectly well. Table and column names come from the migration
     class now (`alpha.10`).

  4. `withdraw-seed` left an open withdrawal behind, and §7 then reported
     three true sentences about the PREVIOUS run: a request already existed
     and the eligible balance was smaller. `reset` gives the withdrawals back
     too (`alpha.33`), and §7 runs on a fresh fixture so its figure is
     unambiguous.

AND ONE THING THE SUITES FOUND OUT ABOUT THE RUNNERS

  Two phpunit runs against one database is the `alpha.40` trap: the database
  suite reported 179 errors and 21 failures while another run of itself was
  still going. Alone, the same tree answers OK (665 tests, 8055 assertions).

NOT RUN — AND THAT IS NOT A PASS

  PHP 8.1 (no interpreter in this environment; lint.sh says so out loud),
  Dokan Lite and Pro, Min Max Control, the owner's real theme, a persistent
  object cache, the browser suite for this round, and any installation on
  tecteb.com or staging.tecteb.com.
