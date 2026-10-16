<?php

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Module hygiène — relevés de température des équipements froids.
 *
 * Ce fichier est LE contrat du module : types d'équipement, seuils par défaut, actions correctives, moments de relevé.
 * Il ne dépend ni de la caisse, ni de la comptabilité, ni des commandes : le module peut être extrait tel quel dans une
 * application « Cayenne Hygiène » autonome (mêmes tables `hygiene_*`, même API `/admin/hygiene/*`).
 */
return [
    // Restaurant unique (V1 LOCAL) : branche portant les lignes créées par un compte administrateur (branch_id=0).
    'branch_id' => (int) env('HYGIENE_BRANCH_ID', 1),

    /**
     * Types d'équipement. `min` / `max` : seuils par défaut en °C (null = pas de borne de ce côté).
     *   fridge    — réfrigérateur positif : 0 à +4 °C (GBPH restauration ; produits les plus sensibles).
     *   cold_room — chambre froide positive : 0 à +4 °C.
     *   display   — vitrine / saladette réfrigérée : 0 à +4 °C.
     *   freezer   — congélateur : −18 °C ou moins (jamais de borne basse).
     */
    'kinds' => [
        'fridge' => ['label' => 'Réfrigérateur', 'short' => 'Frigo', 'min' => 0.0, 'max' => 4.0, 'negative' => false],
        'cold_room' => ['label' => 'Chambre froide', 'short' => 'Chambre froide', 'min' => 0.0, 'max' => 4.0, 'negative' => false],
        'display' => ['label' => 'Vitrine réfrigérée', 'short' => 'Vitrine', 'min' => 0.0, 'max' => 4.0, 'negative' => false],
        'freezer' => ['label' => 'Congélateur', 'short' => 'Congélateur', 'min' => null, 'max' => -18.0, 'negative' => true],
    ],

    /** Valeur plausible d'un thermomètre d'enceinte froide : au-delà, c'est une faute de frappe (« 30 » pour « 3 »). */
    'plausible_min' => -60.0,
    'plausible_max' => 60.0,

    /**
     * Action corrective, OBLIGATOIRE quand le relevé sort des seuils (HACCP : tout écart est tracé avec ce qui a été fait).
     * `note` : un commentaire libre est exigé avec cette action.
     */
    'corrective_actions' => [
        'recheck' => ['label' => 'Nouvelle mesure après quelques minutes', 'note' => false],
        'door_closed' => ['label' => 'Porte mal fermée, refermée', 'note' => false],
        'defrost' => ['label' => 'Dégivrage en cours', 'note' => false],
        'products_checked' => ['label' => 'Produits contrôlés (température à cœur)', 'note' => false],
        'products_moved' => ['label' => 'Produits déplacés vers un autre équipement', 'note' => false],
        'products_discarded' => ['label' => 'Produits jetés', 'note' => false],
        'technician' => ['label' => 'Technicien appelé', 'note' => false],
        'other' => ['label' => 'Autre (à préciser)', 'note' => true],
    ],

    /** Moments d'un relevé quotidien : avant 15 h = matin (ouverture), après = soir (fermeture). */
    'periods' => ['morning' => 'Matin', 'evening' => 'Soir', 'other' => 'Autre'],
    'evening_from_hour' => 15,
];
