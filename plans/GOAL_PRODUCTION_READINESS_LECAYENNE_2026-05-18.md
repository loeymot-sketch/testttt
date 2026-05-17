# GOAL — Production Readiness FoodKing V1 Le Cayenne
**Date** : 2026-05-18
**Branche cible** : `v1-0-1-hardening-2026-05-17` (HEAD `1235e3e1a`)
**Méthodologie** : `superpower-gstack` + `ultra-audit-profond` (nouveau skill, voir `~/.claude/skills/ultra-audit-profond/`)
**Orchestration** : `ultra-architect-planify` (nouveau skill, génère ce document)
**Skip clarifying questions** : ✅ owner carte-blanche
**Target wall-clock** : 5-7 jours-agent (waves parallèles)

---

## §0 — Preamble (verrouillage scope & état initial)

### 0.1 Working-tree decision (B5)
Le working tree contient le **POS payment 4-scenarios fix** non-committé du 2026-05-18 (35+ fichiers modifiés : `PosController.php`, `Stripe.php`, `PaymentService.php`, `SplitPaymentService.php`, `mobile/*`, tests sentinels, screenshots).

**Décision** : ✅ **commit comme première sub-task de Wave 1** (cf. §X Wave 1) sous message `feat(pos-payment-v1): 4-scenarios green (cash/card/split-equal/split-mixed) + simulation_hardware bypass + i18n keys`. La mission DÉMARRE par ce commit ; aucun travail GOAL n'est exécuté sur working-tree mixte. L'owner reste libre de pre-inspecter le diff avant de lancer Wave 1.

### 0.2 V1 scope expansion (Livreur)
Le NORTH STAR `PROJECT_BRAIN.md §1` V1 liste 5 surfaces : POS + Kiosk + KDS + OSS + Admin + Sync. Le user ajoute **Livreur (DeliveryBoy)** comme 6e système production-required.

**Découverte d'ancrage** : le système Livreur existe DÉJÀ sous le namespace `DeliveryBoy*` :
- `app/Http/Controllers/Admin/DeliveryBoyController.php` (CRUD admin)
- `app/Http/Controllers/Admin/DeliveryBoyOrderController.php` (assignation)
- `app/Http/Controllers/Admin/DeliveryBoyAddressController.php` (adresses)
- `app/Http/Controllers/Frontend/DeliveryBoyOrderController.php` (interface livreur)
- `app/Services/DeliveryBoyService.php` + `app/Services/Delivery/DeliveryFeeService.php`
- `app/Http/Resources/DeliveryBoyResource.php` + `SimpleDeliveryBoyOrderResource.php` + `DeliveryBoyOrderCountResource.php`
- `app/Events/SendOrderDeliveryBoyPush.php` + Sms + Mail
- `app/Listeners/SendOrderDeliveryBoyPushNotification.php` + Sms + Mail
- `app/Exports/DeliveryBoyExport.php`
- 2 migrations toutes neuves (2026-05-18) : `add_delivery_fee_settings_to_branches`, `add_delivery_minimum_order_to_branches`

**Conclusion** : Livreur est **MATURE** au même titre que POS/Kiosk/KDS/OSS — pas de phase DISCOVERY séparée nécessaire. Audit→heal→production-perfect direct comme les 5 autres. **V1 scope expand officiellement à 6 systèmes.**

### 0.3 Production blockers à clôturer en parallèle du GOAL (owner physique)
- **B1** AWS keys rotation (commit `a4a88df06` exposé) — OWNER seul peut faire
- **B2** LOCK POS-A4 menu addon role mirror — owner countersign requis
- **B3** LOCK POS Wizard XSS escape (`plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`) — owner countersign requis
- **B4** OVH VPS-1 provisioning + Certbot + DR drill — checklist 10 actions owner physique

Ces blockers **n'arrêtent pas le GOAL** (qui ne touche pas l'infra). Mais ils sont prérequis au flip cloud + hardware. À tracker en parallèle.

### 0.4 Pipeline mandaté par tâche (voir `~/.claude/skills/ultra-audit-profond/`)
Chaque tâche du GOAL suit la séquence canonique :
```
1. ANCHOR        → grep file:line réel, refus de fiction (anti-hallucination)
2. ARCHITECT     → subagent read-only "patterns + cohérence + dependency"
3. SECURITY      → subagent read-only "auth/authz/branch isolation/secrets"
4. UX/A11y       → subagent read-only "WCAG 2.1 + ARIA + keyboard"
5. DBA           → subagent read-only "schema + FK + N+1 + BranchScope"
6. SRE / SYNC    → subagent read-only "Outbox + Pusher + polling + queue"
   ──> 5 specialists fire en SEUL MESSAGE (parallèle, ~3 min wall-clock)
7. SYNTHESIZE    → main agent consolide les 5 reports en P0/P1/P2
8. IMPLEMENT     → scope-minimal (≤30 LOC inline OU subagent implementer TDD-first)
9. RED-TEAM      → adversarial dispute, framing hostile, cherche P0s manqués
10. TEST tech    → PHPUnit + Vitest + sentinels (100% PASS exact count)
11. E2E + VISUAL → Playwright headed + screenshot capture + Read + analyse
    LOOP heal max 3 cycles → si > 3 ESCALATE owner
12. ADVERSARIAL VISUAL → 2e agent re-analyse les screenshots (cross-check)
13. COMMIT       → INLINE-EDIT-EXCEPTION format si applicable
14. REFLECT      → BRAIN.md update + Graphiti push si pattern significatif
```

