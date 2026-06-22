<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [F-01 + NEW-05] Stock release ledger for cancel/refund flows.
 *
 * `released_qty` is the per-line idempotency ledger: the listener increments it
 * inside a SELECT ... FOR UPDATE block and never lets it exceed `quantity`.
 * Duplicate OrderCanceled / RefundCreated deliveries become safe no-ops.
 *
 * `released_at` is informational (latest release tick) for audit / debugging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'released_qty')) {
                $table->integer('released_qty')->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('order_items', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('released_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'released_at')) {
                $table->dropColumn('released_at');
            }
            if (Schema::hasColumn('order_items', 'released_qty')) {
                $table->dropColumn('released_qty');
            }
        });
    }
};
