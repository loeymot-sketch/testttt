<?php

use App\Console\Commands\SyncOwnerCatalog20261111Command;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER CATALOGUE 2026-11-11 · correctif du soir] « Sandwich 2 Viandes » : ses deux viandes au choix sont celles du
 * Terminator (sandwich de référence à deux viandes), pas seulement « Poulet mariné » hérité du Sandwich Classique —
 * en ligne et à la borne le produit exigeait deux viandes et n'en proposait qu'une : impossible à commander.
 *
 * Rejoue la commande idempotente {@see SyncOwnerCatalog20261111Command::ensure()} (déjà jouée en production par
 * 2026_11_11_040000) : AJOUT de variations seulement, rien supprimé, rien de facturé (viandes comprises à 0 €).
 */
return new class extends Migration
{
    public function up(): void
    {
        SyncOwnerCatalog20261111Command::ensure(false);
    }

    public function down(): void
    {
        // Non réversible (NF525) : retirer une viande vendue casserait l'historique. Retour arrière = admin.
    }
};
