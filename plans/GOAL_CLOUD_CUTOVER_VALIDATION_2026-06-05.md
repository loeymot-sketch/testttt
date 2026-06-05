# GOAL — CLOUD CUTOVER VALIDATION (Le Cayenne V1 LOCAL → cloud-ready certification)

> **Mission type:** VALIDATION / CERTIFICATION campaign (NOT a dev cycle). The system is
> already very green (PHPUnit 2857/0, Vitest 1895/0, sync live-proven, 5 surfaces clean,
> 63-functionality sweep 0 new P0/P1, 19/19 pre-cloud P1 resolved/deferred). The job is to
> **prove cloud-readiness exhaustively** — abuse + E2E + visual + technical + functional +
> synchronization — across every system and every page, then assemble the cutover dossier.
>
> **Author:** Claude orchestrator · **Date:** 2026-06-05 · **Worktree:** `pre-cloud-exec`
> branch `heal/pre-cloud-exec-2026-06-05` · HEAD `1ef8e5ca6`. **No push** (owner gate G-PUSH).

---

## §0. Preamble

### §0.1 Working-tree decision
Validation is **read-only-first**: audits + tests + Playwright captures dominate; code edits
happen ONLY for a finding that passes the §0.3 vision-triage as a **V1-LOCAL blocker**. Frozen
edits require LOCK + explicit owner countersign (the discipline just exercised for M6-002).
Pre-existing working-tree noise (`public/js/vendor.js`, `.playwright-mcp/*.yml`) is out of scope;
do not stage it. Backup branch `backup/pre-cutover-validation-2026-06-05` at W0.

### §0.2 Convergence criteria (READ TWICE — this is how the campaign ends)
The Stop-hook bar is "ready to go to the cloud." That is **green + gates-surfaced**, NOT
"deployed" — physical-owner blockers (server access, push auth, real-hardware E2E) cannot be
cleared by the orchestrator. **DONE =**
1. Every system green across the 6 dimensions (technical/interface/functional/sync/visual/abuse),
   page-by-page, **two consecutive cycles with P0+P1 = 0 and identical findings sets** (flake guard).
2. **Cloud-delta resolved-or-LOCK-gated** (Wave 1) — the genuinely-new cloud risks closed or
   documented with an owner gate.
3. A clean **GO/NO-GO cutover dossier** (Wave 8) enumerating every owner gate WHO/WHAT/WHERE.
Anything short of this on an autonomously-reachable item → REJECT + heal (Axis 6 rules apply literally).

### §0.3 ⭐ VISION-TRIAGE GATE (structural — runs before ANY heal)
"Maximum abuse on a green system manufactures findings." Owner mandate (repeated): *"acte comme
superviseur, pas bête dev"* — filter every finding against the V1-LOCAL envelope BEFORE healing.
**Every audit/RED finding is triaged into exactly one bucket:**
- **🔴 V1-LOCAL BLOCKER** → heal now (TDD, scope-minimal, vision-respecting). Money/fiscal/data-loss/
  crash/security on the single-box Le Cayenne path.
- **☁️ CLOUD-PREP** → route to Wave 1 cloud-delta backlog (config:cache, multi-worker, ALB, secrets-rotation).
- **📋 POST-V1 / UNREACHABLE** → document in backlog, do NOT heal (the S13-02 / M3-01 / M8-01 / M3-02 pattern).
A finding may NOT skip triage. Reachability must be proven (DB query / config / trace), not assumed.

### §0.4 Per-task pipeline (do NOT re-describe here)
Each task delegates to `ultra-audit-profond` (5-specialist read-only fan-out → synthesise → implement
→ RED → test → visual → adversarial-visual). Page audits use `test-e2e` (dual-team). Frozen overrides
use `lock-plan`. Abuse methodology = `memory/feedback_adversarial_audit_pattern.md`.

### §0.5 Vision invariants (never violate)
V1 = **personal single-box Le Cayenne**, FR locale, `branch_id=1`, TPE = manual SumUp alternative
(NOT going live — [[feedback_terminals_manual_encaissement_unified_2026-06-05]]), kiosk card → counter
(Plan B). SaaS/multi-tenant = future, never a V1 blocker. Frozen zones (§7) + NF525 (§8) intact.
Anti-duplication: this GOAL is the authoritative spec; the approved remote PR is one validated execution.

