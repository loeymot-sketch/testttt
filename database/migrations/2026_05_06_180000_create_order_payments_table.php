<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-SPLIT-PAYMENT-001 — multi-tender breakdown.
 *
 * Crée la table `order_payments` (1:N avec `orders`) qui stocke les tranches
 * d'un paiement multi-mode pour le POS. Cf. PLAN_P12 §3.1 et
 * `OrderDetailsResource::buildPaymentsBreakdown()` (qui consomme déjà la
 * relation `payments()` côté Order).
 *
 * Migration purement additive : pas d'altération de `orders`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('branch_id');         // denormalised for fast scope/audit queries
            $table->unsignedTinyInteger('mode');             // PosPaymentMethod enum: 1=CASH, 2=CARD, 3=MOBILE_BANKING, 4=OTHER, 5=TICKET_RESTAURANT
            $table->decimal('amount', 10, 2);                // tranche montant facturé
            $table->decimal('tendered', 10, 2)->nullable();  // for CASH only — peut > amount
            $table->decimal('change_amount', 10, 2)->default(0);
            $table->string('reference', 64)->nullable();     // 4-derniers card / ID TPE / ID ticket resto
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index(['order_id', 'mode']);
            $table->index(['branch_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
