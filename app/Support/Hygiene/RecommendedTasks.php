<?php

namespace App\Support\Hygiene;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Plan de nettoyage de départ d'un restaurant de burgers : l'écran n'est jamais vide au
 * premier lancement. Les libellés reprennent la structure vue chez Komia (cuisine, salle, sanitaires, hotte, murs) et ajoutent
 * ce qui manque à un point de vente de friture (friteuses, déchets, froid).
 *
 * AUCUN dosage ni temps de contact n'est inventé : la fiche renvoie à l'étiquette du produit utilisé. Fréquences et contenu sont
 * des valeurs de départ — le plan de maîtrise sanitaire du restaurant fait foi, et chaque fiche est modifiable.
 */
final class RecommendedTasks
{
    private const HOW = 'Dégraisser, rincer, puis désinfecter avec un produit compatible contact alimentaire. Respecter le dosage et le temps de contact indiqués sur l’étiquette du produit.';

    /** @return list<array{catalog_key:string,name:string,zone:string,how_to:string,checklist:list<string>,frequency:string,weekday:?int,month_day:?int,sort_order:int}> */
    public static function all(): array
    {
        $rows = [
            ['cuisine', 'Cuisine et laboratoire', 'Cuisine', self::HOW, [
                'Fours et appareils de cuisson', 'Plaques de cuisson, grills et plancha', 'Plans de travail, planches et inox',
                'Portes des frigos et des placards', 'Sols de la cuisine et siphons',
            ], 'daily', null, null],
            ['friteuses', 'Friteuses et zone de friture', 'Cuisine', 'Friteuse froide et hors tension avant tout nettoyage. Dégraisser l’extérieur et les paniers ; rincer et sécher avant remise en service.', [
                'Extérieur des friteuses dégraissé', 'Paniers lavés', 'Bac à miettes vidé',
            ], 'daily', null, null],
            ['salle', 'Salle', 'Salle', self::HOW, [
                'Mobilier et surfaces', 'Sols', 'Vitrines alimentaires et présentoirs', 'TPE, caisses et bornes',
            ], 'daily', null, null],
            ['sanitaires', 'WC et sanitaires', 'Sanitaires', self::HOW, [
                'Toilettes', 'Urinoirs', 'Lavabos', 'Portes et poignées', 'Savon et papier réapprovisionnés',
            ], 'daily', null, null],
            ['dechets', 'Poubelles et local déchets', 'Déchets', self::HOW, [
                'Poubelles vidées', 'Bacs lavés et désinfectés', 'Local déchets balayé et lavé',
            ], 'daily', null, null],
            ['hotte', 'Hotte et filtres', 'Cuisine', 'Filtres démontés et dégraissés ; remontés une fois secs.', [
                'Filtres de hotte dégraissés', 'Hotte nettoyée',
            ], 'weekly', 1, null],
            ['murs', 'Murs et crédences', 'Cuisine', self::HOW, [
                'Murs et crédences',
            ], 'weekly', 1, null],
            ['froid', 'Chambres froides et réfrigérateurs', 'Cuisine', self::HOW, [
                'Parois et clayettes', 'Joints de portes', 'Produits périmés ou ouverts retirés',
            ], 'weekly', 1, null],
        ];

        $out = [];
        foreach ($rows as $i => [$key, $name, $zone, $how, $items, $freq, $weekday, $monthDay]) {
            $out[] = [
                'catalog_key' => $key, 'name' => $name, 'zone' => $zone, 'how_to' => $how, 'checklist' => $items,
                'frequency' => $freq, 'weekday' => $weekday, 'month_day' => $monthDay, 'sort_order' => ($i + 1) * 10,
            ];
        }

        return $out;
    }
}
