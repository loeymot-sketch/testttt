<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL-CMS-2026-05-18 S-P0-J heal] R3 T-3.3.1 DBA-W7-002:
 * `webhook_events.order_id` has NO FK to `orders.id`. Eloquent
 * `belongsTo` is declared but schema doesn't enforce — garbage
 * `order_id` writes from SenangPay raw `$orderRef` succeed silently.
 * Combined with Order's SoftDeletes + 180d webhook prune, fiscal
 * evidence and Order lifecycle diverge.
 *
 * Adds nullable FK with `nullOnDelete` (preserves audit trail when
 * order is soft-deleted — `nullOnDelete` does not fire on soft-delete
 * but on hard-delete which is forbidden by NF525 anyway). Before
 * adding, scrub any existing `order_id` values that don't reference
 * a real order — those were silent garbage writes.
 */
return new class extends Migration {
    public function up(): void
    {
        // Step 1: scrub garbage order_id values (silent writes that
        // pre-date this FK migration). Setting them to NULL preserves
        // the webhook_events row (audit trail) but breaks the broken
        // FK link.
        DB::table('webhook_events')
            ->whereNotNull('order_id')
            ->whereNotIn('order_id', DB::table('orders')->select('id'))
            ->update(['order_id' => null]);

        // Step 2: add FK constraint.
        Schema::table('webhook_events', function (Blueprint $table) {
            // The existing index `idx_order_id` (created in 2026_05_09_120000
            // via `->index()`) covers the FK lookup. Foreign-key adds the
            // constraint check.
            $table->foreign('order_id', 'fk_webhook_events_order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropForeign('fk_webhook_events_order_id');
        });
    }
};