Frozen-zones : `CLAUDE.md §7` strict — touche = STOP, dispatch `/lock-plan` skill.
NF525 invariants : `CLAUDE.md §8` strict — chain HMAC, séquence monotone, snapshot frozen.

### 0.5 Convergence criteria (production-perfect, pas juste GREEN)
Une tâche est **DONE** quand TOUS sont vrais :
- [x] 5 specialists ont audité (P0 list verified)
- [x] RED-team a disputé (no new P0)
- [x] PHPUnit + Vitest sentinels 100% PASS exact count
- [x] E2E Playwright headed PASS
- [x] Screenshots analysés par main + RED (no raw labels, no broken layout, no console error, no a11y critical)
- [x] Frozen-zone diff = zéro lignes
- [x] BRAIN.md updated + commit avec evidence

**Pas de "ça marche presque", pas de "on verra plus tard", pas de bouton manquant.**

---

## §1 — Map des 6 systèmes principaux (V1 production-required)

| # | Système | Surface | Maturity | Anchor primaire | Tests existants |
|---|---|---|---|---|---|
| 1 | **POS Caisse** | staff register | HARDENED (V1.0.1) | `Admin/PosController.php` + `public/js/pos-*.js` | 12+ files `tests/Feature/Pos/` |
| 2 | **Kiosk Borne** | customer SPA | HARDENED (Wave Z) | `Frontend/KioskEventController.php` + `resources/js/components/frontend/kiosk/*.vue` (FROZEN) | `tests/Feature/Kiosk*` |
| 3 | **KDS Cuisine** | kitchen display | HARDENED (Wave Z) | `Admin/KitchenDisplaySystemController.php` + `Admin/KdsSyncController.php` + `public/js/admin-kds.js` | 6 files `tests/Feature/Kds*Test.php` |
| 4 | **OSS Status** | customer wait screen | HARDENED (Wave Z) | `Admin/OrderStatusScreenController.php` + `public/js/admin-oss.js` | (à compléter) |
| 5 | **Stock + Sync** | cross-surface | MATURE | `Models/StockLevel.php` + 10 Persist*ToOutbox listeners + 25 Events | scattered |
| 6 | **Livreur (DeliveryBoy)** | driver app | MATURE | `Admin/DeliveryBoy{,Order,Address}Controller.php` + `Services/Delivery/*` + 2 NEW migrations 2026-05-18 | (à compléter) |

## §2 — Map des 2 systèmes séparés (audit profond, pas reliés à V1 central)

| # | Système | Path | Status | Audit profondeur |
|---|---|---|---|---|
| M | **Mobile App** | `mobile/screens-*.jsx`, `mobile/data/menu.js` | standalone (V1 mobile cycle 2026-05-17 GREEN) | page-par-page, mirror data parity |
| W | **Web Site** | `/Users/1millnonstop/Downloads/web/` (17 files : screens, wizard-v2, funnel, loyalty-v2, account-v2, orders, data/menu.js) | standalone (V1 web cycle 2026-05-17 GREEN) | page-par-page × 4 viewports |

**Règle absolue** : mobile + web **restent standalone**. Aucun wireup API/MCP vers le système central V1 dans ce GOAL. Cf. `feedback_mobile_realignment.md`.

---

## §3 — Système 1 : POS Caisse

### Contract
Caisse staff fast-food : commande items + composition (wizard popup), paiement (cash + card + ticket-restaurant + split), tiroir cash + Z-reports NF525, refunds, parked orders, modifications.

### Frozen zones (strict-no-touch)
- `public/js/pos-wizard.js` (~296 KB Vanilla JS, version S25-SinglePage, non-Mix)
- `public/css/pos-wizard.css`
- `resources/views/admin-pos-v4.blade.php`

### Décomposition en 4 sub-systèmes

#### Sub 1.1 — POS Wizard (commande)
**Anchors** : `public/js/pos-wizard.js` (FROZEN), `public/js/pos-app.js`, `public/js/pos-shell.js`, `resources/views/admin-pos-v4.blade.php` (FROZEN), `Admin/PosController.php`
**Tasks** :
- T-1.1.1 Audit composition flow (item + variation + extras + supplements) — 0 raw label, 0 step missing
- T-1.1.2 Audit profile mirror DB ↔ wizard (cf. fix 2026-05-18 profile 85 Chicken Burger viande+crudite)
- T-1.1.3 Audit add-to-cart edge cases (allergens modal, parked order resume, quote binding)
**Acceptance** : `tests/Feature/Pos/QuoteBindingTest`, `PosOrderRequestNoClientTotalsTest`, `PosMenuRuntimeAccessTest` all PASS + E2E POS wizard 4 templates GREEN.

#### Sub 1.2 — POS Payment (paiement)
**Anchors** : `app/Services/PaymentService.php`, `app/Services/Payments/SplitPaymentService.php`, `app/Http/PaymentGateways/Gateways/Stripe.php`
**Tasks** :
- T-1.2.1 Validate 4 scenarios green (cash overpay / card / split-equal / split-mixed) — déjà fait 2026-05-18, ré-attester
- T-1.2.2 Audit XSS escape POS Wizard (LOCK pending B3) — apply LOCK after owner signs
- T-1.2.3 Audit terminal_id wire-in cross-branch (cf. `TerminalIdWireInTest`)
- T-1.2.4 Audit refund flow + RefundCreated event (cf. Wave 5F heal)
**Acceptance** : `PosSimulationHardware4ScenariosTest` 5/5 + `SplitPaymentEndToEndTest` 6/6 + `SplitPaymentSentinelTest` 3/3 + visual capture POS payment modal no raw label.

