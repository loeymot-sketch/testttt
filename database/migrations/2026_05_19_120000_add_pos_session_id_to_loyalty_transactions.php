<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier audit trail.
 *
 * Adds `pos_session_id` nullable to `loyalty_transactions` so a cashier
 * redemption can be traced back to the open cash drawer session at the
 * time of redemption. Anti-replay safeguard per LOCK §6.6.
 *
 * The existing migration 2026_03_26_075919_add_unique_to_loyalty_transactions
 * already enforces UNIQUE(user_id, order_id, type) — that covers "single
 * redemption per order" (LOCK §6.2) at DB level. We do NOT add a second
 * partial UNIQUE here to avoid index collisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('loyalty_transactions')) {
            return;
        }
        if (Schema::hasColumn('loyalty_transactions', 'pos_session_id')) {
            return;
        }

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            // Nullable on purpose : kiosk/web/mobile redemptions have no
            // cash drawer session attached. POS cashier redemptions MUST
            // set this column (enforced at service level, not DB).
            $table->unsignedBigInteger('pos_session_id')->nullable()->after('source_surface');
            $table->index('pos_session_id', 'loyalty_transactions_pos_session_id_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('loyalty_transactions')) {
            return;
        }

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('loyalty_transactions', 'pos_session_id')) {
                try {
                    $table->dropIndex('loyalty_transactions_pos_session_id_index');
                } catch (\Throwable $e) {
                    // idempotent
                }
                $table->dropColumn('pos_session_id');
            }
        });
    }
};
