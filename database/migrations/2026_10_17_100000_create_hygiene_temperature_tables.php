<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Relevés de température : frigos (positifs), congélateurs (négatifs), chambres froides.
 *
 * DEUX TABLES
 *   hygiene_equipments            — les équipements du restaurant, chacun avec SES seuils (min/max en °C).
 *   hygiene_temperature_readings  — le JOURNAL des relevés. Append-only : un relevé n'est jamais modifié ni effacé, il peut
 *                                   seulement être ANNULÉ (`voided_at`) avec un motif — il reste au journal.
 *
 * CHAQUE RELEVÉ PORTE SON CONTEXTE COPIÉ (nom et type de l'équipement, seuils en vigueur, opérateur, moment, fuseau) : renommer
 * un frigo ou changer un seuil ne réécrit jamais l'historique — c'est ce qu'un contrôleur sanitaire demande de relire.
 * Statut calculé par le SERVEUR (une seule règle, une seule horloge). Domaine NEUF, ADDITIF, HORS NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hygiene_equipments')) {
            Schema::create('hygiene_equipments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('name', 80);
                $table->string('kind', 16); // fridge | cold_room | display | freezer
                $table->string('location', 60)->nullable(); // « Cuisine », « Réserve »…
                $table->decimal('min_c', 5, 1)->nullable();
                $table->decimal('max_c', 5, 1)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['branch_id', 'is_active', 'sort_order'], 'hyg_equip_branch_active_sort');
            });
        }

        if (! Schema::hasTable('hygiene_temperature_readings')) {
            Schema::create('hygiene_temperature_readings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('equipment_id')->index();
                // — contexte copié au moment du relevé —
                $table->string('equipment_name', 80);
                $table->string('equipment_kind', 16);
                $table->decimal('min_c', 5, 1)->nullable();
                $table->decimal('max_c', 5, 1)->nullable();

                $table->decimal('value_c', 5, 1);
                $table->string('status', 8)->index(); // ok | alert
                $table->string('alert_reason', 12)->nullable(); // too_warm | too_cold
                $table->string('period', 8)->default('other'); // morning | evening | other
                $table->string('source', 12)->default('manual'); // manual (puis probe / sensor)
                $table->timestamp('recorded_at')->index();
                $table->string('timezone', 40)->nullable();

                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('operator_name', 80)->nullable();

                // écart : action corrective (clé de config/hygiene.php) + commentaire libre
                $table->string('corrective_action', 24)->nullable();
                $table->string('corrective_note', 200)->nullable();

                // annulation (faute de frappe) : la ligne reste au journal
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 120)->nullable();

                // anti-doublon (envoi rejoué après une coupure réseau)
                $table->string('client_ref', 40)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'client_ref'], 'hyg_read_client_ref_unique');
                $table->index(['branch_id', 'recorded_at'], 'hyg_read_branch_time');
                $table->index(['equipment_id', 'recorded_at'], 'hyg_read_equip_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_temperature_readings');
        Schema::dropIfExists('hygiene_equipments');
    }
};
