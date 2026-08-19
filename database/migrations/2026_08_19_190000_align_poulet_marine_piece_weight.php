<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER 2026-08-19] Le poulet du bandeau de cuisson se compte désormais en DEMI-PORTIONS.
 *
 * POURQUOI CETTE MIGRATION EXISTE
 * -------------------------------
 * Le propriétaire a demandé de DOUBLER le poulet affiché en cuisson (« une portion de poulet
 * est affichée la moitié, alors faut doubler »). `MeatPortionCalculator::PORTION_PAR_VIANDE`
 * passe donc de 1 à 2 pour le symbole « P ».
 *
 * Or ces mêmes pièces alimentent la CONSOMMATION DE STOCK : `MeatMaterialResolver` les convertit
 * en grammes via `raw_materials.piece_weight_g`. Laisser ce poids à 200 g pendant que le compte
 * double ferait sortir 400 g de poulet pour un Cayenne au lieu de 200 — une dérive silencieuse
 * de +100 % sur la matière la plus vendue du restaurant. Le poids d'UNE UNITÉ COMPTÉE suit donc
 * le changement d'unité : 200 g -> 100 g, et la quantité physique reste rigoureusement identique.
 *
 * `stock:ensure-meat-materials` sait déjà réaligner cette ligne, mais c'est une commande
 * MANUELLE : sans cette migration, un déploiement laisserait la base sur l'ancien poids et
 * personne ne le verrait passer.
 *
 * PORTÉE
 * ------
 * Une seule colonne, une seule matière, sur toutes les branches. Aucun mouvement de stock déjà
 * écrit n'est touché : `stock_movements` conserve des GRAMMES, qui restent justes tels quels.
 * Conditionnée à la valeur attendue (200) pour être rejouable sans jamais diviser deux fois.
 */
return new class extends Migration
{
    private const ANCIEN_POIDS = 200.0;

    private const NOUVEAU_POIDS = 100.0;

    public function up(): void
    {
        DB::table('raw_materials')
            ->whereRaw('LOWER(name) = ?', ['poulet mariné'])
            ->where('piece_weight_g', self::ANCIEN_POIDS)
            ->update(['piece_weight_g' => self::NOUVEAU_POIDS, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('raw_materials')
            ->whereRaw('LOWER(name) = ?', ['poulet mariné'])
            ->where('piece_weight_g', self::NOUVEAU_POIDS)
            ->update(['piece_weight_g' => self::ANCIEN_POIDS, 'updated_at' => now()]);
    }
};
