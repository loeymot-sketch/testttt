<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Contrôle à la réception des marchandises : fournisseur, bon de livraison, trois températures
 * (frais / surgelé / ambiant) avec leur limite, contrôles visuels, décision (accepté / réserve / refusé), photo du BL.
 *
 * JOURNAL append-only : une ligne n'est jamais modifiée ni effacée, elle s'ANNULE avec un motif. Le contexte est COPIÉ (nom du
 * fournisseur, limites de température du moment, libellés des contrôles) — renommer un fournisseur ou changer une limite ne
 * réécrit pas ce qui a été constaté. Verdict calculé par le SERVEUR. Domaine NEUF, ADDITIF, HORS NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hygiene_receptions')) {
            return;
        }
        Schema::create('hygiene_receptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->string('supplier_name', 80); // copié
            $table->string('delivery_note_no', 40)->nullable(); // numéro du bon de livraison
            $table->string('delivered_by', 60)->nullable(); // livreur

            // [{category, label, value_c, max_c, status}] — la limite du moment est copiée
            $table->json('temperatures');
            $table->json('checks'); // [{key, label, ok}]
            $table->string('status', 8)->index(); // ok | alert
            $table->string('decision', 8)->index(); // accepted | reserve | refused
            $table->string('note', 300)->nullable(); // obligatoire quand status = alert

            $table->unsignedBigInteger('attachment_id')->nullable(); // photo du BL
            $table->json('attachment_ids')->nullable(); // photos complémentaires (produit abîmé, étiquette…)

            $table->timestamp('received_at')->index();
            $table->string('timezone', 40)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('operator_name', 80)->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 120)->nullable();

            $table->string('client_ref', 40)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'client_ref'], 'hyg_recep_client_ref');
            $table->index(['branch_id', 'received_at'], 'hyg_recep_branch_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_receptions');
    }
};
