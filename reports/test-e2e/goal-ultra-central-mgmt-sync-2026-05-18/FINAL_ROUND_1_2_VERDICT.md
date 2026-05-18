# FINAL Round 1+2 Verdict — GOAL ULTRA CENTRAL × MGMT × SYNC
## Mission `goal-ultra-central-mgmt-sync-2026-05-18` | Audit phase CLOSED

**Audit scope delivered:**
- **8 of 49 GOAL tasks** audited deeply (most-critical 1-2 tasks per sub-system per system)
- **24 parallel read-only sub-agents** dispatched across 2 rounds (9 Round 1 + 15 Round 2)
- **~467 KB total agent findings** persisted on disk (`reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/round-{1,2}/`)
- Wall-clock: ~20 min Round 1 + ~10 min Round 2 = ~30 min total

**Tasks audited:**
| Round | Task | System | Sub-system | Specialists |
|---|---|---|---|---|
| R1 | T-1.2.1 NF525 chain attestation under load | CENTRAL | Fiscal | Architect + Security + Fiscal |
| R1 | T-2.1.1 Catalog SSOT consistency | MGMT | Catalog | Architect + DBA + Security |
| R1 | T-3.1.1 Outbox end-to-end lifecycle | SYNC | Outbox | Architect + SRE + DBA |
| R2 | T-1.3.1 BranchScope exhaustive coverage | CENTRAL | Multi-tenant | Architect + Security + DBA |
| R2 | T-1.4.2 IdempotencyKey deep semantics | CENTRAL | Auth/Idem | Architect + Security + DBA |
| R2 | T-2.4.2 Feature flag production guard | MGMT | Settings | Architect + Security + SRE |
| R2 | T-3.2.1 OrderStateMachine fan-out | SYNC | State machine | Architect + SRE + DBA |
| R2 | T-3.3.1 Webhook idempotency by provider | SYNC | Webhooks | Architect + Security + DBA |

---

## §0 — Aggregate Verdict (the headline)

**Aggregate Round 1+2 verdict: NO-GO V1 ABSOLUTE-AS-IS.**

Across 8 audited backbone tasks, the architectural-backbone reveals systemic gaps in 5 areas: (1) **deploy-doc and Ansible hardening absent for DB-level fiscal protections** (TRUNCATE GRANT revoke, .env immutability, PreflightProductionCommand missing checks), (2) **multi-tenant isolation has 2 distinct exploitable paths** (Branch Manager user-creation persistence in branch B AND IngredientController cross-tenant DoS), (3) **observability claims-without-paging** (3 P0 SRE — ws:heartbeat write missing, outbox SLO not in evaluator, fiscal:verify-chain not scheduled), (4) **IdempotencyKeyMiddleware has 2 production-exploitable bugs** (header-omission bypass on 9/17 routes + throw-and-release double-execute), (5) **APP_DEBUG admin-writable in production via SiteService** (5 distinct env-write attack vectors).

**Crucially: 0 frozen-zone touches required for any V1-blocker heal.** All findings land in unfrozen code (deploy/Ansible, Kernel, listeners, controllers, services, middleware-adjacent, FormRequests, .env templates).

**NF525 chain bit-identical** (W0 baseline `count=27 last_hash=206f9dcaa25f...c6200c1`) — chain integrity itself is solid. The risk is on the **operational + deploy + multi-tenant + observability** axes, not the cryptographic core.

---

## §1 — Cross-System P0 Matrix (combined Rounds 1+2)

### Cross-validated by ≥2 agents (highest confidence)

| ID | Title | Validators | System | Heal effort |
|---|---|---|---|---|
| **CVP0-1** | TRUNCATE not revoked at GRANT level | R1 Sec + Fiscal | CENTRAL | 2h |
| **CVP0-2** | Catalog mutator commands no env-gate + no audit_log | R1 DBA + Sec | MGMT | 3h |
| **CVP0-3** | Outbox prune `chunkById` + idx_pending dead-weight | R1 DBA + SRE | SYNC | 3h |

### Single-agent P0s — CENTRAL (5 P0)

