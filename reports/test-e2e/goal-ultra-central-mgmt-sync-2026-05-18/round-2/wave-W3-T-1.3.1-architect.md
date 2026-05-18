# Wave W3 — T-1.3.1 Architect Audit (Round 2)
## BranchScope Exhaustive Coverage

**Mission:** `goal-ultra-central-mgmt-sync-2026-05-18` | Round 2 / W3 / T-1.3.1
**Role:** ARCHITECT (read-only — Read + Bash grep)
**Anchors:** `app/Models/Scopes/BranchScope.php` (frozen §7), `app/Models/*.php`, `tests/Feature/BranchScopeTest.php`
**Cross-ref Round 1:** `DBA-002 — BranchScope on DomainEvent deferred V1.0.2` — this audit recommends pulling forward (see F1).
**Date:** 2026-05-18

---

## Verdict — **NO-GO V1 ABSOLUTE-AS-IS**

The 17 declared `addGlobalScope(new BranchScope())` match the CLAUDE.md §9 list (+ ItemWizardProfile via specialised `WizardProfileBranchScope`). **But 11 additional models reference `branch_id` without any global scope.** Two (`AuditLog`, `ZReport`) are NF525-critical. Three (`Message`, `KioskPromo`, `UpsellRule`, `FrontendDiningTable`) are business-data with no documented exception. Defence relies on every developer remembering `where('branch_id', ...)` at each read site — the exact F-A3 pattern that already leaked through ActionLog before heal at `DashboardService.php:367`. No sentinel test enforces "new model with `branch_id` declares BranchScope or is in explicit allowlist". V1 Le Cayenne single-resto unexposed (1 tenant), but invariant broken; V2-SaaS inherits hidden leak surface.

---

## §1 — Coverage Map

### §1.1 — 17 declared + 1 specialised (GREEN)
Order:92, OrderItem:27, OrderPayment:67, OrderQuote:22, KioskMachine:38, StockLevel:25, StockMovement:23, CashDrawerSession:68, CashMovement:59, PendingPaymentConfirmation:24, PushNotification:31, DiningTable:30, Printer:41, User:90 (short-circuited at `BranchScope.php:21-23` for Sanctum recursion), FrontendOrder:23, PosParkedOrder:40, PaymentTerminal:69. ItemWizardProfile uses `WizardProfileBranchScope` on `branch_id_scope` column (NULL=global) — correct.

### §1.2 — 11 gap models (REVIEW)
| # | Model | branch_id | Documented? | Risk |
|---|---|---|---|---|
| 1 | **AuditLog** | `:21,35` | NO | **NF525 P0** |
| 2 | **ZReport** | `:19,46` | NO | **NF525 P0** |
| 3 | **DomainEvent** | `:16` | DBA-002 defer | **Outbox P1** |
| 4 | **ActionLog** | `:14` (manual WHERE healed F-A3 at DashboardService:367) | Implicit | **P1 — leak on new callsite** |
| 5 | **KioskPromo** | `:42,49,79,114` | NO | **P1 — promo cross-tenant** |
| 6 | **UpsellRule** | `:45,57,84` | NO | **P1 — upsell cross-tenant** |
| 7 | **Message** | `:13,16` | NO | **P1 — chat cross-tenant** |
| 8 | **FrontendDiningTable** | `:13,20` (table=`dining_tables`, alias to scoped DiningTable) | NO | **P1 — alias bypass** |
| 9 | **DiningTableAuditLog** | `:14,25` | NO | **P2** |
| 10 | **ItemBranchAvailability** | `:14` | Overlay (joined via item_id) | **P2** |
| 11 | **Customer** | `:17` extends User | Inherits Sanctum bypass | **P2** |

WebhookEvent has NO `branch_id` column at all; design documented `app/Models/WebhookEvent.php:43-45` — correct.

### §1.3 — Catalog-global (no `branch_id`, correct)
`Item`, `ItemCategory`, `ItemAttribute`, `ItemExtra`, `ItemVariation`, `Ingredient`, `Addon`, `Tax` — grep returns 0. Only overlay today is `item_branch_availability`. **Round 1 MGMT-P0-B (Ingredient DoS)** proposed `ingredient_branch_availability` analogue — confirmed canonical pattern. But `ItemBranchAvailability` itself lacks BranchScope, defeating its own purpose if queried `where('item_id', X)->first()` without branch.

---

## §2 — Top 3 Findings

### F1 — AuditLog + ZReport unscoped on NF525-critical tables (P0)

