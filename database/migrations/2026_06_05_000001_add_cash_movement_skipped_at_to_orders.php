<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [M10-01] NF525 cash trail — persist a QUERYABLE marker when a CASH order is
 * collected with no open cash-drawer session (the order goes PAID but no
 * cash_movement row exists). Before this, the skip lived only in a transient
 * in-memory `cash_movement_skipped` attribute that reached the HTTP response and
 * then vanished — EOD reconciliation could not find under-counted cash. This
 * timestamp column makes the gap auditable: `whereNotNull('cash_movement_skipped_at')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cash_movement_skipped_at')) {
                $table->timestamp('cash_movement_skipped_at')->nullable()->after('pos_payment_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cash_movement_skipped_at')) {
                $table->dropColumn('cash_movement_skipped_at');
            }
        });
    }
};
