# F-9 Observability — Foundation Audit Round 1 STATUS

**Date** : 2026-05-18
**Scope** : Health checks, logs, audit trail, SLI metrics, DashboardService (DIRTY attest), KDS overflow UI
**Mode** : READ-ONLY ultra-deep audit, 3 specialists parallel (Architect / SRE / RED-team)
**Branch** : v1-0-1-hardening-2026-05-17

---

## Verdict — F-9 Observability

**Status global** : `HEAL-LIGHT` (no blockers, several P1-P2 heal candidates)

L'infrastructure observability est solidement architecturée : channel separation explicite avec rétentions calibrées (security 90d, observability 90d, fiscal 400d), three-probe health stack (/full IP-gated, /live public minimal, /ready avec degraded 503), F-015 outbox staleness probes en defense-in-depth (HTTP + cron + dashboard), non-blocking telemetry recorder avec whitelist, audit_logs HMAC chain bétonné. **Mais** : 320 occurrences de `Log::info($exception->getMessage())` (severity mis-leveling massif, PII risk indirect), customer name plaintext dans `ActionLog.details` (OrderService.php:540-545), `/health/ready` non-IP-gated leak ops info en payload 503, et 2 endpoints admin mutating (outboxRetryFailed/Drain) sans audit emit.

**Recommendation** : NE PAS bloquer V1 — heal-light targeté avant V1.0.1 ship.

---

## 4-List Output

### KEEP (production-ready, do not touch)

| ID | Sujet | Cite |
|---|---|---|
| ARCH-K1 | Channel separation hardware/security/observability/fiscal avec rétentions explicites | config/logging.php:121-187 |
| ARCH-K2 | Three-probe health stack (full/live/ready) asymetrie deliberate | HealthController.php:13-60 |
| ARCH-K3 | F-015 outbox staleness probe baked into /ready + cron monitor + dashboard | HealthController.php:143-168, MonitorOutboxStaleness.php:31-84 |
| ARCH-K4 | Production-only broadcast misconfig probe (sync/null gates) | HealthController.php:181-213 |
| ARCH-K5 | SyncMetricsRecorder non-blocking invariant explicit | SyncMetricsRecorder.php:108-128 |
| ARCH-K6 | SLO collector ratio vs latency classification + driver-aware SQL | SloMetricCollector.php:30-198 |
| ARCH-K7 | audit_logs NF525 HMAC chain (frozen-zone) | AuditLogService.php:60-119 |
| ARCH-K8 | SloEvaluatorJob retry resilience (tries=3, backoff=10, timeout=120) | SloEvaluatorJob.php:41-43 |
| ARCH-K9 | Outbox dashboard role-tiered gating (permission + role + branch scope) | SyncOverviewController.php:45-56, 188-219 |
| SRE-K1 | K8s/SRE convention three-probe stack | routes/api.php:139-141 |
| SRE-K2 | Channel retention tiers calibrated to compliance | config/logging.php (90/90/400d) |
| SRE-K3 | 5 outbox scheduled lanes avec withoutOverlapping + onOneServer | Console/Kernel.php:40-118 |
| SRE-K4 | SLO evaluator 5min cadence + mutex 5min | Console/Kernel.php:125-128 |
| SRE-K5 | Outbox dashboard SELECT_LIMIT=50000 + truncated flag | SyncOverviewController.php:35-93 |
| SRE-K6 | MonitorOutboxStaleness non-zero exit + Log::error for pager hooks | MonitorOutboxStaleness.php:80-83 |
| SRE-K7 | Heuristic process-up probes explicitly labelled method=heuristic_* | SyncOverviewController.php:554-565 |
| SRE-K8 | Queue worker setup documented in 3 deployment docs | docs/DEPLOYMENT_GUIDE_V1.md, REALTIME_SETUP.md, QUEUE_WORKER_SETUP.md |
| RED-K1 | HMAC chain integrity multi-layer (UNIQUE + lock + transaction + DB trigger) | AuditLogService.php:60-119 |
| RED-K2 | /health/full correctly IP-gated via HEALTH_IPS_ALLOWED CSV | HealthController.php:15, 65-78 |
| RED-K3 | Client metric type whitelist (5 entries) prevents cardinality spray | SyncMetricsRecorder.php:46-52 |
| RED-K4 | Client metric value max:600000 (10min) prevents INT_MAX attack | StoreClientMetricsRequest.php:31 |
| RED-K5 | Cross-branch read returns explicit 403, no silent down-scope | SyncOverviewController.php:211-215 |

### HEAL (proposals, scope-minimal)

**P0 — None.**

**P1 (V1.0.1 cycle):**