```yaml
finding_id: T-1.3.1-ARCH-F1
severity: P0
trigger:
  - AuditLog fillable+cast branch_id (app/Models/AuditLog.php:21,35), no scope
  - ZReport identical (app/Models/ZReport.php:19,46); migration unique(branch_id, sequence_no) line 62
  - ZReportController::index manual where('branch_id', $branchId) line 29; ::show abort_if line 62
  - OrderDetailsResource::buildAuditFingerprint reads AuditLog by resource_id WITHOUT branch_id filter (line 88-92)
failure_mode:
  v1: |
    Le Cayenne 1-tenant — not exploitable today. But OrderDetailsResource
    queries AuditLog by (resource, resource_id) only. If a cross-tenant
    Order id collision ever appears (multi-DB merge, backup-restore,
    V2 migration), wrong chain hash returned to wrong client.
  v2_saas: |
    Hard fail SaaS day-1. DGFiP chain inspection requires "chain of
    branch X" — global query picking branches Y..Z = non-compliant.
v2_saas_impact: BLOCKER
cost_of_delay:
  fiscal: Art. 1729 D CGI — 5000€/exercice per branch on cross-contamination
  business: NF525 chain claim invalidated; visible compliance badge falsified
  customer: Trust collapse on disclosure; competitor messaging window
  cross_tenant: YES (latent; safety-net = developer memory, same as F-A3)
recommendation:
  - Add addGlobalScope(new BranchScope()) to AuditLog + ZReport. Read-only filter; INSERT path unaffected; admin (branch_id=0) sees all.
  - Sentinel: actingAs(staff_branch_B) -> ZReport::find($zr_branch_A_id) must return null
  - SEQUENCING - lands AFTER Round 1 CENTRAL-P0-A env() cache fix. Otherwise per-branch HMAC verify breaks under config:cache.
owner_gate: N (additive; no behavior change for admin)
heal_effort: 1h (2 lines per model + 2 sentinel tests)
LOCK_required: N
sentinel_test: tests/Feature/Multitenant/FiscalBranchScopeTest.php
```

### F2 — Sentinel test absent: new branch_id model can ship unscoped undetected (P0)

```yaml
finding_id: T-1.3.1-ARCH-F2
severity: P0
trigger:
  - tests/Feature/BranchScopeTest.php tests Order behavior only — no coverage assertion
  - tests/Feature/Multitenant/WizardProfileBranchScopeTest.php per-model only
  - Live grep gap = 11 models (§1.2)
  - Round 1 DBA-002 (DomainEvent gap) had no CI signal — caught only by audit
load_mode: CI guardrail (compile-time)
failure_mode:
  v1: |
    Adverse selection: diligent dev adds scope; new dev forgets. Every
    PR adding model with branch_id (CV1 backlog mentions SkillProgress,
    FormulaInstance, FrequencyRecord) ships unscoped unless author
    remembers + reviewer catches.
  v2_saas: |
    1-2 new models/release on multi-tenant. Each gap = 1 sprint leak
    window. SaaS competitor parity needs deterministic coverage.
v2_saas_impact: BLOCKER (process at scale)
cost_of_delay:
  cross_tenant: YES (model-dependent severity)
  business: First SaaS customer leak = irrecoverable reputation
recommendation: |
  Add CI-blocking sentinel tests/Feature/Multitenant/BranchScopeCoverageSentinelTest.php:
  1. Schema::getAllTables → filter tables with branch_id column
  2. Resolve table → model (registry or glob app/Models)
  3. Assert $model->getGlobalScopes() contains BranchScope OR is in ALLOWLIST
  4. ALLOWLIST entries each documented in docs/multitenant/BRANCH_SCOPE_ALLOWLIST.md
     (User/Customer = Sanctum; ActionLog = manual scope; AuditLog/ZReport/DomainEvent
     marked "TODO heal V1"; ItemBranchAvailability = overlay joined; etc.)
  Sentinel ~10ms, schema reflection only.
  Adding new allowlist entry → owner-gated commit (CR by owner).
owner_gate: N
heal_effort: 2h (test + allowlist doc + CI plumbing)
v1_blocker: YES — without sentinel, F1 heal can silently regress
```

### F3 — `withoutGlobalScope` audit: 45 callsites, 1 latent risk (P1)

```yaml
finding_id: T-1.3.1-ARCH-F3
severity: P1
trigger:
  - grep returns 45 callsites across app/
  - Categorised - 14 controller/service pre-auth (kiosk login, payment reconcile, order destroy);
    10 fiscal-archive cron commands; 9 ZReportService + ZReportCashEnrichmentService callsites;
    6 EnsureXLogin bootstrap commands; 1 BackfillAllergensSnapshot with explicit comment
failure_mode:
  v1: |
    Safe today. No HTTP withoutGlobalScope returns data to non-admin
    requester. PaymentReconcile locks by id+amount; PosOrderController:113
    admin-only. All service-tier callers admin-gated upstream.
  v2_saas: |
    Service-tier OrderService/SplitPaymentService callsites return data
    to caller. Currently admin-checked; future "tenant admin" role weaker
    than super-admin could expose.
  latent: |
    ZReportService walks Order::withoutGlobalScope to compute Z totals
    for $branchId. No assertion $branchId > 0. Admin sentinel id (0)
    leaking into per-branch op = cross-tenant fiscal sum violation.
v2_saas_impact: WARN (mitigable at V2 role-audit)
recommendation:
  - At each fiscal withoutGlobalScope - abort_if($branchId <= 0, 422, 'Fiscal ops require positive branch_id')
  - Tag every legit withoutGlobalScope with `// [BRANCH-SCOPE-BYPASS - reason]` comment
  - F2 sentinel extension - grep withoutGlobalScope, assert tag within 3 lines above
