<?php

use App\Console\Commands\SyncOwnerCatalog20261111Command;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER CATALOGUE 2026-11-11] Appliqué au `migrate --force` du déploiement :
 *   · « 5 Tenders » à 5,00 € (produit simple, catégorie « Tenders ») ;
 *   · « Sandwich 2 Viandes » à 9,00 € (Sandwich Classique en deux viandes comprises) ;
 *   · supplément « Façon Cayenne » à 1,50 € sur les sandwichs (sauf le Cayenne) ;
 *   · libellés « steak haché frais », « base fromage et crème fraîche », « galette croustillante ».
 *
 * Délègue à la commande idempotente {@see SyncOwnerCatalog20261111Command::ensure()} : re-jouable sans
 * doublon, ré-exécutable à la main (`php artisan menu:owner-2026-11-11`). Sans effet sur une base sans la
 * carte Le Cayenne (bases de test). DATA only — aucun prix calculé ici, aucune zone gelée, aucun chemin NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        SyncOwnerCatalog20261111Command::ensure(false);
    }

    public function down(): void
    {
        // Volontairement NON réversible : retirer un produit déjà vendu casserait l'historique fiscal (NF525,
        // six ans). Retour arrière = passer les articles en INACTIF depuis l'admin.
    }
};
