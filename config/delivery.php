<?php

/**
 * [LIVRAISON 2026-10-20 · décision propriétaire] Barème et organisation de la livraison propre
 * du Cayenne. Remplace le barème « 4 € ≤ 3 km puis +2 €/km » du 2026-07-27.
 *
 * Barème (km = rayon à vol d'oiseau depuis le restaurant, hors Hénin-Beaumont) :
 *   · toute adresse de Hénin-Beaumont ........ 3,90 €
 *   · autre commune, rayon ≤ 5 km ............ 4,90 €
 *   · autre commune, rayon ≤ 8 km ............ 5,90 €
 *   · autre commune, rayon ≤ 10 km ........... 6,90 €
 *   · au-delà de 10 km ....................... refusé → on propose Uber Eats
 *
 * Les montants sont en EUROS décimaux ici, convertis en centimes par DeliveryTariff.
 */
return [
    // Commune « maison » : forfait unique, quelle que soit la distance dans la commune.
    'home_city'         => 'Hénin-Beaumont',
    'home_postal_codes' => ['62110'],
    'home_fee'          => 3.90,

    // Paliers hors commune, triés par rayon croissant. Premier palier dont max_km >= distance.
    'tiers' => [
        ['max_km' => 5.0,  'fee' => 4.90],
        ['max_km' => 8.0,  'fee' => 5.90],
        ['max_km' => 10.0, 'fee' => 6.90],
    ],

    // Polygone de zone propre à la branche (`branches.zone`) en plus du rayon : DÉSACTIVÉ. La zone de livraison est
    // « Hénin-Beaumont + rayon de 10 km » ; un polygone saisi à la main la contredirait (cf. DeliveryQuoteService).
    'use_branch_zone_polygon' => (bool) env('DELIVERY_USE_BRANCH_ZONE_POLYGON', false),

    // Au-delà : refus + proposition Uber Eats (URL lue dans la méta du site / config uber).
    'max_km' => 10.0,

    /*
     * Tournées de 30 minutes : les commandes dont le départ tombe dans la même fenêtre
     * (grille :00 / :30) partent ensemble, un seul livreur, un seul trajet. Deux commandes
     * séparées de plus d'une fenêtre = deux départs.
     */
    'wave_minutes'      => 30,
    // Nombre maximal d'arrêts dans un même départ ; au-delà la commande est reportée au créneau suivant.
    'max_stops_per_wave' => 6,
    // Temps de remise au client (sonner, monter, encaisser), minutes par arrêt.
    'handover_minutes'  => 4,
    // Vitesse moyenne urbaine retenue pour l'ESTIMATION côté client (la vraie durée = Waze côté livreur).
    'average_speed_kmh' => 28,
    // Marge fixe (garer, sortir du restaurant) ajoutée au trajet estimé, minutes.
    'travel_buffer_minutes' => 3,
    // Délai minimal entre « maintenant » et le premier départ proposé = préparation + chargement.
    'min_lead_minutes'  => 20,
    // [2026-10-24] Préparation ANNONCÉE au client par le devis public (`prep_minutes_low` / `prep_minutes_high`) : le site
    // affiche « Préparation : 15 à 20 min, puis livraison selon le trajet ». C'est une promesse d'affichage, pas un
    // réglage de cuisine. Invariant (test DeliveryCardAtDoorTest) : high <= min_lead_minutes — le premier départ proposé
    // ne peut pas tomber avant la fin de la préparation annoncée. À CONFIRMER PAR LE PROPRIÉTAIRE après quelques services.
    'prep_minutes'      => ['low' => 15, 'high' => 20],
    // Horizon : combien de jours à l'avance un créneau de livraison peut être réservé.
    'horizon_days'      => 2,
    // [VG-2026-10-21 M4-004] Avance maximale du départ livreur (« Je pars ») sur l'heure de sa tournée, minutes.
    // Au-delà : refus 422 « Départ possible à partir de HH:MM ». Un départ ferme la tournée de 30 min à toute
    // nouvelle commande : sans borne, la tournée du lendemain partait la veille (constat du 2026-10-22).
    // 45 = valeur prudente PROPOSÉE, À CONFIRMER PAR LE PROPRIÉTAIRE.
    'depart_max_advance_minutes' => 45,

    /*
     * [2026-10-23 · décision propriétaire] Durées de trajet ROUTIÈRES réelles (OSRM, service public sans clé).
     *  · client / site / prix : aller SIMPLE (restaurant → adresse) ;
     *  · gestion livreur (caisse, écran livreur) : tournée ALLER-RETOUR (restaurant → arrêts → restaurant).
     * Panne ou délai dépassé : repli sur l'estimation à vol d'oiseau × 1,3, et l'écran le dit (source = estimate).
     * `traffic_factor` : OSRM donne une durée « route dégagée » ; ×1,15 = circulation urbaine moyenne
     * (VALEUR PROPOSÉE, À CONFIRMER PAR LE PROPRIÉTAIRE après quelques vraies tournées). Le PRIX n'en dépend jamais.
     */
    'routing' => [
        'enabled'         => (bool) env('DELIVERY_ROUTING_ENABLED', true),
        'base_url'        => env('DELIVERY_ROUTING_URL', 'https://router.project-osrm.org'),
        'timeout_seconds' => (int) env('DELIVERY_ROUTING_TIMEOUT', 3),
        'cache_minutes'   => 360,
        'traffic_factor'  => (float) env('DELIVERY_ROUTING_TRAFFIC_FACTOR', 1.15),
    ],

    // Géocodage serveur : Base Adresse Nationale (service public, sans clé).
    'geocoder' => [
        // Ne jamais couper en production : sans géocodage serveur, les coordonnées du navigateur seraient crues.
        'enabled'         => (bool) env('DELIVERY_SERVER_GEOCODING', true),
        'base_url'        => env('DELIVERY_GEOCODER_URL', 'https://api-adresse.data.gouv.fr'),
        'timeout_seconds' => (int) env('DELIVERY_GEOCODER_TIMEOUT', 5),
        // Score BAN minimal (0..1) pour accepter une adresse comme « trouvée ».
        'min_score'       => (float) env('DELIVERY_GEOCODER_MIN_SCORE', 0.55),
        // Indice de proximité envoyé à la BAN (lat/lon) : sans lui, « 12 rue de la gare Oignies » a été comprise
        // comme une rue à 96 km. C'est un INDICE de classement, jamais une décision de prix (le prix vient de la
        // position finale et de la commune). Par défaut : l'adresse du restaurant (437 rue Élie Gruyelle).
        'bias_lat'        => (float) env('DELIVERY_GEOCODER_BIAS_LAT', 50.4215667),
        'bias_lon'        => (float) env('DELIVERY_GEOCODER_BIAS_LON', 2.9549060),
        'cache_minutes'   => 60 * 24,
    ],

    // Photo de preuve d'arrivée (disque local `public`, dossier relatif).
    'proof_photo' => [
        'directory' => 'delivery-proofs',
        'max_kb'    => 6144,
    ],
];
