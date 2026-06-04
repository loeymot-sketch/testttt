# GOAL — V1 Production-Perfect Phase 2 LOCAL — Le Cayenne
**Date** : 2026-05-18
**Branche cible** : `heal/cms-pr1-quickwins-2026-05-18` (HEAD `626d5a389` post Wave A+B+C heals)
**Méthodologie** : `ultra-architect-planify` (ce doc) → `ultra-audit-profond` (per-task) → `superpower-gstack` (LOOP) → `test-e2e` (page audits)
**Skip clarifying questions** : ✅ owner carte-blanche
**Target wall-clock** : 3-5 jours-agent (mostly V1.0.2 backlog + re-attestation Phase 1)
**Owner mandate** : NO cloud talk (production initiative owner). LOCAL-only mandate immutable.

---

## §0 — Preamble (verrouillage scope & état initial)

### 0.1 Working-tree decision
État working-tree au démarrage : clean (post commit `626d5a389`). Pas de stash nécessaire. **DÉCISION** : démarrer Wave 1 directement, aucune sub-task de commit pré-flight.

### 0.2 État Phase 1 (Critical Focus 2026-05-18 — déjà GO)
- **7/7 zones GO V1 LOCAL** post Critical Focus (Z1 NF525 / Z2 POS / Z3 KDS+Kiosk / Z4 Auth+TrustHosts / Z5 Pricing SSOT / Z6 Sync Outbox / Z7 Admin Daily)
- **40+ commits** scope-minimal cumulés
- **NF525 chain CHAIN OK** (audit_logs + z_reports) `count=27 last_hash=206f9d…c6200c1`
- **Frozen-zone diff = 0** sur 13 fichiers protégés
- **9 P0 closed Wave A+B+C** post-Critical-Focus (CENTRAL 3 + MGMT 5 + SYNC 3 per `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/FINAL_CONVERGENCE.md`)

### 0.3 Phase 2 scope — ce qui RESTE pour production-perfect LOCAL
- **~28 P0 V1.0.2 backlog** issus de l'audit architectural-backbone CMS (CENTRAL ~12 + MGMT ~9 + SYNC ~10 — 47 cumulatifs minus 19 closed)
- **8 V1.0.2 items** émergés Phase 1 (SYNC-ADV4-N1 Stripe CSRF, Z7-V1.0.2-P2-01 BranchStatusChanged outbox, KDS-ADV3C-05/06/09/10/11/12, FISCAL-ADV3B-04/05/06/07 + ADV3C-04)
- **Post-heal re-audit non fait** : critère #7 GOAL CMS `RED-team P0+P1=0 NEW on 2 consecutive cycles` non vérifié
- **2 owner-decision pending** : POS-ADV3-05/06/07 cash drawer + POS XSS LOCK pos-wizard.js
- **CLAUDE.md additions owner-review pending** : `plans/CLAUDE_MD_PROPOSED_ADDITIONS_2026-05-18.md` 4 sections (Audit Workflow + Data SSOT + Environment Safety + Execution Mode)

### 0.4 Pipeline mandaté par tâche (référence : `~/.claude/skills/ultra-audit-profond/SKILL.md`)
Chaque tâche du GOAL délègue à `ultra-audit-profond` (20-step pipeline 10 GATES). Ne pas re-décrire le pipeline ici. Discipline héritée :
- **Frozen zones** : `CLAUDE.md §7` strict — `memory/reference_frozen_zones.md` canonical 13 fichiers. Touch = STOP + `/lock-plan` skill.
- **NF525 invariants** : `CLAUDE.md §8` strict — chain HMAC, séquence monotone, snapshot frozen, retention 6y.
- **Multi-tenant** : `BranchScope` global sur 17 modèles + 10 exemptions baseline-locked via `BranchScopeCoverageSentinelTest`.
- **Pricing SSOT** : `PricingService::calculateOrder` authoritative, 0 frontend trust.

### 0.5 Convergence criteria Phase 2 (production-perfect LOCAL)
Une tâche est **DONE** quand TOUS sont vrais :
- [x] 5 specialists ont audité (P0 list verified file:line)
- [x] RED-team a disputé (no new P0)
- [x] PHPUnit + Vitest sentinels 100% PASS exact count
- [x] E2E Playwright (zone correspondante) PASS
- [x] Screenshots Read'd + analyzed (no raw labels, no broken layout, no console error)
- [x] Frozen-zone diff = zéro lignes
- [x] BRAIN.md updated + commit avec evidence

Une WAVE est DONE quand checkpoint Axis 3 ultra-architect-planify : 6/6 critères + interrupt-resume hook armé.

Le GOAL est DONE quand 6/6 waves green + final smoke broad PHPUnit/Vitest/Playwright + tag `v1.0.2-production-perfect-local`.

---

## §1 — Map principal : 7 zones + V1.0.2 follow-up

