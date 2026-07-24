<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c — écran de scan]
 *
 * ADDITIF, HORS NF525. Le classifieur ({@see InvoiceClassificationService})
 * calcule DÉJÀ un `score` (0..1) de confiance + un flag `matched` (la ligne
 * a-t-elle matché une cible connue, ou repli charge non-confirmé). P3b les
 * jetait ; P3c en a besoin pour l'UI (« proposé par IA » + score, surligner
 * les lignes non-matchées à confirmer par l'owner AVANT validation).
 *
 * Deux colonnes NULLABLE (les lignes P3b antérieures restent NULL = inconnu).
 * Aucune donnée existante réécrite, aucune contrainte fiscale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            // Score de match IA (0..1). NULL = ligne legacy pré-P3c.
            $table->decimal('score', 4, 3)->nullable()->after('status');
            // La ligne a matché une cible connue (true) ou repli non-confirmé (false).
            $table->boolean('matched')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn(['score', 'matched']);
        });
    }
};
