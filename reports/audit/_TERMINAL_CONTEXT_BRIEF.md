# Bref contexte FoodKing — alimentation terminal Claude Code

Généré : 2026-04-24T14:05:46+02:00

## .cursor/ACTIVE_CYCLE.md (extrait, 60 premières lignes)
# Active Cycle – FoodKing

**Méta (SSOT `run-cycle.md` Step 0 + `AGENTS.md` § *Authoritative … cycle state*)** — requis pour que l’orchestrateur ne s’arrête pas sur *« RUNNER_MODE not set »*.

| Champ | Valeur actuelle |
| --- | --- |
| **RUNNER_MODE** | `single-session` |
| **PHASE (cycle W10)** | `IN_PROGRESS` (détail = section `CYCLE_W10_…` ci-dessous) |
| **TASK_ID** | `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22` |
| **PLAN_FILE** | `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md` |
| **REPORT_FILE** | `reports/post_execute_latest.log` (append — preuve `EXECUTE_DELEGATION` / `AUDIT_*`) |

> **ACTIVE_PRIMARY** : `CYCLE_W10_EXECUTION_CLOSEOUT` (un seul cycle peut être actif à la fois — voir B03 méga-checklist).
> Cycles plus anciens en lecture seule = **archive** déplacée dans **`.cursor/ACTIVE_CYCLE_ARCHIVE.md`** (lecture humaine / forensique uniquement, **non requise** par le parcours obligatoire).

## CYCLE_W10_EXECUTION_CLOSEOUT (IN_PROGRESS — mémoire 180 + MCP global + commit + CI + prod)

**TASK_ID** : `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22`  
**Plan SSOT** : `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md`  
**Ordre** : Piste A (POS+Centrale : PLAN-MEM-1) ∥ Piste B (humain : PLAN-MEM-3) → C (smoke) → D (commit sur « go commit ») → E (CI) → F (prod J-7→J+7).  
**Gate mémoire** : `python3 memory/verify.py` → count **≥ 175** (180 idéal) avant de considérer PLAN-MEM-1 **CLOSED**.

- **Vérif locale (2026-04-22)** : `python3 memory/verify.py` → **count = 182**, smoke `search_memory_facts` OK — gate **satisfaite** pour clôturer l'ingestion côté seuil d'épisodes (suite : commit / CI / prod selon plan `PLAN_EXECUTION_CLOSEOUT_*`).

**Gouvernance globale (2e passe 2026-04-22)** : primer multi-agents + Graphiti vivant + tokens « zéro effet négatif » → **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** + rapport **`reports/audit/AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md`**.

---

## Archive

Tous les cycles **CLOSED / COMPLETED PASSED** (W4 → W9, NF525, etc.) ont été déplacés dans **`.cursor/ACTIVE_CYCLE_ARCHIVE.md`** pour réduire le coût de lecture du parcours obligatoire (audit 2026-04-24, mission `T-PARCOURS-OPTIMIZE-001`).

- **Lecture humaine** : ouvrir `.cursor/ACTIVE_CYCLE_ARCHIVE.md`.
- **Lecture agent** : **non requise** sauf instruction explicite du plan ou du chat (ex. "reprend le rationale du cycle W9").
- **Recherche** : `rg "CYCLE_W9_" .cursor/ACTIVE_CYCLE_ARCHIVE.md` ou `git log --follow .cursor/ACTIVE_CYCLE.md`.

