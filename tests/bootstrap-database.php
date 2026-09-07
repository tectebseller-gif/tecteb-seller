<?php
declare(strict_types=1);

/*
 * Database-suite bootstrap: requires a DISPOSABLE MariaDB/MySQL reachable via
 * environment variables. Refuses to run (fails loudly) when they are missing,
 * so a skipped database suite can never be mistaken for a passed one.
 */
require __DIR__ . '/bootstrap-contract.php';

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "Database suite: environment variable {$var} is not set. Refusing to run.\n");
        exit(2);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "Database suite: DSN must point at a database named tmc_test (disposable). Refusing to run.\n");
    exit(2);
}
