# VERIFY-09 — Paiements / Idempotence (POS double-order, double-payment)

**Date :** 2026-04-20
**Mode :** AUDIT-ONLY (lecture seule du code applicatif ; écriture autorisée uniquement pour ce rapport)
**Spec :** `tasks/verify-2026-04-20/09_VERIFY_PAYMENTS_IDEMPOTENCY.md`
**Sources amont :** `AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`, `AUDIT_POS_SECTION_4_BACKEND_2026-04-18.md`, `F-SYNC-002`
**Méthode :**
1. Lecture parallèle des sources §2 (back : `PosOrderController`, `PosController`, `OrderService::posOrderStore`/`changePaymentStatus`, `PaymentService`, middlewares, routes ; front : `PosComponent.vue`, `PaymentComponent.vue`, `posOrder.js`).
2. Recherche grep ciblée : `Idempotency`, `idempotency-key`, `idempotent`, `concurrent`, `double`, `lockForUpdate`, `X-Idempotency`.
3. Challenge des hypothèses H1-H5.
4. Couverture des vérifications V1-V7 avec preuves `fichier:ligne`.
5. Construction de la matrice scénario × protection.
6. Re-vérification d'un précédent rapport homonyme (claims contradictoires : voir §0bis).

---

## 0. TL;DR

> **GLOBAL : WARN** (proche FAIL spec — V1 strict + V6 cassé).
>
> L'idempotence POS existe **uniquement au niveau applicatif** (pré-check `Order::where('idempotency_key', …)` + catch `QueryException 23000` sur la contrainte unique composite `(branch_id, idempotency_key)`). **Aucun middleware HTTP réutilisable** n'est enregistré. Le header `X-Idempotency-Key` n'est **pas obligatoire** côté serveur (aucune réponse `422` si absent — un client tiers/script qui l'omet perd toute protection). **`changePaymentStatus`** n'a **aucune** garde de transition : `PAID → PAID`, `PAID → UNPAID`, `UNPAID → PAID` répété sont tous acceptés et créent à chaque fois une ligne `ActionLog` + une entrée `AuditLog` HMAC. Aucun test Feature ne couvre la **route POS** `POST /api/admin/pos` 2× même clé. La protection front est un **single-flight** (`:disabled="loading.isActive"` + early-return) — pas un debounce, pas d'`aria-disabled`, pas de `data-testid` sur le bouton « Confirmer & imprimer ». La transaction DB couvre `Order + Items + ActionLog + AuditLog (discount)` mais **pas** la création de ligne `Transaction` (jamais appelée en POS) ni l'insert `domain_events` (Outbox écrit dans le listener post-`OrderCreated`, hors transaction d'origine).

### 0bis. Différences vs précédent rapport homonyme

Un rapport portant le même nom existait avant cet audit. Cet audit a **réécrit** le fichier après vérification indépendante. Corrections principales :

| Claim précédent | Vérification | Correction |
|---|---|---|
| « `PosComponent.vue` workspace = 0 octet » | `wc -l` → **2078 lignes** | Faux : fichier intact, le `M` git status reflète des édits sur d'autres fichiers, pas un truncate. Bouton « Order » (`PosComponent.vue:446-449`) et `orderSubmit()` (`:1439-1500`) sont présents. |
| « pré-check `OrderService.php:562` ignore `branch_id` → fuite cross-branche » | `Order` model a `BranchScope` global (`app/Models/Order.php:82`) | Pour staff (branch_id ≠ 0), le pré-check est **implicitement** scopé branche par BranchScope (`app/Models/Scopes/BranchScope.php:39`). **Reste exposé pour Admin** (`branch_id=0` → no filter, `BranchScope.php:33-36`) : un admin créant un POS pourrait recevoir l'order d'une autre branche s'il y a collision (très improbable avec un UUID, plausible avec le format `Date.now()_xxx_branchId` du front). |
| « V7 partiel : Outbox hors transaction » | Confirmé. Listener `PersistOrderCreatedToOutbox::handle` (`app/Listeners/PersistOrderCreatedToOutbox.php:14-40`) crée `DomainEvent` après commit Order et n'est pas réinclus dans une txn. | Maintenu. |

---

## 1. Périmètre & hypothèses H1-H5

