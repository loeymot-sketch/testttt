# Livraison propre du Cayenne — mode d'emploi et contrat

> Mis en place le 2026-10-20 (décision propriétaire). Lire avant de toucher au barème, aux tournées ou à l'écran livreur.

## 1. Les règles (décidées par le propriétaire)

| Adresse du client | Frais |
|---|---|
| Toute adresse de **Hénin-Beaumont** (commune du restaurant) | **3,90 €** forfait unique |
| Autre commune, rayon ≤ 5 km | 4,90 € |
| Autre commune, rayon ≤ 8 km | 5,90 € |
| Autre commune, rayon ≤ 10 km | 6,90 € |
| Au-delà de 10 km | **refusé** → on propose Uber Eats |

- Distance = **à vol d'oiseau** depuis le restaurant (haversine). Bornes **inclusives** (5,00 km = 4,90 € ; 5,01 km = 5,90 €).
- **Une seule source de vérité** : `config/delivery.php` → `App\Services\Delivery\DeliveryTariff`. Le miroir d'affichage
  `resources/js/helpers/deliveryCharge.js` est gardé par `DeliveryTariffMirrorSentinelTest` (échoue s'il diverge).
- Les colonnes `branches.delivery_fee_*` restent en base mais **ne pilotent plus le prix**.
- Le prix est **toujours recalculé par le serveur depuis l'adresse enregistrée** (web : adresse du client connecté ;
  caisse : adresse du `customer_id`). Distance et montant envoyés par un écran sont ignorés.

## 2. Tournées de 30 minutes

- Une **tournée** = un départ du restaurant sur la grille :00 / :30 ; un livreur, un trajet, plusieurs arrêts.
- Premier départ possible = maintenant + `min_lead_minutes` (20), arrondi au cran de grille supérieur, dans la fenêtre
  de service (`kds.scheduled_window_*`, 18:00 → 00:30).
- Commandes dans la même fenêtre → **même départ**. Plus d'une fenêtre d'écart → **deux départs**.
- **Regroupement tardif** (exemple du propriétaire : 2ᵉ commande 25 min après la 1ʳᵉ) : si la 2ᵉ ne peut plus prendre
  le départ de la 1ʳᵉ, elle le **décale d'un cran** pour partir avec elle — sauf si le livreur est déjà parti, si la
  tournée est pleine (`max_stops_per_wave`), ou si un client a **choisi lui-même** son créneau.
  ⚠️ Conséquence assumée : l'heure estimée de la 1ʳᵉ commande peut reculer de 30 min. C'est la règle demandée.
- Un client peut **choisir son créneau** (`delivery_slot_at`), validé **avant paiement** (grille, horaires, horizon, tournée non pleine).
- L'ordre de passage est le plus proche voisin depuis le restaurant. L'ETA d'**arrivée** par arrêt (ce que voit le client) est
  un **aller simple**, calculé à la création de la commande SANS appel réseau : itinéraire routier déjà en mémoire (§7) s'il existe, sinon l'estimation historique
  (vitesse `average_speed_kmh` × 1,3 de détour + marge). **Le temps réel en conduite est celui de Waze**, ouvert depuis l'écran livreur.
- Tout vit dans `delivery_stops` (jamais dans `OrderStateMachine`, gelée). Écouteur : `AssignDeliveryWaveOnOrderCreated`.
  Filet : `php artisan delivery:assign-pending` (toutes les 5 min).

## 3. Qui fait quoi

