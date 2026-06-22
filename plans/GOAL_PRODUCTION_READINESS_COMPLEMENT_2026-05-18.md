# GOAL — Production Readiness V1 COMPLEMENT (zones non-owned par session-A)
**Date** : 2026-05-18
**Branche** : `v1-0-1-hardening-2026-05-17` (HEAD `0ca8ea800`)
**Méthodologie** : `ultra-architect-planify` (ce document) + `ultra-audit-profond` (par tâche, voir `~/.claude/skills/ultra-audit-profond/`)
**Skip clarifying questions** : ✅ owner carte-blanche
**Target wall-clock** : 4-6 jours-agent (waves parallèles + heal restreint disjoint)
**Budget** : no limit (max sub-agent fan-out demandé par owner)

---

## §0 — Preamble (verrouillage scope & coexistence avec session-A)

### 0.1 Working-tree state + co-orchestration avec session-A

**Working tree state au lancement** (`git status --short` snapshot) :
```
M app/Console/Commands/FiscalVerifyChainCommand.php          ← session-A Wave 2c-2 WIP
M app/Console/Commands/OutboxRetryFailedCommand.php          ← session-A Wave 2c-5 WIP
M app/Console/Commands/OutboxWebhookRetryFailedCommand.php   ← session-A Wave 2c-5 WIP
M app/Http/Kernel.php                                         ← session-A Wave 2c WIP
M app/Http/Middleware/TrustHosts.php                          ← session-A Wave 2c-4 WIP
M mobile/screens-main.jsx                                     ← origin uncertain (pre-existing WIP)
M public/js/admin-oss.js                                      ← origin uncertain (pre-existing WIP)
M public/js/kiosk-shell.js                                    ← origin uncertain (pre-existing WIP)
M tests/Feature/Outbox/OutboxReplayAuditTest.php              ← session-A Wave 2c WIP
```

**Décision** : NE PAS toucher ces fichiers — laisser session-A finir ses heals 2c-2 → 2c-5. Cette mission opère sur **scope strictement disjoint**.

**Backup** : avant toute heal, créer la branche `backup/pre-goal-complement-2026-05-18` depuis HEAD `0ca8ea800` (Wave 0 task).

### 0.2 Co-orchestration map — qui possède quoi

| Système / Zone | Owner session-A | Owner session-B (ce GOAL) |
|---|---|---|
| Fiscal (FiscalSequenceService, ZReportService, AuditLogService) | ✅ Wave 2c-2 heal verify-chain | ❌ read-only attest pattern only |
| Outbox (OutboxRetryFailed + WebhookRetry + Cache::lock) | ✅ Wave 2c-5 heal | ❌ read-only audit only |
| TrustHosts whitelist + TrustProxies | ✅ Wave 2c-4 heal | ❌ off-limits |
| KDS cadence cap (60s) + cron iterate | ✅ Wave 2c-3 heal | ❌ off-limits |
| Adversarial dispute Wave 2c | ✅ Wave 3c | ❌ off-limits |
| POS Caisse E2E | ✅ Wave 4b (post-8:20am reset) | ❌ off-limits |
| Kiosk Borne E2E | ✅ Wave 4b | ❌ off-limits |
| Sync cross-surface E2E | ✅ Wave 4 batch 2 | ❌ read-only audit only |
| Admin daily E2E | ✅ Wave 4 batch 2 | ❌ read-only audit only |
| CONVERGENCE_FINAL.md | ✅ session-A | ❌ off-limits |
| **KDS DEEPER (post-batch-1 logic + contract + edge cases)** | — | ✅ **READ-ONLY** (KDS R1 AMBER, dirty frontend) |
| **OSS FULLSYS (page-by-page UI/A11y, sync robustness, edge cases)** | — | ✅ **READ-ONLY** (dirty frontend) |
| **STOCK FULLSYS (backend integrity + dashboard UI + concurrent flows)** | — | ✅ **HEAL ALLOWED** (services not dirty) |
| **LIVREUR FULLSYS (admin + frontend + delivery fee + assignment)** | — | ✅ **HEAL ALLOWED** (services not dirty) |
| **PRICING SSOT (Zone 5 critical focus)** | — | ✅ **READ-ONLY** (frozen-zone strict) |
| **MOBILE standalone (post 2026-05-17 GREEN)** | — | ✅ **READ-ONLY** (mobile/screens-main.jsx dirty) |
| **WEB standalone (/Downloads/web/)** | — | ✅ **HEAL ALLOWED** (different filesystem) |
| **CROSS-SURFACE i18n + A11y hunt** | — | ✅ **READ-ONLY** (cross-system, would touch dirty surfaces) |

Heal scope total = **3 zones disjointes** (Stock backend, Livreur backend, Web standalone). Tout le reste = **audit profond persisté disque**, heal différé.

### 0.3 NF525 attestation pattern (corrige bit-identical → appended-only)

session-A peut **légitimement** étendre `audit_logs` (Z-report close, fiscal:verify-chain extend) pendant cette mission. Attestation pattern :

```
APPENDED-ONLY ✅ : count(audit_logs) à wave-close >= count à wave-start (jamais décrémenté)
HASH-EXTENDS ✅  : last_hash de wave-close peut différer de wave-start IF count augmenté
TAMPER FORBIDDEN ❌ : count décrémenté = critical alert
TAMPER FORBIDDEN ❌ : count identique + last_hash différent = chain rewrite = critical alert
TAMPER FORBIDDEN ❌ : `php artisan fiscal:verify-chain` != CHAIN OK = critical alert
```

Baseline Wave 0 : capture `SELECT count(*) AS n, MAX(current_hash) AS h FROM audit_logs;`
Wave 6 final : ré-exécuter, attester pattern (pas snapshot bit-identical).

### 0.4 Pipeline mandaté par tâche (voir `~/.claude/skills/ultra-audit-profond/`)

Chaque tâche **HEAL** du GOAL suit la séquence canonique (14 étapes) :
```
1. ANCHOR        → grep file:line réel, refus de fiction (anti-hallucination)
2-6. 5 SPECIALISTS (parallèles, 1 message) : Architect + Security + UX/A11y + DBA + SRE
7. SYNTHESIZE    → main agent consolide en P0/P1/P2
8. IMPLEMENT     → scope-minimal (≤30 LOC inline OU subagent implementer TDD-first)
9. RED-TEAM      → adversarial dispute (hostile framing, cherche P0s manqués)
10. TEST tech    → PHPUnit + Vitest + sentinels 100% PASS exact count
11. E2E + VISUAL → Playwright headed + screenshot + Read + analyse
    LOOP heal max 3 cycles → si > 3 ESCALATE owner
12. ADVERSARIAL VISUAL → 2e agent re-analyse les screenshots (cross-check)
13. COMMIT       → INLINE-EDIT-EXCEPTION format si applicable
14. REFLECT      → BRAIN.md update + Graphiti push si pattern significatif
```

Chaque tâche **AUDIT-ONLY** s'arrête à l'étape 7 (synthesize) — les findings sont persistés disque, heal différé documenté.

### 0.5 Convergence criteria (production-perfect, pas juste GREEN)

Tâche **HEAL** = DONE quand TOUS vrais :
- [x] 5 specialists ont audité (P0 list verified)
- [x] RED-team a disputé (no new P0 vs synthesize)
- [x] PHPUnit + Vitest sentinels 100% PASS exact count
- [x] E2E Playwright headed PASS sur surface(s) touchée(s)
- [x] Screenshots analysés par QA-Visual + RED-Visual (no raw labels, no broken layout, no console error, no a11y critical)
- [x] Frozen-zone diff GOAL range = zéro lignes
- [x] Commit avec evidence + BRAIN.md updated

Tâche **AUDIT-ONLY** = DONE quand TOUS vrais :
- [x] 5 specialists ont audité, findings.json persisté disque
- [x] P0/P1/P2 verified avec file:line citation **réelle** (Read tool sur le fichier confirmé)
- [x] Heal recommendation scope-minimal documentée (mais NON appliquée)
- [x] Ticket V1.0.X backlog référencé OR escalation reason

Convergence GOAL = **2 cycles consécutifs P0+P1=0 stable** sur le scope HEAL, ET audit-only zones ont leur backlog clos avec scope-minimal recommendation.

---

## §1 — Map des 8 systèmes scope COMPLEMENT (anchors verified)