owner_gate: N
heal_effort: 3h (45 tags + grep sentinel + 1 service assertion + 1 test)
sentinel_test: tests/Feature/Multitenant/BypassReasoningSentinelTest.php
```

---

## §3 — Cross-Reference to Round 1

| Round 1 finding | Round 2 impact |
|---|---|
| **DBA-002 DomainEvent defer V1.0.2** | **Upgrade P1 — pull forward to V1.** SyncOverviewController:384 lists DomainEvents unfiltered; DispatchDomainEventsJob re-processes by id with no tenant assertion; broadcast channel name from `payload.channel` → misconfigured pattern could broadcast cross-tenant. Heal ~1h. |
| **CENTRAL-P0-A env() cache** | **Sequencing constraint:** F1 heal MUST land AFTER P0-A or per-branch HMAC verify breaks under prod config:cache. Document in PR scope. |
| **MGMT-P0-B Ingredient DoS overlay** | **Pattern parallel.** New `ingredient_branch_availability` model must declare BranchScope from day one. F2 sentinel guarantees catch. |
| **SYNC-P0-D Listeners afterCommit** | **Reinforces F1.** Phantom DomainEvent rows after rollback carry branch_id payload; without scope, retroactive audit is harder. Scope + afterCommit refactor together. |

---

## §4 — Sentinel Test Architecture (F2 detail)

```php
final class BranchScopeCoverageSentinelTest extends TestCase {
    private const ALLOWLIST = [
        User::class => 'Sanctum guard recursion (BranchScope.php:21-23)',
        Customer::class => 'Extends User; same exemption',
        ActionLog::class => 'Manual WHERE at every read; paired DashboardService.php:367 + SloMetricCollector.php:75,96,144',
        Message::class => 'TODO heal V1.0.2',
        FrontendDiningTable::class => 'TODO heal V1.0.2 — alias for dining_tables (scoped via DiningTable)',
        DiningTableAuditLog::class => 'TODO heal V1.0.2',
        KioskPromo::class => 'TODO heal V1.0.2',
        UpsellRule::class => 'TODO heal V1.0.2',
        ItemBranchAvailability::class => 'Overlay; queried via where(item_id) joined',
        DomainEvent::class => 'TODO heal V1 — Round 1 DBA-002 upgraded',
        AuditLog::class => 'TODO heal V1 — T-1.3.1-ARCH-F1',
        ZReport::class => 'TODO heal V1 — T-1.3.1-ARCH-F1',
    ];
    public function test_every_branch_id_table_resolves_to_scoped_or_allowlisted_model(): void {
        // Schema::getAllTables → filter has 'branch_id' column → resolve model → assert scope OR allowlist
    }
}
```

Catches BranchScope regressions at PR time. Adding allowlist entry = owner-CR commit.

---

## §5 — Open Questions for Owner Gate

1. **PR sequencing CENTRAL-P0-A vs F1:** Chain in PR #1 or split into PR #1.5? Owner gate.
2. **DomainEvent V1 vs V1.0.2:** Round 1 deferred; Round 2 recommends V1 (1h, phantom-broadcast risk). Owner pull-forward?
3. **`ItemBranchAvailability` scope:** Intentionally unscoped (joined via Item)? If yes, permanent allowlist. If no, scope it. Architect call.
4. **Customer model inheritance:** Pre-V2 dormant; V2 customer-directory per-tenant. Decide at V2 cutover.
5. **Tag-comment policy for `withoutGlobalScope`:** F3 grep sentinel = +200ms/push. Adopt?

---

## §6 — Audit Trail

```
grep -l "branch_id" app/Models/*.php | sort                                  # 29 files
grep -l "addGlobalScope.*BranchScope" app/Models/*.php | sort                # 18 files (17 std + 1 WizardProfile)
comm -23 (branch_id-set) (scope-set)                                         # 11 gap files (§1.2)
grep -rn "withoutGlobalScope" app/ --include="*.php" | wc -l                 # 45 callsites
```

All confirmations Read + Bash grep only. Zero writes.

**Heal path:** F1 (1h) + F2 (2h) + F3 (3h) = ~6h. Unlocks V1 single-tenant safety net + V2 SaaS deterministic coverage.

— Architect / T-1.3.1 / 2026-05-18
