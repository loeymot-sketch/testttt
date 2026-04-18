# FoodKing — Compréhension globale & carte d'audit

**Date :** 2026-04-17
**Auteur :** Claude (orchestrateur, conformément à CLAUDE.md)
**Portée :** lecture profonde des docs stratégiques + cartographie du code pour POS, Kiosk, KDS, OSS, et de la chaîne de synchronisation temps-réel.
**Objectif :** préparer des audits massifs ciblés sur les zones critiques.
**Statut :** base de travail — à compléter au fil des audits ciblés suivants.

---

## 1. Identité produit (résumé exécutif)

FoodKing est une plateforme SaaS restaurant mono-dépôt (Laravel 9 + SPA Vue 3 + MySQL) couvrant quatre surfaces :

- **POS** (caisse web Vue, caissier humain, rôle Manager),
- **Kiosk** (borne client, Electron Windows + web Vue, token Sanctum `kiosk:order`),
- **KDS** (écran cuisine, rôle Chef),
- **OSS** (écran statut client, lecture seule, api-key).

La cible opérationnelle réelle est le déploiement **Le Cayenne** (restaurant EUR), pas la démo héritée. Le monolithe est contraint par les invariants documentés dans `docs/ARCHITECTURE.md`, `docs/ORDER_FLOW.md`, `docs/BUSINESS_RULES.md`, `docs/AUTHZ_MATRIX.md` et `docs/PROJECT_CONTINUITY_AND_VISION.md`.

---

## 2. Architecture réelle (telle que trouvée dans le code)

### 2.1 Deux chemins de commande, même table `orders`

| Chemin | Service | Modèle Eloquent | Entrée API | Contrôleur |
|---|---|---|---|---|
| POS (caisse comptoir) | `App\Services\OrderService::posOrderStore` | `App\Models\Order` | `POST /api/pos/` | `Admin\PosController` → `OrderService` |
| Admin (online / table / pos-order) | `OrderService::changeStatus / changePaymentStatus` etc. | `Order` | `/api/admin/pos-order/*`, `online-order/*`, `table-order/*` | `Admin\{Pos,Online,Table}OrderController` |
| Kiosk + web client | `App\Services\FrontendOrderService::myOrderStore` | `App\Models\FrontendOrder` | `POST /api/frontend/order` | `Frontend\OrderController` |
| KDS | `App\Services\KitchenDisplaySystemOrderService` | `Order` | `/api/admin/kds-order/*` | `Admin\KitchenDisplaySystemController` |
| OSS | `App\Services\OrderStatusScreenOrderService` | `Order` (lecture seule) | `/api/admin/oss-order/*` | `Admin\OrderStatusScreenController` |

`OrderService.php` fait 1693 lignes, `FrontendOrderService.php` 781. Ce sont les deux fichiers où se concentre l'essentiel du risque métier.

### 2.2 Machine à états — `App\Domain\Order\OrderStateMachine`

Unique SOT des transitions autorisées. Pipeline : `PENDING (1) → ACCEPT (4) → PREPARING (7) → PREPARED (8) → OUT_FOR_DELIVERY (10) → DELIVERED (13)`. Terminaux : `CANCELED (16)`, `REJECTED (19)`, `RETURNED (22)`.

Deux points d'entrée :
- `allows()` / `assertAllows()` — pure, sans effet.
- `apply(Model, int, ?Auth, ?reason)` — garde + mutation + audit dans une seule `DB::transaction`. Méthode préférée pour le **nouveau** code ; les call-sites historiques `OrderService` / `FrontendOrderService` / `KitchenDisplaySystemOrderService` restent sur le pattern legacy (`save()` puis `recordTransition`) car zone gelée V1.

Raccourci POS : `ACCEPT → DELIVERED` ou `PREPARING → DELIVERED` autorisé si le user a la permission `pos`. Transitions vers `CANCELED/REJECTED/RETURNED` exigent une raison non-vide. Seul un `Admin` peut sortir d'un terminal.

Audit systématique dans `order_status_transitions` (ligne par transition).

### 2.3 Pricing — SSOT backend

`config/pricing.php::use_ssot_service` active `App\Services\Pricing\PricingService::calculateOrder(PricingRequest)`. Les deux chemins (POS et Kiosk) `unset($validated['total'], $validated['subtotal'], $validated['discount'])` **avant** création de l'Order pour empêcher toute persistance d'un prix client. Le backend relit chaque item via `Item::find()` et applique `DiscountCalculator`, `TaxCalculator`, plafond coupon, plancher 0.00 €. Cette règle est une invariante FoodKing : `OrderService.php:546` et `FrontendOrderService.php:121` l'implémentent symétriquement (doctrine `AGENTS.md` : « `OrderService` / `FrontendOrderService` symmetry mandatory »).

