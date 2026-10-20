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
- L'ordre de passage est le plus proche voisin depuis le restaurant ; l'ETA par arrêt est une **estimation** (vitesse
  `average_speed_kmh` × 1,3 de détour + marge). **Le temps réel est celui de Waze**, ouvert depuis l'écran livreur.
- Tout vit dans `delivery_stops` (jamais dans `OrderStateMachine`, gelée). Écouteur : `AssignDeliveryWaveOnOrderCreated`.
  Filet : `php artisan delivery:assign-pending` (toutes les 5 min).

## 3. Qui fait quoi

- **Caisse** (`/admin/pos`) : bouton **« Livraison active — suspendre / remettre »** (deux appuis), bouton **Tournées**
  (tournées du jour, choix du livreur s'il y en a plusieurs), téléphone → client retrouvé + ses adresses enregistrées,
  adresse choisie dans la liste serveur → devis. Routes `GET/POST /api/admin/pos-delivery/*`, permission `pos`.
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
