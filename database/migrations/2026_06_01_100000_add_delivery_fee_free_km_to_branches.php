<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — DEL-FEE whole-km, owner decision]
 *
 * Adds delivery_fee_free_km to branches. When set (alongside base/per_km/minimum),
 * DeliveryFeeService uses the owner's "whole-km" rule:
 *   fee = max(minimum, base + per_km * ceil(max(0, distance - free_km)))
 * i.e. a flat `base` covers the first `free_km`, then each STARTED km beyond
 * adds `per_km` (rounded up). For Le Cayenne: base=5, per_km=1, minimum=5,
 * free_km=5  →  5€ for ≤5km, +1€ per started km beyond.
 *
 * NULL free_km preserves the existing linear `max(minimum, base + per_km*d)`
 * path — zero migration risk for branches not using the free-radius rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('delivery_fee_free_km', 8, 2)->nullable()->after('delivery_fee_minimum');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('delivery_fee_free_km');
        });
    }
};
