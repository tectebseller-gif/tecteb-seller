The two holes `alpha.58` shipped in the commission guard — measured on the
disposable WordPress 7.1 with real WooCommerce 11.0.1 and a real
`shop_manager`, each tree from its own ZIP.

  bypass-probe.php          both bypasses, asked through WooCommerce's object
  before-alpha58.txt        the DELIVERED alpha.58 bytes
  after-alpha59.txt         this tree
  control-probe.php         the shop manager's OWN custom meta, three stages
  control-after-alpha59.txt …each stage in its own process

HOLE 1 — the row-addressed verbs, which is the path WooCommerce takes.
`WC_Data_Store_WP::update_meta()` calls `update_metadata_by_mid()` and
`delete_meta()` calls `delete_metadata_by_mid()`
(class-wc-data-store-wp.php:132,155). Those fire
`update_post_metadata_by_mid` / `delete_post_metadata_by_mid`, which alpha.58
did not hook. A REST `meta_data` entry carrying an `id` — and the REST API
hands out an `id` for every row — becomes exactly that call.

  before:  after_wc_object_save=999999   type_after_wc_object_delete=[]
  after:   after_wc_object_save=12.50    type_after_wc_object_delete=[percent]

HOLE 2 — `===` is not how MySQL compares a meta key. `wp_postmeta.meta_key` is
`utf8mb4_unicode_520_ci` here: case-insensitive and PAD SPACE, so
`WHERE meta_key = '_TMC_COMMISSION_VALUE'` finds the lower-case row.

  before:  uppercase_write=landed   value_after_uppercase=777777
           trailing_space_write=landed  value_after_trailing_space=888888
           rows_for_value=1   ← it overwrote the real row, it did not add one
  after:   both refused, value stays 12.50

CONTROL — the guard now intercepts `update_metadata_by_mid`, which WooCommerce
uses for EVERY meta row it already knows, so a guard one character too wide
would stop the shop saving products. Measured across three processes, as the
shop manager, on a key WooCommerce treats as real custom meta:

  add → [first] · update → [second] · delete → []   and commission 12.50 throughout

The first version of that control used `_purchase_note` and its delete looked
refused. That was NOT the guard: `_purchase_note` is in WooCommerce's
`$internal_meta_keys` (class-wc-product-data-store-cpt.php:59), so
`WC_Product` keeps it as a prop and `delete_meta_data()` never sees it. Ask
which backend runs before attributing anything — and a control that measures
the wrong key is not a control.
