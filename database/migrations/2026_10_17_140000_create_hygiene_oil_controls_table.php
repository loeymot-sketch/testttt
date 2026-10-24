<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Suivi de l'huile de friture : test (composés polaires, température du bain), filtrage, vidange.
 *
 * Les friteuses sont des lignes de `hygiene_equipments` (kind = fryer) : un seul référentiel d'équipements. JOURNAL append-only
 * (annulation motivée seulement), contexte copié (nom de la friteuse). Verdict calculé par le SERVEUR (OilRules) : au-dessus de
 * 25 % de composés polaires (limite légale) ou bain trop chaud ⇒ écart, action corrective obligatoire.
 * Domaine NEUF, ADDITIF, HORS NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hygiene_oil_controls')) {
            return;
        }
        Schema::create('hygiene_oil_controls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('equipment_id')->index();
            $table->string('equipment_name', 80); // copié
            $table->string('type', 8)->index(); // test | filter | change
            $table->decimal('polar_pct', 4, 1)->nullable(); // % composés polaires (test)
            $table->decimal('bath_temp_c', 5, 1)->nullable(); // température du bain (test)
            $table->decimal('oil_litres', 5, 1)->nullable(); // volume remis (vidange)
            $table->string('oil_brand', 60)->nullable(); // huile utilisée (vidange)
            $table->string('status', 8)->default('ok')->index(); // ok | warn | alert
            $table->string('reasons', 40)->nullable(); // polar_over,bath_hot,polar_warn
            $table->decimal('polar_limit', 4, 1)->nullable(); // limite en vigueur au moment du test (copiée)
            $table->string('corrective_action', 24)->nullable();
            $table->string('corrective_note', 200)->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable(); // photo de la bandelette / de l'appareil

            $table->timestamp('performed_at')->index();
            $table->string('timezone', 40)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('operator_name', 80)->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 120)->nullable();

            $table->string('client_ref', 40)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'client_ref'], 'hyg_oil_client_ref');
            $table->index(['branch_id', 'performed_at'], 'hyg_oil_branch_time');
            $table->index(['equipment_id', 'type', 'performed_at'], 'hyg_oil_equip_type_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_oil_controls');
    }
};
