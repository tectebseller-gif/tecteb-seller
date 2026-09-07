<?php
declare(strict_types=1);

/*
 * Unit-suite bootstrap. Deliberately loads NO WordPress code and NO stubs:
 * if anything under Core/ or Contracts/ calls a WordPress function, the test
 * fails with "undefined function". tests/Unit/UnitSuiteGuardTest.php asserts
 * that invariant explicitly.
 */
require __DIR__ . '/../src/Core/Autoloader.php';
\Tecteb\Marketplace\Core\Autoloader::register(__DIR__ . '/../src');
require __DIR__ . '/../vendor/autoload.php'; // tests/ namespace only; src/ is NOT mapped here
