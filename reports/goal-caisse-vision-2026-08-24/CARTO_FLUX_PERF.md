# CARTO FLUX & PERF — Suivi des commandes caisse

Lecture seule. Mesures réelles sur `foodking_e2e` (3 663 lignes `order_items`, 6 598 commandes).
Date : 2026-08-24. Aucune modification de fichier.

---

## 1. Chaîne d'alimentation du suivi caisse

| Maillon | file:line |
|---|---|
| Écran | `resources/js/router/modules/posOrderRoutes.js:52` → `/admin/pos-orders-tracker` |
| Composant | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` (3 326 lignes) |
| Appel | `PosOrdersTrackerComponent.vue:1518` `this.$store.dispatch('posOrder/lists', {paginate:1, per_page:100, lean:1, from_date, to_date, vuex:false})` |
| Store | `resources/js/store/modules/posOrder.js:177-183` → `axios.get('admin/pos-order' + query)` |
| Route | `routes/api.php:1358` `Route::get('/', [PosOrderController::class, 'index'])` (préfixe `pos-order`) |
| Contrôleur | `app/Http/Controllers/Admin/PosOrderController.php:250-262` — `abort_unless(can('pos-orders')||can('pos'))`, puis `$this->orderService->list($request)` |
| Marquage NF525 | `PosOrderController.php:281+` `markSealed()` → `app/Services/Order/SealedOrderGuard.php:174` `sealedOrderIds()` (1 requête groupée) |
| Service | `app/Services/OrderService.php:133` `list()` — eager-load, filtres, pagination |
| Resource | `app/Http/Resources/SimpleOrderResource.php:21` `toArray()` ; lignes → `:155` `'order_items' => resolveItemsForTracker()` (`:224`) et `:161` `has_instruction` (`:249`) |

**Endpoints secondaires du même écran**
- `PosOrdersTrackerComponent.vue:1819` `GET admin/pos/counter-collect/pending` — compteur « en souffrance », TTL 5 min (`OLDER_PENDING_TTL_MS = 300000`, ligne 805).
- `PosOrdersTrackerComponent.vue:1656` `GET admin/pos-order/stale?per_page=50` — **à la demande** (ouverture du panneau, `toggleStalePanel:1672`), route `routes/api.php:1364`, `throttle:60,1`.

---

## 2. Cadence de rafraîchissement

Constantes : `PosOrdersTrackerComponent.vue:794` `POLL_WS_MS = 60000` · `:801` `POLL_NO_WS_MS = 5000` · `:813` `EVENT_STALE_MS = 35000` · `:821` `AGE_TICK_MS = 30000`.

Décision (`:1409-1419`) :
```
if (!realtimeConnected) return 5000;
eventsStale = (now - lastEventAt) > 35000;   // lastEventAt bumpé UNIQUEMENT par un event Echo (:1407)
boardEmpty  = orders.length === 0;
return (eventsStale || boardEmpty) ? 5000 : 60000;
```

**Requêtes/minute au repos — le régime réel est 12/min, pas 1/min.** `lastEventAt` n'est réarmé que par un event Echo livré (`_noteRealtimeEvent`, `:1406`, appelé depuis les 4 handlers `:1374-1394`). Entre deux commandes réelles (plusieurs minutes en service normal), `eventsStale` devient vrai au bout de 35 s → cadence 5 s → **12 req/min en permanence**. Le régime 60 s (1 req/min) n'existe que dans les ~35 s qui suivent chaque event. Un tableau **vide** force aussi 5 s.

Garde-fous présents :
- **Débounce WS** `:1506-1509` — rafale d'events → un seul fetch (trailing 250 ms).
- **Garde in-flight** `:1512-1515` + `:1613-1616` — jamais deux fetch concurrents ; une demande en attente est rejouée une seule fois.
- **Watchdog de cadence** `:1465-1472` — ticker 30 s qui réarme le `setInterval` si l'état de fraîcheur a changé (ne déclenche pas de fetch supplémentaire, sauf au passage en mode dégradé).
- **Back-off** sur `counter-collect/pending` en cas d'échec : re-tentative à 30 s au lieu de chaque poll (`:1834`).
- **Dédup GET en vol** global : `resources/js/shared/inflight-dedupe.js` (PROJECT_BRAIN.md:3792).
- ⚠️ **Aucune pause sur onglet caché** : `grep visibilitychange` = 0 occurrence dans le composant. Un écran de suivi laissé ouvert en arrière-plan continue à 12 req/min.

WebSocket : `_subscribeEcho()` `:1362` → canal `branch.{branchId}` (helper `onEvents(branchId, …)`), events `OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`, `OrderPaymentStatusChanged` (`:1374-1394`). Chaque event → `_debouncedFetch()`, jamais de mise à jour incrémentale : **tout event provoque un refetch complet de 100 commandes**.

---

## 3. Eager-loading réel — pas de N+1 sur la composition

`OrderService.php:150-156`, branche `lean=1` (celle du tracker) :
```php
$relations = $request->get('lean', 0) == 1
    ? ['transaction', 'orderItems.orderItem', 'user']
    : [ … 8 relations … ];