| # | Système | Mode | Anchor primaire (verified) | Tests existants | Surface visuelle |
|---|---|---|---|---|---|
| 1 | **KDS deeper** | AUDIT-ONLY | `Admin/KitchenDisplaySystemController.php` + `Admin/KdsSyncController.php` + 5 Vue `kitchenDisplaySystem/Kds*.vue` + `public/js/admin-kds.js` | 16 tests `tests/Feature/Kds*` + `tests/Feature/KDS*` | `/admin/kitchen-display-system` |
| 2 | **OSS fullsys** | AUDIT-ONLY | `Admin/OrderStatusScreenController.php` + `public/js/admin-oss.js` (dirty) + `resources/js/services/OssSyncService.js` | 6 spec `tests/js/oss*` + orderStatusScreen | `/order-status-screen` |
| 3 | **Stock fullsys** | **HEAL** | `app/Services/Stock/StockService.php` + `ChoiceAvailabilityResolver.php` + `Admin/StockRuptureDashboardController.php` + `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` | 10 tests `tests/Feature/Stock/*` + `Availability/StockReleaseTest` | `/admin/stock-rupture-dashboard` |
| 4 | **Livreur fullsys** | **HEAL** | `app/Services/Delivery/DeliveryFeeService.php` + `DeliveryQuoteService.php` + `Admin/DeliveryBoy{,Order,Address}Controller.php` + `Frontend/DeliveryBoyOrderController.php` | 4 tests `tests/Feature/Delivery/*` + `DeliveryBoyOrderStatusOrderingTest` + `tests/Unit/Services/DeliveryFeeServiceTest.php` | `/admin/delivery-boys` + livreur-app |
| 5 | **Pricing SSOT** | AUDIT-ONLY (FROZEN) | `app/Services/Pricing/PricingService.php` *(FROZEN §7)* + `PricingRequest.php` + `CompositionSnapshotBuilder.php` + `DiscountCalculator.php` + `TaxCalculator.php` | `PosPricingSsotProofTest` + `PricingIntegrityTest` + `PosKioskPricingParityTest` + 2 sentinels + 2 service tests | (no direct surface — invariant) |
| 6 | **Mobile standalone** | AUDIT-ONLY | `mobile/screens-main.jsx` (dirty) + `mobile/data/menu.js` + `mobile/screens-item-steps.jsx` + `mobile/screens-modals.jsx` + `mobile/components/*` | 22 specs `tests/mobile-e2e/loyalty-*` + adv | mobile preview |
| 7 | **Web standalone** | **HEAL** | `/Users/1millnonstop/Downloads/web/` — `screens.jsx` (43 KB) + `funnel.jsx` + `flows.jsx` + `components.jsx` + `account-v2.jsx` + `loyalty-v2.jsx` + `orders.jsx` + `data/menu.js` + `legal/` | `tests/e2e/__screenshots__/test-e2e-website-realignment-2026-05-16/` artifacts | web preview × 4 viewports (mobile/tablet/desktop/wide) |
| 8 | **Cross-surface i18n+A11y** | AUDIT-ONLY | `resources/lang/{fr,en,ar}/*.php` + `public/js/admin-*.js` + cross-surface raw-label hunt | sentinel tests i18n drift detection | all surfaces sweep |

**Règle absolue** : mobile + web **restent standalone**. Aucun wireup API/MCP vers le système central V1 dans ce GOAL.

---

## §2 — Système KDS deeper (AUDIT-ONLY)

### Contract
Kitchen Display System : V2 default-on (`config/kds.php`). 8 cards × 1 col layout. Order lifecycle PENDING → PREPARING → READY → COMPLETED. Bump CTA + shortcuts [A]/[B]. Allergen pill + recall mechanism. Sync via Echo (Pusher) + polling fallback (cadence configurable + jitter).

### Frozen zones (cette mission)
- `public/js/admin-kds.js` *(modifié récemment 0ca8ea800 — laisser stable)*

### Anchors verified (Wave 0)
```
app/Http/Controllers/Admin/KitchenDisplaySystemController.php
app/Http/Controllers/Admin/KdsSyncController.php
app/Services/Kds/                      ← (verify if exists; sister of OSS)
resources/js/components/admin/kitchenDisplaySystem/
  ├── KdsStatusBanner.vue
  ├── KdsV2Grid.vue
  ├── KdsOrderLine.vue
  ├── KdsUndoToast.vue
  └── KdsOrderCard.vue
public/js/admin-kds.js                 ← compiled, recent fix KDS-R1-05 a11y
tests/Feature/Kds/                     ← 6 files (TZ-aware, Sargable, etc.)
tests/Feature/KDS*.php                 ← 10 files (Pagination, ExpectedStatusConflict, etc.)
```

### Pre-flight reference
- `reports/test-e2e/goal-pageby-2026-05-18/round-1/KDS/FINAL_SUMMARY.md` : Round 1 = **AMBER** (0 P0, 2 P1, 2 P2, 1 P3). Healing commits `2fb5a7df1`, `afd5787ec`, `0ca8ea800` ont fermé KDS-R1-01, R1-02, R1-03, R1-05. **KDS-R1-04 status incertain** (multi-item body-fade clip, needs re-seed Round 2).

### Décomposition en 3 sub-systèmes

#### Sub 2.1 — KdsOrder contract + lifecycle integrity
**Anchors** : `Admin/KitchenDisplaySystemController.php` (index/show methods), `Admin/KdsSyncController.php` (sync/status endpoints), `app/Services/Kds/KdsSyncService.php` (verify), `app/Models/Order.php` (status transitions)
**Tasks** :
- T-2.1.1 Audit KdsOrder canonical contract (id, queue_number, items[], allergens[], status, elapsed_seconds, station). Verify all rendered fields traceable to backend payload, no derivation from null.
  - anchor: `Admin/KdsSyncController.php` (find via `find app/Http/Controllers -iname "KdsSync*"`)
  - test: `tests/Feature/Kds/KdsSyncSargableTest.php` (existing) + `(test TO BE CREATED at tests/Feature/Kds/KdsOrderContractIntegrityTest.php)`
- T-2.1.2 Audit transition whitelist (PENDING → PREPARING → READY → COMPLETED). Reject invalid jumps. Concurrency-safe via Cache::lock or DB FOR UPDATE.
  - anchor: `app/Services/Kds/` (verify path) + `Admin/KdsSyncController.php::changeStatus`
  - test: `tests/Feature/KdsTransitionWhitelistTest.php` + `tests/Feature/KdsChangeStatusConcurrencyTest.php` + `tests/Feature/Sentinels/KdsTransitionWhitelistSentinelTest.php` (existing)
- T-2.1.3 Audit allergen aggregation split (multi-item order, split-by-station, deduplication). Cf. project_v1_0_1_hardening healed pattern.
  - anchor: `tests/Feature/Kds/KdsAllergenAggregationSplitTest.php` (existing)
  - test: existing + `(test TO BE CREATED at tests/Feature/Kds/KdsAllergenCrossStationDedupTest.php)` if dedup gap found

**Acceptance (AUDIT-ONLY)** : findings.json with P0/P1/P2 verified Read-cited + scope-minimal heal recommendation. Heal NOT applied (heal commit blocked until session-A converges).

#### Sub 2.2 — Recall mechanism + station surface
**Anchors** : `KdsOrderCard.vue` (recall button), `KdsUndoToast.vue` (undo flow), `Admin/KdsSyncController.php::recall` (verify endpoint), `tests/Feature/Sentinels/KdsTransitionWhitelistSentinelTest.php`
**Tasks** :
- T-2.2.1 Audit recall flow (COMPLETED → PREPARING ou READY back). Idempotent + audit log entry + rollback safe.
  - anchor: `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue`
  - test: `(test TO BE CREATED at tests/Feature/Kds/KdsRecallIdempotencyTest.php)`
- T-2.2.2 Audit station surface (single-station per GOAL §5.3.3, but verify station_filter prop drives correct subset).
  - anchor: `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue`
  - test: existing `tests/Feature/KDSScopeRestrictionTest.php`
- T-2.2.3 Audit undo toast (mis-bump recovery). Verify 5s window + Cache::lock to prevent double-undo + chain hash unchanged.
  - anchor: `KdsUndoToast.vue` + `Admin/KdsSyncController.php::undo` (verify endpoint)
  - test: `(test TO BE CREATED at tests/Feature/Kds/KdsUndoToastWindowTest.php)`

**Acceptance (AUDIT-ONLY)** : findings.json. Identical persistence schema.

#### Sub 2.3 — Edge cases (overflow, expected status, pagination)
**Anchors** : `tests/Feature/KdsPaginationOverflowTest.php`, `tests/Feature/KdsExpectedStatusConflictTest.php`, `tests/Feature/KdsBranchFilterExactTest.php` (existing)
**Tasks** :
- T-2.3.1 Audit pagination overflow flag (>50 orders display "+N more" or page indicator).
  - anchor: `KdsV2Grid.vue` + controller
  - test: existing
- T-2.3.2 Audit expected_status conflict (KDS state diverges from Order model state — auto-reconciliation logic).
  - anchor: existing test
  - test: existing
- T-2.3.3 Audit branch-filter exactness (cross-branch leak prevention — BranchScope global).
  - anchor: `tests/Feature/KdsBranchFilterExactTest.php` + `tests/Feature/KDSScopeRestrictionTest.php`
  - test: existing

**Acceptance (AUDIT-ONLY)** : findings.json. KDS-R1-04 re-investigation report attached if applicable.

---

## §3 — Système OSS fullsys (AUDIT-ONLY — frontend dirty)

### Contract
Order Status Screen : public wall display + chime audio + wake lock. Customer waits for order. Sync via Echo + polling fallback. Mobile-fallback responsive.

