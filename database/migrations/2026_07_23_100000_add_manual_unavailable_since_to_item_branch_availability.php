<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [panel-manual-86-reason-collision — DÉCISION OWNER « A » 2026-07-23]
 *
 * Ajoute une PROVENANCE explicite au 86 d'un ITEM (item_branch_availability),
 * calquée sur le pattern éprouvé stock_levels.manual_unavailable_* (migration
 * 2026_05_08_150000 + StockManualReasonSurfacingSentinelTest) — mais sur la
 * BONNE table : le 86 d'un item vit dans item_branch_availability, pas dans
 * stock_levels (qui ne sert que le chemin extras/variations).
 *
 * POURQUOI : le 86 MANUEL (panel caisse/KDS/dashboard + /m téléphone) et la
 * rupture AUTO physique (StockService::syncItemAvailabilityForStockLevel + cron
 * stock:scan-rupture) écrivent TOUS LES DEUX unavailable_reason='stock_rupture'.
 * StockService POSSÈDE ce slug comme sa raison « réactivable au restock » : un
 * réapprovisionnement (annulation/remboursement qui recrédite on_hand, ou futur
 * endpoint de réception) rallumait donc un produit coupé VOLONTAIREMENT à la main
 * (friteuse en panne → livraison de patates → frites rallumées à tort).
 *
 *   manual_unavailable_since != null  =>  86 décidé par un HUMAIN : STICKY, le
 *                                         restock NE réactive PAS (seul un humain
 *                                         rallume via toggle(available=true)).
 *   manual_unavailable_since == null  =>  86 AUTO stock : réactivable au restock
 *                                         comme avant (comportement inchangé).
 *
 * Le slug 'stock_rupture' reste IDENTIQUE pour l'affichage (dashboard, /m, borne,
 * projection, toasts POS/KDS) : zéro impact vocabulaire/UI. La provenance est un
 * signal SÉPARÉ, lu uniquement par la garde de réactivation de StockService.
 *
 * Zero-downtime : ADD nullable + index, aucune réécriture destructive. Les
 * anciens appelants (StockService decrement/release) continuent à voir null.
 *
 * BACKFILL SÛR : les lignes EXISTANTES en 'stock_rupture' sont AMBIGUËS (manuel
 * vs auto — indistinguables faute de provenance jusqu'ici). On les marque
 * MANUELLES (sticky) : direction fail-safe. Un 86 manuel préexistant est ainsi
 * immédiatement protégé ; une rupture auto préexistante devient sticky et
 * demandera UNE réactivation manuelle après restock (coût négligeable en V1
 * mono-poste, lignes transitoires même-jour) — jamais l'inverse dangereux
 * (rallumer un 86 humain). Les NOUVELLES ruptures auto (post-migration) portent
 * manual_unavailable_since=null et restent réactivables normalement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_branch_availability')
            || Schema::hasColumn('item_branch_availability', 'manual_unavailable_since')) {
            return;
        }

        Schema::table('item_branch_availability', function (Blueprint $table) {
            $table->dateTime('manual_unavailable_since')->nullable()->after('unavailable_since');
            $table->index('manual_unavailable_since', 'iba_manual_unavailable_since_idx');
        });

        // Backfill SÛR : tout 86 'stock_rupture' existant devient manuel/sticky.
        // Deux passes portables (MySQL + SQLite) : recopier unavailable_since quand
        // il existe, puis combler les null résiduels avec l'instant courant.
        DB::table('item_branch_availability')
            ->where('is_available', false)
            ->where('unavailable_reason', 'stock_rupture')
            ->whereNull('manual_unavailable_since')
            ->whereNotNull('unavailable_since')
            ->update(['manual_unavailable_since' => DB::raw('unavailable_since')]);

        DB::table('item_branch_availability')
            ->where('is_available', false)
            ->where('unavailable_reason', 'stock_rupture')
            ->whereNull('manual_unavailable_since')
            ->update(['manual_unavailable_since' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('item_branch_availability')
            || ! Schema::hasColumn('item_branch_availability', 'manual_unavailable_since')) {
            return;
        }

        Schema::table('item_branch_availability', function (Blueprint $table) {
            $table->dropIndex('iba_manual_unavailable_since_idx');
            $table->dropColumn('manual_unavailable_since');
        });
    }
};
