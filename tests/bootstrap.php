<?php

declare(strict_types=1);

/**
 * tests/bootstrap.php — PHPUnit bootstrap.
 *
 * IMPORTANT — read this before changing anything here:
 *
 * vendor/bin/phpunit loads vendor/autoload.php to get its own classes
 * BEFORE it reads phpunit.xml to find this file. composer.json's
 * autoload.files lists config.php as its first entry, so config.php
 * (and therefore the DB_NAME constant) is already loaded from your REAL
 * .env by the time this file's code runs. Setting $_ENV['DB_NAME'] here
 * has no effect — PHP constants can't be redefined once set.
 *
 * Consequences:
 *   - Unit tests never touch the database, so they don't need DB_NAME
 *     to be anything in particular. We skip the DB guard entirely for a
 *     Unit-only run (detected below), so `--testsuite Unit` works with
 *     zero database setup.
 *   - Integration tests DO need a real, separate test database. Because
 *     of the load-order issue above, the ONLY place that can still work
 *     is a real OS-level environment variable, set in your terminal
 *     BEFORE you invoke phpunit at all — not inside any PHP file:
 *
 *       set DB_NAME=ecollab_test          (Windows cmd)
 *       vendor\bin\phpunit --testsuite Integration
 *
 *     This only works once config.php's own .env-loading logic is
 *     updated to respect an already-set environment variable (it
 *     currently only checks $_ENV, which a shell-set env var doesn't
 *     populate unless php.ini's variables_order includes "E"). See
 *     tests/README.md for that fix and full setup instructions.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config.php';

// Detect a Unit-only invocation (e.g. `phpunit --testsuite Unit` or
// `--testsuite=Unit`) and skip the database guard entirely — Unit tests
// have no DB dependency, so requiring a test database for them is wrong.
$argv = $_SERVER['argv'] ?? [];
$isUnitOnly = false;
foreach ($argv as $i => $arg) {
    if ($arg === '--testsuite' && isset($argv[$i + 1]) && $argv[$i + 1] === 'Unit') {
        $isUnitOnly = true;
    }
    if (str_starts_with($arg, '--testsuite=') && substr($arg, 12) === 'Unit') {
        $isUnitOnly = true;
    }
}

if (!$isUnitOnly) {
    // Sanity guard: refuse to run anything that might touch the database
    // unless DB_NAME is clearly a test database, so a misconfigured
    // environment can't accidentally run integration tests against real
    // data. See the docblock above for how to actually set DB_NAME for
    // Integration tests.
    if (!str_contains(DB_NAME, 'test')) {
        fwrite(STDERR, "\nRefusing to run: DB_NAME (\"" . DB_NAME . "\") does not contain 'test'.\n" .
            "See tests/bootstrap.php and tests/README.md for how to point this at a test database.\n" .
            "(Running only the Unit suite? Use: vendor\\bin\\phpunit --testsuite Unit — no database needed.)\n\n");
        exit(1);
    }
}