- **Caisse** (`/admin/pos`) : bouton **« Livraison active — suspendre / remettre »** (deux appuis), bouton **Tournées**
  (tournées du jour, choix du livreur s'il y en a plusieurs), téléphone → client retrouvé + ses adresses enregistrées,
  adresse choisie dans la liste serveur → devis. Routes `GET/POST /api/admin/pos-delivery/*`, permission `pos`.
  **« Commande livraison »** (2026-10-23) : en mode livraison, ce bouton remplace « Commande téléphone ». Il enregistre la livraison
  (adresse + téléphone obligatoire) et l'envoie en cuisine **sans encaisser** : le livreur encaisse à la porte, et la remise scelle
  la vente (numéro fiscal à ce moment-là, `deliveryBoyOrderChangeStatus`). Marqueur serveur **`delivery_door_payment`** (livraison
  seulement) → commande différée (COUNTER_DEFERRED + PENDING_COUNTER, pas de numéro fiscal à la création) tagguée
  `source_surface = 'delivery'`. ⛔ Jamais `phone_order` en livraison : `source_surface='phone'` tombe dans la file « À encaisser au
  comptoir » et la purge « client non venu » l'annulerait (refusé 422, `PhoneOrderDeliveryRefusedTest`). Le mode d'encaissement
  à la porte reste noté **espèces** (limite connue plus bas). Une telle commande ne s'encaisse **qu'à la remise par le livreur** : elle n'entre pas
  dans la file « À encaisser au comptoir » (`CounterCollectQueue`, `assertCounterDeferredOrder`) et n'est pas confiable à Uber (« à encaisser »).
  La purge d'abandon (6 h, `CleanupStalePendingKioskOrders`) attend le **départ prévu** de la tournée, pas l'heure de saisie. Tests : `DeliveryDoorPaymentOrderTest`, `posCommandeLivraison.spec.js`.
- **Site / application** : `GET /api/frontend/delivery/status|suggest|waves`, `POST /api/frontend/delivery/quote`.
  La livraison n'apparaît que si le **serveur** dit « active ». Adresse enregistrée **une seule fois** par client.
- **Livreur** : page `/livreur` (téléphone), API `/api/frontend/delivery-run`. Départ → arrivée (**photo**, position) →
  remise (encaissement). La remise passe par `OrderService::deliveryBoyOrderChangeStatus` : fiscal NF525, audit et
  notifications **inchangés**. Photo = disque **privé**, visible du livreur concerné et de la caisse.
- **Un seul livreur actif** sur la branche → il est rattaché automatiquement. **Plusieurs** → personne n'est choisi en silence, la caisse désigne.

## 3bis. Garde-fous ajoutés après relecture adverse (2026-10-20)

- **Commande de table (QR, publique)** : ne peut plus être une livraison, et son `delivery_charge` est forcé à 0 (elle lisait le
  montant du client sans adresse : livraison gratuite forgeable). Test : `TableOrderNoDeliveryForgeryTest`.
- **Anciennes adresses** (saisies avant le géocodage serveur : ni commune ni code postal, coordonnées venues du navigateur) :
  **refusées**, jamais facturées — le client ou le caissier doit la ressaisir. À la caisse la pastille affiche « à ressaisir ».
- **Adresse enregistrée = adresse comprise par le serveur** (libellé BAN, complément séparé dans `apartment`) : le point facturé est
  exactement celui que reçoit le livreur. Une simple commune n'est pas une adresse (« Hénin-Beaumont » seul est refusé).
- **Caisse** : le client « de passage » (compte partagé) ne reçoit jamais une adresse de livraison ; changer le numéro détache
  le client, l'adresse et le prix précédents ; le serveur refuse aussi les comptes de l'équipe.
- **Annulation** : une commande annulée ou refusée libère sa place dans la tournée (sauf si le livreur est déjà parti).
  `delivery:assign-pending` n'assigne pas une commande carte en ligne non payée et nettoie les arrêts de commandes fermées.
- **Livreur** : le départ vérifie d'abord que TOUTES les commandes lui sont attribuées (aucun départ à moitié) ; le montant à
  encaisser est relu en direct (un paiement en ligne tardif ne laisse plus « Encaisser par carte ») ; la désignation par l'ancien
  écran (`select-delivery-boy`) met aussi l'arrêt à jour ; le livreur est prévenu (mail / SMS / push existants) quand la caisse
  ou l'attribution automatique le désigne ; photo : JPEG / PNG / WebP / HEIC seulement (pas de SVG).
- **Idempotence** : départ / arrivée / remise sont dans `idempotency.required_routes` (l'écran livreur envoie déjà la clé) ;
  les routes `pos-delivery/*` sont naturellement rejouables et n'en portent pas.

## 3ter. Trouvé par le navigateur réel (2026-10-20, Playwright, `tests/Playwright/livraison-caisse.spec.js`)

- **Polygone `branches.zone`** : il refusait l'adresse du restaurant elle-même (polygone de test sur Paris), et même le polygone du
  seeder (boîte ≈ 6 × 5 km) aurait refusé Oignies, pourtant dans le rayon de 10 km. Il datait d'avant le géocodage serveur.
  **Désactivé par défaut** (`delivery.use_branch_zone_polygon`) : la zone de livraison, c'est le rayon.
- **Mise en page du ticket de caisse** : en mode livraison avec un article, le formulaire d'adresse se peignait PAR-DESSUS le
  total et le bouton Commande (défaut déjà documenté dans `pos-v5.css` comme « limite connue », invisible tant que la livraison
  était coupée). Corrigé à la racine : liste de suggestions dans le flux, exception CSS supprimée, l'en-tête défile.
  Prouvé par contact (`elementFromPoint`) à 1280×720, 1440×900 et 1920×1080.
- **Quota de la caisse** : elle appelait les routes publiques du site, dont la limite (par adresse IP) est partagée par tout le
  restaurant (« Too Many Attempts » en plein service). Elle a maintenant ses routes authentifiées
  `GET /api/admin/pos-delivery/suggest` et `POST /api/admin/pos-delivery/quote`, limitées par utilisateur.

## 3quater. Trouvé par le test réel de bout en bout (`tests/Playwright/livraison-parcours-complet.spec.js`)

Une vraie livraison traverse les trois surfaces : commande web par l'API (comme le site) → cuisine → la caisse désigne le livreur
(navigateur) → le livreur part, arrive avec une vraie photo, remet et encaisse (navigateur) → relecture de la base.
- **Vente livraison hors chaîne NF525 (défaut ANTÉRIEUR, corrigé)** : une commande de livraison créée par l'API du site porte
  `source_surface = 'delivery'` ; `OrderService::deliveryBoyOrderChangeStatus` ne reconnaissait que `'web'`. Résultat : livrée et
  encaissée à la porte, la commande restait `PENDING_COUNTER`, **sans numéro fiscal**. Le test existant fixait `'web'` à la main,
  ce qui masquait la réalité. Même ensemble que `OnlineOrderController` désormais ; test de non-régression avec la vraie valeur.
- **Tournée déjà partie** : un livreur peut partir en avance ; une commande dont le premier départ possible tombait sur ce même
  créneau rejoignait la tournée partie (injoignable). Une tournée partie est désormais fermée : plus de commande, plus de créneau
  proposé ni accepté.

## 3quinquies. Trouvé par la supervision E2E adversariale (2026-10-21, `reports/test-e2e/livraison-geo-2026-10-21/`)

- **P0 — titre-restaurant forgé sur une livraison** : `payment_method=5` était accepté par `OrderRequest` ; au départ du livreur, le flip
  historique « non-espèces → PAYÉ » de `OrderService::deliveryBoyOrderChangeStatus` scellait la vente (numéro fiscal) **sans aucun
  paiement** (reproduit : commandes 7934 et 7952). Le site n'envoie que 1 (espèces) ou 4 (carte en ligne) ; seule une requête forgée
  l'atteignait. Refusé (422) à la prise de commande le 2026-10-21 — **refus LEVÉ le 2026-10-22 (VG-2026-10-21 L2, décision du
  propriétaire : titre-restaurant accepté partout, y compris en livraison)**, une fois la cause corrigée :
  - `deliveryBoyOrderChangeStatus` traite le titre-restaurant **comme les espèces** : jamais PAYÉ au départ ; PAYÉ et scellé
    (numéro fiscal) **à la remise**, avec une ligne `order_payments` (mode `PosPaymentMethod::TICKET_RESTAURANT`, montant = total
    serveur) dans la même transaction et une trace d'audit `order.payment_tranche_persisted` (pas d'escrow espèces).
  - L'écran livreur affiche **« Encaisser en titre-restaurant »** et le montant (mode d'arrêt `meal_voucher`), avant et après le départ.
  - Une livraison prise en caisse et déjà payée en titre-restaurant au comptoir n'est pas touchée (aucune 2ᵉ tranche).
  - Tests : `DeliveryMealVoucherDoorstepTest` (de bout en bout depuis l'API), `DeliveryFeeForgeWebTest` (accepté, prix serveur).
  - ⚠️ Restes : le **site n'envoie jamais** le titre-restaurant (`funnel.jsx` : carte en ligne ou comptoir) — à ajouter côté site ;
    la planche « Tournées » de la caisse (`PosDeliveryBoard.vue`) affiche « Espèces » pour un arrêt `meal_voucher` ; une livraison
    caisse « à encaisser à la porte » est toujours notée espèces (`OrderService::posOrderStore` force `CASH_ON_DELIVERY`) ;
    le flip historique des autres modes non-espèces (carte, portefeuille) au départ est inchangé.
    **Mis à jour le 2026-10-24 :** le titre-restaurant reste à ajouter côté site ; la **carte à la livraison** est faite (voir
    « 3sexies. Carte à la livraison » ci-dessous). Le flip historique d'une commande `payment_method=4` (carte EN LIGNE) restée
    NON payée, basculée PAYÉE au départ du livreur sans aucun paiement, **reste ouvert** : le site ne l'atteint pas (une carte en ligne
    n'est livrée qu'après paiement Mollie), seule une commande carte jamais payée ou une requête forgée y arrive — à traiter
    avant d'exposer un autre canal « carte non payée » (`OrderService::deliveryBoyOrderChangeStatus`, branche `else` « Non-COD »).
