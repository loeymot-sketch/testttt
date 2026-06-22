<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [AUDIT-F-003 / Sub-task 1] Cash drawer session — Option A (cashier-supervised).
 *
 * Une session représente un service caisse : ouverture (fond initial),
 * pendant lequel chaque vente cash crée un cash_movement, fermeture (cash
 * compté physiquement), puis reconciliation (calcul de la variance).
 *
 * GATED OWNER : ne PAS exécuter `php artisan migrate` en prod sans validation
 * comptabilité française (NF525). Pour les Feature tests, RefreshDatabase via
 * SQLite mémoire est OK et requis pour la suite F-003.
 *
 * status:
 *   - open       : session ouverte, le caissier encaisse
 *   - closed     : session fermée (closing_amount déclaré) MAIS pas encore reconciled
 *   - reconciled : variance calculée et figée
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cash_drawer_sessions')) {
            return;
        }

        Schema::create('cash_drawer_sessions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('opened_by_user_id');

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // Decimals 10,2 — cash en euros, jamais besoin de plus de précision.
            $table->decimal('opening_amount', 10, 2);
            $table->decimal('closing_amount', 10, 2)->nullable();
            $table->decimal('expected_closing_amount', 10, 2)->nullable();
            $table->decimal('variance', 10, 2)->nullable();
            $table->string('variance_reason', 255)->nullable();

            $table->string('status', 16)->default('open'); // open|closed|reconciled

            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['opened_by_user_id', 'status']);
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_drawer_sessions');
    }
};
