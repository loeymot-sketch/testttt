<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [AUDIT-F-003 / Sub-task 1] Cash movement — granular cash event tied to a session.
 *
 * type:
 *   - order_payment : ventilation cash d'un order PAID
 *   - cashback      : monnaie rendue ou refund cash
 *   - drawer_open   : audit-log ouverture tiroir physique (sans transaction)
 *   - drawer_close  : audit-log fermeture
 *   - adjustment    : régularisation manuelle (entrée/sortie cash hors vente)
 *
 * direction:
 *   - in  : argent entrant dans la caisse (vente cash, dépôt manuel)
 *   - out : argent sortant (cashback, retrait, refund cash)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cash_movements')) {
            return;
        }

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_drawer_session_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id')->nullable();

            $table->string('type', 32);       // order_payment|cashback|drawer_open|drawer_close|adjustment
            $table->decimal('amount', 10, 2);
            $table->string('direction', 8);   // in|out

            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index('cash_drawer_session_id');
            $table->index(['branch_id', 'created_at']);
            $table->index(['order_id', 'type']);

            $table->foreign('cash_drawer_session_id')
                ->references('id')
                ->on('cash_drawer_sessions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