### Frozen zones (cette mission)
- `public/js/admin-oss.js` *(dirty 46-line WIP — origin uncertain, ne pas écraser)*

### Anchors verified
```
app/Http/Controllers/Admin/OrderStatusScreenController.php
public/js/admin-oss.js                                ← dirty (46 lines)
resources/js/services/OssSyncService.js
tests/js/orderStatusScreenOssSync.spec.js
tests/js/ossChimePublicWall.spec.js
tests/js/ossWakeLockOnMount.spec.js
tests/js/ossSyncFallback.spec.js
tests/js/ossSyncReconnect.spec.js  (verify if exists)
```

### Décomposition en 3 sub-systèmes

#### Sub 3.1 — OSS UI/A11y page-by-page
**Anchors** : `Admin/OrderStatusScreenController.php` (Blade view), public wall layout, font scaling
**Tasks** :
- T-3.1.1 Audit raw labels (zero `oss.X`, zero `Label.foo`, zero `0undefined`)
  - anchor: `Admin/OrderStatusScreenController.php` + Blade view
  - test: `(test TO BE CREATED at tests/Feature/Oss/OssRawLabelSentinelTest.php)` + visual capture
- T-3.1.2 Audit contrast WCAG AA on order numbers (large display)
  - anchor: OSS CSS
  - test: visual capture + axe-core run
- T-3.1.3 Audit responsive breakpoints (mobile fallback + tablet + desktop)
  - anchor: OSS view
  - test: visual capture × 4 viewports

#### Sub 3.2 — OSS sync robustness
**Anchors** : `resources/js/services/OssSyncService.js`, `tests/js/ossSyncFallback.spec.js`, `tests/js/orderStatusScreenOssSync.spec.js`
**Tasks** :
- T-3.2.1 Audit polling cadence (config + cap 60s after session-A Wave 3b). Verify no race.
  - anchor: `OssSyncService.js`
  - test: existing
- T-3.2.2 Audit Echo (Pusher) reconnect after drop. Re-subscribe + re-fetch.
  - anchor: `OssSyncService.js`
  - test: existing `ossSyncFallback.spec.js`
- T-3.2.3 Audit wake-lock release on unmount. Battery-friendly.
  - anchor: `OssWakeLockOnMount.spec.js`
  - test: existing

#### Sub 3.3 — OSS edge cases (chime, reconnect, multi-order)
**Tasks** :
- T-3.3.1 Audit chime trigger (only on status → READY, not on initial mount, debounce)
  - anchor: `ossChimePublicWall.spec.js`
  - test: existing
- T-3.3.2 Audit multi-order render (10+ orders concurrent, ordering deterministic)
  - anchor: OSS service
  - test: `(test TO BE CREATED at tests/js/ossMultiOrderOrderingSpec.js)`
- T-3.3.3 Audit session-A Wave 3b TZ-aware fix sister-service applied correctly (commit `c2613cab0` heal)
  - anchor: `app/Services/Oss/` (sister of KDS) — verify path
  - test: `(test TO BE CREATED at tests/Feature/Oss/OssTzAwareTest.php)` mirroring `tests/Feature/Kds/KdsSyncTzAwareTest.php`

**Acceptance (AUDIT-ONLY)** : findings.json. Heal recommendations queued for post-session-A.

---

## §4 — Système Stock fullsys (HEAL ALLOWED — backend services not dirty)

### Contract
Stock management : item-level + composition-level (option_id) + branch-scoped. Append-only `stock_movements`. Auto-rupture detection. Dashboard UI alerting low stock.

### Frozen zones (cette mission)
- aucun frozen file pertinent — services + dashboard libres

### Anchors verified
```
app/Services/Stock/StockService.php
app/Services/Stock/ChoiceAvailabilityResolver.php
app/Http/Controllers/Admin/StockRuptureDashboardController.php
app/Models/StockLevel.php
app/Models/StockMovement.php
resources/js/components/admin/stock/StockRuptureDashboardComponent.vue
resources/js/components/admin/dashboard/StockLowAlertsWidget.vue
tests/Feature/Stock/                  ← 10 files (Concurrent, Idempotency, Release, etc.)
tests/Feature/Availability/StockReleaseTest.php
tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php
```

### Décomposition en 3 sub-systèmes

#### Sub 4.1 — Stock backend integrity (concurrent + idempotency)
**Anchors** : `StockService.php` (decrement/increment), `StockMovement.php` (append-only enforcement)
**Tasks** :
- T-4.1.1 Heal-or-audit concurrent decrement race (5 orders parallel, same item, branch-scoped lock)
  - anchor: `app/Services/Stock/StockService.php` (decrement method)
  - test: `tests/Feature/Stock/StockConcurrentDecrementTest.php` (existing — verify passes; if flake, heal Cache::lock + DB FOR UPDATE)
- T-4.1.2 Heal-or-audit idempotency key UNIQUE constraint on movements (replay-safe)
  - anchor: `app/Models/StockMovement.php` + migration `add_idempotency_key_to_stock_movements_*`
  - test: `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php` (existing)
- T-4.1.3 Heal-or-audit append-only enforcement (no UPDATE, no DELETE on stock_movements)
  - anchor: migration trigger `BEFORE DELETE` + `BEFORE UPDATE` on `stock_movements`
  - test: `tests/Feature/Stock/StockMovementsAppendOnlyTest.php` (existing)

**Acceptance (HEAL)** : 3 tests above 100% PASS exact count + sentinel re-run after any heal + frozen-zone diff 0 + screenshots for any dashboard touch.

#### Sub 4.2 — Stock release flows (cancel + refund + composition option)
**Anchors** : `tests/Feature/Stock/StockReleaseOnCancelTest.php` + `StockReleaseOnRefundTest.php` + `WizardOptionStockSyncTest.php`
**Tasks** :
- T-4.2.1 Heal-or-audit release on cancel (refund stock_levels + audit_log entry)
  - anchor: `app/Services/Stock/StockService.php` (release method) + `app/Services/Order/CancelOrderService.php` (verify path)
  - test: `tests/Feature/Stock/StockReleaseOnCancelTest.php` (existing) + `tests/Feature/Availability/StockReleaseTest.php`
- T-4.2.2 Heal-or-audit release on refund (partial refund = partial release)
  - anchor: `StockService.php` + refund listener
  - test: `tests/Feature/Stock/StockReleaseOnRefundTest.php` (existing)
- T-4.2.3 Heal-or-audit composition option sync (wizard option choice → stock_level decrement via `ChoiceAvailabilityResolver`)
  - anchor: `app/Services/Stock/ChoiceAvailabilityResolver.php` + `app/Services/Order/SubmitOrderService.php` (verify path)
  - test: `tests/Feature/Stock/WizardOptionStockSyncTest.php` (existing) + `tests/Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php`

**Acceptance (HEAL)** : 5 tests PASS exact count + sentinel run + commit.

#### Sub 4.3 — Stock dashboard UI heal-light (F-016b backlog from V1.x)
**Anchors** : `StockRuptureDashboardController.php` + `StockRuptureDashboardComponent.vue` + `StockLowAlertsWidget.vue`
**Tasks** :
- T-4.3.1 Heal raw labels in dashboard (zero `stock.X`, fully FR i18n) — visual capture sweep
  - anchor: `StockRuptureDashboardComponent.vue`
  - test: `(test TO BE CREATED at tests/Feature/Stock/StockDashboardRawLabelSentinelTest.php)` + visual capture
  - visual: `http://127.0.0.1:8000/admin/stock-rupture-dashboard`
- T-4.3.2 Heal contrast WCAG AA on low-stock badges + alert colors (red/orange not below 4.5:1)
  - anchor: `StockRuptureDashboardComponent.vue` CSS + `StockLowAlertsWidget.vue`
  - test: visual axe-core check
  - visual: dashboard + widget overlay
- T-4.3.3 Heal-or-audit threshold config (low_stock_threshold per item, default 5, customizable)
  - anchor: `StockRuptureDashboardController.php` + config
  - test: `(test TO BE CREATED at tests/Feature/Stock/StockThresholdConfigTest.php)`

**Acceptance (HEAL)** : visual capture × admin desktop viewport + axe pass + 3 sentinel tests PASS + dashboard screenshot analysis (QA Visual + RED Visual).

---

## §5 — Système Livreur fullsys (HEAL ALLOWED — services not dirty)

### Contract
Delivery driver app : assignment from admin → driver fetches order → status updates (en route, delivered) → optional GPS. Delivery fee configurable per branch (2 NEW migrations 2026-05-18). Minimum order config.

### Frozen zones (cette mission)
- aucun frozen file pertinent

