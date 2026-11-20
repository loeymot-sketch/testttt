<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-11-18 · audit E2E P1-4] Journal des changements de STRUCTURE d'un équipement de température : création, nom, type, seuil bas,
 * seuil haut, masquage / réactivation, suppression. Qui, quand, ancienne → nouvelle valeur. C'est ce qu'un inspecteur regarde : un frigo passé de
 * 4 à 8 °C puis remis à 4 ne laissait AUCUNE trace (seul `updated_at` bougeait).
 *
 * En AJOUT SEUL : aucune route ne modifie ni ne supprime une ligne, et le modèle refuse toute mise à jour ou suppression.
 * Additif : une table neuve, aucune table existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hygiene_equipment_changes')) return;

        Schema::create('hygiene_equipment_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('equipment_id');
            $t->string('equipment_name', 80);          // contexte copié : le nom AU MOMENT du changement
            $t->string('field', 20);                   // created | name | kind | min_c | max_c | is_active | deleted
            $t->string('old_value', 80)->nullable();
            $t->string('new_value', 80)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_name', 80)->nullable();   // contexte copié : la personne AU MOMENT du changement
            $t->timestamp('changed_at');
            $t->timestamp('created_at')->nullable();
            $t->index(['equipment_id', 'id'], 'hy_eq_changes_equipment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_equipment_changes');
    }
};
