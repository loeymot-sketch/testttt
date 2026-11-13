<?php

use App\Console\Commands\SyncOwnerCatalog20261111Command;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER CATALOGUE 2026-11-11 · correctif du soir] « Sandwich 2 Viandes » : ses deux viandes au choix sont celles du
 * Terminator (sandwich de référence à deux viandes), pas seulement « Poulet mariné » hérité du Sandwich Classique —
 * en ligne et à la borne le produit exigeait deux viandes et n'en proposait qu'une : impossible à commander.
 *
 * N'exécute QUE l'étape des viandes {@see SyncOwnerCatalog20261111Command::ensureSandwich2ViandesMeats()} : AJOUT de
 * variations seulement, rien supprimé, rien de facturé (viandes comprises à 0 €).
 * [SUPERVISION 2026-11-13] Elle rejouait ensure() en entier — en production (040000 déjà jouée le 11/11) le déploiement
 * aurait réimposé prix/statut/disponibilité du Sandwich 2 Viandes et des Tenders et le prix du supplément Façon Cayenne,
 * écrasant les retouches admin. Garde : tests/Feature/Menu/SandwichDeuxViandesMigrationRespecteAdminTest.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        SyncOwnerCatalog20261111Command::ensureSandwich2ViandesMeats(false);
    }

    public function down(): void
    {
        // Non réversible (NF525) : retirer une viande vendue casserait l'historique. Retour arrière = admin.
    }
};