### Anchors verified
```
app/Services/Delivery/DeliveryFeeService.php
app/Services/Delivery/DeliveryQuoteService.php
app/Http/Controllers/Admin/DeliveryBoyController.php
app/Http/Controllers/Admin/DeliveryBoyOrderController.php
app/Http/Controllers/Admin/DeliveryBoyAddressController.php
app/Http/Controllers/Frontend/DeliveryBoyOrderController.php
tests/Feature/Delivery/                ← directory exists
tests/Feature/Delivery/DeliveryFeeConfigurableTest.php
tests/Feature/Delivery/DeliveryFeeForgePosTest.php
tests/Feature/DeliveryBoyOrderStatusOrderingTest.php
tests/Feature/DeliveryOrderContractTest.php
tests/Feature/PosWalkInAndDeliveryFeeTest.php
tests/Unit/Services/DeliveryFeeServiceTest.php
tests/js/kdsLegacyDeliveryAllLanes.spec.js
tests/js/deliveryCharge.spec.js
tests/e2e/_red-e-livreur-round3-2026-05-18.spec.js   ← E2E reference
```

### Décomposition en 3 sub-systèmes

#### Sub 5.1 — Delivery fee + quote (backend service)
**Anchors** : `DeliveryFeeService.php` + `DeliveryQuoteService.php` + 2 NEW migrations (`add_delivery_fee_settings_to_branches`, `add_delivery_minimum_order_to_branches`)
**Tasks** :
- T-5.1.1 Heal-or-audit fee calculation (flat / per-km / minimum order threshold). Branch-scoped config.
  - anchor: `app/Services/Delivery/DeliveryFeeService.php`
  - test: `tests/Unit/Services/DeliveryFeeServiceTest.php` (existing) + `tests/Feature/Delivery/DeliveryFeeConfigurableTest.php` (existing)
- T-5.1.2 Heal-or-audit minimum order enforcement (block order < min, FR i18n error)
  - anchor: `app/Services/Delivery/DeliveryQuoteService.php` + migration `add_delivery_minimum_order_to_branches`
  - test: `(test TO BE CREATED at tests/Feature/Delivery/DeliveryMinimumOrderEnforcedTest.php)`
- T-5.1.3 Heal-or-audit POS walk-in path (POS staff manual entry — fee applied correctly)
  - anchor: `app/Services/Delivery/DeliveryFeeService.php` + `Admin/PosController.php` (walk-in method)
  - test: `tests/Feature/PosWalkInAndDeliveryFeeTest.php` (existing) + `tests/Feature/Delivery/DeliveryFeeForgePosTest.php`

**Acceptance (HEAL)** : 5 tests above 100% PASS + sentinel + commit.

#### Sub 5.2 — Delivery assignment + order lifecycle
**Anchors** : `Admin/DeliveryBoyOrderController.php` (assign + reassign) + `Frontend/DeliveryBoyOrderController.php` (driver UI) + `DeliveryBoyOrderStatusOrderingTest.php`
**Tasks** :
- T-5.2.1 Heal-or-audit assignment (admin assigns order to driver, idempotent, audit log)
  - anchor: `app/Http/Controllers/Admin/DeliveryBoyOrderController.php`
  - test: `tests/Feature/DeliveryBoyOrderStatusOrderingTest.php` (existing) + `tests/Feature/DeliveryOrderContractTest.php`
- T-5.2.2 Heal-or-audit status transitions (ASSIGNED → EN_ROUTE → DELIVERED, no back-jump)
  - anchor: `Frontend/DeliveryBoyOrderController.php::updateStatus`
  - test: `(test TO BE CREATED at tests/Feature/Delivery/DeliveryStatusTransitionWhitelistTest.php)` — mirror Kds transition pattern
- T-5.2.3 Heal-or-audit notifications cascade (driver gets push/sms/mail on assignment + status change — listeners idempotent)
  - anchor: `app/Events/SendOrderDeliveryBoyPush.php` + `app/Listeners/SendOrderDeliveryBoyPushNotification.php` (verify path) + Sms + Mail listeners
  - test: `(test TO BE CREATED at tests/Feature/Delivery/DeliveryNotificationsIdempotencyTest.php)`

**Acceptance (HEAL)** : 4 tests PASS + commit + visual capture admin delivery panel.

#### Sub 5.3 — Livreur admin CRUD + addresses + frontend
**Anchors** : `Admin/DeliveryBoyController.php` (CRUD), `Admin/DeliveryBoyAddressController.php` (delivery zones), `Frontend/DeliveryBoyOrderController.php` (driver-facing)
**Tasks** :
- T-5.3.1 Heal-or-audit driver CRUD (create/edit/delete/restore + BranchScope per driver assignment)
  - anchor: `Admin/DeliveryBoyController.php`
  - test: `(test TO BE CREATED at tests/Feature/Delivery/DeliveryBoyCrudBranchScopeTest.php)`
- T-5.3.2 Heal-or-audit address management (driver-served addresses + zone radius)
  - anchor: `Admin/DeliveryBoyAddressController.php`
  - test: `(test TO BE CREATED at tests/Feature/Delivery/DeliveryBoyAddressZoneTest.php)`
- T-5.3.3 Heal-or-audit driver frontend (order list + claim + status update)
  - anchor: `Frontend/DeliveryBoyOrderController.php`
  - test: existing `tests/e2e/_red-e-livreur-round3-2026-05-18.spec.js` (reference)
  - visual: livreur driver-facing UI (verify routes)

**Acceptance (HEAL)** : 3 sentinels PASS + visual capture admin + driver-facing + commit.

---

## §6 — Système Pricing SSOT (AUDIT-ONLY — FROZEN strict)

### Contract
SSOT pricing : 100% calcul backend via `PricingService::calculateOrder`. `composition_snapshot` JSON frozen à création (immutable). Frontend envoie item_id + qty + option_ids UNIQUEMENT. Aucun env flag pour bypass. Sentinel production-stable.

### Frozen zones (cette mission)
- `app/Services/Pricing/PricingService.php` *(FROZEN §7 — Backend multi-tenant + payment critical)*

### Anchors verified
```
app/Services/Pricing/PricingService.php           ← FROZEN
app/Services/Pricing/PricingRequest.php
app/Services/Pricing/PricingResult.php
app/Services/Pricing/PricingLineResult.php
app/Services/Pricing/CompositionSnapshotBuilder.php
app/Services/Pricing/DiscountCalculator.php
app/Services/Pricing/TaxCalculator.php
tests/Feature/PosPricingSsotProofTest.php
tests/Feature/PricingIntegrityTest.php
tests/Feature/PosKioskPricingParityTest.php
tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php
tests/Feature/Sentinels/PricingSsotFlagProductionStableSentinelTest.php
tests/Feature/Services/Pricing/PricingServiceTest.php
tests/Feature/Services/Pricing/PricingServiceMultiQtyTest.php
tests/Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php
```

### Décomposition en 3 sub-systèmes

#### Sub 6.1 — PricingService calculation paths
**Tasks** :
- T-6.1.1 Audit `calculateOrder` deterministic (same inputs → same outputs, no time-dep, no random)
  - anchor: `app/Services/Pricing/PricingService.php` (FROZEN — read-only)
  - test: `tests/Feature/Services/Pricing/PricingServiceTest.php` (existing) + `PricingServiceMultiQtyTest.php`
- T-6.1.2 Audit discount + tax order-of-operations (discount before tax? tax-inclusive? French TVA)
  - anchor: `DiscountCalculator.php` + `TaxCalculator.php`
  - test: `tests/Feature/PricingIntegrityTest.php` (existing)
- T-6.1.3 Audit POS ↔ Kiosk parity (same item + options → same final price)
  - anchor: `tests/Feature/PosKioskPricingParityTest.php`
  - test: existing + sentinel `PricingSsotFlagProductionStableSentinelTest.php`

**Acceptance (AUDIT-ONLY)** : findings.json. NO heal application (FROZEN). Any P0 = ESCALATE owner immediately with LOCK plan recommendation.

#### Sub 6.2 — Composition snapshot integrity
**Tasks** :
- T-6.2.1 Audit snapshot frozen at creation (5 write sites, zero UPDATE)
  - anchor: `CompositionSnapshotBuilder.php`
  - test: `tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php` (existing)
- T-6.2.2 Audit historical re-order replay (old order replay → uses snapshot, not current prices)
  - anchor: snapshot read path
  - test: existing sentinel
- T-6.2.3 Audit SubmitRevalidatesChoiceAvailability through Pricing (re-check at submit not crash if option unavailable)
  - anchor: `tests/Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php`
  - test: existing

#### Sub 6.3 — Pricing SSOT sentinels + bypass forbidden
**Tasks** :
- T-6.3.1 Audit "no env flag bypass" sentinel (assert no `if (env(...))` in PricingService)
  - anchor: `PricingService.php` (FROZEN read-only)
  - test: `tests/Feature/Sentinels/PricingSsotFlagProductionStableSentinelTest.php`
- T-6.3.2 Audit POS pricing SSOT proof test (full E2E POST item → backend computes → snapshot matches)
  - anchor: `tests/Feature/PosPricingSsotProofTest.php`
  - test: existing
- T-6.3.3 Audit Kiosk pricing SSOT (same surface, same proof)
  - anchor: `KioskFiscalAtCreationGuardTest.php` family (verify)
  - test: existing

**Acceptance (AUDIT-ONLY)** : findings.json + sentinel suite green proof.

---

