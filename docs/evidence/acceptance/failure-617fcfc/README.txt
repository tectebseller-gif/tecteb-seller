OBSERVED FAILURE — gate G-04, package 617fcfc451e3d2e1a6451a41ed616330b7e265d2ac6b3549bdf7b94f7a94f30a

This directory holds the failing run of the package that was submitted for
acceptance. The package was rebuilt from its own commit (2c22bfc) and hashed
to exactly 617fcfc4…94f30a before the run, so this is that package, not a
lookalike.

WHAT WAS DONE
  A save through the real settings form that changed ONE field: max_staff
  33 -> 77. Three fields were left as they were (12.34% / 9 days / staging).

WHAT WAS EXPECTED
  One audit row naming only the field that changed:
      {"changed":["max_staff"],"old":{"max_staff":33},"new":{"max_staff":77}}

WHAT HAPPENED (G-04-audit-payload.txt)
  {"changed":["default_commission_rate_bp","settlement_delay_days","max_staff",
   "environment_override"],
   "old":{"default_commission_rate_bp":null,"settlement_delay_days":4,
          "max_staff":10,"environment_override":"auto"},
   "new":{...}}
  Every field is listed as changed and the OLD values are the schema defaults,
  which had not been the stored values since the first save. The value itself
  reached the database correctly; the trail describing the change did not.

ROOT CAUSE
  register_setting() was given the CURRENT stored values as the option's
  registered default. WordPress compares that default with the stored value to
  decide whether an option really exists:

      if ( apply_filters( "default_option_{$option}", false, $option, false ) === $old_value ) {
          return add_option( $option, $value, '', $autoload );
      }                                        -- wp-includes/option.php

  With the current values registered, that test was true on EVERY save, so every
  save was routed through add_option() and fired add_option_tmc_settings instead
  of update_option_tmc_settings. The audit was then written from the "the option
  did not exist" branch, whose "before" is by definition the schema defaults.

FIX
  Register the SCHEMA defaults (Settings::defaults()->toStored()), which is what
  the option's default actually is. See docs/review-fixes-phase-1.md §14 and
  docs/evidence/regression-registered-default.log.

The same gate passes on the delivered package
(c37f8902ef3152bfc897114f7044f546d521783528e2c9f38c1d3442227f46f1): see
../php81/G-04-summary.txt and ../php84/G-04-summary.txt. The first fix was built
as 6116950dca1c8790e68b1a129d6710e34db5d37be6733f81119faefe1825eaed, which was
superseded before delivery; docs/phase-1-report.md §4.1 lists the whole
lineage.
