<?php

namespace Tests\Capstone;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base class of the MySQL/MariaDB capstone unit tests. Test-only.
 *
 * RefreshDatabase rebuilds the schema of the connected database, so the connection the booted
 * application really opened is checked in the hook Laravel calls BEFORE it migrates: unless that
 * connection is `mr_pride_testing` on the local server, the test stops and nothing is migrated.
 */
abstract class MysqlTestCase extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        capAssertMysqlTestingDatabase();
    }
}
