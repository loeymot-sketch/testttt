<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Mapping table between an external delivery-platform order
 * (the platform's `order_id` / equivalent) and our internal
 * `orders.id`.
 *
 * Lifecycle stages:
 *   - received_at  : webhook arrived and row was inserted.
 *   - ingested_at  : payload was successfully parsed and (if
 *                    applicable) a frontend Order was created.
 *   - last_pushed_at / push_attempts / last_push_error : status
 *                    push back to the platform after our internal
 *                    state changes.
 *
 * `frontend_order_id` is nullable + ON DELETE SET NULL so that a
 * legitimate destroy() of the FoodKing-side order (e.g. soft-delete
 * after a Z report rollback) does not orphan-cascade the inbound
 * mapping — we still keep the platform-side raw_payload for forensics.
 *
 * Uniqueness on (platform, external_id) is the dedupe key for
 * inbound webhooks: re-delivery of the same external order id by
 * the platform must be a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_platform_external_orders')) {
            return;
        }

        Schema::create('delivery_platform_external_orders', function (Blueprint $table) {
            $table->id();

            $table->enum('platform', ['uber_eats', 'deliveroo', 'delicity']);
            $table->string('external_id', 128);

            $table->unsignedBigInteger('frontend_order_id')->nullable();
            $table->foreign('frontend_order_id')
                ->references('id')->on('orders')
                ->onDelete('set null');

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches');

            $table->unsignedBigInteger('delivery_platform_id');
            $table->foreign('delivery_platform_id', 'dpeo_delivery_platform_id_fk')
                ->references('id')->on('delivery_platforms');

            $table->string('external_status', 64)->nullable();
            $table->unsignedSmallInteger('internal_status')->nullable();

            $table->json('raw_payload');

            $table->dateTime('last_pushed_at')->nullable();
            $table->text('last_push_error')->nullable();
            $table->unsignedSmallInteger('push_attempts')->default(0);

            $table->dateTime('received_at');
            $table->dateTime('ingested_at')->nullable();

            $table->timestamps();

            $table->unique(['platform', 'external_id'], 'dpeo_platform_external_id_unique');
            $table->index(['frontend_order_id'], 'dpeo_frontend_order_id_idx');
            $table->index(['branch_id', 'received_at'], 'dpeo_branch_received_idx');
            $table->index(['platform', 'received_at'], 'dpeo_platform_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_platform_external_orders');
    }
};
