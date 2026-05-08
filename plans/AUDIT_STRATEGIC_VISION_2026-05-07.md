# Audit Stratégique FoodKing — Vision Architecture, Concurrence Marché, Roadmap SaaS B2B
**Date :** 2026-05-07
**Auditeur :** Claude orchestrateur (mode expert architecture)
**Public :** Owner — préparation prod restaurant + perspective SaaS B2B FR
**Méthodologie :** lecture directe code + Graphiti foodking + advisor cross-check
**Périmètre vérifié :** voir §0.2 (honnêteté de scope)

---

## 0. PRÉAMBULE

### 0.1 Contexte de l'audit

Cet audit complète la couche tactique (14 findings dans `plans/PLAN_AUDIT_F0XX_*.md`). Il opère un cran au-dessus : **structure, scalabilité, extensibilité multi-canaux, positionnement SaaS B2B**.

**But final exprimé par l'owner :**
1. Déployer FoodKing dans son fast-food (proche échéance).
2. Vendre la plateforme en SaaS B2B France à d'autres restaurants (modèle abonnement).

### 0.2 Scope de vérification (honnêteté)

| Domaine | Statut |
|---|---|
| Code services backend (OrderService, FrontendOrderService, FiscalSequenceService, AuditLogService, LoyaltyService, PaymentService) | ✅ Lu directement |
| Routes `routes/api.php` | ✅ Lu directement (527 routes) |
| Docs core (ARCHITECTURE_TECHNIQUE, SAAS_VISION, ORDER_FLOW, BUSINESS_RULES, PRICING_SSOT, DEVICE_FLOW, DATABASE_SCHEMA_CORE, OUTBOX_PATTERN, EVENT_CONTRACT, REALTIME_SETUP, FCM_SETUP, AUTHZ_MATRIX, DECISION_GRAPHIFY, GATES_DOCTRINE) | ✅ Lus |
| Migrations DB (~105) | ✅ Inspectées par grep |
| Composants Vue (POS, Kiosk Payment, KioskApp) | ✅ Lus partiellement |
| Graphiti foodking group | ✅ Pull contextuel |
| FCM réellement actif en prod | ❌ Non vérifié — uniquement documenté |
| App mobile customer existante | ❌ Non vérifiée — Flutter mentionné en doc, pas trouvé dans le repo |
| App mobile admin existante | ❌ Inexistante (déduction) |
| Couverture i18n / multi-langue runtime | ❌ Non vérifiée en profondeur |
| Statistiques concurrents (prix, intégrations exactes) | ❌ Non vérifiables — je décompose par catégorie de fonctionnalité, pas par compétiteur nommé avec stats |

Toute affirmation hors de la colonne ✅ est explicitement labellée "à vérifier" dans le texte.

---

## 1. 🔴 PRODUCTION BLOCKER — Configuration Queue (P0 séparé des F-0XX)

### 1.1 Le problème

Trois sources se contredisent et créent une bombe à retardement en prod :

| Source | Affirmation |
|---|---|
| `.env.example:73` | `QUEUE_CONNECTION=sync` (avec commentaire `[CRITICAL-PROD]`) |
| `docs/REALTIME_SETUP.md:88` | "QUEUE_CONNECTION=sync est suffisant car ShouldBroadcastNow" |
| `app/Events/OrderCreated.php:12` | "replacing direct ShouldBroadcastNow dispatch from this event class" |
| `app/Listeners/PersistOrderCreatedToOutbox.php` + `app/Jobs/DispatchDomainEventsJob.php` | Outbox pattern : events persistés en `domain_events`, dispatchés par queue worker |

**Réalité du code** : depuis le refactor outbox, les broadcasts NE SONT PLUS synchrones. Ils dépendent du queue worker.

### 1.2 Conséquence en prod

```
Fresh deploy avec .env.example (defaults sync) :
  Order created → DB transaction OK
  Listener PersistOrderCreatedToOutbox → INSERT domain_events row OK
  DB::afterCommit → DispatchDomainEventsJob::dispatch() → "queued" sur le driver `sync`
  Driver sync → exécute IMMÉDIATEMENT dans la même requête HTTP

  Mais en prod :
  - Si admin met QUEUE_CONNECTION=database/redis sans démarrer le worker
    → events s'accumulent en `domain_events` avec dispatched_at=NULL
    → KDS/OSS/POS reçoivent RIEN en realtime
    → Polling 30s masque cosmétiquement le défaut
    → Latence apparente 30s sur toutes les surfaces realtime
  - Si admin laisse sync en prod (suivant la doc obsolète)
    → chaque commande bloque la requête HTTP sur le broadcast Pusher
    → Si Pusher ralentit ou crash, l'API timeout
```

### 1.3 Sévérité

**P0 production blocker** distinct des F-001..F-014. Bloque tout déploiement prod fast-food et SaaS.

### 1.4 Fix recommandé