## Dernières entrées — memory/episodes/12_decisions_log.jsonl
{"ts":"2026-04-23T22:00:00Z","cycle":"sync_hardening_v3","lot":"NEW-02","status":"completed","files":["resources/js/services/WebSocketService.js","resources/js/services/KdsSyncService.js","tests/js/wsReconnectStormDetection.spec.js","tests/js/wsReconnectStormCircuitBreaker.spec.js","tests/js/wsAuthAndStormCohabitation.spec.js","tests/js/kdsReactsToReconnectStorm.spec.js","tests/js/eventContractDedupe.spec.js"],"description":"Echo/Pusher reconnect-storm detection + AWS-canonical decorrelated-jitter circuit breaker. Sliding 30s window of disconnect timestamps, threshold=4, breaker delay 5-30s with prev*3 growth, single-open guard, self-reset on CONNECTED. KdsSyncService subscribes to new reconnect_storm event, runs forceSync() with 0-500ms client-side jitter (audit-2 G10) to spread fleet bursts. WebSocketService.on() now returns unsubscribe (audit-2 G7), bookkeeping moved before _emit(state_change) for reentrancy safety (audit-2 G2), timer-fire clears stale timestamps (audit-2 G6), SESSION_INVALID during cool-down cancels pending pusher.connect (audit-2 A1). F-12 auth-failure path (handleSubscriptionError, _pruneAuthFailures, _resetAuthFailures, session_invalid emission) preserved bit-for-bit and proven independent via cohabitation suite. Pre-existing eventContractDedupe.spec.js cap=512 updated to cap=2048 to track NEW-01 LRU bump.","tests":"18/18 NEW-02 specs (4 files) + 790/790 vitest full suite (108 files) + 21/21 phpunit Outbox/EventContract regression + 6/6 invariants","audit_report_t":"missions/T-AUDIT-NEW02/output_codex.json","audit_report_claude":"reports/audit/AUDIT_LOT_NEW02_RECONNECT_STORM_2026-04-23.md","audit_findings":"GPT audit (T): PASS_WITH_WARNINGS — G2 reentrancy / G6 stale timestamps / G7 listener leak / G10 KDS thundering-herd → all fixed. T-MISS-A..E (5 tests) → all added. Claude audit (terminal): PASS_WITH_WARNINGS — A1 (SESSION_INVALID during cool-down → fixed: timer guard + cancel on session-invalid entry) / A2 (onAuthError missing unsubscribe → fixed) / A3 (attempts_in_window over-count → fixed: snapshot before pusher.disconnect) / A4-A5 (intentional behavior, documented). T-NEW-1 (P1) added.","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_5_high_via_codex_runner","auditor_t":"gpt_5_5_high_via_codex_runner","auditor_claude":"claude_code_cli_independent"}
{"ts":"2026-04-23T22:35:00Z","cycle":"sync_hardening_v3","lot":"NEW-03","status":"completed","files":["app/Jobs/DispatchDomainEventsJob.php","app/Jobs/SendFcmNotificationJob.php","app/Listeners/PersistOrderCreatedToOutbox.php","app/Listeners/PersistOrderStatusChangedToOutbox.php","app/Listeners/PersistOrderTableChangedToOutbox.php","app/Listeners/PersistItemAvailabilityChangedToOutbox.php","app/Traits/HasDomainEvents.php","app/Console/Commands/OutboxRetryFailedCommand.php","app/Console/Commands/OutboxRescueCommand.php","docs/operations/QUEUE_TOPOLOGY.md","tests/Feature/Queue/QueueRoutingTest.php","tests/Feature/Queue/DispatchDomainEventsFailedCallbackTest.php","tests/Feature/Queue/ShouldQueueJobsDeclareTriesTest.php"],"description":"Queue topology + retry SLO + observability. DispatchDomainEventsJob: $tries 5→6 (audit-T G2: previous tries=5 made the 300s trailing backoff entry unreachable; now 1+5+15+60+300+300=~6.4min covers Pusher restart SLA), $backoff=[1,5,15,60,300] confirmed, failed() refactored to compute persistedErrorMessage ONCE for DB+log (audit-T G3: contract_violation: prefix now in BOTH last_error column AND log context + new error_category field), Log::warning→error (terminal failure = pager-grade), structured payload (queue, connection, attempts, delay_since_creation_seconds, error_class), optional Sentry breadcrumb behind triple class_exists/function_exists guard. SendFcmNotificationJob: constructor onQueue('notifications') as SSOT (job class encodes its lane). 7 listener/command call sites cleaned of redundant ->onQueue('high') chains (audit-Claude B7: footgun if constructor changes). docs/operations/QUEUE_TOPOLOGY.md: SSOT 3-lane topology (high/notifications/default), Supervisor program-per-lane (no combined queue list in prod), --tries=0 clarification (audit-T G10), updated JSON example with last_error/error_category/attempts=6 (audit-Claude EXTRA-2). Stale comment in handle() phase 3b removed (audit-Claude EXTRA-1). Sentry-absent test narrowed with markTestSkipped + tighter error_handler scope (audit-Claude B4). New invariant test ShouldQueueJobsDeclareTriesTest (audit-Claude T-CLA-3 P1): every ShouldQueue job MUST declare $tries (guard against --tries=0 + unbounded job → infinite retries).","tests":"13/13 NEW-03 (8 routing + 4 failed_callback + 1 invariant) + 9/9 OutboxConcurrentWorkerDedupe regression + 31/31 broader Outbox/Queue/Persist/HasDomainEvents/OrderCreated regression + 6/6 invariants + 790/790 vitest","audit_report_t":"missions/T-AUDIT-NEW03/output_codex.json","audit_report_claude":"reports/audit/AUDIT_LOT_NEW03_QUEUE_SCALABILITY_2026-04-23.md","audit_findings":"Audit T (GPT-5.5 high): PASS_WITH_WARNINGS — G2 backoff window unreachable / G3 contract_violation prefix lost in log context / G10 --tries=0 doc ambiguous → all fixed. T-MISS-A,B,C,D (4 tests) added; T-MISS-E covered. Audit Claude (terminal): PASS_WITH_WARNINGS — B4 set_error_handler scope too broad / B7 7 redundant ->onQueue('high') chains (footgun) / EXTRA-1 stale comment / EXTRA-2 doc JSON outdated → all fixed. T-CLA-1,2,3 added (T-CLA-3 P1 = architectural ShouldQueue $tries invariant test).","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_5_high_via_codex_runner_then_orchestrator_patches","auditor_t":"gpt_5_5_high_via_codex_runner","auditor_claude":"claude_code_cli_independent"}
{"ts":"2026-04-23T23:35:00Z","cycle":"sync_hardening_v3","lot":"NEW-04","status":"completed","files":["database/migrations/2026_04_23_220000_create_sync_metrics_table.php","app/Services/Observability/SyncMetricsRecorder.php","app/Http/Requests/Admin/Observability/StoreClientMetricsRequest.php","app/Http/Controllers/Admin/Observability/SyncOverviewController.php","app/Providers/AppServiceProvider.php","app/Jobs/DispatchDomainEventsJob.php","resources/js/services/MetricsBatcher.js","resources/js/services/WebSocketService.js","resources/js/bootstrap.js","routes/api.php","tests/Feature/Observability/SyncMetricsRecorderTest.php","tests/Feature/Observability/SyncOverviewControllerTest.php","tests/Feature/Observability/EnsureCorrelationIdPropagatesToMetricsTest.php","tests/Feature/Observability/DispatchDomainEventsObservabilityIntegrationTest.php","tests/js/metricsBatcherFlush.spec.js","tests/js/metricsBatcherEvents.spec.js"],"description":"Observability surface: sync_metrics table (3 indexed + 2 composite), SyncMetricsRecorder singleton (non-blocking try/catch around DB::insert + correlation_id fallback to X-Correlation-ID header), GET /api/admin/observability/sync-overview (p50/p95/p99 dispatch-latency, ws.auth_failures_count, kds.sync_fallback_p50, recent_failures from domain_events, since= filter, branch_id scoping with Spatie permission gate), POST /api/admin/observability/client-metrics (whitelisted metric_type Rule::in, max:200 batch, throttle 60/min). MetricsBatcher.js (FIFO buffer 200, retry-preserving flush via unshift on 5xx, lifecycle start/stop/destroy, subscribes to ws.connected/disconnected/reconnect_storm/auth_error + kds.sync_fallback_change). DispatchDomainEventsJob.handle() success branch records outbox.dispatch_latency_ms via app(SyncMetricsRecorder)->recordEventDispatched in non-blocking try/catch. WebSocketService emits observability_metric ws.auth_failure on subscription error. bootstrap.js axios interceptor captures X-Correlation-ID header → window.__correlationId + localStorage so MetricsBatcher.readCorrelationId() can echo same id end-to-end.","tests":"15/15 SyncOverviewControllerTest + 6/6 SyncMetricsRecorderTest + 1/1 EnsureCorrelationIdPropagates + 2/2 DispatchDomainEventsObservabilityIntegration + 8/8 metricsBatcherFlush + 3/3 metricsBatcherEvents = 35 NEW-04 tests passing; 798/798 vitest full suite + 9/9 Outbox + 13/13 Queue + 8/8 KdsSyncController regression + 6/6 invariants","audit_report_t":"missions/T-AUDIT-NEW04/output_codex.json","audit_report_claude":"reports/audit/AUDIT_LOT_NEW04_OBSERVABILITY_2026-04-23.md","audit_findings":"Audit T (GPT-5.5 high): FAIL — G2 critical (cross-branch data leak: GET /sync-overview did NOT enforce branch isolation, branch-scoped operator could read any ?branch_id= → fixed via resolveBranchScope() + permission:kitchen-display-system gate) / G3 (SELECT cap silently skewed percentiles → fixed: SELECT_LIMIT=50000 const + truncated flag + orderBy('id') for determinism) / G4 (ws.auth_failure emitted client-side but missing from ALLOWED_CLIENT_METRICS whitelist → fixed in both PHP and JS) / G9 (frontend correlation ID hydration unproven → fixed: axios response interceptor in bootstrap.js captures X-Correlation-ID echoed by CorrelationIdMiddleware). T-MISS-A,B,C,D,E (5 tests) added. Audit Claude (terminal): PASS_WITH_WARNINGS — A1 (NULL/0 branch_id silently promoted ANY user to global admin → fixed: hasRole('Admin') gate added in resolveBranchScope, returns 403 instead of empty result to surface misconfiguration deterministically) / A3 (POST /client-metrics had no permission gate, asymmetric with GET → fixed: middleware now applies to both 'index' and 'clientMetrics', consistent gating) / A5 (ws.auth_failure double-counting trap if server-side recordWebSocketAuthFailure() ever wired → fixed: extensive doc comment instructs future caller to add 'source' label, dead method preserved with explicit warning). T-CLA-1,2,3,4 added (T-CLA-1 P0 = Branch Manager 403 on GET; T-CLA-2 P1 = POS Operator cross-branch 403; T-CLA-3 P0 = null branch_id without Admin role 403; T-CLA-4 P2 = Branch Manager 403 on POST too).","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_5_high_via_codex_runner_then_orchestrator_patches","auditor_t":"gpt_5_5_high_via_codex_runner","auditor_claude":"claude_code_cli_independent"}
{"ts":"2026-04-24T01:25:00Z","cycle":"mega_orchestration_2026-04-24","lot":"2.I","status":"completed","files":["app/Services/KitchenDisplaySystemOrderService.php","resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue","resources/js/helpers/kdsAllergens.js","resources/js/languages/fr.json","resources/js/languages/en.json","resources/js/languages/ar.json","tests/Feature/KDS/KdsAllergenAggregationSplitTest.php","tests/js/kdsAllergens.spec.js"],"description":"Lot 2.I G-4/G-5 closed: allergens_hash in KDS orderItems() groupBy; badge+modal on 4 columns; kdsAllergens.js helpers; i18n fr/en/ar. Post-audit: focus restore to trigger element, Tab trap in allergens modal (WCAG), inter-order PHP test + order_items/ numeric Vitest. Claude terminal audit: AUDIT_LOT_2I_2026-04-24.md (initial PASS_WITH_WARNINGS → a11y addressed).","tests":"811/811 vitest + 936 phpunit (8 skip) + 6/6 invariants","audit_report":"reports/audit/AUDIT_LOT_2I_2026-04-24.md","decided_by":"orchestrator","implementer":"cursor_composer","auditor":"claude_code_cli"}
{"ts":"2026-04-24T12:00:00Z","cycle":"mega_orchestration_2026-04-24","lot":"P2_P3_P4_batch","status":"completed","summary":"2.ABD POS idempotence tests+reçu toasts+kiosk TPE help; 2.FGH KDS per-user station filter+scheduler parked purge+kiosk payment single-flight; 2.CEJ KDS sound throttle+loyalty HTTP 25s timeout+floorplan release after paid dine-in POS (DiningTableService+OrderService)","evidence":{"check_invariants":"6/6","vitest":815,"phpunit":"939 total 8 skipped","memory_verify_episodes":182},"reports":["reports/audit/AUDIT_GLOBAL_MEGA_CONSOLIDATION_2026-04-24.md","reports/audit/TERMINAL_AUDIT_BRIEF_RAW_2026-04-24.txt"],"terminal_audit":"claude_code audit-brief exit 0","decided_by":"orchestrator_session","implementer":"cursor_composer","auditor_claude":"claude_code_cli audit-brief"}