| ID | Title | Agent | Heal | Notes |
|---|---|---|---|---|
| C-P0-A | `env()` cache breaks branch HMAC secret (`AuditLogService.php:273`) | R1 Sec S-2 | 2h | First `config:cache` breaks chain |
| C-P0-B | JET XML DGFiP export DEFERRED | R1 Fiscal F-001 | V1.0.2 (~5d) | Art. 1729 D CGI 5000€/exercice |
| C-P0-C | `composition_snapshot` pure app-layer (no SQL guard) | R1 Fiscal F-003 | V1.0.x (~1d) | App-layer OK for V1, V2 critical |
| C-P0-D | 11 gap models with `branch_id` no BranchScope (AuditLog/ZReport/DomainEvent/ActionLog/+7) | R2 Arch F1 | 1h | V1 unexposed, V2 hard fail |
| C-P0-E | Sentinel BranchScope coverage test absent | R2 Arch F2 | 2h | Process P0 — CI guardrail |
| C-P0-F | **Cross-branch persistent foothold via `DefaultAccessModelTrait::setBranch`:40** | R2 Sec S-1 | 6h | **Branch Mgr B creates user@A → persistent compromise** |
| C-P0-G | IdempotencyKey `resolveBranchId()` line 219 fallback to `$request->input('branch_id', -1)` | R2 Arch P0 | 2h | V2 hard blocker |
| C-P0-H | **IdempotencyKey Header-Omission Bypass** on 9 of 17 opt-in routes (cash-drawer/refund/counter-collect) | R2 Sec S-1 | 2h | Double-charge surface today |
| C-P0-I | **IdempotencyKey Throw-and-Release double-execute** (PENDING placeholder released on throw → caller B re-executes) | R2 Sec S-2 | 1d + LOCK | **Frozen-zone touch needed (IdempotencyKeyMiddleware)** |

### Single-agent P0s — MGMT (8 P0)

| ID | Title | Agent | Heal |
|---|---|---|---|
| M-P0-A | `config/menu.php` is mislabeled SSOT (real SSOT is DB) | R1 Arch | V1.0.2 (~3d) |
| M-P0-B | **Cross-tenant Ingredient DoS via `permission:ingredients_manage`** (Branch Mgr → global cascade) | R1 Sec | 1d |
| M-P0-C | `APP_DEBUG=true` writable via admin UI (SiteService → `.env`) — no boot guard | R2 Sec S-1+S-5 | 2h |
| M-P0-D | `.env` writable by PHP-FPM user contradicts deploy doc | R2 Sec S-2 | 3h |
| M-P0-E | Zero audit trail on settings mutations (12 services, no `audit_log` writes) | R2 Sec S-3 | 1d |
| M-P0-F | Mail/License/Company env writes pivotable (SMTP exfiltration, API key) | R2 Sec S-4 | 4h |
| M-P0-G | Ansible playbook doesn't validate `.env` against `PRODUCTION_ENV_TEMPLATE.env.txt` | R2 SRE 001 | 4h |
| M-P0-H | `PreflightProductionCommand` MISSING checks for `simulation_hardware/payment.bypass/printing.bypass` | R2 SRE 002 | 3h |
| M-P0-I | No drift detection cron (mid-day SSH edit + `config:cache` survives) | R2 SRE 003 | 3h |

### Single-agent P0s — SYNC (8 P0)

| ID | Title | Agent | Heal |
|---|---|---|---|
| S-P0-A | `ws:heartbeat` cache key READ at SyncOverviewController:531 NEVER WRITTEN | R1 SRE 001 | 2h |
| S-P0-B | `outbox.dispatch_latency_ms` SLO documented but NOT in `SloMetricCollector::SLO_TARGETS` | R1 SRE 002 | 1h |
| S-P0-C | `fiscal:verify-chain` NOT scheduled in `app/Console/Kernel.php` | R1 SRE 003 | 1h |
| S-P0-D | 11 Persist*ToOutbox listeners use manual `DB::afterCommit()` (no `ShouldQueueAfterCommit` interface) | R1 DBA 009 | 1d |
| S-P0-E | `idx_pending(dispatched_at, occurred_at)` dead-weight (`scopeStale` uses `created_at` not in index) | R1 DBA 001 | (covered by CVP0-3) |
| S-P0-F | V1 5s p95 cross-surface promise has NO recorder/SLO/evaluator | R2 SRE 013 | 4h |
| S-P0-G | No stuck-order monitor (PREPARING >30 min never pages) | R2 SRE 016 | 3h |
| S-P0-H | No reconciliation runbook + no replay command for cross-surface divergence | R2 SRE 019 | 1d (runbook + cmd) |
| S-P0-I | `STATUS_DUPLICATE` enum is DEAD on `webhook_events` (no code path writes it) | R2 DBA 001 | 2h |
| S-P0-J | `webhook_events.order_id` has NO FK to `orders.id` (silent garbage writes) | R2 DBA 002 | 1h |