#### Sub 1.3 — POS Cash Drawer + Z-Reports (NF525 fiscal)
**Anchors** : `app/Services/Fiscal/FiscalSequenceService.php` (FROZEN), `app/Services/Fiscal/ZReportService.php` (FROZEN), `app/Services/Fiscal/AuditLogService.php` (FROZEN), migrations triggers `audit_logs` + `z_reports` (FROZEN)
**Tasks** :
- T-1.3.1 Attester chain HMAC unchanged (hash `ca4ac1fdc208dae1`, 26 rows)
- T-1.3.2 Audit cash drawer session lifecycle (open/close/reconciliation forensic) — cf. Z10-F-7, F-10/F-11/F-12 backlog
- T-1.3.3 Audit Z-report close daily + retention 6 ans
- T-1.3.4 Validate `POS_SIMULATION_HARDWARE` flip path (true → false ne casse rien sauf drawer requirement)
**Acceptance** : `PosCashTrailTest` 6/6 PASS + `tests/Feature/Sentinels/*` 3/3 + fiscal sentinels all PASS + manual Z close dry-run OK.

#### Sub 1.4 — POS Parked Orders + Modifications + Refunds
**Anchors** : `Admin/PosController.php` (parked actions), `app/Models/PosParkedOrder` (BranchScope), `tests/Feature/Pos/PosPurgeParkedScheduleTest`
**Tasks** :
- T-1.4.1 Audit parked-order lifecycle (park → resume → modify → pay → unpark scheduled purge)
- T-1.4.2 Audit walk-in customer API (cf. `PosWalkInCustomerApiTest`)
- T-1.4.3 Audit dining table release after POS order (cf. `DiningTableReleaseAfterPosOrderTest`)
- T-1.4.4 Audit floorplan controller (cf. `FloorplanControllerTest`) — flag V1 `pos.dine_in_enabled=false` confirmé
**Acceptance** : 4 tests above PASS + E2E parked order flow visual GREEN.

---

## §4 — Système 2 : Kiosk Borne

### Contract
Borne client tactile fast-food : idle screen + langue, wizard composition (items + variations + extras + supplements + upsell), paiement card (NF525 sequence alloc à création order), ticket + retour idle, gestion offline conflict.

### Frozen zones (strict-no-touch)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`

(Mais : **tests automatisés sur ces composants frozen sont AUTORISÉS** cf. `feedback_kiosk_wizard_frozen_tests_allowed.md`.)

### Décomposition en 4 sub-systèmes

#### Sub 2.1 — Kiosk Idle + Auth + Language
**Anchors** : `KioskIdleScreenComponent.vue`, `KioskLoginComponent.vue`, `app/Http/Controllers/Auth/KioskMachineLoginController.php`, `Admin/KioskMachineController.php`, `Admin/KioskSetupController.php`
**Tasks** :
- T-2.1.1 Audit idle screen rotation (promo carousel `KioskPromoCarouselComponent.vue`) + inactivity overlay
- T-2.1.2 Audit machine login (Sanctum `kiosk:order` ability only, TTL 480min, old tokens revoked)
- T-2.1.3 Audit language FR-lock + i18n full resolved (no raw `kiosk.X` labels)
- T-2.1.4 Audit `KioskLoyaltyComponent.vue` opt-in flow
**Acceptance** : E2E kiosk idle→wizard→pay→ticket GREEN + visual no raw label + Sanctum sentinels PASS.

#### Sub 2.2 — Kiosk Wizard (composition)
**Anchors** : `KioskWizardComponent.vue` (FROZEN), `KioskPosWizardComponent.vue`, `KioskCategoriesComponent.vue`, `KioskCartComponent.vue`, `steps/*` dir
**Tasks** :
- T-2.2.1 Audit composition flow (sandwich/taco/burger/assiette/menu_formule) parity avec POS wizard
- T-2.2.2 Audit upsell modal (`KioskUpsellComponent.vue` FROZEN) — pas de changement logique, juste attester
- T-2.2.3 Audit error states (`KioskErrorMenuUnavailableComponent.vue`, `KioskErrorProductRemovedComponent.vue`)
- T-2.2.4 Audit `CatalogChangeToastComponent.vue` real-time sync (cf. §6 Stock+Sync)
**Acceptance** : `tests/Feature/Kiosk*` PASS + Vitest kiosk wizard 100% + Playwright capture 5 templates wizard GREEN visual.

#### Sub 2.3 — Kiosk Payment + NF525
**Anchors** : `KioskPaymentComponent.vue`, `KioskCashInstructionComponent.vue` (cash-rare path), `KioskErrorPaymentRefusedComponent.vue`
**Tasks** :
- T-2.3.1 Audit card payment integration (Stripe/SenangPay parity, idempotency `X-Idempotency-Key`)
- T-2.3.2 Validate `fiscal_sequence_no` allocation à création order (kiosk paid path)
- T-2.3.3 Audit payment refused flow (UX recovery + no order created)
- T-2.3.4 Audit cash instruction screen (legacy path, FR cash-rare)
**Acceptance** : Stripe + SenangPay webhook tests PASS + fiscal seq sentinel + visual payment modal GREEN.