| # | Zone | Surface | Phase 1 Status | Phase 2 scope | Anchor primaire | Tests existants |
|---|---|---|---|---|---|---|
| 1 | **NF525 Fiscal** | legal chain | GO 1 cycle | 5 V1.0.2 P1 backlog | `app/Services/Fiscal/*.php` (5 services FROZEN) | 8 files `tests/Feature/Fiscal/` |
| 2 | **POS Caisse** | staff register | GO V1 LOCAL | re-attestation + owner-decision | `Admin/PosController.php` + 6 `Admin/Pos/*Controller.php` | 15+ files `tests/Feature/Pos/` |
| 3 | **KDS + Kiosk** | kitchen + customer | GO 1 cycle | 6 V1.0.2 P2 backlog | `Services/KdsSyncService.php` + `Services/KitchenDisplaySystemOrderService.php` + 48 Vue files kiosk | `tests/Feature/Kds*Test.php` + sentinels TZ-aware |
| 4 | **Auth + TrustHosts** | security perim | GREEN 2 cycles | re-attestation + V1.0.2 SYNC-ADV3C-04 | `Middleware/TrustHosts.php` + `Middleware/TrustProxies.php` | `TrustHostsTest.php` + `TrustProxiesThrottleIsolationTest.php` |
| 5 | **Pricing SSOT** | money authority | GO 0 code | re-attestation + LOCK plans W2/W5 | `Services/Pricing/PricingService.php` (FROZEN) + 6 collaborators | `PricingIntegrityTest.php` + `PosPricingSsotProofTest.php` + 4 |
| 6 | **Sync Outbox** | cross-surface | GO 1 cycle | 10 SYNC V1.0.2 P0 (CMS audit) | `Console/Commands/Outbox*.php` (4) + listeners | `tests/Feature/Outbox/` |
| 7 | **Admin Daily** | owner ops | GO V1 LOCAL | 9 MGMT V1.0.2 P0 (CMS audit) | 87 `Admin/*Controller.php` | scattered |

**Pas de scope expand** : Phase 2 reste sur le périmètre Phase 1 + V1.0.2 backlog. Pas de nouvelle surface ajoutée.

## §2 — Map standalone (FROZEN, audit re-attestation only)

| # | Système | Path | Status | Phase 2 scope |
|---|---|---|---|---|
| M | **Mobile App** | `mobile/screens-*.jsx`, `mobile/data/menu.js` | V1 GREEN 2026-05-17 | smoke regression seulement |
| W | **Web Site** | `/Users/1millnonstop/Downloads/web/` | V1 GREEN 2026-05-17 + Z7 a11y closed | smoke regression seulement |

Les 2 standalone NE sont PAS un focus Phase 2. Si une régression visuelle est détectée pendant la wave de re-attestation, dispatch ad-hoc heal. Sinon, hands-off.

---

## §3 — Zone 1 : NF525 Fiscal Chain (V1.0.2 follow-up)

### Contract
Chaîne fiscale française NF525 légalement immutable. Toute brèche = inspection fiscale → fermeture admin + responsabilité pénale gérant. Cf. `memory/reference_frozen_zones.md` + `CLAUDE.md §8`.

