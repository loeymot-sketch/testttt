<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which surface (kiosk, pos, web, mobile) generated each order.
 * Enables per-channel loyalty reporting and analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'source_surface')) {
                $table->string('source_surface', 20)->nullable()->after('loyalty_customer_code')
                    ->comment('kiosk | pos | web | mobile');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'source_surface')) {
                $table->dropColumn('source_surface');
            }
        });
    }
};
