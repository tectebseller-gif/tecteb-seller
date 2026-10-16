The commission meta guard, measured on the disposable WordPress 7.1 with real
WooCommerce 11.0.1 and a real `shop_manager` account.

  guard-probe.php     the probe, run with `wp eval-file` on both trees
  before-alpha57.txt  the DELIVERED alpha.57 bytes (its own ZIP, src swapped in)
  after-alpha58.txt   the alpha.58 bytes (its own ZIP)

What it asks, and why through `update_post_meta()` rather than through any of
our classes: that call is where WooCommerce's REST `meta_data` handling and its
own CSV importer both end up, so it is the only honest place to ask whether a
`shop_manager` can move the owner's commission.

before (alpha.57):  manager_update=landed  manager_delete=landed
                    value_after_manager=999999   ← §5-هـ broken
after  (alpha.58):  manager_update=refused manager_delete=refused
                    type_after_manager=percent  value_after_manager=12.50

Read `admin_write=refused` in before-alpha57.txt correctly: the alpha.58 run
had already stored `percent`, and `update_post_meta()` answers `false` for
«failed» and for «it already said that» alike (the `alpha.44` rule). It is not
the guard refusing the administrator — `stored_type` on the next line is
`percent`, which is what was asked for.

`manager_other_meta=landed` on both trees is the control: the guard is about
two keys, not about a shop manager's ordinary product work.
