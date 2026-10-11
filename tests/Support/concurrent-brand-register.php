<?php
/**
 * ONE process registering ONE brand — so two of them can be a real race.
 *
 * `alpha.40`'s rule: a third actor on your own connection is not a third
 * actor. Two calls inside one request share the directory's memo, WordPress's
 * term cache and the same database connection, so the only honest form of
 * «two managers approve the same name at the same moment» is two processes.
 *
 * Prints one line: `term=<id>` or `term=__null__`. The caller runs two of
 * these at once and counts the TERMS afterwards — one name must be one term,
 * whichever of them wins.
 *
 *   wp eval-file tests/Support/concurrent-brand-register.php "<name>"
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "term=refused\n";
    return;
}

$name = (string) ($args[0] ?? '');
if ($name === '') {
    echo "term=no_name\n";
    return;
}

/** @var BrandDirectoryInterface $directory */
$directory = Bootstrap::container()->get(BrandDirectoryInterface::class);
$brand = $directory->register($name);
echo 'term=' . ($brand === null ? '__null__' : (string) $brand->termId) . "\n";
