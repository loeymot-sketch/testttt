# U1 — Architecture commande / panier / paiement / argent (avant Uber Direct)

Lecture seule. Chaque affirmation = `fichier:ligne` lu ou requête SQL exécutée en production
(`ssh lecayenne`, `/var/www/lecayenne`, `php artisan tinker`). Branche : `pos/category-first-caisse-2026-06-23`.

---

## 1. Modèle de commande

Une seule table `orders`. `app/Models/Order.php` et `app/Models/FrontendOrder.php` la partagent
(`FrontendOrder.php:27-32` et `Order.php:167-177` portent le MÊME hook `creating`, dupliqué à dessein).

`SHOW COLUMNS FROM orders` (prod) — colonnes livraison **déjà présentes** :

| colonne | type | peuplée en prod |
|---|---|---|
| `delivery_charge` | `decimal(19,6)` def. `0.000000` | **0 ligne > 0** (sur 963) |
| `delivery_boy_id` | `bigint` NULL | **0 ligne non nulle** |
| `delivery_time` | `varchar(191)` NULL | 464 lignes (texte d'heure, pas une durée) |
| `preparation_time`, `scheduled_at`, `is_advance_order` | | présentes |

Il n'y a **aucune colonne d'adresse sur `orders`**. Deux tables dédiées existent :

- `order_addresses` : `id, order_id, user_id, label, address, apartment, latitude, longitude, timestamps` — **0 ligne**.
- `addresses` : idem + `geocode_status varchar(20)` — **0 ligne**.

ABSENT : aucune table `deliveries`, `couriers`, `uber_direct_*`, aucun champ de suivi coursier.
La seule table « coursier » est `delivery_boy_cash_sessions` / `_movements` (caisse du livreur interne).

Casts : `Order.php:86-90` → `subtotal/discount/delivery_charge/total_tax/total` en `decimal:6`.

---

## 2. Le canal

`app/Enums/OrderType.php:7-11` — `DELIVERY=5`, `TAKEAWAY=10`, `POS=15`, `DINING_TABLE=20`, `KIOSK=25`.

Répartition réelle (prod, `GROUP BY order_type, source_surface`) :

```
type=5  surface=uber_eats  n=186   sum(delivery_charge)=0
type=10 surface=kiosk      n=274
type=10 surface=phone      n=313
type=10 surface=pos        n=151
type=10 surface=web        n=38
type=10 surface=uber_eats  n=1
```

Conclusion mesurée : **aucune commande livraison « maison » n'a jamais existé**. Les seules
`order_type=5` viennent de l'ingestion Uber Eats. La valeur `source_surface='delivery'` a **0 ligne**,
alors même que `Order.php:175-176` / `FrontendOrder.php:31-32` la forcent automatiquement dès que
`order_type === OrderType::DELIVERY` et que la surface est vide. C'est donc bien ainsi qu'une
livraison serait marquée aujourd'hui — mécanisme en place, jamais déclenché.

Le web envoie `order_type=10` : `FrontendOrderService.php:699-701` pose alors
`source_surface = $isKioskMachineOrder ? 'kiosk' : 'web'`. Aucune liste blanche ne valide
`source_surface` dans `OrderRequest` (grep vide) — c'est un `varchar(20)` libre.

---

## 3. Parcours de commande web

1. `routes/api.php:1987` — `POST /api/frontend/order` → `FrontendOrderController@store`,
   middlewares `['throttle:kiosk-orders', 'require_customer_phone', 'idempotency']`.
2. `app/Http/Controllers/Frontend/OrderController.php:113-116` → `frontendOrderService->myOrderStore($request)`.
3. FormRequest `app/Http/Requests/OrderRequest.php` :
   - `authorize()` 39-86 : exige `tokenCan('kiosk:order')` (les jetons client `['*']` passent).
   - `prepareForValidation()` 88-130 : **c'est ici que le prix de la livraison est décidé**
     (§5 ci-dessous) — jamais depuis le corps client.
   - `rules()` 137+ : `delivery_charge` 152, `delivery_distance_km` 157, `expected_total` 170,
     `address_id` 180, `delivery_time` 184 — tous conditionnels à `$isDelivery`.
   - Garde d'activation : **`OrderRequest.php:295`**.
4. `app/Services/FrontendOrderService.php:154` `myOrderStore()` :
   - `:345-346` — si `order_type !== DELIVERY`, `delivery_charge` est écrasé à 0 (anti-payload forgé).
   - `:367-374` — `PricingService::calculateOrder(PricingRequest::forKiosk(...))`, dernier argument
     `(float) $this->frontendOrder->delivery_charge`.
   - **Le total est arrêté en `:621-642`**, dans la closure `saveFrontendOrderWithQueueNumber` :
     `total_tax`, `subtotal`, `discount`, puis `:631-636` la remise « livraison offerte »
     (`Settings delivery.free_delivery_above`), puis `:638-642`
     `total = round(subtotal (+ tax si mode HT) + delivery_charge − discount, 2)`.
   - `:657-666` — garde `expected_total` : 422 si l'écart serveur/client dépasse 1 centime.
   - `:704+` — snapshot d'adresse dans `order_addresses` si `address_id` (garde IDOR).

Côté dépôt séparé Vercel : `/Users/1millnonstop/Downloads/web/api.js:432` envoie `order_type: o.orderType || 10`,
et **la branche livraison est déjà écrite** en `:440-448` (`delivery_distance_km`, `delivery_time`,
`delivery_charge`, POST avec `idempotencyKey`). `api.js:39-45` porte un miroir client du barème.

---

## 4. L'argent — convention réelle

**Décimal, pas centimes.** Sans jugement, le constat :

- `orders` : `decimal(19,6)` ; `order_items` : `price`, `discount`, `tax_amount`,
  `item_variation_total`, `item_extra_total`, `total_price` tous en `decimal(19,6)`.
- Modèles : casts `decimal:6` (`Order.php:86-90, 104`).
- En PHP : **`float`** partout. `app/Services/Pricing/PricingRequest.php:20`
  `public readonly float $deliveryCharge`. Arrondi `round(..., 2)` à l'écriture.
- Seule conversion « format » : `Mollie.php:186` `number_format((float) $order->total, 2, '.', '')`
  (chaîne à 2 décimales exigée par Mollie).

**ABSENT** : aucun stockage ni calcul en entiers-centimes nulle part dans la chaîne commande.

Comment les frais de livraison entrent dans la zone gelée `PricingService.php` : **par un seul point**,
l'argument `deliveryCharge` du DTO. `PricingService.php:347` `$delivery = $req->deliveryCharge;` →
`:352` / `:354` (`rawTotal = subtotal (+tax) + delivery − discount`) → `:367` renvoyé dans `PricingResult`.
Les quatre fabriques du DTO le prennent en dernier paramètre : `PricingRequest.php:30 forWeb`,
`:50 forPos`, `:70 forTable`, `:90 forKiosk`. **Le service gelé ne calcule jamais un frais de livraison,
il ne fait que l'additionner.** Aucune modification de la zone gelée n'est nécessaire pour Uber Direct.

---

## 5. Frais de livraison actuels

- `app/Services/Delivery/DeliveryFeeService.php:26-56` — `fromDistanceKm($km, ?Branch)` :
  si `delivery_fee_base` + `_per_km` + `_minimum` sont toutes non nulles →
  `round(max(minimum, base + per_km × ceil(distance − free_km)), 2)` (`:43-50`) ;
  sinon repli hérité `max(5, ceil(d/5)×5)` (`:55`).
- `app/Services/Delivery/DeliveryQuoteService.php:32-87` — `quoteForAddress()` : refuse si
  `geocode_status !== OK` (`:34-38`), exige des coordonnées valides (`:46-54`), impose le polygone
  `branches.zone` en ray-casting (`:71-74`), et renvoie `['distance_km', 'delivery_charge']` (`:76-86`).

**Appelants** : `OrderRequest.php:108` (adresse enregistrée) et `:126` (repli distance) — donc **oui,
le parcours web les atteint** ; `PosOrderRequest.php:55` et `Admin/PosController.php:240` pour la caisse.

**La livraison est éteinte — confirmé.** Requête prod sur `settings` :

```
order_setup_delivery              payload {"$cast": null, "$value": 10}   → Activity::DISABLE
free_delivery_above               {"$value": 0}
order_setup_basic_delivery_charge {"$value": "1"}   (legacy, non utilisé par DeliveryFeeService)
order_setup_free_delivery_kilometer {"$value": "2"} (legacy)
```

`app/Enums/Activity.php:7-8` : `ENABLE=5`, `DISABLE=10`. Le refus serveur est en
`OrderRequest.php:295` (miroirs : `PosOrderRequest.php:306`, `TableOrderRequest.php:61`).
Migration `database/migrations/2026_07_27_093000_disable_delivery_until_launch.php:25-26` (`up`),
`:31-32` (`down`).

**Pour rallumer** : `Settings::group('order_setup')->set(['order_setup_delivery' => Activity::ENABLE])`
(5) — ou l'écran admin `OrderSetupComponent.vue:71-79`. Décider explicitement `free_delivery_above`
(0 aujourd'hui = pas d'offerte). Le barème vit sur `branches` : en prod **branche 1 =
base 3.00 / per_km 2.00 / minimum 4.00 / free_km 3.00** — à noter, le miroir client du dépôt Vercel
(`api.js:42-45`) annonce base 4 / per_km 1 / free_km 5 : **les deux divergent**, le backend fait foi.

---

## 6. Paiement

Passerelles réellement actives — table `payment_gateways` (prod) :
`1 Cash On Delivery status=5`, `2 Credit status=5`, `3 Paypal status=10`, `4 Stripe status=10`
(5 = actif, 10 = inactif). Stripe est en plus verrouillé par `config/payment.php:49-56`.

Mollie est actif : `config/payment.php:115` (`MOLLIE_ENABLED`) et `.env` prod
`MOLLIE_ENABLED=true`, `MOLLIE_API_KEY` présent, `MOLLIE_REDIRECT_URL=https://www.lecayenne.fr`.
(`payment.web_payment_v1.enabled=false`, `config/payment.php:14-19`, ne concerne que l'ancien flux Blade.)

Séquence :

1. `routes/api.php:2019` `POST /api/frontend/order/{frontendOrder}/mollie-checkout`
   → `MolliePaymentController@checkout` (+ `:2016` `applepay-session`, feuille Apple Pay native).
2. `app/Http/PaymentGateways/Gateways/Mollie.php:110-195` `createPayment()` :
   montant = `$order->total` **scellé serveur** (`:186`), `webhookUrl` = `route('webhooks.mollie')` (`:190`),
   `metadata.order_id` (`:192-194`). Avec `cardToken` / `applePayToken` / `googlePayToken`
   (`:157-170`), **la création EST l'encaissement — capture directe, pas d'autorisation différée**
   (cf. commentaire `routes/api.php:2001-2003`). Rien n'est jamais posé `PAID` ici (`Mollie.php:241-248`).
3. **Le serveur apprend le paiement de façon fiable UNIQUEMENT par le webhook** :
   `routes/api.php:176-178` `POST /api/webhook/mollie` → `Mollie::handleWebhook`.
   Le body ne porte qu'un `id` ; le statut est **re-fetché chez Mollie** (`:329-330`) — jamais cru sur parole.
   L'écriture décisive est **`Mollie.php:605-608`**, sous `DB::transaction` + ligne verrouillée :
   `payment_status = PaymentStatus::PAID` (`app/Enums/PaymentStatus.php:7`), `transaction_id`, `card_type='mollie'`.
   Gardes en amont : montant (`:492`), commande déjà payée → auto-remboursement du 2ᵉ débit (`:537-552`),
   commande terminale (`:590`), remboursée (`:571`).
4. Après-coup : `Mollie.php:636-670` appelle `FrontendOrderService::finalizePaidKioskOrder()`
   (best-effort, jamais de 500). Ce service (`FrontendOrderService.php:1525`) alloue le numéro fiscal,
   promeut la commande et **dispatche `OrderCreated` en `:1759`**, à l'intérieur de la transaction
   (afterCommit) — c'est le signal « payée ET libérée en cuisine ».
   Note : son gate accepte déjà `source_surface ∈ {web, delivery}` avec `payment_method = CARD`
   (`FrontendOrderService.php:1552-1554`) — la livraison web y a été explicitement prévue.
5. Second chemin `PAID` (borne / TPE) : `routes/api.php:1997` `payment-confirm`, miroir cité en `Mollie.php:604`.

---

## 7. Idempotence — mécanisme réutilisable

**Requêtes entrantes** — `app/Http/Middleware/IdempotencyKeyMiddleware.php` (gelé) :
portée `(branch_id, user_id, sha256(key))` (`:19-23`, `:77-81`), empreinte du corps
`sha256` (`:76`), rejeu dans le TTL → réponse 2xx re-servie, **409 si le même en-tête revient avec un
corps différent** (`:86-93`, `:115`), verrou « pending » anti-course (`:100-128`),
`resolveBranchId()` (`:182-219`). Prod : `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`, `IDEMPOTENCY_FAIL_OPEN=false`,
TTL 86400 s.

**Webhooks entrants** — table `webhook_events`, index vérifié en prod :
`uk_webhook_provider_id` UNIQUE sur `(provider, webhook_id)`.
Motif Mollie (`Mollie.php:343-373`) : `WebhookEvent::firstOrCreate(['provider','webhook_id'])`,
`catch QueryException` + `isUniqueViolation()` (`:679-687`) → `200 already_processing` ;
`!wasRecentlyCreated` → `200 duplicate_ignored`. La clé porte **le statut** :
`webhook_id = "{payment_id}:{status}"` (`:349`), avec dérivation d'un statut `refunded` distinct
(`:337-341`) pour que remboursement et paiement ne se confondent pas.
Échec de traitement → `markFailed` + 500 pour que Mollie rejoue (`:384-387`).
Même motif déjà éprouvé côté Uber Eats : `app/Http/Controllers/Webhook/UberWebhookController.php:37-141`,
signature HMAC-SHA256 `X-Uber-Signature` (`:316-327`), tombstone d'annulation en `insertOrIgnore` (`:222-229`).
`app/Models/WebhookEvent.php:51-60` (constantes `PROVIDER_*`, `STATUS_*`), `:101` `markProcessed`, `:111` `markFailed`.
ABSENT : pas de constante `PROVIDER_UBER*` — le contrôleur Uber écrit la chaîne `'uber_eats'` en dur.

Pour qu'un rejeu ne crée jamais deux courses : insérer une ligne `webhook_events`
(`provider='uber_direct'`, `webhook_id` porteur du statut) **avant** tout appel sortant, dans la même
transaction que l'écriture de l'identifiant de course ; un doublon lève l'UNIQUE et sort en 200.

---

## Points d'accroche pour Uber Direct

Aucun de ces points ne demande de toucher une zone gelée.

1. **Activer la livraison** — `Settings order_setup.order_setup_delivery = Activity::ENABLE (5)`
   + trancher `delivery.free_delivery_above`. Le refus vit en `OrderRequest.php:295`.
2. **Prix de la course** — `OrderRequest.php:108-128` (`prepareForValidation`) est le seul endroit où
   `delivery_charge` est décidé côté serveur. Un devis Uber Direct s'y substitue à
   `DeliveryQuoteService::quoteForSavedAddress` sans rien changer en aval : le montant redescend tel
   quel dans `PricingRequest::deliveryCharge` et ressort du service gelé en `PricingService.php:347`.
3. **Adresse & coordonnées** — `order_addresses` (vide, prête) est écrite en
   `FrontendOrderService.php:704+`. `latitude`/`longitude` y sont déjà, c'est le point de retrait/dépose.
4. **Marquage du canal** — rien à écrire : `Order.php:175-176` pose `source_surface='delivery'` dès
   `order_type=5`. Prévoir que les tableaux de bord distinguent `'delivery'` de `'uber_eats'`
   (`Order.php:346-353` exclut déjà `uber_eats` du Z fiscal — la livraison maison, elle, DOIT y entrer).
5. **Création de la course, point le plus fiable** — après `Mollie.php:605-608` (PAID scellé sous verrou).
   Deux implantations possibles, par ordre de propreté :
   a. un **listener sur `OrderCreated`** (dispatché `FrontendOrderService.php:1759`, afterCommit,
      donc jamais sur une transaction annulée) filtrant `order_type=5` — c'est le motif déjà utilisé
      par `PrintKioskKitchenTicketOnOrderCreated` ;
   b. à défaut, dans le bloc best-effort `Mollie.php:636-670`, à côté de `finalizePaidKioskOrder`.
   Ne PAS s'accrocher au retour navigateur `MOLLIE_REDIRECT_URL` : il n'est pas fiable.
6. **Anti-doublon de course** — réutiliser `webhook_events` avec un `provider` dédié et le motif
   `firstOrCreate` + `isUniqueViolation` de `Mollie.php:343-373` / `:679-687`, et la signature HMAC
   de `UberWebhookController.php:316-327` pour les callbacks de suivi entrants.
7. **Client OAuth existant** — `app/Services/Uber/UberClient.php:21-40` (client_credentials, cache de
   jeton, retry 401 en `:98-118`) et `config/uber.php:14-34` sont réutilisables ; ils sont aujourd’hui
   câblés Eats seuls — `scopes = "eats.store eats.order"` (`config/uber.php:22`) et `endpoints` tous en
   `/v1/eats/...` (`:29-34`) : il faut y ajouter le scope et les endpoints Uber Direct, rien d’autre.
8. **Suivi de course** — ABSENT. Aucune colonne ni table ne peut porter un `delivery_id` Uber, un
   statut coursier ou une URL de suivi : c'est le seul ajout de schéma réellement nécessaire.
