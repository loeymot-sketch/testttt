<?php

use App\Console\Commands\EnsureKidsMenuStepsCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER 2026-07-17] Menus enfants borne+caisse : « Menu Enfant Nuggets » → étape
 * choix de sauce ; « Menu Enfant Chicken Burger » → crudités (Salade, Tomate,
 * Oignon) puis suppléments standard.
 *
 * Délègue à {@see EnsureKidsMenuStepsCommand::ensure()} (idempotente, data-only,
 * 0 frozen) : variations sauce copiées du burger de référence, extras
 * crudite/supplement, profils composer PUBLIÉS niveau item (seul levier projeté
 * borne + caisse, cat menu-enfant étant template 'simple').
 *
 * Guard class_exists : replay-safe si la commande est renommée/supprimée un jour —
 * l'état cible resterait ré-assurable via la commande artisan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(EnsureKidsMenuStepsCommand::class)) {
            return;
        }

        EnsureKidsMenuStepsCommand::ensure(false);
    }

    public function down(): void
    {
        // No-op réversible : dépublier les profils suffit à revenir à l'heuristique
        // (UPDATE item_wizard_profiles SET is_published=0 WHERE item_id IN (40,106)) ;
        // on ne supprime pas de data pour ne pas casser d'commandes historiques.
    }
};