#### Sub 2.4 — Kiosk Confirmation + Ticket + Offline
**Anchors** : `KioskConfirmationComponent.vue`, `KioskOrderSummaryComponent.vue`, `KioskOfflineConflictModalComponent.vue`, `KioskWaitingComponent.vue`, `KioskToastComponent.vue`, `KioskInactivityOverlayComponent.vue`
**Tasks** :
- T-2.4.1 Audit confirmation screen i18n (cf. `kiosk.confirmation.*` migration 2026-05-08)
- T-2.4.2 Audit ticket printing + receipt + QR code
- T-2.4.3 Audit offline conflict modal (network down → recovery)
- T-2.4.4 Audit POS offline full stack (Phase C local Wave 5D-G — déjà green)
**Acceptance** : POS offline tests PASS + visual ticket clean + idle return after 30s inactivity.

---

## §5 — Système 3 : KDS Cuisine

### Contract
Kitchen Display System : ordres entrants real-time, item-level bump/recall, allergen modal, multi-station routing (grill/fryer/drinks), Echo + polling 5s fallback, accordéon par order, banners stack discipline.

### Décomposition en 4 sub-systèmes

#### Sub 3.1 — KDS Orders Board (display)
**Anchors** : `Admin/KitchenDisplaySystemController.php`, `public/js/admin-kds.js`, `Admin/KdsSyncController.php`, route `/api/admin/kds-order/sync`
**Tasks** :
- T-3.1.1 Audit orders board layout (4 cols, cards visible, no stack overflow) — cf. `KdsPaginationOverflowTest`
- T-3.1.2 Audit branch filter exact (cf. `KdsBranchFilterExactTest`) — multi-tenant isolation
- T-3.1.3 Audit transition whitelist (cf. `KdsTransitionWhitelistTest`) — order state machine
- T-3.1.4 Audit change status concurrency (cf. `KdsChangeStatusConcurrencyTest`) — Cache::lock
**Acceptance** : 6 KDS tests PASS + visual board 100% items visible per card (no truncation) + console clean.

#### Sub 3.2 — KDS Item-Level Workflow (bump/recall/allergen)
**Anchors** : `public/js/admin-kds.js`, `Admin/KitchenDisplaySystemController.php` actions
**Tasks** :
- T-3.2.1 Audit bump button hit-area (cf. KDS audit 2026-05-11 : bump 32px insuffisant)
- T-3.2.2 Audit recall flow + audit trail
- T-3.2.3 Audit allergen modal typo (cf. `allergenModal` bug KDS audit 2026-05-11)
- T-3.2.4 Audit contrast WCAG (cf. KDS audit : 3.2:1 insuffisant, cible ≥4.5:1)
**Acceptance** : a11y axe-core 0 critical + bump hit-area ≥44px + 18 raw labels FR fixed.

#### Sub 3.3 — KDS Station Routing
**Anchors** : `app/Listeners/DispatchKdsTicket.php`, station config in `config/kds.php` (à vérifier)
**Tasks** :
- T-3.3.1 Audit multi-station dispatch (grill, fryer, drinks, salads)
- T-3.3.2 Audit overflow flag UI (V1.0.1 backlog : KDS overflow flag UI)
- T-3.3.3 Audit station filtering (chef sees only his station)
**Acceptance** : DispatchKdsTicket listener test + station filter sentinel + visual per-station view GREEN.

#### Sub 3.4 — KDS Sync (Echo + polling)
**Anchors** : `Admin/KdsSyncController.php`, `routes/channels.php` (branch-scoped), `Echo` + Pusher config
**Tasks** :
- T-3.4.1 Audit Echo channel authorization (branch-scoped post P4-1 FIX)
- T-3.4.2 Audit polling 5s fallback (Echo down → polling continues)
- T-3.4.3 Audit `KdsExpectedStatusConflictTest` (race condition guard)
- T-3.4.4 Audit Outbox listener `DispatchKdsTicket` (idempotency + retry)
**Acceptance** : 6 KDS tests + Outbox idempotency sentinel + manual Echo-off failover GREEN.

---

## §6 — Système 4 : OSS Order Status Screen

### Contract
Customer waiting screen (TV walls fast-food) : numéros en préparation vs ready, ticker real-time, multi-langue, branding, wakeLock pour TV (no screensaver).

### Décomposition en 3 sub-systèmes

#### Sub 4.1 — OSS Display
**Anchors** : `Admin/OrderStatusScreenController.php`, `public/js/admin-oss.js`, route `/oss-order` (public + admin), `/admin/order-status-screen`
**Tasks** :
- T-4.1.1 Audit columns layout (preparing / ready) + deterministic order (cf. Wave Z heal)
- T-4.1.2 Audit popular items widget (`mostPopularItems` action)
- T-4.1.3 Audit public vs admin endpoint security (no IDOR cross-branch)
- T-4.1.4 Audit OSS polish cluster B (Wave 5H Z4-P2-03/04/05/06 + NEW-Z4-01)
**Acceptance** : OSS polish 4 tasks + visual TV wall 1920×1080 GREEN + no IDOR sentinel.

