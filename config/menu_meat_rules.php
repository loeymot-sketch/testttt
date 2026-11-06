<?php

/**
 * [VG-2026-10-21 M5-001b M3-101 M5-001 M1-004 · OWNER 2026-10-22] Nombre de viandes INCLUSES dans le prix d'un article,
 * publié aux clients en `viande_count` (borne : KioskMenuService ; site et fiche produit : NormalItemResource).
 *
 * POURQUOI UNE RÈGLE ÉCRITE ICI. Aucune donnée du catalogue ne porte ce nombre : ni la taille (les Tacos M/L/XL sont trois
 * articles distincts), ni le profil publié (Tacos M, L et XL ont tous « viande » min 1, « viande_2 »/« viande_3 » min 0),
 * ni l'attribut. Le compter à partir des attributs « Viande N » portant des variations a vendu un Tacos M à 3 viandes
 * pour 6,90 € (la borne GELÉE exige EXACTEMENT `viande_count` viandes et les sert gratuites) dès que la matérialisation
 * du 2026-09-02 a posé Viande 2/3 sur les trois Tacos et Viande 2 sur les sandwichs.
 *
 * Décisions du propriétaire (2026-10-22) :
 *   - Tacos : M = 1 viande, L = 2, XL = 3.
 *   - Méga et Terminator : 2 viandes imposées.
 *   - Sandwich (Cayenne, Classique, et tout sandwich à « Viande 2 » facultative) : 1 viande ; une 2e viande est
 *     proposée PAYANTE à 2,50 € via l'extra « Viande supplémentaire », plus jamais gratuite.
 *   - Bols, burgers, galettes : inchangés (1, leur seul emplacement « Viande 1 »).
 *   - Suprême : NON tranché par le propriétaire ; sa fiche décrit DEUX viandes fixes (« Steak haché, cordon bleu ») —
 *     on garde donc sa valeur actuelle (2). À confirmer par le propriétaire.
 *
 * Résolution (App\Support\Menu\IncludedMeatCount) : la valeur du PRODUIT (`by_item`) prime ; à défaut, celle de sa
 * CATÉGORIE (`by_category`) ; à défaut, le nombre d'emplacements « Viande N » du catalogue. La règle n'est jamais
 * supérieure à ce nombre d'emplacements (la borne place la i-ème viande sur le i-ème attribut : une viande sans
 * emplacement serait perdue en silence) et un article sans emplacement viande reste à 0.
 * Noms comparés sans casse ni accents (même convention que config/menu_recommended_supplements.php).
 *
 * Ce fichier ne fixe AUCUN prix : PricingService (gelé) facture ce que la commande contient, depuis le catalogue.
 */
return [
    'by_item' => [
        'Tacos M'            => 1,
        'Tacos L'            => 2,
        'Tacos XL'           => 3,
        'Méga'               => 2,
        'Terminator'         => 2,
        'Cayenne'            => 1,
        'Sandwich Classique' => 1,
        'Suprême'            => 2,
    ],

    'by_category' => [
        // « Tout sandwich à Viande 2 facultative » : une viande incluse, la suivante passe par l'extra payant.
        'Sandwichs' => 1,
    ],
];
