# FINAL Round 1+2+3 Verdict — GOAL ULTRA CENTRAL × MGMT × SYNC
## Mission `goal-ultra-central-mgmt-sync-2026-05-18` | Audit phase CLOSED — 27% coverage

**Audit scope delivered:**
- **13 of 49 GOAL tasks** audited deeply (27% coverage, vs 16% post-Round-2)
- **39 parallel read-only sub-agents** dispatched across 3 rounds (9 + 15 + 15)
- **~792 KB total agent findings** on disk
- **~47 P0 findings** + ~25 P1 + ~30 P2 (cumulative across 3 rounds, some refining R1+R2 findings)

Round 3 added 5 tasks: T-1.1.1 (Pricing SSOT callsite), T-1.2.5 (Refund + sealed-order), T-2.2.1 (Role/Permission CRUD), T-3.1.4 (Outbox 10k simulation), T-3.2.2 (Pusher channel auth).

---

## §1 — Aggregate Verdict — NO-GO V1 ABSOLUTE-AS-IS (escalated)

Round 3 **escalates** the verdict by surfacing 5 categories of new V1-blocker P0s missed by Rounds 1-2:

1. **Pricing fraud surface today** — `addon.role` client-controlled (60% off any addon via menu-formula ratio); `coupons.usage_count` never incremented (unlimited coupon use vs declared `max_uses_global`)
2. **Fiscal Z aggregation broken** — Mirror counter-entry refunds are **NOT aggregated in Z totals** + TVA breakdown drops mirror tax rows → signed Z over-reports for every post-Z refund (Art. 1729 D CGI 7500€/exercice + Art. 1743 CGI criminal exposure)
3. **Cashier-fraud surface** — no amount-tiered manager gate on refunds (POS Operator refunds any €N); cash refund pulls drawer with no counterparty proof (refund-to-self vector); pre-Z VOID erases revenue without manager countersign; orders.created_at no DB-level immutability post-fiscal-allocation
4. **RBAC privilege-escalation surface** — `Tenant Admin` shadow-role hijack (1 POST creates super-admin treatment in 9 production files); Self-Permission Sync (2 HTTP calls → full super-admin via syncPermissions WRITE + PermissionRequest authorize-true); PermissionController::index unauthenticated; AdministratorService 5 callsites still use integer `EnumRole::ADMIN` (only line 46 patched)
5. **Outbox 10k simulation does not exist** — `OutboxProductionLikeSimulationTest.php` is misnamed (5 unit-grade tests touching 4-11 events, not a 10k simulation); SQLite-CI structurally unprovable for `lockForUpdate` dedupe; cross-system fan-out 0% coverage
6. **Pusher channel-auth observably broken** — Sanctum wildcard `['*']` makes `tokenCan('kiosk:order')` TRUE for every admin/staff token → admin-bypass clause at `routes/channels.php:33-35` is dead code; Guest-Echo-Bypass latent (3 trivial refactors away from cross-branch read for every customer)

**0 frozen-zone touches** still required for V1-blocker heal (CLAUDE.md §7 protected). NF525 chain bit-identical (W0 baseline `count=27, last_hash=206f9d…c6200c1`).

---

## §2 — Cumulative P0 Inventory (Rounds 1+2+3)

### CENTRAL (15 P0 total)

**From Round 1 (3):** CVP0-1 TRUNCATE GRANT (Sec+Fiscal) ; C-P0-A env() HMAC ; C-P0-B JET XML DGFiP (V1.0.2) ; C-P0-C composition_snapshot SQL guard (V1.0.x).

**From Round 2 (4):** C-P0-D 11 gap models BranchScope ; C-P0-E sentinel test ; C-P0-F Cross-branch persistent foothold ; C-P0-G IdempotencyKey resolveBranchId ; C-P0-H Idempotency Header-Omission Bypass ; C-P0-I IdempotencyKey Throw-and-Release (LOCK).