### Frozen zones (strict-no-touch)
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Fiscal/AuditLogService.php`
- `app/Services/Fiscal/ZReportService.php`
- Tables `audit_logs` + `z_reports` triggers DELETE/UPDATE (MySQL prod)

### Anchors (verified 2026-05-18 via `ls app/Services/Fiscal/`)
- 5 services : `AuditLogService.php` / `FiscalChainValidator.php` / `FiscalSealingService.php` / `FiscalSequenceService.php` / `XReportService.php`
- 8 tests : `AuditLogHashChainTest.php` / `FiscalRateLimitTest.php` / `RefundPostZTest.php` / `XReportTest.php` / `FiscalVerifyChainCommandTest.php` / `FiscalSealingHmacTest.php` / `FiscalArchiveScheduledTest.php` / `OrderFiscalSequenceSchemaTest.php`
- CLI : `php artisan fiscal:verify-chain --branch=N` ou `--all`

### Décomposition en 3 sub-systèmes

#### Sub 1.1 — Alerting monitoring NF525 (V1.0.2 FISCAL-ADV3B-04/05/06)
**Anchors** : `app/Console/Kernel.php` cron `fiscal-chain-monitor-all-branches` (cron 03:30 daily), `app/Console/Commands/FiscalVerifyChainCommand.php` exit codes 0/1/2/3.
**Tasks** :
- T-1.1.1 Promotion onFailure log-file → multi-channel alert (Slack webhook OU mail OU SIEM)
   • anchor: `app/Console/Kernel.php` ligne `->onFailure(fn() => Log::channel('fiscal')->error(...))`
   • test: (test TO BE CREATED at `tests/Feature/Fiscal/FiscalChainMonitorAlertingTest.php`)
   • visual: n/a
- T-1.1.2 Distinct catch-Throwable lanes (Error vs Exception) pour exit code semantic clarity
   • anchor: `FiscalVerifyChainCommand.php` handle() catch(\Throwable $e) — split Error → 4, Exception → 3
   • test: (test TO BE CREATED at `tests/Feature/Fiscal/FiscalVerifyChainExitLanesTest.php`)
- T-1.1.3 `withoutOverlapping()` 60min cap (default 1440 = stall risk multi-branch sweep)
   • anchor: `app/Console/Kernel.php` cron registration ligne `->withoutOverlapping()`
   • test: extend `tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php` (test #9 schedule expiration)
**Acceptance** : 3 tests GREEN + cron `schedule:list` shows 60-min lock expiry + alerting channel verifiable via `Log::channel('fiscal')->shouldReceive` mock.

#### Sub 1.2 — Audit/Z verify decoupling (FISCAL-ADV3C-04)
**Anchors** : `FiscalVerifyChainCommand.php` lignes 80-95 (orchestrates `AuditLogService::verifyChain` + `ZReportService::verifyChain` sequentially).
**Tasks** :
- T-1.2.1 Split into 2 sub-commands `fiscal:verify-audit-chain` + `fiscal:verify-z-chain`
   • anchor: NEW `app/Console/Commands/FiscalVerifyAuditChainCommand.php` + `FiscalVerifyZChainCommand.php`
   • test: (test TO BE CREATED at `tests/Feature/Fiscal/FiscalVerifyChainSplitCommandsTest.php`)
- T-1.2.2 Keep `fiscal:verify-chain` as orchestrator-of-both for backward compat
   • anchor: refactor existing `FiscalVerifyChainCommand.php` to call both sub-commands
   • test: existing `FiscalVerifyChainCommandTest.php` MUST still pass (regression sentinel)
**Acceptance** : 2 NEW commands + orchestrator unchanged at CLI level + 100% test parity.

#### Sub 1.3 — LOCK plan owner-decision (FISCAL-ADV3B-07 anon test fragility)
**Anchors** : `tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php:120+` anon class subclass `AuditLogService` for mocking.
**Tasks** :
- T-1.3.1 Document anon class fragility (frozen AuditLogService ctor add = breaks test)
   • anchor: NEW `plans/LOCK_FISCAL_TEST_ANON_CLASS_2026-05-18.md`
   • test: n/a (doc-only)
   • owner-gate: owner countersign before fix landing
**Acceptance** : LOCK plan written, owner countersign requested, no code change.

---

## §4 — Zone 2 : POS Caisse (re-attestation + owner-decision)

### Contract
Caisse staff fast-food fonctionnelle local : drawer + wizard + payment cash/card/split/ticket-restaurant + refund counter-entry NF525 + Z close + receipt.

### Frozen zones (strict-no-touch)
- `public/js/pos-wizard.js` (5964 LOC)
- `public/css/pos-wizard.css`
- `resources/views/admin-pos-v4.blade.php`

### Anchors (verified 2026-05-18 via `ls app/Http/Controllers/Admin/Pos*`)
- 4 root + 6 sub : `PosController.php` / `PosOrderController.php` / `PosCategoryController.php` + `Pos/{CashDrawer,CashDrawerSession,CustomerNfcLookup,Floorplan,ParkedOrder,PosReceiptPrint}Controller.php`
- 15+ tests `tests/Feature/Pos/`
- E2E spec : `tests/e2e/zone2-pos-chronological.spec.js` (verified existant)

### Décomposition en 2 sub-systèmes

#### Sub 2.1 — Owner-decision clearance POS-ADV3-05/06/07 cash drawer
**Anchors** : `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md` (déjà écrit, 3 options C/C/C proposées accept-as-is).
**Tasks** :
- T-2.1.1 Surface owner-decision doc, attendre countersign
   • anchor: `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md`
   • test: n/a
   • owner-gate: WHO=owner physical, WHAT=signature dans §Décision section, WHERE=commit message
- T-2.1.2 Si owner accepte C/C/C → close docs as "accepted as-is V1 single-cashier"
   • anchor: edit `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md` §Décision finale
   • test: (test TO BE CREATED at `tests/Feature/Pos/CashDrawerSingleCashierAcceptanceTest.php` regression sentinel current behavior)
- T-2.1.3 Si owner refuse → spawn heal plan séparé (V1.0.X follow-up wave)
   • alt-anchor: NEW `plans/HEAL_POS_ADV3_CASH_DRAWER_2026-05-XX.md`
**Acceptance** : owner-decision file signed OU heal plan séparé écrit. T-2.1.2 regression sentinel locks current behavior.

#### Sub 2.2 — POS XSS LOCK plan owner countersign
**Anchors** : `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` (401 LOC, Wave 5G).
**Tasks** :
- T-2.2.1 Owner review LOCK plan POS Wizard XSS escape (frozen pos-wizard.js touch)
   • anchor: `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`
   • test: n/a (doc countersign)
   • owner-gate: WHO=owner physical, WHAT=signature §10 sign-off, WHERE=commit message + BRAIN.md §6 DECISIONS LOG
- T-2.2.2 Si owner countersign → execute heal via `superpower-gstack` + frozen-zone tracking
   • anchor: `public/js/pos-wizard.js` lines specified in LOCK §3
   • test: existing `tests/Feature/Pos/PosWizardXssRegressionTest.php` (verified existant)
**Acceptance** : LOCK §10 signé OU explicitly deferred V1.0.X with rationale.

---

## §5 — Zone 3 : KDS + Kiosk Sync (V1.0.2 P2 backlog)

### Contract
Cuisine + borne client synchronisés <1s WebSocket / <30s polling fallback. TZ-aware boundaries Paris→UTC pour requêtes TIMESTAMP. Cadence polling floor/ceiling clamped.

### Frozen zones (strict-no-touch)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (3104 LOC)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (1576 LOC)
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` (543 LOC)

### Anchors (verified 2026-05-18 via `ls app/Services/Kds*`)
- 2 services : `KdsSyncService.php` (sargable + TZ-aware Wave 2c) + `KitchenDisplaySystemOrderService.php` (TZ sister Wave 2c)
- 48 Vue files dans `resources/js/components/frontend/kiosk/`
- E2E spec : `tests/e2e/zone3-kiosk-to-kds.spec.js` (verified existant)
- Sentinels : `KdsSyncSargableTest.php` + `KdsSyncTzAwareTest.php` + `SisterServicesTzAwareV2Test.php` + `CatalogKdsCadenceFloorTest.php` + `kdsCadenceFloor.spec.js` + `posOssCadenceCap.spec.js`

### Décomposition en 3 sub-systèmes

