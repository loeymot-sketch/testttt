# CLAUDE — CAISSE V1 · PLAN D’ORCHESTRATION FULLSTACK 360°

**Auteur** : Claude (architecte-orchestrateur)  
**Cible** : Codex CLI (codex-extension, gpt-5.5-pro xhigh) en boucle MASTERPLAY  
**Cadre** : `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` + `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` + `docs/ORDER_FLOW.md` + `docs/gates/GATE_LOG.md` + audit prior `CLAUDE_ULTRA_ORCHESTRATION_REVIEW_2026-04-25.md`  
**Statut** : `PLAN_FULLSTACK_VERSION: 1.0` · `READY_FOR_CODEX_LOOP: YES (sous garde-fous)` · `READY_FOR_PRODUCT_CHANGE_ON_FROZEN: NO_UNTIL_HUMAN_REGULARIZATION`

**Note outillage** : le script `audit-brief` ne prend pas de `TASK_ID`. Pour les audits Claude **par mission** (action C-1), utiliser `bash scripts/foodking-claude-orchestrate.sh audit "…"` avec le périmètre explicite (fichiers + rapports GPT) — voir `AGENTS.md` § Terminal Claude.

---

## Exécutif

Caisse V1 a déjà fermé la Vague A (M-19, M-01, M-02, M-12, M-16, M-18, M-20, M-21a, M-03) et 8 missions Vague B (M-09, M-06, M-05, M-04B, M-08, M-07, M-10, M-17). La file `MASTERPLAY_QUEUE.md` montre 17/24 missions CLOSED. **Mais la chaîne d’attestation est cassée** : (i) les 8 gates Wave B sont signés `Codex (instruction humaine explicite)` dans `docs/gates/GATE_LOG.md` L39-47 — violation directe de `human-gates.mdc` (no self-approval) ; (ii) toutes les CLOSED Wave B le sont via `GPT_FINAL_AUDIT: PASS` sans `AUDIT_VERDICT: PASS` Claude opposable (mode `FOODKING_GPT_ONLY=1`). Conséquence : **interdiction d’avancer M-13/M-15/M-21b/M-22 sur frozen** sans deux régularisations préalables (H-1 humain, C-1 Claude rétrospectif). Ce plan est conçu pour donner à Codex une bible 10+ jours **dense, ancrée file:line, sans réinventer ce que la matrice FK couvre déjà**, et discipline la boucle en lots ≤4h avec garde-fous (allowlist, mandatory_tests, double audit, GATE_LOG humain). Tranches : **A = POS** (encaissement, remises, multi-tender, park, reprise, sync temps réel) ; **B = Kiosk** (panier, TPE, queue offline, file d’attente, fiscal routing) ; **C = Connectivité globale** (matrice double entrée Source→Consommateur). Pour chaque tranche, un catalogue fonctionnel par écran/composant majeur, un catalogue d’abus (fuzz, replay, forge, race, leak) à automatiser AVANT GO, une grille d’audit 360° par interface/route critique, une roadmap 10 lots Codex (chacun fini par diff allowlist + tests verts + mini-rapport + GPT_FINAL_AUDIT + AUDIT Claude). Priorités P0 inviolables : pricing SSOT backend, OrderStatus enum, branch_id strict (=, jamais LIKE), dispatch après commit, symétrie OS/FOS, frozen zones sous gate humain réel. La séquence respecte l’arbitrage Claude (sécurité/branches d’abord, puis quote, puis paiement, puis fiscal, puis KDS/release, puis kiosk runtime, puis ops/canary/observabilité). Aucun gate signé par Codex ne sera reconnu : signature humaine nominative + commit SHA dans `GATE_LOG` est un prérequis dur de toute sortie de zone gelée. **Verdict d’ouverture : HEAL — la file produit du résultat mais doit être remise en conformité avant tout nouveau passage `PENDING → RUNNING` sur frozen.**

---

## Carte des surfaces

