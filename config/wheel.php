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
    /*
    | [2026-08-12 · propriétaire] DES PRODUITS, PAS DES POINTS NI DES POURCENTAGES.
    |
    | Sa demande, mot pour mot : « des vrais produits à gagner — une boisson, une frite, un
    | cheeseburger, un Cayenne, une tarte, un tiramisu ; et un Terminator qu'on affiche mais que
    | personne ne gagne, parce que ça ferait mal à notre production : la probabilité, ça va être
    | zéro. Je veux pouvoir régler la probabilité ET le nombre de cadeaux. »
    |
    | Ce que ces valeurs sont, et ne sont pas :
    |   · `weight`   = la PROBABILITÉ relative. Un poids de 0 signifie « affiché, jamais tiré ».
    |   · `quantity` = le NOMBRE de cadeaux pour la campagne (le « mois » du propriétaire). Épuisé,
    |                  le lot disparaît de la roue — il n'est plus ni tiré, ni montré.
    |   · `daily_cap`= un frein JOURNALIER de sécurité, pour qu'un lot ne parte pas en une heure.
    |
    | CES CHIFFRES NE SONT QU'UN POINT DE DÉPART. L'exploitant les règle depuis `/admin/roue-reglages`
    | sans déploiement ; les réglages enregistrés PRIMENT sur ce fichier
    | (`WheelSettingsService::prizeOverrides()`).
    |
    | Tous les produits cités existent en base — vérifié : Boisson Seule (3), Frites Seules (2),
    | Cheese Burger (98), Cayenne (22), Tarte Daim (50), Tiramisu (51), Terminator (105). ⛔ Ne
    | JAMAIS inventer un produit : s'il n'est pas dans la carte, il n'existe pas (CLAUDE.md §3bis).
    |
    | UNE RÉSERVE QUE JE DOIS AU PROPRIÉTAIRE, et qu'il a tranchée : afficher un lot dont la
    | probabilité est nulle reste une tromperie si elle est définitive — un habitué finit par
    | remarquer que le Terminator ne tombe jamais. Ici ce n'est PAS gravé dans le code : c'est un
    | curseur sur SON écran, qu'il peut lever un soir de lancement. C'est ce qui rend la chose
    | tenable plutôt que mensongère.
    */
    'segments' => [
        // Les deux lots les plus probables sont les moins coûteux (1,90 € pièce).
        ['key' => 'boisson',     'label' => 'Boisson',          'type' => 'free_item',      'value' => 0,   'weight' => 34, 'daily_cap' => 20, 'quantity' => 50,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_BOISSON', 3), 'cost_item_name' => 'Boisson Seule'],
        ['key' => 'frites',      'label' => 'Frites',           'type' => 'free_item',      'value' => 0,   'weight' => 30, 'daily_cap' => 20, 'quantity' => 50,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_FRITES', 2),  'cost_item_name' => 'Frites Seules'],
        // Desserts : coût moyen (3,50 €), forte valeur perçue.
        ['key' => 'tiramisu',    'label' => 'Tiramisu',         'type' => 'free_item',      'value' => 0,   'weight' => 14, 'daily_cap' => 10, 'quantity' => 50,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_TIRAMISU', 51), 'cost_item_name' => 'Tiramisu'],
        ['key' => 'tarte',       'label' => 'Tarte Daim',       'type' => 'free_item',      'value' => 0,   'weight' => 12, 'daily_cap' => 10, 'quantity' => 50,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_TARTE', 50),    'cost_item_name' => 'Tarte Daim'],
        // Sandwichs : les lots chers. Le propriétaire a dit « 10 sandwiches, 10 burgers ».
        ['key' => 'cheeseburger', 'label' => 'Cheese Burger',   'type' => 'free_item',      'value' => 0,   'weight' => 6,  'daily_cap' => 3,  'quantity' => 10,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_CHEESE', 98),  'cost_item_name' => 'Cheese Burger'],
        ['key' => 'cayenne',     'label' => 'Cayenne',          'type' => 'free_item',      'value' => 0,   'weight' => 4,  'daily_cap' => 2,  'quantity' => 10,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_CAYENNE', 22),  'cost_item_name' => 'Cayenne'],
        // LA VITRINE. Poids 0 = affiché, jamais tiré. Curseur sur l'écran du propriétaire.
        ['key' => 'terminator',  'label' => 'Terminator',       'type' => 'free_item',      'value' => 0,   'weight' => 0,  'daily_cap' => 1,  'quantity' => 1,   'cost_item_id' => (int) env('WHEEL_COST_ITEM_TERMINATOR', 105), 'cost_item_name' => 'Terminator'],
    ],

    /*
    | Ancienne liste (points + pourcentages), gardée pour mémoire : le propriétaire a demandé des
    | produits réels, plus lisibles sur une roue et plus désirables qu'un « -10 % ». Elle reste
    | reconstituable si besoin — les types `points` et `coupon_percent` sont toujours pris en charge
    | par le moteur, et l'écran de réglages ne les supprime pas.
    |
    |   points_50 (30) · remise_10 (28, plafond 4 €) · points_100 (18) ·
    |   remise_15 (12, plafond 6 €) · boisson (8) · frites (3) · menu (1)
    */
    'segments_heritage' => [
        ['key' => 'points_50',   'label' => '50 points',        'type' => 'points',         'value' => 50,  'weight' => 30, 'daily_cap' => 0],
        // `max_discount` : PLAFOND EN EUROS. Sans lui, `-15 %` sur une commande de groupe de 250 €
        // fait 37,50 € offerts par un jeu censé donner « un petit lot ». Le moteur de coupons
        // n'applique le plafond que s'il est > 0 (`CouponService`), et la colonne vaut 0 par
        // défaut : ne pas le renseigner, c'est un pourcentage SANS LIMITE.
        ['key' => 'remise_10',   'label' => '-10%',             'type' => 'coupon_percent', 'value' => 10,  'weight' => 28, 'daily_cap' => 0,  'max_discount' => (float) env('WHEEL_MAX_DISCOUNT_10', 4.0)],
        ['key' => 'points_100',  'label' => '100 points',       'type' => 'points',         'value' => 100, 'weight' => 18, 'daily_cap' => 0],
        ['key' => 'remise_15',   'label' => '-15%',             'type' => 'coupon_percent', 'value' => 15,  'weight' => 12, 'daily_cap' => 30, 'max_discount' => (float) env('WHEEL_MAX_DISCOUNT_15', 6.0)],
        // `cost_item_id` : produit de RÉFÉRENCE pour la charge (voir §DÉCHARGE ci-dessous).
        // NUL = pas encore choisi → le cadeau n'est pas chiffré et la réconciliation le SIGNALE.
        // `cost_item_name` : motif de REPLI, cherché dans la carte si aucun identifiant n'est réglé.
        // Il évite qu'un réglage oublié laisse un cadeau non chiffré — un trou comptable ne doit pas
        // dépendre d'une variable d'environnement que quelqu'un a pensé à poser.
        ['key' => 'boisson',     'label' => 'Boisson offerte',  'type' => 'free_item',      'value' => 0,   'weight' => 8,  'daily_cap' => 15, 'cost_item_id' => (int) env('WHEEL_COST_ITEM_BOISSON', 0), 'cost_item_name' => 'Boisson Seule'],
        ['key' => 'frites',      'label' => 'Frites offertes',  'type' => 'free_item',      'value' => 0,   'weight' => 3,  'daily_cap' => 8,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_FRITES', 0),  'cost_item_name' => 'Frites Seules'],
        // Libellé RENOMMÉ : « Menu offert » désignait en réalité le supplément « Frites + Boisson »
        // à 2,50 €. Un client qui lit « Menu offert » et reçoit des frites et une boisson se sent
        // floué — et il a raison. On nomme ce qu'on donne.
        ['key' => 'menu',        'label' => 'Frites + Boisson', 'type' => 'free_item',      'value' => 0,   'weight' => 1,  'daily_cap' => 2,  'cost_item_id' => (int) env('WHEEL_COST_ITEM_MENU', 0),    'cost_item_name' => 'Menu (Frites + Boisson)'],
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
    |
    | POURQUOI UN `cost_item_id` PAR SEGMENT, ET NON UNE SORTIE « SANS PRODUIT ».
    | La table `stock_outflows` exige un produit (`item_id` NOT NULL) : elle est née pour les repas
    | du personnel et les pertes, où l'on nomme toujours ce qui sort. La roue, elle, promet « une
    | boisson » — l'équipe sert ce qu'elle veut au retrait.
    | Trois réponses possibles, deux mauvaises :
    |   · assouplir la colonne → exige une dépendance absente, et sous SQLite une reconstruction de
    |     table à déclencheurs. Trop de risque pour une écriture comptable ;
    |   · choisir un produit tout seul (le premier de la catégorie) → on inscrirait dans les charges
    |     un produit que PERSONNE n'a choisi, et l'inventaire de ce produit dériverait ;
    |   · demander à l'exploitant quel produit sert de RÉFÉRENCE DE COÛT. C'est la bonne : ce n'est
    |     pas une donnée inventée, c'est une décision de gestion, et lui seul peut la prendre.
    | Tant qu'un segment n'a pas son `cost_item_id`, son cadeau n'est PAS chiffré — et la
    | réconciliation le dit à chaque passage, plutôt que de laisser un trou silencieux.
    */
    'record_cost_on_claim' => true,

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

    /*
    |--------------------------------------------------------------------------
    | LE PARCOURS EN ÉTAPES — arbitré par le propriétaire le 2026-08-09
    |--------------------------------------------------------------------------
    |
    | RÈGLE D'ERGONOMIE QUI COMMANDE TOUT : on n'annonce JAMAIS « 3 étapes » au
    | départ. Un client debout au comptoir qui lit « 3 étapes » repose son
    | téléphone. On révèle une étape à la fois — « une dernière petite étape » —
    | parce que l'engagement déjà consenti pousse à finir, alors qu'un parcours
    | annoncé long décourage avant de commencer.
    |
    | Ce qu'on peut vérifier, et ce qu'on ne peut pas : AUCUNE API ne dit qu'une
    | personne précise a écrit un avis ou s'est abonnée. On mesure donc ce qui est
    | mesurable — le lien a été ouvert, le temps passé — et on contrôle le RESTE
    | globalement (nombre d'abonnés avant/après). Le client, lui, n'en sait rien :
    | le propriétaire ne veut surtout pas l'alourdir avec ça.
    */

    'steps' => [
        'review' => [
            // FAUX = l'avis devient une simple INVITATION, sans conditionner le lot.
            // À basculer si l'on veut se conformer strictement à la politique Google, qui
            // interdit de récompenser un avis. Un seul réglage, aucun redéploiement.
            'required' => (bool) env('WHEEL_STEP_REVIEW_REQUIRED', true),
            'url' => env('WHEEL_REVIEW_URL', ''),
            // Temps de rédaction avant que le bouton se débloque. 20 s : assez pour écrire une
            // phrase, trop court pour être vécu comme une attente. En dessous, le geste n'a pas eu
            // lieu ; au-dessus, on perd des gens.
            'dwell_seconds' => (int) env('WHEEL_REVIEW_DWELL', 20),
            // REPLI : sans lien collé, on en dérive un depuis le nom et l'adresse du restaurant
            // (recherche Google Maps). Un appui de plus pour le client, mais ça FONCTIONNE tout de
            // suite au lieu d'attendre que quelqu'un colle le lien court.
            'derive_fallback' => (bool) env('WHEEL_REVIEW_DERIVE', true),
        ],
        'follow' => [
            'required' => (bool) env('WHEEL_STEP_FOLLOW_REQUIRED', true),
            'instagram' => env('WHEEL_INSTAGRAM_URL', ''),
            'snapchat' => env('WHEEL_SNAPCHAT_URL', ''),
            // Facebook : l'adresse figure DÉJÀ dans le site du restaurant, c'est donc une donnée
            // vérifiée et non une supposition. Elle rend l'étape « abonnement » utilisable tout de
            // suite, sans attendre les deux autres comptes.
            'facebook' => env('WHEEL_FACEBOOK_URL', 'https://www.facebook.com/LeCayenne'),
            // Plus court : s'abonner prend un geste, pas une rédaction.
            'dwell_seconds' => (int) env('WHEEL_FOLLOW_DWELL', 8),
        ],
    ],

    /*
    | CONDITIONS D'UTILISATION DU LOT — « ils peuvent récupérer ça que avec une
    | commande », plus un minimum d'achat. C'est ce qui rend le jeu rentable : on ne
    | donne rien à qui ne revient pas, et une boisson offerte sur une commande de
    | 10 € reste largement bénéficiaire. C'est aussi le garde-fou contre celui qui
    | viendrait chercher un cadeau sans rien acheter.
    */
    'min_order_amount' => (float) env('WHEEL_MIN_ORDER', 10.0),

    /*
    | Délai pour réclamer un lot qu'on vient de gagner (donner son numéro et son adresse). Assez
    | large pour taper deux champs sans stress, assez court pour qu'un lot laissé en attente ne
    | devienne pas réclamable des heures plus tard, hors de tout plafond journalier — et de toute
    | façon le client aurait oublié.
    */
    'claim_window_minutes' => (int) env('WHEEL_CLAIM_WINDOW', 30),

    /*
    |--------------------------------------------------------------------------
    | ACCÈS AUX ÉCRANS DE LA ROUE (code de la maison)
    |--------------------------------------------------------------------------
    | [P0 2026-08-10] Ces écrans étaient gardés par `auth`, donc INACCESSIBLES : la connexion de la
    | caisse détruit la session web et rend un jeton Bearer, qu'une navigation de document ne porte
    | jamais. Personne ne pouvait ouvrir l'écran de réglages — celui qui existe pour débloquer le jeu.
    |
    | On réemploie le modèle déjà éprouvé du Carnet (`/carnet`) et du Stock mobile (`/m`) : un code
    | posé une fois sur la machine, une session glissante. Même besoin, même public, même contrainte.
    |
    | Vide = accès REFUSÉ (fail-closed). Ces écrans distribuent des lots : une porte ouverte par
    | défaut serait pire que la porte fermée qu'on répare.
    */
    /*
    | La caisse à laquelle rattacher les gestes faits depuis le code de la maison (pas
    | d'utilisateur, donc pas de `branch_id` de compte). V1 LOCAL Le Cayenne = une seule caisse.
    */
    'counter_branch_id' => (int) env('WHEEL_COUNTER_BRANCH', 1),

    'access' => [
        'pin' => (string) env('WHEEL_PIN', ''),
        'session_minutes' => (int) env('WHEEL_SESSION_MINUTES', 240),
    ],

    /*
    | L'E-MAIL DES CONDITIONS. Le lot est expliqué par écrit : ce qu'il a gagné, le
    | minimum d'achat, la date limite, et le fait qu'il se retire au comptoir. Un
    | client qui découvre une condition AU MOMENT de la retirer se sent piégé — et il
    | a raison. Tout doit être dit avant.
    */
    'notify_by_email' => (bool) env('WHEEL_NOTIFY_EMAIL', true),

    /*
    | CONTRÔLE DE COHÉRENCE, invisible du client. Le nombre d'abonnés est un TOTAL :
    | il ne prouve rien individuellement, et il baisse quand quelqu'un d'autre se
    | désabonne. Mais sur une journée, l'écart entre « tours accordés » et « abonnés
    | gagnés » dit la vérité. Instagram expose ce nombre pour SON PROPRE compte pro ;
    | Snapchat n'a aucune API publique équivalente — on ne prétendra donc pas le
    | mesurer.
    */
    'followers' => [
        'instagram_account_id' => env('WHEEL_IG_ACCOUNT_ID', ''),
        'instagram_token' => env('WHEEL_IG_TOKEN', ''),
    ],

    // Garde-fou global : au-delà, la roue se ferme d'elle-même pour la journée et
    // le dit honnêtement au client. Mieux vaut « revenez demain » qu'un budget qui
    // part sans que personne ne regarde.
    'daily_total_cap' => (int) env('WHEEL_DAILY_TOTAL_CAP', 120),
];