### 2.4 Chaîne temps-réel — outbox + Echo/Pusher/Soketi

Le pipeline event est le suivant :

1. Service métier commite la mutation, puis `event(new OrderCreated($order))` ou `OrderStatusChanged::dispatch($order, $old, $new)` **après** le commit DB.
2. Les listeners synchrones `PersistOrderCreatedToOutbox` / `PersistOrderStatusChangedToOutbox` écrivent une ligne dans la table `domain_events` (payload canonique V1) puis programment `DispatchDomainEventsJob` via `DB::afterCommit(...)->onQueue('high')`.
3. `DispatchDomainEventsJob` relit la ligne, reconstruit l'enveloppe via `App\Domain\Events\EventContract::buildEnvelope`, la valide (`assertEnvelopeValid` + `assertPayloadValid`), puis `pusher->trigger($channels, $broadcast_as, $envelope)` sur `private-branch.{branchId}`.
4. Côté Vue, `resources/js/bootstrap.js` initialise `window.Echo` (`broadcaster: 'pusher'`) avec auth Bearer Sanctum. Les composants s'abonnent à `branch.{branchId}` et écoutent `OrderCreated` / `OrderStatusChanged`, puis émettent un `CustomEvent('realtime-order-update')` que les listes (KDS/OSS/POS) utilisent comme trigger de refresh, en plus d'un polling de secours (~15-30 s).

Enveloppe V1 figée : `{version:1, type, aggregate_id, branch_id, occurred_at, correlation_id, payload:{...}}`. Toute dérive casse `DispatchDomainEventsJob` (échec contrôlé, marquage `last_error`, retry exponentiel `[1,5,30,300]`, 5 tentatives).

### 2.5 Canaux & autorisation broadcast — `routes/channels.php`

`branch.{branchId}` autorisé pour : kiosk machine (si `tokenCan('kiosk:order')` ET `KioskMachine.branch_id === branchId`) ; admin (`user.branch_id === 0`) → tout ; staff → propre branche seulement. Fix récent (`[P4-1][GAP-21-5]`) : sans contrôle `tokenCan`, un token kiosk aurait pu s'abonner à toutes les branches.

### 2.6 Auth & isolation

Sanctum + Spatie Permission. Token kiosk avec ability restreinte `kiosk:order` (uniquement `/api/frontend/*`). Middleware `apiKey` sur tout `/api/frontend/*`. `landing_url` dans `roles` réappliqué dans `POST /api/auth/authcheck` (sinon F5 casse le redirect POS Operator → fix présent lignes ~192-210 de `routes/api.php`).

Isolation branche enforced dans `OrderService::posOrderStore` (exception 403 si `auth->branch_id !== request->branch_id` et non-admin) et dans tous les services par le `branch_id` forcé depuis le KioskMachine côté kiosk.

### 2.7 Flux kiosk spécifique (payment & hardware)

`FrontendOrderService::myOrderStore` distingue trois cas de paiement :
- **Cash kiosk** : `PaymentStatus::PAID` immédiat + auto-accept `PENDING → ACCEPT` après commit.
- **Card / Ticket-Restaurant** : reste `PENDING` + `UNPAID` ; la confirmation physique passe par `POST /api/frontend/order/{id}/payment-confirm` → `finalizePaidKioskOrder` qui verrouille la ligne (`lockForUpdate`), promeut en `ACCEPT`, émet `OrderCreated` + `OrderStatusChanged`.
- **Idempotence** : `X-Idempotency-Key` + `Cache::lock` 10 s → retourne la commande existante en cas de retry. Présent aussi sur `posOrderStore`.

Hardware (Electron) : `window.borne.openDrawer()` appelé sur le chemin cash ; événements observables envoyés via `POST /api/frontend/kiosk-event` (types allowlistés : `printer_failure`, `cash_drawer_failure`, `order_abandoned`, etc.). `throttle:30,1` par token.

### 2.8 Notifications

