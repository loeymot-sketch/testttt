<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [POS-9-H.2.2 / F-C3 / F-B1]
 *
 * UNIQUE(branch_id, prev_hash) on `audit_logs`.
 *
 * Prevents chain forks caused by concurrent writers. If two requests
 * land on the same leader at the same millisecond and both read the
 * same tail, they would both attempt to INSERT with an identical
 * `prev_hash` — the resulting pair forks the chain and verifyChain()
 * flags one of them as tampering. The service layer already takes
 * a Cache::lock (AuditLogService::write()) but the cache driver can
 * be offline or split-brained; this index is the defense-in-depth
 * guarantee that the DB rejects the fork even when Redis is down.
 *
 * The first row of a chain has prev_hash = NULL. On MySQL NULLs do
 * not collide under a UNIQUE constraint (each NULL is distinct), and
 * SQLite ≥ 3.9 ships the same semantics, so the initial row per
 * branch is safe.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->unique(
                    ['branch_id', 'prev_hash'],
                    'audit_logs_branch_prev_unique'
                );
            });
        } catch (\Throwable $e) {
            // Already present — makes the migration idempotent across
            // partial runs (fresh DB, re-seed, CI re-runs). Doctrine is
            // not required to diff indexes this way.
            if (!str_contains(strtolower($e->getMessage()), 'unique')
                && !str_contains(strtolower($e->getMessage()), 'duplicate')
                && !str_contains(strtolower($e->getMessage()), 'already exists')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }
        if (app()->environment('production')) {
            // Mirror the audit_logs table rollback guard: refuse to drop
            // chain-integrity structures in production.
            throw new \RuntimeException(
                'Refusing to drop audit_logs_branch_prev_unique in APP_ENV=production.'
            );
        }
        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropUnique('audit_logs_branch_prev_unique');
            });
        } catch (\Throwable $e) {
            // index already missing — ok
        }
    }
};