**NEW Round 3 (8):**
- **C-R3-P0-A** `PricingService.php:224-227,793-813` — `addon.role` is client-controlled, menu-formula ratio (0.4/0.6) applied without validating role against published profile → up to 60% off ANY addon. HMAC quote/seal does NOT mitigate (`OrderQuoteService::normalizeForCanonical` deep-clones the spoof which signs cleanly). [Sec T-1.1.1 S-1]
- **C-R3-P0-B** `coupons.usage_count` initialized to 0 in `CouponService.php:236` and NEVER incremented → `max_uses_global` check at `Coupon.php:151-154` is dead code → unlimited flash-sale coupon use. [Sec T-1.1.1 S-3]
- **C-R3-P0-C** Refund: no amount-tiered manager gate (`pos-orders` permission alone). POS Operator can refund any €N. [Sec T-1.2.5 S-AUTHZ-01]
- **C-R3-P0-D** Refund: cash refund pulls drawer cash with no physical counterparty proof. Refund-to-self vector wide open. [Sec T-1.2.5 S-SELF-01]
- **C-R3-P0-E** Refund: `orders.created_at` (seal anchor) has NO DB-level immutability trigger when `fiscal_sequence_no IS NOT NULL`. DB-write attacker can shift orders across Z boundaries. [Sec T-1.2.5 S-FORGE-01]
- **C-R3-P0-F** Refund: pre-Z VOID erases same-day revenue without manager countersign. `status:ACCEPT→CANCELED` while `payment_status=PAID` stays PAID (not gated by PaymentStateMachine). [Sec T-1.2.5 S-VOID-01]
- **C-R3-P0-G** Fiscal: Mirror counter-entry refund **NOT aggregated in Z totals** (`ZReportService::aggregate()` lines 297-435 exclude RETURNED status + `created_at <= $from` mismatch). Signed Z over-reports `total_ttc` by every post-Z refund €. **Art. 1729 D CGI 7500€/exercice + Art. 1743 CGI criminal**. [Fiscal T-1.2.5 F1]
- **C-R3-P0-H** Fiscal: TVA breakdown silently drops mirror tax rows. `taxBreakdownForOrders` (line 415-417) called only on `$orders` + `$adjustmentOrderIds` — mirror in neither. **Art. 1729 CGI 40-80% TVA penalty exposure**. [Fiscal T-1.2.5 F2]

### MANAGEMENT (14 P0 total)

**From Round 1 (2):** CVP0-2 Mutator no env-gate (DBA+Sec) ; M-P0-A config/menu.php non-SSOT (V1.0.2) ; M-P0-B Ingredient cross-tenant DoS.

**From Round 2 (7):** M-P0-C APP_DEBUG writable ; M-P0-D .env writable ; M-P0-E settings no audit ; M-P0-F Mail/License env pivotable ; M-P0-G Ansible no .env validation ; M-P0-H Preflight missing checks ; M-P0-I no drift detection.

**NEW Round 3 (5):**
- **M-R3-P0-A** `PermissionController.php:27` middleware only on `update`, NOT on `index` + `PermissionRequest.php:17` `authorize()` returns `true` → any auth:sanctum holder (POS Operator, Chef, stale Customer token) can enumerate full permission matrix of any role via `GET /admin/permission/{role}`. **Wave 5H hardening forgot Permission**. [T-2.2.1 Arch F1]
- **M-R3-P0-B** AdministratorService class-of-bug residual: commit `10a00c127` patched ONLY line 46. 5 untouched callsites: L74 `assignRole(EnumRole::ADMIN=1)` WRITE + L119/145/162/181 `hasRole(EnumRole::ADMIN=1)` — Spatie `HasRoles::hasRole(int)` checks `roles.id == 1` but fresh seed lands at id=73. **All admin mutation paths fail with generic permission_denied on fresh-seed deployment**. [T-2.2.1 Arch F2]
- **M-R3-P0-C** `Tenant Admin` shadow-role hijack: 9 production files (AdminController:22,34, ItemResource:187, etc.) dual-gate super-admin treatment on `hasRole('Admin') || hasRole('Tenant Admin')`, yet **no seeder creates it** and **no validator forbids creating/renaming a role to that name**. **Single-request exploit**: `POST /api/admin/role {"name":"Tenant Admin"}` → super-admin in 9 files. [T-2.2.1 Sec S-1]
- **M-R3-P0-D** Self-Permission Sync: `PermissionService::update:39-47` calls `$role->syncPermissions(...)` (WRITE, not merge) with no guard against caller's own role. `PermissionRequest::authorize()` returns `true`. **2 HTTP calls → full super-admin**. [T-2.2.1 Sec S-2]
- **M-R3-P0-E** Admin@Branch0 Mint (cross-ref R2 T-1.3.1 S-1): `AdministratorRequest.php:57` still `'branch_id'=>['nullable','numeric']` (R2 mitigation never applied). `AdministratorService.php:70,74` writes attacker-supplied `branch_id=0` + `assignRole(ADMIN)` → persistent phantom Admin. [T-2.2.1 Sec S-3]

