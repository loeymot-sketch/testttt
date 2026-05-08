<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Raw audit log of every webhook hit received from a delivery
 * platform — verified or not. We persist the request body,
 * headers, signature header and a boolean signature_valid so
 * that:
 *   - every accepted webhook is replayable for forensics;
 *   - signature failures are captured (rate-limit + alert);
 *   - the platform / nonce dedupe key prevents replay attacks.
 *
 * UNIQUE(platform, nonce) — note that NULL is treated as
 * distinct by MySQL / SQLite / Postgres, so platforms whose
 * webhook envelope does not carry a nonce will simply produce
 * many rows with nonce = NULL (no constraint violation).
 *
 * No FK on branch_id: at receive-time we may not yet have
 * resolved the branch (the row predates parse). It is filled
 * in after `ingested_at` once the matching delivery_platform
 * is found.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_webhook_events')) {
            return;
        }

        Schema::create('delivery_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->enum('platform', ['uber_eats', 'deliveroo', 'delicity']);
            $table->string('event_type', 64);

            $table->string('signature_header', 512)->nullable();
            $table->boolean('signature_valid');

            $table->string('nonce', 128)->nullable();

            $table->json('headers');
            // Body: prefer JSON when the driver supports it. SQLite stores
            // JSON as TEXT so this works in tests; MySQL gets a real JSON
            // column with validation.
            $table->json('body');

            $table->unsignedBigInteger('branch_id')->nullable();

            $table->unsignedBigInteger('delivery_platform_external_order_id')->nullable();

            $table->dateTime('processed_at')->nullable();
            $table->text('processing_error')->nullable();

            $table->dateTime('received_at');

            $table->timestamps();

            $table->index(['platform', 'received_at'], 'dwe_platform_received_idx');
            $table->unique(['platform', 'nonce'], 'dwe_platform_nonce_unique');
            $table->index(['signature_valid', 'received_at'], 'dwe_sig_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_webhook_events');
    }
};