## §7 — Système Mobile standalone (AUDIT-ONLY — screens-main.jsx dirty)

### Contract
Mobile app standalone (Le Cayenne). Pas wireup API V1. Menu data mirror de `mobile/data/menu.js` (alignment cycle 2026-05-17). Loyalty page + wizard 4-template + scan QR + Apple/Google Wallet.

### Frozen zones (cette mission)
- `mobile/screens-main.jsx` *(dirty 5 lines WIP — ne pas écraser)*

### Anchors verified
```
mobile/screens-main.jsx                ← dirty
mobile/screens-item-steps.jsx
mobile/screens-modals.jsx
mobile/screens-onboarding.jsx
mobile/data/menu.js                    ← real SSOT (post mobile-realignment 2026-05-17)
mobile/components/
mobile/api/
mobile/icons.jsx
mobile/styles.css
mobile/redesigns-styles.css
mobile/CONNECTION_PLAN.md
mobile/WALLET_PLAN.md
tests/mobile-e2e/                       ← 22 spec files (loyalty-* + adv)
tests/mobile-e2e/playwright.config.js   ← dirty
```

### Décomposition en 3 sub-systèmes

#### Sub 7.1 — Menu data alignment + wizard parity
**Tasks** :
- T-7.1.1 Audit `mobile/data/menu.js` alignment with central SSOT pattern (no fictional products, real Le Cayenne menu)
  - anchor: `mobile/data/menu.js`
  - test: `tests/mobile-e2e/red-d-mobile-fictional-purge-2026-05-18.spec.js` (existing reference)
- T-7.1.2 Audit wizard 4-template parity (sandwich / taco / burger / menu_formule) with kiosk wizard contract
  - anchor: `mobile/screens-item-steps.jsx`
  - test: `(test TO BE CREATED at tests/mobile-e2e/wizard-templates-parity.spec.js)` if gap found
- T-7.1.3 Audit allergens display + fictional purge (cf. project_massive_logic_image_cycle 2026-05-17 fix)
  - anchor: existing fictional-purge spec
  - test: existing

#### Sub 7.2 — Loyalty + Wallet flows
**Tasks** :
- T-7.2.1 Audit loyalty 15 E2E specs (earn / redeem / QR / Wallet) — all GREEN baseline
  - anchor: `tests/mobile-e2e/loyalty-{01..15}-*.spec.js`
  - test: existing 15 specs
- T-7.2.2 Audit adversarial flows A1-A5 (clipboard replay, screenshot detection, localStorage tamper, double-tap redeem, browser back mid-wizard)
  - anchor: `tests/mobile-e2e/loyalty-adv-A{1..5}-*.spec.js`
  - test: existing
- T-7.2.3 Audit Apple Wallet + Google Wallet integration (mock if production unavailable)
  - anchor: `tests/mobile-e2e/loyalty-07-apple-wallet.spec.js` + `loyalty-08-google-wallet.spec.js`
  - test: existing

#### Sub 7.3 — Mobile a11y + visual + design parity
**Tasks** :
- T-7.3.1 Audit contrast (cf. mobile/design-perfect round-3 ≥ 4.5:1 closed)
  - anchor: `tests/mobile-e2e/inspect-contrast.spec.js`
  - test: existing
- T-7.3.2 Audit RGPD consent banner + modals dialog ARIA (cf. round-1 + round-3 heal)
  - anchor: mobile components
  - test: `(test TO BE CREATED at tests/mobile-e2e/rgpd-modal-dialog.spec.js)` if gap
- T-7.3.3 Audit visual snapshot drift (`tests/e2e/__screenshots__/test-e2e-mobile-realignment-2026-05-16/*.png` dirty — investigate)
  - anchor: 2 screenshots dirty (A01-home, Z00-home-overview)
  - test: visual diff comparison

**Acceptance (AUDIT-ONLY)** : findings.json + dirty-screenshot investigation report. Heal deferred until owner clarifies origin.

---

## §8 — Système Web standalone (HEAL ALLOWED — different filesystem)

### Contract
Web site standalone Le Cayenne. Pas wireup API V1. Tree at `/Users/1millnonstop/Downloads/web/`. Funnel guest order + account management + loyalty mirror + legal pages.

### Frozen zones (cette mission)
- aucun frozen — full heal autorisé sur le tree disjoint

### Anchors verified
```
/Users/1millnonstop/Downloads/web/
├── screens.jsx                  ← 43 KB main screens
├── screens-v3.jsx
├── funnel.jsx                   ← guest order funnel
├── flows.jsx
├── components.jsx
├── account-v2.jsx               ← user account
├── loyalty-v2.jsx               ← loyalty mirror
├── orders.jsx                   ← order history
├── data/menu.js                 ← SSOT data (post web-cycle 2026-05-17)
├── legal/                       ← legal pages
├── styles-mobile.css
├── styles-v2.css
├── styles-v3.css
├── styles-v4.css
├── README.md
└── index.html
tests/e2e/__screenshots__/test-e2e-website-realignment-2026-05-16/   ← dirty artifacts (7 PNGs)
```

### Décomposition en 3 sub-systèmes

#### Sub 8.1 — Web funnel + guest order flow
**Tasks** :
- T-8.1.1 Heal raw labels + i18n hunt (zero `web.X`, full FR resolved)
  - anchor: `funnel.jsx` + `flows.jsx`
  - test: `(test TO BE CREATED at tests/e2e/web-raw-label-sentinel.spec.js)` + visual capture × 4 viewports
- T-8.1.2 Heal funnel state-machine (item select → wizard → cart → checkout → confirmation, no broken back-button)
  - anchor: `funnel.jsx`
  - test: `(test TO BE CREATED at tests/e2e/web-funnel-statemachine.spec.js)`
- T-8.1.3 Heal cart persistence (localStorage tamper-safe, expire after N hours)
  - anchor: `funnel.jsx` cart logic
  - test: `(test TO BE CREATED at tests/e2e/web-cart-persistence.spec.js)`

**Acceptance (HEAL)** : 3 sentinel specs PASS + visual capture × 4 viewports + dual-agent QA/RED Visual cross-check.

#### Sub 8.2 — Web account + loyalty + orders history
**Tasks** :
- T-8.2.1 Heal account-v2 (login + signup + edit profile + delete account — mock-backed, no real V1 wireup)
  - anchor: `account-v2.jsx`
  - test: `(test TO BE CREATED at tests/e2e/web-account-flows.spec.js)`
- T-8.2.2 Heal loyalty-v2 page (earn/redeem mock, mirror mobile UX)
  - anchor: `loyalty-v2.jsx`
  - test: `(test TO BE CREATED at tests/e2e/web-loyalty-mirror.spec.js)`
- T-8.2.3 Heal orders history (paginated, filterable, status display)
  - anchor: `orders.jsx`
  - test: `(test TO BE CREATED at tests/e2e/web-orders-history.spec.js)`

**Acceptance (HEAL)** : 3 sentinel specs PASS + visual capture + commit.

#### Sub 8.3 — Web legal + i18n + visual integrity
**Tasks** :
- T-8.3.1 Heal legal pages (CGU, CGV, mentions légales, politique confidentialité — French market mandatory)
  - anchor: `/Users/1millnonstop/Downloads/web/legal/`
  - test: `(test TO BE CREATED at tests/e2e/web-legal-pages.spec.js)`
- T-8.3.2 Heal cross-viewport visual integrity (mobile 360px + tablet 768px + desktop 1280px + wide 1920px)
  - anchor: `screens.jsx` + `screens-v3.jsx`
  - test: visual capture × 4 viewports + axe-core run
- T-8.3.3 Heal dirty-screenshot reconciliation (7 PNGs dirty in `test-e2e-website-realignment-2026-05-16/`)
  - anchor: dirty screenshots
  - test: re-capture + diff or accept

**Acceptance (HEAL)** : visual capture × 4 viewports clean + axe pass + 3 sentinels + dual-agent visual check + commit.

---

## §9 — Système Cross-surface i18n + A11y (AUDIT-ONLY — touches dirty surfaces)

### Contract
Cross-system raw-label hunt + i18n key drift + a11y WCAG AA sweep. Read-only (would touch dirty surfaces — OSS, KDS, Kiosk-shell, Mobile).

### Anchors verified
```
resources/lang/fr/
resources/lang/en/
resources/lang/ar/
public/js/admin-*.js              ← multiple compiled JS surfaces
tests/Feature/Sentinels/*I18n*    ← i18n drift sentinels (verify)
```

### Décomposition en 2 sub-systèmes

#### Sub 9.1 — i18n key drift hunt
**Tasks** :
- T-9.1.1 Audit raw `i18n.X` / `kiosk.X` / `kds.X` / `oss.X` / `pos.X` leaks visible across all admin surfaces
  - anchor: `public/js/admin-*.js` + Vue templates
  - test: `(test TO BE CREATED at tests/Feature/Sentinels/CrossSurfaceI18nLeakSentinelTest.php)`
