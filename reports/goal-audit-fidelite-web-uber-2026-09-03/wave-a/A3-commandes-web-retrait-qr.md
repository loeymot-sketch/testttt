# A3 — Commandes web, retrait comptoir, QR de validation

Audit LECTURE SEULE. `testttt`, branche `pos/category-first-caisse-2026-06-23`, HEAD `a91f95e2e`.
Base réelle : `foodking_e2e` (`.env:14`). Chaque affirmation = `fichier:ligne` lu ou requête SQL exécutée.

---

## § Ce qui EXISTE

### 1. Canaux — UNE SEULE TABLE, deux modèles

`app/Models/FrontendOrder.php:20` → `protected $table = "orders";`
`DESCRIBE frontend_orders` → `ERROR 1146 … doesn't exist`. `SHOW TABLES LIKE '%order%'` ne la liste pas.
`Order` et `FrontendOrder` sont **deux façades sur la même table `orders`** — le code le dit : `app/Listeners/AwardLoyaltyPointsOnDelivery.php:93` « FrontendOrder::$table = "orders" — physical table is always "orders" ». D'où des hooks `creating` **dupliqués** : `source_surface` (`Order.php:175` / `FrontendOrder.php:31`), `tracking_token` (`Order.php:190` / `FrontendOrder.php:45`), motif expliqué `FrontendOrder.php:36-42`.

**La colonne de canal est `source_surface varchar(20) NULL`** (`DESCRIBE orders`). Il existe aussi `order_type tinyint NOT NULL DEFAULT 5` et une colonne `source varchar(191)` héritée, incohérente (valeurs numériques).

`SELECT source_surface, COUNT(*) FROM orders GROUP BY 1` (3609 lignes) :
`pos` 1823 · `kiosk` 1277 · `web` 253 · `delivery` 42 · `uber_eats` 23 · `phone` 7 · `mobile` 1 · **`NULL` 183**.

**Trois dérives mesurées :**
- `order_type=30` sur **1338 lignes** alors que `app/Enums/OrderType.php` ne définit que `DELIVERY=5, TAKEAWAY=10, POS=15, DINING_TABLE=20, KIOSK=25`. Les valeurs 1, 2, 3, 4, 30 en base ne correspondent à aucune constante.
- `source_surface` NULL sur 183 lignes ; une valeur `'POS'` majuscule (2 lignes) — tolérée côté JS (`toLowerCase()`), pas côté SQL (`routes/api.php:1148` `whereIn('source_surface',['web','delivery'])`, sans normalisation).
- **`order_type` n'est PAS le canal** : une commande `web` est presque toujours `order_type=10` (TAKEAWAY). Le canal ne se lit QUE dans `source_surface`.

Écriture : `app/Services/FrontendOrderService.php:700`
`$this->frontendOrder->source_surface = $isKioskMachineOrder ? 'kiosk' : 'web';`
Ternaire binaire : tout ce qui n'est pas une borne physique devient `'web'`, y compris l'app mobile. **Aucun `app/Enums/SourceSurface.php` n'existe.**

### 2. Flux de commande site web

`routes/web.php:43-46` : `Route::redirect('/', '/login')`, idem `/menu`, `/offers`, `/offres`. Commentaire `routes/web.php:38` : **« commande en ligne hors périmètre V1 »**. Ce backend **ne sert plus de vitrine client**.
Création par API seule : `routes/api.php:1987` → `Route::post('/', [FrontendOrderController::class,'store'])` (préfixe `order`, `auth:sanctum`, `throttle:kiosk-orders`, `require_customer_phone`, `idempotency`).
Contrôleur `app/Http/Controllers/Frontend/OrderController.php:110` → `frontendOrderService->myOrderStore()`. Service `app/Services/FrontendOrderService.php`.
État initial : **PENDING / UNPAID** — `routes/api.php:1132` « toute commande web = règlement au comptoir → créée PENDING/UNPAID + source_surface='web' ». Confirmé : sur 253 web, 81 en `status=1`, 124 en `status=19` (purge janitor).