| H | Énoncé | Verdict | Preuve |
|---|---|---|---|
| **H1** | Pas de middleware `idempotency-key` actif | **CONFIRMÉ** | `app/Http/Kernel.php:43-79` (groupes `web`/`api` + `routeMiddleware`) — aucune entrée `idempotency`. `app/Http/Middleware/*.php` — aucun fichier `Idempotency*`. Grep `app/Http/Middleware` `[Ii]dempotency` → **0 match**. |
| **H2** | `changePaymentStatus` peut être appelé 2× avec succès sur le même order | **CONFIRMÉ** | `app/Services/OrderService.php:1592-1646` : assignation directe `$order->payment_status = $request->payment_status; $order->save();` sans `if (current === target)` ni machine d'état. `PaymentStatusRequest::rules` (`app/Http/Requests/PaymentStatusRequest.php:30-32`) : `numeric` seul, pas d'`in:[5,10]`. |
| **H3** | Front pas de garde anti double-clic visible | **PARTIELLEMENT FAUX** | `PaymentComponent.vue:97` : `:disabled="loading.isActive"` + `confirmOrder()` early-return `if (this.loading.isActive) return;` (`:193-197`). **Pas de** `aria-disabled`, **pas de** `data-testid`, **pas de** `lodash.debounce`. Bouton « Order » `PosComponent.vue:446-449` : aucun guard front (`@click.prevent="orderSubmit"` ouvre la modal, `orderSubmit` met `loading.isActive=true` puis `false` avant `modalShow`, `:1444 / :1498-1499`). |
| **H4** | `orderId` réutilisé entre 2 sessions | **HORS PÉRIMÈTRE / NON DÉMONTRÉ** | `Order::create` est appelé sans `id` explicite ; auto-increment Eloquent. La clé partagée est `idempotency_key`, format **non-UUID** côté POS : `Date.now()_${Math.random().toString(36).substr(2,9)}_${branch_id}` (`PosComponent.vue:1496`). Le store fallback est `crypto.randomUUID()` (`posOrder.js:74`) **uniquement si** `payload?.idempotency_key` est falsy — or `PosComponent` la définit toujours → **format UUID jamais utilisé en POS standard**. Risque de collision pratique très faible mais non nul (entropie ≈ 36⁹ ≈ 10¹⁴ par milliseconde par branche). |
| **H5** | Transaction DB ne couvre pas Order + Payment + Outbox | **CONFIRMÉ** | `OrderService.php:570-952` : `DB::transaction` couvre `Order::create`, recalcul prix SSOT, `OrderItem::insert`, allocation `queue_number` (Cache lock), `OrderCoupon::create`, `OrderAddress::create`, `ActionLog::create`, `AuditLogService::write` (discount). **Pas inclus** : (a) ligne `Transaction` — `PaymentService::payment` (`app/Services/PaymentService.php:13-28`) n'est **pas** appelé depuis `posOrderStore` ; le flux POS pose simplement `payment_status = PAID` sur `orders` (`OrderService.php:598`). (b) `DomainEvent` (Outbox) — créé hors txn dans `PersistOrderCreatedToOutbox::handle` (`app/Listeners/PersistOrderCreatedToOutbox.php:18-35`), via listener post-dispatch post-commit. |

---

## 2. Architecture observée (synthèse)

```
[PosComponent.vue]
   ├ orderSubmit() (:1439-1500)
   │   ├ génère idempotency_key = `${Date.now()}_${rand36(9)}_${branch_id}` (:1496) ⚠ NON-UUID
   │   ├ loading.isActive = true → false → modalShow('#orderpayment')
   │   └ pas de :disabled / aria-disabled / data-testid sur le bouton « Order » (:446)
   ▼
[PaymentComponent.vue]
   ├ confirmOrder() (:193-288)
   │   ├ early-return if loading.isActive (single-flight)
   │   ├ :disabled="loading.isActive" (:97) ✅ premier rempart
   │   └ dispatch posOrder/save → openDrawer() si CASH
   ▼
[posOrder.js : save()]
   ├ uses payload.idempotency_key OR crypto.randomUUID() OR fallback (:73-75)
   ├ headers: { 'X-Idempotency-Key': key } (:79)
   ├ AbortController(30000ms) → message anti-retry (:77-91)
   ▼
[POST /api/admin/pos] routes/api.php:625 — middleware('throttle:pos-order-create') = 60/min/user
   ▼
[PosController::store] → [OrderService::posOrderStore]  (:556-986)
   ├ pré-check applicatif : Order::where('idempotency_key',$key)->first() (:561-566)
   │     ⚠ PAS de filtre branch_id explicite — implicitement scopé via BranchScope global
   │       sauf pour Admin (branch_id=0) qui voit toutes les branches
   ├ DB::transaction { Order + items + queue + coupon + address + ActionLog + AuditLog } (:570-952)
   │     ⚠ Aucune ligne `transactions` créée (PaymentService::payment non appelé)
   │     ⚠ FiscalSequenceService::next dans la txn (SAVEPOINT — OK NF525)
   ├── commit ──
   ├ catch QueryException 23000 → re-lookup → return existing (:968-979)
   ├ dispatch OrderCreated POST-commit (:961) ✅ invariant respecté
   ▼
[Listener PersistOrderCreatedToOutbox]
   ├ DomainEvent::create (HORS transaction d'origine) (:18-35)
   └ DB::afterCommit { DispatchDomainEventsJob::dispatch(…)->onQueue('high') } (:37-39)

[POST /api/admin/pos-order/change-payment-status/{order}] routes/api.php:635
   middleware('throttle:pos-order-update') = 120/min/user
   ├ permission:pos-orders
   ├ PaymentStatusRequest : payment_status numeric only (pas d'enum/in)
   ▼
[OrderService::changePaymentStatus] (:1592-1646)
   ❌ pas de DB::transaction
   ❌ pas de lecture du header X-Idempotency-Key
   ❌ pas de garde transition : $order->payment_status = $request->payment_status; $order->save();
   ✓ branch isolation (:1606-1611)
   ✓ ActionLog + AuditLog (HMAC) écrits à chaque appel — donc 2× appels = 2× lignes auditables
```

