<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [AUDIT-F-008] Pending Payment Confirmations — reconciliation queue.
 *
 * Tracks TPE transactions approved by hardware but not yet confirmed
 * by the backend (network blip, app crash post-TPE). Frontend persists
 * the result in localStorage; on boot or every 60s, batches the entries
 * to POST /api/frontend/payment/reconcile-pending which idempotently
 * promotes the order to PAID and writes a row here for audit.
 *
 * Idempotency: UNIQUE(transaction_id) — replaying the same tx is a no-op.
 *
 * GATED OWNER (NF525-sensible) :
 * Cette migration touche au flux de paiement / fiscal. Elle est gated
 * pour exécution manuelle owner avec backup pré-migration. NON exécutée
 * automatiquement ce cycle. Les tests Feature la consomment via
 * RefreshDatabase (chargement complet des migrations en SQLite mémoire).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pending_payment_confirmations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->string('transaction_id', 255);
            $table->unsignedInteger('amount_cents');
            $table->string('card_type', 50)->nullable();
            $table->unsignedTinyInteger('payment_method');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->enum('status', ['pending', 'resolved', 'failed'])->default('pending');
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            // Idempotency hard guard: replay same tx → no double row.
            $table->unique('transaction_id', 'uniq_pending_payment_transaction_id');

            $table->index(['status', 'created_at'], 'idx_pending_payment_status_created');
            $table->index(['branch_id', 'status'], 'idx_pending_payment_branch_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_payment_confirmations');
    }
};