#### Sub 4.2 — OSS Real-Time + Notifications
**Anchors** : `OrderStatusChanged` event + Echo channel + ticker JS
**Tasks** :
- T-4.2.1 Audit Echo subscription (status change → ticker update)
- T-4.2.2 Audit ticker rotation + sound (call number) — UX timing
- T-4.2.3 Audit reconnect logic (Echo down → reconnect strategy)
**Acceptance** : E2E status transition → OSS ticker update <2s + reconnect manual test PASS.

#### Sub 4.3 — OSS TV Walls + a11y + i18n
**Anchors** : OSS Vue/JS + i18n keys
**Tasks** :
- T-4.3.1 Audit wakeLock (Wave 5H : OSS wakeLock TV walls heal)
- T-4.3.2 Audit a11y for vision-impaired (large fonts, contrast WCAG AAA si possible pour TV)
- T-4.3.3 Audit i18n full FR/EN/AR (no raw label)
**Acceptance** : Lighthouse a11y ≥95 + wakeLock active 1h test + visual 3 langues GREEN.

---

## §7 — Système 5 : Stock + Synchronization (cross-surface)

### Contract
**Le système le plus critique pour la cohérence cross-surface.** Quand un produit passe en rupture → tous les surfaces (Kiosk, POS, KDS, OSS, Admin) doivent refléter en <2s. Backend : `StockLevel` + `StockMovement` + listeners outbox + Pusher Echo + polling fallback.

### Frozen zones (strict-no-touch — multi-tenant)
- `app/Models/Scopes/BranchScope.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`

### Décomposition en 4 sub-systèmes

#### Sub 5.1 — Stock Backend (data layer)
**Anchors** : `app/Models/StockLevel.php`, `app/Models/StockMovement.php`, `AvailabilityService` (cf. BRAIN `feedback_v1_focus_no_saas_2026-05-08.md` — 90% backend déjà existant)
**Tasks** :
- T-5.1.1 Audit StockLevel schema + indexes + BranchScope (13 models scoped post iter11+12)
- T-5.1.2 Audit StockMovement append-only (no UPDATE on past movements)
- T-5.1.3 Audit `DecrementStockOnOrderCreated` listener + race condition (Cache::lock)
- T-5.1.4 Audit `BumpMenuSnapshotOnItemAvailabilityChanged` (cache invalidation chain)
**Acceptance** : 4 stock backend sentinels PASS + DBA review GREEN.

#### Sub 5.2 — Stock UI (admin dashboard — V1.x backlog F-016b)
**Anchors** : `/admin/stock-rupture-dashboard` route, BRAIN backlog "F-016b stock dashboard UI 5-7j 90% backend déjà existant"
**Tasks** :
- T-5.2.1 Build stock dashboard UI (5-7j-agent budget) — rupture list + manual override + bulk actions
- T-5.2.2 Audit stock low notification (cf. `NotifyStockLowOnStockLevelChanged` listener)
- T-5.2.3 Audit branch manager permission scope (Spatie `permission:stock`)
**Acceptance** : UI dashboard GREEN visual 3 viewports + manual rupture flow E2E GREEN + permission sentinel.

#### Sub 5.3 — Stock Sync à toutes surfaces (Pusher + polling + cache invalidation)
**Anchors** : 10 listeners `Persist*ToOutbox.php`, 25 Events (`CatalogChanged`, `ItemAvailabilityChanged`, `ItemVariationAvailabilityChanged`, `ItemExtraAvailabilityChanged`, etc.), `InvalidateKioskMenuCacheOnItemAvailabilityChanged`, `InvalidateMenuProjectionOnIngredientChange`
**Tasks** :
- T-5.3.1 Audit Outbox pattern (PersistedToOutbox → drainer → Pusher emit)
- T-5.3.2 Audit `wasRecentlyCreated` guard (cf. Wave Z 6 listeners healed)
- T-5.3.3 Audit Outbox pruning (Wave 5E heal)
- T-5.3.4 Audit polling 5s fallback per surface (Kiosk + KDS + OSS)
**Acceptance** : Outbox sentinels + cross-surface E2E rupture cascade <2s GREEN.

