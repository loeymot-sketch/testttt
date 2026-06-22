<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Sprint H3 DEL-8 2026-05-17] Branch-configurable delivery minimum order
 * amount. NULL = no minimum (legacy / V1 default). When set, OrderRequest
 * rejects DELIVERY orders whose subtotal is below the threshold with a
 * clear error message. Closes Sister Sprint 4 carryover DEL-8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('delivery_minimum_order', 10, 2)->nullable()->after('delivery_fee_minimum');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('delivery_minimum_order');
        });
    }
};