### SYNCHRONIZATION (18 P0 total)

**From Round 1 (4):** CVP0-3 Outbox prune (DBA+SRE) ; S-P0-A ws:heartbeat ; S-P0-B SLO target ; S-P0-C fiscal:verify-chain cron ; S-P0-D 11 listeners afterCommit.

**From Round 2 (6):** S-P0-F V1 5s p95 unprovable ; S-P0-G stuck-order monitor ; S-P0-H reconciliation runbook ; S-P0-I STATUS_DUPLICATE dead ; S-P0-J webhook_events FK ; S-P0-E `idx_pending` dead.

**NEW Round 3 (8):**
- **S-R3-P0-A** `OutboxProductionLikeSimulationTest.php` is **misnamed** — 5 unit-grade tests touching 4-11 events sequentially, not a simulation. Biggest stress = 12 events. **10k unproven anywhere**. Mission acceptance criterion T-3.1.4 cannot be attested today. [T-3.1.4 Arch]
- **S-R3-P0-B** CI/prod divergence on 4 axes (SQLite vs MySQL, sync vs Redis queue, log vs Pusher, array vs Redis cache). `lockForUpdate` no-op in SQLite. 7 production failure modes unprovable. [T-3.1.4 SRE 002]
- **S-R3-P0-C** SIGTERM mid-batch event lost forever (claim committed → worker killed → `dispatched_at` stays set → `scopePending` filters it out → event never broadcast). [T-3.1.4 SRE 005]
- **S-R3-P0-D** Outbox dedupe random-sha1 `uniq_domain_events_idempotency_key` is InnoDB anti-pattern at 167 INSERT/s (random page splits, no monotonic right-tail growth). [T-3.1.4 DBA R3-001]
- **S-R3-P0-E** Rescue cron races worker claim — at 10k pending × 90s timeout × 2-min interval = 20k worker-sec wasted on lock-and-skip. [T-3.1.4 DBA R3-002]
- **S-R3-P0-F** PruneOutbox `(clause A) OR (clause B)` triggers `index_merge` with FULL SCAN on clause B (no `(attempts, created_at)` index). [T-3.1.4 DBA R3-007]
- **S-R3-P0-G** Pusher channel-auth observably broken: Sanctum `['*']` wildcard makes `tokenCan('kiosk:order')` TRUE for every admin/staff token → admin-bypass at `routes/channels.php:33-35` is dead code. Legitimate KDS/POS staff silently lose real-time push (masked by polling fallback). One-line refactor flips to over-grant. [T-3.2.2 Sec F-SEC-W6-01]
- **S-R3-P0-H** Guest-Echo-Bypass latent: every guest (`GuestSignupController.php:121`) AND every customer (`SignupController.php:92`) gets `branch_id=0`. Admin-zero clause at `channels.php:33` uses only integer sentinel + no role check. Three trivial refactors (clause reorder, removing `&& $machine`, seeding test KioskMachine) each open cross-branch read for every customer. [T-3.2.2 Sec F-SEC-W6-02]
- **S-R3-P0-I** Channel-auth 403 fails silently server-side (no Log/metric/audit) + client `_refreshEchoAuth()` re-injects same stale token in loop → ACL break invisible. [T-3.2.2 SRE 024]
- **S-R3-P0-J** Pusher Cloud has NO documented DR runbook, NO message-budget telemetry, NO replay command for `attempts>=6` rows post-outage. 2h Pusher outage = ~300 silent terminal-failed rows. [T-3.2.2 SRE 025]

**Total cumulative P0:** 15 CENTRAL + 14 MGMT + 18 SYNC = **47 P0 findings**

---

## §3 — Cross-Validated P0s (≥2 agents, highest confidence)

