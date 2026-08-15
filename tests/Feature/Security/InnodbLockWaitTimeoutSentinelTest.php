<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Sentinel — F2-HEAL-02 — innodb_lock_wait_timeout per-session cap.
 *
 * Phase F.6 error-storm audit F-6-4-FIND-02: MySQL default
 * innodb_lock_wait_timeout=50s × FPM worker pool = realistic DoS surface
 * under hot-row contention (fiscal_sequence allocation, Idempotency-Key
 * locks, OrderStateMachine lockForUpdate). 30+ lockForUpdate call sites
 * had no application-side cap.
 *
 * Fix lives in app/Providers/AppServiceProvider.php::boot — guards:
 *   1) SET SESSION innodb_lock_wait_timeout = ? statement present
 *   2) env() variable DB_LOCK_WAIT_TIMEOUT (default 5)
 *   3) Guarded by getDriverName() === 'mysql' (SQLite/Pg noop)
 *
 * This is a STATIC AST-equivalent guard: we read the file source and
 * assert the literal contract. It does NOT exercise the runtime SET
 * SESSION (CI is SQLite — the guard intentionally skips). The presence
 * of the statement + the driver guard + the env var is the contract.
 *
 * @see app/Providers/AppServiceProvider.php
 * @see plans/GOAL_ULTRA_DEEP_F2_2026-05-23.md
 * @see reports/test-e2e/goal-ultra-deep-2026-05-23/phase-f/F-6-4-FIND-02.json
 */
class InnodbLockWaitTimeoutSentinelTest extends TestCase
{
    private function readProvider(): string
    {
        $path = base_path('app/Providers/AppServiceProvider.php');
        $this->assertFileExists($path, 'AppServiceProvider.php missing — F2-HEAL-02 invariant broken');

        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertNotEmpty($source);

        return $source;
    }

    public function test_app_service_provider_sets_innodb_lock_wait_timeout(): void
    {
        $source = $this->readProvider();

        $this->assertStringContainsString(
            'SET SESSION innodb_lock_wait_timeout',
            $source,
            'AppServiceProvider must contain `SET SESSION innodb_lock_wait_timeout` '
            . 'statement (F2-HEAL-02 — prevents FPM pool starvation under hot-row '
            . 'contention). MySQL default 50s × worker pool = realistic DoS surface.'
        );
    }

    public function test_app_service_provider_reads_db_lock_wait_timeout_env(): void
    {
        $source = $this->readProvider();

        $this->assertMatchesRegularExpression(
            "/env\\(\\s*['\"]DB_LOCK_WAIT_TIMEOUT['\"]\\s*,\\s*5\\s*\\)/",
            $source,
            'AppServiceProvider must read env(\'DB_LOCK_WAIT_TIMEOUT\', 5) '
            . '(F2-HEAL-02 — env-configurable cap, default 5s aligned with '
            . 'fiscal Cache::lock TTL).'
        );
    }

    public function test_app_service_provider_guards_mysql_driver(): void
    {
        $source = $this->readProvider();

        $this->assertMatchesRegularExpression(
            "/getDriverName\\(\\)\\s*===\\s*['\"]mysql['\"]/",
            $source,
            'AppServiceProvider must guard the SET SESSION statement with '
            . '`getDriverName() === \'mysql\'` (F2-HEAL-02 — SQLite/Postgres '
            . 'do not implement innodb_lock_wait_timeout; the guard ensures '
            . 'noop on non-MySQL drivers).'
        );
    }

    public function test_app_service_provider_wraps_in_try_catch(): void
    {
        $source = $this->readProvider();

        // The SET SESSION must be inside a try/catch so a transient DB error
        // at boot does not crash the entire request — pool starvation is a
        // perf risk, not a correctness risk.
        $this->assertMatchesRegularExpression(
            "/try\\s*\\{[^}]*SET SESSION innodb_lock_wait_timeout[^}]*\\}\\s*catch/s",
            $source,
            'The SET SESSION innodb_lock_wait_timeout statement must be wrapped '
            . 'in try/catch (F2-HEAL-02 — non-fatal: log warning on failure, '
            . 'continue boot. Perf risk, not correctness risk).'
        );
    }
}