### 3. Statuts RÉELS (`app/Enums/OrderStatus.php`, recopié)

`PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10, DELIVERED=13, CANCELED=16, REJECTED=19, RETURNED=22`

Transitions, recopiées de `app/Domain/Order/OrderStateMachine.php:37-122` (ZONE GELÉE, lue seulement) :

| De | Vers |
|---|---|
| `PENDING` | ACCEPT, CANCELED, REJECTED |
| `ACCEPT` | PREPARING, CANCELED · +DELIVERED si `hasPermissionTo('pos')` · +RETURNED si `pos-refund` |
| `PREPARING` | PREPARED, CANCELED · +DELIVERED si `pos` · +RETURNED si `pos-refund` |
| `PREPARED` | OUT_FOR_DELIVERY, **DELIVERED** · +CANCELED inconditionnel (LOCK-OSM-CANCEL-AFTER-READY) · +RETURNED si `pos-refund` |
| `OUT_FOR_DELIVERY` | DELIVERED, CANCELED |
| `DELIVERED` | RETURNED **uniquement** |
| `CANCELED`/`REJECTED`/`RETURNED` | rien, sauf rôle `Admin` (`:117`) |
| `$from === $to` | toujours autorisé (`:31`) |

**AUCUN statut « prêt à retirer », « retiré » ni « validé ».** Aucune colonne `picked_up_at`, `collected_at`, `pickup_code` dans `DESCRIBE orders`.

### 4. Le QR côté COMMANDE — il existe, mais pointe dans l'autre sens

`app/Http/Controllers/Frontend/OrderController.php:84-95` `trackQr()` : SVG via `SimpleSoftwareIO\QrCode` (`size(320)`, `errorCorrection('H')`), contenu = `url('/suivi/'.$trackingToken)`.
Route `routes/api.php:1968` `GET order/track-qr/{trackingToken}` (`[A-Za-z0-9]{48}`, `withoutMiddleware('apiKey')`, `throttle:30,1`). Page `resources/js/router/modules/orderTrackingRoutes.js:13` `/suivi/:trackingToken`.
Identité : `orders.tracking_token varchar(64) UNIQUE`, `Str::random(48)` dans les hooks.

**C'est un QR de SUIVI pour le client, pas un QR de RETRAIT pour l'employé** : `app/Services/OrderTrackingService.php:25` « SELECT-only : zéro impact NF525, zéro écriture ». **Aucune route ne consomme un QR pour muter une commande.**

Couverture mesurée — `SELECT source_surface, COUNT(*), SUM(tracking_token IS NOT NULL) FROM orders GROUP BY 1` :
**`web` : 253 commandes, 0 avec token.** `pos` 1823→8, `kiosk` 1277→46, `delivery` 42→0, `uber_eats` 23→0. Hook du 2026-08-16, **jamais rétro-appliqué**.

Précédent réutilisable : `app/Services/Loyalty/LoyaltyQrSigner.php:14-36` — token `lqr.<payload>.<hmac>` HMAC-SHA256, anti-rejeu par `INSERT` sur `loyalty_qr_nonces_consumed` (`nonce varchar(64) UNIQUE`) avec capture de `QueryException` (pas de TOCTOU).

### 5. Où un employé validerait le retrait

**a) Module « Commandes en ligne » — existe, MASQUÉ en V1.**
`app/Http/Controllers/Admin/OnlineOrderController.php` (269 l., `permission:online-orders` `:35`), routes `routes/api.php:1508-1520`, SPA `resources/js/router/modules/onlineOrderRoutes.js:11` → `/admin/online-orders`, composants `resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue` + `OnlineOrderShowComponent.vue`.
Mais `resources/js/config/v1-hidden-modules.js:19` liste `'onlineOrders'` → retiré du nav (URL directe seulement). Pas la surface de retrait.