1. **Mettre à jour `docs/REALTIME_SETUP.md`** : retirer la phrase "sync est suffisant" + documenter le worker requis.
2. **Mettre à jour `.env.example`** : commenter `sync` en `[DEV ONLY]`, décommenter `redis` en default `[PROD]`.
3. **Ajouter health check** dans `HealthController::ready` qui vérifie qu'au moins 1 worker tourne sur la queue `high` (où DispatchDomainEventsJob est dispatché).
4. **Ajouter monitoring** : alerte ops si `domain_events.count(WHERE dispatched_at IS NULL AND created_at < NOW()-INTERVAL 30 SECOND) > 10`.
5. **Ajouter un test feature** : `tests/Feature/RealtimeOutboxDeliveryTest.php` qui crée un order, vérifie qu'un domain_events row apparaît, vérifie qu'il est dispatched dans les 5s avec un worker actif, et FAIL si dispatched=null après 5s sans worker.

→ Sub-plan dédié à créer : `PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md` (P0).

---

## 2. 🌳 FOUNDATION MAP — Arbre de structure actuelle (vérifié)

### 2.1 Arbre des entités

```
foodking (mono-tenant currently — V2 cible Tenant-per-DB)
└── Brand (implicit — pas de table dédiée actuellement)
    └── Branch (multi)                     ← isolation primaire (BranchScope)
        ├── Users
        │   ├── Admin             (Sanctum + role Admin)
        │   ├── Manager           (Spatie : pos, pos-orders, pos-discount-*, pos-manage-fiscal)
        │   ├── POS Operator      (Spatie : pos, pos-orders)
        │   ├── Chef              (Spatie : kds-orders)
        │   ├── Waiter            (Spatie : table-orders)
        │   └── Customer (role 2) (Sanctum guest/normal)
        ├── KioskMachines         (Sanctum + ability `kiosk:order`)
        ├── Orders                (POS Order class)
        │   └── OrderItems → Items / ItemVariations / ItemExtras
        ├── FrontendOrders        (Kiosk/Web class — partage table 'orders')
        ├── ZReports              (NF525 fiscal seal HMAC chain)
        ├── AuditLogs             (HMAC chain par branche)
        ├── DomainEvents          (outbox)
        ├── KioskPromos
        ├── UpsellRules
        └── ItemBranchAvailability
```

### 2.2 Arbre des surfaces (channels × devices × roles)

```
FoodKing (current state)
├── 🟢 POS Web (Vue 3 + vanilla JS wizard frozen)
│   ├── Caissier prend commande au comptoir
│   ├── Cash + Card (saisie 4 derniers chiffres) + Mobile banking + Other
│   ├── Print receipt → kioskHardware bridge (partagé avec kiosk)
│   ├── Drawer → kioskHardware.openDrawer (partagé)
│   └── KDS sync via Pusher channel `branch.{id}` + polling 30s
│
├── 🟢 Kiosk Vue + Electron Windows (PROTÉGÉ — voir §6.1)
│   ├── Idle → Wizard → Cart → Upsell → Promo → Payment → Confirm
│   ├── Cash (drawer signal) + Card TPE + Ticket Restaurant
│   ├── ESC/POS receipt → kioskHardware.printReceipt
│   └── KDS sync identique POS
│
├── 🟢 KDS (Vue 3)
│   ├── Liste commandes ACCEPT → PREPARING → PREPARED
│   ├── Subscribes Pusher channel `branch.{id}` events OrderCreated, OrderStatusChanged
│   └── Fallback polling 30s
│
├── 🟢 OSS (Vue 3)
│   ├── Affichage public file d'attente (PREPARING, PREPARED)
│   └── Subscribes idem
│
├── 🟢 Admin Backoffice (Vue 3 — Dashboard)
│   ├── Items, catégories, branches, users, taxes, coupons
│   ├── Reports : sales, items, credit balance, fiscal X/Z
│   └── Real-time analytics (channels, SLA alerts)
│
├── 🟡 API publique frontend (27 controllers)
│   ├── Address, Item, ItemCategory, Order, Loyalty, Promo, etc.
│   ├── Auth Sanctum customer (Login, Signup OTP, GuestSignup)
│   └── Surface API READY pour app mobile customer mais pas exploitée
│
├── 🔴 App mobile CUSTOMER → INEXISTANTE (uniquement référencée en doc FCM)
├── 🔴 App mobile ADMIN → INEXISTANTE
├── 🔴 Site web ordering white-label → INEXISTANT
├── 🔴 Drive-thru workflow → INEXISTANT
├── 🔴 Delivery integrations (Uber Eats, Deliveroo, Stuart, Just Eat) → frozen V2
└── 🔴 Multi-tenant SaaS root (subdomain, billing Stripe) → V2 vision uniquement
```

### 2.3 Arbre du flux de commande (state machine V1)

