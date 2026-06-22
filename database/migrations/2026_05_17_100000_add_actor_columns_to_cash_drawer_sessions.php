<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Sprint H2 F-10 2026-05-17] Add closed_by_user_id + reconciled_by_user_id
 * to cash_drawer_sessions for forensic clarity. CLAUDE.md NF525 §8.
 *
 * Nullable + ON DELETE SET NULL so user deletion doesn't cascade-delete
 * fiscal-evidence rows. Existing audit_logs HMAC chain (Sprint 1D / F-8)
 * still carries the authoritative actor identity — these columns are a
 * query convenience that speeds up Z-report dispute investigations.
 *
 * SQLite (test) skips FK creation (canonical project pattern, cf.
 * 2026_04_18_140001_add_fks_to_item_branch_availability.php).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cash_drawer_sessions')) {
            return;
        }

        Schema::table('cash_drawer_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_drawer_sessions', 'closed_by_user_id')) {
                $table->unsignedBigInteger('closed_by_user_id')
                    ->nullable()
                    ->after('closing_amount');
            }
            if (! Schema::hasColumn('cash_drawer_sessions', 'reconciled_by_user_id')) {
                $table->unsignedBigInteger('reconciled_by_user_id')
                    ->nullable()
                    ->after('closed_by_user_id');
            }
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('cash_drawer_sessions', function (Blueprint $table) {
            $table->foreign('closed_by_user_id', 'cds_closed_by_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->foreign('reconciled_by_user_id', 'cds_reconciled_by_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_drawer_sessions')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('cash_drawer_sessions', function (Blueprint $table) {
                try {
                    $table->dropForeign('cds_closed_by_fk');
                } catch (\Throwable $e) { /* idempotent */ }
                try {
                    $table->dropForeign('cds_reconciled_by_fk');
                } catch (\Throwable $e) { /* idempotent */ }
            });
        }

        Schema::table('cash_drawer_sessions', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('cash_drawer_sessions', 'reconciled_by_user_id')) {
                $cols[] = 'reconciled_by_user_id';
            }
            if (Schema::hasColumn('cash_drawer_sessions', 'closed_by_user_id')) {
                $cols[] = 'closed_by_user_id';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
