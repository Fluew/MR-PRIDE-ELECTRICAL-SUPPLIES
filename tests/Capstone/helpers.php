<?php

/*
 * Shared helpers for the Chapter 4 capstone test suites:
 *   tests/Unit/Capstone      (4.4.1 Unit Testing,        IDs UT-xx)
 *   tests/Feature/Capstone   (4.4.2 Integration Testing, IDs IT-xx)
 *
 * This file is test-only. It changes nothing in the application.
 */

/*
|--------------------------------------------------------------------------
| Database isolation guard (runs when the file is loaded, before any test)
|--------------------------------------------------------------------------
| The capstone tests use RefreshDatabase, which rebuilds the schema of whatever database the
| application is connected to. They must therefore only ever see the in-memory SQLite database
| configured in phpunit.xml. If the environment says anything else, or a cached configuration
| could override it, stop the whole run before a single query is sent.
*/
(function (): void {
    $connection = getenv('DB_CONNECTION');
    $database = getenv('DB_DATABASE');
    $cachedConfig = dirname(__DIR__, 2).'/bootstrap/cache/config.php';

    if ($connection !== 'sqlite' || $database !== ':memory:' || getenv('APP_ENV') !== 'testing' || file_exists($cachedConfig)) {
        fwrite(STDERR, "\nCAPSTONE TESTS STOPPED: the isolated test database is not active.\n"
            .'  DB_CONNECTION='.var_export($connection, true).' (expected sqlite), DB_DATABASE='.var_export($database, true)
            .' (expected :memory:), APP_ENV='.var_export(getenv('APP_ENV'), true)." (expected testing).\n"
            .(file_exists($cachedConfig) ? "  bootstrap/cache/config.php exists: run `php artisan config:clear` first.\n" : '')
            ."  No test was run and no database was touched.\n");
        exit(2);
    }
})();

/** Second check, against the configuration the booted application actually uses. */
function capAssertIsolatedDatabase(): void
{
    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");

    if (! app()->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
        throw new RuntimeException("Capstone tests refuse to run against [{$connection}:{$database}] in [".app()->environment().'].');
    }
}

require_once __DIR__.'/fixtures.php';
