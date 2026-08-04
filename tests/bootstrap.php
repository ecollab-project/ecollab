<?php

declare(strict_types=1);

/**
 * tests/bootstrap.php — PHPUnit bootstrap.
 *
 * IMPORTANT: pre-sets $_ENV['DB_NAME'] to a separate test database BEFORE
 * config.php loads .env. config.php's env-loader only sets a key
 * "if (!array_key_exists($key, $_ENV))" — so this override is picked up
 * automatically by Database::getInstance() with zero changes to any
 * application code.
 *
 * You must create this database once and run migrations against it:
 *   mysql -u root -e "CREATE DATABASE IF NOT EXISTS ecollab_test"
 *   DB_NAME=ecollab_test php database/migrate.php
 *
 * See tests/README.md for full setup instructions.
 */

$_ENV['DB_NAME'] = getenv('ECOLLAB_TEST_DB_NAME') ?: 'ecollab_test';
putenv('DB_NAME=' . $_ENV['DB_NAME']);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config.php';

// Sanity guard: refuse to run integration tests against anything that isn't
// clearly a test database, so a misconfigured environment can't accidentally
// truncate real data.
if (!str_contains(DB_NAME, 'test')) {
    fwrite(STDERR, "\nRefusing to run: DB_NAME (\"" . DB_NAME . "\") does not contain 'test'.\n" .
        "Set ECOLLAB_TEST_DB_NAME or ensure your test database name contains 'test'.\n\n");
    exit(1);
}