- **P1 — compteurs de débit partagés** : des `throttle:N,M` nus signent la requête par (domaine|IP) — ou par utilisateur — **sans la
  route** : toutes les routes invitées d'une même IP partagent UN compteur. 21 appels à `/status` faisaient répondre 429 au devis
  (limite 20) ; côté caisse, 30 suggestions bloquaient la bascule et l'enregistrement d'adresse. Chaque route a maintenant son limiteur
  nommé (`delivery-*`, `pos-delivery-*` dans `RouteServiceProvider`). Tests : `DeliveryPublicThrottleIsolationTest`, `PosDeliveryControllerTest`.
  ⚠️ Toute NOUVELLE route de livraison doit recevoir son propre limiteur nommé, jamais un `throttle:N,M` nu.
- **Adresse** : quand la BAN ne connaît pas le numéro (« 1 rue Jean Jaurès 62590 Oignies » revenait « Rue Jean Jaurès »), le numéro tapé est
  **conservé dans le libellé** envoyé au livreur (`AddressGeocoder::keepTypedHouseNumber`) ; le point et le prix restent ceux de la rue.
  ⚠️ Connu et non traité : la BAN peut substituer une autre rue de la même commune, ou comprendre une autre commune si la saisie n'a ni code
  postal ni numéro — le libellé compris est toujours affiché au client et au caissier, jamais caché.
- **Caisse / livreur (relecture visuelle adversariale)** : le complément d'adresse (étage, interphone) restait inaccessible une fois l'adresse choisie ;
  un texte illisible ne donnait aucun message ; le refus hors zone se soldait par « Choisissez l'adresse dans la liste » ; le devis se retrouvait
  rogné sous l'en-tête défilant du ticket ; sur l'écran livreur, une remise terminée réclamait encore l'encaissement et « commande(s) pas prête(s) »
  s'effaçait aussitôt après « Je pars ». Tous corrigés, mesurés dans `tests/Playwright/livraison-supervisor-captures.spec.js`.
- **Décision propriétaire en attente (zone gelée `PricingService`)** : le Z fiscal applique déjà 10 % de TVA sur les frais de livraison
  (`ZReportService`, décision du 2026-07-07), mais le **ticket** ne montre qu'une TVA sur les articles (0,09 € pour 1,00 € + 3,90 € de frais).
  Afficher la TVA des frais sur le ticket demande de toucher `PricingService` : à trancher.
- **À vérifier en production avant d'ouvrir** : `Settings delivery.free_delivery_above` doit valoir **0** (le code retombe sur 30 € s'il
  est absent ; la migration `2026_07_27_093000` le pose à 0) ; `users.branch_id` du livreur doit être > 0 (sinon la garde d'idempotence
  répond 422 au départ/arrivée/remise).

## 3sexies. Carte à la livraison (2026-10-24, décision propriétaire)

Trois façons de payer une livraison commandée sur le site : **(1) en ligne** (Apple Pay / carte, `payment_method=4`, Mollie, inchangé),
**(2) carte à la livraison** (nouveau), **(3) espèces à la livraison** (`payment_method=1`, inchangé).

**Contrat (le site est codé contre lui).**
- Requête `POST /api/frontend/order` : `payment_method=1` **+** `door_payment='card'`, `order_type=5`. L'absence du champ = espèces.
- `OrderRequest` refuse (422, clé `door_payment`, message français) : `door_payment` avec un `order_type` autre que livraison, avec un
  `payment_method` autre que 1, ou avec une valeur autre que `card` (anti-forgeage ; même famille que le titre-restaurant forgé du 2026-10-21).
  `FrontendOrderService` re-vérifie avant d'écrire (jamais posé par la borne, la caisse ou un autre type).
- Colonne `orders.door_payment_mode` (`string(12)` nullable, migration `2026_10_24_100000`) : `'card'` ou `NULL`. Exposée par
  `GET /api/frontend/order/show/{id}` (`OrderDetailsResource`) sous la clé `door_payment_mode`.
- Le devis public (`POST /api/frontend/delivery/quote`) renvoie aussi `prep_minutes_low` / `prep_minutes_high`
  (`config/delivery.php` → `prep_minutes`, 15 et 20 : « Préparation 15 à 20 min, puis livraison selon le trajet »).
  Invariant testé : `high <= min_lead_minutes` (le premier départ proposé ne peut pas promettre moins que la préparation annoncée).

**Ce que fait le livreur.** L'arrêt porte le mode `card` et le montant total (« Encaisser par carte »), avant comme après le départ.
À la porte il fait payer le client **sur son terminal de paiement**, puis touche « Remis » (`POST …/stops/{id}/deliver`).

**Ce que fait le logiciel.** Comme pour les espèces et le titre-restaurant (`OrderService::deliveryBoyOrderChangeStatus`) : **jamais PAYÉ
au départ** ; à la REMISE : `payment_status=PAID`, numéro fiscal NF525 (une seule fois), `pos_payment_method=CARD`, une ligne
`order_payments` (mode CARD, montant = total serveur, `reference='livraison'`) et une trace d'audit `order.payment_tranche_persisted`
(événement `doorstep_card_collection`). **Aucun** escrow espèces, aucun mouvement de tiroir ni de caisse livreur. Le Z du jour range la vente
en **Carte** (clé `2`), jamais en espèces (`ZLivraisonCarteALaPorteTest`).