- T-9.1.2 Audit fr/en/ar key parity (no fr-only or en-only orphans)
  - anchor: `resources/lang/{fr,en,ar}/*.php`
  - test: `(test TO BE CREATED at tests/Feature/Sentinels/I18nParityFrEnArSentinelTest.php)`

#### Sub 9.2 — A11y WCAG AA cross-surface sweep
**Tasks** :
- T-9.2.1 Audit axe-core results aggregation (POS + Kiosk + KDS + OSS + Admin Stock + Livreur + Web + Mobile)
  - anchor: existing axe results in `reports/test-e2e/goal-pageby-2026-05-18/round-1/*/axe-results.json`
  - test: aggregation script
- T-9.2.2 Audit keyboard nav cross-surface (Tab order + focus management + Esc dismiss)
  - anchor: cross-surface
  - test: `(test TO BE CREATED at tests/e2e/cross-surface-keyboard-nav.spec.js)`

**Acceptance (AUDIT-ONLY)** : findings.json with cross-surface P0/P1/P2. Heal recommendations queued V1.0.X.

---

## §A — Agent Army Map + Fan-Out Matrix

### Roles utilisés (9 + 2 visual)

| Rôle | Subagent type | Tools | Prompt source |
|---|---|---|---|
| Architect | `general-purpose` | Read | `~/.claude/skills/superpower-gstack/agents/architect-prompt.md` |
| Security | `general-purpose` | Read | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (SECURITY mode) |
| UX / A11y | `general-purpose` | Read + axe-core | inline (WCAG 2.1 + ARIA + flat design Le Cayenne) |
| DBA | `general-purpose` | Read | inline (schema + FK + N+1 + BranchScope on 13 models) |
| SRE / Sync | `general-purpose` | Read | inline (Outbox + Pusher + polling + queue) |
| Implementer | `general-purpose` | Edit + Write + Bash | `~/.claude/skills/superpower-gstack/agents/implementer-prompt.md` (TDD-first) |
| RED-team | `general-purpose` | Read | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (RED mode) |
| QA Visual | `general-purpose` | Read + Playwright | inline (run spec + capture + analyze screenshot) |
| RED Visual | `general-purpose` | Read | inline (re-analyze QA screenshots independently) |

### Fan-Out matrix par type de tâche (qui fire)

| Task type | Architect | Security | UX/A11y | DBA | SRE | Implementer | RED | QA Vis | RED Vis |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Stock backend integrity | x | x | . | x | . | x (heal) | x | . | . |
| Stock dashboard UI | x | x | x | . | . | x (heal) | x | x | x |
| Livreur backend service | x | x | . | x | . | x (heal) | x | . | . |
| Livreur admin UI | x | x | x | . | . | x (heal) | x | x | x |
| Web standalone (any) | x | x | x | . | . | x (heal) | x | x | x |
| KDS deeper (audit-only) | x | x | . | x | x | . | x | . | . |
| OSS fullsys (audit-only) | x | x | x | . | x | . | x | . | . |
| Pricing SSOT (audit-only FROZEN) | x | x | . | x | . | . | x | . | . |
| Mobile (audit-only) | x | . | x | . | . | . | x | . | . |
| Cross-surface i18n+A11y | . | . | x | . | . | . | x | . | . |

Legend : `x` = fire mandatory ; `.` = skip.

### Dispatch discipline

- **Phase R (Wave 1)** : 5 specialists per system, all in SINGLE message with multiple Agent calls. Wall-clock ~5-8 min total. Output persisted to disk per Axis 4 schema.
- **Phase H implementer** : MAX 2 in parallel (Stock backend + Livreur backend disjoint), 3 if Web standalone adds a 3rd. NEVER more — write conflict risk.
- **QA Visual + RED Visual** : parallel OK (read-only on screenshots).
- **RED-team dispute** : ALWAYS after implementer commit, BEFORE declaring task DONE.

### Reporting Contract (subagent → disk)

Every subagent persists findings to disk (main thread reads from disk, NOT context returns — survives usage-limit interrupts) :

Path : `reports/audit/goal-complement-2026-05-18/round-N/<SYSTEM>/<ROLE>.{md,json}`

Per finding :
```
[P0|P1|P2|P3] <file>:<line> — <one-line title>
  reproduction: <exact command or click path>
  evidence: <screenshot path | console error | test name>
  recommendation: <scope-minimal fix proposal>
```

Hard cap per agent : 1500 words.

### §A.2 — Per-Zone Autonomous Sub-Agent Contract (template prompt)

Each of the 8 master sub-agents dispatched in Phase 1 receives this contract template (zone variables filled in per zone) :

```
You are the autonomous master sub-agent for ZONE Z-<N>: <ZONE-NAME>.

MANDATE: deliver this zone to PRODUCTION-PERFECT autonomously, parallel with 7 other
master sub-agents working other zones. You do NOT coordinate with them at runtime —
you operate on disjoint scope (verified by orchestrator). Coordinate via disk reports.

SKILLS YOU MUST USE:
- GStack pipeline (Think → Plan → Build → Review → Test → Ship → Reflect)
- Superpowers : brainstorming, TDD, subagent-driven-development, verification-before-completion
- Adversarial RED-team pattern (memory/feedback_adversarial_audit_pattern.md)
- test-e2e (real Playwright + axe + dual-agent QA/RED Visual) ending in correction loop

ZONE SCOPE (read this from the GOAL):
- §<N> in plans/GOAL_PRODUCTION_READINESS_COMPLEMENT_2026-05-18.md (sub-systems + tasks
  + anchors + acceptance criteria)
- HEAL MODE: <HEAL ALLOWED | AUDIT-ONLY>
- FROZEN files (cette mission): <list>
- DIRTY files (session-A WIP): <list, ne pas écraser>

INTERNAL TRACK (11 steps):
1. RECONNAISSANCE: Read the GOAL § for your zone. Verify every anchor file:line via Read tool
   (anti-fiction: reject if file missing, mark for re-discovery).
2. AUDIT FAN-OUT: spawn 3-5 specialists in parallel (Architect / Security / UX/A11y /
   DBA / SRE) per fan-out matrix §A.1. Single message, multi-Agent. Each persists findings
   to reports/audit/goal-complement-2026-05-18/round-1/Z-<N>/<role>.json with verified
   file:line citations.
3. SYNTHESIZE: read the 3-5 reports from disk, dedupe, score P0/P1/P2.
4. IMPLEMENT (heal-allowed only): spawn 1 implementer agent with TDD-first prompt from
   ~/.claude/skills/superpower-gstack/agents/implementer-prompt.md. Scope-minimal patches
   per task in the GOAL §. NEVER touch frozen or dirty files.
4b. AUDIT-ONLY: skip step 4; write findings.json + heal recommendation to
    reports/audit/.../deferred-heal/Z-<N>/findings.json. Mark each P0/P1 with V1.0.X
    backlog ticket reference.
5. RED-TEAM DISPUTE: spawn 1 RED agent from
   ~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md to attack the diff.
   If RED surfaces new P0 → loop step 4. Max 3 cycles. 3rd fail → write STUCK report
   and escalate to orchestrator with reason.
6. TECHNICAL TEST: run `php artisan test --filter "<zone-test-pattern>"` and
   `npx vitest run <zone-pattern>` and the zone's sentinels. 100% PASS exact count
   or heal goto step 4.
7. E2E REAL WEBSITE: Playwright headed run on a real local server (port 8000) of the
   spec for this zone. Capture screenshots + DOM + console + network artifacts to
   reports/test-e2e/goal-complement-2026-05-18/Z-<N>/round-<R>/.
   For AUDIT-ONLY zones with dirty surfaces: visual recon via existing artifacts only
   (no new write capture; no JS rebuild).
8. AXE + VISUAL DUAL-AGENT: spawn QA Visual + RED Visual in parallel. Each Reads the
   screenshots independently and writes its analysis to disk. Compare both reports —
   if they diverge, investigate.
9. CORRECTION LOOP: if any of [P0/P1/serious-axe/raw-label/console-error/layout-break]
   → goto step 4 with heal scope = just what's failing. Max 3 correction cycles.
   3rd cycle fail → STUCK report + escalate.
10. VALIDATION GATE: 2 consecutive cycles with P0+P1=0 AND identical findings set
    on this zone → DONE.
11. PERSIST + RETURN: write reports/audit/.../Z-<N>/STATUS.md with:
    - final verdict (VALIDATED / STUCK / DEFERRED)
    - commits (SHAs)
    - test counts (PHPUnit/Vitest/Playwright)
    - visual artifact paths
    - deferred-heal backlog if any
    - one-line summary for orchestrator to read

COORDINATION RULES (do NOT violate):
- Do NOT touch any file outside your zone's scope.
- Do NOT touch any file in the dirty list (session-A WIP).
- Do NOT touch any file in the frozen list (CLAUDE.md §7).
- Do NOT push to remote; commits stay local on v1-0-1-hardening-2026-05-17.
- Do NOT run `composer dump-autoload` against the live server (memory feedback).
- Do NOT commit .env or secrets.
- If you must run a long Bash command (>60s), use run_in_background and check Monitor.
- Update PROJECT_BRAIN.md ONLY if your zone closes a new VERIFICATION CHECKLIST domain
  (§7); otherwise the orchestrator handles BRAIN at Phase 2.

EVIDENCE QUALITY BAR:
- Every finding has a Read-verified file:line citation (no hallucinated paths).
- Every heal has a commit SHA + green sentinel test path.
- Every visual claim has a screenshot Read+analyzed (paste the visual analysis in
  your STATUS.md).
- Never claim "DONE" without 2 consecutive green cycles.

INTERRUPT HANDLING:
- If you hit any usage limit / 503 / unrecoverable error:
  1. Commit WIP as `wip(zone-<N>): partial through step <X>` (lossless save).
  2. Write reports/audit/.../INTERRUPT_Z-<N>_<timestamp>.md with: last step, last
     commit SHA, what's next, recovery instructions.
  3. Other zones continue uninterrupted. Orchestrator re-dispatches only YOU on resume.

DELIVERABLE:
- reports/audit/goal-complement-2026-05-18/Z-<N>/STATUS.md
- final verdict + evidence + commit SHAs + test counts + screenshots.

Now START at step 1.
```

