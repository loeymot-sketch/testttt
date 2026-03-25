<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splash security: idempotency key prevents duplicate orders from double-tap
 * or network retry on the kiosk. Same key = same order, not a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // UUID v4 envoyé par le client (borne, app) — nullable pour rétrocompat
            $table->string('idempotency_key', 64)->nullable()->unique()->after('order_serial_no');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
