<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [LIVRAISON 2026-10-20] Un arrêt de livraison = une commande livrée par NOTRE livreur.
 *
 * Table dédiée à dessein (même raisonnement que `uber_direct_deliveries`) : le cycle de
 * LIVRAISON (parti → arrivé → remis) est indépendant de la CUISINE. Aucune arête nouvelle dans
 * `OrderStateMachine` (gelée) ; la remise finale passe toujours par le changement de statut
 * existant (DELIVERED), qui porte l'encaissement et la séquence fiscale.
 *
 * Tournée = tous les arrêts de même `wave_at` (grille de 30 minutes), `sequence` = ordre de passage.
 * Argent en centimes entiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_stops')) {
            return;
        }

        Schema::create('delivery_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('delivery_boy_id')->nullable()->index();

            $table->timestamp('wave_at')->index();          // départ de la tournée (grille :00/:30)
            $table->unsignedSmallInteger('sequence')->default(1);
            // Vrai si le CLIENT a choisi ce créneau : la tournée ne le décale alors jamais d'elle-même.
            $table->boolean('slot_fixed')->default(false);
            $table->decimal('distance_km', 7, 3)->nullable();
            $table->unsignedSmallInteger('eta_minutes')->nullable();   // estimation client, pas Waze

            // Ce que le livreur doit encaisser à la porte.
            $table->string('collect_method', 16)->default('paid_online'); // cash|card|paid_online
            $table->unsignedInteger('amount_due_cents')->default(0);
            $table->unsignedInteger('amount_collected_cents')->nullable();

            $table->timestamp('left_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('arrival_lat', 10, 7)->nullable();
            $table->decimal('arrival_lng', 10, 7)->nullable();
            $table->string('proof_photo_path', 255)->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'wave_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_stops');
    }
};
