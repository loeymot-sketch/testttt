<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('quote_token')->unique();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('surface', 24);
            $table->char('intent_hash', 64);
            $table->char('hmac_signature', 64);
            $table->json('canonical_payload');
            $table->decimal('subtotal', 19, 6)->default(0);
            $table->decimal('discount', 19, 6)->default(0);
            $table->decimal('total_tax', 19, 6)->default(0);
            $table->decimal('delivery_charge', 19, 6)->default(0);
            $table->decimal('total_ttc', 19, 6)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_by_user_id')->nullable();
            $table->unsignedBigInteger('consumed_order_id')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('consumed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('consumed_order_id')->references('id')->on('orders')->nullOnDelete();
            $table->index(['branch_id', 'surface', 'actor_id', 'intent_hash', 'expires_at'], 'order_quotes_branch_surface_intent_idx');
            $table->index(['branch_id', 'expires_at'], 'order_quotes_branch_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_quotes');
    }
};
