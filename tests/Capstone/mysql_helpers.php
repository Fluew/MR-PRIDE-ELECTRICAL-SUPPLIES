<?php

/*
 * Guard and helpers for the MySQL/MariaDB capstone unit tests (tests/CapstoneMysql).
 * Test-only file. It changes nothing in the application.
 */

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Database isolation guard (runs when the file is loaded, before any test)
|--------------------------------------------------------------------------
| These tests use RefreshDatabase, which rebuilds the schema of the connected database. They may
| therefore run only against the dedicated local database `mr_pride_testing`. Anything else —
| another database name (such as the development database `mr_pride`), another host, a database
| URL, a cached configuration — stops the whole run before the application boots or a query is sent.
| There is no fallback to SQLite.
*/
const CAP_MYSQL_TEST_DATABASE = 'mr_pride_testing';

(function (): void {
    $expected = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => CAP_MYSQL_TEST_DATABASE, 'DB_URL' => '', 'DB_SOCKET' => ''];
    $cachedConfig = dirname(__DIR__, 2).'/bootstrap/cache/config.php';

    $problems = [];
    // Laravel reads environment values from $_SERVER and $_ENV before getenv(), so every source
    // must agree: a variable exported in the shell must not be able to outvote phpunit.mysql.xml.
    foreach ($expected as $name => $value) {
        $sources = ['getenv' => getenv($name), '$_ENV' => $_ENV[$name] ?? null, '$_SERVER' => $_SERVER[$name] ?? null];
        if ($sources['getenv'] === false) {
            $problems[] = "  {$name} is not set (expected ".var_export($value, true).')';
        }
        foreach ($sources as $source => $actual) {
            if ($actual !== null && $actual !== false && (string) $actual !== $value) {
                $problems[] = "  {$name} from {$source} = ".var_export($actual, true).' (expected '.var_export($value, true).')';
            }
        }
    }
    if (file_exists($cachedConfig)) {
        $problems[] = '  bootstrap/cache/config.php exists: run `php artisan config:clear` first.';
    }

    if ($problems) {
        fwrite(STDERR, "\nCAPSTONE MYSQL TESTS STOPPED: the dedicated testing database is not the only possible target.\n"
            .implode("\n", $problems)
            ."\n  Run them with:  php vendor/bin/pest -c phpunit.mysql.xml\n  No test was run and no database was touched.\n");
        exit(2);
    }
})();

require_once __DIR__.'/fixtures.php';

/** Second check, against the connection the booted application actually opened. */
function capAssertMysqlTestingDatabase(): void
{
    $connection = DB::connection();
    $actual = [
        'driver' => $connection->getDriverName(),
        'database' => $connection->getDatabaseName(),
        'server database' => $connection->selectOne('SELECT DATABASE() AS db')->db,
        'host' => $connection->getConfig('host'),
    ];
    $expected = ['driver' => 'mysql', 'database' => CAP_MYSQL_TEST_DATABASE, 'server database' => CAP_MYSQL_TEST_DATABASE, 'host' => '127.0.0.1'];

    if (! app()->environment('testing') || $actual !== $expected) {
        throw new RuntimeException('Capstone MySQL tests refuse to run: connected to '.json_encode($actual).' in ['.app()->environment().'].');
    }
}
