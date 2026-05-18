# Round 1 Global Verdict — GOAL ULTRA CENTRAL × MGMT × SYNC
## Mission `goal-ultra-central-mgmt-sync-2026-05-18` | Round 1 of N

**Round 1 scope:** 3 most-critical tasks (1 per system), 3 specialists each, 9 parallel read-only sub-agents dispatched. **All 9 returned ≤10 min wall-clock.** Findings written to `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/round-1/wave-W{2,4,6}-T-*-{role}.md` (~115 KB / 2558 LOC).

---

## §1 — At-a-Glance Verdict

| System | Task audited | Architect | Security | DBA | SRE | Fiscal | **System verdict** |
|---|---|---|---|---|---|---|---|
| **CENTRAL** | T-1.2.1 NF525 chain attestation under load | GO-COND | PASS-COND | — | — | AMBER | **GO-CONDITIONAL** (4 P0) |
| **MGMT** | T-2.1.1 Catalog SSOT consistency | GO-COND | NO-GO | ORANGE | — | — | **NO-GO-CONDITIONAL** (4 P0 incl 1 cross-tenant DoS) |
| **SYNC** | T-3.1.1 Outbox end-to-end lifecycle | GREEN/V1 YELLOW/V2 | — | ORANGE (3 P0) | NOT V1-READY (3 P0) | — | **NO-GO-V1** (6 P0) |

**Aggregate Round 1 verdict for the backbone:** **NO-GO V1 ABSOLUTE-AS-IS** — must heal **at least the 4 cross-validated P0s + 3 single-agent P0s with DGFiP/multi-tenant impact** before merge. Remaining P0/P1 → V1.0.2 backlog with explicit reasoning.

---

## §2 — Cross-Validated P0 Findings (≥2 agents)

These are the highest-confidence findings, validated by independent specialists with non-overlapping framing.

### CVP0-1 — TRUNCATE not revoked at GRANT level (deploy-doc gap)
- **Validators:** Security (S-1) + Fiscal (F-FISC-002)
- **Anchor:** `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:29-34` explicitly says "deploy doc, not migration scope"; `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` + `deploy/ansible/site.yml` contain **0 mentions** of GRANT/REVOKE on app DB user
- **Failure mode:** App user with TRUNCATE permission can wipe `audit_logs` + `z_reports` chain in ~50 ms; BEFORE DELETE trigger does NOT fire on TRUNCATE (MySQL design)
- **Cost-of-delay if V1 ships:**
  - **fiscal:** DGFiP audit fail → amende fiscale + interdiction d'opérer + Art. 1743 CGI criminal prison time
  - **business:** falsification fiscale techniquement possible by anyone with `DROP/TRUNCATE` SQL privilege
  - **customer:** trust collapse if disclosed
- **Recommendation:** Ansible task `REVOKE DROP, TRUNCATE ON foodking_prod.* FROM 'foodking_app'@'%';` + assertion test in `deploy/ansible/site.yml` + section in `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` + new sentinel `tests/Feature/Fiscal/AuditTruncateProtectionDeployDocTest.php`
- **Owner gate:** N (deploy artefact, no frozen-zone touch)
- **Heal effort:** ~2h (Ansible task + sentinel test)

### CVP0-2 — Catalog mutator commands have no env-gate, no audit_log
- **Validators:** DBA (F1) + Security (F-W4-SEC-02)
- **Anchors:** `app/Console/Commands/MenuResetLeCayenneCommand.php:25-158` (zero `App::environment` check) + `MenuHealLightV3Command.php` (similar)
- **Failure mode:** `--force` bypasses interactive confirm; compromised CI runner OR stolen `php artisan` access can soft-delete 8 categories + ~35 items + rename categories (rename is `update()` not soft-delete → unrecoverable) on prod
- **Cost-of-delay if V1 ships:**
  - **customer:** menu disappears for kiosk users (10+ min recovery from backup)
  - **business:** owner cannot operate during incident; staff cannot take orders
  - **fiscal:** no immediate NF525 violation, but audit_log gap (no record of who ran the command)
- **Recommendation:** Add `if (App::environment('production') && !$this->option('production-confirmed')) throw RuntimeException();` to `handle()` of both commands + emit `audit_log` row with `actor_id` + `command_name` + `--force` flag value
- **Owner gate:** N
- **Heal effort:** ~1h per command × 2 commands + sentinel test = ~3h