| ID | Sujet | Cite + recommended action |
|---|---|---|
| RED-RED1 | **PII leak — customer name plaintext in ActionLog.details** | OrderService.php:540-545. Replace `Auth::user()->name` with `user_id`. Grep similar pattern across 11 ActionLog::create sites. |
| RED-RED5 | **/health/ready 503 payload leaks ops info to anonymous callers** | HealthController.php:39-60. Gate via assertFullHealthIpAllowed OR sanitize payload to `{status:degraded}` for non-allowlisted IPs. |
| RED-RED6 | **outboxDrainFailed + outboxRetryFailed mutate without audit trail** | SyncOverviewController.php:377-435. Wrap in AuditLogService::write OR Log::channel('security')->info with actor_id, branch_id, count, ip. |
| ARCH-H1 | **320 Log::info($exception->getMessage()) — severity mis-leveling + PII vector** | Refactor to Log::error('domain.event', [exception_class, message, trace, context]). Bucket: Touchable services (BranchService, SMS gateways, Listeners) = direct heal; Frozen-adjacent (PaymentGateways/Stripe) = LOCK plan; DIRTY (DashboardService) = attest only. |

**P2 (V1.0.2):**

| ID | Sujet | Cite |
|---|---|---|
| RED-RED3 | FCM token first-20-chars logged | FcmNotificationService.php:80,94 → log hash() suffix |
| RED-RED4 | Loyalty point manipulation log lacks authorization context | LoyaltyController.php:236,320 → channel security + branch_id/ip/role |
| RED-RED7 | Telemetry write failure invisible (failopen + Log::warning default channel) | SyncMetricsRecorder.php:120 → Log::channel('observability') + Cache counter |
| ARCH-H2 | String-concat logs (not structured) inconsistent across codebase | docs/OBSERVABILITY_CONVENTIONS.md required |
| ARCH-H3 | 58 Log::channel(...) vs 487 unscoped Log::* — routing inconsistency | Targeted heal on high-value flows |
| ARCH-H4 | BypassAuditLogger writes default channel (14d) not 'security' (90d) | BypassAuditLogger.php:32,45 → Log::channel('security')->warning |
| ARCH-H5 | AuditLog vs ActionLog boundary implicit | Add docs/AUDIT_TRAILS_CONTRACT.md |
| SRE-H1 | Daily channels rotate by days, no size cap (disk-fill risk) | logrotate(8) OS-level OR RotatingFileHandler maxFiles |
| SRE-H2 | /health/live no throttle/middleware (DDoS bandwidth surface) | routes/api.php:140 add throttle:120,1 |
| SRE-H3 | No disk-free check in /healthz | Add subsystem check (warn 15%, error 5%) |
| SRE-H4 | Percentile in-memory; no degradation hint when SELECT_LIMIT hit | V1.0.2 materialized view |
| SRE-H5 | No Prometheus /metrics endpoint | Optional V1.0.2 |
| SRE-H6 | **KDS overflow flag UI in place but no server-side alert** | KitchenDisplaySystemComponent.vue:1888,1926 → backend emit Log::channel('observability')->warning('kds.overflow_detected', ...) + sync_metrics counter. Already V1.0.1 backlog. |

### DUPLICATE (single-event, multiple sinks)

| ID | Sujet | Cite |
|---|---|---|
| ARCH-D1 | **SloEvaluatorJob writes 'slo_evaluation' to BOTH ActionLog::create AND Log::channel('observability') no dedup key** | SloEvaluatorJob.php:65-83. Pick canonical sink (ActionLog OR channel), other becomes debug-level secondary or removed. |
| ARCH-D2 | Outbox staleness checked in 3 places (/ready, monitor cron, dashboard) — deliberate by design | KEEP-but-doc the contract |
| SRE-D1 | /healthz/ready vs /api/admin/observability/outbox health probe — different signals, can disagree | Unify in QueueHealthProbe shared service (V1.0.2) |
| RED-D1 | 320 Log::info($exception->getMessage()) calls follow identical pattern → template heal (single trait sweeps callers) | LogsExceptionsConsistently trait |

### DEAD (orphan hooks / dead paths)

| ID | Sujet | Cite |
|---|---|---|
| ARCH-DEAD1 | **SyncMetricsRecorder::recordWebSocketAuthFailure is UNCALLED server-side** | SyncMetricsRecorder.php:36-44, 65-74. Documented intentional surface. Keep, add unit test pinning unused state. |
| SRE-DEAD1 | Observability channel has no Slack/Sentry alert forwarding | config/logging.php:163-169. Write-only sink. Configure forwarding OR document intent. |
| SRE-DEAD2 | 'hardware' channel level=info but only warning is emitted | config/logging.php:124, KioskEventController.php:265. Promote channel level to 'warning' or add info-level emit. |
| RED-DEAD1 | outboxRetryFailed batch operation emits no log/audit | SyncOverviewController.php:377-406 |
| RED-DEAD2 | BypassAuditLogger routes to default channel (14d) instead of 'security' (90d) | Mis-routing, also ARCH-H4 |