**La limite honnête.** L'encaissement physique se fait sur le terminal de paiement du livreur, **hors de notre système** ; nous enregistrons
le mode et le montant à la remise, comme pour les espèces — **aucun TPE n'est piloté par le logiciel**. Le logiciel ne peut donc pas
prouver que la carte a réellement été débitée : c'est la confirmation du livreur (« Remis ») qui scelle la vente. Le rapprochement avec le
relevé du terminal reste manuel.

Tests : `tests/Feature/Delivery/DeliveryCardAtDoorTest.php` (forgeages, chemin complet par l'API publique, régression espèces, carte en ligne
inchangée, exposition au site, devis), `tests/Feature/Fiscal/ZLivraisonCarteALaPorteTest.php` (ventilation du Z).

## 4. Lancer la livraison (rien n'est publié tant que ce n'est pas fait)

1. Déployer le **backend** (migrations `2026_10_20_100000` et `2026_10_20_100100` : additives) — voir la mémoire
   `deploiement-controles-avant-le-reset` (fetch, ancêtre, sauvegarde par la connexion de l'application).
2. Vérifier que le compte livreur existe (rôle `Delivery Boy`) et que `branches.latitude/longitude` du restaurant sont justes.
3. **Site** : déployer `Site lecayenne` (compilé + `?v=` déjà bumpés). La livraison reste **invisible** tant que le serveur
   dit « suspendue » (état actuel : `order_setup_delivery = DISABLE`).
4. Dans la caisse : **remettre** la livraison. Elle apparaît partout, sans redéploiement. La suspendre la retire.
5. Avant d'ouvrir au public, relire la copie « 100 % à emporter / pas de livraison » du site (pages SEO, `llms.txt`,
   `livraison-henin-beaumont.html`) : elle contredirait l'offre.
6. Application des stores : `app/www` se périme à chaque correctif du site (`npm run check:www` puis `build:www`).

## 5. Ce qui n'est PAS fait (dit franchement)

- **Vérification du téléphone** : aucune passerelle SMS n'est branchée ; le numéro est exigé et normalisé, jamais prouvé.
- **Vérification de l'e-mail à la caisse** : l'état « vérifié / non vérifié » est affiché ; la preuve se fait à la première
  connexion du client par code (e-mail). Le site, lui, vérifie déjà l'e-mail par code.
- **Waze en temps réel dans notre écran** : impossible sans leur SDK ; on ouvre Waze (lien profond) qui affiche le temps réel.
- **Notification du livreur** : réutilise les événements existants (mail / SMS / push) ; sans passerelle SMS ou push configurée, il voit sa liste (rafraîchie toutes les 30 s).
- **Paiement par carte à la porte** : le montant et le mode sont affichés au livreur ; l'encaissement carte physique n'est pas câblé (TPE).

## 6. Tests

```
vendor/bin/phpunit --filter 'Delivery|AddressGeocoder|AddressServerGeocoding|PosDeliveryController'
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/livraison-caisse.spec.js --workers=1 --retries=0
# (navigateur réel : caisse OUVERTE, réseau ouvert — la BAN est interrogée pour de vrai ; base LOCALE seulement)
npx vitest run tests/js/deliveryCharge.spec.js tests/js/posLivraison.spec.js tests/js/posDeliveryEnsureCustomer.spec.js tests/js/posDeliveryFlag.spec.js
```
`php artisan test` n'exécute que le **premier** chemin donné : utiliser `phpunit --filter` pour en enchaîner plusieurs.
`phpunit.xml` pose `DELIVERY_SERVER_GEOCODING=false` pour les tests historiques ; la configuration livrée est **active**
(sentinelle : `AddressServerGeocodingTest::test_server_geocoding_is_on_by_default_in_the_shipped_configuration`).

## 6. Uber Direct — bascule d'un arrêt entre nos livreurs et un coursier Uber (2026-10-22, décision propriétaire)

**Usage (caisse → Tournées)** : chaque arrêt affiche « 🛵 Nos livreurs » ou « 🚗 Uber ». « **Confier à Uber** » demande d'abord le
**prix et le délai d'Uber** (devis gratuit, sans engagement) ; le caissier voit le montant, puis « **Confirmer Uber** » commande la
course. « **Nos livreurs** » annule la course Uber et rend l'arrêt à notre livreur — possible **tant que le coursier Uber n'a pas le sac**.
La suspension / remise de la **livraison entière** reste le bouton « Livraison active » de la caisse (§3).

**Règles qui ne se discutent pas**
- **Uber ne peut pas encaisser à la porte.** Un arrêt « à encaisser » (espèces, carte, titre-restaurant) n'est jamais confié à Uber : le
  bouton est grisé avec la raison. Il faut l'avoir fait payer avant (paiement en ligne ou au comptoir).
- Le coût d'Uber est **à la charge du restaurant** : le client paie notre barème (§1), inchangé. Le prix d'Uber n'est qu'affiché à la caisse.
- Un devis expiré ou changé n'est **jamais** dépêché : le nouveau prix est à reconfirmer (409 `quote_changed`).
- Un double appui ne crée **qu'une course** (verrou de ligne + `idempotency_key` + contrainte UNIQUE `order_id`). La clé est
  `lecayenne-order-{id}` tant que la fin de la course précédente n'est pas connue (réponse perdue + réessai, même après un nouveau devis →
  Uber renvoie la course déjà créée, jamais une 2ᵉ) ; après une course **annulée / revenue côté Uber**, elle devient
  `lecayenne-order-{id}-after-{delivery_id}` : re-confier crée bien une NOUVELLE course (E2E 2026-10-24).
- Le coursier Uber prend le sac d'une commande encore **en préparation** : le webhook **ne touche pas à la cuisine** (deux cycles séparés,
  consigne propriétaire) ; la planche affiche « Uber a le sac : marquez la commande prête en cuisine », et dès que la cuisine marque PRÊTE,
  l'écouteur `SyncUberCourseAfterKitchenReady` passe la commande EN ROUTE (ou LIVRÉE) par la machine officielle (E2E 2026-10-24).
