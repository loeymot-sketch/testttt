<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a — Domaine ACHATS/FACTURES]
 *
 * Domaine NEUF, ADDITIF, HORS NF525 (pilotage/compta uniquement — ne touche
 * JAMAIS la chaîne fiscale). Amendement #6 du plan : achats/fournisseurs/
 * factures = domaine complet (rien n'existait, seul le carnet plat).
 *
 * Trois tables :
 *  - suppliers          : fournisseurs (Metro, Promocash…).
 *  - purchase_documents : une facture/ticket photographié (le BRUT est stocké
 *    — photo_path, amendement #8 = donnée d'apprentissage B6). Idempotence
 *    portée par doc_hash UNIQUE (ré-ingérer la même facture = rejeté).
 *  - purchase_lines     : lignes lues (libellé brut + qté + prix), chacune
 *    ciblant soit une matière première (raw_material_id), soit un item
 *    revendu à l'unité (boisson → stock_levels), soit une charge sans stock.
 *
 * Branch isolation : `branch_id` porté par suppliers + purchase_documents MAIS
 * pas de BranchScope global (hard-scope explicite par les appelants — pattern
 * DailyBookEntry / RawMaterial, mono-branche V1). Exemptions déclarées dans
 * BranchScopeCoverageSentinelTest. `purchase_lines` n'a PAS de branch_id (elle
 * hérite du document parent) → aucune exemption requise.
 *
 * target_id N'A PAS de FK : il référence polymorphiquement un raw_material OU
 * un item selon target_type (null pour une charge). La cohérence est gérée
 * côté service (PurchaseService).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fournisseurs. UNIQUE(branch_id, name) — pas de doublon par branche.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->default(1)->index();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'name']);
        });

        // Documents d'achat (facture OU ticket photographié).
        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->default(1)->index();
            // Fournisseur optionnel (une facture peut être saisie sans fournisseur connu).
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();
            $table->date('doc_date');
            // Montants lus (nullable — l'IA/l'owner peut ne renseigner que le détail lignes).
            $table->decimal('total_ht', 12, 2)->nullable();
            $table->decimal('total_ttc', 12, 2)->nullable();
            $table->decimal('tva_rate', 5, 2)->nullable();
            // Stockage du BRUT (amendement #8) — chemin de la photo d'origine.
            $table->string('photo_path')->nullable();
            $table->enum('source', ['facture', 'ticket']);
            $table->enum('status', ['draft', 'validated'])->default('draft');
            // Idempotence : hash du document physique (photo/signature) — UNIQUE.
            $table->string('doc_hash')->unique();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        // Lignes d'achat. Cible polymorphe (target_type + target_id sans FK).
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_id')
                ->constrained('purchase_documents')
                ->cascadeOnDelete();
            // Libellé brut lu sur la facture (donnée d'apprentissage B6).
            $table->string('raw_label');
            $table->decimal('qty', 12, 3);
            $table->string('unit', 16);
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->decimal('tva_rate', 5, 2)->nullable();
            // Cible : raw_material (matière) | stock_item (item revendu unité) | charge (sans stock).
            $table->enum('target_type', ['raw_material', 'stock_item', 'charge']);
            // raw_material_id OU item_id ; NULL si charge. Pas de FK (polymorphe).
            $table->unsignedBigInteger('target_id')->nullable();
            $table->enum('status', ['proposed', 'validated'])->default('proposed');
            $table->timestamps();

            $table->index(['purchase_document_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        // Ordre inverse des FK.
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchase_documents');
        Schema::dropIfExists('suppliers');
    }
};