This contract is what makes the 8 zones truly autonomous and max-parallel.

---

## §X — Orchestration : 8 ZONE TRACKS EN PARALLÈLE MAXIMUM (pas wave-by-wave)

### Architecture max-parallel

```
                  ┌─────────────────────────┐
                  │  Phase 0 — Pre-flight   │  ~3 min sequential
                  │  (1 task, capture +     │
                  │   backup branch + NF525 │
                  │   baseline)             │
                  └────────────┬────────────┘
                               │
              ┌────────────────┴────────────────┐
              │   SINGLE MESSAGE DISPATCH       │
              │   8 master sub-agents PARALLEL  │
              ▼                                  ▼
  ┌─────────────────────────────────────────────────────────┐
  │  Z1   Z2   Z3   Z4   Z5   Z6   Z7   Z8                   │
  │  ▼    ▼    ▼    ▼    ▼    ▼    ▼    ▼                    │
  │  KDS  OSS  Sto  Liv  Pri  Mob  Web  Crs                  │
  │  ┃    ┃    ┃    ┃    ┃    ┃    ┃    ┃                     │
  │  ┃    ┃    ┃    ┃    ┃    ┃    ┃    ┃   <-- each track: │
  │  audit (5 specialists ∥) → synth → heal-or-defer →       │
  │  RED dispute → PHPUnit/Vitest sentinels → Playwright     │
  │  headed E2E + axe → QA Visual + RED Visual (∥) →         │
  │  CORRECTION LOOP (heal → retest → re-capture)            │
  │  until "validated" (P0+P1=0 stable 2 cycles)             │
  └──────────────────────────┬───────────────────────────────┘
                             │
                  ┌──────────┴──────────┐
                  │  Phase 2 — Global   │   ~10 min sequential
                  │  convergence        │
                  │  (rebase aware,     │
                  │   NF525 attest,     │
                  │   BRAIN+Graphiti)   │
                  └─────────────────────┘
```

### Phase 0 — Pre-flight (sequential, 1 task ~3 min)

- Verify `pwd` + `git worktree list` + scope-guard vs session-A
- Capture NF525 baseline (`count(audit_logs)` + `MAX(current_hash)` + verify-chain)
- Capture PHPUnit + Vitest smoke counts
- Create backup branch `backup/pre-goal-complement-2026-05-18`
- Write `reports/audit/goal-complement-2026-05-18/00_PREFLIGHT.md`

**Done when** 6/6 checkpoint items confirmed.

### Phase 1 — 8 ZONE TRACKS IN PARALLEL (single message, 8 master sub-agents)

**Dispatch pattern** : single message with **8 Agent calls** (one per zone). Each master sub-agent is autonomous and runs its own internal pipeline. Wall-clock = max(8 zones), not sum.

Each master sub-agent receives :
- Skills mandate : GStack + Superpowers + RED adversarial pattern + test-e2e
- Zone scope (full §2..§9 system block from this GOAL)
- Heal mode flag (HEAL ALLOWED / AUDIT-ONLY)
- Output paths (findings + capture artifacts)
- Convergence criteria (when to call itself DONE)

Per-zone internal track (executed by the master sub-agent) :

```
1. RECONNAISSANCE     → Read GOAL § for this zone, verify anchors with Read tool
2. AUDIT FAN-OUT      → spawn 5 specialists in parallel (Architect+Security+UX+DBA+SRE
                         or subset per Fan-Out Matrix §A.1)
3. SYNTHESIZE         → consolidate P0/P1/P2 with Read-cited file:line
4. IMPLEMENT          → if HEAL ALLOWED, spawn implementer (TDD-first via Superpowers)
                         if AUDIT-ONLY, persist findings + heal recommendation
5. RED DISPUTE        → adversarial agent re-attacks the diff (hostile framing)
6. TECHNICAL TEST     → PHPUnit + Vitest + sentinels for the zone, 100% PASS exact
7. E2E REAL WEBSITE   → Playwright headed on production-like local
                         (Stock dashboard / Livreur driver+admin / Web × 4 viewports
                          OR for audit-only zones: visual recon via existing artifacts)
8. AXE + VISUAL       → QA Visual + RED Visual in parallel, cross-check screenshots
9. CORRECTION LOOP    → if any P0/P1/serious-a11y/raw-label/console-error/layout-break:
                         goto step 4 (heal), MAX 3 cycles
                         3rd cycle fail → escalate owner with STUCK report
10. VALIDATION GATE   → 2 consecutive cycles P0+P1=0 stable on this zone → DONE
11. PERSIST + RETURN  → write zone-status to reports/audit/.../<ZONE>/STATUS.md
                         master orchestrator picks up from disk
```

**Parallelism inventory** :

| Layer | Parallel count |
|---|---|
| Master zone tracks (Phase 1 outer) | **8** |
| Specialists per zone (step 2 inner) | 3-5 per zone × 8 zones = **24-40** |
| QA Visual + RED Visual per surface | 2 per surface × ~8 surfaces = **16** |
| Implementers (heal-allowed zones only) | 3 (Stock + Livreur + Web, disjoint files) |
| **Peak concurrent agents** | **~40-50** at audit-fan-out moment |
| **Sustained concurrent agents** | **~16-20** during heal+E2E |

### Phase 2 — Global convergence + BRAIN (sequential, ~10 min)

Once **all 8 master sub-agents** have written their `STATUS.md = VALIDATED` :

1. **Rebase-aware sync** : if session-A advanced HEAD, rebase or merge ; resolve conflicts (none expected since disjoint scopes)
2. **Global NF525 attest** : run `php artisan fiscal:verify-chain` → assert APPENDED-ONLY pattern (count >= baseline, hash extends OK, no truncate)
3. **Broad smoke** : PHPUnit broad + Vitest broad (counts >= baseline)
4. **Frozen-zone diff GOAL range** : `git diff --stat <preflight-sha>..HEAD -- <13-frozen-list>` must show 0 lines
5. **Write `CONVERGENCE_COMPLEMENT.md`** : summary of 8 zones, commits, deferred-heal backlog
6. **BRAIN.md update** : §2 HEAD + branch + timestamp, §3 LAST DONE, §4 V1.0.X backlog, §7 verification checklist
7. **Graphiti push** : `mcp__graphiti__add_memory` group=`foodking` episode summarizing the cycle
8. **Tag** : `v1.0.X-goal-complement-2026-05-18` after owner sign-off

### Interrupt-resume protocol (per zone autonomy)

If a zone master sub-agent is interrupted (usage limit, session timeout) :
1. Sub-agent commits any WIP under `wip(zone-<N>): partial through step <X>` prefix
2. Sub-agent writes its own resume manifest to `reports/audit/.../INTERRUPT_<zone>_<timestamp>.md`
3. Other zones continue uninterrupted (independent agents)
4. On user resume : re-dispatch ONLY the interrupted zone's master sub-agent with its manifest path as input

This is the whole point of per-zone autonomy : **one zone hitting limits doesn't block the other 7**.

---

## §G — Owner Gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | NF525 chain bit-identical → APPENDED-ONLY pattern shift acceptance | Physical owner | One-line attest in BRAIN §2 + Graphiti episode | BRAIN.md §2 line "attest pattern: APPENDED-ONLY" | PENDING (auto-cleared if owner doesn't object pre-Phase 2) |
| G2 | Heal scope restriction to disjoint trees acceptance | Physical owner | Owner confirms heal restricted to Stock+Livreur backend + Web standalone | This GOAL §0.2 acceptance reply | PENDING (carte-blanche default acceptance) |
| G3 | LOCK Pricing SSOT if zone Z-5 surfaces P0 in frozen file | Physical owner | LOCK plan countersigned + V1.0.X scheduling | New `plans/LOCK_PRICING_SSOT_<date>.md` | NOT TRIGGERED YET (only if P0 found) |
| G4 | Web standalone separate git repo decision (/Downloads/web has .git?) | Physical owner | Confirm commit target (main tree vs separate repo) | Z-7 reconnaissance step 1 output | PENDING (Phase 0 must verify) |
| G5 | Tag `v1.0.X-goal-complement-2026-05-18` creation | Physical owner | Owner sign-off after CONVERGENCE_COMPLEMENT.md review | Final commit message + Phase 2 announcement | PENDING |
| G6 | Final merge to `main` after session-A closes its own CONVERGENCE | Physical owner | Wait for session-A merge first, then this branch | git log main | DEFERRED post-session-A |