---

## 3. Matrice scénarios × protections

Légende protections : **DB-UQ** = contrainte unique composite `(branch_id, idempotency_key)` ; **MW** = middleware HTTP idempotency ; **App-lock** = pré-check + catch 23000 dans le service ; **Front-SF** = single-flight Vue ; **Throttle** = `throttle:pos-order-*` Laravel (rate-limit) ; **DB-Tx** = transaction DB explicite.

| # | Scénario | DB-UQ | MW | App-lock | Front-SF | Throttle | DB-Tx | Verdict |
|---|----------|-------|----|---------|----------|---------|-------|---------|
| **S1** | Caissier double-clic « Confirmer & imprimer » (PaymentComponent) | ✅ | ❌ | ✅ même `idempotency_key` réutilisée | ✅ `:disabled` + early-return | ✅ 60/min | ✅ | **PROTÉGÉ** |
| **S2** | Caissier double-clic « Order » (PosComponent → ouvre la modal) | ✅ | ❌ | ✅ (clé attachée à `checkoutProps.form`) | ⚠ aucun guard front sur ce bouton ; `loading.isActive` est repassé à `false` avant `modalShow` (`PosComponent.vue:1498`) | ✅ | ✅ | **PROTÉGÉ** par dédoublonnage serveur ; UX peut afficher 2× la modal |
| **S3** | Réseau lent + retry navigateur (F5) avec **même** key | ✅ catch 23000 | ❌ | ✅ | n/a | ✅ | ✅ | **PROTÉGÉ** |
| **S4** | Retry **sans** header `X-Idempotency-Key` (client tiers, script, mobile non-conforme) | ❌ (champ nullable côté DB) | ❌ pas de 422 | ❌ pré-check skip si null (:561) | n/a | ✅ (60/min) | ✅ | **DOUBLON POSSIBLE** — protection = throttle uniquement (60 commandes/min) |
| **S5** | 2× POST `change-payment-status` (UNPAID→PAID puis PAID→PAID) | ❌ | ❌ | ❌ | ❌ | ✅ 120/min | ❌ | **V6 CASSÉ** — accepté, ré-écrit le statut, recrée 2× ActionLog + 2× AuditLog HMAC |
| **S6** | 2 caissiers branches A et B utilisent par hasard la même string idempotency | ✅ composite branch_id | n/a | ⚠ pré-check sans `branch_id` explicite mais BranchScope global filtre par branche pour staff | n/a | n/a | ✅ | **PROTÉGÉ** pour staff ; **EXPOSÉ** pour Admin (branch_id=0) qui voit cross-branche → pourrait retourner l'order de l'autre branche en pré-check |
| **S7** | Intercepteur axios re-rejoue après 401 | n/a | n/a | n/a | `__retry401Kiosk` flag one-shot **kiosk only** (`resources/js/app.js`) | ✅ | n/a | **BORNÉ** au kiosk, pas POS admin |
| **S8** | Crash mid-transaction `posOrderStore` après dispatch | n/a | n/a | n/a | n/a | n/a | ✅ Order rolled back ; `OrderCreated::dispatch` est hors txn → pas d'event fantôme | **CONFORME** invariant dispatch-after-commit |
| **S9** | Crash entre commit Order et insert `domain_events` (panne DB sur 2ème connexion) | n/a | n/a | n/a | n/a | n/a | ❌ Outbox **non transactionnel** strict (listener post-commit) | **V7 PARTIAL** — Order existe sans event Outbox → divergence WebSocket/KDS si pas de retry/réconciliation |
| **S10** | Cashier admin (branch_id=0) crée un POS et collision de clé non-UUID | ✅ DB-UQ composite | ❌ | ⚠ pré-check **renvoie** un order d'une autre branche (Admin bypass BranchScope) | n/a | ✅ | ✅ | **DOUBLON SUBTIL** — pré-check peut retourner un mauvais ordre, jamais inséré, mais payload côté front incorrect |

