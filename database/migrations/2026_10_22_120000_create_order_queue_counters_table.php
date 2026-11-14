<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [VG-2026-10-21 M6-002 M5-102] Compteur du numéro de file par (branche, jour métier).
 *
 * Une ligne par jour et par branche ; la vente verrouille SA ligne jusqu'au COMMIT
 * (`App\Services\Orders\QueueNumberAllocator`). Remplace la lecture du MAX des commandes, qui
 * relisait un instantané périmé sous concurrence (ventes simultanées refusées en 422).
 *
 * Aucune donnée existante n'est modifiée : le compteur se recale tout seul sur le plus grand
 * numéro déjà présent en base au premier appel du jour. Réversible : `down()` supprime la table,
 * l'allocation se recale de la même façon si elle est recréée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_queue_counters')) {
            return;
        }

        Schema::create('order_queue_counters', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id');
            $table->date('business_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            // Clé primaire = la ligne verrouillée par l'allocation (pas d'index secondaire unique,
            // source classique d'interblocages sur INSERT … ON DUPLICATE KEY UPDATE).
            $table->primary(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_queue_counters');
    }
};
