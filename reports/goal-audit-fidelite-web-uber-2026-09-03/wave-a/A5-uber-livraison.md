# A5 — Uber & livraison : ce qui existe, ce que le patron demande, et l'écart

Audit LECTURE SEULE. Branche `pos/category-first-caisse-2026-06-23`, HEAD `a91f95e2e`.
Aucun secret recopié : toute valeur sensible est notée `<défini>`.

---

## § Ce qui existe (et quel produit Uber)

**Verdict : le dépôt implémente Uber Eats Marketplace / Order API — l'INGESTION de
commandes Uber Eats entrantes. Uber Direct est ABSENT.**

Preuves, endpoints **littéraux** lus dans le code :

- `config/uber.php:29-34` — `'endpoints'` :
  - `'order'  => '/v1/eats/orders/{order_id}'`
  - `'accept' => '/v1/eats/orders/{order_id}/accept_pos_order'`
  - `'deny'   => '/v1/eats/orders/{order_id}/deny_pos_order'`
  - `'store'  => '/v1/eats/stores/{store_id}'`
  - le commentaire de tête de ce bloc dit lui-même « Endpoints (Eats Marketplace) ».
- `config/uber.php:20-22` — `token_url = https://login.uber.com/oauth/v2/token`,
  `api_base = https://api.uber.com`, `scopes = 'eats.store eats.order'`.
- `app/Services/Uber/UberClient.php:9-15` — docbloc : « Client API Uber Eats (OAuth2
  client_credentials + Orders API) ».
- `UberClient.php:62-83` — les quatre seules méthodes métier : `fetchOrder()`,
  `acceptOrder()`, `denyOrder()`, `storeStatus()`. Aucune méthode de devis ni de
  création de course.
- `UberClient.php:36-41` — OAuth 2.0 `client_credentials` (pas d'autorisation
  utilisateur), token mis en cache (`:53`), retry unique sur 401 (`:106-110`).
- `routes/api.php:167` — `POST /api/webhooks/uber` → `UberWebhookController@handle`.
  Le sens du flux est entrant : Uber pousse, la caisse reçoit.
- Secrets : `config/uber.php:16-19,26` lisent tout depuis `.env`
  (`UBER_CLIENT_ID`/`_SECRET`/`_ORG_ID`/`_STORE_ID`/`_WEBHOOK_SECRET`) — valeurs
  `<défini en .env, non commité>`. `.env.example:539-567` ne porte que les clés.

Le second bloc Uber n'est pas une API du tout : `app/Models/UberTicketCapture.php:10-23`
décrit la **photo du ticket Uber prise sur la tablette**, lue par un modèle de vision
(`app/Services/Uber/Vision/`, `UberPhotoOrderMapper.php`), avec confirmation humaine
obligatoire avant création de commande. C'est un contournement de l'absence d'accès API,
pas une intégration.

**Recherche de Uber Direct — négative.** `grep -rin "delivery_quote|UberDirect|uber_direct|
direct\.organizations|/deliveries|dropoff_address"` sur `app/ config/ routes/` ne renvoie
aucun résultat de code (deux occurrences du mot « courier » en commentaire seulement :
`app/Http/Resources/SimpleOrderResource.php:123`, `app/Http/Requests/OrderRequest.php:305`).
→ **ABSENT — à créer** (chemin proposé : `app/Services/Delivery/Providers/UberDirectProvider.php`
+ `config/uber_direct.php`).

**⚠️ Piège de lecture.** La branche `fix/uber-order-fetch-v2` ajoute des chemins contenant
le mot `delivery` (`git show fix/uber-order-fetch-v2:config/uber.php`) :
`/v1/delivery/store/{store_id}/status`, `/v1/delivery/order/{order_id}/ready`,
`/v1/delivery/order/{order_id}/cancel`, `/v1/delivery/order/{order_id}/deny`, plus
`/v2/eats/order/{order_id}` et `/v2/eats/stores/{store_id}/menus`. **Ce ne sont PAS des
endpoints Uber Direct** : ce sont les endpoints de cycle de vie d'une commande Marketplace
(« la commande est prête », « le restaurant annule », statut boutique ouverte/fermée). Ils
disent au coursier Uber, déjà dépêché par Uber, que le sac est prêt. Ils ne permettent pas
de *commander* une course pour une commande née chez nous.