---

## DIRTY zone attestation (read-only, NO touch)

**DashboardService.php** (session-A WIP) :
- 11 occurrences of `Log::info($exception->getMessage())` at lines 97, 179, 310, 320, 330, 340, 350, 387, 414, 451, 488 — same severity mis-leveling pattern as ARCH-H1.
- Wave 3c KDS-ADV3C-01 P0 heal at lines 68-83 is **production-grade** (TZ-aware day boundary fix, sentinel `SisterServicesTzAwareV2Test` cited).
- Status : **deferred to DashboardService refactor cycle**. Not part of this heal batch.

---

## KDS overflow flag UI attestation (V1.0.1 backlog)

- Vue state : `KitchenDisplaySystemComponent.vue:1139` (`kdsOverflowDetected: false`)
- Setter sites : `1888`, `1926` from API response `res.data.meta.overflow`
- Computed : `1255` (`return this.kdsOverflowDetected === true`)
- UI surface : `90` (button `kds_see_more`)
- Handler : `2004` (`kdsOverflowSeeMore`)
- **Gap** : backend sets `meta.overflow=true` when KDS query truncates, but no server-side emit (Log::channel('observability') / sync_metrics counter / SLO breach signal). UI-only signal.
- **Verdict** : SRE-H6 above — V1.0.1 backlog item.

---

## Questions Owner — user-friendly

1. **PII migration sur ActionLog.details legacy** — la fix forward (changer `Auth::user()->name` → `user_id`) protège l'avenir, mais les rows existantes contiennent le nom client en plaintext. Tu préfères qu'on fasse un script one-shot de redaction (anonymise les rows >30j) ou qu'on accepte l'état historique et fix forward only ? Le risque RGPD article 5(1)(c) penche pour la redaction.

2. **/health/ready 503 sanitization** — la payload détaillée (stale_count, broadcast driver, error messages) facilite le triage opérateur mais leak info à un attaquant externe non-authentifié. Tu veux qu'on IP-gate `/ready` comme `/full` (et accepter que le LB cloud doive être whitelisté), ou qu'on garde `/ready` public mais qu'on retourne juste `{status: degraded}` sans subsystems ?

3. **320 Log::info(exception) heal — scope** — on a 3 buckets : (a) Touchable = BranchService, DefaultAccessService, 8 SMS gateways, 8 Listeners, 2 LoyaltyController = ~25 fichiers safe à patcher. (b) Frozen-adjacent = 3 PaymentGateways (Stripe/Paypal/Credit) = requiert LOCK doc. (c) DIRTY = DashboardService = skip. Tu valides qu'on fasse uniquement (a) en V1.0.1 et qu'on plan (b) pour plus tard avec LOCK ?

4. **SloEvaluatorJob double-sink** — actuellement écrit `slo_evaluation` à la fois dans `action_logs` (queryable admin UI) et dans `observability.log` (Loki-ingestible). Tu préfères canonical = ActionLog (UI-first) ou canonical = observability channel (Loki-first) ? L'autre devient debug-level secondary.

5. **KDS overflow flag** — v1.0.1 backlog connu. Tu veux qu'on l'inclut dans le batch heal F-9 ou qu'on le garde séparé sur le ticket V1.0.1 dédié KDS ?

6. **outboxRetryFailed/Drain audit emit** — admin actions sans trail aujourd'hui. Tu veux Log::channel('security') (simple, suffisant pour V1) ou AuditLogService::write (HMAC chain, immutable, plus lourd) ? Mon avis SRE : `security` channel pour les ops courantes, `audit_logs` réservé NF525.

---

## Wall-clock & word budget

- Time spent : ~20 min (RECON 8 min, specialists writing 9 min, synthesis 3 min)
- Words : architect 1480 / sre 1465 / red-team 1490 — all under 1500 cap.
- Files inspected : 14 distinct files cited with line numbers.
- 0 frozen-zone modification, 0 code touched, 0 DIRTY touch.

## Deliverables

- `reports/audit/foundation-2026-05-18/round-1/F-9-OBS/architect.json`
- `reports/audit/foundation-2026-05-18/round-1/F-9-OBS/sre.json`
- `reports/audit/foundation-2026-05-18/round-1/F-9-OBS/red-team.json`
- `reports/audit/foundation-2026-05-18/round-1/F-9-OBS/STATUS.md` (this file)
