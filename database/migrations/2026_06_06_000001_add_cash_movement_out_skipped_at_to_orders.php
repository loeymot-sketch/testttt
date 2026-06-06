<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CASH-01] NF525 cash trail — symmetric OUT-skip marker. When cash LEAVES the
 * drawer for a refund (RefundWithCounterEntryService) or cashback
 * (PaymentService::recordCashBackMovement) but NO open cash-drawer session
 * exists, no `cash_movement` direction=out row is written → end-of-day expected
 * cash is OVERSTATED and the EOD detector cannot find the gap. This timestamp
 * column makes the OUT gap auditable: `whereNotNull('cash_movement_out_skipped_at')`.
 *
 * Mirrors `cash_movement_skipped_at` (M10-01, the IN-path marker) but is kept
 * DISTINCT because IN and OUT skips are opposite-signed (IN understates,
 * OUT overstates) and must surface as separate reconciliation figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cash_movement_out_skipped_at')) {
                $table->timestamp('cash_movement_out_skipped_at')->nullable()->after('cash_movement_skipped_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cash_movement_out_skipped_at')) {
                $table->dropColumn('cash_movement_out_skipped_at');
            }
        });
    }
};
