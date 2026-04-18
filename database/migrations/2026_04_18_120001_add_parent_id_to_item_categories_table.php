<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (1/8)
 *
 * Ajoute un `parent_id` nullable auto-référencé sur `item_categories` pour
 * permettre une hiérarchie à 2 niveaux (ex. Burgers → Burgers Signature).
 *
 * Règles :
 *  - Profondeur maximale : 2 (enforced au niveau service
 *    `App\Services\ItemCategoryHierarchyService::validateParent`, pas de
 *    trigger SQL).
 *  - `ON DELETE SET NULL` : supprimer une catégorie parente détache les
 *    enfants sans les perdre.
 *  - Backfill : aucune catégorie existante n'a de parent — pas d'opération
 *    de backfill nécessaire.
 *
 * Idempotent : guard `Schema::hasColumn` pour relance sans casser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            if (!Schema::hasColumn('item_categories', 'parent_id')) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('item_categories')
                    ->nullOnDelete();

                $table->index(['parent_id', 'status'], 'item_categories_parent_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            if (Schema::hasColumn('item_categories', 'parent_id')) {
                // MySQL enforces : FK constraints rely on the column's index ;
                // on doit donc dropper la FK AVANT l'index, puis la colonne.
                $table->dropForeign(['parent_id']);
                $table->dropIndex('item_categories_parent_status_idx');
                $table->dropColumn('parent_id');
            }
        });
    }
};
