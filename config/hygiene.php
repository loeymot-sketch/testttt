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
     *   fryer     — friteuse (domaine « oil » : suivi de l'huile, pas de relevé de température d'enceinte).
     * `domain` : l'écran qui montre ce type (temperature | oil). Un équipement n'apparaît que dans SON écran.
     */
    'kinds' => [
        'fridge' => ['domain' => 'temperature', 'label' => 'Réfrigérateur', 'short' => 'Frigo', 'min' => 0.0, 'max' => 4.0, 'negative' => false],
        'cold_room' => ['domain' => 'temperature', 'label' => 'Chambre froide', 'short' => 'Chambre froide', 'min' => 0.0, 'max' => 4.0, 'negative' => false],
        'display' => ['domain' => 'temperature', 'label' => 'Vitrine réfrigérée', 'short' => 'Vitrine', 'min' => 0.0, 'max' => 4.0, 'negative' => false],
        'freezer' => ['domain' => 'temperature', 'label' => 'Congélateur', 'short' => 'Congélateur', 'min' => null, 'max' => -18.0, 'negative' => true],
        // Friteuse : même référentiel d'équipements (comme Komia), mais jamais proposée au relevé de température d'enceinte froide.
        'fryer' => ['domain' => 'oil', 'label' => 'Friteuse', 'short' => 'Friteuse', 'min' => null, 'max' => null, 'negative' => false],
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

    /**
     * Réception des marchandises. Trois familles de température (comme le camion : frais / surgelé / ambiant), chacune avec SA limite.
     * Valeurs de départ prudentes (GBPH restauration, arrêté du 21/12/2009) : le plan de maîtrise sanitaire du restaurant fait foi, et
     * ces limites se changent ici. `ambient` : relevé tracé sans limite (aucun seuil réglementaire général).
     * `plausible` : au-delà, c'est une faute de frappe (« 30 » pour « 3 ») et le relevé est refusé.
     */
    'reception' => [
        'categories' => [
            'chilled' => ['label' => 'Frais', 'max' => 4.0, 'negative' => false, 'plausible' => [-10.0, 40.0]],
            'frozen' => ['label' => 'Surgelé', 'max' => -18.0, 'negative' => true, 'plausible' => [-45.0, 15.0]],
            'ambient' => ['label' => 'Ambiant', 'max' => null, 'negative' => false, 'plausible' => [-10.0, 60.0]],
        ],
        /** Contrôles visuels : chacun doit être répondu (conforme ou non) avant d'enregistrer. */
        'checks' => [
            'packaging' => 'Emballages intacts et propres',
            'dlc' => 'DLC / DLUO suffisantes',
            'truck' => 'Véhicule et livreur propres',
            'labelling' => 'Étiquetage et traçabilité présents',
            'order' => 'Conforme à la commande (produits et quantités)',
        ],
        /** Un écart (température hors limite ou contrôle non conforme) interdit « accepté » : réserve ou refus, avec un commentaire. */
        'decisions' => ['accepted' => 'Accepté', 'reserve' => 'Accepté avec réserve', 'refused' => 'Refusé'],
    ],

    /**
     * Huile de friture. Limite LÉGALE : 25 % de composés polaires (décret n° 2008-1463) — au-delà l'huile doit être changée.
     * `polar_warn` : seuil « à surveiller » (valeur de départ prudente, sans valeur réglementaire). `bath_max_c` : au-delà de 180 °C
     * l'huile se dégrade vite (recommandation courante) — écart à corriger. Toutes ces valeurs se changent ici.
     */
    'oil' => [
        'polar_limit' => 25.0,
        'polar_warn' => 20.0,
        'bath_max_c' => 180.0,
        'plausible_polar' => [0.0, 60.0],
        'plausible_bath' => [20.0, 260.0],
        'types' => ['test' => 'Test de l’huile', 'filter' => 'Filtrage', 'change' => 'Vidange / changement'],
        /** Écart (polaires au-dessus de la limite, bain trop chaud) : ce qui a été fait est OBLIGATOIRE. `note` : commentaire exigé. */
        'corrective_actions' => [
            'oil_changed' => ['label' => 'Huile changée', 'note' => false],
            'fryer_stopped' => ['label' => 'Friteuse arrêtée', 'note' => false],
            'thermostat_set' => ['label' => 'Température du bain réglée', 'note' => false],
            'retest' => ['label' => 'Filtrée puis nouveau test', 'note' => false],
            'other' => ['label' => 'Autre (à préciser)', 'note' => true],
        ],
    ],
];
