<?php

use App\Console\Commands\EnsureCayenneMixteCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [INCIDENT BORNE 2026-08-01] DÉBLOQUE LA BORNE — correctif urgent.
 *
 * SYMPTÔME EN PRODUCTION : sur la borne, un panier contenant un Cayenne ne pouvait plus être
 * validé du tout. Message affiché au client : « Sélectionnez au moins 1 Viande 1 (actuel : 0) ».
 * Panier plein (28,30 €), bouton « Valider ma commande » sans effet. Vente perdue.
 *
 * CAUSE : `min_select` est porté par l'ATTRIBUT « Viande 1 » (table item_attributes, partagé par
 * plusieurs items), PAS par le nombre de variations visibles sur la surface. En passant TOUTES les
 * viandes du Cayenne #22 en `visible_on=['pos']` (c53c7a820, puis étendu par 2026_08_01_130000),
 * la borne héritait d'une étape OBLIGATOIRE avec ZÉRO option sélectionnable → validation
 * impossible. Mettre min_select à 0 n'est PAS une option : l'attribut est partagé (la Galette et
 * les autres perdraient leur choix de viande obligatoire).
 *
 * CORRECTIF : la viande SIGNATURE « Poulet mariné » redevient visible sur TOUTES les surfaces.
 *   · BORNE + SITE WEB : exactement 1 viande → toujours du poulet, contrainte satisfaite, plus
 *     aucun blocage. C'est le comportement attendu par l'owner (« toujours poulet »).
 *   · CAISSE : conserve les 3 choix — Poulet mariné · Mixte · Viande Hachée.
 * Les choix EN PLUS (Mixte, Viande Hachée) restent `['pos']`, donc invisibles borne/web.
 *
 * Un garde-fou (EnsureCayenneMixteCommand::assertKioskHasAtLeastOneMeat) fait désormais échouer
 * bruyamment toute tentative future de vider l'étape obligatoire côté borne.
 *
 * Idempotent. DATA only, 0 frozen. NF525 : catalogue seulement, aucune commande scellée touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-joue le ensure() corrigé (repositionne les visible_on et applique le garde-fou).
        EnsureCayenneMixteCommand::ensure(false);

        // Ceinture + bretelles : même si un item échappait à ensure(), aucune viande signature ne
        // doit rester cachée à la borne sur un sandwich mono-viande.
        $viandeAttrId = DB::table('item_attributes')->where('name', EnsureCayenneMixteCommand::ATTR_VIANDE_1_NAME)->value('id');
        if ($viandeAttrId !== null) {
            DB::table('item_variations')
                ->whereIn('item_id', DB::table('items')->where('name', 'Cayenne')->pluck('id'))
                ->where('item_attribute_id', $viandeAttrId)
                ->where('name', EnsureCayenneMixteCommand::SIGNATURE_MEAT)
                ->whereNull('deleted_at')
                ->update(['visible_on' => null, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // No-op volontaire : re-cacher la viande signature re-bloquerait la borne.
    }
};