```
`orderItems.orderItem` **est** chargé. Trace SQL réelle du chemin `list(paginate=1, per_page=100, lean=1)` mesurée en tinker :

```
SQL_queries=6  ms=77  bytes=97935
q0 select count(*) from `orders` …
q1 select * from `orders` … order by id desc limit 100
q2 select * from `transactions` where order_id in (…)
q3 select * from `order_items` where order_id in (…)
q4 select * from `items` where id in (…)
q5 select * from `users` where id in (…)
```
(+1 requête `SealedOrderGuard::sealedOrderIds` côté contrôleur → 7 au total.)

**Les variations/extras ne sont PAS une relation : ce sont des COLONNES de `order_items`** — `app/Models/OrderItem.php:71-73` (`item_variations`, `item_extras`, `composition_snapshot` fillable) et casts `:101-103` (`composition_snapshot => 'array'`). La requête `q3` fait `select *` : **ces colonnes sont déjà rapatriées aujourd'hui**, payées et jetées. Enrichir `resolveItemsForTracker()` avec la composition coûte **0 requête SQL supplémentaire et 0 octet SQL supplémentaire**.

Piège écarté par la mesure : les lignes **héritées** sans snapshot ne portent que des `id` dans `item_variations` (doc `app/Http/Resources/OrderItemResource.php:64-72`) ; résoudre leurs noms exigerait des lookups `ItemVariation`/`ItemExtra` → N+1. Mesure : `snap_null=39` sur 3 663, dont **`legacy_var=0`, `legacy_extra=0`**. Risque nul sur ce jeu de données ; il redeviendrait réel si l'implémentation appelait une relation `Item` pour résoudre des noms.

---

## 4. Poids du payload

Plafond d'affichage : **100 commandes** (`PosOrdersTrackerComponent.vue:1526` `per_page: 100`), fenêtre = journée de **service** (`_todayRange()` `:1637` → `resources/js/helpers/posServiceDay.js`, la veille reste visible avant 5 h). Le panneau « en souffrance » est plafonné à 50 (`:1656`).

Mesures réelles (`SimpleOrderResource::collection(…)->resolve()`, JSON non compressé, 100 dernières commandes) :

| Grandeur | Mesure |
|---|---|
| Payload actuel, 100 commandes | **97 231 o** (~95 Ko) |
| Par commande | **972 o** |
| Lignes de commande | 128 (moy. 1,08 ligne/commande, max 5) |

Structure réelle de `composition_snapshot` (plus grosse ligne de la base, tacos) : `{"lines":[{quantity, line_total, unit_price, attribute_id, variation_id, attribute_name, variation_name} ×4], "addons":[], "extras":[{extra_id, quantity, extra_name, line_total, unit_price} ×5], "captured_at", "schema_version"}` — **1 198 o** pour cette ligne ; moyenne base 178 o, `item_variations` moy. 16 o / max 468 o, `item_extras` moy. 7 o / max 277 o.

Surcoût mesuré de l'enrichissement, deux formes :

| Forme expédiée | 100 dernières cmd | 100 commandes les PLUS composées (pire cas) |
|---|---|---|
| **Compacte** (`nom`, `attribut`, `qté` seulement) | +3 221 o soit **+32 o/commande (+3,3 %)** | +22 938 o soit **+229 o/commande** ; max 1 commande = **538 o** |
| **Snapshot brut** (passthrough `composition_snapshot`) | +26 697 o soit **+267 o/commande (+27 %)** | +120 945 o soit **+1 209 o/commande (+124 %)** |

Traduction en débit, régime réel 12 req/min : aujourd'hui ~95 Ko × 12 = **1,14 Mo/min par écran**. Forme compacte pire cas : **+275 Ko/min**. Passthrough brut pire cas : **+1,45 Mo/min, soit un doublement du trafic caisse**.

---

## 5. Garde-fous existants (contraintes à respecter)

- **`tests/e2e/pos-request-budget.spec.js:33,36`** — banc permanent : `BUDGET_OUVERTURE = 32` requêtes à l'ouverture, `BUDGET_REPOS_PAR_MINUTE = 12` au repos, 0 doublon simultané.
- **Plafond serveur** — `app/Providers/RouteServiceProvider.php:52-70` : `throttle:api` = 120/min, désormais **par appareil** (`X-Device-Id`) + un plafond par compte. Contexte du correctif dans PROJECT_BRAIN.md:3792 (« Trop de requêtes » à 4 écrans sur le même login).
- **POSPERF-07-tracker-unbounded** (`reports/goal-caisse-stock-sync-2026-07-22/REGISTRE.md:25`) — c'est le défaut d'origine : `per_page:100` ignoré faute de `paginate`, donc `->get('*')` sur toute la journée avec 8 relations. Verrouillé par `tests/Feature/Pos/PosOrderListLeanPaginationTest.php` (4 specs : pagination bornée, `lean` trime bien branch/transaction.order/user.roles/user.media/media/category, non-`lean` intact).
- **POSPERF-04-idle-hammer** (REGISTRE.md:32) + `tests/js/sentinels/posKioskPollingCadenceSentinel.spec.js` — sentinelle de cadence : socket connecté ⇒ 60 s même liste vide, sur les panneaux de `PosComponent`. Le tracker, lui, retombe encore à 5 s sur `boardEmpty` (`:1417`).
- **POSPERF-09-tracker-ws-stale** (REGISTRE.md:16) — P2 ouvert : socket up + worker mort ⇒ le tracker se croit temps-réel. C'est précisément ce qui rend le régime 5 s permanent.
- **`markSealed()`** (`PosOrderController.php:265-280`) — commentaire de contrat : le prédicat par commande aurait coûté 100 requêtes ; « mesuré : 9 requêtes / 31 ms au total ».
- **`resolveItemsForTracker()`** (`SimpleOrderResource.php:225-231`) — garde N+1 explicite : `relationLoaded('orderItems')` sinon `[]`, **jamais de lazy SELECT**. Tout ajout doit conserver cette garde.

---

## 6. Canaux d'entrée des commandes

| Canal | `source_surface` réel | Chemin de création | Visible dans le suivi caisse ? |
|---|---|---|---|
| **WEB** (site client) | `web` (253 en base) | `app/Services/FrontendOrderService.php:699-700` (`$isKioskMachineOrder ? 'kiosk' : 'web'`) | Oui, onglet 🌐 « En ligne » (`sourceOf` `:1951`) |
| **BORNE** (kiosk) | `kiosk` (1 286) | `FrontendOrderService.php:700` + `:1336` | Oui, onglet 🖥️ (`:1946`) |
| **TÉLÉPHONE** | `phone` (**7 en base — le canal EXISTE**) | `app/Services/OrderService.php:1273` `$this->order->source_surface = $request->boolean('phone_order') ? 'phone' : 'pos'` ; drapeau validé `app/Http/Requests/PosOrderRequest.php:126,249-253` ; CTA caisse `PosComponent.vue:1531` (`label.pos_phone_order_cta` = « Commande téléphone ») | Oui, **mais fondu dans l'onglet 🛒 Caisse** : `sourceOf()` `:1944-1958` ne connaît pas `'phone'` → heuristique `order_type` (10 ou 15) → `'pos'`. Aucun badge 📞 dans le tracker (il existe dans `PosComponent.vue:499-503`, pas ici) |
| **PLATEFORME (Uber)** | `uber_eats` (23) | `app/Services/Uber/UberOrderIngestor.php:140` ; entrée `app/Http/Controllers/Webhook/UberWebhookController.php` ; crée directement en `OrderStatus::ACCEPT` + `PaymentStatus::PAID` | Oui, **mal classé** : `order_type` = 5 (DELIVERY, 17 cmd) ou 10 (TAKEAWAY, 6 cmd) → `sourceOf()` retourne `'pos'` → onglet 🛒 Caisse. Reconnu ailleurs : `isPlatformOrder()` `:1985-1988` (bouton ticket promo) |
| Livraison (dérivé) | `delivery` (42) — posé automatiquement par `app/Models/Order.php:175-176` si `order_type=DELIVERY` | — | Onglet 🌐 (`:1951` traite `delivery` comme web dans `isWebPending` `:1869`, mais `sourceOf` ne le mappe pas → 🛒) |

Onglets disponibles : `sourceTabs()` `:1027-1034` = `all / pos / kiosk / online`. **Il n'existe donc que 4 onglets pour 6 surfaces réellement présentes en base** — `phone`, `uber_eats`, `delivery`, `wheel` tombent tous dans 🛒 « Caisse ».

---

## Budget de perf à respecter

Toute évolution du flux de suivi doit tenir ces 5 contraintes, chacune vérifiable :

1. **≤ 12 requêtes/minute au repos et ≤ 32 requêtes à l'ouverture**, écran de suivi inclus — banc `tests/e2e/pos-request-budget.spec.js:33,36`. Aucun endpoint supplémentaire ne doit être ajouté au chemin de poll ; un enrichissement doit voyager **dans la réponse existante**, jamais dans un second appel par commande.
2. **≤ 8 requêtes SQL par tick de poll** (référence mesurée : 6 pour `OrderService::list` + 1 `sealedOrderIds` = 7). Toute forme d'enrichissement doit conserver la garde `relationLoaded('orderItems')` de `SimpleOrderResource.php:225` et n'ajouter **aucune** relation au set `lean` de `OrderService.php:151-155`. Vérification : `DB::listen` sur le chemin `list(paginate=1, per_page=100, lean=1)`.
3. **≤ +150 octets par commande, et ≤ 600 octets pour la commande la plus composée** — c'est-à-dire la forme **compacte** (`variation_name`, `attribute_name`, `quantity`, `extra_name`) et **pas** le passthrough de `composition_snapshot`. Mesuré : compact = +32 o/cmd en moyenne, +229 o/cmd et 538 o max en pire cas ; brut = +267 o/cmd, +1 209 o/cmd en pire cas. Cible de payload total : **≤ 125 Ko pour 100 commandes** (référence actuelle 97 231 o).
4. **≤ 100 ms côté serveur pour la page de 100 commandes** (référence mesurée : 77 ms / 97 935 o). Re-mesurable avec le même harnais tinker.
5. **Zéro régression des sentinelles de charge** : `tests/Feature/Pos/PosOrderListLeanPaginationTest.php` (4 specs), `tests/js/sentinels/posKioskPollingCadenceSentinel.spec.js`, `tests/js/sentinels/posOssFetchCoalesceSentinel.spec.js` — et le plafond serveur `throttle:api` 120/min par appareil (`RouteServiceProvider.php:52-70`) doit rester tenable à 4 écrans simultanés.

**Deux constats hors-budget, à arbitrer** (non corrigés ici, lecture seule) :
- La cadence réelle est **5 s / 12 req/min quasi en permanence** (et non 60 s), parce que `lastEventAt` n'est réarmé que par un event Echo : en service normal, plus de 35 s séparent deux commandes. Le tableau consomme donc **à lui seul tout le budget de repos** de l'écran caisse. À vérifier au navigateur avant tout enrichissement.
- **Aucune pause sur onglet caché** (`visibilitychange` absent du composant) : un second écran de suivi laissé ouvert double la consommation sans rien afficher.
