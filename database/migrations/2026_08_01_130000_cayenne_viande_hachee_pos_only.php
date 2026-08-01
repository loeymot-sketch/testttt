<?php

use App\Console\Commands\EnsureCayenneMixteCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER 2026-08-01] Le Cayenne sandwich (#22) propose désormais TROIS choix de viande, et
 * UNIQUEMENT EN CAISSE : « Poulet mariné » · « Mixte (hachée + poulet) » · « Viande Hachée ».
 *
 * Les trois sont GRATUITS (@0) : ce sont des CHOIX de viande, pas des suppléments. Le surplus
 * de viande reste facturé par l'ItemExtra « Viande supplémentaire » @2,50, INCHANGÉ.
 *
 * BORNE : rigoureusement inchangée. Les trois variations sont visible_on=['pos'], donc la borne
 * voit 0 variation viande sur le #22 → aucune étape de choix de viande, exactement comme avant.
 * La Galette Cayenne (#24) n'est pas touchée : « Viande Hachée » y fait déjà partie des 7 viandes
 * visibles sur toutes les surfaces.
 *
 * Re-joue le ensure() étendu. Idempotent (par item+attribut+nom, et ressuscite la ligne
 * soft-supprimée au lieu d'insérer un doublon). DATA only, 0 frozen.
 * NF525 : catalogue seulement — aucune commande scellée touchée (composition_snapshot immuable).
 */
return new class extends Migration
{
    public function up(): void
    {
        EnsureCayenneMixteCommand::ensure(false);
    }

    public function down(): void
    {
        // No-op volontaire : retirer un choix de viande demandé par l'owner serait un retour
        // arrière non désiré, et le soft-delete de la variation casserait l'affichage des
        // commandes passées qui la référencent.
    }
};
