<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Delivery boy cash movement — atomic cash event tied to a livreur session.
 *
 * type :
 *   - order_collect : livreur encaisse cash à la livraison (direction in)
 *   - change_given  : livreur rend la monnaie (direction out)
 *   - drawer_open   : audit ouverture session (sans transaction)
 *   - drawer_close  : audit fermeture session (sans transaction)
 *   - adjustment    : régularisation manuelle (entrée/sortie cash hors livraison)
 *
 * direction :
 *   - in  : argent entrant
 *   - out : argent sortant (rendu de monnaie, refund cash, retrait)
 *
 * Path B mirror — séparé de `cash_movements` (POS) pour ne pas polluer le
 * Z-report POS et permettre une enrichissement Z indépendant.
 *
 * BranchScope appliqué côté model. FK cash_drawer_session restrictOnDelete
 * (NF525 immutability — pas de cascade silencieuse).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_boy_cash_movements')) {
            return;
        }

        Schema::create('delivery_boy_cash_movements', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('delivery_boy_cash_session_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id')->nullable();

            $table->string('type', 32);       // order_collect|change_given|drawer_open|drawer_close|adjustment
            $table->decimal('amount', 10, 2);
            $table->string('direction', 8);   // in|out

            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index('delivery_boy_cash_session_id', 'idx_dbcm_session');
            $table->index(['branch_id', 'created_at'], 'idx_dbcm_branch_created');
            $table->index(['order_id', 'type'], 'idx_dbcm_order_type');

            // RESTRICT on session delete (NF525 immutability — pas de cascade).
            $table->foreign('delivery_boy_cash_session_id', 'fk_dbcm_session')
                ->references('id')
                ->on('delivery_boy_cash_sessions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_boy_cash_movements');
    }
};
