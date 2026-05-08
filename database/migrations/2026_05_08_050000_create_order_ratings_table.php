<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [WAVE-ALPHA-A3 / M-3] CSAT 5-star ratings for orders (kiosk + web + mobile).
 *
 * Cette table journalise un score 1..5 et un commentaire optionnel par
 * commande. Order et FrontendOrder partagent la table `orders` (même PK
 * auto-increment), donc la colonne `order_type` reste un discriminator
 * informatif pour la traçabilité par surface, et la contrainte unique
 * (order_id, order_type) suffit à empêcher les doublons (l'`id` étant déjà
 * unique table-wide).
 *
 * `branch_id` est requis pour les agrégats analytiques par branche (CSAT
 * moyen, NPS) et alignement avec BranchScope.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            // Discriminator (Order vs FrontendOrder partagent la table `orders`).
            $table->string('order_type', 32)->default('FrontendOrder');
            $table->unsignedBigInteger('branch_id');
            $table->tinyInteger('rating')->unsigned(); // 1..5
            $table->text('comment')->nullable();
            $table->string('source_surface', 32)->default('kiosk'); // kiosk | web | mobile | pos
            $table->timestamps();

            $table->index(['order_id', 'order_type']);
            $table->index(['branch_id', 'created_at']);
            // 1 rating par (order_id, order_type) — updateOrCreate idempotent.
            $table->unique(['order_id', 'order_type'], 'order_rating_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ratings');
    }
};
