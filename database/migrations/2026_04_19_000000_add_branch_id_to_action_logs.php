<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [POS-9.1.4] Add branch_id to action_logs to enforce multi-tenant isolation
 * on the audit trail exposed by DashboardService::auditTrail().
 *
 * Additive only: column is nullable and left NULL for legacy rows (backfill
 * attempts to derive from user->branch_id where possible).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('action_logs', 'branch_id')) {
            Schema::table('action_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('user_id');
                $table->index('branch_id', 'action_logs_branch_id_idx');
            });

            // Best-effort backfill: when user still exists and has a branch, copy it.
            // [POS-9.1.4 / gate POS-9.1] Use a portable correlated UPDATE so the
            // migration runs identically on MySQL (prod) and SQLite (test suite).
            DB::statement(<<<'SQL'
                UPDATE action_logs
                SET branch_id = (
                    SELECT branch_id FROM users WHERE users.id = action_logs.user_id
                )
                WHERE branch_id IS NULL
                  AND user_id IS NOT NULL
                  AND EXISTS (
                    SELECT 1 FROM users
                    WHERE users.id = action_logs.user_id
                      AND users.branch_id IS NOT NULL
                  )
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('action_logs', 'branch_id')) {
            Schema::table('action_logs', function (Blueprint $table) {
                $table->dropIndex('action_logs_branch_id_idx');
                $table->dropColumn('branch_id');
            });
        }
    }
};