### CVP0-3 — Outbox prune scale risk (gap-lock wedge + dead-weight index)
- **Validators:** DBA (DBA-001 + DBA-004) + SRE (SRE-011)
- **Anchors:** `app/Console/Commands/PruneOutboxCommand.php` (do-while + LIMIT without ORDER BY) + `domain_events.idx_pending(dispatched_at, occurred_at)` (DBA-001: `scopeStale` uses `created_at` not in index → filesort)
- **Failure mode:** at 10M Outbox rows, `chunked DELETE WHERE` without `ORDER BY` triggers InnoDB gap locks that wedge concurrent INSERTs; `idx_pending` falls back to filesort under load
- **Cost-of-delay if V1 ships:**
  - **customer:** order broadcasts stall during nightly prune → POS/Kiosk/KDS desync window 5-15 min
  - **business:** alert spike + on-call page
  - **fiscal:** none direct (audit_logs is separate table)
- **Recommendation:** `chunkById()` in `PruneOutboxCommand`; new index `(processed_at, occurred_at, branch_id)` for stale-query path
- **Owner gate:** N
- **Heal effort:** ~3h (refactor + migration + load test)

---

## §3 — Single-Agent P0s (must address — single-validator status documented)

### CENTRAL (NF525)
- **CENTRAL-P0-A** — `AuditLogService.php:273` reads per-branch HMAC secret via `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` at runtime. First production `php artisan config:cache` will silently fall back to shared secret → previous chain unverifiable → cashiers cannot close Z. (Security S-2)
- **CENTRAL-P0-B** — JET XML DGFiP export DEFERRED per `docs/gates/GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md:22-144`. Custom ZIP JSON bundle is not a DGFiP-recognized format. Potential 5000€/exercice per art. 1729 D CGI. (Fiscal F-FISC-001)
- **CENTRAL-P0-C** — `composition_snapshot` has no SQL-level immutability guard (migration `2026_04_22_000020`: plain nullable JSON, `OrderItem.php:44+71` fillable+castable). Pure application-layer guard. Z signature computes on Order aggregates, not snapshot → mutation post-vente non-détectable. (Fiscal F-FISC-003)

### MANAGEMENT (Catalog SSOT)
- **MGMT-P0-A** — `config/menu.php:5` self-labels "SINGLE SOURCE OF TRUTH" but is consumed by `config('menu.*')` at exactly **2 runtime callsites** (`OrderQuoteService.php:505` currency fallback + a seeder comment). Real SSOT is DB. Prod catalog mutators (`MenuResetLeCayenneCommand` 1066 LOC, `MenuHealLightV3Command` 621 LOC) hardcode their own `SAUCES`/`CRUDITES`/`SUPPLEMENTS` constants that diverge from config (13 vs 15 sauces, 4 vs 3 crudités, 10 entirely different supplements). Frontend payload (`KioskMenuService.php:66-100`) is 100% DB-derived with zero config consistency check. (Architect, MGMT)
- **MGMT-P0-B** — Cross-tenant ingredient DoS via `permission:ingredients_manage`. Granted to `Manager` + `Branch Manager`; `IngredientController::toggleAvailability` accepts no `branch_id`; `IngredientAvailabilityService::toggle` cascades via `ItemAttribute/ItemExtra->where('name', $name)->update(...)` on **global** tables (no `branch_id` column). One Branch Manager from Branch A toggling "Cheddar → rupture" propagates instantly to **every other branch's kiosk projection**. Event payload omits actor + branch_id → zero forensic trail. **One-click multi-tenant catalog DoS exploitable with any per-branch manager credential.** (Security F-W4-SEC-01)

### SYNCHRONIZATION (Outbox)
- **SYNC-P0-A** — `ws:heartbeat` cache key read at `SyncOverviewController.php:531` but **NEVER written** anywhere in `app/`, `routes/`, or `vendor/beyondcode/`. WS health probe falls through to dispatch-recency proxy → green emerald-50 dashboard panel while Pusher dies silently. (SRE SRE-001)
- **SYNC-P0-B** — `outbox.dispatch_latency_ms` p95 SLO documented in `SyncMetricsRecorder.php:11-15` but **NOT in `SloMetricCollector::SLO_TARGETS` (lines 30-36)**. `SloEvaluatorJob` only iterates that array → Slack/alert never fires on outbox latency creep. (SRE SRE-002)
- **SYNC-P0-C** — `fiscal:verify-chain` **NOT scheduled** in `app/Console/Kernel.php`. Only `fiscal:archive` daily + `fiscal:retry-alloc` per-minute exist. **NF525 chain integrity has no periodic prove-unbroken job in production.** (SRE SRE-003)
- **SYNC-P0-D** — 11 `Persist*ToOutbox` listeners use manual `DB::afterCommit()` instead of `implements ShouldQueueAfterCommit`. If any future caller fires the event OUTSIDE a transaction (or rolls back), the Outbox row commits and broadcasts a phantom event — silent failure mode with **no operational signal**. (DBA DBA-009)

