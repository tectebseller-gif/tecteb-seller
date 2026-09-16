<?php
/**
 * Reads the refund data store WooCommerce is ACTUALLY using on this site, and
 * reports how many separate writes its create path makes.
 *
 * Not a guessed class name. The first version of this probe read
 * `Abstract_WC_Order_Data_Store_CPT`, which is the legacy-posts path — and this
 * site runs HPOS, so it was describing code that never executes here. Both
 * backends turn out to have the same three-step shape, but that is a finding,
 * not something to assume.
 *
 * The point is also that the answer can change: if a future WooCommerce wraps
 * the three writes in a transaction, this line is where it shows up, and the
 * intent marker becomes belt-and-braces instead of load-bearing.
 */
$refund = new WC_Order_Refund();
$store = $refund->get_data_store()->get_current_class_name();

$read = static function (string $class, string $method): string {
    try {
        $r = new ReflectionMethod($class, $method);
    } catch (\Throwable) {
        return '';
    }
    $file = $r->getFileName();
    return $file === false ? '' : implode('', array_slice(
        file($file),
        $r->getStartLine() - 1,
        $r->getEndLine() - $r->getStartLine() + 1
    ));
};

// `create()` on the HPOS refund store just delegates, so the body that matters
// is the one it delegates TO.
$src = $read($store, 'create');
foreach (['persist_save', 'create'] as $candidate) {
    if (str_contains($src, $candidate . '(') && $candidate !== 'create') {
        $src .= $read($store, $candidate);
    }
}

$rowWrite = str_contains($src, 'wp_insert_post') || str_contains($src, 'persist_order_to_db');
$propWrite = str_contains($src, 'update_post_meta') || str_contains($src, 'update_order_meta');
$ourWrite = str_contains($src, 'save_meta_data');
$transaction = str_contains($src, 'START TRANSACTION') || str_contains($src, 'COMMIT');

// A refund's post_excerpt: the refund stores do not override
// `get_post_excerpt()`, so nothing in the row itself names the return.
$excerpt = false;
try {
    $m = new ReflectionMethod('WC_Order_Refund_Data_Store_CPT', 'get_post_excerpt');
    $excerpt = $m->getDeclaringClass()->getName() !== 'Abstract_WC_Order_Data_Store_CPT';
} catch (\Throwable) {
    $excerpt = false;
}

printf(
    "steps store=%s hpos=%s row_write=%d prop_write=%d stamp_write=%d transaction=%d excerpt_carries_reason=%d\n",
    $store,
    get_option('woocommerce_custom_orders_table_enabled') === 'yes' ? 'yes' : 'no',
    (int) $rowWrite,
    (int) $propWrite,
    (int) $ourWrite,
    (int) $transaction,
    (int) $excerpt
);