**La branche n'est pas fusionnée.** `git rev-list --left-right --count HEAD...fix/uber-order-fetch-v2`
→ `871  10` : 10 commits vivent uniquement sur la branche. `git branch --contains
fix/uber-order-fetch-v2` ne liste qu'elle-même. Son runbook
(`docs/runbooks/UBER_GO_LIVE.md`, présent seulement sur la branche) date du 2026-08-03 et
attend la validation « Basic Production » d'Uber (ticket `<référence dans le runbook>`,
dossier envoyé le 2026-08-02) — donc **l'ingestion Uber Eats elle-même n'est pas en
production**, et son go-live est suspendu à Uber depuis un mois.

---

## § Ce que le besoin owner exige réellement

Le patron ne décrit pas la réception de commandes Uber Eats. Il décrit :

1. une commande **née chez nous** (site, borne, téléphone, caisse) ;
2. livrée par **notre** livreur d'habitude ;
3. **basculée** vers un coursier Uber quand nous n'avons personne ou que la production sature ;
4. avec un **prix de livraison dynamique selon l'adresse** ;
5. le coursier Uber **vient récupérer** la commande au comptoir.

C'est la définition de **Uber Direct** (livraison à la demande en marque blanche : on paie
Uber pour livrer nos propres commandes à nos propres clients). C'est un **produit distinct**
de Uber Eats Marketplace, avec son propre contrat, son propre accès développeur et ses
propres identifiants. Avoir un compte commerçant Uber Eats **ne donne pas** Uber Direct.

---

## § Écart

| Besoin | État |
|---|---|
| Recevoir des commandes Uber Eats | Codé, non fusionné, bloqué chez Uber |
| Commander une course Uber pour NOS commandes | **ABSENT — 0 ligne** |
| Devis de course selon l'adresse (prix Uber) | **ABSENT** |
| Suivi du coursier Uber (statuts, ETA) | **ABSENT** |
| Bouton « basculer sur Uber » en caisse | **ABSENT** |
| Frais de livraison selon l'adresse (NOTRE tarif) | **EXISTE** (voir ci-dessous) |
| Affectation d'un livreur | **EXISTE, point unique** |

**Les frais de livraison existent déjà et sont dynamiques par adresse.**
`app/Services/Delivery/DeliveryQuoteService.php:32-87` géocode l'adresse enregistrée,
calcule la distance (`:56`, haversine), refuse hors polygone de zone (`:71-74`,
`branches.zone`, ray-casting) et délègue le tarif à
`app/Services/Delivery/DeliveryFeeService.php:26-56` :
- si les colonnes de branche sont peuplées (`app/Models/Branch.php:20-22`
  `delivery_fee_base`, `_per_km`, `_minimum`, `_free_km`) :
  `max(minimum, base + per_km × ceil(distance − free_km))` — la règle patron « 4 € jusqu'à
  5 km, +1 €/km » (`DeliveryFeeService.php:39-49`) ;