```
┌────────────────────────────────── FOODKING CAISSE V1 — TOPOLOGIE ──────────────────────────────────┐
│                                                                                                    │
│   CLIENT                       CAISSIER                  CUISINE                  CLIENT/SUPPORT   │
│   ┌──────────┐                 ┌──────────┐              ┌──────────┐             ┌──────────┐    │
│   │  KIOSK   │                 │   POS    │              │   KDS    │             │   OSS    │    │
│   │ (borne)  │                 │ (caisse) │              │ (cuisine)│             │ (écran)  │    │
│   └────┬─────┘                 └────┬─────┘              └────┬─────┘             └────┬─────┘    │
│        │ Sanctum kiosk              │ Web + Bouncer            │ Bouncer (chef)          │ Public    │
│        │ ability=kiosk:order        │ permission=pos          │ permission=kds-bump     │ token=oss │
│        ▼                           ▼                          ▼                         ▼          │
│   ┌────────────────────────────────────────────────────────────────────────────────────────────┐ │
│   │                            BACKEND LARAVEL — SOT                                            │ │
│   │                                                                                              │ │
│   │   ┌──────────────────┐      ┌─────────────────────┐      ┌────────────────────────────┐   │ │
│   │   │ FrontendOrder    │ ←──► │     OrderService     │ ◄──► │ KitchenDisplaySystemOrder  │   │ │
│   │   │ Service (kiosk)  │ SYM  │   (POS + admin)      │ SYM  │   Service (KDS)            │   │ │
│   │   └──────────────────┘      └─────────────────────┘      └────────────────────────────┘   │ │
│   │            │                          │                              │                     │ │
│   │            ▼                          ▼                              ▼                     │ │
│   │   ┌──────────────────────────────────────────────────────────────────────────────────┐    │ │
│   │   │  PricingService (SSOT) · OrderStateMachine (V1) · PaymentService (pilote OptB)    │    │ │
│   │   │  OrderQuoteService (M-05) · FiscalSealingService (M-08, NF525) · LoyaltyService   │    │ │
│   │   └──────────────────────────────────────────────────────────────────────────────────┘    │ │
│   │            │                          │                              │                     │ │
│   │            └──────────────► DB MySQL (orders, frontend_orders, payment_*, fiscal_*) ──────┤ │
│   │                                       │                              │                     │ │
│   │                            ┌──────────┴──────────┐         ┌─────────┴──────────┐          │ │
│   │                            │  Outbox/DomainEvent │ ──────► │ Pusher/Echo (WS)   │          │ │
│   │                            │ DispatchAfterCommit │         │ private-branch.{id}│          │ │
│   │                            └─────────────────────┘         └────────────────────┘          │ │
│   └──────────────────────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                                    │
│   Hardware bridge : TPE (Worldline / Ingenico), printer ESC/POS, drawer (POS) ; touchscreen +     │
│   NFC + scanner (Kiosk) ; failover réseau Wi-Fi/4G ; horloge (synchro fiscale).                   │
│                                                                                                    │
│   Frozen zones (édition sous gate humain réel uniquement) :                                       │
│     · app/Services/OrderService.php · app/Services/FrontendOrderService.php                       │
│     · app/Services/PaymentService.php · app/Services/Fiscal/* · app/Domain/Order/OrderStateMachine│
│     · routes/api.php · app/Http/Controllers/Frontend/OrderController.php                          │
│     · resources/js/components/admin/pos/PaymentComponent.vue (prop mutation gate)                 │
│     · migrations/* (Schema gate Option A)                                                          │
└────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

**Surfaces hors V1 mais à garder sous lint** : `kiosk_implementation/` (legacy archive), `borne (Remix)/` (prototype), `pos-wizard.js` (legacy POS) — guards `M-12` actifs (LegacyImportGuardLintTest, BundleScanLegacyTest).

---

## Matrice de synchronisation

Lecture : ligne = **producteur** (qui crée l’événement / la mutation) · colonne = **consommateur** (qui doit voir ou subir l’effet). Cellule = `donnée transportée | mécanisme | invariant attendu | échec partiel`. P0 si rupture monnaie/statut/branche/fiscal.


| Source ↓ / Cible →                                                                                     | POS                                               | Kiosk                       | KDS                                                                                                                                                                                                        | OSS                                                                           | Fiscal Z                                                                           | Reports/Analytics                              | Notif (push/Echo)                                     |
| ------------------------------------------------------------------------------------------------------ | ------------------------------------------------- | --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- | ---------------------------------------------- | ----------------------------------------------------- |
| **POS commit (`OrderService::posOrderStore`)** L566+                                                   | self                                              | ❌ (silos)                   | `OrderCreated` outbox → `KitchenDisplaySystemOrderService` query (filter status≥ACCEPT, payment_status=PAID) ; **invariant** : KDS ne voit que post-commit ; **race** : si dispatch in-tx → ticket fantôme | `OrderStatusChanged` → `OrderStatusScreenOrderService:65` filtre exact branch | `FiscalSealingService` invoqué sur `DELIVERED` ou `finalize` selon Option B fiscal | sales report agg (`salesReportOverview` L1920) | `private-branch.{branch_id}` event `order.created`    |
| **Kiosk commit (`FrontendOrderService::myOrderStore`)** L123+                                          | POS list (admin) via outbox + filter exact branch | self                        | KDS quand `payment_status=PAID` (kiosk auto via `finalizePaidKioskOrder` L791)                                                                                                                             | OSS bip + clignotement quand PREPARED                                         | Option B fiscal kiosk : finalize côté POS (gate `GATE_FISCAL_KIOSK_SCOPE_V1`)      | analytics `kiosk.commit` + abandon funnel      | event `order.created`                                 |
| **Kiosk `payment-confirm`** `OrderController.php:77-151`                                               | (POS pas concerné sauf cash kiosk via M-06)       | self                        | release cuisine après PAID                                                                                                                                                                                 | OSS waiting → ACCEPT                                                          | ledger paiement (M-04B Option B pilote restreint)                                  | `payment_success_rate` KPI                     | event `order.paid`                                    |
| **POS multi-tender / multi-payment** PaymentComponent + `TransactionService`                           | self                                              | ❌                           | KDS unchanged si statut déjà ACCEPT                                                                                                                                                                        | OSS unchanged                                                                 | fiscal : 1 transaction = 1 ligne ledger ; somme tenders = total quote              | `payment.failure_rate`, `tender.mix`           | event `order.paid` (1 émission par finalisation)      |
| **POS park (`pos_parked_orders`)**                                                                     | self                                              | ❌                           | ❌ (pas de release tant que park)                                                                                                                                                                           | ❌                                                                             | ❌                                                                                  | `pos.parked_count`                             | ❌                                                     |
| **POS reprise commande**                                                                               | self                                              | ❌                           | KDS si transition PENDING→ACCEPT générée                                                                                                                                                                   | OSS si client connu                                                           | fiscal : pas de Z impact                                                           | analytics reprise                              | event `order.resumed` (à attester)                    |
| **KDS bump (`OrderStatusRequest`)** L45-47 + `KitchenDisplaySystemOrderService::changeStatus` L117-168 | POS visibility temps réel                         | ❌                           | self                                                                                                                                                                                                       | OSS : flux PREPARED → bip + clignotement                                      | fiscal : ne déclenche pas Z (sauf DELIVERED via POS shortcut)                      | `kds.bump_p99` latency                         | event `order.status_changed`                          |
| **KDS multi-écran (2 chefs simultanés)**                                                               | POS visibility                                    | ❌                           | self                                                                                                                                                                                                       | OSS visibility                                                                | fiscal idem                                                                        | `kds.race_conflict`                            | event idem (idempotent attendu via `expected_status`) |
| `**OrderService::changeStatus`** L1489-1540                                                            | self admin                                        | (rare)                      | KDS si transition release                                                                                                                                                                                  | OSS                                                                           | fiscal selon transition (DELIVERED)                                                | `status_change_rate`                           | event `order.status_changed`                          |
| `**OrderService::changePaymentStatus**` L1661                                                          | self                                              | **absent FOS** (divergence) | KDS si PAID nouveau                                                                                                                                                                                        | OSS                                                                           | fiscal ledger update                                                               | `payment.status_change`                        | event `order.paid`                                    |
| `**destroy` (void)** L1783 + dispatch L1793-1795                                                       | self                                              | (rare)                      | KDS retire ticket                                                                                                                                                                                          | OSS retire                                                                    | fiscal : voucher void pré/post-Z                                                   | `void_rate`                                    | event `order.voided`                                  |
| **CleanupStalePendingKioskOrders (Job)**                                                               | (rare)                                            | UI offline notif            | retire ticket                                                                                                                                                                                              | retire OSS                                                                    | flag réconciliation TPE (FK-029, M-06 sub3)                                        | `cleanup.timeouts`                             | event `order.rejected` (race avec `payment-confirm`)  |
| **Cash collect kiosk (POS path)**                                                                      | self                                              | front kiosk transitionne    | release après cash collect (M-06 sub2)                                                                                                                                                                     | OSS waiting close                                                             | fiscal : kiosk option B → POS finalize Z                                           | `cash.kiosk_volume`                            | event `order.paid`                                    |
| **Fiscal Z fin de service** `ZReportService`                                                           | self admin                                        | ❌                           | ❌                                                                                                                                                                                                          | ❌                                                                             | self (chain HMAC, NF525)                                                           | `fiscal_anomaly`, Z mismatch                   | ❌                                                     |
| **Web public payment** (Stripe)                                                                        | hors V1 (gate B)                                  | ❌                           | ❌                                                                                                                                                                                                          | ❌                                                                             | ❌                                                                                  | `web.payment_attempts_blocked`                 | ❌                                                     |


**Mécanismes transverses** :

- **Outbox/DomainEvent** + `DispatchDomainEventsJob` (ORDER_FLOW.md L98). Invariant FK-070 : `Availability event` peut partir avant commit → sentinel `AfterCommitDispatchTest` (M-14).
- **WS canal** : `private-branch.{branch_id}` (Pusher). Cross-branch leak = P0 (FK-008/021/033/040 → M-09 CLOSED, mais re-validation NEW-04 G2 demandée par audit prior R-15).
- **Idempotence** : `idempotency_key` côté commit POS/Kiosk + `payment_provider_reference` unique côté ledger (FK-028, M-04B Option B).
- **Symétrie OS/FOS** : table de correspondance `myOrderStore`, `changeStatus`, `cashBack`, `refundPoints` (PLAN_GPT §2.2). `changePaymentStatus` **absent FOS** = divergence connue (M-10 CLOSED mais à re-vérifier C-1).
- **Reprises échec** : `outbox` rescue (M-14), reconnect-storm Pusher (NEW-02 verified 2026-04-23, à re-spot dans M-22).

---

## Catalogue fonctionnel POS

> Granularité demandée : par écran / composant majeur. Réf. : `resources/js/components/admin/pos/*.vue`, `app/Http/Controllers/Admin/PosController.php`, `app/Http/Controllers/Admin/PosOrderController.php`, `app/Services/OrderService.php`, `app/Services/PaymentService.php`, `app/Services/TransactionService.php`.

### A.1 Écran d’accueil / sélection mode (Dine-in / Takeaway / Drive)

- **Composant** : `PosComponent.vue` (entrée), `pos-v4.blade.php` (Option D cutover en cours, gate `HG-W2-1` `PENDING_HUMAN_GATE`).
- **Rôle** : router POS V3 vs V4, choisir order_type, brancher contexte branche.
- **API** : `GET /api/admin/pos/bootstrap` (config), `GET /api/admin/pos/menu` (catalogue cache).
- **Events Echo** : `private-branch.{id}` `menu.updated`, `branch.theme.updated`.
- **Invariants** : `branch_id` strict (FK-008/021/033) ; pas de cache cross-branch (M-09 CLOSED).
- **Test reprise** : `tests/js/posBootConfig.spec.js` (FK-035, deferred LOT-0).

### A.2 Sélection menu / cartes produits / variations / addons

- **Composant** : `PosMenuComponent.vue`, `MenuItemCard.vue`, `VariationModal.vue`.
- **Rôle** : afficher items disponibles (filtre `item_branch_availability` — gate V1_MENU_86), gérer addons, variations multi-qty (gate G14A consolidé).
- **API** : `POST /api/admin/order/preview` (à attester — re-cálcul SSOT), `GET /api/menu/categories`.
- **Invariants** : pricing front = lecture seule ; aucun calcul total côté JS (FK-017 P0 résolu via M-05 OrderQuote, à re-attester C-1).
- **Test reprise** : `PosTotalServerAuthoritativeSentinelTest` (FK-017), `PosSubtotalForgerySentinelTest` (FK-018).

### A.3 Panier / récap / discount / coupon

- **Composant** : `PosCartComponent.vue`, `PosDiscountComponent.vue` (`PosComponent.vue:423-425` `v-model="discount"`, FIND-01).
- **Rôle** : ajouter, retirer, appliquer remise, coupon, motif.
- **API** : `POST /api/admin/coupon/apply`, `POST /api/admin/order/quote` (M-05 NEW), recalc backend.
- **Invariants** : remise pos décidée sur subtotal **backend** (M-06 sub5) ; `discountReason` lié `v-model` (FK-079 LOT-0 résolu par M-21a CLOSED).
- **Test reprise** : `PosDiscountReasonBindingSentinelTest` (FK-079), `PosSubtotalForgerySentinelTest` (FK-018).

### A.4 Paiement / multi-tender / cash / CB / TR / wallet

- **Composant** : `PaymentComponent.vue` (16+ mutations props — gate `GATE_PAYMENT_PROP_MUTATION` Option A signé Codex, à régulariser H-1).
- **Rôle** : encaisser sur quote sealed, gérer multi-tender (cash + CB + TR), fallback wallet, change rendu.
- **API** : `POST /api/admin/pos/order` (commit + paiement), `POST /api/admin/order/{id}/transaction` (multi-tender), `POST /api/admin/order/{id}/payment-status` (M-06).
- **Events Echo** : `order.paid`, `order.status_changed`.
- **Invariants** : 
  - paiement sur `quote.total_ttc` jamais sur `form.total` (FK-017/032/036, M-05) ;
  - somme tenders == quote ; arrondi monnaie unique (FK-024) ;
  - idempotence `payment_provider_reference` unique (FK-028) ;
  - `cashBack` pas double-déclenché en cas de double cancel (FK-079→OrderStatusNoopSideEffectsSentinelTest).
- **Test reprise** : `PaymentLedgerStateMachineTest`, `PaymentProviderReferenceUniqueTest`, `OrderStatusNoopSideEffectsSentinelTest`, `PaymentMethodRestrictedTest` (Option B M-04B).

### A.5 Park / reprise commande (`pos_parked_orders`)

- **Rôle** : suspendre une commande (interruption), reprendre plus tard sans perte panier.
- **API** : `POST /api/admin/pos/park`, `POST /api/admin/pos/resume/{id}`.
- **Invariants** : park n’émet pas `OrderCreated` (KDS pas alerté) ; expire (FK-088 P2, `ParkedOrderExpiration` test, gate Schema Option A) — **manque** `expires_at` (M-13).
- **Test reprise** : `tests/Feature/Pos/ParkResumeFlowTest.php` (à créer M-21b ou M-13).

### A.6 Cash kiosk collect (POS finalize fiscal Option B)

- **Composant** : modal `PosCollectKioskCash.vue` (à créer dans M-06 sub2).
- **Rôle** : finaliser cash kiosk via POS, déclencher Z fiscal côté POS (gate `GATE_FISCAL_KIOSK_SCOPE_V1` Option B).
- **API** : nouvelle route `POST /api/admin/pos/collect-kiosk-cash/{order}` (M-06 sub2) — **dépréciation** du chemin actuel via `kds-order/change-status` (FK-023 P0, FK-042).
- **Invariants** : encaissement et statut cuisine **découplés** ; release KDS auto si cash collecté.
- **Test reprise** : `PosCollectKioskCashRouteTest`, `PosCashEndpointSentinelTest` (sentinel #14).

### A.7 Multi-écran POS (Caissier #1, #2, lecture cuisine)

- **Rôle** : plusieurs caissiers même branche peuvent ouvrir liste commandes simultanément.
- **API** : `GET /api/admin/order/list` (sans LIKE branch_id, FK-021 — résolu M-09 CLOSED).
- **Invariants** : pas de fuite cross-branch (P0) ; `expected_status` à appliquer aussi côté POS (anti race) si pas seulement KDS.
- **Test reprise** : `OrderListBranchExactnessSentinelTest` (sentinel #7).

### A.8 Refunds / void / partial refund

- **Rôle** : annulation pré-Z (full refund), post-Z (partial refund + écriture ledger), void item.
- **API** : `POST /api/admin/order/{id}/refund`, `POST /api/admin/order/{id}/void`.
- **Invariants** : 
  - refund pré-Z = pas d’impact Z, post-Z = ligne ledger + lien Z d’origine (FK-074 P1, M-04A bloqué Option B) ;
  - HMAC chain inviolée (M-08).
- **Test reprise** : `RefundPreZTest`, `RefundPostZTest`, `VoidPreZTest`, `PartialRefundLedger` (M-04A si Option A — actuellement Option B → portée réduite).

### A.9 Reports / Z fin de service

- **Composant** : `admin-reports.js`, `ZReportComponent.vue`.
- **Rôle** : générer Z, fermer journée, exporter NF525.
- **API** : `POST /api/admin/fiscal/z`, `GET /api/admin/fiscal/z/{id}/export`.
- **Invariants** : signature HMAC chain (FK-010 M-08 CLOSED) ; Z par branche (FK-062) ; archive TTL (FiscalArchiveTtlTest).
- **Test reprise** : `FiscalSealingHmacTest`, `ZAggregationKioskRoutingTest`, `FiscalZBranchExactnessSentinelTest`.

### A.10 Sync temps réel / dégradation gracieuse

- **Composants** : `useRealtime.js`, `private-branch.{id}` listener.
- **Invariants** : reconnect-storm contrôlé (NEW-02 verified 2026-04-23) ; outbox rescue (M-14) si Pusher down ; pas d’intégrité portée par le dedupe per-tab (FK-076 P2).
- **Test reprise** : `realtime-dedupe.spec.js`, `OutboxRescueTest` (M-14).

---

## Catalogue fonctionnel Kiosk

> Réf. : `resources/js/components/frontend/kiosk/*.vue`, `resources/js/store/modules/kioskCart.js`, `resources/js/helpers/kioskOfflineQueue.js`, `app/Http/Controllers/Frontend/OrderController.php`, `app/Services/FrontendOrderService.php`. Gate offline = `GATE_OFFLINE_SCOPE_V1` Option A (read-only menu, paiement désactivé), gate fiscal = Option B (POS finalize), gate Stripe = Option B (inactive prod V1).

### B.1 Splash / pairing / sélection langue / branche

- **Composant** : `KioskSplashComponent.vue`, `KioskBranchPicker.vue`.
- **Rôle** : afficher splash, lancer pairing kiosk machine (Sanctum `kiosk:order` ability), résoudre `KioskMachine.branch_id`.
- **API** : `POST /api/kiosk/auth`, `GET /api/kiosk/branch`.
- **Invariants** : `branch_id` résolu côté serveur (FK-051 P0 résolu kiosk reload menu via `frontend/menu`) ; pas de credentials hardcodés (FK-060 P2 deferred — `KioskProvisioningSecurity`).
- **Test reprise** : `kiosk-menu-source.spec.js`, `KioskProvisioningSecurity` (deferred).

### B.2 Menu / catégories / cartes produits

- **Composant** : `KioskMenuComponent.vue`, `KioskProductCard.vue`.
- **Rôle** : afficher menu SSOT (`frontend/menu`), filtrer par disponibilité branche.
- **API** : `GET /api/frontend/menu`.
- **Invariants** : pas d’endpoint legacy (FK-051) ; pas de logique prix locale (FK-052 P0 — résolu M-11 BLOCKED, à débloquer si M-08 evidence présente) ; locales fr-FR/EUR pas hardcodées (FK-082 P1 LOT-0 traité M-21a CLOSED).
- **Test reprise** : `kiosk-pricing-ssot.spec.js`, `kiosk-format-price-locale.spec.js`.

### B.3 Personnalisation produit (variations, addons, allergens)

- **Composant** : `KioskItemCustomizeComponent.vue`.
- **Rôle** : choisir options, qté, allergens display (LOCK_OrderItem allergens migration).
- **API** : `POST /api/frontend/menu/preview` (à attester) — recalc SSOT.
- **Invariants** : pas de prix calculé front ; preview/checkout parity (FK-055 P0 résolu M-05).
- **Test reprise** : `KioskPromoPreviewCheckoutParitySentinelTest`.

### B.4 Panier / promo / coupon / parity preview/checkout

- **Composant** : `KioskCartComponent.vue`, `kioskCart.js` store.
- **Rôle** : maintenir panier client, appliquer promo, présenter total **backend**.
- **API** : `POST /api/frontend/order/quote` (M-05) ; commit final via `POST /api/frontend/order`.
- **Invariants** : `discount_amount` cohérent preview/final (FK-055) ; quote consumé exactement une fois (M-05 SYMMETRY_NOTE M-10 REWORK résolu).
- **Test reprise** : `kiosk-promo-preview-checkout.spec.js`, `QuoteReplayIdempotencyTest`.

### B.5 Identification / loyalty / upsell

- **Composant** : `KioskLoyaltyComponent.vue`, `KioskUpsellComponent.vue`.
- **Rôle** : scan carte fidélité, ajout points, propositions upsell branch-scope.
- **API** : `POST /api/frontend/loyalty/scan`, `GET /api/frontend/upsell` (FK-054 P1 — branche endpoint pas strict).
- **Invariants** : ability route-level loyalty (FK-065 P1) ; upsell branche stricte (FK-054).
- **Test reprise** : `kiosk-upsell-branch.spec.js`, `KioskLoyaltyScanAbility`.

### B.6 Paiement TPE (CB / TR / cash conditionnel)

- **Composant** : `KioskPaymentComponent.vue` (line 566 `axios.post('frontend/order/${id}/payment-confirm')` retry ×3).
- **Rôle** : invoquer TPE via bridge HW (`_invokeTpe` L473-501), confirmer côté backend.
- **API** : `POST /api/frontend/order/{id}/payment-confirm` (sub-mission M-06 sub1 : ability check, kioskMachine resolver, branch_id == machine.branch_id, payment_method match) ; `OrderController.php:77-151`.
- **Invariants P0** :
  - `kiosk:order` ability obligatoire (FK-029) ;
  - `KioskMachine::branch_id` = `order.branch_id` (FK-029 cross-branch) ;
  - `order.payment_method` revérifié vs request (FK-073) ;
  - cash kiosk **n’est pas marqué payé immédiat** (FK-058 P1) — politique selon Option B fiscal ;
  - CB/TR offline refusés serveur 422 (FK-030/044 P0) — gate offline Option A.
- **Test reprise** : `PaymentConfirmAbilitySentinelTest`, `PaymentConfirmCrossBranchSentinelTest`, `PaymentConfirmCashOrderSentinelTest`, `PaymentConfirmConcurrencySentinelTest`, `KioskCbTrOfflineRefusedSentinelTest`.

### B.7 Mode offline / queue locale

- **Composant** : `kioskOfflineQueue.js` (génération id `offline_${ts}_uuid` L135, L330).
- **Rôle** : enregistrer commande offline (panier seulement — pas de paiement, gate Option A), reprise au reconnect.
- **API** : aucun endpoint offline pour paiement (gate A) ; reprise via `POST /api/frontend/order` au reconnect.
- **Invariants** : préfixe `offline_` strict (FK-053 P0) ; total fallback ≠ source d’autorité (FK-052) ; `String(orderId).startsWith('offline_')` côté `KioskPaymentComponent.vue:292`.
- **Test reprise** : `KioskOfflineIdPrefixSentinelTest`, `kioskOffline*.spec.js`.

### B.8 File d’attente / waiting screen

- **Composant** : `KioskWaitingComponent.vue` (status enum L155-159 ; mais L392 `POST .../change-status { status: 16 }` **littéral** — FK-031 P0 → M-11 résoudra via enum).
- **Rôle** : afficher statut, permettre annulation (PENDING→CANCELED), polling guards offline.
- **API** : `POST /api/frontend/order/{id}/change-status`.
- **Invariants** : enum jamais littéral (FK-031 — `OrderStatusEnumKioskHardcodeSentinelTest`) ; polling sécurisé offline.
- **Test reprise** : `OrderStatusEnumKioskHardcodeSentinelTest` (M-02 sentinel #), `kiosk-waiting-cancel.spec.js`.

### B.9 Receipt / fiscal kiosk routing (Option B = POS finalize)

- **Composant** : `KioskReceiptComponent.vue` (non-fiscal sur kiosk V1, gate `GATE_W9_AUDIT_KIOSK_RECEIPT_NON_FISCAL`).
- **Rôle** : afficher / imprimer reçu non-fiscal ; le Z reste côté POS (Option B).
- **API** : `POST /api/frontend/order/{id}/receipt` (best-effort, pas de garantie fiscale).
- **Invariants** : pas de signature fiscale émise par kiosk (FK-062 P0 résolu M-08) ; archive non-fiscale.
- **Test reprise** : `ZAggregationKioskRoutingTest`, `KioskCashPaymentPolicy` (FK-058).

### B.10 Admin PIN kiosk / reset / settings

- **Composant** : `KioskAdminComponent.vue`.
- **Rôle** : accès admin local pour reset, calibration.
- **Invariants P1** : aucun fallback PIN `1234` en prod (FK-063 P1 — `KioskAdminPinFallback`).
- **Test reprise** : `KioskAdminPinFallback`.

### B.11 Analytics offline / sendBeacon

- **Composant** : `kioskAnalytics.js`.
- **Rôle** : queue offline analytics, sendBeacon best-effort.
- **Invariants** : pas de PII ; pas d’intégrité métier portée par analytics (FK-056 P1).
- **Test reprise** : `kiosk-analytics-transport.spec.js`.

---

## Scénarios d’abus & tests de résistance

> Pour chaque ligne : `scénario | surface | vecteur | atténuation backend attendue | test à automatiser`. Tout abus doit être rouge en sentinel **avant** la mission qui le clôt et vert **après**. Les FK existants sont cités, ne pas dupliquer la matrice.

### POS — abuse catalogue (≥ 18 scénarios)


| #     | Scénario                            | Surface                                                                                     | Vecteur                                                      | Atténuation backend                                                  | Test cible                                                     | Réf. FK / Mission                           |
| ----- | ----------------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------ | -------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------------------- |
| P-A1  | Forge subtotal POST POS             | `/api/admin/pos/order`                                                                      | client envoie `subtotal=1`, backend utilise pour gate remise | recalc Pricing SSOT, ignore `subtotal` client, decisions sur backend | `PosSubtotalForgerySentinelTest`                               | FK-018, M-06                                |
| P-A2  | Replay `payment-confirm`            | `/api/frontend/order/{id}/payment-confirm` (POS path?), `/api/admin/order/{id}/transaction` | rejouer même `transaction_id`                                | unicité `payment_provider_reference`                                 | `PaymentProviderReferenceUniqueTest`                           | FK-028, M-04B/A                             |
| P-A3  | Cross-branch order list LIKE        | `/api/admin/order/list?branch_id=1`                                                         | branch_id LIKE matche `10/100`                               | strict `=`                                                           | `OrderListBranchExactnessSentinelTest`                         | FK-008/021/033, M-09 CLOSED → re-attest C-1 |
| P-A4  | branch_id=0 staff non-admin         | `/api/admin/order/list`, KDS, OSS                                                           | usurpation rôle global                                       | policy `OssAdminBranchPolicy`                                        | `OssAdminBranchPolicySentinelTest`                             | FK-033/040, M-09                            |
| P-A5  | Discount > permission               | `/api/admin/pos/order`                                                                      | header role spoof / payload                                  | re-check permission sur subtotal backend                             | `PosDiscountForgeryTest`                                       | FK-018, M-06 sub5                           |
| P-A6  | Double cancel → double cashBack     | `/api/admin/order/{id}/change-status`                                                       | enchaîne cancel cancel                                       | guard idempotent (status target == current → noop)                   | `OrderStatusNoopSideEffectsSentinelTest`                       | sentinel #5 M-02                            |
| P-A7  | Race cleanup vs payment-confirm     | Job vs HTTP                                                                                 | cleanup REJECTED puis confirm tardif                         | 422 + audit `payment_late_after_cleanup` + flag réconciliation TPE   | `CleanupVsConfirmRaceSentinelTest`                             | FK-029, M-06 sub3                           |
| P-A8  | Quote tamper (modif amount)         | `/api/admin/pos/order`                                                                      | client envoie quote signée modifiée                          | rejet 401 (HMAC), TTL expiré → 401                                   | `QuoteTamperTest`, `QuoteExpirationTest`                       | FK-015, M-05                                |
| P-A9  | Quote replay autre commande         | idem                                                                                        | quote consumée 2×                                            | idempotency consume → même réponse                                   | `QuoteReplayIdempotencyTest`                                   | FK-015, M-05                                |
| P-A10 | Multi-tender somme ≠ quote          | `/api/admin/order/{id}/transaction`                                                         | tenders mal renseignés                                       | refus 422 si abs(sum - quote.total) > 0                              | `MultiTenderSumEqualsQuoteTest`                                | FK-074, M-04                                |
| P-A11 | Park bypass (pas d’expiration)      | `pos_parked_orders`                                                                         | park infini → fuite état                                     | `expires_at` + Job de purge                                          | `ParkedOrderExpiration`                                        | FK-088, M-13                                |
| P-A12 | Refund post-Z sans lien Z d’origine | `/api/admin/order/{id}/refund`                                                              | refund orphelin                                              | obligation `parent_z_id`                                             | `RefundPostZTest`                                              | M-08                                        |
| P-A13 | Void post-Z                         | idem                                                                                        | void post-fiscalisation                                      | refus ou ligne corrective signée                                     | `VoidPostZTest`                                                | M-08                                        |
| P-A14 | Stripe cents conversion (si actif)  | TVA décimale → erreur cents                                                                 | round half-even                                              | `StripeCentsConversionTest`                                          | FK-027, M-17 (Option B → guard inactive)                       |                                             |
| P-A15 | Web public payment ouverture        | `/payment/{order}/pay`                                                                      | route exposée + raw id                                       | guard 403 + feature_flag off                                         | `WebPaymentDisabledTest`                                       | FK-026, M-17                                |
| P-A16 | OrderStatus littéral dans diff JS   | bundle public                                                                               | numérique `16` non enum                                      | lint + bundle scan                                                   | `OrderStatusEnumKioskHardcodeLintTest`, `BundleScanLegacyTest` | FK-031, M-11/M-12                           |
| P-A17 | Dispatch event in-tx                | code path `OrderService::changeStatus` (L1496-1540)                                         | event avant commit                                           | `DispatchableAfterCommit` enforced                                   | `AfterCommitDispatchTest`                                      | FK-070, M-14                                |
| P-A18 | Wallet credit double-débit          | callback concurrent                                                                         | 2 callbacks même cb                                          | lock + idempotence callback                                          | `CreditWalletIdempotency`                                      | FK-034, M-04A blocked → garde sentinel      |
| P-A19 | Token kiosk volé exploite POS       | abuse cross-surface                                                                         | kiosk token sur route POS                                    | abilities + middleware route POS check `permission=pos`              | `PosRouteAbilityTest`                                          | (à créer)                                   |
| P-A20 | Queue number collision              | concurrent inserts                                                                          | fallback microtime collision                                 | unicité (branch_id, day, queue_number) DB                            | `QueueNumberUniquenessSentinelTest`                            | FK-020, M-13                                |


### Kiosk — abuse catalogue (≥ 16 scénarios)


| #     | Scénario                             | Surface                                    | Vecteur                                 | Atténuation backend                                           | Test cible                                      | Réf. FK / Mission      |
| ----- | ------------------------------------ | ------------------------------------------ | --------------------------------------- | ------------------------------------------------------------- | ----------------------------------------------- | ---------------------- |
| K-A1  | `payment-confirm` sans ability       | `/api/frontend/order/{id}/payment-confirm` | user Sanctum non-kiosk                  | check `kiosk:order` Sanctum ability                           | `PaymentConfirmAbilitySentinelTest`             | sentinel #1, M-06 sub1 |
| K-A2  | Cross-branch payment-confirm         | idem                                       | machine A confirme order branche B      | resolver `KioskMachine.branch_id == order.branch_id`          | `PaymentConfirmCrossBranchSentinelTest`         | sentinel #2, M-06      |
| K-A3  | Confirm cash order sur path TPE      | idem                                       | order `payment_method=cash`             | refus 422 — methods doivent matcher                           | `PaymentConfirmCashOrderSentinelTest`           | sentinel #3, M-06      |
| K-A4  | Concurrency 2 confirms simultanés    | idem                                       | replay parallèle                        | un seul `OrderStatusChanged` (transaction lock + idempotence) | `PaymentConfirmConcurrencySentinelTest`         | sentinel #4            |
| K-A5  | Offline ID forgé sans préfixe        | helpers/kioskOfflineQueue.js               | id sans `offline_`                      | refus côté commit                                             | `KioskOfflineIdPrefixSentinelTest`              | FK-053, M-11           |
| K-A6  | CB/TR offline accepté                | UI + API                                   | bouton actif offline + commit acceptait | UI grisé + 422 serveur                                        | `KioskCbTrOfflineRefusedSentinelTest`           | FK-030/044, M-11       |
| K-A7  | Total local diverge backend          | UI + commit                                | client envoie total                     | quote SSOT, ignore total client                               | `kiosk-pricing-ssot.spec.js`, `QuoteTamperTest` | FK-052, M-05/M-11      |
| K-A8  | Promo preview ≠ checkout             | UI                                         | promo affiché ≠ appliqué                | parity test serveur                                           | `KioskPromoPreviewCheckoutParitySentinelTest`   | FK-055, M-05/M-11      |
| K-A9  | Status 16 littéral changement statut | `change-status` body                       | numérique au lieu de enum               | enum strict + lint JS                                         | `OrderStatusEnumKioskHardcodeSentinelTest`      | FK-031, M-11           |
| K-A10 | PIN admin fallback `1234`            | admin kiosk                                | PIN par défaut prod                     | refus + obligation rotation                                   | `KioskAdminPinFallback`                         | FK-063, M-11           |
| K-A11 | Cash auto-PAID                       | commit kiosk cash                          | `payment_status=PAID` immédiat          | option B → POS finalize, statut `pending_pos_collect`         | `KioskCashPaymentPolicy`                        | FK-058, M-11/M-08      |
| K-A12 | Loyalty scan sans ability            | `/api/frontend/loyalty/scan`               | route appelée hors kiosk                | middleware ability route-level                                | `KioskLoyaltyScanAbility`                       | FK-065, M-11           |
| K-A13 | Upsell endpoint cross-branch         | `/api/frontend/upsell`                     | branch query forgée                     | branch resolver kiosk                                         | `kiosk-upsell-branch.spec.js`                   | FK-054, M-11           |
| K-A14 | Provisioning credentials expose      | `/api/kiosk/provision`                     | leak token                              | ability scoped + rotation                                     | `KioskProvisioningSecurity`                     | FK-060, M-11 deferred  |
| K-A15 | Reconnect storm                      | WS Pusher                                  | flap réseau spam reconnect              | backoff jitter NEW-02                                         | `kiosk-reconnect-backoff.spec.js`               | NEW-02 (re-spot M-22)  |
| K-A16 | KioskMachine collision               | DB                                         | 2 kiosks même `machine_uid`             | unicité DB                                                    | `KioskMachineUniqueness`                        | FK-064, M-13 deferred  |


**Régle d’armement** : un abus dont le sentinel est rouge sans mission qui le clôt → `BLOCKED` jusqu’à création du brief mission ou décision de scope V2.

---

## Lien avec Wave A (terminée) & Wave B (masterplay)

> Source : `plans/masterplay/MASTERPLAY_QUEUE.md` (ligne par ligne) + `reports/audit/CLAUDE_ULTRA_ORCHESTRATION_REVIEW_2026-04-25.md` §C/D. Codex **ne refera pas** ce qui est CLOSED ; il **ré-attestera** via C-1 et **fermera** ce qui reste.

### Wave A — CLOSED (ne pas refaire, citer FK)


| Mission                | TASK_ID                     | Couvre FK                              | Statut | Garde-fou imposé à Codex                                                                                             |
| ---------------------- | --------------------------- | -------------------------------------- | ------ | -------------------------------------------------------------------------------------------------------------------- |
| M-19 mémoire           | CV1-M19-MEMORY-DISCIPLINE   | FK-012                                 | CLOSED | `python3 memory/verify.py` ≥175 avant tout commit ; ne pas réécrire JSONL                                            |
| M-01 traçabilité       | CV1-M01-TRACEABILITY-MATRIX | FK-001..003, FK-013, FK-101            | CLOSED | toute nouvelle finding = nouvelle ligne FK-### avec PLAN-ID + test ; jamais réécrire l’existant                      |
| M-02 sentinels         | CV1-M02-SENTINEL-BASELINE   | FK-009 + 18 sentinels                  | CLOSED | sentinels rouges => rester rouges tant que la mission cible ouverte ; pas de skip                                    |
| M-12 legacy guards CI  | CV1-M12-LEGACY-GUARDS-CI    | FK-011, FK-067, FK-072, FK-077         | CLOSED | `npm run lint:fk-legacy` + `npm run scan:bundle:legacy` doivent rester verts                                         |
| M-16 hardware lab      | CV1-M16-HARDWARE-LAB        | FK-007, FK-025, FK-078                 | CLOSED | checklist signable, n’implique aucun code prod                                                                       |
| M-18 test architecture | CV1-M18-TEST-ARCHITECTURE   | FK-047, FK-049, FK-061, FK-085, FK-090 | CLOSED | grille couverture POS≥80% / KDS≥80% / Kiosk≥70% à respecter dans nouvelles missions                                  |
| M-20 runbooks          | CV1-M20-RUNBOOKS-SKELETON   | FK-059                                 | CLOSED | runbook update obligatoire à chaque rollout/canary                                                                   |
| M-21a quickwins LOT-0  | CV1-M21A-QUICKWINS-LOT0     | FK-079, FK-082, FK-083, FK-084, FK-086 | CLOSED | sentinels passent vert ; pas de régression UX                                                                        |
| M-03 gates draft       | CV1-M03-GATES-DRAFT         | FK-004, FK-080, FK-093..099            | CLOSED | **ATTENTION** : signatures gates Wave B = `Codex (instruction humaine explicite)` → R-01 P0 → exiger H-1 humain réel |


### Wave B — état réel


| Mission                   | TASK_ID                           | Statut queue                     | FK fermés                                                                          | Action Codex                                                                |
| ------------------------- | --------------------------------- | -------------------------------- | ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| M-09 branch isolation     | CV1-M09-BRANCH-ISOLATION          | CLOSED                           | FK-008, FK-021, FK-033, FK-040                                                     | C-1 audit Claude rétrospectif obligatoire avant M-13                        |
| M-06 POS revenue guards   | CV1-M06-POS-REVENUE-GUARDS        | CLOSED                           | FK-018, FK-023, FK-029, FK-042, FK-073                                             | C-1 + sentinels P-A1..A7 verts                                              |
| M-05 OrderQuote           | CV1-M05-ORDER-QUOTE               | CLOSED                           | FK-015, FK-017, FK-032, FK-036, FK-055                                             | C-1 ; vérif consume kiosk+POS                                               |
| M-04A payment ledger full | CV1-M04A-PAYMENT-LEDGER-FULL      | BLOCKED (Option B retenue)       | (FK-019/024/028/034/074 → en pilote)                                               | ne pas exécuter ; garder sentinels rouges                                   |
| M-04B payment pilot       | CV1-M04B-PAYMENT-PILOT-RESTRICT   | CLOSED                           | scope restreint pilote                                                             | C-1 + `PaymentMethodRestrictedTest`                                         |
| M-08 fiscal Z NF525       | CV1-M08-FISCAL-Z-NF525            | CLOSED                           | FK-010, FK-062                                                                     | C-1 ; vérif `FiscalSealingHmacTest`                                         |
| M-07 KDS release          | CV1-M07-KDS-RELEASE               | CLOSED                           | FK-037..043, FK-068, FK-069, FK-098                                                | C-1 ; sentinel `KdsExpectedStatusConflict`                                  |
| M-10 OS/FOS symmetry      | CV1-M10-OS-FOS-SYMMETRY           | CLOSED                           | FK-016, FK-022, FK-071, FK-102                                                     | C-1 ; `changePaymentStatus` divergence à attester                           |
| M-17 web/Stripe scope     | CV1-M17-WEB-STRIPE-SCOPE          | CLOSED                           | FK-026, FK-027, FK-097                                                             | déjà CLOSED (info handoff) — re-attester si statut différent                |
| M-11 kiosk runtime        | CV1-M11-KIOSK-RUNTIME             | BLOCKED (preuves M-08 attendues) | (FK-030..031, FK-044, FK-051..054, FK-058, FK-060, FK-063, FK-065, FK-095, FK-099) | déblocage conditionné à evidence M-08 + gate offline humain réel            |
| M-13 migrations safety    | CV1-M13-MIGRATIONS-SAFETY         | PENDING                          | FK-020, FK-064, FK-088                                                             | prochaine cible Codex après H-1+C-1                                         |
| M-14 ops preflight        | CV1-M14-OPS-PREFLIGHT             | BLOCKED (M-13)                   | FK-006, FK-070, FK-075, FK-076, FK-087, FK-100                                     | post-M-13                                                                   |
| M-15 rollout canary       | CV1-M15-ROLLOUT-CANARY            | BLOCKED                          | FK-005                                                                             | post-M-04*+M-08+M-14, exige campagne E2E vert                               |
| M-21b payment refactor    | CV1-M21B-PAYMENT-REFACTOR         | BLOCKED                          | FK-081, FK-089                                                                     | gate prop_mutation à régulariser H-1 ; débloquer après M-06/M-10 stabilisés |
| M-22 post-launch obs.     | CV1-M22-POST-LAUNCH-OBSERVABILITY | BLOCKED                          | FK-014, FK-048, FK-050, FK-056                                                     | post-M-14/M-15                                                              |


**Règle anti-doublon** : toute mission Codex ouverte ci-dessous **doit citer le ou les FK qu’elle ferme** dans son `input.json.context.fk_ids[]`. Pas de FK sans test ; pas de test sans FK.

---

## Roadmap d’itérations Codex

> Une **itération Codex = un lot ≤4h elapsed**, qui doit finir par : (a) **diff dans allowlist** (pas un fichier hors liste), (b) **mandatory_tests verts** (PHPUnit / Vitest / Playwright cités dans le brief), (c) **mini-rapport** structuré (technique / logique métier / sécurité / blast radius), (d) `GPT_FINAL_AUDIT_<TASK_ID>.md` PASS, (e) `AUDIT_VERDICT: PASS` Claude (terminal `foodking-claude-orchestrate.sh` ou fallback Cursor sub-agent + `AUDIT_FALLBACK_REASON`). **Aucune itération ne signe un gate**. Si au-delà de 5 cycles REWORK : escalade humaine dure.

### LOT 0 — Régularisation chaîne d’audit (humain + Claude)

- **Action H-1** (humain) : éditer `docs/gates/GATE_LOG.md` L39-47 → champ Approver = nom humain réel + commit SHA. Sinon CLOSED Wave B contestables.
- **Action C-1** (Claude terminal) : pour chaque mission, `bash scripts/foodking-claude-orchestrate.sh audit "…périmètre CV1-M0X + rapports GPT + diff allowlist…"` (pas de `TASK_ID` sur `audit-brief` seul) → 7 fichiers `reports/audit/AUDIT_REVERIFY_<TASK_ID>_2026-04-26.md`.
- **Sortie** : 8 lignes GATE_LOG corrigées + 7 audits rétro.
- **Bloque** : LOT 1+. Pas de `PENDING → RUNNING` sur frozen avant LOT 0.

### LOT 1 — M-17 final close

- **Périmètre** : `routes/web.php` (route paiement publique → 410/403 ou supprimée), `config/payment.php` flag `web_payment_enabled=false`, `config/services.php` Stripe inactive guard, tests Feature.
- **Allowlist** : `routes/web.php`, `config/payment.php`, `config/services.php`, `tests/Feature/Payment/WebPaymentDisabledTest.php`, `tests/Feature/Payment/StripeInactiveGuardTest.php`.
- **Off-limits** : `OrderService.php`, `FrontendOrderService.php`, `PaymentService.php`, migrations.
- **Tests obligatoires** : `WebPaymentDisabledTest`, `StripeInactiveGuardTest`, `php artisan route:list | grep payment` (capture).
- **Mini-rapport** : technique (route disabled), logique (V1 sans web payment), sécurité (404/403 garanti), blast radius (web public seul).
- **Rollback** : flag `web_payment_enabled=true` réversible via env (humain).

### LOT 2 — M-13 migrations safety (Schema gate Option A)

- **Périmètre** : `database/migrations/2026_`* Caisse V1, `app/Console/Commands/AppPreflightProductionCommand.php`, `docs/operations/MIGRATIONS_SAFETY.md`, scripts `scripts/db/{dry-run,rehearsal,backup}.sh`, `tests/Feature/Migrations/MigrationsSafetyTest.php`.
- **Allowlist** : ci-dessus uniquement.
- **Off-limits** : services métier, contrôleurs, vues.
- **Tests obligatoires** : `php artisan migrate --pretend`, `migrate:rollback --step=N` puis `migrate` ré-applique sans erreur, `MigrationsSafetyTest`, simulation insertion concurrente.
- **Mini-rapport** : pour chaque migration, downtime estimé + rollback `down()` exécutable + `parked_orders.expires_at` (FK-088), `kiosk_machines` unicité (FK-064), `queue_number` unicité (FK-020).
- **Critère GOLDEN** : `reports/migrations/REHEARSAL_<DATE>.md` + Schema gate humain réel.

### LOT 3 — M-21b PaymentComponent refactor (gate prop_mutation Option A signé H-1)

- **Périmètre** : `resources/js/components/admin/pos/PaymentComponent.vue`, `resources/js/components/admin/pos/PosComponent.vue`, `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`, tests Vitest associés.
- **Allowlist** : 3 composants Vue + tests.
- **Off-limits** : `OrderService.php`, `FrontendOrderService.php`, `PaymentService.php`, migrations, routes.
- **Tests obligatoires** : `PaymentComponentPropMutationVitestSentinel` (compteur ≥16 → 0), lint Vue `vue/no-mutating-props`, snapshot payload backend identique octet pour octet.
- **Mini-rapport** : 16 sites mutations → `emit('update:form')` + parent state OU copie locale (Option A précisée).
- **Rollback** : git revert simple ; flag UI `payment_component_v2`.

### LOT 4 — M-11 kiosk runtime (gate offline Option A signé H-1)

- **Pré-requis** : LOT 0+1+2 + evidence M-08 fiscal kiosk routing.
- **Périmètre** : `resources/js/components/frontend/kiosk/*.vue` (sauf legacy archivés), `resources/js/store/modules/kioskCart.js`, `resources/js/helpers/kioskOfflineQueue.js`, `app/Http/Controllers/Frontend/OrderController.php` (refus offline CB), tests Vitest + Playwright.
- **Allowlist** : ci-dessus.
- **Off-limits** : autres backend, schéma DB.
- **Tests obligatoires** : `KioskCbTrOfflineRefusedSentinelTest`, `KioskOfflineIdPrefixSentinelTest`, `OrderStatusEnumKioskHardcodeSentinelTest` vert (status 16 enum), `KioskAdminPinFallback`, `KioskCashPaymentPolicy`.
- **Mini-rapport** : enum jamais littéral, ID préfixé strict, paiement offline interdit.
- **Rollback** : flag `kiosk_offline_strict=on`.

### LOT 5 — M-14 ops preflight (post-M-13)

- **Périmètre** : `app/Console/Commands/AppPreflightProductionCommand.php` (extensions), `config/horizon.php`, dashboards, `tests/Feature/Preflight/*.php`, `scripts/ops-preflight-caisse-v1.sh`.
- **Tests obligatoires** : `OpsPreflightCaisseV1Test`, `AfterCommitDispatchTest` (FK-070), `OutboxRescueTest`, `ReceiptAuditFailureAlert` (FK-075), `SyncMetricsPurgeJob` (FK-087), checks queue/scheduler/workers/broadcast/cache/outbox/fiscal archive.
- **Mini-rapport** : 12+ checks couverts + injection d’erreur → exit ≠0.

### LOT 6 — Campagne Playwright E2E Caisse V1

- **Périmètre** : `tests/Playwright/sentinels/`* + scenarios bout en bout : (1) POS dine-in commande payée → KDS bump → fiscal Z ; (2) Kiosk borne → POS cash collect → KDS release → DELIVERED ; (3) Kiosk CB happy path → KDS → OSS waiting → DELIVERED ; (4) abandon kiosk timeout → cleanup ; (5) multi-écran KDS bump simultané (`expected_status` conflict).
- **Sortie** : `reports/antigravity/E2E_CAISSE_V1_<DATE>.md` avec runs vidéo + screenshots + console clean (zero erreur).
- **Bloque** : LOT 7 (canary) sans E2E vert.

### LOT 7 — M-15 rollout canary (post-M-04* + M-08 + M-14)

- **Périmètre** : `docs/operations/CANARY_PLAN_CAISSE_V1.md`, `scripts/canary/`*, `config/feature.php` feature flags par surface (`payment_ledger_v1`, `pos_revenue_guards`, `kds_strict_release`, `quote_v1`, `fiscal_z_v1`, `kiosk_offline_strict`).
- **Tests obligatoires** : drill rollback < 5 min ; predicate testés (`payment_success_rate < 95% / 5min` ; `fiscal_anomaly > 0` ; `kds_error_rate > 5%`).
- **Critère GOLDEN** : double PASS + E2E vert (LOT 6) + sign-off humain `GATE_GO_NO_GO_CAISSE_V1` (Codex ne signe **jamais**).

### LOT 8 — M-22 post-launch observability (post-M-14/M-15)

- **Périmètre** : `app/Services/Observability/`*, dashboards `sync-overview` filtrés branche (rappel R-15 / NEW-04 G2), Sentry alerting on-call, runbook on-call.
- **Tests obligatoires** : `Observability` suite ; injection synthétique → page on-call ≤1 min ; permissions Spatie sur dashboards.
- **Sortie** : 1 incident-drill documenté `reports/incident-drills/`.

### LOT 9 — Audit transversal final Claude (avant `GO_NO_GO`)

- Revue chaîne sync OrderIntent → OrderQuote → PaymentProof → KitchenRelease → KDS → Fiscal Z → OSS (file:line + tests verts cités).
- Revue invariants 6 (§0.3 PLAN_GPT) — preuve par sentinel + lint statique.
- Revue 9 gates signés humain réel (correction H-1 vérifiée).
- Revue rollback (drills LOT 7 effectués).
- Revue mémoire Graphiti facts ingestés (≥200 cible).
- Verdict `CAISSE_V1_GO_LIVE_VERDICT: GO|HOLD` + raison dans `reports/audit/CLAUDE_TRANSVERSAL_VERDICT_CAISSE_V1_<DATE>.md`.

### LOT 10 (réserve) — Backlog discoveries / régressions tardives

- Si une finding non couverte apparaît, créer `FK-###` matrice → `M-XX` mission, ne pas patcher dans une mission existante (anti-drift).
- Cas attendus : audit endpoints ajoutés Wave B (R-09 ULTRA), sentinel `branch_id` final post-NEW-04 G2 dans M-14.

> **Discipline temporelle** : sur 10 jours pleins, attendre 6-8 lots fermés (LOT 0+1+2+3 ou 4 sur les 5 premiers jours, LOT 5/6/7 J6-J9, LOT 8 J10). Si un lot dépasse 4h sans test vert : `STOP_ON_REWORK=1` déclenche escalade.

---

## Grille d’audit 360°

> Lignes = interface ou route critique ; colonnes = **fonctionnel · logique métier · sécurité · perf · observabilité · tests**. Cellule vide ⇒ hypothèse à confirmer (mention `?`). Réf. file:line ancrées.


| Interface / Route                                                                       | Fonctionnel                           | Logique métier                                                                      | Sécurité                                                            | Perf                                                  | Observabilité                                               | Tests                                                                                                               |
| --------------------------------------------------------------------------------------- | ------------------------------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------- | ----------------------------------------------------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `POST /api/admin/pos/order` (`PosController::store`)                                    | commit POS quote consumée             | recalc Pricing SSOT, gate remise sur subtotal backend (M-06 sub5)                   | branch_id strict, role `pos`, idempotency_key                       | p95 < 500ms ; lock orders insert                      | `pos.commit_p99`, `pos.failure_rate`                        | `PosTotalServerAuthoritativeSentinelTest`, `PosSubtotalForgerySentinelTest`, `OrderListBranchExactnessSentinelTest` |
| `POST /api/admin/pos/quote` (M-05 NEW)                                                  | quote signée HMAC TTL 60s             | empreinte canonique, idempotency consume                                            | secret par device, replay = même réponse                            | p95 < 200ms                                           | `quote.issue_count`, `quote.tamper_count`                   | `QuoteTamperTest`, `QuoteExpirationTest`, `QuoteReplayIdempotencyTest`, `QuoteCurrencyOriginTest`                   |
| `POST /api/admin/pos/collect-kiosk-cash/{order}` (M-06 sub2 NEW)                        | finalise cash kiosk via POS           | release KDS auto, fiscal Z routé POS (Option B)                                     | role `pos` strict, branch match order ↔ user                        | p95 < 300ms                                           | `cash.kiosk_volume`                                         | `PosCollectKioskCashRouteTest`, `PosCashEndpointSentinelTest`                                                       |
| `POST /api/admin/order/{id}/transaction`                                                | multi-tender                          | somme tenders == quote.total ; arrondi unique                                       | branch_id strict, idempotence `payment_provider_reference`          | ?                                                     | `tender.mix`, `payment.failure_rate`                        | `MultiTenderSumEqualsQuoteTest`, `PaymentProviderReferenceUniqueTest`                                               |
| `POST /api/admin/order/{id}/change-status` (`OrderStatusRequest`)                       | transition orderstatus                | `OrderStateMachine::allows`, raison requise sur CANCELED/REJECTED/RETURNED          | role check + abilities + status whitelist                           | lock SELECT ... FOR UPDATE                            | `status_change_rate`, `status.illegal_attempts`             | `KdsTransitionWhitelistSentinelTest`, `KdsExpectedStatusConflictSentinelTest`, audit row `order_status_transitions` |
| `POST /api/admin/order/{id}/payment-status` (`OrderService::changePaymentStatus`) L1661 | mise à jour paiement                  | côté FOS absent → divergence M-10                                                   | branch_id, role                                                     | ?                                                     | `payment.status_change`                                     | `OrderServicesContractTest` (M-10), C-1 audit retro                                                                 |
| `POST /api/frontend/order` (kiosk commit)                                               | commit kiosk quote consumée           | pricing SSOT, parity preview/checkout                                               | Sanctum ability `kiosk:order`, KioskMachine resolver, branch match  | p95 < 500ms                                           | `kiosk.commit_p99`                                          | `kiosk-pricing-ssot.spec.js`, `kiosk-promo-preview-checkout.spec.js`, `KioskOfflineIdPrefixSentinelTest`            |
| `POST /api/frontend/order/{id}/payment-confirm` (`OrderController.php:77-151`)          | finalisation TPE kiosk                | check method match, branch_id == machine.branch_id, refus cash, refus offline CB    | ability `kiosk:order`, transactions DB locked, idempotence callback | retry ×3 client (KioskPaymentComponent.vue:566)       | `payment.confirm_p99`, `payment.confirm_late_after_cleanup` | sentinels #1..6 (M-02), `CleanupVsConfirmRaceSentinelTest`                                                          |
| `POST /api/frontend/order/{id}/change-status` (kiosk cancel)                            | annulation client                     | enum strict (status `CANCELED`), no littéral 16                                     | branch_id, ability                                                  | ?                                                     | `kiosk.cancel_rate`                                         | `OrderStatusEnumKioskHardcodeSentinelTest`, `kiosk-waiting-cancel.spec.js`                                          |
| `GET /api/admin/order/list` (`OrderService::myOrder`/`orderFilter`) L151,194,230,267    | listing admin                         | filtre `branch_id` `=` strict (M-09), pagination, statut whitelist                  | branch_id strict, ability role                                      | p95 < 300ms ; index `(branch_id, status, created_at)` | `order.list_p99`, `branch_leak_count`                       | `OrderListBranchExactnessSentinelTest`, `OssAdminBranchPolicySentinelTest`                                          |
| `GET /api/admin/kds/orders` (`KitchenDisplaySystemOrderService::list`) L84-90           | listing KDS                           | filter status≥ACCEPT, payment_status=PAID (sauf cash POS), pagination >50 → bandeau | branch_id strict                                                    | p95 < 200ms                                           | `kds.list_p99`, `kds.cap_overflow`                          | `KdsPaginationOverflowTest`, `KdsTransitionWhitelistSentinelTest`                                                   |
| `POST /api/admin/kds/order/{id}/change-status` (avec `expected_status`)                 | bump cuisine multi-écran              | `OrderStateMachine::allows`, `expected_status` du body = current → 409 sinon        | role `kds-bump`, branch                                             | p95 < 200ms ; lock                                    | `kds.bump_p99`, `kds.race_conflict`                         | `KdsExpectedStatusConflictSentinelTest`, `KitchenReleaseRuleTest`, `KdsMultiScreenPlaywrightTest`                   |
| `POST /api/admin/fiscal/z` (`ZReportService`)                                           | clôture journée                       | aggregation par branche, Option B routing kiosk → POS                               | role `fiscal`, HMAC chain, archive TTL                              | < 30s end-to-end pour N=10k                           | `fiscal_anomaly`, `fiscal.Z_chain_break_count`              | `FiscalSealingHmacTest`, `ZAggregationKioskRoutingTest`, `FiscalZBranchExactnessSentinelTest`, `RefundPostZTest`    |
| `Job CleanupStalePendingKioskOrders`                                                    | purge commandes pending kiosk timeout | rejette REJECTED, flag réconciliation TPE si confirm tard                           | branch scope                                                        | scheduler period                                      | `cleanup.timeouts`, `payment_late_after_cleanup`            | `CleanupVsConfirmRaceSentinelTest`                                                                                  |
| `Event OrderCreated` (outbox)                                                           | broadcast post-commit                 | `DispatchableAfterCommit` enforced                                                  | canal `private-branch.{id}` auth                                    | rescue outbox                                         | `dispatch_p99`, `outbox.lag`                                | `AfterCommitDispatchTest`, `OutboxRescueTest`                                                                       |
| `WS canal private-branch.{id}`                                                          | live updates branche                  | event allowlist                                                                     | auth Pusher signed, branch isolation                                | reconnect storm bounded NEW-02                        | `ws.auth_failures`, reconnect rate                          | `kiosk-reconnect-backoff.spec.js`, `branch-channel-leak.spec.js`                                                    |
| `OSS screen` (public)                                                                   | affichage statuts client              | refresh push + polling fallback                                                     | token oss + branch                                                  | latence affichage ≤1s                                 | `oss.update_p99`                                            | `OssAdminBranchPolicySentinelTest`, Playwright OSS                                                                  |
| `/payment/{order}/pay` (web public)                                                     | hors V1 (gate B)                      | route doit retourner 403/410                                                        | guard runtime + flag                                                | n/a                                                   | `web.payment_attempts_blocked`                              | `WebPaymentDisabledTest`, `SignedPaymentIntent` (latent)                                                            |
| `Stripe webhook` (si actif)                                                             | hors V1 (gate B inactive)             | guard inactive prod                                                                 | signature Stripe                                                    | n/a                                                   | `stripe.attempts_blocked`                                   | `StripeInactiveGuardTest`, `StripeCentsConversionTest`                                                              |


---

## Prochaine action immédiate

> Trois actions, dans l’ordre, **chacune actionnable et vérifiable par fichier ou commande**. Aucune ne signe un gate.

1. **[Humain — H-1]** Régulariser `docs/gates/GATE_LOG.md` lignes 39-47 et 47 (PROP_MUTATION) : remplacer Approver `Codex (instruction humaine explicite, 2026-04-25)` par signature humaine nominative + commit SHA. **Sortie attendue** : un commit `chore(gates): human signature regularization` avec `git diff docs/gates/GATE_LOG.md` montrant 9 lignes corrigées. **Bloque** tout LOT 1+ sur frozen.
2. **[Claude terminal — C-1]** Pour chaque mission (M-04B, M-05, M-06, M-07, M-08, M-09, M-10), lancer `bash scripts/foodking-claude-orchestrate.sh audit "…"` avec le périmètre et les chemins d’artefact GPT listés. **Sortie attendue** : 7 rapports `reports/audit/AUDIT_REVERIFY_<TASK_ID>_2026-04-26.md` avec `AUDIT_VERDICT: PASS|REWORK`. Tout `REWORK` génère immédiatement une mission Codex de rectification (LOT 0bis) avant LOT 1.
3. **[Codex — LOT 1]** Vérifier si `missions/CV1-M17-WEB-STRIPE-SCOPE/` existe ; sinon créer `input.json` (allowlist : `routes/web.php`, `config/payment.php`, `config/services.php`, `tests/Feature/Payment/{WebPaymentDisabledTest,StripeInactiveGuardTest}.php`) + `execute_brief.md`, puis `npm run codex:complex -- CV1-M17-WEB-STRIPE-SCOPE` puis `codex:final-audit` puis `bash scripts/foodking-claude-orchestrate.sh audit "…M-17…"`. **Sortie attendue** : `output_codex.json` valid + double PASS + statut queue `EXECUTED → CLOSED`. **Pré-requis** : LOT 0 (#1+#2) terminé.

---

`PLAN_FULLSTACK_VERSION: 1.0` · `READY_FOR_CODEX_LOOP: YES (LOT 0 d'abord)` · `READY_FOR_PRODUCT_CHANGE_ON_FROZEN: NO_UNTIL_HUMAN_REGULARIZATION` · `Total FK couverts par référence : 102/102` · `0 invention de fichier ; 0 gate signé par modèle ; 0 doublon de la matrice FK.`