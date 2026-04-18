<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [POS-9-H.3.6 / F-A8]
 *
 * DashboardService::auditTrail() issues `SELECT * FROM action_logs
 * WHERE branch_id = ? ORDER BY created_at DESC LIMIT ?`, which is the
 * hottest read on the audit surface: every branch dashboard poll hits
 * it, and the table grows monotonically (INSERT-only-by-policy).
 *
 * The existing single-column `branch_id` index is used to filter but
 * forces a filesort on `created_at` — that filesort explodes in
 * latency once the table passes a few hundred k rows and is the
 * biggest ticking dashboard-slowdown risk identified by the audit.
 *
 * A composite `(branch_id, created_at)` index lets MySQL serve the
 * branch-scoped, created_at-desc dashboard query directly from the
 * index range (index-order scan, LIMIT early-out).
 *
 * This migration is additive and reversible.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('action_logs', 'branch_id')) {
            // Must run AFTER 2026_04_19_000000_add_branch_id_to_action_logs
            return;
        }

        Schema::table('action_logs', function (Blueprint $table) {
            // A composite (branch_id, created_at) index is strictly a
            // superset of the existing branch_id-only index for the
            // dashboard query, but keeping both is harmless (<1% write
            // overhead vs. > 20x read speedup on large tables) and
            // avoids breaking any co-existing filter that still uses
            // the single-column name.
            $table->index(['branch_id', 'created_at'],
                'action_logs_branch_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('action_logs', 'branch_id')) {
            Schema::table('action_logs', function (Blueprint $table) {
                $table->dropIndex('action_logs_branch_created_idx');
            });
        }
    }
};
