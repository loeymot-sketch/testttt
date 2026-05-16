<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Wave F F-2 / Sprint 1C] Payment terminals — per-TPE fee tracking.
 *
 * Owner verbatim : "les taux sont par terminal de paiement aussi". Cette
 * migration crée une entité physique « TPE » (terminal de paiement) avec
 * son taux propre (fee_percent + fee_fixed), distincte du gateway logique.
 * Couvre les modèles SaaS multi-tenant où chaque TPE physique a négocié
 * un tarif différent avec son acquéreur.
 *
 * gateway_type :
 *   - stripe    : Stripe Terminal
 *   - senangpay : Senangpay TPE
 *   - ingenico  : Ingenico iCT/iWL/Move
 *   - verifone  : Verifone V200/V400
 *   - manual    : encaissement manuel (sans TPE = cash counter, ticket-resto)
 *
 * status (tinyint, convention BRAIN) :
 *   - 1 = ACTIVE  (sélectionnable au paiement)
 *   - 5 = ARCHIVED (soft-delete, non sélectionnable mais historique préservé
 *                   pour rapports Z passés liés à ce terminal_id)
 *
 * Multi-tenant via BranchScope (voir App\Models\PaymentTerminal).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payment_terminals')) {
            return;
        }

        Schema::create('payment_terminals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->string('name', 100);                  // ex: "TPE Cuisine Comptoir"
            $table->string('gateway_type', 30);           // stripe|senangpay|ingenico|verifone|manual

            // Fees per transaction. Decimals are validated at application layer
            // (PaymentTerminalRequest). 5,3 → 1.500% max 99.999%. 8,2 → 999999.99€ max.
            $table->decimal('fee_percent', 5, 3)->default(0);
            $table->decimal('fee_fixed', 8, 2)->default(0);

            $table->string('serial_number', 100)->nullable();   // pour TPE physique
            $table->unsignedTinyInteger('status')->default(1);  // 1=ACTIVE, 5=ARCHIVED

            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('gateway_type');

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terminals');
    }
};