#### Sub 3.1 — DST + cross-driver test coverage (KDS-ADV3C-05/06)
**Anchors** : `tests/Feature/KDS/KdsSyncTzAwareTest.php` (Paris winter only pinned).
**Tasks** :
- T-3.1.1 DST start + end boundary tests (October 27 / March 30 transitions)
   • anchor: extend `tests/Feature/KDS/KdsSyncTzAwareTest.php` (test #2 DST start, test #3 DST end)
   • test: same file +2 cases
- T-3.1.2 MySQL CI test parity (current SQLite-only masks TZ-coercion bug)
   • anchor: CI config (à identifier dans `.github/workflows/*.yml` ou `phpunit.xml.dist`)
   • test: (test TO BE CREATED at `.github/workflows/tests-mysql.yml`) OR extend phpunit.xml
**Acceptance** : DST tests PASS Paris winter + summer + transition days. MySQL CI run GREEN ou explicit deferral V1.0.X.

#### Sub 3.2 — KDS SLO + runtime cadence refresh (KDS-ADV3C-09/10/11)
**Anchors** : `resources/js/services/KdsSyncService.js` lignes 20-34 + 451-478 (clampBase/clampJitter).
**Tasks** :
- T-3.2.1 Comment-vs-code SLO sync (claim 1/min but actual max=1.5min jitter+base)
   • anchor: `KdsSyncService.js:451-478` documenting actual max
   • test: (test TO BE CREATED at `tests/js/kdsSloDocSync.spec.js`)
- T-3.2.2 Zero-jitter thundering herd guard (jitter 0 = all stations sync poll → spike)
   • anchor: `config/catalog_v15.php` add `min(50, max(0, ...))` floor pour jitter
   • test: extend `tests/Feature/Config/CatalogKdsCadenceFloorTest.php` test #J (jitter min 50ms)
- T-3.2.3 Runtime config refresh (Blade → window.foodkingConfig wire) integration test
   • anchor: NEW Vitest `tests/js/kdsCadenceWireIntegration.spec.js`
   • test: Vitest with mock window.foodkingConfig
**Acceptance** : 3 sentinel tests GREEN + SLO doc updated + jitter floor 50ms enforced.

#### Sub 3.3 — DashboardService whereTime UTC (KDS-ADV3C-12)
**Anchors** : `app/Services/DashboardService.php` lignes encore avec `whereTime` Paris-local sur UTC TIMESTAMP.
**Tasks** :
- T-3.3.1 grep `whereTime` cross app/Services/ — identify all sites
   • anchor: `app/Services/DashboardService.php` (et autres flagged Wave 3c)
   • test: (test TO BE CREATED at `tests/Feature/Services/DashboardWhereTimeTzAwareTest.php`)
- T-3.3.2 Apply TZ-aware pattern Wave 2c (Carbon::create($appTz)->setTimezone('UTC')) à tous les sites
   • anchor: pattern reference `tests/Feature/KDS/KdsSyncTzAwareTest.php`
   • test: same NEW file
**Acceptance** : 0 `whereTime` left non-TZ-aware in app/Services/. DashboardService TZ-aware regression sentinel.

---

## §6 — Zone 4 : Auth + TrustHosts (re-attestation + Outbox track SYNC-ADV3C-04)

### Contract
Multi-tenant BranchScope strict + Sanctum kiosk:order scope + TrustHosts anchored regex defense + TrustProxies per-IP throttle. Wave 3c P0 caught + healed (`b1c50311d` + `9269f9830`).

### Frozen zones
- `app/Models/Scopes/BranchScope.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`

### Anchors (verified 2026-05-18)
- `app/Http/Middleware/TrustHosts.php` + `TrustProxies.php`
- `tests/Feature/Middleware/TrustHostsTest.php` + `TrustProxiesThrottleIsolationTest.php`

### Décomposition en 2 sub-systèmes

#### Sub 4.1 — Outbox track SYNC-ADV3C-04 follow-up
**Anchors** : Wave 3c residual finding « Outbox track decoupling from Audit verify ».
**Tasks** :
- T-4.1.1 Read `reports/audit/critical-focus-2026-05-18/wave-3c/adv-2-sync-heals-r3.md` SYNC-ADV3C-04 exact scope
   • anchor: that report file
   • test: (test TO BE CREATED based on report recommendation)
- T-4.1.2 If actionable scope-minimal → implement heal (≤30 LOC)
   • anchor: TBD per T-4.1.1
   • test: TBD
**Acceptance** : SYNC-ADV3C-04 either closed OR explicit deferral V1.0.X with rationale.

#### Sub 4.2 — Re-attestation Wave 3c convergence (no regression check)
**Anchors** : E2E spec `tests/e2e/zone4-auth-cross-branch.spec.js` (verified existant).
**Tasks** :
- T-4.2.1 Re-run zone4 E2E spec (Playwright headed) → assert 6/6 PASS + 27 attack patterns rejected
   • anchor: `tests/e2e/zone4-auth-cross-branch.spec.js`
   • test: same file
- T-4.2.2 Verify TrustHosts/TrustProxies unchanged-or-cleaner
   • anchor: `git diff HEAD~10..HEAD -- app/Http/Middleware/TrustHosts.php`
   • test: PHPUnit `tests/Feature/Middleware/TrustHostsTest.php` 5/5 + Symfony {%s}i regression
**Acceptance** : 6/6 E2E + 5/5 PHPUnit + 0 regression. Visual A01-admin-landing.png Read'd clean.

---

## §7 — Zone 5 : Pricing SSOT (re-attestation + LOCK plans W2/W5)

### Contract
Backend authoritative `PricingService::calculateOrder` strict. composition_snapshot 5 INSERT-only sites, 0 UPDATE. Cross-surface integrity cart=receipt=KDS=OSS.

### Frozen zones
- `app/Services/Pricing/PricingService.php` (FROZEN)

### Anchors (verified 2026-05-18)
- 7 service classes : `CompositionSnapshotBuilder` + `DiscountCalculator` + `PricingLineResult` + `PricingRequest` + `PricingResult` + `PricingService` + `TaxCalculator`
- Tests : `PosPricingSsotProofTest.php` + `PricingIntegrityTest.php` + `PosKioskPricingParityTest.php` + `tests/Feature/Pricing/` directory + Vitest `tests/js/kioskPricingPreview.spec.js`
- E2E spec : `tests/e2e/zone5-pricing-ssot.spec.js` (verified existant)

### Décomposition en 1 sub-système + 2 LOCK plans

#### Sub 5.1 — Re-attestation + LOCK plans W2/W5
**Tasks** :
- T-5.1.1 Re-run zone5 sentinel + E2E (proves no regression Phase 2 cycles)
   • anchor: `tests/Feature/Sentinels/Zone5PricingSsotConvergenceSentinelTest.php` (verified existant) + `tests/e2e/zone5-pricing-ssot.spec.js`
   • test: same — 6/6 sentinel + 5/5 E2E
- T-5.1.2 Write LOCK plan W2 (composition_snapshot `updating` guard model)
   • anchor: NEW `plans/LOCK_PRICING_W2_COMPOSITION_UPDATING_GUARD_2026-05-18.md`
   • test: (test TO BE CREATED at `tests/Feature/Pricing/CompositionSnapshotUpdatingGuardTest.php`)
   • owner-gate: WHO=owner physical, WHAT=signature LOCK §10, WHERE=commit
- T-5.1.3 Write LOCK plan W5 (DB BEFORE UPDATE trigger on `order_items.composition_snapshot`)
   • anchor: NEW `plans/LOCK_PRICING_W5_DB_UPDATE_TRIGGER_2026-05-18.md`
   • test: (test TO BE CREATED at `tests/Feature/Pricing/CompositionSnapshotDbTriggerTest.php`)
   • owner-gate: same as T-5.1.2
**Acceptance** : 6/6 sentinel + 5/5 E2E + 2 LOCK plans written + countersign requested.

---

## §8 — Zone 6 : Sync Outbox + 10 SYNC residual P0 (CMS audit)

### Contract
Outbox + Domain Events + Pusher channels + 10k load tolerance. Wave 3c heals : Cache::lock 300s + BATCH_CAP=500 + write-then-dispatch + audit_logs trail manual replay.

### Frozen zones
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`

### Anchors (verified 2026-05-18)
- `app/Console/Commands/` — 4 commands : `MonitorOutboxStaleness.php` + `OutboxRescueCommand.php` + `OutboxRetryFailedCommand.php` + `OutboxWebhookRetryFailedCommand.php`
- `routes/channels.php` (62 LOC) — Pusher auth
- `app/Http/PaymentGateways/Routes/` : `stripe.php` + `senangpay.php`
- Sentinels : `WsHeartbeatWriteSentinelTest` + `PusherChannelAuthWildcardSentinelTest` + `OutboxConcurrentRetryLockTest` + `OutboxReplayAuditTest`
- E2E spec : `tests/e2e/zone6-sync-resilience.spec.js` (verified existant)

### Décomposition en 4 sub-systèmes

#### Sub 6.1 — 11 listeners ShouldHandleEventsAfterCommit (SYNC residual P0 CMS audit)
**Anchors** : `app/Listeners/` (note: pas de sous-dir Outbox confirmé via ls — listeners directement dans app/Listeners/).
**Tasks** :
- T-6.1.1 grep all PersistXToOutbox + DispatchKdsTicket + RevokeTokensOnBranchDeactivated listeners
   • anchor: `find app/Listeners -name "Persist*ToOutbox*" -o -name "DispatchKds*" -o -name "RevokeTokens*"`
   • test: (test TO BE CREATED at `tests/Feature/Listeners/ListenerAfterCommitCoverageTest.php`)
- T-6.1.2 Add `ShouldHandleEventsAfterCommit` interface to all 11
   • anchor: per listener file (file:line per implementation)
   • test: same NEW coverage sentinel
**Acceptance** : 11 listeners attest `implements ShouldHandleEventsAfterCommit` + coverage sentinel passes.

#### Sub 6.2 — Outbox 10k simulation (SYNC-R3-P0-A)
**Anchors** : NEW test/seeder + load runner.
**Tasks** :
- T-6.2.1 Write 10k order seeder for outbox flood simulation
   • anchor: NEW `database/seeders/OutboxLoadTestSeeder.php`
   • test: (test TO BE CREATED at `tests/Feature/Outbox/OutboxTenKSimulationTest.php`)
- T-6.2.2 Assert 0 stale `domain_events` rows after 60-min simulation
   • anchor: NEW test + cron simulation
   • test: same file
**Acceptance** : 10k events processed, 0 stale > 60min. PHPUnit benchmark report.

#### Sub 6.3 — SYNC-ADV4-N1 Stripe CSRF except pattern (1 LOC P1)
**Anchors** : `app/Http/Middleware/VerifyCsrfToken.php` `$except` array.
**Tasks** :
- T-6.3.1 Fix pattern `payment/stripe-webhook/*` ≠ route bare `payment/stripe-webhook`
   • anchor: `VerifyCsrfToken.php` `$except = [...]` — replace `/*` by additional bare entry OR change pattern
   • test: (test TO BE CREATED at `tests/Feature/Webhook/StripeWebhookCsrfBareRouteTest.php`)
**Acceptance** : POST `payment/stripe-webhook` (no trailing) returns 200/4xx-not-419. CSRF exempt verified.

#### Sub 6.4 — Z7-V1.0.2-P2-01 BranchStatusChanged outbox persist (~30 LOC P2)
**Anchors** : pattern mirror `app/Listeners/PersistSettingsUpdatedToOutbox.php` (Wave 5G).
**Tasks** :
- T-6.4.1 NEW listener `PersistBranchStatusChangedToOutbox.php`
   • anchor: pattern reference Wave 5G `PersistSettingsUpdatedToOutbox`
   • test: (test TO BE CREATED at `tests/Feature/Outbox/PersistBranchStatusChangedTest.php`)
- T-6.4.2 Register in `app/Providers/EventServiceProvider.php` alongside `RevokeTokensOnBranchDeactivated`
   • anchor: `EventServiceProvider.php` listen array
   • test: same NEW file (regression check)
**Acceptance** : Test verifies domain_events row inserted on `BranchStatusChanged` dispatch with `wasRecentlyCreated` guard.

---

## §9 — Zone 7 : Admin Daily + 9 MGMT residual P0 (CMS audit)

### Contract
Owner daily flow : catalogue + stock + settings + Z report + branch deactivate. Wave 5G fanout R9 SettingsUpdated + R10 BranchStatusChanged tokens revoke. Sprint H1 Z6-06 EnsureUserStatusActive.

### Frozen zones
- (zone admin n'a pas de fichier frozen direct — discipline héritée des autres zones)

### Anchors (verified 2026-05-18 via `ls app/Http/Controllers/Admin/*.php | wc -l`)
- 87 `Admin/*Controller.php` files
- `app/Http/Controllers/Admin/PermissionController.php` (verified)
- E2E spec : `tests/e2e/zone7-admin-daily.spec.js` (verified existant)

### Décomposition en 4 sub-systèmes

#### Sub 9.1 — Ingredient overlay (MGMT residual P0)
**Anchors** : `app/Http/Controllers/Admin/IngredientController.php` (no constructor middleware — verified Wave 1 architect).
**Tasks** :
- T-9.1.1 Add constructor middleware to IngredientController
   • anchor: `IngredientController.php` __construct() add `$this->middleware(['permission:items'])` ou équivalent
   • test: (test TO BE CREATED at `tests/Feature/Admin/IngredientControllerAuthzTest.php`)
**Acceptance** : non-perm user → 403. With-perm → 200.

#### Sub 9.2 — env-edit lockdown (MGMT residual P0)
**Anchors** : à identifier via CMS audit report (probablement `app/Http/Controllers/Admin/EnvController.php` ou similaire).
**Tasks** :
- T-9.2.1 Find env-edit endpoint (`grep -r "putenv\|env(" app/Http/Controllers/Admin/`)
   • anchor: to-be-grepped
   • test: (test TO BE CREATED based on findings)
- T-9.2.2 Lock down via permission gate + audit_logs trail
   • anchor: same file
   • test: same NEW test file
**Acceptance** : env edit requires `permission:settings-admin` + audit_logs trail.

#### Sub 9.3 — Ansible validate + Preflight + drift cron (MGMT residual P0)
**Anchors** : NEW commands à créer.
**Tasks** :
- T-9.3.1 NEW `app/Console/Commands/AnsibleValidateCommand.php` (sanity check deploy files)
   • anchor: NEW file
   • test: (test TO BE CREATED at `tests/Feature/Console/AnsibleValidateCommandTest.php`)
   • **NOTE** : ne touche PAS au deploy lui-même (cloud talk archived). Valide juste les fichiers playbook syntax.
- T-9.3.2 NEW `app/Console/Commands/PreflightProductionCommand.php` (env checks)
   • anchor: NEW file
   • test: (test TO BE CREATED at `tests/Feature/Console/PreflightProductionCommandTest.php`)
   • **NOTE** : commande exists pour quand owner initie production, ne déclenche aucun cloud action.
- T-9.3.3 NEW drift cron monitoring `app/Console/Commands/MonitorConfigDriftCommand.php`
   • anchor: NEW file
   • test: (test TO BE CREATED at `tests/Feature/Console/MonitorConfigDriftCommandTest.php`)
**Acceptance** : 3 NEW commands + tests + `schedule:list` listings.

#### Sub 9.4 — EnsureUserStatusActive PHPUnit sentinel (V1.0.2 R-3)
**Anchors** : `app/Http/Middleware/EnsureUserStatusActive.php` (Sprint H1 Z6-06).
**Tasks** :
- T-9.4.1 NEW PHPUnit sentinel `tests/Feature/Middleware/EnsureUserStatusActiveSentinelTest.php`
   • anchor: NEW file mirror E2E AD09 logic
   • test: same NEW file (4 cases : active 200 / flip-inactive 401 / token deleted / different user no-impact)
**Acceptance** : 4/4 GREEN + middleware behavior locked in unit test (not just E2E).

---

## §A — Agent Army Map + Fan-Out Matrix

### Roles (9 base — applicable Phase 2)

| Rôle | Subagent type | Tools | Prompt template |
|---|---|---|---|
| Architect | `Plan` ou `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/architect-prompt.md` |
| Security RED | `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (SECURITY) |
| UX/A11y | `general-purpose` | Read + axe-core | inline brief WCAG 2.1 + ARIA |
| DBA | `general-purpose` | Read | inline brief schema + FK + N+1 + BranchScope 17 |
| SRE/Sync | `general-purpose` | Read | inline brief Outbox + Pusher + polling + queue |
| Implementer | `general-purpose` | Edit/Write/Bash | `~/.claude/skills/superpower-gstack/agents/implementer-prompt.md` (TDD-first) |
| RED-team | `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (RED) |
| QA Visual | `general-purpose` | Read + Playwright | inline brief run spec + capture + analyse |
| RED Visual | `general-purpose` | Read | inline brief re-analyse QA screenshots, dispute |

### Fan-Out Matrix per task type

| Task type | Architect | Security | UX/A11y | DBA | SRE | Implementer | RED | QA Vis | RED Vis |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Frontend visual (Vue/JS) | x | x | x | . | . | x | x | x | x |
| Backend logic | x | x | . | x | . | x | x | . | . |
| Sync cascade | x | x | . | x | x | x | x | . | . |
| Fiscal NF525-adjacent | x | x | . | x | . | x | x | . | . |
| Migration / schema | x | x | . | x | . | x | x | . | . |
| Data alignment | . | . | . | x | . | x | x | . | . |
| Cross-surface E2E | x | x | x | x | x | x | x | x | x |
| Owner-decision doc | . | . | . | . | . | (orchestrator) | . | . | . |

### Dispatch discipline
- **5 read-only specialists** = SINGLE MESSAGE multi-Agent calls (parallèle, ~3 min wall-clock)
- **Implementer** = JAMAIS parallèle avec autre Implementer (write conflict)
- **QA Visual + RED Visual** = parallèle OK (read-only screenshots)
- **RED-team dispute** = TOUJOURS après implementer commit, AVANT déclarer DONE

### Agent reporting contract (subagent → disk, NOT in-context)
Path : `reports/test-e2e/<mission>/<round>/wave-<W>-<role>.json`
Schema per finding : `[P0|P1|P2|P3] <file>:<line> — <title>` + reproduction + evidence + recommendation. Cap 1200-1500 mots/agent.

---

## §X — Convergence Waves (6 waves)

### Wave 1 — Pre-flight + Re-attestation 7 zones (sequential, ~30 min)
**Scope** : verify 7 zones Phase 1 still GREEN post Wave A+B+C commits. Full smoke broad PHPUnit + Vitest.
**Tasks** : run E2E specs zone1..zone7 sequentially. Smoke PHPUnit `--filter=Fiscal|Pos|Kds|Trust|Pricing|Outbox|Admin`. NF525 chain verify-chain --all.
**Parallelism** : sequential (regression scan).
**Checkpoint** : 7 E2E PASS + smoke PHPUnit GREEN + NF525 chain unchanged-or-appended + frozen-zone diff = 0.
**Interrupt-resume hook** : if interrupted, commit no-op WIP marker, write `INTERRUPT_W1_<ts>.md` listing zones already verified, resume from next zone.

### Wave 2 — Owner-gate clearance + CLAUDE.md additions (sequential, ~1h, BLOCKED on owner)
**Scope** : owner countersign POS XSS LOCK + POS-ADV3 cash drawer C/C/C + CLAUDE.md 4 additions (Audit Workflow + Data SSOT + Environment Safety + Execution Mode).
**Tasks** : T-2.1.1, T-2.1.2, T-2.2.1, T-2.2.2 + CLAUDE.md insert sections.
**Parallelism** : sequential (owner is single-threaded).
**Checkpoint** : LOCK signed OR explicit deferral; CLAUDE.md committed.
**Interrupt-resume hook** : owner pending = pause wave, continue Waves 3-5 in parallel where allowed.

### Wave 3 — Zone 1 NF525 V1.0.2 follow-up (parallel-allowed with Wave 4-5, ~2h)
**Scope** : §3 Sub 1.1 + 1.2 + 1.3 — alerting + audit/z decoupling + LOCK anon class.
**Tasks** : T-1.1.1..1.1.3, T-1.2.1..1.2.2, T-1.3.1.
**Parallelism** : disjoint of Waves 4-5 (Auth + Pricing) → parallel OK.
**Checkpoint** : 5 new tests + 2 NEW commands + 1 LOCK plan + frozen-zone diff = 0.

### Wave 4 — Zone 3 KDS+Kiosk + Zone 4 Auth + Zone 5 Pricing follow-up (parallel internal, ~3h)
**Scope** : §5 (DST + cross-driver + SLO + DashboardService UTC) + §6 (Outbox track + re-attestation) + §7 (LOCK W2/W5 + re-attestation).
**Tasks** : T-3.1.1..3.3.2, T-4.1.1..4.2.2, T-5.1.1..5.1.3.
**Parallelism** : 3 sub-tracks parallèles (Sub 3.x / Sub 4.x / Sub 5.x) — disjoint files.
**Checkpoint** : DST tests + SLO doc + Outbox heal + 2 LOCK plans + re-attestation E2E GREEN.

### Wave 5 — Zone 6 Sync 10 P0 + Zone 7 Admin 9 P0 (parallel internal, ~4h)
**Scope** : §8 (11 listeners + 10k sim + Stripe CSRF + BranchStatusChanged) + §9 (Ingredient + env-edit + Ansible/Preflight/drift + EnsureUserStatusActive sentinel).
**Tasks** : T-6.1.1..6.4.2, T-9.1.1..9.4.1.
**Parallelism** : 2 sub-tracks (Sync 4 + Admin 4 sub-systems) — mostly disjoint files.
**Checkpoint** : 11 listeners attest + 10k sim GREEN + Stripe CSRF + BranchStatusChanged + Ingredient gate + 3 commands + sentinel.

### Wave 6 — Final convergence + tagging (~1h)
**Scope** : RED-team adversarial sweep cumulative + full smoke broad + tag `v1.0.2-production-perfect-local` + BRAIN update.
**Tasks** : dispatch 3 hostile RED agents parallèle (CENTRAL + MGMT + SYNC cross-cutting). PHPUnit full Feature/Unit. Vitest full. Playwright zone1..zone7. NF525 chain verify-chain --all. Frozen-zone diff full range.
**Parallelism** : 3 RED agents parallèle ; verification sequential.
**Checkpoint** : 0 NEW P0/P1 on 2 consecutive cycles + frozen-zone diff = 0 + NF525 chain appended-only + tag created.

### Wave-Interrupt Protocol
If any wave interrupted mid-flight :
1. Commit WIP `wip(W<N>): partial through T-X.Y.Z`.
2. Write `reports/test-e2e/goal-v1-production-perfect-phase2-2026-05-18/INTERRUPT_W<N>_<ts>.md` with last-green SHA + next task.
3. Update BRAIN.md §2 with interrupt state.
4. Next session resume : read manifest, 1-task smoke, proceed.

### Wave-Convergence-Failure Protocol (3-loop stuck)
If a wave hits 3rd heal-loop on same cluster :
1. STOP wave.
2. Spawn Plan subagent — analyze stuck cluster.
3. Write `STUCK_W<N>_<ts>.md`.
4. Surface to owner : A) accept-with-doc, B) architecture pivot, C) defer V1.0.X, D) human gate.
5. DO NOT auto-pick.

---

## §G — Owner Gates (WHO/WHAT/WHERE)

| Gate | Description | WHO | WHAT | WHERE | Status | Blocks Wave |
|---|---|---|---|---|---|---|
| G1 | POS XSS LOCK countersign | Physical owner | Signature §10 sign-off in `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` | Commit message + BRAIN §6 DECISIONS LOG | PENDING | Wave 2 |
| G2 | POS-ADV3-05/06/07 cash drawer decision | Physical owner | Signature §Décision finale C/C/C OR alt in `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md` | Same doc | PENDING | Wave 2 |
| G3 | CLAUDE.md 4 additions accept | Physical owner | Decision A/B/C in `plans/CLAUDE_MD_PROPOSED_ADDITIONS_2026-05-18.md` | CLAUDE.md commit | PENDING | Wave 2 |
| G4 | LOCK W2 composition_snapshot updating guard | Physical owner | Signature §10 in NEW LOCK plan | Commit | PENDING (created Wave 4) | Wave 4 T-5.1.2 |
| G5 | LOCK W5 DB BEFORE UPDATE trigger | Physical owner | Signature §10 in NEW LOCK plan | Commit | PENDING (created Wave 4) | Wave 4 T-5.1.3 |
| G6 | LOCK Fiscal test anon class | Physical owner | Signature §10 in NEW LOCK plan | Commit | PENDING (created Wave 3) | Wave 3 T-1.3.1 |
| G7 | Tag `v1.0.2-production-perfect-local` | Physical owner | Owner authorisation push tag | Git tag in repo | PENDING (after Wave 6) | Wave 6 close |

### Owner-Gate-Waiting Protocol
While a gate is PENDING :
1. **Do NOT execute** the wave/task whose checkpoint depends on it.
2. **DO execute** any wave that does NOT depend on the gate (parallel).
3. List in BRAIN.md §2 blocked vs running waves.

Example : G1+G2+G3 block Wave 2. Waves 3-5 (zones 1/3/4/5/6/7 V1.0.2) run in parallel sans owner. Wave 6 final tag blocked by G7.

---

## §R — References

- `~/.claude/skills/ultra-audit-profond/SKILL.md` — per-task 20-step pipeline (10 gates)
- `~/.claude/skills/superpower-gstack/SKILL.md` — composition partner (frozen + NF525 + visual)
- `~/.claude/skills/test-e2e/SKILL.md` — dual-team adversarial visual
- `~/.claude/skills/lock-plan/SKILL.md` — frozen-zone override doc
- `~/.claude/skills/superpower-gstack/agents/architect-prompt.md` — Architect template
- `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` — RED template
- `~/.claude/skills/superpower-gstack/agents/implementer-prompt.md` — Implementer template
- `CLAUDE.md` §§4-13 — FoodKing operating memory
- `PROJECT_BRAIN.md` §1 NORTH STAR + §2 CURRENT STATE + §3 LAST DONE
- `reports/sessions/SESSION_HANDOFF_2026-05-18_FULL.md` — 600-LOC handoff 14 sections
- `plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md` — Phase 1 doctrine
- `reports/test-e2e/critical-focus-2026-05-18/MASTER_CONVERGENCE_FINAL.md` — 7 zones GO V1 LOCAL
- `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/FINAL_CONVERGENCE.md` — 19 P0 closed + 28 V1.0.2 backlog
- `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md` — owner-decision proposé C/C/C
- `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` — Wave 5G LOCK plan owner-gated
- `plans/CLAUDE_MD_PROPOSED_ADDITIONS_2026-05-18.md` — 4 additions /insights
- `memory/reference_frozen_zones.md` — canonical 13-file frozen list
- `memory/feedback_no_cloud_until_owner_initiates.md` — owner mandate ABSOLU
- `memory/feedback_massive_team_orchestration_e2e_per_system.md` — triple-team mandate
- `memory/feedback_insights_full_2026-05-18.md` — cross-session patterns full data
- `memory/feedback_adversarial_audit_pattern.md` — RED methodology canonical
- `memory/feedback_gstack_pipeline_methodology.md` — 7-step pipeline canonical
- `Graphiti foodking group` — knowledge graph cross-session

---

## §F — Final rule

Le GOAL est DONE quand :

1. **6/6 waves green** (chacune avec checkpoint Axis 3 ultra-architect-planify passé)
2. **0 NEW P0/P1** on 2 consecutive RED-team cycles (Wave 6 sweep)
3. **NF525 chain** unchanged-or-appended (`count` ≥ baseline, `last_hash` recalculable)
4. **Frozen-zone diff = 0** sur full GOAL range (`git diff --stat <wave1-start>..HEAD`)
5. **PHPUnit Feature/Unit full smoke** GREEN (target 800+ tests)
6. **Vitest full smoke** GREEN (target 50+ sentinels)
7. **Playwright zone1..zone7** all GREEN (E2E specs verified existant)
8. **BRAIN.md §2 §3 §6 §7** updated with final state
9. **7 owner gates G1-G7** signed OR explicitly deferred V1.0.X
10. **Tag `v1.0.2-production-perfect-local`** created post-G7 owner authorisation

**Production-perfect = perfect. Pas "presque", pas "good enough", pas "on verra plus tard".**

**Pas de cloud talk** — mandate immuable owner. Si Wave 6 close clean → V1 LOCAL READY pour quand owner initiera production. Aucune action cloud proposée d'ici-là.

---

*GOAL généré 2026-05-18 par `ultra-architect-planify` skill — orchestrateur Claude. Anchors verified via grep/find/ls. Target size 35 KB. Branche `heal/cms-pr1-quickwins-2026-05-18` HEAD `626d5a389`. Verdict baseline : 7/7 zones Phase 1 GO V1 LOCAL.*
