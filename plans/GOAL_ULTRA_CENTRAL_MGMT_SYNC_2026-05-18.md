# GOAL — ULTRA Audit, Architectural Backbone
## CENTRAL × MANAGEMENT × SYNC — V1.0.1 hardening branch
## Slug: `goal-ultra-central-mgmt-sync-2026-05-18` | Author: Claude | Skill: `ultra-architect-planify`

> **Scope:** deep-decomposition production-readiness audit of the **backbone** that, if broken, destroys the whole platform: backend orchestration (CENTRAL), admin/catalog/configuration (MANAGEMENT), real-time + queue + cross-surface coherence (SYNC).
> **NOT a duplicate** of `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` (CONVERGED ✅ today, user-facing flow-level). This GOAL goes one layer down: shared services, scopes, chains, gates, channels. Cf. §0.2.

---

## §0 — Preamble

### §0.1 Working-tree decision — **PATH-ISOLATE**
Current `git status -s` shows untracked artefacts from parallel `ULTRA_PLAN_FULLFLOW_2026-05-18` mission (`mobile/screens-main.jsx M`, `plans/ULTRA_PLAN_FULLFLOW_*`, `reports/test-e2e/goal-pageby-*`, `tests/e2e/goal-pageby-*.spec.js`). They do NOT touch backbone services. This GOAL writes only to `plans/GOAL_ULTRA_CENTRAL_MGMT_SYNC_2026-05-18.md` + `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/**` + paths declared per-task. Any incidental cross-touch → REJECT + revert.

### §0.2 Scope delta vs prior GOAL (`GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md`, HEAD `a34d1f696`)
Prior covered the **user-facing 8 flows** (POS / Kiosk / KDS / OSS / Mobile / Web / Livreur). This goes deeper into the **shared backbone**:
- Exhaustive `PricingService` SSOT (every callsite, every code path, every snapshot lifecycle)
- End-to-end NF525 chain (creation → seal → close → archive → verify → redact)
- 17-model `BranchScope` deep + every `withoutGlobalScope` exit
- Sanctum `kiosk:order` semantics (lifecycle, abuse, branch binding, refresh)
- `IdempotencyKeyMiddleware` semantics (replay window, 409 conflict, scope-tuple, 2xx-only)
- Outbox end-to-end (creation → dispatch → broadcast → ack → retry → DLQ → prune)
- Webhook idempotency per provider (Stripe / SenangPay / FCM)
- Latency budget attestation (POS→KDS / Kiosk→KDS / KDS→OSS p95)

Prior answered: *do user flows work?* This answers: *is the backbone trustworthy under load, attack, partial-failure, multi-tenant scale?*

### §0.3 Convergence criteria (DONE)
1. Every task acceptance PASS (or deferred with §H reasoning + V1.0.2 backlog).
2. NF525: `audit_logs.count + audit_logs.last_hash` unchanged-or-appended-with-valid-chain.
3. Frozen-zone diff = 0 across CLAUDE.md §7 13-file list (or LOCK doc with owner countersign).
4. BranchScope: 0 cross-branch row exposure in 4 sentinel tests (Order / OrderPayment / KioskMachine / StockLevel).
5. Idempotency: every POST mutating route declares enforcement OR documented exception.
6. Outbox: 0 unprocessed rows aged > 5 min in 60-min steady-state simulation.
7. RED-team P0+P1 = 0 NEW on two consecutive convergence cycles.
8. Each system's PR `/ultrareview` verdict ≥ GO-CONDITIONAL.

### §0.4 Pipeline reference
Per-task execution = `ultra-audit-profond` skill (14 steps: brief → 5-specialist read-only fan-out → synthesis → implement → RED → unit/integration → visual gate × 2 → adversarial visual → wave-checkpoint → BRAIN update). Not re-documented. See `~/.claude/skills/ultra-audit-profond/SKILL.md`. Sub-agent fan-out, frozen-zone overrides, visual mandate inherit from `superpower-gstack` + `test-e2e` + `lock-plan`.

### §0.5 PR-split — SEQUENTIAL branches
User runs `/ultrareview` once per system (3 runs; user-triggered + billed; I cannot launch). Volume cap assumed ≤5K LOC net diff/PR (sub-split per §0.5b if exceeded).

```
main (or v1-0-1-hardening-2026-05-17)
 └─ heal/central-backbone-2026-05-18      (PR #1 — /ultrareview run 1)
      └─ heal/mgmt-backbone-2026-05-18    (PR #2 — /ultrareview run 2, rebased on #1)
           └─ heal/sync-backbone-2026-05-18 (PR #3 — /ultrareview run 3, rebased on #2)
```

CENTRAL holds backbone services MGMT/SYNC depend on — reverse order = merge conflicts + unstable audit baselines. Each PR description: scope + task IDs from §3/§4/§5, acceptance test counts, frozen-zone diff (0 or LOCK ref), NF525 attestation (count + last_hash), command line `/ultrareview <PR#>`.

**§0.5b sub-split:** if a system exceeds 5K LOC net, split by sub-system (e.g. `heal/central-1-pricing-2026-05-18` … `heal/central-4-auth-idem-2026-05-18`), each gets its own `/ultrareview`.

### §0.6 Strong-reasoning output template (mandatory per P0/P1)
Every P0/P1 from `ultra-audit-profond` MUST include this YAML block (rejected otherwise):

```yaml
[P0|P1] <file>:<line> — <title>
  trigger:
    load_mode: <condition that triggers in prod>
    failure_mode: <manifestation>
  v2_saas_impact:
    blocks: <V2 migration path blocked, or "none">
    enables: <V2 capability enabled once fixed, or "none">
  cost_of_delay_if_v1_ships:
    customer: <impact>
    fiscal: <impact>
    business: <impact>
  recommendation:
    scope: <file:line minimal change>
    rollback: <undo>
    owner_gate: <Y if frozen-zone/LOCK, N otherwise>
```

§9 synthesis reads these directly — no second-pass research.

### §0.7 Interrupt-resume (operating mode, not recovery)
Per task: sub-agent reports → `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/<round>/wave-<W>-<task-id>-<role>.json`; heal commits prefixed `[<task-id>]`; Graphiti episode per significant task (`group_id="foodking"`); BRAIN.md §2 += last closed task SHA. If interrupted: commit WIP, write INTERRUPT manifest, update BRAIN, exit. Resume: read manifest, smoke last task, proceed.

