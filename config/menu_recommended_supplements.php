<?php

/**
 * [OWNER 2026-10-21] Suppléments « RECOMMANDÉS » : ceux qu'on met en avant au client (badge + en tête de liste)
 * pour relever le panier moyen. C'est une SUGGESTION d'affichage : aucun prix n'est décidé ici, le client renvoie
 * des ids d'extras et PricingService facture comme avant.
 *
 * Résolution (RecommendedSupplements::isRecommended) : la liste PROPRE AU PRODUIT (`by_item`) prime ; à défaut, la liste
 * de sa CATÉGORIE (`by_category`) ; une catégorie absente n'a aucune recommandation. Les noms sont comparés sans
 * casse ni accents, et doivent être ceux des extras du catalogue (item_extras.name, group_label « supplement »).
 * « Viande supplémentaire » n'a rien à faire ici : la borne la gère à l'étape Viande, pas dans la liste des suppléments.
 */
return [
    'by_item' => [
        // Cayenne : la sauce fromagère est déjà incluse ; qui en veut plus va vers le fromage.
        'Cayenne'         => ['Cheddar', 'Boursin'],
        'Galette Cayenne' => ['Cheddar', 'Boursin'],
    ],

    'by_category' => [
        'Sandwichs' => ['Cheddar', 'Œuf', 'Oignons frits'],
        'Galette'   => ['Cheddar', 'Œuf', 'Oignons frits'],
        'Burgers'   => ['Raclette', 'Œuf', 'Légumes sautés'],
        'Tacos'     => ['Cheddar', 'Boursin', 'Champignons'],
    ],
];