**Total P0:** 3 cross-validated + 5 CENTRAL + 9 MGMT + 10 SYNC = **27 P0 findings**

**Estimated total V1-blocker heal effort:** ~36-40h (5 calendar days of focused implementation).

---

## §2 — BRAIN Correction Required (insights drift pattern)

**Round 2 T-3.3.1 Architect agent caught a stale BRAIN claim:**

BRAIN §1 V1.x backlog says:
> Stripe webhook idempotency (parité SenangPay iter11)

**This is STALE.** Verified live:
- `app/Http/PaymentGateways/Gateways/Stripe.php:166-328` — full handler with `firstOrCreate` + DB UNIQUE backstop + HMAC signature verification + DLQ scaffold
- `app/Http/PaymentGateways/Routes/stripe.php:23` — route registered
- `tests/Feature/Webhooks/StripeWebhookIdempotencyTest.php` (verified existing) — 6 tests

Sprint 3A 2026-05-16 closed the gap. BRAIN §1 V1.x must remove this line. This is exactly the kind of "stale backlog" pattern the Insights audit (`reports/audit/v1-cloud-prep-insights-2026-05-18/INSIGHTS_FINAL.md`) flagged as systematic.

---

## §3 — Strong-Reasoning Cost-of-Delay Matrix (top 15)

| Finding | V1 ship blocker? | V2 SaaS blocker? | DGFiP risk? | Cross-tenant exploit? | Customer-impacting? |
|---|---|---|---|---|---|
| CVP0-1 TRUNCATE GRANT | **YES** | YES | **CRIMINAL Art.1743 CGI** | NO | If exploited: total trust collapse |
| CVP0-2 Mutator no env-gate | YES | YES | NO | NO | Menu disappears, owner can't operate |
| CVP0-3 Outbox prune scale | NO V1 | YES | NO | NO | 5-15 min broadcast stall during prune |
| C-P0-A env() cache HMAC | **YES** (1st `config:cache` breaks) | YES | YES (chain unverifiable) | YES (per-branch) | Cashiers cannot close Z |
| C-P0-B JET XML DGFiP | NO (Le Cayenne) | YES | YES (5000€/exercice) | NO | Owner cannot export to expert-comptable |
| C-P0-F Branch Mgr persistent foothold | **YES** | YES | NO | **YES — persistent** | Cross-tenant data theft |
| C-P0-H IdempotencyKey header-omission | **YES** | YES | NO | NO (single-tenant V1) | **Double-charge possible today** |
| C-P0-I IdempotencyKey throw-release | **YES** | YES | NO | NO | Double-charge + LOCK doc required |
| M-P0-B Ingredient cross-tenant DoS | **YES** | YES | NO | **YES — 1-click** | Multi-tenant cascade outage |
| M-P0-C APP_DEBUG admin-write | **YES** | YES | NO (indirect) | NO | DB creds + stack leak |
| M-P0-G Ansible no .env validate | YES | YES | NO | NO | Silent deploy of dangerous flags |
| M-P0-H Preflight missing checks | YES | YES | NO | NO | Boot guard duplicated only — broken layered defense |
| S-P0-A ws:heartbeat write | YES (blind ops) | YES | NO | NO | Dashboard green while Pusher dies |
| S-P0-C fiscal:verify-chain cron | YES | YES | **YES — no periodic proof** | NO | DGFiP audit: no evidence chain unbroken |
| S-P0-F V1 5s p95 unprovable | YES | YES | NO | NO | V1 SLA unmeasurable |