- sinon repli hérité `max(5, ceil(d/5) × 5)` (`:55`).
Appelé côté client par `app/Http/Requests/OrderRequest.php:108,127` et côté caisse par
`app/Http/Controllers/Admin/PosController.php:251` + `app/Http/Requests/PosOrderRequest.php:55`
(recalcul serveur anti-gonflage). **Aucun écran d'admin pour ces colonnes**
(`app/Http/Requests/OrderSetupRequest.php:38` le dit : « sans écran d'admin »).

**Le point de bascule est unique et identifié.**
`app/Services/OrderService.php:3163` — `selectDeliveryBoy(Order $order, Request $request,
bool $auth = false)` est le SEUL endroit où une commande reçoit un livreur :
lecture de `delivery_boy_id` (`:3174`), garde de rôle Spatie `'Delivery Boy'` (`:3190-3193`),
garde inter-branches (`:3201`), mutation `$order->delivery_boy_id = …; $order->save();`
(`:3208-3209`), puis trace `order.delivery_boy_assigned` dans la chaîne d'audit (`:3220-3233`).
Appelé depuis `PosOrderController` et `OnlineOrderController` (commentaire `:3160`).
**C'est là que branche l'aiguillage « notre livreur / coursier Uber ».**

**Statuts réels** (`app/Enums/OrderStatus.php:7-15`) : `PENDING=1, ACCEPT=4, PREPARING=7,
PREPARED=8, OUT_FOR_DELIVERY=10, DELIVERED=13, CANCELED=16, REJECTED=19, RETURNED=22`.
Transitions livraison (`app/Domain/Order/OrderStateMachine.php`, ZONE GELÉE, lue seulement) :
`PREPARED → {OUT_FOR_DELIVERY, DELIVERED}` puis `OUT_FOR_DELIVERY → {DELIVERED, CANCELED}`.
**Bonne nouvelle : aucune modification de la machine à états n'est nécessaire** — une course
Uber emprunte exactement `PREPARED → OUT_FOR_DELIVERY → DELIVERED`. La zone gelée reste intacte.

---

## § Démarche owner auprès d'Uber (checklist WHO-WHAT-WHERE)

Tout ce qui suit est **À CONFIRMER auprès d'Uber** : je ne peux le prouver ni depuis le
dépôt ni depuis une source vérifiée dans cette session. Je n'invente aucun nom d'endpoint.

1. **WHO — le patron, pas un développeur.** Uber Direct se souscrit commercialement. Le
   compte Uber Eats existant ne l'inclut pas.
2. **WHAT — demander nommément « Uber Direct »** (livraison à la demande / marque blanche),
   en précisant : « je veux payer Uber pour livrer MES commandes à MES clients, pas recevoir
   des commandes Uber Eats ». Cette phrase évite le malentendu qui a produit l'intégration
   actuelle. Dire aussi : usage **ponctuel, en débordement**, pas tout le flux.
3. **WHERE — deux portes.**
   - a. Le gestionnaire de compte Uber Eats existant du restaurant (le plus rapide : le
     compte marchand est déjà ouvert) ;
   - b. le formulaire de contact commercial Uber Direct sur le site Uber, à défaut.
   Le ticket Uber en cours mentionné dans `docs/runbooks/UBER_GO_LIVE.md` porte sur
   Marketplace : **ouvrir un dossier distinct**, ne pas le greffer dessus.
4. **Informations à fournir** : SIRET et raison sociale, adresse exacte du point de retrait,
   horaires, volume mensuel estimé de courses, panier moyen, rayon de livraison souhaité,
   coordonnées de facturation. À CONFIRMER.
5. **À obtenir en retour** (À CONFIRMER — c'est ce qu'il faut exiger par écrit) :
   contrat/tarification par course ; accès au portail développeur Uber Direct ; une
   **application dédiée** avec `client_id` + `client_secret` **distincts** de ceux de
   Marketplace ; l'identifiant d'organisation/client ; les **scopes** exacts ; l'URL du
   **webhook de suivi de course** ; et la **documentation des endpoints** (devis + création
   de course). ⛔ Ne rien coder avant d'avoir cette doc en main : je n'ai lu aucun endpoint
   Direct dans ce dépôt et je refuse d'en présumer.
6. **Bloquants humains** : validation commerciale Uber (délai inconnu), couverture
   géographique d'Uber Direct à l'adresse du restaurant (à vérifier avant tout code), et le
   précédent Marketplace montre qu'une validation Uber peut durer plus d'un mois.
7. **Hygiène** : les identifiants transitent par le runbook et les chats. Prévoir la
   régénération du secret après mise en service, comme déjà prescrit
   (`docs/runbooks/UBER_GO_LIVE.md` étape 4).

---

## § Ce qui est faisable AVANT l'accès Uber

Tout est faisable sans toucher la zone gelée ni NF525.

1. **Adaptateur (contrat d'abord).** Créer `app/Services/Delivery/Contracts/DeliveryProvider.php`
   avec `quote(adresse): Devis` / `dispatch(Order): Course` / `cancel()` / `track()`, et deux
   implémentations : `InHouseProvider` (l'existant) et `UberDirectProvider` **stub** qui lève
   « accès non accordé ». Le jour où Uber répond, une seule classe est à remplir.
2. **Bascule manuelle — la valeur immédiate.** Étendre le point unique
   `OrderService::selectDeliveryBoy()` (`:3163`) : au lieu d'exiger un `delivery_boy_id`,
   accepter un `delivery_mode ∈ {in_house, uber}`. En mode `uber` : persister le mode +
   tracer dans la chaîne d'audit (le motif de la trace existe déjà, `:3220-3233`), afficher
   « confiée à Uber » en caisse, et laisser la commande suivre
   `PREPARED → OUT_FOR_DELIVERY → DELIVERED`. Le patron commande la course **à la main dans
   l'application Uber** en attendant l'API. Colonne à ajouter : `orders.delivery_provider`
   (nullable, défaut `in_house`).
3. **Tarif par adresse : déjà là, à finir.** `DeliveryFeeService` est correct mais ses
   colonnes de branche se peuplent en SQL. Livrer l'**écran d'admin** manquant
   (`OrderSetupRequest.php:38`) et prévoir un **supplément « bascule Uber »** paramétrable,
   pour ne pas vendre à 4 € une course qu'Uber facture davantage. Sans cela, chaque bascule
   se fait à perte inconnue.
4. **Ne pas fusionner `fix/uber-order-fetch-v2` « pour préparer Uber Direct »** : elle ne
   contient rien de Direct, et ses 10 commits de Marketplace embarquent leur propre risque
   de go-live. Décision séparée.
