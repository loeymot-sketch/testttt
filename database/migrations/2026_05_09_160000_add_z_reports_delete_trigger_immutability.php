<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [P0-FIX-3 NF525 / POS-9.4.6 — 2026-05-09]
 *
 * z_reports DELETE immutability via MySQL trigger.
 *
 * audit_logs already has UPDATE+DELETE triggers (migration
 * 2026_04_22_000002). z_reports had NO database-level guard —
 * fully mutable via raw SQL or Eloquent. NF525 mandates fiscal
 * Z reports must survive 6 years post-close, so DELETE must be
 * forbidden once the row exists.
 *
 * UPDATE is INTENTIONALLY allowed because z_reports has a
 * legitimate state machine: open → closed (sign + lock totals)
 * → archived (FiscalArchiveCommand sets archived_at). Cash
 * enrichment (ZReportCashEnrichmentService) also UPDATEs post-close.
 *
 * Driver-conditional: MySQL/MariaDB get the trigger, SQLite/other
 * test runners skip silently. Same pattern as audit_logs migration.
 *
 * Edge cases :
 *  - FK CASCADE from a future parent table → trigger fires →
 *    transaction rolls back → parent delete fails. This is
 *    DEFENSIVE: fiscal trail must outlive parent rows.
 *  - TRUNCATE bypasses MySQL triggers. Mitigation = revoke
 *    TRUNCATE permission on production DB user (deploy doc,
 *    not migration scope).
 *  - Existing z_reports rows are protected immediately (trigger
 *    applies to ALL future DELETE attempts).
 *
 * Rollback: drops the trigger cleanly on dev/staging via down().
 * Production should NEVER rollback this trigger (NF525 6y retention).
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $this->dropTrigger();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER z_reports_no_delete
            BEFORE DELETE ON z_reports
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'z_reports is immutable post-close (NF525 / POS-9.4.6) — DELETE forbidden';
            END
        SQL);
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $this->dropTrigger();
    }

    private function dropTrigger(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS z_reports_no_delete');
        } catch (\Throwable $e) {
            // best-effort: migration must remain re-runnable in dev
        }
    }
};