- `SendOrderMail/Sms/Push` et `SendOrderGotMail/Sms/Push` : jobs dispatchés **après** commit.
- FCM via `SendFcmNotificationJob`, listeners `SendFcmOnOrderCreated` / `SendFcmOnOrderStatusChange`.
- `QUEUE_CONNECTION=sync` en dev = toutes ces dispatches s'exécutent dans la requête HTTP → risque de latence P95 (point P0 de `PROJECT_CONTINUITY_AND_VISION.md`).

---

## 3. Zones gelées (ne pas toucher sans plan explicite)

Source : `docs/ARCHITECTURE.md` §Zones gelées + `docs/CORE_MODULES.md`.

1. Gateways de paiement externes (Stripe, Paypal, Paystack, Razorpay).
2. `App\Services\PushNotificationService` (couche Guzzle FCM héritée).
3. `Admin\DashboardController` et sous-modules analytics avancés.
4. Delivery Boy Logic.

Toute modification dans ces zones exige `architecture_exception` dans le plan JSON et validation humaine.

---

## 4. Invariants FoodKing (non-négociables)

Consolidation des règles trouvées dans `CLAUDE.md`, `AGENTS.md §FoodKing Non-Negotiables`, `docs/BUSINESS_RULES.md`, `docs/GATES_DOCTRINE.md` :

1. Le **backend est SSOT** du prix — jamais le frontend.
2. `OrderStatus` enum est autoritaire — jamais de string.
3. `branch_id` isole les données — pas de fuite cross-branche.
4. Dispatch des events/jobs **après** commit DB, jamais dedans.
5. `OrderService` et `FrontendOrderService` doivent rester symétriques sur toute modif de commande.
6. Le total ne peut pas descendre sous 0.00 €.
7. Les transitions passent par `OrderStateMachine::allows()` ou `apply()` — jamais de chemin retour (`DELIVERED → PREPARING` = BLOCKED).
8. Abilities des tokens kiosk figées à `kiosk:order` — toute extension = BLOCKED security-regression-gate.
9. Payload broadcast rétrocompatible — rupture = BLOCKED.
10. Les 12 corrections historiques de `PROJECT_CONTINUITY_AND_VISION.md §5` ne doivent pas régresser.

---

## 5. Carte du code — fichiers pivots

### Backend

| Zone | Fichier | Rôle |
|---|---|---|
| Commandes POS / admin | `app/Services/OrderService.php` (1693 L) | `posOrderStore:546`, `changeStatus:1363`, `changePaymentStatus:1485`, `selectDeliveryBoy:1554`, `salesReportOverview:1600` |
| Commandes kiosk / web | `app/Services/FrontendOrderService.php` (781 L) | `myOrderStore:121`, `changeStatus:627`, `finalizePaidKioskOrder:707`, `dispatchNewOrderSignals:765` |
| KDS | `app/Services/KitchenDisplaySystemOrderService.php` (208 L) | `list:39`, `changeStatus:108`, `orderItems:155` |
| OSS | `app/Services/OrderStatusScreenOrderService.php` (85 L) | `list:35` (lecture seule + branch filter + today/advance) |
| State Machine | `app/Domain/Order/OrderStateMachine.php` | `allows`, `apply`, `recordTransition`, `requiresReason`, `legalTransitions` |
| Pricing SSOT | `app/Services/Pricing/{PricingService,PricingRequest,PricingResult,PricingLineResult,DiscountCalculator,TaxCalculator}.php` | `calculateOrder(PricingRequest::forPos|forKiosk)` |
| Events | `app/Events/{OrderCreated,OrderStatusChanged,ItemAvailabilityChanged}.php` | Plain dispatchable, payload porté par `BroadcastableOrder` |
| Contract | `app/Domain/Events/EventContract.php` | Enveloppe V1 + validation payload |
| Outbox | `app/Listeners/PersistOrderCreatedToOutbox.php`, `app/Listeners/PersistOrderStatusChangedToOutbox.php` | Écrit `domain_events` puis programme le job `afterCommit` |
| Dispatch | `app/Jobs/DispatchDomainEventsJob.php` | Pusher trigger + validation contract + retries |
| Coupons | `app/Services/CouponService.php` (321 L) | Validation code + plafond |
| Kiosk login | `app/Http/Controllers/Auth/KioskMachineLoginController.php` | Génère token `kiosk:order` |
| Kiosk events | `app/Http/Controllers/Frontend/KioskEventController.php` | Observabilité hardware (printer, drawer) |
| Frontend order controller | `app/Http/Controllers/Frontend/OrderController.php` | `store`, `changeStatus`, `paymentConfirm` |
| Channels auth | `routes/channels.php` | `branch.{branchId}` avec garde `tokenCan('kiosk:order')` |
| Routes API | `routes/api.php` (938 L) | auth, frontend kiosk, admin pos/online/table/kds/oss |

