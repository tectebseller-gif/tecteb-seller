<?php
/*
 * WordPress STUBS for the contract and database suites.
 *
 * These are not WordPress. They model just enough of the functions the
 * plugin calls to check the plugin's *contract* with WordPress (which hooks
 * it registers, which capability it checks, what it echoes, which SQL it
 * sends). Evidence produced with these stubs is reported separately and is
 * never presented as WordPress integration testing.
 */
declare(strict_types=1);

require_once __DIR__ . '/State.php';
require_once __DIR__ . '/classes.php';
require_once __DIR__ . '/wpdb.php';
require_once __DIR__ . '/functions.php';

\TmcWpStubs\State::reset();