---

## §1. Map principal — 5 systems (anchors grep-verified @ HEAD 1ef8e5ca6 via SYSTEM_MAP.md)

| # | System | Maturity | Real-code anchor (verified) | Tests (verified `ls`) |
|---|---|---|---|---|
| 1 | **BORNE (Kiosk)** | mature, frozen-protected | `resources/js/components/frontend/kiosk/**`, bundles `public/js/kiosk-{shell,wizard,wizard-step,errors}.js` (4), `app/Services/Kiosk/**`, `KioskMachineLoginController.php`, routes `api.php:167/205` | `tests/Feature/Kiosk/` (4 files) + e2e specs |
| 2 | **CAISSE (POS)** | mature, frozen wizard | `resources/js/components/admin/pos/**`, `pos-app.js`, bundle `pos-shell.js`, `Admin/Pos/**`+`AdminPosV4Controller`, `PaymentService`/`SplitPaymentService`/`CashDrawerService`, routes `api.php:792/971/1156` | `tests/Feature/Pos/` (16) + `Cash/` (12) |
| 3 | **KDS + OSS** | mature, auditable | `components/admin/{kitchenDisplaySystem,orderStatusScreen}/**`, bundles `admin-kds.js`/`admin-oss.js`, `KitchenDisplaySystemOrderService`, `KitchenDisplaySystemController`/`KdsSyncController`/`OrderStatusScreenController`, routes `api.php:1168/1216/~1244` | sync + kds e2e specs |
| 4 | **CENTRAL (Admin)** | mature, broad | `app/Http/Controllers/Admin/**` (91 direct + subdirs), `components/admin/**` (ex-POS/KDS dirs), bundles `admin-shell.js`(117 chunks)/`admin-reports.js` | `tests/Feature/Sentinels/` (97), Branch (6), broad |
| 5 | **WEB+APP (standalone)** | standalone, NO wireup V1 | `/Users/1millnonstop/Downloads/web/**`, `mobile/**`, backend storefront `components/frontend/**` (≠kiosk) | standalone visual specs |
| — | **Cross-cutting SHARED** | FROZEN/critical | **FISCAL** `Services/Fiscal/{FiscalSequence,ZReport,AuditLog}` (53 fiscal tests) · **SYNC** bus `Events/{OrderCreated,OrderStatusChanged,KdsOrderRecalled}`+outbox+soketi · **AUTH** `BranchScope`/Sanctum/`IdempotencyKeyMiddleware` · **PRICING** `PricingService` | Fiscal/Sync/Refund/Sentinels |

## §2. Map separated (out of cloud-cutover critical path)
Web (`/Downloads/web`) + mobile (`mobile/`) are **standalone, NO API wireup in V1** (owner mandate).
They ride the cutover only as static assets if at all; their validation = visual/parity only (prior
GO-CONDITIONAL). **The cloud cutover subject is the backend `testttt` system (1–4 + cross-cutting).**
Mobile palette = NOIR/ORANGE/JAUNE/BLANC (never Cayenne red). Not a V1 cloud blocker.

---

## §3. Cross-cutting FISCAL / NF525 (highest-criticality — validated first under abuse)
### Contract
100% pricing backend (`PricingService::calculateOrder`); `fiscal_sequence_no` monotonic gap-free per
branch; `audit_logs`+`z_reports` HMAC chain (prev→current); 6-yr retention; M6-002 per-tranche Z bucketing applied.
### Frozen zones
`FiscalSequenceService`, `ZReportService`, `AuditLogService`, audit/z triggers (§7) — LOCK to touch.
### Sub-systems
- **3.1 Chain integrity** — T-3.1.1 chain verify --all green (anchor `fiscal:verify-chain`; test `tests/Feature/Fiscal/FiscalSealingHmacTest.php`) · T-3.1.2 delete/truncate trigger blocks (`ZReportDeleteTriggerMysqlOnlyTest.php`) · T-3.1.3 sequence gap-free under concurrency (`F001KioskFiscalSequenceInvariantSentinelTest`).
- **3.2 Z close correctness** — T-3.2.1 split bucketing M6-002 (`ZReportSplitPaymentBucketingTest` 3/3) · T-3.2.2 discount netting F1 (`ZReportDiscountNettingTest`) · T-3.2.3 refund mirror nets to 0 (`RefundMirrorSplitPaymentTest`).
- **3.3 Cloud-delta fiscal** → **see Wave 1** (config:cache × chain, `AuditLogService:273` env() triage).
### Acceptance
`tests/Feature/Fiscal/` + `tests/Feature/Refund/` green; chain identical before/after each wave; frozen-diff 0.

