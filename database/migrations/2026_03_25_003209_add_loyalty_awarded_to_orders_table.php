<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks how many loyalty points were awarded for this order.
 * null = not yet awarded | 0 = ineligible | N = awarded N points.
 * Used by AwardLoyaltyPointsOnDelivery to prevent double-award.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points_awarded')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('loyalty_points_awarded');
        });
    }
};