```
[*] → PENDING (1)
       ├→ ACCEPT (4)
       │   ├→ PREPARING (7)
       │   │   ├→ PREPARED (8)
       │   │   │   ├→ OUT_FOR_DELIVERY (10) → DELIVERED (13) → RETURNED (22, terminal)
       │   │   │   └→ DELIVERED (13)
       │   │   ├→ DELIVERED (13)        ← raccourci POS (permission `pos`)
       │   │   └→ CANCELED (16, reason)
       │   ├→ DELIVERED (13)            ← raccourci POS
       │   └→ CANCELED (16, reason)
       ├→ CANCELED (16, reason)
       └→ REJECTED (19, reason)

CANCELED, REJECTED, RETURNED = terminaux (admin only pour sortir)
```

---

## 3. ⚙️ AUDIT CONCURRENCE — État profond

### 3.1 Primitives recensées (108 occurrences vérifiées)

| Primitive | Localisation principale | Usage |
|---|---|---|
| `Cache::lock($key, $ttl)->block($wait)` | `OrderService` (queue), `FrontendOrderService` (idempotency + queue), `FiscalSequenceService`, `AuditLogService` | Mutex distribué (Redis-backed) |
| `lockForUpdate()` | `LoyaltyService`, `FiscalSequenceService`, `FrontendOrderService` (loyalty), `OrderController::paymentConfirm` | Verrou row-level MySQL |
| `DB::transaction(fn)` | Partout dans services | Atomicité multi-write |
| `DB::afterCommit(fn)` | Listeners outbox | Dispatch jobs uniquement après commit |
| `idempotency_key` UNIQUE constraint | `orders`, `frontend_orders` | Anti-duplicate au niveau DB |
| `domain_events.dispatched_at` | Outbox table | Tracking durable broadcasts |

### 3.2 Forces

✅ **FiscalSequenceService** : combo Cache::lock (5s TTL, 3s block) + lockForUpdate + transaction = NF525-grade.
✅ **AuditLogService** : Cache::lock par branche → serialise les writers, intégrité HMAC chain garantie.
✅ **LoyaltyService::redeem** : lockForUpdate sur user → empêche double-déduction de points.
✅ **Outbox pattern** : DB::afterCommit + DispatchDomainEventsJob → events durables, retries documentés.
✅ **108 occurrences ≠ surrespecté** — distribution équilibrée, pas de hot-spot pathologique.

### 3.3 Faiblesses identifiées

⚠️ **Cache::lock TTL 10s** sur queue_number alloc : si le path SSOT non-flag prend >10s (cf. F-005), le lock relâche → collision possible. Solution F-005 : monter à 30s + fallback préfixe Z monotonique.

⚠️ **Lock fiscal 5s TTL / 3s block** : tendu. Si une branche très chargée fait 1 order/sec et que le lock+next prennent 4s en queue, on a déjà des `LockTimeoutException`. À monitorer en prod.

⚠️ **Idempotency POS hors transaction** (cf. F-006) : check existing en mémoire avant DB::transaction → race condition possible → catch 23000 manquant côté POS (kiosk a, POS pas). Asymétrie.

⚠️ **lockBranchId fallback à 0** (cf. F-007) : si Auth::id() est null, lock cross-branche.

⚠️ **`broadcasting/auth` route protégée mais pas confirmée d'avoir un rate-limit**. À vérifier (potential DOS vector).

⚠️ **Queue worker dépendance silencieuse** (cf. §1) : c'est un hidden coupling fort qui n'est pas dans la doc.

### 3.4 Risques de concurrence non gérés actuellement

| Risque | Probabilité | Impact | Mitigation actuelle | Reco |
|---|---|---|---|---|
| Deux caissiers POS encaissent la MÊME commande table | Low | High | `lockForUpdate` sur Order avant changeStatus | OK |
| Stockout kiosk (item indispo entre cart & confirm) | Medium | Medium | `item_branch_availability` table + invalidation cache via listener | Vérifier que la commande FAIL si item indispo au commit (à vérifier — pas confirmé dans le code lu) |
| Z-report opened/close concurrent (2 admins) | Low | Critical | Throttle 10/min + lock dans ZReportService | À auditer pour confirmer |
| Multiple workers traitent le même DomainEvent | Medium | Medium | `attempts` increment + `dispatched_at` non-null check | OK si select FOR UPDATE dans le job (à vérifier) |

---

## 4. 📡 AUDIT SYNCHRONISATION — Outbox + Pusher + Polling + FCM

### 4.1 Architecture actuelle (vérifiée)