## memory/INDEX.md (début)
# FoodKing — Index de la mémoire d'intelligence

> Table des matières navigable des épisodes Graphiti.
> Chaque fichier = un domaine. Chaque ligne JSONL = un fact atomique.

| # | Fichier | Domaine | Épisodes | Pour qui |
|---|---------|---------|----------|----------|
| 01 | `01_project_overview.jsonl` | Vision, business, stack, surfaces | ~10 | Tout LLM/dev qui découvre le projet |
| 02 | `02_architecture_invariants.jsonl` | Invariants techniques, frozen zones, multi-tenant | ~16 | Avant toute modification backend |
| 03 | `03_domain_events_sync.jsonl` | Outbox, DispatchableAfterCommit, Echo, dédup | ~14 | Travail sur sync borne↔POS↔KDS |
| 04 | `04_pricing_ssot.jsonl` | Single Source of Truth pricing, formules, edge cases | ~10 | Avant toute modif PricingService |
| 05 | `05_fiscal_nf525.jsonl` | Conformité fiscale FR, chain hash, Z, audit_log | ~12 | Conformité, compta, fiscaliste |
| 06 | `06_kiosk_features.jsonl` | Wizard tacos, multi-quantité, allergens, offline, a11y | ~14 | Dev frontend Kiosk |
| 07 | `07_pos_features.jsonl` | Park orders, multi-tender, refund, floorplan, ESC/POS, NFC | ~16 | Dev frontend POS |
| 08 | `08_kds_features.jsonl` | Bump/recall, station filter, timers, item availability | ~10 | Dev KDS |
| 09 | `09_tasks_history.jsonl` | 22 tasks V14 + Vague D + cross-wave findings (G-1, G-2, G-3, SYNC-001/002) | 24 | Audit, planning, debug régression |
| 10 | `10_tests_coverage.jsonl` | Sentinels Vitest 707 + PHPUnit 825, par domaine | ~12 | Avant tout refactor |
| 11 | `11_production_plan.jsonl` | Sync-first rollout phases 0-5, monitoring, V2 plan | ~12 | Préparation prod, ops |
| 12 | `12_decisions_log.jsonl` | ADRs, gates passed/blocked, choix d'architecture | 25 | Comprendre POURQUOI |
| 13 | `13_agents_roles.jsonl` | Multi-agents (Claude/GPT-5.4/Composer), orchestration | ~20 | Reprendre orchestration |
| 14 | `14_conventions.jsonl` | Naming, scope, safety, paths critiques, hooks | ~10 | Tout dev |

> Voir aussi : `memory/JSONL_SCHEMA.md` (schéma strict), `memory/POLICIES.md` (clear_graph + duplicates).

## Recherche typique par cas d'usage

### "Reprendre le projet sans contexte (nouveau LLM)"
```
search_memory_facts query="FoodKing project overview surfaces stack"
search_nodes query="frozen zone OrderService PaymentService"
```


## Rappel
- Neo4j/Graphiti : **pas** branché sur ce script ; lire `memory/INDEX.md` + JSONL, ou le MCP `search_memory_facts` dans **Cursor**.
- Ce fichier évite de recoller tout le chat : **réutilisé** par `audit-brief`.

## Post-implémentation (ordre — alimentation base + abonnement utile)
1) `bash scripts/after-execute-memory.sh` — manifeste + rappel `graphiti-ingest` (si JSONL touchés).
2) `bash /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/scripts/foodking-claude-orchestrate.sh context` (ce bref) ; option utile 3) `audit-brief` = audit claude -p ciblé.