- Le suivi client d'une commande confiée à Uber annonce l'**arrivée estimée** par Uber (`arrivee_estimee`), plus le départ de notre tournée.
- Une panne d'Uber ne casse rien : message clair, la commande **reste à nos livreurs**.
- Un arrêt Uber n'a **plus de livreur à nous** (disparaît de son écran) ; désigner un livreur pour la tournée ne le lui reprend pas.
  Le départ du coursier Uber (« sac pris ») ne fait **pas partir la tournée de nos livreurs** : elle reste « à venir », son livreur
  reste affiché et désignable, et elle reste ouverte aux nouvelles commandes (`waveHasLeft` ne compte que nos départs — E2E 2026-10-24).
- Les dates d'Uber sont en **UTC** (`expires` « …Z ») : elles sont ramenées au fuseau de l'application avant enregistrement, sinon un
  devis paraît périmé 2 h trop tôt et chaque « Confirmer Uber » répond 409 (défaut trouvé par le banc E2E 2026-10-24, corrigé).
- La commande suit Uber : sac pris → EN ROUTE, livré → LIVRÉE, par `OrderStateMachine::apply` (voie du code neuf ; zone gelée intacte).
  Uber annule ou renvoie la course **avant** la prise du sac → l'arrêt revient à nos livreurs. **Après** la prise du sac, la commande est
  EN ROUTE et nos livreurs ne peuvent plus la reprendre : la caisse affiche une **alerte rouge** (« la commande n'est pas livrée »), jamais un retour silencieux.
- **Commande annulée alors qu'un coursier Uber est demandé** : la course Uber est annulée AVANT que l'arrêt disparaisse. Si Uber ne confirme pas
  (ou si le coursier a déjà le sac), l'arrêt **reste visible** avec la raison : jamais une course facturée sans trace.
- **Réponse d'Uber perdue** à la demande de course : l'appel n'est pas dans une transaction ; le message dit que la course **a peut-être été créée**
  (vérifier dans l'application Uber Direct avant de réessayer) ; la même clé d'idempotence évite une 2ᵉ course. Un événement Uber plus ancien que l'état connu ne la fait pas reculer.

**Code** : `App\Services\UberDirect\UberDirectDispatchService` (règles), `UberDirectClient` (transport), routes
`POST /api/admin/pos-delivery/uber/{quote,dispatch,release}` (permission `pos`, limiteurs nommés `pos-delivery-uber-*`),
webhook `POST /api/webhooks/uber-direct` (HMAC-SHA256 du corps brut, `X-Uber-Signature`, fail-closed), relevé de secours des
courses actives à chaque ouverture de la planche (≤ 1 fois / 15 s). Tests : `UberDirectCaisseSwapTest`, `posTourneesUberDirect.spec.js`.

**Mise en service — ce qui reste à faire (dit franchement)**
1. ⛔ **L'application Uber fournie n'a PAS le droit `eats.deliveries`** (constaté le 2026-10-22 : le jeton n'est accordé qu'avec
   `direct.organizations` ; les devis et courses répondent 401 « requires … eats.deliveries »). À activer côté Uber (tableau de bord
   Direct → Developer, ou support Uber Direct) ; probablement aussi la validation du compte.
2. Les **corps de requête** (devis, création, annulation) suivent la documentation publique, **pas encore confrontés au compte réel** :
   le premier devis réel est la vérification. Ne passer `UBER_DIRECT_ENABLED=true` en production qu'après un devis réussi.
3. Vérifier qu'Uber Direct **couvre Hénin-Beaumont** (couverture ≠ marketplace) — le devis le dira.
4. Créer le webhook dans le tableau de bord (URL ci-dessus) et poser `UBER_DIRECT_WEBHOOK_SECRET`. Sans lui, la planche relève les statuts elle-même.
5. Poser les identifiants dans le `.env` **du serveur** (jamais commité) ; **régénérer le secret client**, il a transité dans une conversation.
6. Non fait : bascule de **toute une tournée** d'un appui (aujourd'hui : arrêt par arrêt) ; annulation côté Uber d'une course dont le coursier est déjà en route (frais éventuels : conditions du compte).

## 7. Aller simple ou aller-retour — qui voit quoi (2026-10-23, décision propriétaire)

Deux calculs distincts, à ne jamais confondre :

| | Trajet | Où | Dépend du prix ? |
|---|---|---|---|
| **Client / site / application** | **Aller simple** : restaurant → l'adresse du client | devis (`distance_km`, `travel_minutes`), ETA d'arrivée de chaque arrêt | Le **prix** vient du barème (§1) ; ni le retour ni le routage n'y changent rien |
| **Gestion des livreurs** | **Aller-retour réel** : restaurant → arrêts → **restaurant**, remises comprises | planche « Tournées » de la caisse et page Livraisons (`tour`), écran livreur (`tour`) | Non |

- Le seuil de **7 km** (nos livreurs / devis Uber) se mesure toujours en **aller simple** : 7 km pour aller, le retour n'est jamais compté.
  ⚠️ Ce seuil n'est pas encore construit (étape 2 de `reports/test-e2e/etat-des-lieux-2026-10-23/SYNTHESE.md`) ; le barème actuel reste en
  **vol d'oiseau** (§1), inchangé.
- `RoadRouteService` lit les durées d'**itinéraire routier** (OSRM, service public sans clé ; `config/delivery.php` → `routing`).
  Panne, délai (3 s) ou réponse inexploitable → estimation à vol d'oiseau × 1,3, et la réponse le **dit** (`source = estimate`) : l'écran
  écrit « estimation, itinéraire indisponible », jamais « itinéraire routier réel ». Une panne est mémorisée 60 s ; un itinéraire réussi 6 h.
  `DELIVERY_ROUTING_ENABLED=false` coupe tout appel réseau (positionné dans `phpunit.xml`).
- `tour` = `{ tour_minutes, out_minutes, return_minutes, stops, distance_km, back_at, source }` (null si tout est remis ou si aucun arrêt
  n'a de point GPS : on n'invente pas une durée). Durée = Σ (conduite × `traffic_factor` + marge de stationnement + remise par arrêt) + trajet de retour.
  `traffic_factor` 1,15 et la marge de 3 min sont des **valeurs proposées, à confirmer par le propriétaire** après quelques vraies tournées.
- **Appel réseau jamais dans la création d'une commande** : `resequence()` (dans la transaction d'`assign()`, verrous tenus) n'utilise que
  l'itinéraire déjà en mémoire, sinon l'estimation. Le réseau n'est sollicité qu'à la LECTURE (planche, écran livreur). Une panne du
  fournisseur est mémorisée 60 s pour TOUS les itinéraires (coupe-circuit global) : au pire un délai de 3 s par minute.