## §4. Système BORNE (Kiosk)
### Contract / Frozen
Self-service order→pay→KDS. Frozen: `Kiosk{Wizard,App,Upsell}Component.vue` (auditable w/ care).
### Sub-systems
- **4.1 Wizard composition** — T-4.1.1 4 templates (sandwich/taco/bowl/menu) 0 raw label · T-4.1.2 profile mirror DB↔wizard · T-4.1.3 allergens/upsell. anchor `KioskWizardComponent.vue`; visual `http://127.0.0.1:8000/kiosk/idle`; test `tests/Feature/Kiosk/` + e2e.
- **4.2 Order create + Plan-B payment** — T-4.2.1 card→counter route (`kiosk.payment_route_all_to_counter`) · T-4.2.2 cash auto-accept→KDS · T-4.2.3 token branch-scoped (`kiosk:order` ability). test `(TO BE CREATED at tests/Feature/Kiosk/KioskPlanBRouteTest.php)` if absent.
- **4.3 Offline resilience** — T-4.3.1 offline queue (`kioskOfflineQueue.js`) replays, no dup · T-4.3.2 idempotency on replay.
### Acceptance
Kiosk e2e GREEN 4 templates; OrderCreated→KDS proven (Wave 4); frozen-diff 0.

## §5. Système CAISSE (POS)
### Contract / Frozen
Payment + cash + fiscal terminal. Frozen STRICT: `pos-wizard.js`, `pos-wizard.css`, `admin-pos-v4.blade.php`; Frozen-gate: `PaymentComponent.vue`, `PosV5TrancheRow.vue`.
### Sub-systems
- **5.1 Encaissement unified** — T-5.1.1 `PosCounterCollectModal` 4-mode (Espèces/TR/Terminal-manuel+réf) [G-H delivered] · T-5.1.2 split-payment guard (M6-001) · T-5.1.3 no-drawer cash trail (M10-01 `cash_movement_skipped_at`). test `tests/Feature/Cash/`, `tests/Feature/Pos/`.
- **5.2 Operator identity NF525** — T-5.2.1 receipt operator=cashier not customer (M11-01/S11-02/S16-01: `editor_id??creator_id`, never `$order->user`). test `tests/Feature/Pos/` receipt.
- **5.3 Cash session / Z** — T-5.3.1 drawer open/close reconcile · T-5.3.2 no-sale audit (M1-01) · T-5.3.3 Z close at counter-PAID alloc.
### Acceptance
`tests/Feature/Pos/`+`Cash/` green; POS visual `http://127.0.0.1:8000/admin/pos` clean; frozen-diff 0.

## §6. Système KDS + OSS
### Contract / Frozen
Kitchen display + customer status. No frozen inside.
### Sub-systems
- **6.1 KDS board** — T-6.1.1 order card render (customization helper sandwich/taco/etc.) · T-6.1.2 bump/recall (KdsOrderRecalled→outbox) · T-6.1.3 recall surfaces dropped items (M7-02). anchor `KitchenDisplaySystemController`; visual `/kds`.
- **6.2 OSS wall** — T-6.2.1 public feed render (poll 60s) · T-6.2.2 no-PII (HIST-13 pattern) · T-6.2.3 prepared/ready transition. anchor `OrderStatusScreenController`; visual `/admin/order-status-screen`.
- **6.3 Cross-surface consume** — see Wave 4 (live sync).
### Acceptance
KDS/OSS visual clean; recall + status e2e green; OSS no-PII verified.

