<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a — B1+B2 foundations]
 *
 * Couche ADDITIVE « matières premières » (raw materials / BOM). Domaine NEUF,
 * distinct de l'`Ingredient*` existant (availability virtuelle) et de
 * `stock_levels` (unsigned, CHECK>=0, enum fermé). Ici : décimales SIGNÉES,
 * mouvements append-only idempotents — miroir du pattern StockService (plan
 * amendement #3).
 *
 * NF525 : cette couche NE TOUCHE JAMAIS la chaîne fiscale — aucune migration
 * de trigger, aucun listener fiscal. Lecture des snapshots seulement (P2+).
 *
 * Branch isolation : `branch_id` porté par chaque table MAIS pas de BranchScope
 * global (hard-scope explicite par les appelants — pattern DailyBookEntry,
 * mono-branche V1). Exemption déclarée dans BranchScopeCoverageSentinelTest.
 *
 * Tables créées dans l'ordre des FK (parent `raw_materials` en premier).
 */
return new class extends Migration
{
    public function up(): void
    {
        // B1 — Matières premières (nom, unité de base, poids/pièce, coût moyen, seuil).
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->default(1)->index();
            $table->string('name');
            // g | piece | tranche | cl ...
            $table->string('unit', 16);
            // Poids d'une pièce en grammes (ex : steak façonné ~75 g). NULL si non applicable.
            $table->decimal('piece_weight_g', 8, 2)->nullable();
            // Prix d'achat moyen (mis à jour par les entrées facture en P3).
            $table->decimal('avg_cost', 10, 4)->nullable();
            // Seuil d'alerte stock bas (théorique).
            $table->decimal('threshold_low', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'name']);
        });

        // B2 — Recettes : sujet vendable (produit/variation/extra, mappé par GROUPE
        // logique via subject_group — plan amendement #4) → matière + quantité.
        Schema::create('raw_material_recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->default(1);
            // Type de sujet (ex : App\Models\Item, ItemVariation, ItemExtra) — string libre.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            // Groupe logique optionnel (43 noms ≈ 535 rows ItemExtra) — plan amendement #4.
            $table->string('subject_group')->nullable();
            $table->foreignId('raw_material_id')
                ->constrained('raw_materials')
                ->cascadeOnDelete();
            // Quantité de matière consommée par unité de sujet (dans l'unité de la matière).
            $table->decimal('qty', 10, 3);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['branch_id', 'subject_type', 'subject_id', 'raw_material_id'],
                'rm_recipe_subject_unique'
            );
        });

        // Stock théorique courant par matière + branche. on_hand SIGNÉ (peut passer
        // négatif — l'inventaire correcteur mensuel réaligne ; pas de CHECK>=0).
        Schema::create('raw_material_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_material_id')->constrained('raw_materials');
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->decimal('on_hand', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['raw_material_id', 'branch_id']);
        });

        // Ledger append-only des mouvements de stock (entrée/consommation/ajustement).
        // Idempotence portée par (source_type, source_id, raw_material_id) côté service.
        Schema::create('raw_material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_material_id')->constrained('raw_materials');
            $table->unsignedBigInteger('branch_id')->default(1);
            // Delta SIGNÉ (+ entrée, - consommation, ± ajustement inventaire).
            $table->decimal('delta', 12, 3);
            $table->string('reason', 64);
            // Origine rejouable (ex : order / invoice_line / inventory_count). NULL = manuel.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('meta')->nullable();
            // Append-only : created_at seul (pas d'updated_at).
            $table->timestamp('created_at')->nullable();

            $table->index(['raw_material_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        // Ordre inverse des FK.
        Schema::dropIfExists('raw_material_movements');
        Schema::dropIfExists('raw_material_stocks');
        Schema::dropIfExists('raw_material_recipe_lines');
        Schema::dropIfExists('raw_materials');
    }
};
