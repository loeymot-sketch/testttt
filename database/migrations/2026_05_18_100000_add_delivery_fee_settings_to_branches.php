<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Sprint H3 DEL-5 2026-05-17] Branch-configurable delivery fee.
 *
 * NULL columns fall back to the legacy `max(5, ceil(d/5)*5)` formula in
 * DeliveryFeeService — zero migration risk for existing branches.
 * Admin can populate these via tinker/SQL for V1.0.1; admin UI is V1.0.2.
 *
 * Closes Sister Sprint 4 carryover DEL-5 SaaS multi-tenant invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('delivery_fee_base', 10, 2)->nullable()->after('zone');
            $table->decimal('delivery_fee_per_km', 10, 2)->nullable()->after('delivery_fee_base');
            $table->decimal('delivery_fee_minimum', 10, 2)->nullable()->after('delivery_fee_per_km');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['delivery_fee_base', 'delivery_fee_per_km', 'delivery_fee_minimum']);
        });
    }
};