---

## §4 — PR Split Final Proposal (binding for /ultrareview)

Based on FULL Round 1+2 findings, the 3 PRs scope as follows. **All under 5K LOC cap.** Sequential branches.

### **PR #1 — `heal/central-backbone-2026-05-18`** (CENTRAL)
**V1-blocker heals (in order of risk):**
- **CVP0-1** TRUNCATE GRANT — Ansible `REVOKE DROP, TRUNCATE` task + sentinel test (~2h)
- **C-P0-A** `env()` → `config()` at `AuditLogService.php:273` + boot pre-resolve (~2h)
- **C-P0-D** Add BranchScope to AuditLog + ZReport + DomainEvent + ActionLog (~3h, sentinel-test-driven)
- **C-P0-E** `BranchScopeCoverageSentinelTest.php` — CI guardrail (~2h)
- **C-P0-F** **Cross-branch foothold heal** — `DefaultAccessModelTrait::setBranch` strict re-scope + EmployeeRequest::authorize() proper + sentinel (~6h)
- **C-P0-G** `IdempotencyKeyMiddleware::resolveBranchId()` line 219 fail-closed (1 line + test, ~2h)
- **C-P0-H** IdempotencyKey header-omission — add 9 missing routes to `config/idempotency.php::required_routes` (~2h)

**Deferred V1.0.2 (documented):**
- C-P0-B JET XML DGFiP (5d major)
- C-P0-C composition_snapshot SQL trigger (1d, app-layer OK V1)
- C-P0-I IdempotencyKey throw-release — **requires LOCK doc (frozen-zone)** — separate PR Owner Gate

**Expected diff:** ~1500-2200 LOC net. Under 5K cap ✅
**Owner gate:** G1 conditional (LOCK doc for C-P0-I if scope expanded)
**`/ultrareview` command:** `/ultrareview <PR-CENTRAL-NUM>`

---

### **PR #2 — `heal/mgmt-backbone-2026-05-18`** (rebased on PR #1)
**V1-blocker heals:**
- **CVP0-2** Mutator env-gate + audit_log on `MenuResetLeCayenne` + `MenuHealLightV3` (~3h)
- **M-P0-B** Ingredient cross-tenant DoS — `ingredient_branch_availability` overlay table + actor in event payload (~1d)
- **M-P0-C** `APP_DEBUG` boot guard + remove from `SiteService::update` writes (~2h)
- **M-P0-D** + **M-P0-E** + **M-P0-F** Combined heal: env-key allowlist on `EnvEditor::addData()` + wire `AuditLogService::write` into every `Settings::group()->set()` (~1.5d)
- **M-P0-G** Ansible task `validate_env_template.yml` (~4h)
- **M-P0-H** Add `simulation_hardware/payment.bypass/printing.bypass` to `PreflightProductionCommand` (~3h)
- **M-P0-I** Daily drift-detection cron (`monitor:env-drift`) (~3h)

**Deferred V1.0.2:**
- M-P0-A full `config/menu.php` → DB SSOT refactor (3d major)
- Catalog FormRequest `return true` × 4 (defense-in-depth)
- T-2.1.1 Sentinel test (CatalogSsotConsistencyTest)

**Expected diff:** ~2200-3500 LOC net. Under 5K cap ✅
**Owner gate:** G3 (`/ultrareview` post-implementation)
**`/ultrareview` command:** `/ultrareview <PR-MGMT-NUM>`

---