| ID | Title | Validators | Round | Heal |
|---|---|---|---|---|
| **CVP0-1** | TRUNCATE GRANT not revoked | R1 Sec + R1 Fiscal | R1 | 2h |
| **CVP0-2** | Catalog mutator no env-gate | R1 DBA + R1 Sec + R3 Arch (extends to AdministratorService) | R1+R3 | 3h |
| **CVP0-3** | Outbox prune gap-lock + dead idx | R1 DBA + R1 SRE + R3 DBA + R3 SRE | R1+R3 | 6h (expanded) |
| **CVP0-4** | composition_snapshot no SQL immutability | R1 Fiscal + R3 DBA F1 | R1+R3 | 1d |
| **CVP0-5** | Admin@Branch0 Mint via setBranch | R2 Sec + R3 Sec S-3 | R2+R3 | 6h |
| **CVP0-6** | Wave 5H FormRequest authz partial (missed Permission + Administrator residuals) | R3 Arch F1+F2 + R3 Sec S-2 | R3 | 1h (Permission) + 30min (Admin residuals) |
| **CVP0-7** | Outbox 10k simulation does not exist (R1 + R3 confirm) | R1 Arch + R3 Arch + R3 SRE + R3 DBA | R1+R3 | 1d (CI test) + staging artifact |

---

## §4 — Strong-Reasoning Cost-of-Delay (top 15 — including Round 3)

| Finding | V1 ship blocker? | V2 SaaS blocker? | DGFiP risk? | Cross-tenant? | Heal effort |
|---|---|---|---|---|---|
| CVP0-1 TRUNCATE GRANT | YES | YES | **CRIMINAL Art.1743** | — | 2h |
| C-R3-P0-G/H Mirror counter-entry NOT in Z aggregation | YES | YES | **Art.1729 D CGI 7500€/exercice + criminal** | — | 4h |
| C-R3-P0-A `addon.role` 60% off fraud | **YES (today)** | YES | — | — | 2h |
| C-R3-P0-B coupons.usage_count dead | **YES (today)** | YES | — | — | 2h |
| C-R3-P0-D Cash refund-to-self | **YES (today)** | YES | indirect | — | 1d (witness/audit) |
| C-R3-P0-C Refund no amount-gate | **YES (today)** | YES | indirect | — | 4h |
| M-R3-P0-C Tenant Admin shadow hijack | **YES (today)** | YES | — | YES (cross-branch via shadow role) | 2h |
| M-R3-P0-D Self-Permission Sync | **YES (today)** | YES | — | YES | 1h |
| M-R3-P0-A PermissionController unauth | **YES (today)** | YES | — | — | 15min |
| M-R3-P0-B AdministratorService 5 callsites broken | **YES (functional)** | YES | — | — | 30min |
| S-R3-P0-G Pusher wildcard channel-auth broken | YES (silent) | YES | — | — | 2.5h |
| S-R3-P0-H Guest-Echo latent bypass | YES (latent) | YES | — | YES (3 refactors) | 2h |
| C-P0-F Branch foothold (R2) | YES | YES | — | YES | 6h |
| M-P0-B Ingredient DoS (R1) | YES | YES | — | YES (1-click) | 1d |
| S-P0-A ws:heartbeat write (R1) | YES (blind ops) | YES | — | — | 2h |

**Total V1-blocker heal effort estimate:** ~65-80h (~7-10 calendar days).

---

## §5 — Updated PR Split (3 PRs, scope expanded by Round 3 findings)

Per FINAL §0.5 binding contract, 3 sequential PRs. Round 3 expanded scope materially — each PR LOC estimate increased.

### **PR #1 — `heal/central-backbone-2026-05-18`** (CENTRAL)
**V1 heals (R1+R2 original + R3 additions):**
- CVP0-1 TRUNCATE GRANT (Ansible + sentinel) — 2h
- C-P0-A `env()` HMAC fix (boot pre-resolve) — 2h
- C-P0-D + C-P0-E BranchScope on 11 gap models + sentinel test — 5h
- C-P0-F Cross-branch foothold heal — 6h
- C-P0-G IdempotencyKey resolveBranchId fail-closed — 2h
- C-P0-H Add 9 routes to required_routes — 2h
- **NEW R3 C-R3-P0-A** `addon.role` validation (server-side reject unauthorized role) — 2h
- **NEW R3 C-R3-P0-B** Wire coupon.usage_count increment + max_uses_global check — 2h
- **NEW R3 C-R3-P0-C** Refund amount-tiered manager gate (>€X requires manager auth) — 4h
- **NEW R3 C-R3-P0-D** Cash refund witness/audit (operator+manager dual-signature OR audit_log + reconciliation flag) — 1d
- **NEW R3 C-R3-P0-G/H Fiscal Z aggregation patch** (mirror counter-entry inclusion in Z totals + TVA breakdown) — 4h + 2 sentinel tests
- **NEW R3 C-R3-P0-F** pre-Z VOID manager countersign — 3h