### Frontend (Vue 3 / Vuex)

| Surface | Fichiers | Notes |
|---|---|---|
| POS | `resources/js/components/admin/pos/{PosComponent,PaymentComponent,ItemComponent,ReceiptComponent,CreateCustomerAddressComponent}.vue` + `public/js/pos-wizard.js`, `public/css/pos-wizard.css` | Wizard produit hors Vue pour perf ; cart Vuex `posCart` |
| KDS | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | Polling + Echo `branch.{branchId}` lignes 568-571, émet `realtime-order-update` ligne 779 |
| OSS | `resources/js/components/admin/orderStatusScreen/{PreparingAndReadyComponent,OrderStatusScreenComponent,PopularItemComponent}.vue` | Lecture seule, Echo idem |
| Kiosk web | `resources/js/components/frontend/kiosk/Kiosk*.vue` (17 fichiers + `steps/`) | Wizard, idle timeout 180 000 ms, upsell, confirmation, Echo dans `KioskAppComponent:301` + `KioskWaitingComponent:189` |
| Bootstrap Echo | `resources/js/bootstrap.js` lignes 23-87 | Echo avec Bearer Sanctum, `_refreshEchoAuth` exposé pour post-login |

---

## 6. Points de friction connus (issus de la doc) et risques cachés à vérifier

### 6.1 Déjà documentés (backlog P0–P3 de `PROJECT_CONTINUITY_AND_VISION.md §6`)

P0 : `QUEUE_CONNECTION=sync` en prod, Soketi/Pusher réel non prêt partout.
P1 : FCM pas complètement configuré ; **amend commande POS** (modifier une commande déjà validée) non livré.
P2 : TPE/tiroir non natifs en POS navigateur pur (besoin Electron/agent local) ; tests E2E à étendre.
P3 : expiration tokens Sanctum + refresh UX ; refactor types events `OrderCreated<Order>` vs `<FrontendOrder>`.

### 6.2 Zones à auditer massivement (priorisation proposée)

Ordre de priorité inféré des risques financiers, sécuritaires et de synchronisation :

**A — Intégrité pricing (P0 audit)**
Cibles : `OrderService::posOrderStore`, `FrontendOrderService::myOrderStore`, `App\Services\Pricing\*`, `config/pricing.php`, `OrderItem::insert` bulk path.
À vérifier : aucun chemin n'accepte `total/subtotal/discount` client ; cohérence cascade taxes item-par-item ; plafond coupon ; plancher 0.00 € ; comportement si `use_ssot_service=false` ; timestamps `OrderItem`.

**B — State machine & transitions (P0)**
Cibles : `OrderStateMachine`, `ValidStatusTransition`, tous les call-sites `changeStatus` (POS, Admin, KDS, Frontend), `recordTransition` coverage.
À vérifier : aucun chemin interdit exécutable ; raisons obligatoires bien imposées ; raccourci POS limité à `pos` permission ; admin override tracé ; identité no-op partout ; `apply()` vs legacy cohérent (double-écriture possible ?).

**C — Branch isolation & authz (P0)**
Cibles : `BranchScope`, `routes/channels.php`, middlewares Sanctum abilities, `KioskMachineLoginController`, `PosOrderController`, chemins admin `withoutGlobalScope`.
À vérifier : aucun kiosk ne peut voir/écrire sur une autre branche ; chef KDS restreint à sa branche ; OSS en api-key vraiment lecture seule ; admin `branch_id=0` légitime partout ; tokenCan contrôlé sur TOUTES les routes kiosk.

**D — Synchronisation temps-réel (P0)**
Cibles : `DispatchDomainEventsJob`, `PersistOrder*ToOutbox`, `EventContract`, `bootstrap.js`, composants Vue `subscribeBroadcast`, polling de secours (KDS/OSS/Kiosk/POS).
À vérifier : aucun dispatch dans `DB::transaction` ; outbox complet (tous events légitimes) ; retry + marquage `last_error` ; validation enveloppe couvre toutes les BROADCAST_MAP ; reconnection Echo après F5 / rotate token ; polling ne duplique pas Echo (race) ; latence mesurée P95.