**b) La vraie surface : Suivi commandes caisse** — `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` (4258 l.)
- Onglets canal `:1333-1356` `sourceTabs()` → `all/pos/kiosk/online` + `phone/platform/delivery` ajoutés s'ils sont présents.
- `:2314-2346` `sourceOf()` → `source_surface==='web'` ⇒ onglet `online` (`:2321`, WEB-TRACKER-VISIBILITY 2026-07-20).
- `:34-41` pastille `tracker-web-pill` « 🌐 N web à traiter ». `:527` `acceptWebOrder()`, temps de prépa `:510`.

**c) Deux files serveur dédiées** (`abort_unless(auth()->user()?->can('pos'), 403)`) :
`routes/api.php:1139` `GET web-orders/pending` (`whereIn('source_surface',['web','delivery'])` + `status=PENDING`, exclut CARD+UNPAID) ; `routes/api.php:1196` `GET web-orders/paid` (PAID + `status IN (ACCEPT,PREPARING)`). Voisine : `routes/api.php:1058` `counter-collect/pending`.

**d) Le patron « scanner OU saisir » existe déjà, complet.**
`resources/js/components/admin/pos/PosLoyaltyIdentifyModal.vue` : téléphone `:45`, **code au clavier `:63`** (`loy-id-code-input`), **QR caméra `:77-106`**. Lecteur = `BarcodeDetector` natif (`:830`), garde de capacité `:509-510`, repli explicite `:80`. Aucune bibliothèque externe.

### 6. Fidélité — le crédit existe, mais pas sur « retrait »

`app/Listeners/AwardLoyaltyPointsOnDelivery.php:27` sur `OrderStatusChanged` :
`:39` `$isKiosk = order_type ∈ {KIOSK, TAKEAWAY}` → déclenche sur `PREPARED` **ou** `DELIVERED` (`:42`) ; `:45` sinon `DELIVERED` seul. Idempotence par sentinelle atomique `-1` sur `orders.loyalty_points_awarded` (`:96-98`). `:80` `award()` détachée, **appelable sans événement** depuis 2026-08-19 (motif `:57-77` : 307 ventes caisse jamais créditées).

Une commande web est `order_type=10` (TAKEAWAY) → elle tombe dans la branche `$isKiosk` et **crédite dès `PREPARED`, avant que le client soit venu chercher.**

### 7. Tests (chemins vérifiés)

`tests/Feature/Pos/` : `WebOrdersPendingEndpointTest.php`, `WebOrdersPaidEndpointTest.php`, `WebOrderInlineAcceptTest.php`, `WebAcceptPreparationTimeTest.php`, `PosOperatorWebOrderPermissionTest.php`, `SimpleOrderResourceTrackerContractTest.php`
`tests/Feature/Frontend/` : `WebOrderExpectedTotalGuardTest.php`, `WebCardOrderPaidPathReleasesAllOrderTypesTest.php`, `WebCardOrderNotBroadcastBeforePaymentTest.php`, `FrontendOrderIdorStatusCodeTest.php`
`tests/Feature/Order/OrderTrackingTest.php` · `tests/js/orderTrackingPageComponent.spec.js` · `tests/e2e/test-e2e-goal-4chantiers-wave-D.spec.js`
**ABSENT** : aucun test de retrait/pickup/scan-commande (`grep -ri "pickup\|retrait" tests/Feature/` → rien).

---

## § Ce qui MANQUE pour la demande owner