## §7. Système CENTRAL (Admin / management)
### Contract / Frozen
Catalogue, dashboard, history, settings, users, reports, stock. No specific frozen.
### Sub-systems
- **7.1 Catalogue + stock** — T-7.1.1 45-item SSOT render · T-7.1.2 rupture dashboard + availability sync (`item_branch_availability`) · T-7.1.3 catalogue CRUD authz. visual `/admin/items`, `/admin/stock-rupture-dashboard`.
- **7.2 Dashboard + history** — T-7.2.1 KPI net-realized semantics (DASH-NET-01) · T-7.2.2 history snapshot immutable (HIST-10) · T-7.2.3 RBAC admin vs POS (DASH-T12). visual `/admin/dashboard`.
- **7.3 Settings + users + reports** — T-7.3.1 gateway-secret index gated (SET-01) · T-7.3.2 RBAC role-grant (USR-RBAC) · T-7.3.3 sales/items report realized-only.
### Acceptance
Reachability 27/27 nav→working page (0 orphan, DASH-T10); RBAC matrix; visual clean.

---

## §A. Agent army map + fan-out matrix
Roles per `~/.claude/skills/ultra-architect-planify` Axis 4 (Architect/Security/UX-A11y/DBA/SRE-Sync/
Implementer/RED/QA-Visual/RED-Visual). **Reports persist to disk** `reports/test-e2e/cutover-validation/<wave>/wave-<W>-<role>.{json,md}` (survive interrupts; main thread synthesises from disk).
Fan-out matrix (which roles fire) = the skill's Axis 4 table. Dispatch discipline: 5 read-only specialists
in a SINGLE message (parallel); Implementer never parallel with Implementer; QA-Visual ∥ RED-Visual OK;
RED dispute ALWAYS after implement, before DONE. Workflow orchestration allowed (owner opted into "maximum
orchestration") — `Workflow` for fan-out/dispute pipelines, with the §0.3 triage between audit and heal.

## §X. Convergence waves (W0–W8)

| Wave | Scope | Parallelism | Checkpoint (6-pt: pass/frozen-0/chain/visual/RED/BRAIN) |
|---|---|---|---|
| **W0 Pre-flight** | baselines (PHPUnit/Vitest counts), backup branch + DB dump, infra health (server:8000+soketi:6001+queue+redis UP), chain attestation `count+MAX(hash)`, frozen-diff baseline, owner-gate enumeration | sequential | infra all UP; baselines captured; chain OK |
| **W1 ☁️ CLOUD-DELTA** (⭐ highest-value) | the genuinely-NEW cloud risks: (a) `php artisan config:cache` → `fiscal:verify-chain --all` MUST stay green (the `AuditLogService:273` env() trap) + branch-secret-override reachability triage; (b) env() runtime sweep (16 files) — classify each as cloud-safe vs config:cache-fragile; (c) cache-driver multi-worker (UNI-03: file/database pass guard but break `Cache::lock` coherence) — document ALB requirement; (d) boot guards FIRE in prod env (PAYMENT/PRINTING bypass, APP_DEBUG, BROADCAST, QUEUE, CACHE) via `APP_ENV=production` dry boot; (e) printer ESC/POS TCP:9100 reachability model (hybrid local-node). **Each finding → §0.3 triage.** Frozen fixes (AuditLogService) → LOCK + countersign. | sequential (fiscal-adjacent) | config:cache×chain green OR LOCK-gated fix; env() sweep classified; UNI-03 documented; **restore non-cached config after** |
| **W2 Fiscal abuse** | NF525 §3 under adversarial abuse: sequence gap-free under concurrency, chain delete/truncate blocked, M6-002 split bucketing, refund-mirror net-0, discount netting, Z boundary windows | sequential | `tests/Feature/Fiscal/`+`Refund/` green; chain identical; RED 0 P0 |
| **W3 Per-system audit** | systems §4–§7 (BORNE/CAISSE/KDS+OSS/CENTRAL) read-only fan-out (5 specialists each) + RED dispute + **§0.3 triage** → heal only V1-LOCAL blockers | 5 read-only ∥ within wave; heals sequential | each system audited; findings triaged; 0 un-triaged |
| **W4 SYNC live under chaos** | closes SYNC-E2E-01: soketi-UP live **borne order → KDS appears → bump → OSS/tracker update** (real WS push proof) + status cascade; then **chaos**: kill queue:work → poll fallback no-loss → recover replay; kill soketi → circuit-breaker poll → recover; outbox staleness monitor | sequential (shared bus) | live cascade proven (WS receipt evidence); degradation = no data loss; recovery replays |
| **W5 E2E + visual real-web** | Playwright per surface page-by-page: kiosk/idle, admin/pos, login, items, stock-rupture, kds, order-status-screen, dashboard, history — QA-Visual ∥ RED-Visual dispute; 0 raw label/console error/layout break | QA∥RED visual | every surface screenshot Read+analyzed, 2 consecutive clean cycles |
| **W6 Abuse / chaos / resilience** | maximum abuse: concurrent order load (stress), duplicate POST (idempotency 409), offline kiosk queue replay, branch isolation probes (20-model scope), brute-force lockout, payment edge cases, parked-order retry-404 (M7-02) | sequential | abuse specs green; 0 data corruption; isolation holds |
| **W7 Page/system final certification** | each page of each system re-validated to 100%; cross-surface E2E (Kiosk→KDS→OSS→Livreur); 2-consecutive-cycle P0+P1=0 identical sets | sequential | full convergence per §0.2 |
| **W8 ☁️ Cutover dossier + gates** | assemble GO/NO-GO: credentials checklist, server/OVH details (owner-provided), deploy runbook, env production template, the cloud-delta resolutions, owner gates WHO/WHAT/WHERE. **owner-gated** | sequential | dossier complete; gates enumerated; NO auto-push |

**Wave-interrupt protocol** (Axis 3): on usage-limit/session-boundary → WIP commit `wip(<wave>): partial`, write `reports/test-e2e/cutover-validation/INTERRUPT_<wave>_<ts>.md` (last green SHA, last task, next task), update BRAIN §2, resume via manifest + 1-task smoke.
**Convergence-failure protocol**: 3rd heal-loop on a cluster → STOP, spawn `Plan` subagent root-cause → `STUCK_<wave>.md` → surface options {accept-doc / pivot / defer-V1.0.X / human-gate}, do not auto-pick.

## §G. Owner gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-PUSH | Push validation branch / cutover to remote | Physical owner | explicit "push approved" | commit/PR after sign-off | PENDING |
| G-SERVER | Cloud server credentials + OVH/VPS details + IP/printer | Physical owner | server access doc (owner said he has it) | W8 dossier `reports/.../CUTOVER_DOSSIER.md` | PENDING |
| G-FROZEN-CLOUD | Any cloud-delta fix on a frozen file (e.g. AuditLogService env()→config) | Physical owner | LOCK `_*.md` §10 countersign | `tasks/pre-cloud-goal/LOCK_*.md` | CONDITIONAL (only if W1 finds a reachable frozen blocker) |
| G-HARDWARE | Real-hardware E2E (TPE manual SumUp, printer TCP:9100) | Physical owner | on-site test confirmation | W8 dossier | PENDING (deferred — terminals manual per vision) |

**Owner-gate-waiting protocol:** W0–W7 run fully without any gate (local validation). Only W8 + push depend
on gates. Run everything autonomously up to the dossier; surface gates as the deliverable.

## §R. References
`CONSTITUTION.md` · `SYSTEM_MAP.md` · `SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md` · `CLAUDE.md §§4-13` ·
`PROJECT_BRAIN.md §2` · `plans/GOAL_100_CLOUD_READY_LECAYENNE_2026-06-05.md` (remediation predecessor) ·
`reports/cloud-readiness/CLOUD_MIGRATION_DOSSIER_2026-06-04.md` · `plans/core-bulletproof/` (PR-01..07) ·
`memory/feedback_adversarial_audit_pattern.md` · `memory/feedback_terminals_manual_encaissement_unified_2026-06-05.md`.

## §F. Final rule — DONE criteria
**Production-perfect, not "almost there."** The campaign is DONE when: (1) every system green across all 6
dimensions, page-by-page, 2-consecutive-cycle P0+P1=0; (2) cloud-delta (Wave 1) resolved-or-LOCK-gated;
(3) the GO/NO-GO cutover dossier is complete with every owner gate enumerated WHO/WHAT/WHERE; (4) frozen-diff
across the whole GOAL = only owner-countersigned LOCK edits; (5) NF525 chain attested green throughout;
(6) no push (G-PUSH owner-gated). Then: **we are ready to go to the cloud** — the owner clears G-SERVER/G-PUSH.
