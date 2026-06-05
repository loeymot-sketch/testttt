<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        self::guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * [DEVDB-GUARD 2026-06-05] Refuse to run the test suite against a non-test database.
     *
     * RefreshDatabase runs `migrate:fresh`, which WIPES the target database. A
     * `.env.testing` whose DB_DATABASE points at an operating DB (e.g. `foodking`)
     * therefore destroys live/dev data — exactly the incident on 2026-06-05
     * (see reports/test-e2e/cutover-validation/INCIDENT_DEVDB_WIPE_2026-06-05.md).
     *
     * This guard runs at app-boot in createApplication(), i.e. BEFORE the
     * RefreshDatabase trait migrates, and aborts unless the configured test DB is
     * clearly disposable: an in-memory sqlite DB, or a name containing "test".
     * Bypass intentionally (only if you really mean it): ALLOW_NON_TEST_DB=1.
     */
    protected static function guardAgainstNonTestDatabase($app): void
    {
        if (getenv('ALLOW_NON_TEST_DB') === '1' || ($_SERVER['ALLOW_NON_TEST_DB'] ?? null) === '1') {
            return;
        }

        $conn     = (string) $app['config']->get('database.default');
        $driver   = (string) $app['config']->get("database.connections.$conn.driver");
        $database = (string) $app['config']->get("database.connections.$conn.database");

        $isMemorySqlite = $driver === 'sqlite' && ($database === ':memory:' || $database === '');
        $looksLikeTest  = stripos($database, 'test') !== false;

        if ($isMemorySqlite || $looksLikeTest) {
            return;
        }

        fwrite(STDERR, "\n\033[41m DEVDB-GUARD: REFUSING TO RUN TESTS \033[0m against database '{$database}' (connection '{$conn}').\n"
            ."  It is not a recognised test database (':memory:' or a name containing 'test').\n"
            ."  RefreshDatabase would `migrate:fresh` and WIPE it. Point DB_DATABASE at a *_test DB in .env.testing.\n"
            ."  (Set ALLOW_NON_TEST_DB=1 only if you truly intend to target this database.)\n\n");

        throw new \RuntimeException(
            "DEVDB-GUARD: refusing to run tests against non-test database '{$database}'. "
            ."Set DB_DATABASE to ':memory:' or a *_test database in .env.testing, "
            ."or export ALLOW_NON_TEST_DB=1 to override."
        );
    }
}