#### Sub 5.4 — Stock Rupture Cascade (end-to-end test)
**Tasks** :
- T-5.4.1 E2E : admin sets item rupture → kiosk hides <2s → POS hides <2s → KDS knows → OSS reflects
- T-5.4.2 E2E : rupture during active wizard flow (graceful degradation modal)
- T-5.4.3 E2E : rupture cascade with branch isolation (branch A rupture doesn't affect branch B)
**Acceptance** : Wave D rupture cascade E2E GREEN (cf. iter15-mega Wave D précédent).

---

## §8 — Système 6 : Livreur (DeliveryBoy)

### Contract
**NOUVEAU dans le scope V1 explicite owner 2026-05-18.** Interface livreur : assignment d'orders, navigation address, paiement à la livraison (cash/card on-delivery/app paid), gestion caisse livreur (float espèce equipped at start-of-shift, end-of-shift reconciliation), équipement (delivery bag size, hot/cold compartments), capacity, delivery time tracking, alertes late-orders.

### Décomposition en 4 sub-systèmes

#### Sub 6.1 — Livreur Interface (orders + navigation + assignment)
**Anchors** : `Admin/DeliveryBoyOrderController.php` (admin assign), `Frontend/DeliveryBoyOrderController.php` (livreur view), `Admin/DeliveryBoyController.php` CRUD, `Admin/DeliveryBoyAddressController.php`, `Resources/DeliveryBoyOrderCountResource.php`, `Requests/DeliveryBoyRequest.php` + `Address`
**Tasks** :
- T-6.1.1 Audit interface livreur (liste assigned orders, accept/reject, status update)
- T-6.1.2 Audit navigation address (Google Maps deep link ou intégration)
- T-6.1.3 Audit auto-dispatch (V1.0.2 DEFERRED — confirmer flag off pour V1)
- T-6.1.4 Audit notifications livreur (push + sms + mail — cf. `SendOrderDeliveryBoy{Push,Sms,Mail}` events)
**Acceptance** : E2E livreur assign→accept→deliver flow GREEN + 3 notification channels test PASS + visual livreur dashboard GREEN.

#### Sub 6.2 — Livreur Payment Regulation (à la livraison)
**Anchors** : `app/Services/Delivery/DeliveryFeeService.php`, `Services/DeliveryBoyService.php`, integration `PaymentService`
**Tasks** :
- T-6.2.1 Audit cash collection at delivery (montant exact, monnaie rendue, no overpay theft)
- T-6.2.2 Audit card-on-delivery flow (TPE mobile livreur ou app)
- T-6.2.3 Audit app-pre-paid flow (no double-charge à la livraison)
- T-6.2.4 Audit DeliveryFeeService calculation (zone-based ou distance-based)
- T-6.2.5 Audit 2 NEW migrations 2026-05-18 : `delivery_fee_settings`, `delivery_minimum_order` config branch-level
**Acceptance** : 4 payment scenarios sentinel + delivery fee calculation test + migrations rollback-safe.

#### Sub 6.3 — Livreur Cash Management (float + reconciliation)
**Anchors** : extension de `CashDrawerSession` model ? OU nouveau `DeliveryBoyCashSession` ?
**Tasks** :
- T-6.3.1 Audit (ou build si manquant) start-of-shift cash equipment (e.g. livreur start avec 50€ float pour faire la monnaie)
- T-6.3.2 Audit end-of-shift reconciliation (cash collected - cash given as change = expected total)
- T-6.3.3 Audit livreur cash drawer forensic (parity avec POS cash drawer Z10-F-7)
- T-6.3.4 Audit livreur cash export (`DeliveryBoyExport.php` existant)
**Acceptance** : reconciliation sentinel + manual shift open/close dry-run + export Excel GREEN.

#### Sub 6.4 — Livreur Equipment + Delivery Details
**Tasks** :
- T-6.4.1 Audit equipment tracking (bag size, hot/cold compartments, max orders capacity) — possible new schema
- T-6.4.2 Audit delivery time tracking (assign → pickup → delivered timestamps)
- T-6.4.3 Audit late-order alerts (threshold-based notification to admin)
- T-6.4.4 Audit reporting interface admin (livreur performance dashboard)
**Acceptance** : equipment schema audit + late-order alert E2E + admin reporting visual GREEN.

⚠️ **Note** : Si T-6.3.1 / T-6.4.1 révèlent schema manquant → mini-DISCOVERY sub-phase avant BUILD. Pas de fiction — anchor first.

---

## §M — Mobile App (standalone, audit profond séparé)

### Contract
Mobile React Native app Le Cayenne, **standalone** (no API wireup vers V1 central pour le moment). Mirror data parity avec menu canonique (cf. cycle 2026-05-17 GREEN 12/12 E2E).

### Anchors
- `mobile/screens-main.jsx`, `mobile/screens-item-steps.jsx`, `mobile/screens-modals.jsx`, `mobile/screens-onboarding.jsx`
- `mobile/data/menu.js` (mirror), `mobile/data/loyalty.js`, `mobile/data/orders.js`, `mobile/data/user.js`, `mobile/data/wallet-spec.js`
- `mobile/design-canvas.jsx`, `mobile/components.jsx` (shared), `mobile/icons.jsx`, `mobile/ios-frame.jsx`
- `mobile/assets/menu/*.png` (photos owner)

### Décomposition (6 sub-systèmes — audit page-par-page)

#### Sub M.1 — Onboarding + Auth
**Tasks** : audit onboarding 3 écrans + auth flow + permission asks (location, push, camera).
**Acceptance** : E2E onboarding GREEN visual 3 viewports.

#### Sub M.2 — Catalog Browsing
**Tasks** : audit menu list + categories + search + filters + price display + image loading (746KB Chicken / 733KB Big Burger / 1.4MB Cayenne hero owner photos).
**Acceptance** : visual catalog GREEN + image lazy-load OK.

#### Sub M.3 — Wizard Composer (bols / burgers / frites / tacos)
**Tasks** : audit 4 templates wizard parity avec POS/Kiosk (composer_profile hardcoded mirror DB pour wireup futur).
**Acceptance** : 4 templates E2E GREEN + composer parity diff check.

#### Sub M.4 — Cart + Checkout + Payment
**Tasks** : audit cart + checkout + payment (Stripe app SDK) + address + delivery option.
**Acceptance** : checkout E2E GREEN visual.

#### Sub M.5 — Order Tracking + History + Loyalty
**Tasks** : audit order status updates + history + loyalty points + rewards.
**Acceptance** : loyalty 20/20 E2E + history visual GREEN.

#### Sub M.6 — Profile + Preferences + Settings
**Tasks** : audit profile edit + preferences + push notification settings + logout.
**Acceptance** : profile flow E2E GREEN.

---

## §W — Web Site (standalone, audit profond séparé)

### Contract
Site web Le Cayenne, **standalone** (no link V1 central). 23+ pages × 4 viewports (mobile/tablet/desktop/wide). Cycle 2026-05-17 GREEN 32/32 E2E.

### Anchors (`/Users/1millnonstop/Downloads/web/`)
- `index.html`, `screens.jsx`, `screens-v3.jsx`, `components.jsx`, `flows.jsx`, `funnel.jsx`
- `wizard-v2.jsx`, `loyalty-v2.jsx`, `account-v2.jsx`, `orders.jsx`
- `data/menu.js` (mirror canonique — REWRITE Wave 4 cycle 2026-05-17)
- `styles.css`, `styles-v2.css`, `styles-v3.css`, `styles-v4.css`, `styles-v5.css`, `styles-mobile.css`

### Décomposition (7 sub-systèmes — audit page-par-page × 4 viewports)

#### Sub W.1 — Landing + Hero + Brand
**Tasks** : audit landing hero + branding + nav header + hero CTA "Commander".
**Acceptance** : 4 viewports GREEN visual + Lighthouse perf ≥80.

#### Sub W.2 — Catalog Browsing
**Tasks** : audit menu list + categories + price display + 190 photos integrated.
**Acceptance** : catalog 4 viewports GREEN.

#### Sub W.3 — Wizard Composer (4 templates REWRITE)
**Tasks** : audit wizard 4 templates parity avec mobile + POS — REWRITE Wave cycle 2026-05-17.
**Acceptance** : 4 templates × 4 viewports = 16 E2E GREEN.

#### Sub W.4 — Cart + Checkout + Payment
**Tasks** : audit cart flow + Stripe Checkout + delivery vs pickup choice.
**Acceptance** : checkout 4 viewports GREEN.

#### Sub W.5 — Order Tracking + Account + History
**Tasks** : audit order status page + account dashboard + history.
**Acceptance** : account flow 4 viewports GREEN.

#### Sub W.6 — Loyalty + Rewards
**Tasks** : audit loyalty hub + rewards redemption.
**Acceptance** : loyalty page visual + rewards flow E2E GREEN.

#### Sub W.7 — Pages Support (legal, FAQ, contact, allergens)
**Tasks** : audit pages support + footer + legal compliance (CGV, mentions légales).
**Acceptance** : 4 pages × 4 viewports + legal review pass.

---

## §A — Agent Army Map (10+ subagents par task)

Pour chaque task : 5 specialists READ-ONLY en parallèle + 1 implementer + 1 RED-team + 1 QA visual + 1 RED visual (cross-check) = 9 base. Optionnel : +1 SRE (sync-heavy tasks) ou +1 fiscal (NF525-heavy).

| Rôle | Subagent type | Tools | Prompt template |
|---|---|---|---|
| Architect | `Plan` ou `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/architect-prompt.md` |
| Security | `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (SECURITY mode) |
| UX / A11y | `general-purpose` | Read + axe-core | inline brief WCAG 2.1 + ARIA + flat design |
| DBA | `general-purpose` | Read | inline brief schema + FK + N+1 + BranchScope (13 models) |
| SRE / Sync | `general-purpose` | Read | inline brief Outbox + Pusher + polling + queue |
| Implementer | `general-purpose` | Edit + Write + Bash | `~/.claude/skills/superpower-gstack/agents/implementer-prompt.md` (TDD-first) |
| RED-team | `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (RED mode) |
| QA Visual | `general-purpose` | Read + Playwright | inline brief : run spec + capture + analyze screenshot |
| RED Visual | `general-purpose` | Read | re-analyze screenshots independently, dispute QA findings |

### Dispatch discipline (`superpowers:dispatching-parallel-agents`)
- **Read-only fan-out** = SEUL MESSAGE multi-Agent calls (parallèle)
- **Implementer** = JAMAIS en parallèle (write conflict)
- **Visual QA + RED visual** = parallèle OK (read-only)
- **RED team dispute** = TOUJOURS après implementer, AVANT declare DONE

---

## §X — Convergence Plan (waves d'exécution)

Pour ne pas overrun usage limits + permettre reprises clean, le GOAL s'exécute en **8 waves** :

### Wave 1 — Pre-flight (1j-agent)
- Commit working tree POS payment fix (B5)
- Setup backup branch + DB dump pre-GOAL
- Run baseline sentinels + capture metrics
- Owner gates aligned (B1-B4 in flight in parallel)

### Wave 2 — Système 1 (POS) (1.5j-agent, 4 sub × 4 tasks = ~16 tasks)
- 4 sub-systèmes séquentiels (frozen-zone discipline)
- Pipeline per task ~30-45 min wall-clock × 16 tasks = ~10h
- Convergence gate : tous les tests POS PASS + visual GREEN

### Wave 3 — Système 2 (Kiosk) (1.5j-agent)
- 4 sub-systèmes, frozen-zone strict (3 components Vue)
- Pipeline per task
- Convergence gate : Kiosk wizard 5 templates GREEN + Sanctum + NF525

### Wave 4 — Système 3 + 4 (KDS + OSS) (1j-agent — parallèle possible)
- KDS et OSS audit en parallèle (surfaces indépendantes)
- Convergence gate : 6 KDS tests + OSS polish cluster B GREEN + visual TV walls

### Wave 5 — Système 5 (Stock + Sync) (1.5j-agent — CRITIQUE)
- 4 sub-systèmes séquentiels (cascading dependencies)
- Includes 5-7j-agent budget for T-5.2 stock dashboard UI build
- Convergence gate : rupture cascade E2E <2s GREEN cross-surface

### Wave 6 — Système 6 (Livreur) (1.5j-agent baseline — contingence 3j-agent)
- 4 sub-systèmes
- Si schema manquant → mini-plan + build before audit
- **Budget contingent** : si T-6.3.1 (DeliveryBoyCashSession) ou T-6.4.1 (equipment tracking) révèlent schema manquant, Wave 6 splite en **6a** (mini-DISCOVERY + plan migrations + UI specs) et **6b** (BUILD + audit), budget total étendu à **3j-agent**. Détection à l'étape ANCHOR du pipeline ultra-audit-profond.
- Convergence gate : livreur full delivery cycle E2E GREEN + 2 migrations + reconciliation sentinel

### Wave 7 — Standalone Mobile + Web (1j-agent en parallèle)
- Mobile : 6 sub × audits page-par-page
- Web : 7 sub × audits × 4 viewports
- Convergence gate : 12/12 mobile E2E + 32/32 web E2E (cf. cycle 2026-05-17 baseline)

### Wave 8 — Final Convergence + Production Gate (0.5j-agent)
- Full smoke 6 systèmes
- Cross-surface E2E flow complet (order kiosk → KDS → OSS → livreur)
- Frozen-zone diff = 0 attestation
- NF525 chain attestation
- Final BRAIN.md update + Graphiti push + final commit + tag `v1.0.2-production-ready`
- Owner sign-off triggered

---

## §G — Owner Gates (avant flip production)

| Gate | Description | Owner action | Status |
|---|---|---|---|
| G1 | AWS keys rotation (B1) | rotate + new keys in .env.cloud | PENDING |
| G2 | LOCK POS-A4 menu addon role mirror (B2) | countersign LOCK | PENDING |
| G3 | LOCK POS Wizard XSS escape (B3) | countersign LOCK | PENDING |
| G4 | OVH VPS-1 + Certbot + DR drill (B4) | 10-actions checklist | PENDING |
| G5 | GOAL Wave 1-8 converged green | review final BRAIN + visual report | TBD |
| G6 | Hardware install (POS + KDS + TPE + printer) | physical owner | TBD |
| G7 | Flip `POS_SIMULATION_HARDWARE=false` + deploy cloud | owner trigger | TBD |

---

## §R — References

### Skills (mandatées)
- **Génération** : `~/.claude/skills/ultra-architect-planify/SKILL.md` (a généré ce GOAL)
- **Exécution per-task** : `~/.claude/skills/ultra-audit-profond/SKILL.md` (pipeline 14 étapes)
- **Composition** : `~/.claude/skills/superpower-gstack/SKILL.md` (FoodKing bridge Superpowers + GStack)
- **E2E + visual** : `~/.claude/skills/test-e2e/SKILL.md` (dual-team adversarial)
- **Lock plans** : `~/.claude/skills/lock-plan/SKILL.md` (frozen-zone overrides)

### Docs (canonical)
- `CLAUDE.md` §§ 1-16 (operating memory)
- `PROJECT_BRAIN.md` §1 NORTH STAR + §2 CURRENT STATE + §7 VERIFICATION CHECKLIST
- `docs/methodology/GSTACK_PIPELINE_2026-05-08.md`
- `docs/AUTHZ_MATRIX.md` + `docs/BUSINESS_RULES.md` + `docs/ORDER_FLOW.md`
- `memory/reference_frozen_zones.md` (zones interdites)
- `memory/feedback_adversarial_audit_pattern.md` (RED methodology)
- `memory/feedback_gstack_pipeline_methodology.md` (7-step pipeline canonical)
- `memory/feedback_pos_simulation_hardware_pattern.md` (HARDWARE bypass discipline)

### Past cycles (lecture optionnelle pour context)
- `reports/test-e2e/v1-cloud-prep-2026-05-17/CONVERGENCE_FINAL.md`
- `reports/test-e2e/v1-0-1-2026-05-17/CONVERGENCE_V1_0_1.md`
- `reports/test-e2e/wave-z-2026-05-16-claudemax/CONVERGENCE_FINAL.md`
- `reports/audit/ultra-goal-2026-05-13/FINAL_VERDICT.md`

---

## §F — Final Rule

Ce GOAL ne sera **DONE** que quand TOUS sont vrais :

- [x] 8 waves converged green
- [x] 6 systèmes principaux production-perfect (visual + technique + sécurité)
- [x] 2 systèmes standalone audited page-par-page
- [x] 7 owner gates resolved
- [x] Cross-surface E2E flow complet (kiosk → KDS → OSS → livreur) GREEN
- [x] Frozen-zone diff = 0 attestation finale
- [x] NF525 chain attestation finale
- [x] Tag `v1.0.2-production-ready` créé + BRAIN final + Graphiti push

**Aucune tâche ne peut être "presque finie". Aucun bouton ne peut être manquant. Aucun écran ne peut avoir un raw label. Aucun écran ne peut avoir un layout cassé. Aucune sécurité ne peut être délégée à plus tard.**

Le flip production = hardware install + cloud deploy. Et le code sera **prêt**.

— FIN GOAL —
