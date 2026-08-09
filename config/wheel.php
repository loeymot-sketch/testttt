<?php

/*
|--------------------------------------------------------------------------
| Roue Le Cayenne — jeu de récupération des clients plateformes
|--------------------------------------------------------------------------
|
| OBJECTIF MÉTIER. Les plateformes prélèvent jusqu'à 35 % de commission. Un client
| qui commande en direct la fois suivante vaut donc bien plus qu'un client de
| plateforme. La roue paie un petit lot pour obtenir trois choses qui, elles,
| durent : un avis Google, un abonnement, et surtout un NUMÉRO — l'identité qui
| permet de reparler à ce client sans passer par un intermédiaire.
|
| CE QUI EST NON NÉGOCIABLE DANS CE FICHIER
|
| 1. `enabled` est FAUX par défaut. Tant que le propriétaire n'a pas validé, la
|    roue n'existe pas pour le public — seul un compte administrateur peut la
|    voir (voir `preview_role`). Un jeu à moitié fini ouvert au public, c'est de
|    l'argent distribué sans contrepartie.
|
| 2. LES POIDS VIVENT ICI, CÔTÉ SERVEUR, ET NULLE PART AILLEURS. Le navigateur ne
|    reçoit JAMAIS ni les poids ni la liste des lots avec leurs probabilités : il
|    reçoit le libellé des segments pour dessiner la roue, et le RÉSULTAT déjà
|    tranché. Une roue dont le navigateur choisit le segment se gagne avec les
|    outils de développement, en dix secondes.
|
| 3. `daily_cap` est un plafond DUR par lot et par jour. `max_uses_global` du
|    moteur de coupons protège un code, pas un budget : sans plafond ici, une
|    journée virale coûte un service entier.
|
| 4. La somme des poids n'a pas à faire 100 : le tirage normalise. Mettre un poids
|    à 0 retire le lot sans casser la roue (le segment reste dessiné mais n'est
|    jamais tiré — utile pour épuiser un stock sans redéployer).
*/