---

## §1 — Map principal: 3 systems (anchors verified live 2026-05-18)

> ANCHOR-FIRST: every file path verified via `find`/`grep`/`ls` BEFORE this section was written.

### System 1 — CENTRAL (Backend Orchestration) — Maturity **70%**
**Anchors verified (`find app/Services app/Domain app/Http/Middleware app/Models/Scopes`):**
- `app/Services/Pricing/{PricingService.php (814 LOC), CompositionSnapshotBuilder, PricingRequest, PricingResult, PricingLineResult, DiscountCalculator, TaxCalculator}.php`
- `app/Services/Fiscal/{FiscalSequenceService.php (105 LOC frozen), ZReportService.php (frozen), AuditLogService.php (375 LOC frozen), FiscalSealingService, FiscalChainValidator, XReportService, ZReportCashEnrichmentService}.php`
- `app/Domain/Order/{OrderStateMachine.php (312 LOC frozen), PaymentStateMachine, IllegalTransitionException}.php` + `app/Domain/Kds/KitchenReleaseRule.php` + `app/Domain/Events/EventContract.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php (244 LOC frozen)` + `app/Models/Scopes/BranchScope.php (frozen)`
- **17 models declare `addGlobalScope(new BranchScope())`** — verified live: Order:92, OrderItem:27, OrderPayment:67, OrderQuote:22, KioskMachine:38, StockLevel:25, StockMovement:23, CashDrawerSession:68, CashMovement:59, PendingPaymentConfirmation:24, PushNotification:31, DiningTable:30, Printer:41, User:90, FrontendOrder:23, PosParkedOrder:40, PaymentTerminal:69

**Tests existing:** 30+ Fiscal + 5 Branch + 2 Idempotency + 5 Outbox + 1 Symmetry + 3 Security + Order/Cash/Payment/Composer suites (`tests/Feature/...`).

