<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Traçabilité amont : « ce produit, de ce fournisseur, ce lot, cette DLC, ce bon de livraison ».
 *
 * C'est ce qui permet, le jour d'un rappel produit, de répondre en une recherche : quel lot, quand reçu, chez quel fournisseur, et
 * quelles préparations (étiquettes DLC) en sont issues. JOURNAL append-only (annulation motivée seulement), contexte copié
 * (nom du produit, du fournisseur). Domaine NEUF, ADDITIF, HORS NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hygiene_trace_entries')) {
            return;
        }
        Schema::create('hygiene_trace_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('product_name', 80); // copié
            $table->unsignedBigInteger('dlc_product_id')->nullable()->index();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->string('supplier_name', 80)->nullable(); // copié
            $table->unsignedBigInteger('reception_id')->nullable()->index();
            $table->string('delivery_note_no', 40)->nullable(); // copié de la réception
            $table->string('lot', 60)->nullable()->index();
            $table->date('dlc_date')->nullable();
            $table->string('dlc_kind', 4)->default('dlc'); // dlc | dluo
            $table->string('status', 8)->default('ok'); // ok | alert (DLC déjà dépassée à la saisie)
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable(); // photo de l'étiquette fournisseur

            $table->timestamp('traced_at')->index();
            $table->string('timezone', 40)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('operator_name', 80)->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 120)->nullable();

            $table->string('client_ref', 40)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'client_ref'], 'hyg_trace_client_ref');
            $table->index(['branch_id', 'traced_at'], 'hyg_trace_branch_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_trace_entries');
    }
};
