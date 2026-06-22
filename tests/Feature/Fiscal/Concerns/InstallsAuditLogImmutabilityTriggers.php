<?php

namespace Tests\Feature\Fiscal\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * audit_logs INSERT-only triggers — same SQL as migration 2026_04_22_000002.
 *
 * MySQL: DROP TRIGGER is DDL and commits; tests that drop triggers must reinstall
 * or later raw UPDATE/DELETE tests and other suites see a table without triggers.
 */
trait InstallsAuditLogImmutabilityTriggers
{
    protected function dropImmutabilityTriggers(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        } catch (\Throwable $e) {
            // best-effort — unsupported drivers.
        }
    }

    /**
     * Drop + recreate triggers (e.g. after tampering tests). Do not call from
     * setUp/tearDown with MySQL + RefreshDatabase — DROP TRIGGER commits and
     * breaks PHPUnit savepoints.
     */
    protected function reinstallImmutabilityTriggers(): void
    {
        $driver = DB::getDriverName();
        $this->dropImmutabilityTriggers();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_logs is INSERT-only (NF525 / POS-9.4.3)';
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_logs is INSERT-only (NF525 / POS-9.4.3)';
                END
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs is INSERT-only (NF525 / POS-9.4.3)');
                END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs is INSERT-only (NF525 / POS-9.4.3)');
                END;
            SQL);
        }
    }
}