---

## 4. Vérifications V1-V7

### V1 — Middleware `Idempotency-Key` scopé `(branch_id, user_id, key)` avec TTL
**Verdict :** **FAIL strict** / **PARTIAL fonctionnel**

- **Aucun middleware HTTP** : `app/Http/Kernel.php:17-79` → ni dans `$middleware`, ni dans `$middlewareGroups['api']` (`api` = `throttle:api`, `SubstituteBindings`, `JsonMiddleware`, `CorrelationIdMiddleware`), ni dans `$routeMiddleware`. `app/Http/Middleware/*.php` → 16 fichiers, aucun `Idempotency*`.
- **Idempotence applicative** :
  - `app/Services/OrderService.php:560` : `$idempotencyKey = $request->header('X-Idempotency-Key');`
  - `app/Services/OrderService.php:561-566` : pré-check `Order::where('idempotency_key', $idempotencyKey)->first()` → retour direct si existant.
  - `app/Services/OrderService.php:579-581` : persistance `$validated['idempotency_key'] = substr($idempotencyKey, 0, 64);`
  - `app/Services/OrderService.php:968-979` : `catch QueryException 23000` → re-lookup → return existing.
- **Scope DB** : `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php:33-36` → `unique(['branch_id', 'idempotency_key'], 'orders_branch_id_idempotency_key_unique')`. **`user_id` absent du scope** (la spec demande `(branch_id, user_id, key)`).
- **TTL** : aucun (champ `idempotency_key` reste sur `orders` indéfiniment ; pas de purge planifiée détectée).
- **Routes concernées** : `routes/api.php:625` (`POST /api/admin/pos`) avec `throttle:pos-order-create` (60/min). `change-payment-status` `:635` avec `throttle:pos-order-update` mais **sans** lecture du header.

> **Écart vs spec** : pas de middleware réutilisable ; scope sans `user_id` ; pas de TTL ; non appliqué à `changePaymentStatus`/`refund`/`selectDeliveryBoy`. Couverture fonctionnelle uniquement pour `posOrderStore` via DB-UQ + catch 23000.

### V2 — Header obligatoire sur `posOrderStore` ET `changePaymentStatus`
**Verdict :** **FAIL**

- `posOrderStore` (`OrderService.php:560-566`) : `if ($idempotencyKey)` uniquement → **header optionnel**. Aucune réponse `422` si absent. Aucun `'idempotency_key' => 'required'` dans `PosOrderRequest::rules` (`app/Http/Requests/PosOrderRequest.php:35-85`).
- Côté front, le store **génère systématiquement** une clé (`resources/js/store/modules/posOrder.js:73-79` ; `resources/js/components/admin/pos/PosComponent.vue:1496`) → présence garantie sur le chemin nominal ; mais clients tiers / scripts / mobile non maintenu peuvent l'omettre → S4.
- `changePaymentStatus` (`OrderService.php:1592-1646`) : **aucune** lecture du header. Aucun guard.

### V3 — Tests Feature : 2× POST même key → 1 commande ; 2× sans key → 2 (ou 422)
**Verdict :** **PARTIAL** (manque la route POS HTTP)

- **Frontend / Kiosk** :
  - `tests/Feature/ConcurrentOrderTest.php:62-84` `test_idempotency_prevents_duplicate_order` → 2× même key sur `/api/frontend/order` → assert 1 `FrontendOrder`.
  - `tests/Feature/ConcurrentOrderTest.php:93-116` `test_two_orders_created_with_different_keys` → assert 2 orders.
- **Modèle Order (DB-level)** :
  - `tests/Feature/Orders/IdempotencyBranchScopedTest.php:20-30` : même key sur 2 branches différentes → 2 orders OK.
  - `:32-46` : même key + même branche → `QueryException` levée.
- **POS HTTP route** : **absent**. Aucun test n'envoie 2× `POST /api/admin/pos` avec même `X-Idempotency-Key` et n'assert "1 seule `Order`". Grep `Idempot` dans `tests/Feature/POSComprehensiveTest.php` → 0 match. Grep `change-payment-status` + idempotency croisé → 0 match.
- **Sans-clé POS** : aucun test n'assert "2 commandes créées" ou "422".

### V4 — Front POS débounce le bouton « Encaisser » (`data-testid` + `aria-disabled`)
**Verdict :** **WARN** (single-flight présent ; debounce/a11y/testid absents)

- `resources/js/components/admin/pos/PaymentComponent.vue:96-99` :
  ```html
  <!-- [AUDIT-P2] :disabled prevents a second click while the order is being submitted -->
  <button @click="confirmOrder" type="button" :disabled="loading.isActive"
    class="...disabled:opacity-50 disabled:cursor-not-allowed">
    {{ $t('button.confirm_and_print') }}
  </button>
  ```