- Les arrêts confiés à **Uber** ne comptent pas dans la tournée de nos livreurs ; `back_at` ne peut pas être dans le passé (tournée en
  retard : au plus tôt maintenant ; tournée partie : depuis le départ réel).
- ⚠️ **Données personnelles** : le calcul envoie des **coordonnées GPS** (domiciles de clients, sans nom ni téléphone) au service public
  `router.project-osrm.org`, une instance de démonstration. À trancher par le propriétaire avant de s'y fier en production : héberger
  son propre OSRM, ou `DELIVERY_ROUTING_ENABLED=false` (retour à l'estimation à vol d'oiseau).
- Tests : `TourneeAllerRetourTest` (itinéraire, panne, retour compté, client en aller simple), `posTourneesJour.spec.js`, `livreurTourneeAllerRetour.spec.js`.
- Non fait : la caisse du livreur (espèces/TPE) par tournée, le GPS continu, les détours (suite de l'étape 5 de la synthèse).

## 8. QR de remise à usage unique sur le ticket client (2026-11-01)

Un QR est imprimé sur le **ticket client thermique des LIVRAISONS de nos tournées** (jamais à emporter / borne / table, ni Uber Eats, ni
course Uber Direct). Le livreur le scanne avec l'appareil photo de son téléphone, arrive sur **notre plateforme** (`APP_URL`, pas le site
vitrine), se **connecte toujours** (e-mail + mot de passe), voit l'adresse (+ Waze), déclare **comment la commande a été payée à la porte**
(rien à choisir si elle l'est déjà en ligne), et touche « Remis ». Le QR est alors mort pour toujours.

**Valeurs par défaut posées en attendant la confirmation du propriétaire** : (1) le mode déclaré ne peut différer du choix du client
qu'entre **espèces et carte**, toujours tracé et signalé ; titre-restaurant déclaré autrement → refusé, « renvoyez à la caisse » ;
(2) durée de vie = départ de la tournée + **6 h** ; (3) après « Remis », retour à `/livreur` avec la session du livreur conservée ;
(4) jeton dans le **fragment** `#` ; (5) position enregistrée si accordée, jamais bloquante ; montant encaissé = dû **exact**, sinon 422.

**Contrat**
- Jeton : 32 octets, base64url (43 car.), **dérivé** `HMAC-SHA256(clé dérivée d'APP_KEY, "v1|arrêt|commande|génération")`. Seul `sha256(jeton)`
  est en base (`delivery_handover_tokens`, `BranchScope`). Une **réimpression redonne le même QR** ; `regenerate()` = génération + 1 (l'ancien
  papier meurt) ; un jeton révoqué/brûlé n'est jamais ressuscité par une réimpression ; une rotation d'`APP_KEY` invalide les QR imprimés (repli : `/livreur`).
- URL `APP_URL/livreur/remise#<jeton>`, **≤ 124 octets** (la commande `GS ( k` écrit la longueur sur 1 octet et le flux est transcodé CP858 :
  au-delà, **aucun QR** n'est imprimé plutôt qu'un QR faux ; mesuré : 91 octets avec l'hôte actuel).
- `GET /livreur/remise` : page statique identique pour tous (aucune donnée), `no-store`, `no-referrer`, `noindex`, `X-Frame-Options: DENY`. Le script lit
  le fragment, l'**efface aussitôt** (`history.replaceState`), ne le garde qu'en mémoire, **ignore tout stockage** (dont `lc_livreur_token`).
- `POST /api/frontend/delivery-handover/login` : refuse **avant tout jeton** un compte qui n'est pas **exactement** « Delivery Boy » (actif, branche > 0).
  Mauvais mot de passe / compte inconnu / désactivé = **une seule** réponse (« Identifiants incorrects. »). Jeton court `handover_token`
  (aptitude `delivery:handover`, 15 min, appareil unique) : il **ne chasse pas** la session `/livreur` (`auth_token`) et `HandoverTokenPolicy`
  (crochet Sanctum) le rend **inexistant partout sauf** `api/frontend/delivery-handover/*`.
- `POST …/resolve` et `POST …/confirm` (jeton `handover_token` obligatoire ; un jeton de session ordinaire est refusé 403). Jeton inconnu, révoqué,
  périmé, ou qui n'est pas celui de ce livreur → **la même réponse 404** `QR_UNAVAILABLE`, sans adresse, nom, téléphone ni contenu.
- `confirm` — deux règles. (1) **Aucune transaction n'enveloppe `OrderService::deliveryBoyOrderChangeStatus`** (relecture adverse C : ses audits NF525 et
  ses mail/SMS/push partent APRÈS son propre COMMIT ; sous une transaction externe : `1205 Lock wait timeout` sur la chaîne d'audit, audits perdus, mail avant un échec).
  (2) **Aucun état « en cours » n'est persisté** (relecture adverse D : une réservation écrite sur la ligne du jeton survivait à un processus mort et faussait le
  mode, ou bloquait le livreur). L'exclusion de deux appuis est un **verrou applicatif à expiration automatique** : `Cache::lock('delivery-handover:stop:<id>', 30)`,
  pris par un propriétaire, **tenu pendant tout le corps** (jamais une transaction), libéré par lui seul dans un `finally`. Un processus mort ne bloque plus personne
  après 30 s ; un jumeau qui ne peut pas le prendre répond **409 `in_progress`** (« Validation en cours, réessayez dans un instant », la page reste sur l'écran, même clé
  d'idempotence) et **ne rappelle jamais OrderService** (pas de mail/SMS/push/événement en double). Sous le verrou : (a) l'état est **relu** ; (b) si la commande est déjà
  LIVRÉE (jumeau, ancien écran, crash après commit) OrderService n'est PAS rappelé : l'arrêt est terminé avec le **mode réellement appliqué, lu dans la commande**
  (`order_payments` de référence « livraison », ou `pos_payment_method = CASH` + trace d'escrow **ou** ligne de caisse livreur de la commande : une seule des deux suffit), jamais dans la requête ; (c) sinon contrôles → mode et montant → remise officielle
  SEULE → petite transaction (verrou de l'arrêt, brûlure `UPDATE … WHERE used_at IS NULL`, arrêt, `delivery.handover_validated` avec le mode APPLIQUÉ et `mode_mismatch`
  = écart avec le choix du client). Échec de la remise officielle : rien n'est écrit, rien brûlé, verrou libéré, le livreur peut réessayer avec un autre mode. Échec de la
  petite transaction : la commande est LIVRÉE (le jeton est déjà inutilisable : `inspect()` refuse une commande livrée), le prochain appui termine l'arrêt. Le TTL de 30 s
  suppose une remise officielle plus courte que 30 s (mail/SMS synchrones compris) ; au-delà, deux appuis pourraient se chevaucher (la commande LIVRÉE et le verrou de ligne
  d'OrderService protègent l'encaissement et le numéro fiscal). La table n'a **pas** de colonne `chosen_mode` : le choix du client se déduit (espèces ↔ carte) du mode
  appliqué et de `mode_mismatch`. La page annonce l'écart : « Vous avez déclaré carte alors que le client avait choisi espèces : c'est enregistré. »
  Rejeu : le même livreur qui rejoue après coup reçoit `already: true` ; un autre reçoit 404. Les refus sont tracés `delivery.handover_refused` (pas les 409 `in_progress`).
- Matrice : espèces/carte choisis → déclaré espèces ou carte (écart tracé : `mode_mismatch`) ; titre-restaurant → titre-restaurant seulement ;
  payée en ligne → ni mode ni montant (sinon 422) ; carte en ligne jamais payée → « renvoyez à la caisse » (la défaillance historique du flip PAYÉ
  sans paiement n'est PAS atteignable par ce chemin). Aucun escrow espèces ni mouvement de tiroir pour une carte ; le choix du client
  (`orders.door_payment_mode`) n'est jamais réécrit.
- `POST …/confirm` est dans `idempotency.required_routes` ; limiteurs nommés `delivery-handover-login|resolve|confirm` (jamais un `throttle:N,M` nu).

**⚠️ Interrupteur d'impression — le QR est ÉTEINT par défaut.** `config/delivery.php` → `handover.qr_print_enabled`, piloté par l'env `DELIVERY_HANDOVER_QR_PRINT`, **défaut `false`**
(sentinelle : `DeliveryHandoverQrPrintFlagTest`). Éteint : le ticket ne contient **aucun octet de QR** et **aucun jeton n'est créé** ; les routes de remise restent actives
(un jeton n'existe que s'il a été imprimé). Raison : la lisibilité sur l'imprimante réelle n'est pas testée (un modèle sans `GS ( k` imprimerait des caractères parasites sur
TOUS les tickets de livraison). **Procédure propriétaire pour l'allumer** : (1) poser `DELIVERY_HANDOVER_QR_PRINT=true` dans le `.env` de production ; (2) `php artisan config:clear`
(**JAMAIS `config:cache`**, cf. docs) ; (3) imprimer UNE livraison test ; (4) scanner le papier avec un **iPhone ET un Android** et vérifier que la page s'ouvre sans « QR non lisible »
(le `#` doit être conservé par l'appareil photo) ; (5) si un des deux échoue, ou si des caractères parasites sortent : remettre `false` (+ `config:clear`). Rien d'autre à défaire.

**Résidu connu (non corrigé, décision)** : le retour anticipé d'`OrderService::deliveryBoyOrderChangeStatus` (statut déjà atteint) ne retient pas les notifications qui le suivent. Si le
verrou de remise expire pendant une remise officielle de plus de 30 s (ou si Redis est vidé en pleine remise), le jumeau peut renvoyer mail/SMS/push/`OrderStatusChanged`.
**Aucun effet financier** (un seul numéro fiscal, une seule tranche, un seul escrow : verrous de ligne d'OrderService) ; seul le client peut recevoir deux messages « livrée ».

**QR perdu, taché ou photographié — procédure honnête (aujourd'hui)**. `DeliveryHandoverService::revoke()` / `regenerate()` existent et sont testés mais n'ont
**aucun appelant** (ni bouton caisse, ni commande artisan) : rien n'a été construit pour les déclencher. Ce qui protège réellement : (1) le QR seul ne sert à rien —
il faut le **mot de passe du livreur attribué** à cet arrêt (autre livreur, client, caisse, admin : refusés ; réponse identique, rien révélé) ; (2) la commande doit être
**non annulée, non livrée, non confiée à Uber, et partie** ; (3) il **expire** à départ de tournée + 6 h ; (4) usage unique. Ticket abîmé = il suffit de **réimprimer** (même QR).
Si le papier a pu être vu par un tiers : annuler/relivrer par la caisse, ou laisser le livreur valider par l'écran `/livreur` (repli intact). **Reste ouvert** : pas de
révocation à la demande depuis la caisse, et le livreur attribué lui-même peut valider avec un QR photographié.

**Risque de conception connu (documenté, non corrigé)** : le QR voyage sur le ticket avec le livreur, qui peut donc toucher « Remis » **avant d'arriver à la porte** ;
e-mail + mot de passe prouvent QUI valide, pas OÙ. Ce que la remise enregistre pour le détecter après coup : l'**heure** (`delivered_at`), la **position** si le téléphone
l'accorde (jeton + arrêt ; facultative, jamais bloquante), et dans `delivery.handover_validated` : `left_at`, `minutes_since_departure`, `position_given`, `lat`/`lng`/`accuracy_m`,
l'appareil et l'IP. Une remise sans position, ou quelques minutes après le départ, se lit dans le journal ; elle n'est pas refusée.

**Ce qui n'est PAS fait / à vérifier** : le ticket HTML (`ReceiptComponent.vue`, `window.print`) n'a pas de QR ; le support `GS ( k` du modèle réel
d'imprimante, la lecture du QR sur papier thermique et la conservation du `#` par l'appareil photo de chaque téléphone se vérifient **sur la machine**.
Défauts HISTORIQUES de `DeliveryRunController::deliver` (chemin `/livreur`, non modifié) : `collected_cents` vient du client et n'est jamais comparé au dû ;
un 2ᵉ appel réécrit l'arrêt (`delivered_at`, `amount_collected_cents`). Tests : `DeliveryHandoverTokenTest`, `…LoginTest`, `…ConfirmTest`, `LivreurRemisePageTest`,
`ZLivraisonQrDeclaredModeTest`, `ClientTicketDeliveryQrTest`, `TicketWidthSafeTest`, banc navigateur `tests/Playwright/livraison-qr-remise/run.js`.

## 9. Règlement à la porte en plusieurs moyens, « Ma caisse » du livreur et clôture (2026-11-02)

**Règlement en plusieurs moyens.** `POST /api/frontend/delivery-run/stops/{id}/deliver` et `POST /api/frontend/delivery-handover/confirm` acceptent un
paramètre FACULTATIF `payments = [{mode: cash|card|meal_voucher, amount: euros}]` (1 à 4 tranches). Sans `payments`, le chemin historique (un seul moyen, celui
du client) est strictement inchangé. Avec `payments`, le serveur impose (`DoorPaymentSplit::normalize`, sous le verrou de la commande, total relu en base, jamais
un montant client) : somme = total **au centime**, chaque montant ≥ 0,01 € à deux décimales, un seul tranche par moyen, commande encore à encaisser à la porte
(espèces ou titre-restaurant au départ ; pas déjà payée en ligne ni au comptoir). Tout refus = **422 en français, rien d'écrit**.
- Écrit dans la MÊME transaction que le PAYÉ et le numéro fiscal (allocation inchangée) : une ligne `order_payments` par tranche (`reference='livraison'`, ordre
  espèces, carte, titre), `pos_payment_method` = moyen **dominant** (convention du paiement multi-moyens du comptoir ; le Z range la commande entière sous ce moyen,
  la ventilation exacte vit dans `order_payments`, dont `ZReportCashEnrichmentService` fait déjà la somme par moyen). `ZReportService` n'est pas touché.
- Après COMMIT : tranche ESPÈCES → audit `delivery.cash_collected_escrow` (montant espèces seul) + **un seul** `DeliveryBoyCashMovement` ENTRÉE du montant espèces ;
  tranches CARTE / TITRE → un audit `order.payment_tranche_persisted` chacune (`tranche_index` = position dans les lignes `order_payments`). Les payloads portent
  `declared_differs` (le livreur a encaissé autrement que le choix du client, ou en plusieurs fois : accepté, **tracé**), `chosen_mode`, `tranches`.
- Rejeu : la remise est déjà LIVRÉE, l'appel retourne tôt ; aucun paiement, mouvement, audit ni numéro fiscal en double. Sur le QR, `declared_mode='split'` est posé
  sur le jeton (`mode_mismatch=true`) et `delivery.handover_validated` cite `payments` ; `chosen_mode` se relit dans la commande (`chosenModeFromOrder`), plus dans l'accesseur du jeton.
- **Nouveau** : carte et titre-restaurant encaissés à la porte ouvrent AUSSI le service du livreur (fond 0 €, `ensureOpenDeliveryShift`), comme les espèces depuis le 2026-10-30,
  sinon « Ma caisse » ne verrait pas ces encaissements.

**Écran `/livreur`.** « Remis au client » (inchangé) règle avec le moyen choisi par le client. Le bouton « Autre moyen · en plusieurs fois » ouvre la feuille de règlement :
Espèces / Carte / Titre-restaurant / En plusieurs fois ; le total (non modifiable) vient de la commande ; en plusieurs fois, « Reste à encaisser : X € » se met à jour et
« Valider la remise » reste grisé tant que la somme ≠ total. Même saisie sur la page QR de remise. Réseau coupé : message « Pas de réseau… », la feuille reste ouverte, la
**même clé d'idempotence** est rejouée (aucun doublon).

**« Ma caisse ».** Onglet de `/livreur`. `GET /api/frontend/delivery-run/cash` : service OUVERT du livreur connecté (jamais celui d'un autre : `delivery_boy_id` = lui, sa branche ;
rôle « Delivery Boy » exigé, sinon 403) ; sans service ouvert : `{open:false, message:'Aucun service ouvert', last}` (200, jamais une erreur). Contenu (`DeliveryCashRecapService`, sans
nouvelle colonne) : fond de début (`opening_amount`, fixé par l'ouverture en administration ; 0,00 € si le service s'est ouvert tout seul), espèces / carte / titres (depuis
`order_payments` « livraison » des arrêts remis pendant le service ; espèces historiques sans ligne = mouvement de caisse), total, livraisons réglées, **espèces attendues = fond +
Σ mouvements signés** (formule de `reconcileSession`), liste des commandes avec leur répartition, `ledger_gap` (espèces des commandes − livre de caisse : exposé, jamais caché).
`POST …/cash/close {counted_cash, session_id?}` (idempotent, limiteurs nommés `delivery-run-cash*`) : refuse (422) s'il reste une livraison en route à encaisser, sinon
`closeSession` ; réponse = récap final avec **écart = comptées − attendues**. Un second appel renvoie `already:true` sans rien changer. Le service clôturé reste `closed` ;
la réconciliation (`reconcileSession`) reste faite par l'administration. Fiche administration : `DeliveryBoyCashSessionResource.recap` + `DeliveryBoyCashSessionShowComponent.vue`.

**Limites honnêtes.** La carte et le titre-restaurant sont encaissés hors de notre système (terminal du livreur) : le logiciel enregistre ce que le livreur déclare, il ne le prouve pas.
Le mouvement de caisse livreur est écrit « au mieux » après le COMMIT (comme avant) : s'il échoue, `ledger_gap` le montre. Une clôture simultanée à une remise peut laisser un
encaissement sans mouvement (service fermé entre les deux) : même signal. L'écran d'administration (Vue) n'a pas été vérifié dans un navigateur (assets non compilés dans cet arbre) :
test de composant Vitest seulement. Tests : `DeliveryDoorSplitTest`, `DeliveryRunCashTest`, `tests/js/deliveryBoyCashSessionRecap.spec.js`, banc `tests/Playwright/livreur-caisse-split/run.js`.