### Owner-Gate-Waiting Protocol

While a gate is PENDING :
1. Orchestrator does NOT execute the wave whose checkpoint depends on the gate
2. Orchestrator DOES execute disjoint waves in parallel
3. Lists in BRAIN.md §2 which waves are blocked vs running

Carte-blanche default : G1, G2 auto-cleared after owner has 1h to object post-GOAL writing. G3 only triggers conditional on Wave 1 findings. G4 must resolve at Wave 0 (cheap discovery).

---

## §R — References

### Skills mandates
- `~/.claude/skills/ultra-audit-profond/SKILL.md` — per-task 14-step pipeline
- `~/.claude/skills/ultra-architect-planify/SKILL.md` — this document's template
- `~/.claude/skills/superpower-gstack/SKILL.md` — 7-step pipeline composition partner
- `~/.claude/skills/test-e2e/SKILL.md` — adversarial dual-team page audit
- `~/.claude/skills/lock-plan/SKILL.md` — frozen-zone overrides (if G3 triggers)

### Upstream Superpowers skills (composed by superpower-gstack)
- `~/.claude/plugins/cache/claude-plugins-official/superpowers/5.1.0/skills/writing-plans/SKILL.md`
- `~/.claude/plugins/cache/claude-plugins-official/superpowers/5.1.0/skills/dispatching-parallel-agents/SKILL.md`
- `~/.claude/plugins/cache/claude-plugins-official/superpowers/5.1.0/skills/test-driven-development/SKILL.md`
- `~/.claude/plugins/cache/claude-plugins-official/superpowers/5.1.0/skills/verification-before-completion/SKILL.md`

### Project documents
- `CLAUDE.md` §§ 4-13 (FoodKing operating memory)
- `PROJECT_BRAIN.md` (always read at session start)
- `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` (35 KB — reference shape, session-A's original GOAL)
- `plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md` (session-A focus on Zones 1-7)
- `~/.claude/projects/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/memory/reference_frozen_zones.md` (13-file canonical list)

### Prior cycle reports
- `reports/test-e2e/goal-pageby-2026-05-18/round-1/KDS/FINAL_SUMMARY.md` (KDS R1 = AMBER baseline)
- `reports/test-e2e/goal-pageby-2026-05-18/round-1/STOCK/` (STOCK round-1 artifacts)
- `reports/test-e2e/goal-pageby-2026-05-18/round-1/SYNC/flow-{1..8}-*-evidence.md` (sync flows)

### Memory pointers
- `memory/feedback_adversarial_audit_pattern.md` (RED methodology)
- `memory/feedback_gstack_pipeline_methodology.md` (7-step canonical)
- `memory/feedback_massive_team_orchestration_e2e_per_system.md` (triple-team mandate)
- `memory/project_v1_0_1_hardening_2026-05-17.md` (sprint H1-H6 baseline)
- `memory/project_max_audit_test_e2e_convergence_2026-05-18.md` (test-e2e convergence pattern)
- `memory/feedback_no_cloud_until_owner_initiates.md` (no Phase D until owner says go)

---

## §F — Final Rule (production-perfect, not "almost there")

Une tâche / wave / GOAL est DONE quand :

**Tâche HEAL** :
- 5 specialists ont audité (P0 list verified)
- RED-team a disputé sans nouveau P0
- PHPUnit + Vitest sentinels 100% PASS exact count
- E2E Playwright headed PASS
- Screenshots analysés par QA + RED Visual (no raw label, no layout break, no console error, no a11y critical)
- Frozen-zone diff GOAL range = 0 lignes
- Commit avec evidence

**Tâche AUDIT-ONLY** :
- 5 specialists ont audité avec Read-cited file:line
- Findings.json persisté disque
- Scope-minimal heal recommendation documentée
- Backlog V1.0.X ticket OR escalation reason

**GOAL complement** = DONE quand :
- 2 cycles consécutifs P0+P1=0 stable sur scope HEAL
- AUDIT-ONLY zones ont leur backlog clos avec recommendation
- Rebase-clean avec session-A HEAD
- NF525 APPENDED-ONLY attest
- BRAIN + Graphiti + tag

**Pas de "ça marche presque". Pas de "on verra plus tard". Pas de heal silencieux sur zone dirty/frozen.**

**Si la convergence ne tient pas après 3 healing loops sur un cluster donné** :
1. STOP la wave
2. Spawn `Agent(subagent_type=Plan)` brief : "pourquoi 3-cycle heal a fail ? proposer architecture pivot ou escalation"
3. Write analysis to `reports/audit/goal-complement-2026-05-18/STUCK_<wave>_<timestamp>.md`
4. Escalate owner : A) accept-with-doc / B) architecture pivot / C) defer V1.0.X / D) human gate

**Do NOT auto-pick.** Wait for owner choice.

---

## Launch protocol (lance le GOAL complement — MAX PARALLEL DEPLOYMENT)

Quand le user dit "lance le GOAL complement" / "execute" / "go" / "fais tourner les sub-agents" :

**Step 1 — Phase 0 Pre-flight** (~3 min sequential, single task)
- Verify working tree state matches `0ca8ea800` (commit or stash if diverged)
- Create backup branch `backup/pre-goal-complement-2026-05-18`
- Capture NF525 baseline (`count(audit_logs)` + `MAX(current_hash)` + `php artisan fiscal:verify-chain` = CHAIN OK)
- Capture PHPUnit + Vitest smoke counts
- Write `reports/audit/goal-complement-2026-05-18/00_PREFLIGHT.md`

**Step 2 — Phase 1 MAX PARALLEL DISPATCH** (~30-45 min wall-clock, dominated by slowest zone)

SINGLE MESSAGE with **8 Agent tool calls** (one per zone) :

```
Agent(Z-1 KDS deeper           , subagent_type=general-purpose, prompt=§A.2 template filled for Z-1)
Agent(Z-2 OSS fullsys          , subagent_type=general-purpose, prompt=§A.2 template filled for Z-2)
Agent(Z-3 Stock fullsys [HEAL] , subagent_type=general-purpose, prompt=§A.2 template filled for Z-3)
Agent(Z-4 Livreur fullsys [HEAL], subagent_type=general-purpose, prompt=§A.2 template filled for Z-4)
Agent(Z-5 Pricing SSOT [FROZEN], subagent_type=general-purpose, prompt=§A.2 template filled for Z-5)
Agent(Z-6 Mobile standalone    , subagent_type=general-purpose, prompt=§A.2 template filled for Z-6)
Agent(Z-7 Web standalone [HEAL], subagent_type=general-purpose, prompt=§A.2 template filled for Z-7)
Agent(Z-8 Cross-surface i18n+A11y, subagent_type=general-purpose, prompt=§A.2 template filled for Z-8)
```

Each master sub-agent autonomously runs its internal 11-step track (§A.2) :
audit fan-out (3-5 specialists ∥) → synth → heal-or-defer → RED dispute → tech tests → real E2E Playwright headed → axe + dual-agent visual → correction loop (max 3) → validation gate (2 cycles P0+P1=0 stable) → persist STATUS.md.

Peak concurrent agents = ~40-50 (8 masters × 5 specialists at audit peak).
Sustained concurrent agents = ~16-20 (8 masters + their inner workers).

**Step 3 — Phase 2 Global convergence** (~10 min sequential, after all 8 STATUS.md = VALIDATED)
- Rebase-aware sync with session-A HEAD (no conflicts expected, scopes disjoint)
- NF525 APPENDED-ONLY attestation
- Broad PHPUnit + Vitest smoke (counts >= baseline)
- Frozen-zone diff GOAL range = 0 lines
- Write `CONVERGENCE_COMPLEMENT.md`
- Update `PROJECT_BRAIN.md` §2/§3/§4/§7
- Push Graphiti `foodking` episode
- Tag `v1.0.X-goal-complement-2026-05-18` (owner sign-off mandatory CLAUDE.md §10)

**Interrupt-resume** : per-zone autonomous. If zone Z-N hits usage limit / session timeout :
- Z-N commits WIP + writes `INTERRUPT_Z-N_<timestamp>.md` to disk
- Other zones continue uninterrupted (true parallelism advantage)
- Orchestrator re-dispatches ONLY Z-N on resume

**Estimated total wall-clock** : 45-60 min (vs 4-6 days sequential).
**Estimated agent invocations** : 50-80 (8 masters + their inner fan-outs over multiple cycles).
**Budget** : no limit (owner carte-blanche).

---

**FIN GOAL COMPLEMENT — prêt à lancer 8 sub-agents en parallèle sur "go".**
