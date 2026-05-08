<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Per-branch configuration for an external delivery aggregator
 * (Uber Eats / Deliveroo / Delicity).
 *
 * One row binds a FoodKing branch to a single platform-side
 * "store / restaurant id" plus the credentials and webhook secret
 * required to verify inbound webhooks and push status updates.
 *
 * `credentials` is encrypted at rest via Eloquent `encrypted:json`
 * cast — never read this column outside the model or you bypass
 * the cast and pull ciphertext.
 *
 * Uniqueness:
 *   - (platform, external_store_id) — a given external store on
 *     a given platform is owned by exactly one FoodKing branch.
 *   - (branch_id, platform)         — a branch cannot configure
 *     the same platform twice (one row per branch×platform pair).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_platforms')) {
            return;
        }

        Schema::create('delivery_platforms', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->onDelete('cascade');

            $table->enum('platform', ['uber_eats', 'deliveroo', 'delicity']);
            $table->boolean('enabled')->default(false);

            $table->string('external_store_id', 128);

            // Encrypted JSON blob (Eloquent `encrypted:json` cast).
            // Stored as TEXT to accommodate the encrypted ciphertext envelope.
            $table->text('credentials');

            $table->dateTime('webhook_secret_rotated_at')->nullable();
            $table->dateTime('last_webhook_received_at')->nullable();
            $table->dateTime('last_status_pushed_at')->nullable();

            $table->timestamps();

            $table->unique(['platform', 'external_store_id'], 'delivery_platforms_platform_store_unique');
            $table->unique(['branch_id', 'platform'], 'delivery_platforms_branch_platform_unique');
            $table->index(['branch_id', 'platform'], 'delivery_platforms_branch_platform_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_platforms');
    }
};
