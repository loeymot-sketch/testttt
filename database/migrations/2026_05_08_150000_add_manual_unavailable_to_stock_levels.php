<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [F-016a-BIS] Add manual rupture semantics to polymorphic stock_levels.
 *
 * Why: stock_levels.on_hand=0 carries WHEN but not WHY. Manager UI needs
 * a persistent reason distinguishable from automatic on_hand=0 so that
 * "rupture livreur" / "saisonnier" / "qualité" can be surfaced in
 * Kiosk/POS error labels and in admin StockManager dashboard.
 *
 * Priority rule applied in ChoiceAvailabilityResolver::availabilityFromLevel:
 *   manual_unavailable_reason IS NOT NULL  =>  unavailable (override stock auto)
 *   manual_unavailable_reason IS NULL      =>  fall back to on_hand check
 *
 * Zero-downtime safety: ALTER TABLE ADD nullable + index. No re-write of
 * existing rows. New columns default to NULL so legacy callers
 * (StockService decrement/release, ChoiceAvailabilityResolver) continue
 * to behave exactly as before until a row is explicitly toggled manual.
 *
 * GATED OWNER: this migration file is committed but NOT applied to
 * dev/staging/prod by this commit. Owner triggers `php artisan migrate`
 * during the F-016a-BIS rollout window. Test suite runs it through
 * RefreshDatabase only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->string('manual_unavailable_reason', 32)->nullable()->after('threshold_low');
            $table->timestamp('manual_unavailable_since')->nullable()->after('manual_unavailable_reason');
            $table->index('manual_unavailable_reason', 'stock_levels_manual_reason_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropIndex('stock_levels_manual_reason_idx');
            $table->dropColumn(['manual_unavailable_reason', 'manual_unavailable_since']);
        });
    }
};