return [

    // Ouvre la roue au PUBLIC. Faux = seuls les comptes du rôle `preview_role`
    // y accèdent. C'est la porte que le propriétaire ouvrira lui-même.
    'enabled' => (bool) env('WHEEL_ENABLED', false),

    // Qui peut jouer pendant la mise au point.
    'preview_role' => env('WHEEL_PREVIEW_ROLE', 'Admin'),

    // CLÉ DE PRÉVISUALISATION. La page de la roue est servie par Vercel et l'API par le VPS :
    // deux domaines, donc aucun cookie de session n'accompagne l'appel. Le contrôle par rôle
    // ne peut donc pas fonctionner depuis cette page. Cette clé, passée en `?preview=…`,
    // permet au propriétaire de tester EN PRODUCTION sur le vrai matériel avant l'ouverture.
    // Elle ne protège PAS d'argent : gagner un tour exige toujours un jeton signé émis au
    // comptoir. Vide = désactivée (aucune clé par défaut : une clé par défaut serait publique).
    'preview_key' => (string) env('WHEEL_PREVIEW_KEY', ''),

    // Adresse PUBLIQUE de la page de la roue (site client). Sert à composer le QR affiché au
    // comptoir. Vide = pas de QR : on préfère ne rien afficher qu'un QR qui mène nulle part.
    'public_url' => rtrim((string) env('WHEEL_PUBLIC_URL', 'https://www.lecayenne.fr'), '/'),

    // Change de campagne = tout le monde peut rejouer une fois. C'est le seul
    // levier pour relancer le jeu sans vider la table des participations.
    'campaign_key' => env('WHEEL_CAMPAIGN', '2026-rentree'),

    // Validité du lot gagné. Assez court pour créer l'urgence, assez long pour
    // laisser revenir : le client type d'un fast-food revient sous 3 semaines.
    'prize_validity_days' => (int) env('WHEEL_PRIZE_DAYS', 30),

    /*
    | Segments. `weight` est relatif. `type` :
    |   · 'coupon_percent' → remise en % sur la commande suivante
    |   · 'coupon_fixed'   → remise en euros
    |   · 'free_item'      → produit offert (COÛT RÉEL : voir §décharge ci-dessous)
    |   · 'points'         → points de fidélité (le moins cher, le plus fidélisant)
    |
    | Le libellé est ce que le client LIT sur la roue. Il doit être vrai : une roue
    | qui affiche un lot jamais tiré est une tromperie, et elle se voit.
    */
    'segments' => [
        ['key' => 'points_50',   'label' => '50 points',        'type' => 'points',         'value' => 50,  'weight' => 30, 'daily_cap' => 0],
        ['key' => 'remise_10',   'label' => '-10%',             'type' => 'coupon_percent', 'value' => 10,  'weight' => 28, 'daily_cap' => 0],
        ['key' => 'points_100',  'label' => '100 points',       'type' => 'points',         'value' => 100, 'weight' => 18, 'daily_cap' => 0],
        ['key' => 'remise_15',   'label' => '-15%',             'type' => 'coupon_percent', 'value' => 15,  'weight' => 12, 'daily_cap' => 30],
        ['key' => 'boisson',     'label' => 'Boisson offerte',  'type' => 'free_item',      'value' => 0,   'weight' => 8,  'daily_cap' => 15],
        ['key' => 'frites',      'label' => 'Frites offertes',  'type' => 'free_item',      'value' => 0,   'weight' => 3,  'daily_cap' => 8],
        ['key' => 'menu',        'label' => 'Menu offert',      'type' => 'free_item',      'value' => 0,   'weight' => 1,  'daily_cap' => 2],
    ],

    /*
    | LE LOT NE SE RÉCUPÈRE QU'AVEC UNE COMMANDE — exigence propriétaire, et c'est
    | aussi ce qui rend le jeu rentable : on ne donne rien à qui ne revient pas.
    */
    'requires_order_to_claim' => true,

    /*
    | DÉCHARGE / COÛT RÉEL. Un produit offert n'est pas gratuit : il sort du stock
    | et doit apparaître dans les charges, sinon la marge affichée est fausse et
    | l'inventaire dérive. Chaque `free_item` consommé génère une sortie de stock
    | tracée, du même type que le module « repas & pertes » déjà en place.
    */
    'record_cost_on_claim' => true,
    'cost_outflow_reason'  => env('WHEEL_COST_REASON', 'offert_roue'),

    /*
    | DÉVERROUILLAGE — la partie qu'on ne peut PAS automatiser, et il faut le dire.
    |
    | Aucune API publique ne permet de vérifier qu'une personne précise a laissé un
    | avis Google ou s'est abonnée à un compte : Google n'expose pas les avis par
    | auteur, et les API Instagram/TikTok ne donnent pas la liste des abonnés d'un
    | compte tiers. Un bouton « j'ai mis mon avis » sur lequel on clique soi-même
    | ne vérifie RIEN et se rejoue à l'infini — c'est exactement ce que le
    | propriétaire refuse.
    |
    | Deux déverrouillages qui, eux, tiennent réellement :
    |
    |   · 'staff'  — le client montre son avis/abonnement à l'écran, l'équipe
    |                valide sur la caisse ou la tablette du comptoir. Un humain
    |                vérifie, un jeton signé à usage unique est émis. Infalsifiable
    |                et déjà compatible avec l'organisation du comptoir.
    |
    |   · 'order'  — une commande réelle, payée, ouvre un tour. Vérifiable à 100 %
    |                par le système, sans personne dans la boucle. C'est le mode
    |                pour le QR imprimé sur le ticket.
    |
    | Le mode 'declaratif' existe pour mémoire et reste DÉSACTIVÉ : il est là pour
    | qu'on se souvienne qu'il a été écarté sciemment, pas par oubli.
    */
    'unlock_methods' => [
        'staff' => (bool) env('WHEEL_UNLOCK_STAFF', true),
        'order' => (bool) env('WHEEL_UNLOCK_ORDER', true),
        'declaratif' => false,
    ],

    // Durée de vie d'un jeton de déverrouillage émis au comptoir. Court : il doit
    // être consommé devant l'équipe, pas transmis à des amis dans la soirée.
    'unlock_token_ttl_minutes' => (int) env('WHEEL_UNLOCK_TTL', 15),

    // Un tour par téléphone et par campagne. Le téléphone est déjà la clé
    // d'identité invité du système — pas de second annuaire à maintenir.
    'one_spin_per_phone' => true,

    // Garde-fou global : au-delà, la roue se ferme d'elle-même pour la journée et
    // le dit honnêtement au client. Mieux vaut « revenez demain » qu'un budget qui
    // part sans que personne ne regarde.
    'daily_total_cap' => (int) env('WHEEL_DAILY_TOTAL_CAP', 120),
];