**E — Kiosk hardware & paiement**
Cibles : `finalizePaidKioskOrder`, `paymentConfirm`, `KioskPaymentView.vue`, bridge Electron, logs `hardware`.
À vérifier : `transaction_id` vraiment persisté (doc dit « parfois log uniquement » §3.4) ; reversibilité si double-click confirm ; drawer/TPE failures remontent en ActionLog ; idempotency-key kiosk vs rejeu navigateur ; amend flow (non livré — risque produit).

**F — UX wizard & amend**
Cibles : `pos-wizard.js`, `ItemComponent.vue`, `KioskWizardComponent.vue`, steps kiosk.
À vérifier : conformité aux 15 heuristiques `ux-heuristic-gate` de `GATES_DOCTRINE.md` ; total live pendant wizard ; touch target 44×44 ; idle 3 min réel ; upsell déclenché à froid ; impossibilité d'éditer une commande validée depuis POS.

**G — KDS / OSS robustesse**
Cibles : `KitchenDisplaySystemOrderService::list/orderItems`, `OrderStatusScreenOrderService::list`, composants Vue.
À vérifier : pas de zombies (commandes bloquées `ACCEPT` en retard) ; advance orders affichés correctement ; admin vs staff branche ; `limit(50)` pas trop bas sur gros rush ; groupage items par instruction normalisée cohérent.

**H — Observabilité & audit**
Cibles : `order_status_transitions`, `domain_events`, `ActionLog`, `HealthController`, logs `hardware`, correlation-id propagation.
À vérifier : traçabilité bout-en-bout (ajouter X-Correlation-ID sur clients) ; rétention ; healthcheck `/health/ready` couvre queue + broadcast + DB + Pusher.

**I — Conformité corrections historiques (non-régression)**
Cibles : diff Git sur les 12 corrections majeures de `docs/PROJECT_CONTINUITY_AND_VISION.md §5`.
À vérifier que chacune est encore présente dans le code — c'est la règle du `security-regression-gate §8`.

---

## 7. Stratégie proposée pour les audits massifs à venir

Pour respecter l'économie de contexte (CLAUDE.md §9) et la discipline des gates (`GATES_DOCTRINE.md`), je recommande :

1. **Un audit par zone A–I** (ci-dessus) délivré en document autonome sous `reports/review/AUDIT_{zone}_2026-04-{xx}.md`, suivant `workflows/report-format.md`.
2. **Entrée uniforme de chaque audit** : hash des docs de référence au moment du check (comme dans les gates), liste de fichiers examinés, questions testées, findings classés `PASS | WARN | BLOCKED` avec preuves (chemins + lignes + extraits).
3. **Preuves réelles obligatoires** (CLAUDE.md §11) : lecture directe du code + exécution ciblée (`php artisan test --filter ...` pour les suites concernées + `npx vitest` pour le front si UI). Aucun audit ne peut conclure sans preuves.
4. **Verdict par audit** : `continue` / `heal` / `block` / `escalate` / `human` selon le `Decision Framework` CLAUDE.md §8.
5. **Pas de refactor opportuniste** : si un audit détecte un drift hors scope, produire une note séparée et ne pas toucher le code tant que le plan n'est pas validé.
6. **Séquence suggérée** : A (pricing) → C (authz/branch) → B (state machine) → D (sync) → I (non-régression historique) → E (kiosk hardware) → G (KDS/OSS) → F (UX/amend) → H (observabilité).
   Raison : les zones à risque financier/sécurité d'abord, les zones UX/ops ensuite.

---

## 8. Questions ouvertes (à clarifier avec l'humain avant d'attaquer)

1. Le périmètre de l'audit massif inclut-il aussi les zones gelées (Stripe/Paypal/Delivery/Analytics) en mode inspection-seule, ou strictement le core actif ?
2. La partie « borne Windows » (shell Electron) est-elle dans un repo séparé et accessible pour audit, ou limitée à ce monolithe Laravel ?
3. Y a-t-il des flux où le **amend commande POS** est déjà partiellement câblé que je devrais analyser (sinon je classe en P1 produit, pas en audit) ?
4. Cible de priorité : privilégier les audits qui bloquent un go/no-go prod court-terme, ou audit exhaustif « qualité long-terme » ?
5. Disponibilité environnement de test pour preuves E2E (Playwright / Soketi réel) pendant les audits ?

---

*Document de travail, à réviser après chaque audit ciblé. Le code et Git font foi en cas d'écart.*
