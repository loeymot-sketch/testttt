<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A — Splash-style merchandising: control which categories feed the kiosk upsell pool
 * and optionally skip the upsell screen when the cart only contains "skip" categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->boolean('kiosk_upsell_include')->default(true)->after('sauce_included_menu');
            $table->boolean('kiosk_upsell_skip_after_cart')->default(false)->after('kiosk_upsell_include');
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->dropColumn(['kiosk_upsell_include', 'kiosk_upsell_skip_after_cart']);
        });
    }
};
