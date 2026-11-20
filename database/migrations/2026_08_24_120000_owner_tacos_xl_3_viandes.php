<?php

use App\Console\Commands\EnsureTacosXl3ViandesCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER TACOS-XL 2026-08-24] Applique au `migrate --force` du déploiement :
 *   · le tacos DEUX viandes (« Tacos L ») passe de 7,90 € à 8,90 € ;
 *   · le tacos TROIS viandes entre en carte — « Tacos XL » à 10,90 €, trois viandes au choix
 *     COMPRISES dans le prix, même photo et même personnalisation que ses aînés.
 *
 * Délègue à la commande idempotente {@see EnsureTacosXl3ViandesCommand::ensure()} : re-jouable
 * sans doublon, et ré-exécutable à la main (`php artisan menu:ensure-tacos-xl`) si une base
 * particulière est en retard. DATA only — aucune zone gelée, aucun chemin NF525 touché : le prix
 * reste scellé par PricingService à partir de la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);
    }

    public function down(): void
    {
        // Volontairement NON réversible.
        //
        // Retirer le Tacos XL supprimerait un produit potentiellement DÉJÀ VENDU : les lignes de
        // commande et la chaîne fiscale NF525 le référencent, et six ans de rétention l'exigent
        // intact. Remettre le Tacos L à 7,90 € réécrirait de son côté un prix que le propriétaire
        // a explicitement changé.
        //
        // Pour un vrai retour arrière, passer les articles en INACTIF depuis l'admin (réversible,
        // historique préservé) — jamais par une suppression.
    }
};