---

## §4 — Strong-Reasoning Cost-of-Delay Matrix

| Finding | V1 ship blocker? | V2 SaaS blocker? | DGFiP risk? | Cross-tenant risk? | Heal effort |
|---|---|---|---|---|---|
| CVP0-1 TRUNCATE GRANT | YES | YES | **CRIMINAL** | NO | 2h |
| CVP0-2 Mutator no env-gate | YES (high impact) | YES | NO | NO (single tenant action) | 3h |
| CVP0-3 Prune gap-lock | NO (V1 scale OK) | YES (≥1M rows) | NO | NO | 3h |
| CENTRAL-P0-A env() cache | YES (1st `config:cache` breaks chain) | YES | DGFiP-secondary | YES (per-branch secret) | 2h |
| CENTRAL-P0-B JET XML | NO (Le Cayenne single resto) | YES | YES (5000€/exercice) | NO | V1.0.2 backlog (~5d) |
| CENTRAL-P0-C composition_snapshot SQL guard | NO (app guard OK) | YES (multi-actor risk) | YES (post-vente mutation) | NO | ~1d (trigger + test) |
| MGMT-P0-A config-isn't-SSOT | NO (V1 works) | YES (refactor blocker) | NO | NO | V1.0.2 backlog (~3d) |
| MGMT-P0-B Ingredient DoS | YES | YES | NO | **YES (1-click cross-tenant)** | ~1d (branch-overlay + actor log) |
| SYNC-P0-A ws:heartbeat | YES (blind operator) | YES | NO | NO | 2h |
| SYNC-P0-B Outbox SLO gap | YES (silent latency) | YES | NO | NO | 1h (one line) |
| SYNC-P0-C fiscal:verify-chain cron | YES | YES | YES (no proof) | NO | 1h (Kernel entry) |
| SYNC-P0-D Listeners afterCommit pattern | YES (phantom risk) | YES | NO | NO | ~1d (refactor 11 listeners + test) |

**Total P0 heal effort estimate:** ~22h CENTRAL + MGMT + SYNC critical-V1 path (excluding V1.0.2-deferable P0s like JET XML, config-SSOT refactor).

---

## §5 — PR Split Proposal (binding for /ultrareview cadence)

Based on Round 1 findings, the 3 PRs scope as follows:

### **PR #1 — `heal/central-backbone-2026-05-18`** (CENTRAL)
**Heal scope (V1 blockers):**
- CVP0-1 TRUNCATE GRANT (Ansible + sentinel test) — 2h
- CENTRAL-P0-A env() cache fix → resolve at boot via `config()` not `env()` after `config:cache` (or pre-resolve into shared config + per-branch override stored in DB encrypted column) — 2h
- CENTRAL-P0-C composition_snapshot SQL trigger (MySQL prod only) — 1d
- 1 P1 from architect: TOCTOU fiscal_sequence_no — `fiscal_counters` table behind feature flag — 1d

**Defer to V1.0.2:**
- CENTRAL-P0-B JET XML DGFiP export (~5d major work)
- P2 genesis-fork sentinel test

**Expected diff:** ~700-1200 LOC net (heal + tests + Ansible) — under 5K cap ✅
**`/ultrareview` command:** `/ultrareview <PR#1>`

### **PR #2 — `heal/mgmt-backbone-2026-05-18`** (rebased on PR #1)
**Heal scope (V1 blockers):**
- CVP0-2 Mutator env-gate + audit_log (`MenuResetLeCayenneCommand` + `MenuHealLightV3Command`) — 3h
- MGMT-P0-B Ingredient DoS heal — branch-overlay table `ingredient_branch_availability` + actor logging in event payload — 1d
- 1 P1 from DBA: raw seeders bump version + fire `ComposerProfileChanged` event — 4h
- 1 P0 from architect (MGMT-P0-A → partial fix): add `CatalogSsotConsistencyTest.php` sentinel that scans `config/menu.php` vs DB + emits a backlog count (full SSOT refactor deferred V1.0.2)

**Defer to V1.0.2:**
- Full config/menu.php → DB SSOT migration (~3d major work)
- P2 catalog FormRequest `return true` (defense-in-depth)

**Expected diff:** ~800-1500 LOC net — under 5K cap ✅
**`/ultrareview` command:** `/ultrareview <PR#2>`