1. **Canal fiable.** `source_surface` = chaîne libre, sans enum, 183 NULL, un `'POS'` majuscule, `order_type` désynchronisé. La séparation *affichée* est correcte ; la donnée *sous-jacente* n'est pas contrainte. → **ABSENT — `app/Enums/SourceSurface.php`** + migration de normalisation + sentinelle.
2. **Statut de retrait.** Ni « prêt à retirer » ni « retiré ». → toucher `OrderStateMachine.php` = **ZONE GELÉE §7, gate owner**.
3. **Code de retrait + son QR.** Aucune colonne `pickup_code`. `tracking_token` existe mais : (a) **NULL sur 253/253 web** ; (b) 48 caractères — **intapable à la main**, ce qui contredit l'exigence de saisie manuelle ; (c) son QR ouvre une page publique, donc le connaître suffirait à valider — le réutiliser tel quel serait une faille. → **ABSENT — `app/Services/Order/PickupCodeService.php`**.
4. **Endpoint de validation.** Aucune route ne consomme un QR pour muter une commande (`grep scan routes/api.php` → stock, achats, Uber photo, fidélité ; jamais une commande). → **ABSENT — `app/Http/Controllers/Admin/OrderPickupController.php`**.
5. **Écran de retrait.** `/admin/online-orders` masqué ; le tracker sait *accepter* une commande web, pas *valider un retrait*. → **ABSENT — `resources/js/components/admin/pos/PosPickupValidateModal.vue`**.
6. **Fidélité rattachée au retrait.** Les points partent à `PREPARED` (`:39-42`), donc avant le retrait.

---

## § Le chemin le plus court

Le lecteur physique n'existe pas : **la saisie manuelle est le chemin principal, le scan n'est qu'un raccourci.** Patron déjà en production dans `PosLoyaltyIdentifyModal.vue` — le copier, ne rien inventer.

**1. Le code (aucun gate).** Migration additive : `orders.pickup_code varchar(8) NULL UNIQUE`, `picked_up_at timestamp NULL`, `picked_up_by bigint NULL`. Générer dans **les deux** hooks `creating` (`Order.php:190` **et** `FrontendOrder.php:45` — l'oubli du miroir est le défaut historique documenté `FrontendOrder.php:36-42`), uniquement si `source_surface='web'`. **6-8 caractères sans I/O/0/1**, tapables en 3 s. Ne pas réutiliser `tracking_token`.

**2. L'endpoint.** `POST /api/admin/order/pickup/validate` (`abort_unless(can('pos'))`, `idempotency`, `idempotency.branch`, throttle), corps `{ code }` ; résout `pickup_code` **ou** `tracking_token`. Anti-rejeu : `->whereNull('picked_up_at')->update([...])` conditionnel, calqué sur la sentinelle `-1` de `AwardLoyaltyPointsOnDelivery.php:96`. 409 explicite si déjà retirée.

**3. La transition — sans zone gelée.** L'arête `PREPARED → DELIVERED` **existe déjà** (`OrderStateMachine.php:100`) et n'exige **aucune permission spéciale** : `OrderStateMachine::apply($order, DELIVERED, $user)` passe tel quel. **La validation de retrait est donc livrable SANS toucher `OrderStateMachine.php`.** N'ouvrir un `PICKED_UP` distinct que si l'owner le réclame, avec LOCK.

**4. La fidélité.** Appeler `app(AwardLoyaltyPointsOnDelivery::class)->award($order)` depuis l'endpoint (méthode publique `:80`). Puis **restreindre** la branche `$isKiosk` de `:39` pour qu'elle n'englobe plus le canal web — sinon la sentinelle aura déjà consommé le crédit à `PREPARED` et la validation de retrait ne créditera rien. C'est le point le plus subtil du chantier.

**5. L'écran.** Modale dans le tracker (onglet `online` déjà présent `:1338`) : champ code **autofocus** + bouton caméra optionnel (`BarcodeDetector`, repli si absent). Le lecteur physique se branchera en émulation clavier et remplira le même champ — **zéro code supplémentaire le jour du branchement.**

**6. Le QR côté site.** `lecayenne.fr` (dépôt séparé) affiche le `pickup_code` en clair **et** son QR ; réutiliser `OrderController::trackQr()` (`:84`) en variante encodant le `pickup_code`.

**7. Les bancs.** Rejeu (2ᵉ scan → 409), code inconnu → 404, autre branche → refus, commande `CANCELED` → refus, crédit fidélité exactement une fois, saisie manuelle sans caméra. → `tests/Feature/Order/PickupValidationTest.php` + sentinelle de normalisation `source_surface`. **Restaurer le défaut pour prouver que chaque banc mord** avant de le déclarer vert.