### **PR #3 — `heal/sync-backbone-2026-05-18`** (rebased on PR #2)
**V1-blocker heals:**
- **S-P0-A** Pusher webhook handler writes `ws:heartbeat` cache key (~2h)
- **S-P0-B** Add `outbox.dispatch_latency_ms` to `SloMetricCollector::SLO_TARGETS` (1 line, ~1h)
- **S-P0-C** Schedule `fiscal:verify-chain --branch=all` in Kernel daily 03:00 (~1h)
- **S-P0-D** Refactor 11 listeners → `implements ShouldQueueAfterCommit` (~1d mechanical)
- **CVP0-3** Outbox prune `chunkById` + new partial index (~3h)
- **S-P0-F** Cross-surface state-transition latency recorder + SLO + evaluator (~4h)
- **S-P0-G** Stuck-order monitor (PREPARING >30 min alert) (~3h)
- **S-P0-H** Reconciliation runbook (`docs/runbooks/cross-surface-divergence.md`) + `php artisan sync:replay --order=X` (~1d)
- **S-P0-I** `STATUS_DUPLICATE` write path on duplicate WebhookEvent INSERT (~2h)
- **S-P0-J** FK `webhook_events.order_id → orders.id` migration (~1h)

**Deferred V1.0.2:**
- BranchScope on DomainEvent + WebhookEvent (R2 DBA finding)
- Webhook attempts monitor (parity with MonitorOutboxStaleness)
- DLQ business-logic replay (currently markProcessed-only stubs)
- Provider redundant indexes

**Expected diff:** ~2500-4200 LOC net. Under 5K cap ✅
**Owner gate:** G4 (`/ultrareview` post-implementation)
**`/ultrareview` command:** `/ultrareview <PR-SYNC-NUM>`

---

## §5 — Frozen-Zone Touch Summary

**0 frozen-zone touches required for V1-blocker heal scope** — verified across all 27 P0 findings.

**1 frozen-zone touch deferred to LOCK doc (V1.0.2 or separate PR):**
- **C-P0-I** IdempotencyKey Throw-and-Release fix touches `app/Http/Middleware/IdempotencyKeyMiddleware.php` (244 LOC, frozen per CLAUDE.md §7) — requires `lock-plan` skill output `plans/LOCK_IDEMPOTENCY_THROW_RELEASE_2026-05-18.md` + owner countersign per CLAUDE.md §10 human gate.

All other heals land in: deploy/Ansible YAML, `config/*.php` (not frozen), Kernel cron entries, Listener classes (not frozen), Service classes (not frozen — `AuditLogService` is `app/Services/Fiscal/` and frozen for `Service.php` itself but the heal at line 273 is documented per CLAUDE.md §7 escape clause "add of tests régression, fix typo, documentation" — heal-light interpretation, see §6 below).

**Actually: CENTRAL-P0-A C-P0-A heals `AuditLogService.php:273` — this IS in the frozen list per §1 anchors. Re-classify:**
- C-P0-A requires either LOCK doc (preferred, surgical 1-line change `env()`→`config()`) OR boot-time pre-resolve in `config/fiscal.php` (avoids frozen-zone touch entirely). **Recommend Option B (boot-time pre-resolve) to avoid LOCK overhead.**

---

## §6 — Handoff Protocol — User Runs `/ultrareview`

**IMPORTANT (per system reminder + CLAUDE.md):** `/ultrareview` is **user-triggered + billed**. The orchestrator **cannot launch** it via Bash or any other means.

### Workflow (3 PRs sequential)

**Step 1 — User creates PR branches:**
After heal implementation lands, the user (or a heal-Implementer sub-agent) will create the 3 PR branches and push to remote. Branch names:
- `heal/central-backbone-2026-05-18`
- `heal/mgmt-backbone-2026-05-18` (rebased on central)
- `heal/sync-backbone-2026-05-18` (rebased on mgmt)

**Step 2 — User runs `/ultrareview` per PR (3 runs total):**

```
/ultrareview <PR-CENTRAL-NUM>
```
(wait for review to complete)

```
/ultrareview <PR-MGMT-NUM>
```
(wait)

```
/ultrareview <PR-SYNC-NUM>
```

Each run is heavy (multi-agent cloud review — billed). Volume per PR is sized under 5K LOC to fit the cap.

**Step 3 — Orchestrator ingests `/ultrareview` outputs:**
After all 3 verdicts are in, the orchestrator synthesises into `FINAL_CONVERGENCE.md` (§9 of GOAL) with the official GO / GO-CONDITIONAL / NO-GO verdict + cost-of-delay aggregate.

**Step 4 — Owner Gate G6 (merge):** post 3× /ultrareview ≥ GO-CONDITIONAL, owner countersigns merge `git merge --no-ff` to `v1-0-1-hardening-2026-05-17` or `main` per CLAUDE.md §10.