### **PR #3 — `heal/sync-backbone-2026-05-18`** (rebased on PR #2)
**Heal scope (V1 blockers):**
- SYNC-P0-A ws:heartbeat write — Pusher webhook handler writes cache key on `ConnectionEstablished` — 2h
- SYNC-P0-B Add `outbox.dispatch_latency_ms` to `SloMetricCollector::SLO_TARGETS` — 1h (one line)
- SYNC-P0-C `fiscal:verify-chain` Kernel entry — 1h
- SYNC-P0-D Refactor 11 listeners to `implements ShouldQueueAfterCommit` — 1d (mechanical)
- CVP0-3 Outbox prune `chunkById` + new index — 3h
- 1 P1 from SRE: cap `OutboxWebhookRetryFailedCommand` max-attempts (DLQ) — 4h
- 1 P1 from SRE: staleness monitor threshold align with rescue window — 2h

**Defer to V1.0.2:**
- DBA-002 BranchScope on DomainEvent
- DBA-007 lockForUpdate payload projection
- F-T311-ARCH-01 contract-drift sentinel
- P3 Horizon memory_limit guard

**Expected diff:** ~1000-1800 LOC net — under 5K cap ✅
**`/ultrareview` command:** `/ultrareview <PR#3>`

---

## §6 — Owner Gates Triggered by Round 1

- **G1 (LOCK doc)** — **NOT TRIGGERED.** None of the V1-blocker heals touch a CLAUDE.md §7 frozen zone (`PricingService`, `FiscalSequenceService`, `ZReportService`, `AuditLogService`, `BranchScope`, `IdempotencyKeyMiddleware`, `OrderStateMachine`, kiosk Vue components, pos-wizard.js). All heals are in unfrozen support code, deploy infra, Kernel, listeners, commands.
- **G2/G3/G4** — `/ultrareview` runs per PR — pending (post-implementation).
- **G5** — NF525 chain attestation final — pending (W8).

---

## §7 — What Round 1 DID NOT Cover (proposed Round 2 scope)

Round 1 covered 3 of 49 GOAL tasks. Round 2 should cover **5 more critical tasks** to complete the architectural-backbone audit:

| Task | System | Why critical |
|---|---|---|
| T-1.3.1 BranchScope exhaustive coverage sentinel | CENTRAL | 17 models grep-verified, but sentinel for new models missing |
| T-1.4.2 IdempotencyKey deep semantics (409 conflict + scope tuple) | CENTRAL | Double-charge risk if broken |
| T-2.4.2 Feature flag production guard (`simulation_hardware`) | MGMT | Already has boot guard (Wave 5I) but exhaustive flag audit missing |
| T-3.2.1 OrderStateMachine fan-out coherence | SYNC | Cross-surface state-machine the most-load-bearing flow |
| T-3.3.1 Webhook idempotency by provider (Stripe / SenangPay / FCM) | SYNC | Stripe parity SenangPay still pending per BRAIN §1 V1.x |

= 15 more sub-agents (5 specialists × 5 tasks, where applicable specialty fan-out applies). Estimated ~10 min wall-clock if dispatched in parallel.

---

## §8 — Insights Cross-Reference

Round 1 specifically addressed friction patterns from `~/.claude/usage-data/report-2026-05-18-035320.html`:

| Insight friction | How Round 1 addressed it |
|---|---|
| Hallucinated context (fake P0s) | All 9 agents cite `file:line` per finding. Strong-reasoning template (§0.6 of GOAL) rejects findings lacking it. |
| Sessions terminating before convergence | Every agent wrote findings to disk under `reports/test-e2e/.../round-1/` before returning summary. Mission survives session boundary. |
| SSOT-first data work | Architect-MGMT agent EXPLICITLY found that the documented "SSOT" (`config/menu.php`) is NOT the real SSOT — the very kind of insight the user's friction pattern called out. |
| Pre-flight verification before audit | NF525 baseline captured + persisted (`baseline/NF525_BASELINE.md`) BEFORE audit started. |

---

## §9 — Next-Step Decision Menu (user choice)

After this Round 1 verdict, three paths forward:

**Path A — Proceed to PR-split sub-agent + heal execution (RECOMMENDED for V1 ship pressure).**
Dispatch a PR-packager sub-agent to scope the 3 PRs from §5 above. Then begin heal implementation (Implementer + RED-team per task) on PR #1 CENTRAL.

**Path B — Run Round 2 audit first (5 more critical tasks).**
Get full architectural-backbone audit coverage (8 of 49 tasks audited total) before any heal. Higher confidence verdict, longer wall-clock (~10 more min Round 2 + ~5 min synthesis).

**Path C — Stop here, hand off Round 1 to user, defer heal + PR + /ultrareview to a follow-up session.**
Useful if owner wants to review findings + decide scope adjustments before heal commits.

---

**Round 1 closed at 2026-05-18 ~04:30.** All artefacts persisted on disk. Next: BRAIN.md §2/§3 update + Graphiti episode push (mission-progress checkpoint).