**Deferred V1.0.2:**
- C-P0-B JET XML DGFiP (~5d)
- C-P0-C composition_snapshot SQL trigger (~1d)
- C-P0-I IdempotencyKey Throw-and-Release (LOCK doc)
- C-R3-P0-E orders.created_at immutability trigger (~1d)

**Expected diff:** ~3500-5000 LOC net — **at the upper limit of 5K cap, may need sub-split per §0.5b**
**`/ultrareview` command:** `/ultrareview <PR-CENTRAL-NUM>` (or split into 1A Pricing+Fiscal-Z, 1B BranchScope+IdempotencyKey+Refund-cashier)

### **PR #2 — `heal/mgmt-backbone-2026-05-18`** (rebased on PR #1)
**V1 heals (R1+R2 original + R3 additions):**
- CVP0-2 Mutator env-gate + audit_log — 3h
- M-P0-B Ingredient cross-tenant DoS — 1d
- M-P0-C APP_DEBUG boot guard + remove from SiteService — 2h
- M-P0-D + M-P0-E + M-P0-F env-edit lockdown + audit trail — 1.5d
- M-P0-G Ansible task validate_env_template — 4h
- M-P0-H Preflight checks add — 3h
- M-P0-I drift detection cron — 3h
- **NEW R3 M-R3-P0-A** PermissionController index middleware (15 min)
- **NEW R3 M-R3-P0-B** AdministratorService 5 callsites — convert int→name role identity (30 min)
- **NEW R3 M-R3-P0-C** Tenant Admin shadow guard — reject role name in RoleRequest (2h)
- **NEW R3 M-R3-P0-D** Self-Permission Sync guard + PermissionRequest authorize (1h)
- **NEW R3 M-R3-P0-E** AdministratorRequest branch_id authz (overlap with R2 setBranch heal) — 2h

**Deferred V1.0.2:**
- M-P0-A config/menu.php → DB SSOT refactor (~3d)

**Expected diff:** ~3000-4500 LOC net — under 5K cap ✅

### **PR #3 — `heal/sync-backbone-2026-05-18`** (rebased on PR #2)
**V1 heals (R1+R2 + R3 additions):**
- S-P0-A ws:heartbeat write — 2h
- S-P0-B SLO target add — 1h
- S-P0-C fiscal:verify-chain cron — 1h (NOTE: parallel commit `335b98134` may already close this — reconcile)
- S-P0-D 11 listeners ShouldQueueAfterCommit — 1d
- CVP0-3 Outbox prune chunkById + new partial index — 3h (extended)
- S-P0-F Cross-surface latency recorder + SLO — 4h
- S-P0-G stuck-order monitor — 3h
- S-P0-H reconciliation runbook + replay cmd — 1d
- S-P0-I STATUS_DUPLICATE write path — 2h
- S-P0-J webhook_events FK migration — 1h
- **NEW R3 S-R3-P0-A** Outbox 10k simulation — rename + write real test + staging artifact (1d)
- **NEW R3 S-R3-P0-D** Outbox idempotency_key structured prefix (random-sha1 → branch_id|event_type|aggregate_id) (~6h)
- **NEW R3 S-R3-P0-G** Pusher channel-auth fix (token-name check + admin-bypass guard) (2.5h)
- **NEW R3 S-R3-P0-H** Guest-Echo defense-in-depth (Sanctum ability `admin:cross-branch`) (2.5h)
- **NEW R3 S-R3-P0-I** Channel-auth 403 logging/metric (1h)

**Deferred V1.0.2:**
- BranchScope on DomainEvent + WebhookEvent (R2 DBA)
- Webhook attempts monitor parity
- DLQ business-logic replay (markProcessed stubs)
- Pusher DR runbook + budget monitor

**Expected diff:** ~4500-5500 LOC net — **above 5K cap, sub-split needed per §0.5b**
**Recommended sub-split:** PR-3A SYNC-CORE (Outbox + state machine + reconciliation) + PR-3B PUSHER (channel auth + DR + observability)

