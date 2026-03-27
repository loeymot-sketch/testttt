<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty transaction ledger — immutable audit trail of all point movements.
 * Each row records one earn or redeem event with its source surface.
 * This enables:
 *   - Per-channel analytics (kiosk vs POS vs web vs mobile)
 *   - Customer history feed (mobile app, web account)
 *   - Dispute resolution
 *   - Future: expiry, tier calculation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('loyalty_code', 25)->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->enum('type', ['earn', 'redeem', 'manual_add', 'manual_deduct', 'expire'])->default('earn');
            $table->integer('points'); // positive = earn, negative = redeem/deduct
            $table->integer('balance_after'); // snapshot of user balance after this transaction
            $table->string('source_surface', 20)->nullable()->comment('kiosk | pos | web | mobile | admin');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