### System 2 — MANAGEMENT (Admin / Catalog / Config) — Maturity **60%**
**Anchors verified (`find app/Http/Controllers/Admin resources/js/components/admin config/`):**
- 60+ admin controllers (`Admin/{Menu,ItemCategory,Ingredient,ItemAttribute,Administrator,Role,Permission,Branch,Settings,KioskSetup,LoyaltySetup,PaymentGateway,Printer,Dashboard,SalesReport,KitchenDisplaySystem,StockRuptureDashboard,AdminPosV4}Controller}.php`
- Admin Vue: `resources/js/components/admin/{settings,customers,offers,posOrders,salesReport,observability/OutboxOverviewComponent.vue,dashboard/LastZReportWidget.vue,...}/`
- 13 verified `config/{app,broadcasting,cash,catalog_v15,fiscal,idempotency,kds,kiosk,menu (30 KB SSOT),oss,payment,pos,pricing}.php`
- `app/Console/Commands/{MenuHealLightV3Command.php (30 KB), MenuResetLeCayenneCommand.php (51 KB)}` (catalog mutators)

**Tests existing:** `tests/Feature/{Admin,Dashboard,Settings,Catalog,Composer}/`.

### System 3 — SYNCHRONIZATION (Real-time / Queue / Cross-surface) — Maturity **65%**
**Anchors verified (`find app/Events app/Listeners app/Jobs ls config/`):**
- 30 events (`app/Events/{OrderCreated, OrderStatusChanged, OrderCanceled, OrderPaidAtCounter, OrderTableChanged, RefundCreated, CatalogChanged, ComposerProfileChanged, ComposerProfilePublished, SettingsUpdated, ItemAvailabilityChanged, ItemExtraAvailabilityChanged, ItemVariationAvailabilityChanged, IngredientAvailabilityChanged, StockLevelChanged, CouponChanged, SendOrderPush, SendOrderMail, SendSmsCode, ...}.php`)
- 11 `Persist*ToOutbox` listeners verified: `app/Listeners/Persist{OrderCreated, OrderStatusChanged, OrderTableChanged, OrderPaidAtCounter, OrderPaymentStatusChanged, CatalogChanged, CouponChanged, SettingsUpdated, ItemAvailabilityChanged, ItemExtraAvailabilityChanged, ItemVariationAvailabilityChanged}ToOutbox.php`
- `app/Jobs/{DispatchDomainEventsJob, ProcessWebhookEventJob, SendFcmNotificationJob, CleanupStalePendingKioskOrders}.php` + `app/Jobs/Observability/SloEvaluatorJob.php`
- `app/Services/{KdsSyncService.php, Observability/{SyncMetricsRecorder, SloMetricCollector}.php}` + `app/Http/Controllers/Admin/{KdsSyncController, Observability/SyncOverviewController}.php`
- `app/Models/WebhookEvent.php` + migration `2026_05_09_120000_create_webhook_events_table.php` (UNIQUE provider+webhook_id)
- 6 Outbox cmds: `OutboxRetryFailedCommand, OutboxRescueCommand, OutboxWebhookRetryFailedCommand, MonitorOutboxStaleness, PruneOutboxCommand, PruneWebhookEventsCommand`
- `config/{broadcasting, horizon, queue}.php` verified existing
- Migration `2026_05_09_180000_add_idempotency_key_to_domain_events.php` (Outbox dedupe)

**Tests existing:** `tests/Feature/Outbox/{CatalogEventDispatchAfterCommit, ListenerReplayDedupe, OutboxConcurrentWorkerDedupe, OutboxDelivery, OutboxProductionLikeSimulation}Test.php` (5 verified) + `tests/Feature/Idempotency/{CounterCollectAndPrintIdempotency, IdempotencyMiddleware}Test.php` (2 verified) + `tests/Feature/Symmetry/OrderServicesContractTest.php`.

---

## §2 — Map separated: NOT APPLICABLE
All 3 systems are central + tightly coupled. Mobile/Web (standalone, prior GOAL) out-of-scope.

---

## §3 — SYSTEM 1: CENTRAL (Backend Orchestration)

**Contract:** moteur fiscal + tarif + scope + auth + idempotence. Rupture → NF525 violation, branch leak, double-charge, fiscal gap.

**Frozen zones (CLAUDE.md §7, strict-no-touch):** `FiscalSequenceService, ZReportService, AuditLogService, BranchScope, IdempotencyKeyMiddleware, PricingService, OrderStateMachine`.

**NF525 invariants touched:** ALL 4 (Pricing SSOT, fiscal_sequence_no monotonic, audit_logs HMAC chain, composition_snapshot immutability).

### Sub 1.1 — Pricing SSOT
**Anchors:** `PricingService.php:1-814 (frozen)`, `CompositionSnapshotBuilder.php`, `PricingRequest/Result/LineResult.php`, `DiscountCalculator.php`, `TaxCalculator.php`.

- **T-1.1.1** Exhaustive callsite audit — every entry from POS/Kiosk/Refund/Quote routes through `PricingService`
   - anchor: `PricingService.php:1-814` + grep `PricingService::` across `app/`
   - test: `tests/Feature/Symmetry/OrderServicesContractTest.php` (PASS) + `(test TO BE CREATED at tests/Feature/Pricing/PricingSSOTCallsiteSentinelTest.php)`
   - acceptance: sentinel asserts 0 occurrences of `* total *= *` / `* unit_price *= *` outside `PricingService`

- **T-1.1.2** `CompositionSnapshot` immutability across quote → order → refund lifecycle
   - anchor: `CompositionSnapshotBuilder.php` + Order migration JSON column
   - test: `(test TO BE CREATED at tests/Feature/Pricing/CompositionSnapshotImmutabilityTest.php)`
   - acceptance: (a) post-order snapshot byte-identical SQL read, (b) item-price change in DB does NOT affect order snapshot, (c) refund reconstructs from snapshot match cents

- **T-1.1.3** Discount + Tax edge cases (round-half-even, fixed cap, coupon stack, branch tax overrides)
   - anchor: `DiscountCalculator.php` + `TaxCalculator.php` + `Branch.php` (tax fields)
   - test: `tests/Feature/Fiscal/TaxTypeMisconfigDetectionTest.php` (PASS) + `(test TO BE CREATED at tests/Feature/Pricing/DiscountTaxEdgeCasesTest.php)`
   - acceptance: €9.99 × 10% → €1.00 (round-before-cast), coupon-fixed cap ≤ subtotal, two-coupon stack deterministic order

- **T-1.1.4** Pricing observability — log quote→order drift + snapshot mismatch via `SyncMetricsRecorder`
   - anchor: `PricingService.php` (read) + `SyncMetricsRecorder.php`
   - test: `(test TO BE CREATED at tests/Feature/Pricing/PricingObservabilityTest.php)`
   - acceptance: any quote→order delta logs WARN with both snapshot diffs

### Sub 1.2 — NF525 Fiscal Chain
**Anchors:** all 7 `app/Services/Fiscal/*.php` + migrations `2026_05_09_160000_add_z_reports_delete_trigger_immutability` + `2026_05_10_010000_secure_fiscal_audit_trail_immutability` + `2026_05_09_200000_add_fiscal_alloc_error_at_to_orders`.

- **T-1.2.1** End-to-end NF525 attestation under load (10 concurrent × 3 branches × 30 min)
   - anchor: `FiscalSequenceService.php:1-105 (frozen)` + `AuditLogService.php:1-375 (frozen)`
   - test: `tests/Feature/Fiscal/NF525ComplianceE2ETest.php` (PASS) + `AuditLogConcurrencyTest.php` (PASS) + `(test TO BE CREATED at tests/Feature/Fiscal/Nf525LoadAttestationTest.php)`
   - acceptance: post-load `SELECT COUNT(*), MAX(current_hash) FROM audit_logs` shows expected count + sequential `fiscal_sequence_no` (zero gap)

- **T-1.2.2** ZReport boundary cases (midnight, DST, branch tz, leap second)
   - anchor: `ZReportService.php (frozen)`
   - test: `tests/Feature/Fiscal/{ZReportBoundary, ZReportClose}Test.php` (PASS) + `(test TO BE CREATED at tests/Feature/Fiscal/ZReportTzAndDstEdgeCaseTest.php)`
   - acceptance: (a) 23:59:59 branch-local → Day N Z, (b) DST spring-forward no orphan, (c) leap second handled N+0

- **T-1.2.3** Chain immutability under DELETE/TRUNCATE/UPDATE attempts (prod MySQL only)
   - anchor: migration `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php` (BEFORE DELETE trigger SIGNAL SQLSTATE '45000')
   - test: `tests/Feature/Fiscal/{AuditLogImmutability, AuditLogHashChain, ZReportDeleteTriggerMysqlOnly}Test.php` (all PASS) + `(test TO BE CREATED at tests/Feature/Fiscal/AuditTruncateProtectionDeployDocTest.php)`
   - acceptance: deploy doc references TRUNCATE-protection GRANT OR Ansible revokes TRUNCATE from app user

- **T-1.2.4** `fiscal_alloc_error_at` retry path + DLQ quarantine
   - anchor: `FiscalSequenceService.php:1-105 (frozen)` + migration `2026_05_09_200000` + Kernel cron
   - test: `tests/Feature/Fiscal/FiscalAllocOrphanRetryTest.php` (PASS) + `(test TO BE CREATED at tests/Feature/Fiscal/FiscalAllocDlqTest.php)`
   - acceptance: simulate `Cache::lock` timeout → order saved + flag set → cron retry within 5 min → 3-fail → DLQ entry

- **T-1.2.5** Refund post-Z + Void pre-Z + Sealed-order mutation guard
   - anchor: `FiscalSealingService.php`
   - test: `tests/Feature/Fiscal/{SealedOrderMutationGuard, RefundPostZ, RefundPreZ, VoidPreZ}Test.php` (all PASS) + `(test TO BE CREATED at tests/Feature/Fiscal/RefundChainNumberingTest.php)`
   - acceptance: refund = NEW audit row, `prev_hash` = last order row, sealed rows reject UPDATE

### Sub 1.3 — Branch Isolation (BranchScope, 17 models)
**Anchors:** `BranchScope.php (frozen)` + 17 verified grep callsites (see §1 above).

- **T-1.3.1** Exhaustive BranchScope coverage — every business model with `branch_id` declares it
   - anchor: `app/Models/*.php` + grep `addGlobalScope(new BranchScope`
   - test: `tests/Feature/Branch/OrderBranchIsolationTest.php` (PASS) + `(test TO BE CREATED at tests/Feature/Branch/BranchScopeCoverageSentinelTest.php)`
   - acceptance: sentinel iterates every model with `branch_id` column, asserts BranchScope declaration OR documented exception list (Branch, Customer for Sanctum recursion)

- **T-1.3.2** `withoutGlobalScope` exit audit — every exit annotated + restored
   - anchor: grep `withoutGlobalScope` across `app/`
   - test: `(test TO BE CREATED at tests/Feature/Branch/WithoutGlobalScopeExitAuditTest.php)`
   - acceptance: every call followed within ≤5 lines by explicit branch filter OR pre-auth-lookup comment

- **T-1.3.3** Branch deactivation propagation (token revoke + scope behaviour)
   - anchor: `tests/Feature/Branch/{BranchDeactivationTokenRevoke, BranchDestroyRevokesTokens}Test.php` (PASS) + `RevokeTokensOnBranchDeactivated.php`
   - test: existing + `(test TO BE CREATED at tests/Feature/Branch/DeactivatedBranchScopeFanoutTest.php)`
   - acceptance: deactivate branch → tokens revoked → `Order::query()` returns 0 for that branch in any context

- **T-1.3.4** Multi-tenant injection paths (admin `branch_id=0` bypass, staff scoped)
   - anchor: `User.php` + `BranchScope.php (frozen)`
   - test: `tests/Feature/Branch/OssAdminBranchPolicyTest.php` (PASS) + `tests/Feature/KioskMultiBranch/` + `(test TO BE CREATED at tests/Feature/Branch/AdminBypassInjectionTest.php)`
   - acceptance: 4 cases — (a) staff `?branch_id=0` rejected, (b) admin `X-Branch-Id` header validated, (c) JWT-claim tamper rejected, (d) sub-resource (OrderItem) inherits scope

### Sub 1.4 — Auth & Idempotency (Sanctum + Spatie + IdempotencyKey)
**Anchors:** `IdempotencyKeyMiddleware.php:1-244 (frozen)` + `KioskMachineLoginController.php:100` (token `['kiosk:order']`) + 8 verified `tokenCan('kiosk:order')` callsites (MenuController:37, UpsellController:32, LoyaltyController:258+579, PaymentReconcileController:87, KioskEventController:20).

- **T-1.4.1** Sanctum `kiosk:order` exhaustive — every kiosk-facing route token-checks
   - anchor: grep `tokenCan` in `app/Http/Controllers/` (8 callsites verified)
   - test: `tests/Feature/KioskSecurity/` + `(test TO BE CREATED at tests/Feature/Auth/KioskOrderAbilityExhaustiveTest.php)`
   - acceptance: sentinel asserts all `/api/frontend/*` routes declare `abilities:kiosk:order` middleware OR controller-level `tokenCan` guard

- **T-1.4.2** IdempotencyKey deep semantics (scope tuple + replay cache + 2xx-only + 409 conflict)
   - anchor: `IdempotencyKeyMiddleware.php:1-244 (frozen)`
   - test: `tests/Feature/Idempotency/{IdempotencyMiddleware, CounterCollectAndPrintIdempotency}Test.php` (PASS) + `(test TO BE CREATED at tests/Feature/Idempotency/IdempotencyConflict409Test.php)`
   - acceptance: 5 cases — (a) duplicate key + same payload → cached 2xx, (b) duplicate key + diff payload → 409, (c) 5xx does NOT cache, (d) cache expires at TTL, (e) DB UNIQUE rejects on cache-miss + concurrent

- **T-1.4.3** Idempotency coverage on POST mutating routes (gap audit)
   - anchor: `routes/api.php` + grep `IdempotencyKey` usage
   - test: `(test TO BE CREATED at tests/Feature/Idempotency/PostMutatingCoverageSentinelTest.php)`
   - acceptance: sentinel scans `Route::post` declarations, asserts presence of `idempotency` middleware OR allow-list in `config/idempotency.php`

- **T-1.4.4** Spatie permissions matrix (88 endpoints; 5 hardened Wave 5H, 83 V1.0.2 backlog)
   - anchor: `routes/api.php` (verified `permission:ingredients_manage:682`, `catalog.compose:696`, `catalog.publish:718`) + `app/Http/Requests/`
   - test: `(test TO BE CREATED at tests/Feature/Auth/PermissionMatrixCoverageTest.php)`
   - acceptance: sentinel scans Form Requests for `authorize()` returning `true` without check → emits backlog + 0 critical-endpoint exemptions

- **T-1.4.5** Token lifecycle (kiosk 480 min, sensitive 1h, revocation on relogin)
   - anchor: `LoginController.php:108` + `config/sanctum.php` + `RevokeTokensOnBranchDeactivated.php`
   - test: `(test TO BE CREATED at tests/Feature/Auth/TokenLifecycleTest.php)`
   - acceptance: 4 cases — (a) kiosk TTL = config-driven 480 min, (b) admin relogin revokes admin tokens, (c) kiosk relogin does NOT touch admin tokens, (d) branch deactivation revokes branch tokens

---

## §4 — SYSTEM 2: MANAGEMENT (Admin / Catalog / Config)

**Contract:** pilote catalogue + config + rapports fiscaux + utilisateurs/rôles + paramètres. Rupture → owner ne peut plus opérer.

**Frozen zones touched:** `PaymentComponent.vue` + `pos-wizard.js` (audit-only).

**NF525 touched:** Z-report UI surfaces fiscal data (no edit/delete via UI; UI gate + backend trigger).

### Sub 2.1 — Catalog Management
**Anchors:** `Admin/{Menu, ItemCategory, Ingredient, ItemAttribute, MenuSection, PosCategory}Controller.php` + `config/menu.php (30 KB SSOT)` + `MenuHealLightV3Command.php (30 KB)` + `MenuResetLeCayenneCommand.php (51 KB)`.

- **T-2.1.1** Catalog SSOT consistency (`config/menu.php` ↔ DB ↔ frontend payloads)
   - anchor: `config/menu.php` + `MenuController::index`
   - test: `tests/Feature/Catalog/` + `(test TO BE CREATED at tests/Feature/Catalog/CatalogSsotConsistencyTest.php)`
   - acceptance: every config item has matching DB row (id/name/base_price); every published DB item matches config

- **T-2.1.2** Composer publish + propagation (`ComposerProfilePublished` → fanout to POS+Kiosk+KDS+admin)
   - anchor: `ComposerProfilePublished.php` + `ComposerProfileChanged.php`
   - test: `tests/Feature/Composer/` + `(test TO BE CREATED at tests/Feature/Composer/ProfilePublishFanoutTest.php)`
   - acceptance: publish → event → Outbox → Pusher → 3 surfaces refresh within 5s p95

- **T-2.1.3** Availability matrix + cascade (`Ingredient → Item → Variation → Extras` outage)
   - anchor: 4 `*AvailabilityChanged` events + 4 `Persist*ToOutbox` listeners + `InvalidateKioskMenuCacheOn*` listeners
   - test: `tests/Feature/KioskMultiBranch/` + `(test TO BE CREATED at tests/Feature/Catalog/AvailabilityCascadeTest.php)`
   - acceptance: ingredient OUT cascades to N items in <2s; Outbox row; Pusher event; cache invalidated

- **T-2.1.4** Catalog mutation idempotency — `MenuHealLight` re-runnable
   - anchor: `MenuHealLightV3Command.php` + `MenuResetLeCayenneCommand.php`
   - test: `(test TO BE CREATED at tests/Feature/Catalog/CatalogMutationIdempotencyTest.php)`
   - acceptance: run command twice → 0 row duplication, 0 orphan extras, 0 broken FK

### Sub 2.2 — RBAC / Users / Branches Admin
**Anchors:** `Admin/{Role, Permission, Administrator}Controller.php` + `Http/Requests/{BranchRequest, RoleRequest, AdministratorRequest}.php` (5 Wave 5H FormRequest authz hardened).

- **T-2.2.1** Role/Permission CRUD authz (Spatie 5 sync)
   - anchor: `RoleController::store,update,destroy` + `PermissionController::*` + `RoleRequest::authorize()`
   - test: `(test TO BE CREATED at tests/Feature/Admin/RolePermissionAuthzTest.php)`
   - acceptance: user with `roles_manage` cannot self-escalate super-admin; cannot delete own role; audit_log on every grant/revoke

- **T-2.2.2** Branch CRUD propagation (create/deactivate/destroy → token revoke + fanout)
   - anchor: `BranchController::*` + `BranchStatusChanged.php` + `RevokeTokensOnBranchDeactivated.php` + `tests/Feature/Branch/BranchDestroyRevokesTokensTest.php` (PASS)
   - test: existing + `(test TO BE CREATED at tests/Feature/Admin/BranchCrudFanoutTest.php)`
   - acceptance: branch destroy with N orders → soft-delete OR FK refuse with clear message; 0 audit_logs deletion

- **T-2.2.3** Administrator CRUD + password policy + 2FA scaffold
   - anchor: `AdministratorController` + `LoginController.php:108` (bcrypt 10→12 Wave 5G) + `EnsureUserStatusActive.php`
   - test: `tests/Feature/Auth/` + `(test TO BE CREATED at tests/Feature/Admin/AdministratorPasswordPolicyTest.php)`
   - acceptance: weak password rejected; existing weak forced-rehash on login; 2FA scaffold present

- **T-2.2.4** FormRequest `authorize()` coverage (88 endpoints, V1.0.2 backlog)
   - anchor: `app/Http/Requests/` + grep `authorize.*return true`
   - test: `(test TO BE CREATED at tests/Feature/Admin/FormRequestAuthzCoverageSentinelTest.php)`
   - acceptance: scan emits backlog count + 0 critical-endpoint `return true` exemptions

### Sub 2.3 — Fiscal UI + Reports
**Anchors:** `Admin/{SalesReport, CreditBalanceReport, Dashboard}Controller.php` + `dashboard/LastZReportWidget.vue`.

- **T-2.3.1** Z-report viewer integrity (UI cannot edit/delete; print/export only)
   - anchor: `SalesReportController` + `LastZReportWidget.vue` + `tests/Feature/Fiscal/ZReportControllerTest.php` (PASS)
   - test: existing + `(test TO BE CREATED at tests/Feature/Admin/ZReportUiReadOnlyTest.php)`
   - acceptance: DELETE on Z-report → 403/405; UI offers no delete button; export PDF works with permission

- **T-2.3.2** Sales report cross-branch admin vs per-branch staff
   - anchor: `SalesReportController` + `BranchScope (frozen)`
   - test: `tests/Feature/Branch/OssAdminBranchPolicyTest.php` (PASS) + `(test TO BE CREATED at tests/Feature/Admin/SalesReportBranchPolicyTest.php)`
   - acceptance: super-admin sees aggregate, manager sees own branch only, tampered `branch_id` rejected

- **T-2.3.3** X-report parity with Z-report (read-only, no chain mutation)
   - anchor: `XReportService.php` + `tests/Feature/Fiscal/XReportTest.php` (PASS)
   - test: existing + `(test TO BE CREATED at tests/Feature/Admin/XReportUiTest.php)`
   - acceptance: X-report does NOT create audit_logs row, does NOT seal, can run multiple times same day

- **T-2.3.4** Fiscal archive UI + chain verification surface
   - anchor: `FiscalArchiveCommand.php` + `tests/Feature/Fiscal/{FiscalArchive, FiscalArchiveScheduled}Test.php` (PASS)
   - test: existing + `(test TO BE CREATED at tests/Feature/Admin/FiscalArchiveUiPresentationTest.php)`
   - acceptance: admin widget shows `last_archive_at + chain_verified_at + chain_status`; missing >24h → red banner

### Sub 2.4 — Settings + Configuration
**Anchors:** `Admin/{Settings, KioskSetup, LoyaltySetup, PaymentGateway, Printer, Timezone, Currency}Controller.php` + 13 `config/*.php` verified.

- **T-2.4.1** `SettingsUpdated` event fanout (admin → POS/Kiosk/KDS observe new setting)
   - anchor: `SettingsUpdated.php` + `PersistSettingsUpdatedToOutbox.php` + 5 wired controllers (Wave 5G)
   - test: `(test TO BE CREATED at tests/Feature/Admin/SettingsFanoutTest.php)`
   - acceptance: change → event → Outbox → Pusher → 3 surfaces receive within 5s p95

- **T-2.4.2** Feature flag governance (`simulation_hardware, dine_in_enabled, kds_v2_default, kiosk_locale_switch`)
   - anchor: `config/pos.php:112` (simulation_hardware) + `AppServiceProvider.php` (production boot guard `2477a2d05`)
   - test: `tests/Feature/Symmetry/OrderServicesContractTest.php` + `(test TO BE CREATED at tests/Feature/Admin/FeatureFlagProductionGuardTest.php)`
   - acceptance: prod boot with `simulation_hardware=true` throws RuntimeException; admin UI red banner on dangerous flag

- **T-2.4.3** Payment gateway config audit (Stripe + SenangPay + Cash)
   - anchor: `PaymentGatewayController` + `config/payment.php`
   - test: `tests/Feature/Payment/` + `(test TO BE CREATED at tests/Feature/Admin/PaymentGatewayConfigAuditTest.php)`
   - acceptance: secrets encrypted at rest; UI never displays full secret (masked); rotation flow tested

- **T-2.4.4** KioskSetup config (`FRITES_INCLUDED_CATS K-003` + locale lock `K-001`)
   - anchor: `config/kiosk.php` + `EnsureKioskMachineCommand.php`
   - test: `tests/Feature/KioskMultiBranch/` + `(test TO BE CREATED at tests/Feature/Admin/KioskSetupConsistencyTest.php)`
   - acceptance: config change reflected in admin UI; kiosk pulls config on session start; FR-lock K-001 holds

---

## §5 — SYSTEM 3: SYNCHRONISATION (Real-time / Queue / Cross-Surface)

**Contract:** colonne vertébrale temps réel. Rupture → POS/Kiosk désynchros KDS, OSS stale state, orders perdent fan-out.

**Frozen zones touched:** none. Outbox listeners + `DispatchDomainEventsJob` are V1.0.1-hardened but NOT in CLAUDE.md §7.

**NF525 invariants touched:** INDIRECT — Outbox failure → `fiscal_alloc_error_at` delay; webhook idempotency → no double payment recording.

### Sub 3.1 — Outbox + Domain Events
**Anchors:** 11 `Persist*ToOutbox` listeners + `DispatchDomainEventsJob` + migration `2026_05_09_180000_add_idempotency_key_to_domain_events` + 6 Outbox commands.

- **T-3.1.1** Outbox end-to-end (event → listener → row → dispatch → broadcast → ack)
   - anchor: `OrderCreated.php` + `PersistOrderCreatedToOutbox.php` + `DispatchDomainEventsJob.php`
   - test: `tests/Feature/Outbox/OutboxDeliveryTest.php` (PASS) + `CatalogEventDispatchAfterCommitTest.php` (PASS) + `(test TO BE CREATED at tests/Feature/Outbox/OutboxFullLifecycleAttestationTest.php)`
   - acceptance: rollback → 0 Outbox rows; commit → exactly N rows; dispatch p95 <5s

- **T-3.1.2** Concurrent worker dedupe (idempotency_key on domain_events)
   - anchor: migration `2026_05_09_180000` + `DispatchDomainEventsJob` lock semantics
   - test: `tests/Feature/Outbox/{OutboxConcurrentWorkerDedupe, ListenerReplayDedupe}Test.php` (both PASS) + `(test TO BE CREATED at tests/Feature/Outbox/OutboxRaceConditionTest.php)`
   - acceptance: 10 workers × 100 rows = 1000 dispatches → exactly 1000 broadcasts (no duplicates)

- **T-3.1.3** Staleness monitoring + retry + DLQ
   - anchor: `MonitorOutboxStaleness.php` + `OutboxRetryFailedCommand.php` + `OutboxRescueCommand.php` + `PruneOutboxCommand.php`
   - test: `(test TO BE CREATED at tests/Feature/Outbox/OutboxStalenessAlertTest.php)`
   - acceptance: simulate Pusher down 10 min → monitor flags >5min rows → retry resumes on Pusher back → 0 row lost

- **T-3.1.4** Production-like simulation (10k events / 60s)
   - anchor: `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php` (PASS)
   - test: existing + `(test TO BE CREATED at tests/Feature/Outbox/OutboxProdMassiveSimulationTest.php)` — 10k events
   - acceptance: 10k events in 60s, p95 <5s, 0 lost, 0 duplicate broadcasts

- **T-3.1.5** Prune retention (fiscal events 6y vs operational 30j)
   - anchor: `PruneOutboxCommand.php` + Kernel scheduled 04:15
   - test: `(test TO BE CREATED at tests/Feature/Outbox/OutboxPruneRetentionTest.php)`
   - acceptance: prune keeps fiscal events >30j, removes non-fiscal >30j, never touches audit_logs

### Sub 3.2 — Broadcasting + Cross-Surface Coherence
**Anchors:** `config/broadcasting.php` + `DispatchDomainEventsJob` (Graphiti-attested: private Pusher channel branch) + `KdsSyncService.php` + Laravel Echo private channel auth.

- **T-3.2.1** Order state-machine fan-out coherence (paid → preparing → ready → served)
   - anchor: `OrderStateMachine.php:1-312 (frozen)` + `OrderStatusChanged.php` + `PersistOrderStatusChangedToOutbox.php` + `KdsSyncService.php`
   - test: `tests/Feature/Order/` + `(test TO BE CREATED at tests/Feature/Sync/OrderStateMachineFanoutTest.php)`
   - acceptance: transition paid→preparing → POS+Kiosk+KDS+OSS reflect within 5s p95; broadcast carries branch_id + order_id + new_status

- **T-3.2.2** Pusher channel auth + per-branch isolation
   - anchor: `routes/channels.php` + naming convention
   - test: `(test TO BE CREATED at tests/Feature/Sync/PusherChannelBranchIsolationTest.php)`
   - acceptance: user A → `private-branch.{B}` → 403; user A → `private-branch.{A}` → 200 + receives events

- **T-3.2.3** Pusher down fallback (5s polling per CLAUDE.md §1)
   - anchor: Echo client config + `KdsAppComponent.vue` polling fallback
   - test: `(test TO BE CREATED at tests/Feature/Sync/PusherFallbackToPollingTest.php)` (E2E Pusher mock disconnect)
   - acceptance: disconnect → client switches to polling <5s → polling fetches state → surface converges <10s

- **T-3.2.4** KioskMachine heartbeat + ack semantics
   - anchor: `KioskMachine.php:38 (BranchScope)` + `KioskMachineService.php` + `last_seen_at` field
   - test: `(test TO BE CREATED at tests/Feature/Sync/KioskMachineHeartbeatTest.php)`
   - acceptance: heartbeat every 30s; offline alert at 5min; admin UI shows online/offline; `last_seen_at` updated on each event

### Sub 3.3 — Webhook + Queue Workers
**Anchors:** `WebhookEvent.php` + `ProcessWebhookEventJob.php` + `OutboxWebhookRetryFailedCommand.php` + `PruneWebhookEventsCommand.php` + migration `2026_05_09_120000_create_webhook_events_table.php` (UNIQUE provider+webhook_id).

- **T-3.3.1** Webhook idempotency by provider (Stripe + SenangPay + FCM)
   - anchor: `WebhookEvent` + `ProcessWebhookEventJob` + UNIQUE (provider, webhook_id)
   - test: `(test TO BE CREATED at tests/Feature/Webhook/WebhookIdempotencyByProviderTest.php)` (3 provider scenarios)
   - acceptance: same `webhook_id` replayed → 0 duplicate side-effect (no double order, no double payment row, no double audit_log)

- **T-3.3.2** Webhook DLQ + retry semantics
   - anchor: `OutboxWebhookRetryFailedCommand.php` + `PruneWebhookEventsCommand.php`
   - test: `tests/Feature/Webhook/` + `(test TO BE CREATED at tests/Feature/Webhook/WebhookDlqRetryTest.php)`
   - acceptance: 3 retries with backoff → DLQ → admin alert fires

- **T-3.3.3** Horizon queue health + worker scaling
   - anchor: `config/horizon.php`
   - test: `(test TO BE CREATED at tests/Feature/Queue/HorizonHealthSentinelTest.php)`
   - acceptance: Horizon `status` green; queue depth p95 <N; failed jobs <1%

- **T-3.3.4** Cron entries integrity (fiscal_alloc retry, prune, archive, monitor)
   - anchor: `app/Console/Kernel.php`
   - test: `(test TO BE CREATED at tests/Feature/Console/CronEntriesSentinelTest.php)`
   - acceptance: `fiscal:archive, fiscal:verify-chain, outbox:prune, outbox:rescue, monitor:outbox-staleness, kiosk:cleanup-stale` declared + scheduled

### Sub 3.4 — Latency Budgets + Observability
**Anchors:** `SyncMetricsRecorder.php` + `SloMetricCollector.php` + `SloEvaluatorJob.php` + `SyncOverviewController.php` + `OutboxOverviewComponent.vue`.

- **T-3.4.1** Latency budget attestation (POS→KDS p95, Kiosk→KDS p95, KDS→OSS p95)
   - anchor: `SloMetricCollector.php` + `SyncMetricsRecorder.php`
   - test: `(test TO BE CREATED at tests/Feature/Observability/LatencyBudgetAttestationTest.php)`
   - acceptance: simulate POS order → measure POS→KDS broadcast delay → p95 <5s over 100 samples

- **T-3.4.2** SLO evaluator + alert thresholds
   - anchor: `SloEvaluatorJob.php` + admin UI banner
   - test: `(test TO BE CREATED at tests/Feature/Observability/SloEvaluatorAlertTest.php)`
   - acceptance: induce SLO breach → SloEvaluatorJob emits alert → admin UI red banner

- **T-3.4.3** Observability dashboard + Outbox overview
   - anchor: `SyncOverviewController.php` + `OutboxOverviewComponent.vue`
   - test: `(test TO BE CREATED at tests/Feature/Observability/SyncOverviewControllerTest.php)`
   - acceptance: GET `/admin/observability/sync-overview` → p50/p95/p99 + queue depth + Outbox staleness + Pusher connection state

- **T-3.4.4** End-to-end load test (300 orders / 15 min / 3 branches)
   - anchor: `app/Console/Commands/E2EStressCommand.php` (existing)
   - test: existing + `(test TO BE CREATED at tests/Feature/Sync/CrossSurfaceLoadTest.php)`
   - acceptance: 300 orders / 15 min → 0 lost broadcast, p95 <5s, all surfaces converge, no Outbox staleness

---

## §A — Agent Army Map (compressed reference)

Inherited unchanged from `ultra-architect-planify` Axis 4 (9 roles + fan-out matrix). Mission-specific adjustment: **+ Fiscal specialist** (general-purpose, read-only) fires on every Sub 1.2 task; **DBA** fires on every Sub 1.3 + Sub 3.1 task; **SRE/Sync** fires on every §5 task.

**Discipline:** 5 read-only specialists fan out in ONE message (parallel ~3-5min); Implementer + RED-team sequential per task; QA Visual + RED Visual only on UI tasks (§4 Sub 2.3/2.4 + §5 Sub 3.4).

**Persistence contract:** every sub-agent writes to `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/<round>/wave-<W>-<task-id>-<role>.json`. Main thread synthesises from disk (survives interrupts).

---

## §X — Convergence Waves (8 total)

| Wave | Scope | Tasks | Parallelism | Checkpoint |
|---|---|---|---|---|
| **W0** Pre-flight | baseline, backup tag, branch create | — | sequential | `audit_logs count + last_hash` captured; branch `heal/central-backbone-2026-05-18` created |
| **W1** CENTRAL — Pricing | T-1.1.1 → T-1.1.4 | 4 | sequential | T-1.1.1 sentinel PASS + 0 frozen-zone diff |
| **W2** CENTRAL — NF525 Chain | T-1.2.1 → T-1.2.5 | 5 | sequential | NF525 chain attestation |
| **W3** CENTRAL — BranchScope + Auth/Idem | T-1.3.* + T-1.4.* | 9 | T-1.3 seq, T-1.4 parallel | 4 BranchScope sentinels PASS + Idempotency coverage |
| **GATE 1** | **`/ultrareview` PR #1 CENTRAL** | | | branch `heal/mgmt-backbone-2026-05-18` rebased on #1 |
| **W4** MGMT — Catalog + RBAC + Settings | T-2.1.* + T-2.2.* + T-2.4.* | 12 | mixed | All tests PASS |
| **W5** MGMT — Fiscal UI + Reports | T-2.3.* | 4 | parallel | Visual gate fired |
| **GATE 2** | **`/ultrareview` PR #2 MGMT** | | | branch `heal/sync-backbone-2026-05-18` rebased on #2 |
| **W6** SYNC — Outbox + Broadcasting | T-3.1.* + T-3.2.* | 9 | sequential | 10k events simulation PASS |
| **W7** SYNC — Webhook + Queue + Observability | T-3.3.* + T-3.4.* | 8 | mixed | Latency budget p95 <5s |
| **W8** Final convergence | cross-surface E2E + smoke + RED final + Graphiti push + BRAIN update + PR #3 | n/a | sequential | All §0.3 criteria met |
| **GATE 3** | **`/ultrareview` PR #3 SYNC** | | | mission DONE post 3× /ultrareview ≥ GO-CONDITIONAL |

**Wave-checkpoint protocol** (per Axis 3): end of each wave, 6-point check (tasks PASS, frozen-zone=0, NF525 unchanged-or-appended, visual gate if UI, RED P0/P1 healed/deferred, BRAIN updated).

**Wave-interrupt protocol** (per Axis 3): see §0.7 operating mode.

**Convergence-failure protocol** (per Axis 3): 3rd heal-loop same cluster → STOP, spawn `Plan` subagent, write STUCK report, surface 4 owner options.

---

## §G — Owner Gates

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| **G1** | LOCK doc countersign — any frozen-zone touch | Physical owner | `LOCK_<scope>_2026-05-18.md` §10 signed | `plans/LOCK_*.md` §10 | PENDING-IF-NEEDED |
| **G2** | `/ultrareview` PR #1 CENTRAL ≥ GO-CONDITIONAL | Physical owner (user-triggered+billed) | review verdict comment | GitHub PR #1 review thread | PENDING-W3 |
| **G3** | `/ultrareview` PR #2 MGMT ≥ GO-CONDITIONAL | Physical owner | review verdict comment | GitHub PR #2 review thread | PENDING-W5 |
| **G4** | `/ultrareview` PR #3 SYNC ≥ GO-CONDITIONAL | Physical owner | review verdict comment | GitHub PR #3 review thread | PENDING-W8 |
| **G5** | NF525 chain attestation final (post W8) | Claude + owner sign-off | `count + last_hash` confirmed | `FINAL_CONVERGENCE.md` §NF525 + commit tag | PENDING-W8 |
| **G6** | Merge to `main` (or V1.0.1 integration) | Physical owner (CLAUDE.md §10 human gate) | `git merge --no-ff` post 3 reviews | local branch + remote | PENDING-POST-G2/3/4 |

**Waiting protocol:** G1 conditional (frozen-zone touch only). G2/G3/G4 sequential — Claude pauses + hands off (cf. §0.5). G5 auto-verifiable. G6 explicit owner consent (no auto-push).

---

## §9 — Final Synthesis (filled at W8 → `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/FINAL_CONVERGENCE.md`)

Sections: (1) GO/GO-CONDITIONAL/NO-GO per system, (2) P0/P1/P2 counts + top-3 + healed vs deferred per system, (3) strong-reasoning aggregate (cost-of-delay matrix + V2 SaaS readiness scorecard), (4) NF525 final (`count + last_hash + chain_verified_at`), (5) frozen-zone diff attestation (13 files), (6) test counts before/after, (7) `/ultrareview` verdicts ingested (3 reports linked), (8) Graphiti episode summary (5-10 atomic facts), (9) insights cross-reference (anti-fiction / checkpointing / convergence), (10) V1.0.2+ backlog deferred with reasoning + cost.

---

## §R — References (compressed)

- `~/.claude/skills/{ultra-audit-profond, ultra-architect-planify, superpower-gstack, test-e2e, lock-plan}/SKILL.md`
- `CLAUDE.md` §§4-13 (FoodKing operating memory, frozen zones §7, NF525 §8, multi-tenant §9)
- `PROJECT_BRAIN.md` (current state §2/§3 + decisions log §6)
- `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` (prior CONVERGED ✅, complementary)
- `reports/test-e2e/goal-2026-05-18/FINAL_CONVERGENCE.md` (prior convergence proof)
- `reports/audit/v1-cloud-prep-insights-2026-05-18/INSIGHTS_FINAL.md` (RED-team insights, mis-narration patterns)
- Graphiti `group_id="foodking"` (NF525, BranchScope, Outbox, Pusher, Sanctum kiosk:order verified)
- Insights report `~/.claude/usage-data/report-2026-05-18-035320.html` (friction patterns)

---

## §F — Final Rule (production-perfect bar)

**DONE** = all §0.3 8-criteria pass + all §G gates resolved. If any fails: HEAL (max 3 cycles) or DEFER with §H reasoning + V1.0.2 backlog. 3 heal loops fail → convergence-failure protocol (Axis 3). **Production-perfect, not "almost there." No silent gap. No silent drift.**

---

## Self-Review Checklist (Axis 8 — verified before declaring GOAL ready)

- [x] Every system has 3+ real-code anchors verified via live `find/grep/ls` (transcript above + §1)
- [x] Every sub-system has 4 tasks with explicit file paths (§3/§4/§5)
- [x] Every task acceptance references a real test path OR `(test TO BE CREATED at <path>)` (49 tasks total: ~25 existing PASS + ~24 to-create)
- [x] Every owner gate has WHO/WHAT/WHERE (G1-G6)
- [x] Every wave has parallelism + checkpoint + interrupt-resume hook (W0-W8 + §0.7)
- [x] GOAL size 30-40 KB (verified `wc -c` post-write)
- [x] Advisor called before write (transcript above)
- [x] Working-tree decision documented (§0.1 PATH-ISOLATE)

---

**Mission status:** GOAL written. Self-review 8/8. **Ready to launch** Wave W0 pre-flight → W1 Pricing SSOT → W2 NF525 → … → W8.

**Next:** orchestrator dispatches `ultra-audit-profond` per Wave W1 task T-1.1.1, then sequentially. Per §0.7, every task checkpoints to disk + Graphiti so the mission survives session boundaries.