- `resources/js/components/admin/pos/PaymentComponent.vue:193-197` : early-return guard.
  ```js
  confirmOrder: function () {
      if (this.loading.isActive) return;
      this.loading.isActive = true;
  ```
- **`aria-disabled`** : ABSENT.
- **`data-testid`** : ABSENT sur ce bouton (présent ailleurs sur cartes kiosk cash, lignes 604/623 — pas sur le bouton paiement POS).
- **`lodash.debounce`/`throttle`** : non utilisé sur `confirmOrder` ni `orderSubmit`.
- **`PosComponent.vue` (workspace)** : 2078 lignes — fichier intact (vs claim erroné précédent). Bouton « Order » `:446-449` :
  ```html
  <button @click.prevent="orderSubmit"
    class="capitalize text-sm font-medium leading-6 font-rubik w-full text-center rounded-3xl py-2 text-white bg-[#1AB759]">
    {{ $t('button.order') }}
  </button>
  ```
  → **aucun** `:disabled`, `aria-disabled`, `data-testid`. `orderSubmit` (`:1439-1500`) met `loading.isActive=true` puis `false` avant `modalShow('#orderpayment')` → 2 clics rapides peuvent ouvrir 2× la modal (idempotence DB protège la création réelle).
- **Génération clé** (`resources/js/store/modules/posOrder.js:73-79`, `PosComponent.vue:1496`) : format **non-UUID** côté POS (`Date.now()_rand36_branchId`). `crypto.randomUUID()` n'est utilisé que comme fallback dans le store si `payload.idempotency_key` est absent — or `PosComponent` le définit toujours.
- **AbortController 30s** : présent (`posOrder.js:76-77`), refus de retry silencieux (`posOrder.js:87-89`).

### V5 — Transaction DB ouverte avant insert order, fermée après audit log
**Verdict :** **PARTIAL**

- Transaction démarrée : `app/Services/OrderService.php:570` (`DB::transaction(function () use ($request, &$order, $idempotencyKey) {`).
- **Inclus** : `Order::create` (:593), `pricingService->calculateOrder`, `OrderItem::insert` (:633 / :773), allocation `queue_number` via `Cache::lock` (:799-822), `FiscalSequenceService::next` (SAVEPOINT, NF525-OK, :875-877), `Order::save` (:878), `OrderCoupon::create` (:882-887), `OrderAddress::create` (:898-906), `ActionLog::create` (:921-926), `AuditLogService::write` discount (:933-948).
- **Pas inclus** : aucune ligne `transactions` créée en POS (`PaymentService::payment`/`cashBack` jamais appelés depuis `posOrderStore`). `payment_status = PAID` posé directement sur `orders` (:598).
- **Fermée avant dispatch** (:951-952) ; dispatch des notifications + `OrderCreated::dispatch` lignes 957-961 → ✅ **invariant FoodKing "dispatch après commit" respecté**.
- `changePaymentStatus` (`OrderService.php:1592-1646`) : **aucune `DB::transaction`**. Triple `save()` + `ActionLog::create` + `AuditLogService::write` non atomiques (un crash entre les deux laisse un statut modifié sans trace HMAC).

### V6 — `payment_status` transitions guardées (PENDING → PAID, refus PAID → PAID)
**Verdict :** **FAIL**

- `app/Services/OrderService.php:1613-1614` :
  ```php
  $order->payment_status = $request->payment_status;
  $order->save();
  ```
- **Aucune** comparaison du statut courant ; aucune machine d'état ; aucune table de transitions valides.
- `app/Enums/PaymentStatus.php:5-9` : interface ne définit que `PAID = 5` et `UNPAID = 10` — pas de `PENDING`, pas de map de transitions. Note : la spec mentionne `PENDING → PAID` mais l'enum ne contient pas `PENDING` (les commandes POS naissent déjà `PAID`, ligne 598).
- `PaymentStatusRequest::rules()` (`app/Http/Requests/PaymentStatusRequest.php:30-32`) : `numeric` seulement — **pas de `Rule::in([5,10])`** ; donc même `payment_status = 999` traverse la validation et est persisté tel quel.
- **Conséquence** : 2 appels successifs `change-payment-status` avec `payment_status=5` réussissent tous deux, créent **2 lignes ActionLog**, **2 lignes AuditLog** (chaîne HMAC pollée), et peuvent émettre 2 events (ici aucun event `PaymentStatusChanged` détecté — voir Findings).
- **Ré-jeu trivial** : `curl -X POST .../change-payment-status/{id} -d 'payment_status=5'` × N → N entrées AuditLog avec même `to_payment_status`.

