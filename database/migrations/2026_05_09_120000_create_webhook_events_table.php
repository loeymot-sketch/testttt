<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [P0-FIX-1 NF525 / Payment-Idempotency — 2026-05-09]
 *
 * Webhook events outbox-style log for payment providers (Stripe + SenangPay
 * + future). Closes the P0 finding "SenangPay webhook lacks idempotency",
 * with a unified schema for parity across providers (Q-C=A owner decision).
 *
 * Schema rationale :
 *  - UNIQUE (provider, webhook_id) enforces single-processing per provider
 *    event. Stripe sends event.id; SenangPay sends txn_id. The composite
 *    UNIQUE catches replays at INSERT time even if the app handler races.
 *  - status enum = pending | processed | failed | duplicate
 *  - signature stored for forensic audit (HMAC verification)
 *  - payload (JSON) preserves the raw provider payload
 *  - order_id (nullable) optional FK reference for Stripe charge → Order link
 *  - attempts counter for dead-letter re-drive jobs
 *
 * Cross-driver: works on MySQL + SQLite (tests). No triggers needed —
 * the handler enforces idempotency via firstOrCreate + DB UNIQUE.
 *
 * Indexes:
 *  - uk_webhook_provider_id (UNIQUE provider+webhook_id) → atomicity
 *  - idx_pending_received (status+received_at) → dead-letter polling
 *  - idx_provider_received (provider+received_at) → time-range audit
 *
 * Replaces the partial token-based idempotency in
 * `capture_payment_notifications` (legacy Stripe). Legacy table is left
 * untouched for backward compat; future migration can deprecate it.
 *
 * Reference: plans/MASTER_ULTRA_PLAN_V1_INTERNAL_AUDIT_2026-05-09.md §8 P0 #1
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // Provider discriminator: 'stripe' | 'senangpay' | future
            $table->string('provider', 32)->index();

            // Provider-specific identifier (Stripe event.id, SenangPay txn_id)
            $table->string('webhook_id', 255);

            // Event type/code from provider payload
            $table->string('event_type', 128)->index();

            // Raw provider payload (JSON for forensic + replay)
            $table->json('payload');

            // HMAC signature provided by webhook (audit trail)
            $table->string('signature', 512)->nullable();

            // When the webhook was received by app
            $table->dateTime('received_at', 3)->index();

            // When the handler successfully processed it (NULL = pending/failed)
            $table->dateTime('processed_at', 3)->nullable();

            // Lifecycle status
            $table->enum('status', ['pending', 'processed', 'failed', 'duplicate'])
                ->default('pending')
                ->index();

            // Error message if status=failed (truncated for storage)
            $table->text('error_message')->nullable();

            // Retry counter (incremented per re-drive attempt)
            $table->unsignedSmallInteger('attempts')->default(0);

            // Optional FK link to orders (set post-processing for traceability)
            $table->unsignedBigInteger('order_id')->nullable()->index();

            $table->timestamps();

            // CRITICAL: enforces single-processing per (provider, webhook_id)
            // even under concurrent INSERT race
            $table->unique(['provider', 'webhook_id'], 'uk_webhook_provider_id');

            // Dead-letter polling
            $table->index(['status', 'received_at'], 'idx_pending_received');

            // Time-range audit per provider
            $table->index(['provider', 'received_at'], 'idx_provider_received');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
