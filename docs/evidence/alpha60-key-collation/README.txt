The third hole in the commission guard: a Unicode collation imitated in PHP.

  collation-probe.php   the probe, run on each tree from its own ZIP
  before-alpha59.txt    the DELIVERED alpha.59 bytes
  after-alpha60.txt     this tree

alpha.59 compared the meta key with `strtolower(rtrim($key))`. That covered
the two spellings that had been measured — case and a trailing space — and
missed the ones that had not:

  '_tmc_commission' + U+200B + '_type'  = '_tmc_commission_type'   -> 1
  '_<FULLWIDTH T>MC_COMMISSION_VALUE'   = '_tmc_commission_value'  -> 1

A zero-width space is IGNORABLE in a UCA collation and a full-width letter
folds to its ASCII form, so MySQL writes to the real row while PHP calls the
key something else. Both numbers above are printed by the probe itself
(collation_calls_zwsp_ours / collation_calls_wide_ours), so the premise is
measured on the same site as the behaviour.

  before (alpha.59):  zwsp_write=landed  wide_write=landed
                      type_after=fixed   value_after=999999
  after  (alpha.60):  zwsp_write=refused wide_write=refused
                      type_after=percent value_after=12.50

CONTROL, on both trees: variation_key_write=landed. WooCommerce's own
variation keys are percent-encoded (`attribute_%d8%a7…`) and are the only key
on this catalogue outside plain ASCII — 1 distinct key out of 972 rows. That
is why refusing every unusual key was the wrong fix and why the question goes
to the database instead.

What PHP still answers, because measurement says it may: keys inside
`[A-Za-z0-9_-]`, where the same collation gives 0 for a missing underscore,
for hyphens in place of underscores, for a trailing tab and for a dotless ı.