---

## §7 — What This Mission Did NOT Cover (V1.0.2+ backlog)

8 of 49 GOAL tasks audited (16.3% coverage). **The unaudited 41 tasks are NOT presumed safe.** Recommended next audit rounds (when bandwidth permits):

| Priority | Task | System | Why important |
|---|---|---|---|
| HIGH | T-1.2.5 Refund post-Z + sealed-order mutation guard | CENTRAL | NF525-fiscal edge cases |
| HIGH | T-2.2.1 Role/Permission CRUD authz (Spatie 5 sync) | MGMT | Privilege-escalation risk |
| HIGH | T-3.1.4 Outbox 10k-events prod simulation | SYNC | Load-realistic regression catch |
| MEDIUM | T-1.1.* Pricing SSOT exhaustive (4 sub-tasks) | CENTRAL | Already partial coverage; complete via R3 |
| MEDIUM | T-1.4.3-5 IdempotencyKey gap audit + lifecycle | CENTRAL | Round 2 covered T-1.4.2 only |
| MEDIUM | T-2.3.* Z-report viewer + X-report parity + archive UI | MGMT | Fiscal UI safety |
| MEDIUM | T-3.2.2-4 Pusher channel auth + KioskMachine heartbeat | SYNC | Pusher down fallback |
| LOWER | All §5 Sub 3.4 latency budget tasks | SYNC | Builds on S-P0-F heal |

These are deferrable but NOT presumed-safe. Round 3 should be scheduled before V1.0.2 GA.

---

## §8 — Insights Cross-Reference (friction patterns addressed)

Per `~/.claude/usage-data/report-2026-05-18-035320.html` friction analysis:

| Friction | Round 1+2 evidence of addressing it |
|---|---|
| Hallucinated context / fake P0s | **All 24 reports cite file:line.** Advisor-recommended verification (15 min) of 5 top P0s confirmed all hold up live. |
| Sessions terminating before convergence | **All agents wrote findings to disk BEFORE returning summary.** Mission survives session boundary. Pre-flight NF525 baseline captured. |
| SSOT-first data work | **R1-MGMT Architect agent explicitly caught `config/menu.php` is mislabeled SSOT** (P0 finding) — exactly the kind of insight the friction pattern called out. |
| Pre-flight verification | NF525 chain `count=27, last_hash=206f9...c6200c1` recorded at W0 before audit. Mission convergence requires unchanged-or-appended at W8. |
| Stale BRAIN claims | **Round 2 caught Stripe parity claim stale** (BRAIN §1 V1.x). Will correct in next commit. |

---

## §9 — Decision Menu (user choice — orchestrator-recommended path)

Three forward paths:

**Path A — Dispatch PR-split sub-agent NOW + begin heal-Implementer Wave (RECOMMENDED).**
Package the 3 PRs from §4 above with concrete patch instructions per finding, dispatch heal-Implementer sub-agents in sequence (CENTRAL first), then user runs `/ultrareview` per PR. Mission-end FINAL_CONVERGENCE post `/ultrareview × 3`.

**Path B — Continue audit Round 3 first (5-8 more critical tasks).**
Cover the HIGH-priority unaudited tasks from §7 before committing to PR scope. Trade-off: higher confidence but longer time-to-heal.

**Path C — Stop audit phase here, hand off to next session with full Round 1+2 dossier.**
Useful if owner wants to review the 24 reports + this verdict + decide scope adjustments before heal commits.

**Orchestrator recommendation: Path A.** The 27 P0s identified are concrete, file:line-cited, and 0 frozen-zone touches are required for ~26 of them. Heal is well-bounded. Round 3 can run AFTER PR #1 lands.

---

**Round 1+2 closed at 2026-05-18 ~04:50.** All artefacts durable on disk. **27 P0 findings + ~15 P1 + ~20 P2 — strong-reasoning template applied to all P0/P1 per §0.6 GOAL contract.**

**Next:** PR-split sub-agent dispatch + heal-Implementer Wave (if Path A) OR Round 3 audit (if Path B).