```
[Service] DB::transaction
    ├─ INSERT order...
    ├─ event(OrderCreated)
    │     └─ Listener PersistOrderCreatedToOutbox
    │           ├─ INSERT domain_events (channel=branch.X, broadcast_as=OrderCreated)
    │           └─ DB::afterCommit(fn)
    └─ COMMIT
         └─ DispatchDomainEventsJob::dispatch($id)->onQueue('high')
              └─ Worker
                  ├─ Read domain_events row
                  ├─ Validate envelope (EventContract::assertEnvelopeValid)
                  ├─ Pusher::trigger(channel, broadcast_as, payload)
                  ├─ MARK domain_events.dispatched_at = NOW()
                  └─ Si fail → retry exponential backoff [1, 5, 30, 300]
                              → final fail → set last_error
                              
[Frontend Echo client]
    ├─ Echo.private('branch.' + branchId).listen('OrderCreated', handler)
    ├─ Echo.private('branch.' + branchId).listen('OrderStatusChanged', handler)
    └─ Polling 30s en filet de sécurité
```

### 4.2 Forces

✅ Outbox pattern correctement implémenté (DB::afterCommit, retries, audit trail).
✅ `EventContract::assertPayloadValid` + `EventContract::assertEnvelopeValid` validations strictes.
✅ Frontend `eventContract.js` valide aussi à la réception (defense-in-depth).
✅ Polling 30s en fallback.
✅ Channel scoping branch — isolation OK (kiosk peut pas écouter d'autres branches via routes/channels.php).
✅ Versionning V1 + types canoniques (`order.created`, `order.status_changed`, etc.).

### 4.3 Faiblesses

⚠️ **§1 — Production blocker queue config** (déjà couvert).
⚠️ **`broadcasting/auth` JWT/Sanctum** — confirmer rate-limit anti-énumération channels.
⚠️ **FCM** : doc dit "implémenté" mais sans clés Firebase configurées en prod, push réels = 0. Verifier deploy state.
⚠️ **Pas de fallback HTTP long-polling** si WebSocket bloqué par firewall corporate (proxy strict).
⚠️ **Pas de session affinity** documentée pour le LB Pusher → si Soketi multi-instance non sticky, channels peuvent se désincrire après reconnexion.
⚠️ **Pas d'idempotency replay côté Echo client** — si l'event arrive 2× (réseau flaky), KDS peut afficher la même commande 2× avant que le state idempotency soit recalculé. Mitigation actuelle : SYNC-002 client-side dedup, à confirmer présent.

### 4.4 Comparaison avec besoins concurrentiels

| Besoin | Statut FoodKing |
|---|---|
| Realtime push <1s POS↔KDS | ✅ Outbox + Pusher (sous condition queue worker actif) |
| Notification client mobile (commande prête) | ⚠️ FCM doc mais deploy non vérifié |
| Notification staff (nouvelle commande web) | ⚠️ FCM idem |
| Multi-device session continuity (caissier change de poste) | ❌ Pas implémenté (Sanctum tokens device-bound implicite) |
| Event sourcing pour replay/audit | ⚠️ Outbox = événementiel mais pas event-sourcing complet |

---

## 5. 🏢 MULTI-TENANT READINESS

### 5.1 État actuel (vérifié)

- **Modèle** : mono-tenant, multi-branche.
- **Isolation** : `BranchScope` global Eloquent, applique `WHERE branch_id = userBranch` automatiquement.
- **Admin** : `branch_id = 0` → bypass scope, voit tout.
- **Tables** : aucune `tenants`, `companies`, `restaurants` (parent de branches).
- **Auth** : Sanctum tokens, pas de subdomain routing, pas de header `X-Tenant-ID`.

### 5.2 Cible V2 (vision SAAS_VISION.md)

Refactor majeur recommandé : **Tenant-per-DB**.

```
Cible V2
└── Tenant (saas_root.tenants)            ← nouvelle entité
    ├── Subscription Stripe SaaS          ← billing
    ├── Branches (isolated DB or schema)  ← migration cible
    │   ├── Users
    │   ├── Orders
    │   └── ...
    └── Sub-domain `tenantA.foodking.fr`  ← routing
```

### 5.3 Coût refactor estimé

| Composant | Effort approx | Risque |
|---|---|---|
| Schema `tenants` + migration `companies` (parent table) | 2-3 j | Low |
| Routing subdomain + tenant resolver middleware | 3-5 j | Medium |
| Refactor BranchScope → TenantScope (cascade) | 5-10 j | High (toutes les queries impactées) |
| Refactor Sanctum auth pour injecter tenant context | 3-5 j | Medium |
| Stripe SaaS billing intégration | 5-7 j | Medium |
| Onboarding flow nouveau resto (signup → DB provision → seeds) | 7-10 j | High |
| Tests d'isolation tenant (extension BranchIsolationTest) | 3-5 j | Critical (la sécu repose dessus) |
| Migration des données existantes (1 tenant initial) | 2-3 j | Medium |
| **TOTAL** | **30-48 jours-agent** | — |

### 5.4 Stratégie recommandée (alignée SAAS_VISION.md)

> Phase actuelle : **STABILISER mono-tenant**. Sécurité totale BranchScope → preuve qu'on saura isoler les Tenants.

Rester sur **Single DB + ajout tenant_id sur tables racines** est le **chemin court mais dangereux** (cf. SAAS_VISION §3 directives strictes : ⛔ NE PAS faire ça).

**Tenant-per-DB** est lourd mais sécurisé. Recommandé pour fast-food + SaaS B2B premium.

**Alternative : Tenant-per-Schema (MySQL)** — un schéma par tenant, code identique. Compromis intéressant.

---

## 6. 📱 EXTENSIBILITÉ MULTI-CANAUX

### 6.1 Frozen zones rappel (cf. mémoire owner)

| Zone | Status | Justification owner |
|---|---|---|
| POS wizard `public/js/pos-wizard.js` (5769 LOC) | 🔒 Frozen total | Visuel/design parfait |
| Kiosk wizard Vue (8 composants : `KioskWizardComponent`, `KioskPosWizardComponent`, `KioskCartComponent`, `KioskCategoriesComponent`, `KioskUpsellComponent`, `KioskPromoCarouselComponent`, `KioskOrderSummaryComponent`, `KioskProductListComponent`) | 🔒 Frozen code, tests OK | "Presque parfaite, ne modifie pas, mais tests OK" |

### 6.2 Surface API publique disponible (vérifié — 27 controllers, 527 routes)

```
/api/frontend/* (auth Sanctum customer + apiKey middleware)
├── address/         CRUD
├── branch/          GET (liste, by lat-long)
├── coupon/          GET checking
├── item/            GET (list, featured, popular, details, upsell, kiosk-upsell)
├── item-category/   GET (list, by slug)
├── language/        GET
├── loyalty/         CRUD (auth required pour add-points/redeem)
├── menu/            GET (kiosk unified payload)
├── message/         CRUD (auth)
├── offer/           GET
├── order/           CRUD (POST = create order, store, cancel via change-status)
├── page/            GET CMS pages
├── payment/         CRUD payment gateway flow
├── pricing/preview  POST SSOT recalc no persist
├── profile/         CRUD user
├── promo/           POST validate
├── slider/          GET banners
├── subscriber/      POST email subscribe
├── timezone/        GET
└── kiosk-event/     POST telemetry
```

→ **Surface API quasi-complète** pour alimenter une app mobile customer ou un site web ordering. Le travail mobile/web est **principalement frontend**, le backend existe.

### 6.3 Manques identifiés pour mobile customer

| Manquant | Effort | Criticité |
|---|---|---|
| Endpoint `POST /api/frontend/fcm-token/register` (associer token FCM au user) | 0.5 j | High (notif push commande prête) |
| Endpoint `POST /api/frontend/fcm-token/unregister` | 0.2 j | Medium |
| Endpoint `GET /api/frontend/order-status-stream/{order_id}` (SSE alternative à WebSocket pour mobile) | 1 j | Medium |
| Endpoint `POST /api/frontend/payment/intent` (Stripe Payment Intent server-side) | 1 j | High (Apple Pay / Google Pay) |
| Resource `OrderTimelineResource` (étapes commande pour UI tracking) | 0.5 j | Medium |
| Endpoint `POST /api/frontend/feedback/{order}` (rating + commentaire post-livraison) | 0.5 j | Medium |
| Multi-langue tous les endpoints (header `Accept-Language` + locale routing) | 2-3 j | High (FR-EN minimum) |

### 6.4 Manques identifiés pour admin mobile

| Manquant | Effort | Criticité |
|---|---|---|
| Surface API admin mobile-friendly (existe dejà mais pas optimisée mobile) | 3-5 j (review + audit RBAC mobile-context) | High |
| Endpoint `POST /api/admin/2fa` pour MFA (mobile = device jail-broken, sécurité ++) | 2 j | High |
| Endpoint dashboard temps réel `GET /api/admin/dashboard/realtime` (déjà partiel) | 1 j | Medium |
| Endpoint `POST /api/admin/order/{id}/manual-action` (admin override sur le mobile) | 1 j | High |

### 6.5 Manques identifiés pour site web ordering

Frontend principalement (Vue 3 ou Next.js), backend déjà OK. Effort UI: 15-25 jours.

---

## 7. 🥊 BENCHMARK CONCURRENTIEL — Décomposition par catégorie de fonctionnalité

> ⚠️ **Avertissement** : ce qui suit est basé sur une connaissance générale du marché restaurant SaaS FR au moment de mon training. **Pricing, intégrations exactes et features récentes doivent être validés** par un benchmark commercial à jour avant tout positionnement marketing.

### 7.1 Acteurs FR/EU pertinents pour QSR / fast-food

Sans citer de stats fragiles : on retrouve dans le segment **fast-food / QSR France** des acteurs comme Lightspeed Restaurant (ex-iKentoo), Tiller (groupe SumUp), Cashpad, Innovorder (spécialiste kiosk FR), L'Addition, Zelty, et — pour la couche paiement — Square Restaurants, plus quelques niches FR. Le marché est mature côté POS + kiosk + KDS, en bouillonnement côté delivery + AI + analytics.

### 7.2 Décomposition par catégorie — où FoodKing se situe

Légende : 🟢 mature / 🟡 partiel / 🔴 absent

| # | Catégorie | Standard concurrence | FoodKing |
|---|---|---|---|
| 1 | **POS comptoir rapide (<2s/order)** | 🟢 Universel | 🟢 (sous condition F-006 idempotency fix) |
| 2 | **Kiosk self-order multi-langue** | 🟢 Présent partout | 🟢 V1.x prod-ready |
| 3 | **KDS (Kitchen Display)** | 🟢 Universel, certains avec AI prio | 🟢 Basique solide |
| 4 | **OSS / queue display** | 🟢 Universel | 🟢 |
| 5 | **NF525 fiscal compliance FR** | 🟢 Pré-requis FR | 🟢 (sous condition F-001 kiosk fiscal) |
| 6 | **Web ordering channel (white-label)** | 🟢 Universel maintenant | 🔴 Absent — backend API ready |
| 7 | **App mobile customer** | 🟢 Standard premium | 🔴 Absente |
| 8 | **App mobile admin** | 🟡 Disponible chez certains | 🔴 Absente |
| 9 | **Click & Collect dédié** | 🟢 Universel | 🟡 Takeaway générique, pas de C&C dédié |
| 10 | **Drive-thru workflow** | 🟡 QSR-spécifique, présent chez Innovorder/Zelty | 🔴 Absent |
| 11 | **Delivery integrations (Uber Eats, Deliveroo, Just Eat, Stuart)** | 🟢 Pré-requis QSR | 🔴 Frozen V2 |
| 12 | **Loyalty + CRM customer** | 🟢 Universel | 🟢 Basique (loyalty_points, codes) |
| 13 | **Marketing automation (campagnes, segments)** | 🟡 Premium | 🔴 Absent |
| 14 | **Inventory + stock tracking** | 🟢 Universel | 🔴 Absent (BUSINESS_RULES.md "v2") |
| 15 | **Recipe / cost management** | 🟡 Premium | 🔴 Absent |
| 16 | **Multi-location dashboard + cross-store transfers** | 🟢 Universel multi-resto | 🟡 Multi-branche OK, pas de transfer |
| 17 | **Staff management (rotation, time clock, payroll prep)** | 🟢 Premium standard | 🔴 Absent |
| 18 | **Reservations / table booking** | 🟢 Tablerie focus | 🔴 Absent (V1 pas de dine-in) |
| 19 | **Multi-paiement (CB, Apple Pay, Google Pay, Ticket Resto, Voucher)** | 🟢 Pré-requis | 🟡 Cash + Card + Mobile banking + Other ; pas Apple/Google Pay natif ; pas Ticket Resto agréé natif |
| 20 | **Split bill / partage addition** | 🟢 Universel | 🟡 Documenté en tests `SplitPaymentEndToEndTest` — vérifier UI |
| 21 | **Tip management** | 🟢 Universel | 🔴 Absent |
| 22 | **Gift cards** | 🟢 Universel premium | 🔴 Absent |
| 23 | **Accounting integration (Pennylane, Sage, Cegid, QuickBooks)** | 🟢 Pré-requis FR | 🔴 Absent (Excel export uniquement) |
| 24 | **Open API publique pour intégrations tierces** | 🟡 Premium | 🟡 API existe mais pas documentée publique avec clés/webhooks |
| 25 | **Multi-currency** | 🟡 Pertinent multi-pays | 🟡 Configurable, pas multi-currency runtime |
| 26 | **Multi-langue UI complète** | 🟢 Pré-requis EU | 🟡 i18n présent (langs/, locale middleware), couverture à vérifier |
| 27 | **Allergens + labels** | 🟢 Pré-requis FR (INCO) | 🟢 `OrderItemAllergenSnapshot` NF525 ready |
| 28 | **Reporting détaillé (heatmap, marges, retention)** | 🟢 Pré-requis premium | 🟡 Sales/Items reports basiques |
| 29 | **Hardware POS partnership (TPE, imprimantes)** | 🟢 Pré-requis | 🟡 Adapter `kioskHardware` agnostique — drivers à plugger |
| 30 | **AI / Voice ordering / chatbot** | 🟡 Émergent | 🔴 Absent |
| 31 | **Pricing dynamique (happy hour auto)** | 🟡 Premium | 🔴 Absent |
| 32 | **Offline mode (kiosk / POS si net down)** | 🟡 QSR-spécifique | 🟡 Doc kiosk mode offline mentionné, deploy non vérifié |
| 33 | **Onboarding self-service nouveau resto (SaaS)** | 🟢 Pré-requis SaaS | 🔴 Absent |
| 34 | **Stripe SaaS billing (abonnement)** | 🟢 Pré-requis SaaS | 🔴 Absent (V2 vision) |
| 35 | **Multi-tenant isolation absolue** | 🟢 Pré-requis SaaS | 🔴 Mono-tenant actuel |

### 7.3 Synthèse compétitive

**Forces FoodKing :**
- NF525 fiscal compliance native (rare et précieux en FR)
- Kiosk + POS + KDS + OSS bout-en-bout (intégration verticale)
- Outbox pattern + audit chain HMAC = fondations solides
- Test coverage 136 tests + multi-niveau (unit, feature, e2e Playwright)
- Architecture modulaire — extensible

**Faiblesses critiques pour SaaS B2B :**
- ❌ **Pas de mobile customer** : élimine de fait le segment "app first" QSR jeune.
- ❌ **Pas de delivery integrations** : élimine 30-50% du CA QSR moderne.
- ❌ **Pas de staff management** : restaurants moyens veulent un all-in-one.
- ❌ **Pas de inventory / cost** : restaurants moyens-grands l'exigent.
- ❌ **Pas de multi-tenant SaaS** : le produit ne peut pas être vendu en self-service à un nouveau resto.

---

## 8. 🎯 FONCTIONNALITÉS MANQUANTES PRIORISÉES

### 8.1 Priorisation par phase business

> **But 1 — Déployer dans le fast-food owner (court terme) :** P0+P1 audit tactique + P0 queue config. Suffisant fonctionnellement.

> **But 2 — Vendre en SaaS B2B (moyen terme) :** ajouter les fonctionnalités SaaS-readiness + customer-facing channels.

### 8.2 Priorité P0 (bloquant prod owner)

| # | Item | Effort |
|---|---|---|
| Q1 | Production blocker queue config (§1) | 1 j |
| F-001 → F-003 | Audit tactique P0 (NF525 kiosk + TPE amount echo + cash reconcile) | 7 j |

### 8.3 Priorité P1 (bloquant SaaS B2B)

| # | Item | Effort |
|---|---|---|
| S1 | Multi-tenant refactor (Tenant model + DB strategy) | 30-48 j |
| S2 | Mobile customer app (Flutter / React Native) — minimum viable : commande + tracking + loyalty | 30-45 j (frontend) + 3-5 j (backend extension) |
| S3 | Delivery integrations Uber Eats / Deliveroo (au moins 1 native) | 10-15 j |
| S4 | Inventory + stock V1 (track + low alerts) | 15-20 j |
| S5 | Stripe SaaS billing + onboarding self-service | 15-20 j |
| S6 | Web ordering channel white-label | 20-30 j |

### 8.4 Priorité P2 (différenciation marché)

| # | Item | Effort |
|---|---|---|
| D1 | Mobile admin app | 25-35 j |
| D2 | Staff management (time clock, scheduling) | 20-25 j |
| D3 | Recipe + cost management | 15-20 j |
| D4 | Marketing automation (campagnes email/push, segments) | 20-25 j |
| D5 | Click & Collect workflow dédié | 5-7 j |
| D6 | Drive-thru workflow | 10-15 j |
| D7 | Tip management | 3-5 j |
| D8 | Gift cards | 5-7 j |
| D9 | Accounting integrations (Pennylane, Cegid au minimum) | 10-15 j |

### 8.5 Priorité P3 (premium, long terme)

| # | Item | Effort |
|---|---|---|
| A1 | AI-powered upsell + voice ordering | 30+ j |
| A2 | Pricing dynamique (happy hour auto) | 10 j |
| A3 | Reservations / table booking | 15 j (couplé à dine-in V2) |
| A4 | Multi-currency runtime | 7-10 j |
| A5 | Open API publique avec API keys + webhooks customer | 15-20 j |

---

## 9. 🗺️ ROADMAP STRATÉGIQUE 12-24 MOIS

> Détail dans `plans/ROADMAP_SAAS_B2B_2026-05-07.md`. Synthèse ici.

```
M1-M2  : Phase 0 — STABILIZE
         • F-001..F-014 audit tactique
         • Queue config blocker
         • Doc REALTIME_SETUP corrigée
         • Health checks worker + monitoring
         • Déploiement fast-food owner

M3-M5  : Phase 1 — SAAS FOUNDATION
         • Multi-tenant refactor (Tenant-per-Schema MySQL recommandé)
         • Stripe SaaS billing
         • Onboarding self-service
         • RBAC tenant-aware

M4-M7  : Phase 2 — CUSTOMER REACH (parallèle Phase 1)
         • Mobile customer app (Flutter ou React Native)
         • Web ordering white-label
         • FCM tokens + endpoint register
         • Apple Pay / Google Pay via Stripe Payment Intents

M6-M9  : Phase 3 — DELIVERY + INVENTORY (parallèle)
         • Delivery integrations (Uber Eats, Deliveroo native)
         • Inventory + stock V1
         • Click & Collect dédié

M9-M12 : Phase 4 — OPS PREMIUM
         • Mobile admin app
         • Staff management
         • Recipe + cost
         • Accounting integrations FR (Pennylane, Cegid)

M12-M18 : Phase 5 — MARKETING + DIFFÉRENCIATION
         • Marketing automation
         • Reporting avancé (heatmap, marges, retention)
         • Loyalty avancée (tier-based, gift cards, referral)
         • Open API public

M18-M24 : Phase 6 — IA + INNOVATION
         • AI-upsell
         • Voice ordering
         • Pricing dynamique
         • Drive-thru workflow
```

---

## 10. ⚠️ REGISTRE DES RISQUES STRATÉGIQUES

| # | Risque | Probabilité | Impact business | Mitigation |
|---|---|---|---|---|
| R1 | Queue worker silencieusement absent en prod (§1) | High | Critical | §1 fix obligatoire avant deploy |
| R2 | Multi-tenant refactor introduit data leak inter-tenant | Medium | Critical | Tests tenant-isolation exhaustifs (extension BranchIsolationTest), code review systématique, beta restreint |
| R3 | Concurrent Innovorder/Zelty positionne offre full-stack QSR à prix cassé | Medium | High | Différenciation : NF525 native, audit chain HMAC, open architecture |
| R4 | Délais multi-tenant trop longs → perte de fenêtre marché | Medium | High | Phase 0 stabilisation rapide pour cash-flow propre owner ; Phase 1 SaaS en parallèle, pas en série |
| R5 | Dépendance Pusher/Soketi (licence ou panne) | Low | Medium | Outbox pattern permet replay si Pusher down ; envisager fallback Ably ou self-hosted Soketi managed |
| R6 | Compliance NF525 évolue (régulation FR) | Medium | High | Veille DGFiP + tests Z-report regression suite |
| R7 | Lock-in client B2B faible (concurrent peut copier rapidement) | High | Medium | Différenciation par audit chain immutable + intégrations FR profondes |
| R8 | Hardware partnership lent à mettre en place (drivers TPE) | Medium | Medium | API `kioskHardware` agnostique = bon abstraction ; partenariats Ingenico/Verifone/Epson à initier dès Phase 0 |
| R9 | Cybersécurité : token kiosk leaké (compromis device) | Medium | High | Sanctum abilities `kiosk:order` restreintes ; rate-limit ; rotation token planifiée à V2 |
| R10 | Régression frozen wizards lors du refactor V2 | Medium | High | Frozen-zone strict, tests Vitest/Playwright sur le wizard kiosk (autorisé), pas de touche POS wizard sans gate |

---

## 11. RECOMMANDATIONS DE GOUVERNANCE

1. **Geler scope V1** : terminer audit tactique F-001..F-014 + queue config Q1 avant de toucher Phase 1.
2. **Multi-tenant Tenant-per-Schema MySQL** plutôt que Tenant-per-DB : compromis effort/risque optimal.
3. **Mobile-first customer** : commencer par PWA + Capacitor avant native si capital limité — accélère le go-to-market.
4. **Delivery integrations** : commencer par 1 (Uber Eats) en API officielle avant les 4. Permet apprentissage.
5. **Beta SaaS restreint** : 3-5 restaurants pilotes en M5-M7, retour produit avant scale.
6. **Kept tactical and strategic separate** : ne pas mélanger F-0XX (tactique) avec roadmap stratégique. Les 2 cycles vivent en parallèle.
7. **Document de vision client SaaS** : pricing tiers (Starter / Pro / Enterprise), ROI claims, onboarding promesse <30 min.

---

## 12. NEXT STEPS POUR L'OWNER

1. **Lire** ce document + les 2 supporting (`COMPETITOR_GAP_ANALYSIS.md`, `ROADMAP_SAAS_B2B.md`).
2. **Décider** la stratégie multi-tenant : Tenant-per-DB vs Tenant-per-Schema vs Single DB + tenant_id.
3. **Acter Q1** (queue config) en P0 — créer sub-plan F-015 ou exécuter directement.
4. **Valider la phase 0** (stabilization owner-deploy) en cycle court 4-6 semaines.
5. **Décider** stack mobile customer : PWA + Capacitor / Flutter / React Native.
6. **Initier partenariats hardware** dès maintenant (Ingenico, Verifone, Epson, Star).

---

## 13. SIGNATURE

- **Audit conduit par** : Claude orchestrateur (mode expert architecture)
- **Date** : 2026-05-07
- **Méthodologie** : lecture directe code + Graphiti + advisor cross-check
- **Évidence** : référencée file:line ou explicitement marquée "non vérifiée"
- **Honnêteté de scope** : §0.2
- **Mémoire** : Graphiti `foodking` group sera mis à jour à la livraison

— *Vision d'abord, fondations ensuite, fonctionnalités après. Aucune ne vaut sans les deux autres.*
