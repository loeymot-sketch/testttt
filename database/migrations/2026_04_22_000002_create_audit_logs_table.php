<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [POS-9.4.3 / POS-GA-F-04]
 *
 * Creates `audit_logs`, the INSERT-only evidence trail required by
 * NF525 / Loi Finance 2018 anti-fraude TVA and by our own invariant
 * §1.2 "Audit log immuable".
 *
 * Shape (per plan POS-9.4 item 9.4.3):
 *   id, branch_id, user_id, action, resource, resource_id, payload JSON,
 *   prev_hash CHAR(64), current_hash CHAR(64), created_at, ip, user_agent,
 *   session_id.
 *
 * Immutability is enforced two ways:
 *   - on MySQL / MariaDB: BEFORE UPDATE and BEFORE DELETE triggers that
 *     raise SIGNAL SQLSTATE '45000' — any UPDATE/DELETE is rejected at the
 *     DB level even by DBAs with raw SQL access.
 *   - on SQLite (test runner): equivalent triggers using RAISE(ABORT, …)
 *     so the invariant is covered end-to-end in PHPUnit.
 *
 * The service layer (AuditLogService) is the sole authorised writer
 * and computes the `current_hash` HMAC chain (POS-9.4.4).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();

                $table->string('action', 64);
                $table->string('resource', 64)->nullable();
                $table->unsignedBigInteger('resource_id')->nullable();

                $table->json('payload')->nullable();

                $table->char('prev_hash', 64)->nullable();
                $table->char('current_hash', 64);

                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->string('session_id', 128)->nullable();

                $table->timestamp('created_at')->useCurrent();

                $table->index(['branch_id', 'created_at']);
                $table->index(['resource', 'resource_id']);
            });
        }

        $this->installImmutabilityTriggers();
    }

    public function down(): void
    {
        // [POS-9-H.2.9 / F-B7]
        // NF525 / Loi Finance 2018 require fiscal evidence to be retained
        // 6 years. Rolling this migration back in production would drop
        // the whole audit chain — a hard compliance violation. Block it
        // explicitly so an accidental `migrate:rollback` on production
        // fails loud instead of silently erasing evidence.
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'Refusing to drop audit_logs in APP_ENV=production. '
                . 'NF525 mandates 6-year retention. '
                . 'If rollback is truly required, set APP_ENV=maintenance and coordinate with legal.'
            );
        }

        $this->dropImmutabilityTriggers();

        if (Schema::hasTable('audit_logs')) {
            Schema::drop('audit_logs');
        }
    }

    /**
     * Install DB-level triggers that reject any UPDATE/DELETE on
     * audit_logs, making the table truly INSERT-only.
     */
    private function installImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        // Always drop first (idempotent).
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

        // Other drivers (pgsql, sqlsrv) — application-layer enforcement only,
        // because the rejection syntax differs too much to be generic. In
        // production we target MySQL / MariaDB exclusively (see config/database.php).
    }

    private function dropImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql' || $driver === 'mariadb' || $driver === 'sqlite') {
                DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
                DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
            }
        } catch (\Throwable $e) {
            // best-effort; migrations must stay re-runnable.
        }
    }
};