---

## §6 — Parallel-Mission Reconciliation (16 commits during this session)

The parallel `ULTRA_PLAN_FULLFLOW` + `kds-v1-prep` missions landed these commits on `v1-0-1-hardening-2026-05-17` during my 3-round audit:

| Parallel commit | Likely closes (full reconcile pending Step §7) |
|---|---|
| `335b98134` fiscal:verify-chain branch validation + daily cron | **S-P0-C** (fiscal:verify-chain cron) |
| `048c48439` feat(fiscal): fiscal:verify-chain artisan command | **S-P0-C** (also) |
| `e264be951` write-then-dispatch ordering + batch continuity | Partial **S-P0-D** + R3 SRE 005 |
| `8dc6ec331` audit_logs trail on manual DLQ replay | Partial **S-P0-H** reconciliation |
| `79e214542` TrustProxies $proxies='*' per-IP throttle | Partial T-3.3.1 Sec F-SEC-W7-02 (no-throttle DoS) |
| `148dbebce` TZ-aware boundaries in KdsSyncService | Related to T-1.2.2 ZReport tz (not in my R1+R2+R3 scope) |
| `a1dd60f56` clamp polling cadence floor 250ms | Sync-related |
| `5df225ffa` cash drawer session owner-or-manager close | Partial F-10/F-11 (not in scope) |
| `9450dcd54` POS V1 GO-LIVE blocker — terminal_id UI for CARD | Not in my scope |
| Other 7 commits (kds-pageby, borne, stock-livreur, frites filter, POS legal) | Not in my scope |

**Step §7 reconciliation must run BEFORE heal-Implementer Wave** — otherwise duplicate work.

---

## §7 — Heal-Implementer Wave Plan (binding next-step contract)

Per "b than a" instruction (Round 3 then heal):

1. **Reconcile (15 min)** — for each of the 47 P0s, grep current HEAD to confirm still-open. Mark closed ones with parallel-commit SHA. Output: `reports/.../RECONCILIATION_2026-05-18.md`.
2. **PR #1 CENTRAL Implementer Wave** — sequential commits per heal in `heal/central-backbone-2026-05-18` branch. RED-team review after each major heal. Smoke tests per commit (`php artisan test --filter=...`).
3. **PR #2 MGMT Implementer Wave** — rebased on PR #1. Same pattern.
4. **PR #3 SYNC Implementer Wave** — rebased on PR #2. Same pattern.
5. **Push 3 PRs to remote** + open `gh pr create` per PR with PR-PACKAGE description templates.
6. **Surface to user: 3 `/ultrareview` commands** (user-triggered + billed).
7. **Ingest /ultrareview verdicts** + synthesis into `FINAL_CONVERGENCE.md`.
8. **Owner G6** (merge to main per CLAUDE.md §10 human gate).

---

## §8 — What This Mission Still Does NOT Cover (Round 4+ scope, if needed)

36 of 49 GOAL tasks remain unaudited (73% uncovered). NOT presumed safe.

Suggested Round 4 (HIGH priority unaudited):
- T-1.1.2-4 Pricing SSOT remaining (CompositionSnapshot + Discount/Tax + Observability)
- T-1.2.2 ZReport boundary cases (DST, branch tz, leap second) — partially addressed by parallel commit `148dbebce`
- T-1.4.4 Spatie permissions matrix exhaustive (cross-ref R3 T-2.2.1 findings)
- T-2.3.* Fiscal UI 4 tasks (Z-report viewer, X-report, archive UI)
- T-3.4.* Observability dashboard 4 tasks
- T-1.2.3 audit chain immutability under DELETE/TRUNCATE/UPDATE (deeper than CVP0-1)

Round 4 = ~6-8 tasks × 3 specialists = 18-24 agents. Would bring coverage to ~40%.

---

**Round 1+2+3 closed at 2026-05-18 ~05:00.** All 39 reports + 3 verdicts + 3 PR-PACKAGEs durable on disk. **47 P0 + ~25 P1 + ~30 P2 — all file:line-cited, all strong-reasoning-templated per §0.6 contract.**

**Next:** Reconciliation (Step §7.1) → Heal-Implementer Wave (Step §7.2-5).
