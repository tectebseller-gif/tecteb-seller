<?php
/**
 * A SECOND WRITER on the destination's quantity rows, in its own process and
 * on its own connection.
 *
 * The only honest way to measure whether the rollback's compare-and-write is
 * protected. A closure, a filter or an `InterferingDatabase` all write on the
 * connection the code under test is using, so the moment that code holds a row
 * lock open they stop modelling another actor at all — `alpha.40`'s rule, and
 * the first version of this round's race measurement fell into it: the
 * injected write went through `$wpdb` inside the very transaction that held
 * the lock, so of course it was not blocked, and `alpha.53` and this tree
 * answered identically about a guard that does work.
 *
 * Two modes, and the difference between them is which side waits:
 *
 *   write <id> <max>       one `update_post_meta()`, committed immediately.
 *                          Blocks for as long as somebody else holds the row,
 *                          then lands — so a caller that takes the lock first
 *                          is measuring «my write won and theirs queued».
 *   hold <id> <max> <fifo> take the three rows `FOR UPDATE`, write the value,
 *                          and HOLD the transaction open until a line arrives
 *                          on the FIFO. No `sleep` anywhere: the wait is a
 *                          blocking read, so the other side decides when this
 *                          one lets go. That is what makes the measurement
 *                          repeatable instead of a coin toss.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/concurrent-quantity-edit.php write <id> <max>
 *   wp eval-file tests/Support/concurrent-quantity-edit.php hold <id> <max> <fifo>
 */

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) $value) . "\n";
};
$say('writer', 'ran');

$mode = (string) ($args[0] ?? 'write');
$postId = (int) ($args[1] ?? 0);
$value = (string) ($args[2] ?? '8');
$fifo = (string) ($args[3] ?? '');
$key = '_tmc_max_purchase';

if ($postId <= 0) {
    $say('refused', 'no_product');
    return;
}

global $wpdb;

if ($mode === 'hold') {
    if ($fifo === '') {
        $say('refused', 'no_fifo');
        return;
    }
    $wpdb->query('SET SESSION innodb_lock_wait_timeout = 2');
    $wpdb->query('START TRANSACTION');
    $wpdb->get_results($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
          WHERE post_id = %d AND meta_key IN (%s, %s, %s, %s) FOR UPDATE",
        $postId,
        '_tmc_min_purchase',
        $key,
        '_tmc_purchase_step',
        '_tmc_quantity_written'
    ));
    $wrote = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s",
        $value,
        $postId,
        $key
    ));
    $say('holder_wrote', $wrote === false ? 'failed' : (string) $wrote);
    $say('holder_holding', 'yes');
    flush();
    // A blocking read, not a sleep: the other side writes one line when it has
    // finished measuring, and only then is the lock released.
    $handle = fopen($fifo, 'r');
    if ($handle !== false) {
        fgets($handle);
        fclose($handle);
    }
    $wpdb->query('COMMIT');
    $say('holder_committed', 'yes');
    $say('holder_value', (string) get_post_meta($postId, $key, true));
    return;
}

// `write`: the ordinary second writer. It may block behind a lock; whether it
// does is the measurement the caller is after.
$started = microtime(true);
$wpdb->query('SET SESSION innodb_lock_wait_timeout = 20');
$ok = update_post_meta($postId, $key, $value);
$say('write_ok', $ok ? 'yes' : 'no');
$say('waited_ms', (int) round((microtime(true) - $started) * 1000));
$say('value_now', (string) get_post_meta($postId, $key, true));