### V7 — Outbox écrit dans la même transaction (cf. `PersistOrder*ToOutbox`)
**Verdict :** **PARTIAL** (intent OK, atomicité non stricte)

- `app/Listeners/PersistOrderCreatedToOutbox.php:14-40` : crée `DomainEvent::create([…])` puis `DB::afterCommit(fn () => DispatchDomainEventsJob::dispatch($domainEvent->id)->onQueue('high'));`.
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:14-39` : pattern identique pour `OrderStatusChanged`.
- `OrderCreated` est dispatché **hors** `DB::transaction` (`OrderService.php:961`) → le listener exécute `DomainEvent::create` après commit Order, **dans une connexion DB séparée** de la transaction d'origine.
- **Conformité invariant "dispatch after commit"** : ✅ OK.
- **Conformité Outbox transactionnel strict (atomicité Order+Event)** : ❌ KO — un échec de l'insert `domain_events` (DB down, contrainte) laisse l'Order commit sans event Outbox → divergence KDS/Soketi/Web s'il n'y a pas de réconciliation périodique.
- **Aucun listener `PaymentStatusChanged*ToOutbox`** détecté → `changePaymentStatus` n'émet **aucun** event Outbox (perte de signal côté KDS / Z report / OSS si on en a besoin downstream).

---

## 5. Critères d'acceptation (§6 spec)

| Condition spec | Constat |
|---|---|
| ALL_GREEN si V1–V7 OK | Non |
| WARN si V4 manquant | V4 partiel, **mais V1 strict + V6 cassé** |
| **FAIL si V1 absent OU V6 cassable** | **V1 absent (strict middleware)** **ET V6 cassable** |

> **Application stricte du barème** → **FAIL**.
> **Application pondérée** (V1 fonctionnellement couvert par DB-UQ + catch 23000 sur la route POS principale) → **WARN proche FAIL**.
>
> **Verdict retenu : `WARN`** avec **2 blocants P0** à traiter en cycles P (V6 et V1-middleware), 1 risque P1 (Outbox), 1 amélioration P2 (V4 testid/aria).

---

## 6. Findings prioritaires (F-VERIFY-09-XX)

### Top-5 (priorité décroissante)

| ID | Sévérité | Chemin | Description | Recommandation |
|---|---|---|---|---|
| **F-VERIFY-09-01** | **P0** | `app/Services/OrderService.php:1592-1646` + `app/Http/Requests/PaymentStatusRequest.php:30-32` | `changePaymentStatus` sans guard de transition ni transaction ni `Rule::in` : `PAID→PAID`, `UNPAID→PAID` répété, `payment_status=999` tous acceptés ; double AuditLog HMAC ; double ActionLog ; ré-jeu trivial. Casse V6 et VIOLE l'invariant FoodKing "transitions de statut guardées" (`.cursor/rules/safety.mdc:30-35`). | (1) Ajouter `Rule::in([PaymentStatus::PAID, PaymentStatus::UNPAID])`. (2) Implémenter map `[from => allowed-to[]]`, refuser si `current === target` (idempotent return) et si transition non autorisée (422). (3) Envelopper dans `DB::transaction`. (4) Lire `X-Idempotency-Key` et dédoublonner. (5) Émettre un event `OrderPaymentStatusChanged` (post-commit) + listener Outbox. |
| **F-VERIFY-09-02** | **P0** | `app/Http/Kernel.php:43-79`, `app/Services/OrderService.php:560-566`, `routes/api.php:625-637` | Aucun middleware `Idempotency-Key` ; header **optionnel** côté serveur ; un client qui l'omet perd toute protection (S4). Spec exige scope `(branch_id, user_id, key)` + TTL — actuel = `(branch_id, key)` sans TTL ni `user_id`. Pas appliqué à `change-payment-status`/`select-delivery-boy`/`refund`. | Créer `App\Http\Middleware\IdempotencyKeyMiddleware` (cache Redis, TTL 24 h, scope `branch_id|user_id|method|path|key`, replay-cache du body de réponse), enregistrer dans `routeMiddleware`, alias `idempotency`, appliquer sur `POST /admin/pos`, `POST /admin/pos-order/change-*`, `POST /admin/pos-order/select-delivery-boy`. Retourner `422` si header absent sur ces routes critiques. |
| **F-VERIFY-09-03** | **P1** | `app/Services/OrderService.php:961`, `app/Listeners/PersistOrderCreatedToOutbox.php:18-39` | Outbox `domain_events` inséré **hors** transaction d'origine → atomicité Order+Event non garantie ; perte d'event possible (S9). V7 non strict. | Soit (a) déplacer l'insert `domain_events` à l'intérieur de `posOrderStore::DB::transaction` via `HasDomainEvents` recordable, dispatch via `DB::afterCommit` ; soit (b) ajouter une réconciliation périodique (`OutboxReconciliationJob`) qui détecte les Orders sans `domain_events` et les rattrape. |
| **F-VERIFY-09-04** | **P1** | `tests/Feature/POSComprehensiveTest.php` (existant), absent | **Aucun test Feature** sur `POST /api/admin/pos` 2× même `X-Idempotency-Key` → 1 `Order`. Régression silencieuse possible si quelqu'un retire le pré-check `OrderService.php:561-566` ou la migration composite. | Ajouter `tests/Feature/POS/PosIdempotencyTest.php` couvrant : (a) 2× même key → 1 Order, (b) sans key → 2 Orders (état actuel, avant FAIL fix), (c) 2 branches différentes même key → 2 Orders (BranchScope), (d) admin (branch_id=0) avec collision → vérifier comportement. |
| **F-VERIFY-09-05** | **P2** | `resources/js/components/admin/pos/PaymentComponent.vue:97-99` + `PosComponent.vue:446-449` | Bouton « Confirmer & imprimer » sans `data-testid` ni `aria-disabled` (impact tests E2E + a11y). Bouton « Order » sans `:disabled` + `loading.isActive` réinitialisé avant `modalShow` → 2 modals possibles. V4 partiel. | Ajouter `data-testid="pos-confirm-pay"` + `:aria-disabled="loading.isActive"` + `lodash.debounce` 400 ms défensif sur `confirmOrder`. Idem `data-testid="pos-order-submit"` + `:disabled` sur `orderSubmit` ; ne pas remettre `loading.isActive=false` avant `modalShow` (laisser actif jusqu'au callback `posOrder/save`). |

### Findings secondaires

| ID | Sévérité | Chemin | Description |
|---|---|---|---|
| **F-VERIFY-09-06** | P2 | `app/Services/OrderService.php:561` | Pré-check `Order::where('idempotency_key', $key)->first()` sans filtre `branch_id` explicite : pour Admin (branch_id=0, BranchScope no-op `app/Models/Scopes/BranchScope.php:33-36`), peut retourner l'order d'une autre branche en cas de collision. Recommandation : ajouter `->where('branch_id', $request->branch_id)` explicitement. |
| **F-VERIFY-09-07** | P2 | `resources/js/components/admin/pos/PosComponent.vue:1496` | Format `idempotency_key = ${Date.now()}_${rand36(9)}_${branch_id}` non-UUID, entropie ~ 36⁹ ≈ 10¹⁴ par milliseconde par branche. `crypto.randomUUID()` (entropie 122 bits) n'est utilisé que comme fallback dans `posOrder.js:74` mais `PosComponent` définit toujours la clé. Forcer UUID v4 partout. |
| **F-VERIFY-09-08** | P2 | `app/Services/PaymentService.php:13-28` | `PaymentService::payment` n'est pas appelé depuis `posOrderStore` ; aucune ligne `transactions` créée en POS. `Transaction` est uniquement créée pour le flux gateway (Stripe/etc) côté kiosk. Conséquence : journal financier comptable POS = uniquement `orders.payment_status` (pas de chemin séparé `transactions`). Hors périmètre strict mais critique pour `AUDIT_POS_110_PAYMENTS_REFUND`. |
| **F-VERIFY-09-09** | P3 | `app/Services/FrontendOrderService.php:135-137` vs `app/Services/OrderService.php:561-562` | Asymétrie `OrderService` ↔ `FrontendOrderService` : la version Kiosk utilise un `Cache::lock` **avant** le pré-check pour bloquer les requêtes simultanées au niveau process (`FrontendOrderService.php:135-137`) ; `posOrderStore` n'a **pas** ce lock applicatif et repose uniquement sur le catch QueryException 23000. Symétrie attendue par invariant FoodKing. |
| **F-VERIFY-09-10** | P3 | `app/Services/OrderService.php:1592-1646` | `changePaymentStatus` n'émet **aucun** event domaine ; aucun listener `PersistPaymentStatusChangedToOutbox` détecté. Si KDS/OSS/Z report doit s'aligner sur changement de statut paiement (ex. annulation post-paiement), perte de signal silencieuse. |

---

## 7. Cycles P proposés

| ID | Titre | Modèle (PRIMARY_MODEL) | Justification routing | Couverture |
|---|---|---|---|---|
| **P11_IDEMPOTENCY_KEY_MIDDLEWARE** | Middleware HTTP `IdempotencyKeyMiddleware` scopé `(branch_id, user_id, key)`, TTL Redis 24 h, replay-cache du body, `422` si header absent sur routes critiques (POS create + change-payment-status + select-delivery-boy + refund + frontend/order) | **GPT-5.4** (`foodking-complex-implementer`) — concerne auth/sync/sécurité, transverse, état partagé Redis | V1, V2 ; règle F-VERIFY-09-02 |
| **P12_POS_DOUBLE_SUBMIT_FRONT** | Ajouter `data-testid="pos-confirm-pay"`, `:aria-disabled="loading.isActive"`, `lodash.debounce` 400 ms défensif sur `confirmOrder` ; `data-testid="pos-order-submit"` + `:disabled` + suppression du reset prématuré `loading.isActive=false` sur `orderSubmit` ; forcer `crypto.randomUUID()` (drop format non-UUID) | **Composer** (`foodking-routine-implementer`) — UI localisée, copy + 2 attrs HTML + 1 import lodash, low-risk | V4 ; règle F-VERIFY-09-05, F-VERIFY-09-07 |
| **P13_PAYMENT_STATUS_STATE_MACHINE** | Machine d'état `PaymentStatus` (table `[from=>allowed-to[]]`), guard explicite + `Rule::in([5,10])` dans `PaymentStatusRequest`, `DB::transaction` autour de `changePaymentStatus`, lecture `X-Idempotency-Key`, émission `OrderPaymentStatusChanged` + listener Outbox correspondant | **GPT-5.4** (`foodking-complex-implementer`) — touche enum, audit NF525 HMAC, lifecycle financier, fiscal-sensible | V6 ; règle F-VERIFY-09-01, F-VERIFY-09-10 |
| **P14_POS_FEATURE_TESTS_IDEMPOTENCY** | `tests/Feature/POS/PosIdempotencyTest.php` : 2× même key → 1 Order ; sans key → 2 Orders (avant fix) puis 422 (après) ; 2 branches même key → 2 Orders ; admin cross-branche ; `change-payment-status` 2× même key → 1 mutation | **Composer** (`foodking-routine-implementer`) — tests bornés, pattern existant `ConcurrentOrderTest` | V3 ; règle F-VERIFY-09-04 |
| **P15_TRUE_OUTBOX_TRANSACTIONAL** | Refactor `PersistOrderCreatedToOutbox` pour insert `domain_events` à l'intérieur de la `DB::transaction` (via `HasDomainEvents` recordable + `Order::dispatchDomainEvents()`), dispatch job sur `DB::afterCommit`. Ajouter `OutboxReconciliationJob` planifié (kernel) en filet de sécurité | **GPT-5.4** (`foodking-complex-implementer`) — sync logic + atomicité + listener refactor + scheduler | V7 strict ; règle F-VERIFY-09-03 |

### Hors scope immédiat (à backlog)

- **P16_POS_TRANSACTION_LEDGER** — créer une vraie ligne `transactions` à chaque POS commit (CASH/CARD/TR/MOBILE), pour aligner POS et flux gateway sur un même journal comptable. Bloquant pour `AUDIT_POS_110_PAYMENTS_REFUND`. F-VERIFY-09-08.
- **P17_POS_APP_CACHE_LOCK** — porter le `Cache::lock` du Kiosk (`FrontendOrderService.php:135-137`) sur `posOrderStore` pour symétrie. F-VERIFY-09-09.

---

## 8. Conformité aux invariants FoodKing

| Invariant (`.cursor/rules/safety.mdc`, `AGENTS.md` Non-Negotiables) | Constat | Preuve |
|---|---|---|
| Backend pricing SSOT | ✅ Respecté | `OrderService.php:576` `unset($validated['total'], $validated['subtotal'], $validated['discount']);` |
| `OrderStatus` enum authoritative | ✅ | `OrderService.php:596` `OrderStatus::ACCEPT` |
| `branch_id` isolation (no cross-branch bleed) | ⚠ Pré-check idempotency (`OrderService.php:561-566`) ne filtre pas explicitement `branch_id` ; couvert par `BranchScope` global SAUF pour Admin (branch_id=0) | `app/Models/Scopes/BranchScope.php:33-36` |
| Dispatch après commit DB | ✅ Respecté | `OrderService.php:951` (`});` close txn) puis `:961` `OrderCreated::dispatch($order)` |
| Symétrie `OrderService` ↔ `FrontendOrderService` | ⚠ Asymétrie : Kiosk a `Cache::lock` applicatif (`FrontendOrderService.php:135-137`) absent en POS | F-VERIFY-09-09 |
| Frozen zones / Gate clearance | n/a (audit lecture seule, aucune modification) | — |
| Transitions statut paiement guardées | ❌ **VIOLÉ** sur `changePaymentStatus` | F-VERIFY-09-01 |

---

## GLOBAL : **WARN** — l'idempotence POS tient en pratique grâce à la contrainte unique DB composite + catch QueryException 23000, mais le contrat spec FAIL strictement (V1 middleware absent ; V6 transitions paiement cassables) ; cycles P11/P12/P13 à ouvrir en priorité.
