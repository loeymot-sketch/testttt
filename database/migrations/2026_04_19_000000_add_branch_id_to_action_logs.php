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
            DB::statement(<<<'SQL'
                UPDATE action_logs al
                INNER JOIN users u ON u.id = al.user_id
                SET al.branch_id = u.branch_id
                WHERE al.branch_id IS NULL AND u.branch_id IS NOT NULL
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
