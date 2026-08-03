<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Delivery boy cash drawer session — NF525 doorstep cash audit trail.
 *
 * Path B (Planner H plan §0 / Decision Coin #1) : fresh table, mirrors `cash_drawer_sessions`
 * schema. Does NOT extend the POS table (NF525-adjacent + discriminator pollution).
 *
 * Lifecycle (same semantics as POS) :
 *   - open       : livreur on-shift, collects cash at customer doorstep
 *   - closed     : closing_amount declared (physical count)
 *   - reconciled : variance frozen, audit chain sealed
 *
 * GATED OWNER : pas de `php artisan migrate` en prod sans validation NF525.
 * Pour les Feature tests, RefreshDatabase via SQLite mémoire est OK.
 *
 * BranchScope appliqué côté model (DeliveryBoyCashSession::boot).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_boy_cash_sessions')) {
            return;
        }

        Schema::create('delivery_boy_cash_sessions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('delivery_boy_id');     // users.id (Delivery Boy role)
            $table->unsignedBigInteger('opened_by_user_id');   // admin OR livreur self
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->unsignedBigInteger('reconciled_by_user_id')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // Decimals 10,2 — cash en euros, jamais besoin de plus de précision.
            $table->decimal('opening_amount', 10, 2);                   // float pour rendre la monnaie
            $table->decimal('closing_amount', 10, 2)->nullable();       // déclaré physiquement
            $table->decimal('expected_closing_amount', 10, 2)->nullable();
            $table->decimal('variance', 10, 2)->nullable();
            $table->string('variance_reason', 255)->nullable();

            $table->string('status', 16)->default('open'); // open|closed|reconciled
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['delivery_boy_id', 'status']);
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_boy_cash_sessions');
    }
};
