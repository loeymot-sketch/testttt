<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [LIVRAISON QR 2026-11-01] Jeton de remise à usage unique, imprimé en QR sur le ticket client d'une livraison.
 *
 * Migration ADDITIVE : une table neuve, aucune colonne ajoutée à une table existante, aucune écriture de données.
 * Sûre sur MySQL de production (pas de verrou prolongé, pas de remplissage).
 *
 * Pourquoi une table et pas des colonnes sur `delivery_stops` : `delivery_stops.order_id` est UNIQUE (une ligne par
 * commande), donc aucune place pour l'HISTORIQUE des générations d'un jeton (révoqué, puis réémis).
 *
 * Seul `sha256(jeton)` est stocké (UNIQUE) ; le jeton en clair n'est jamais en base — il est DÉRIVÉ
 * (HMAC de la clé de l'application) et recalculé à la réimpression du ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_handover_tokens')) {
            return;
        }

        Schema::create('delivery_handover_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('delivery_stop_id');

            $table->char('token_hash', 64)->unique();          // sha256 hex du jeton (jamais le clair)
            $table->unsignedSmallInteger('generation')->default(1); // +1 = l'ancien jeton est mort
            $table->timestamp('expires_at')->nullable();        // départ de la tournée + 6 h (relu en direct)
            $table->timestamp('wave_at')->nullable();           // instantané de la tournée à l'émission
            $table->timestamp('revoked_at')->nullable();

            // Brûlure (usage unique) et traçabilité de la remise.
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('used_by_user_id')->nullable();
            $table->string('chosen_mode', 16)->nullable();      // choix du client (cash|card|meal_voucher|paid_online)
            $table->string('declared_mode', 16)->nullable();    // ce que le livreur dit avoir encaissé
            $table->boolean('mode_mismatch')->default(false);   // déclaré ≠ choisi
            $table->unsignedInteger('amount_due_cents')->nullable();
            $table->unsignedInteger('amount_collected_cents')->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();

            $table->timestamps();

            $table->unique(['delivery_stop_id', 'generation'], 'dht_stop_generation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_handover_tokens');
    }
};
