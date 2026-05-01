# PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25

Status: SUPER MASTER DAG PLAN  
Date: 2026-04-25  
Source arbitration: Codex meta-brief + Claude terminal adversarial review  
Primary review: `reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md`  
Implementation status: BLOCKED FOR PRODUCT CODE UNTIL HUMAN GATES  

## 0. Verdict

`SUPER_MASTER_VERDICT: HUMAN_GATES_FIRST_WITH_PARALLEL_NO_CODE_WORK`

The previous master plan is useful but not sufficient as an execution artifact. Claude’s adversarial review upgrades it into a plan-of-plans DAG with 22 subplans, 10 gates, traceability, runtime/ops, migration safety, canary/rollback, hardware readiness, and post-launch observability.

Product code remains blocked. Work that can begin immediately is limited to no-code, test-only, documentation, traceability, gate preparation, CI/static scans, hardware preparation, memory discipline, and selected quick wins that do not touch frozen zones.

## 1. Inputs

| File | Role |
| --- | --- |
| `reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md` | Final adversarial review; source of this DAG. |
| `reports/audit/CODEX_META_PLAN_COMPETITION_BRIEF_CAISSE_V1_2026-04-25.md` | Codex self-critique and challenge to Claude. |
| `reports/audit/CLAUDE_SUPER_MASTER_PLAN_PROMPT_CAISSE_V1_2026-04-25.md` | Prompt used for the Claude run. |
| `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md` | Previous 9-phase plan, superseded for orchestration but still useful context. |
| `reports/audit/CLAUDE_MAX_ORCHESTRATION_CAISSE_V1_2026-04-25.md` | Prior Claude max orchestration. |
| `reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md` | Codex/Claude arbitration: Codex concepts, Claude sequence. |
| `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` | Deep Caisse V1 source. |
| `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md` | POS/caisse source. |
| `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` | Kiosk source. |
| `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` | POS/KDS finishing source. |

## 2. Governing Decision

Claude’s final adversarial verdict:

`CLAUDE_PLAN_AUDIT_VERDICT: NEEDS_MAJOR_REPLAN`

`CLAUDE_SUPER_MASTER_PLAN_VERDICT: HUMAN_GATES_FIRST`

Interpretation:

- Do not use the previous 9-phase plan as the final execution plan.
- Use it as an input to this DAG.
- Produce and maintain traceability from findings to plans/tasks/tests/gates.
- Run human gates before product code.
- In parallel with gates, execute no-code/test-only/prep work.

## 3. Required Human Gates

| Gate | Decision | Options | Recommended default | Blocks |
| --- | --- | --- | --- | --- |
| `GATE_FROZEN_ZONES_CAISSE_V1` | Exact frozen zones opened for V1 | A open all scoped, B refuse, C partial allowlist | C partial allowlist by method/surface | PLAN-04A/B, PLAN-06, PLAN-09 |
| `GATE_FISCAL_KIOSK_V1` | Kiosk paid order fiscal policy | A kiosk Z direct, B POS finalizes, C no paid kiosk V1 | C if no auditable Z, B if POS finalization ready | PLAN-08, PLAN-11 |
| `GATE_PAYMENT_LEDGER_V1` | Payment ledger or restricted pilot | A ledger full, B restricted pilot | B for pilot, A only if broad payments mandatory | PLAN-04A vs PLAN-04B |
| `GATE_KDS_BUMP_V1` | KDS bump authority | A local, B server expected_status | B with feature flag | PLAN-07 |
| `GATE_SCHEMA_MIGRATIONS_V1` | Migrations allowed | A all, B subset, C none | A with rehearsal and backup | PLAN-04, PLAN-05, PLAN-08, PLAN-13 |
| `GATE_PAYMENT_PROP_MUTATION_2026-04-26` | PaymentComponent correction | A emit/parent, B local data copy | A | PLAN-06, PLAN-21 |
| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` | Prior P0 frozen cycles signed | A all, B subset, C reverify | A if evidence exists, C otherwise | PLAN-06, PLAN-09 |
| `GATE_OFFLINE_SCOPE_V1` | Offline scope V1 | A cash-only, B card with ledger queue, C no offline | A cash-only, backend refuses CB/TR | PLAN-11 |
| `GATE_WEB_PAYMENT_SCOPE_V1` | Web/table/Stripe active? | A active, B off V1 | B unless mandatory | PLAN-17 |
| `GATE_STRIPE_CENTS_ACTIVE` | Stripe cents fix priority | A Stripe active => P0, B off V1 | Depends on web-payment gate | PLAN-17 |

## 4. Plan-Of-Plans DAG

| PLAN-ID | Name | Objective | Dependencies | Gates | Owner | Output |
| --- | --- | --- | --- | --- | --- | --- |
| PLAN-00 | MASTER_DAG_AND_GOVERNANCE | Governance, RACI, calendar, DAG | none | none | Claude/orchestrator | This file + control cadence |
| PLAN-01 | GOVERNANCE_TRACEABILITY_MATRIX | Map findings to tasks/tests/gates | PLAN-00 | none | Claude + QA | `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` |
| PLAN-02 | SENTINELS_AND_EVIDENCE_RIG | 18 fail-first sentinels | PLAN-00 | none | QA + Codex | sentinel baseline report |
| PLAN-03 | HUMAN_GATES_RESOLUTION | Sign 10 gates | PLAN-00, PLAN-02 | all gates | Human | `docs/gates/GATE_*.md` |
| PLAN-04A | PAYMENT_LEDGER_FULL | Ledger + state machine | PLAN-03 | ledger=A, schema, frozen | Codex | ledger implementation plan |
| PLAN-04B | PAYMENT_RESTRICT_PILOT | Restricted V1 payment pilot | PLAN-03 | ledger=B | Codex | restrictions + backend guards |
| PLAN-05 | ORDER_QUOTE_BACKEND_SSOT | signed quote, TTL, replay defense | PLAN-02, PLAN-03 | schema | Codex | quote implementation |
| PLAN-06 | POS_REVENUE_GUARDS | payment-confirm, cash route, cleanup race, no-op side effects | PLAN-02, PLAN-03 | frozen, prop mutation | Codex | P0 POS/payment fixes |
| PLAN-07 | KDS_RELEASE_AND_TRANSITIONS | release predicate, whitelist, expected_status, overflow | PLAN-02, PLAN-03 | KDS bump | Codex | KDS safe transitions |
| PLAN-08 | FISCAL_Z_RECONCILIATION | fiscal policy, Z, refunds, voids, HMAC | PLAN-03 | fiscal, schema | Codex + QA NF525 | fiscal proof |
| PLAN-09 | BRANCH_ISOLATION_HARDENING | branch isolation across 7+ surfaces | PLAN-02, PLAN-03 | frozen | Codex | branch isolation fixes/tests |
| PLAN-10 | OS_FOS_SYMMETRY_AND_CONTRACTS | OrderService / FrontendOrderService parity | PLAN-06, PLAN-09 | frozen signed | Codex + Claude audit | symmetry report/tests |
| PLAN-11 | KIOSK_RUNTIME_OFFLINE_POLICY | kiosk offline, enum, menu, machine, admin PIN | PLAN-03 | offline, fiscal | Codex | kiosk runtime safe |
| PLAN-12 | LEGACY_CUTOVER_AND_GUARDS | archive markings, CI lint, bundle/route guards | PLAN-00 | none | Codex + DevOps | CI/static guards |
| PLAN-13 | MIGRATION_DATA_SAFETY | dry-run, rehearsal, backups, rollback | PLAN-03 | schema | Codex + DBA | migration runbooks |
| PLAN-14 | OPS_RUNTIME_OBSERVABILITY | queue, workers, scheduler, broadcast, cache, outbox | PLAN-13 | none | DevOps | ops preflight |
| PLAN-15 | ROLLOUT_CANARY_ROLLBACK | feature flags, canary, rollback predicates | PLAN-04, PLAN-08 | none | DevOps + BE | rollout runbook |
| PLAN-16 | HARDWARE_QUALIFICATION | TPE, printer, drawer, kiosk, scanner | PLAN-00 | none | Ops/human | hardware report |
| PLAN-17 | STRIPE_AND_WEB_PAYMENT_GATE | Stripe cents, signed web payment, or disable | PLAN-03 | web payment, Stripe active | Codex | web/Stripe decision |
| PLAN-18 | TEST_ARCHITECTURE_AND_COVERAGE | test coverage matrix and campaign | PLAN-02 | none | QA | coverage report |
| PLAN-19 | MEMORY_DISCIPLINE_GRAPHITI_FALLBACK | Graphiti read/write, memory fallback | PLAN-00 | none | Claude/orchestrator | memory procedure |
| PLAN-20 | DOCUMENTATION_AND_RUNBOOK | ORDER_FLOW, BUSINESS_RULES, runbooks | PLAN-04..PLAN-08 | none | Tech writer + Claude | docs/runbooks |
| PLAN-21 | UX_FINITIONS_POS_KDS_KIOSK | discount v-model, RTL, i18n, focustrap, locale | PLAN-00 | prop mutation only for payment component | FE/Codex | UX finish tests |
| PLAN-22 | POST_LAUNCH_OBSERVABILITY_AND_ANOMALY | anomaly detection and post-launch cadence | PLAN-14, PLAN-15 | none | DevOps + QA | dashboards/on-call |

## 5. Dependency Graph

```text
PLAN-00
  -> PLAN-01
  -> PLAN-02
  -> PLAN-03
  -> PLAN-19
  -> PLAN-12
  -> PLAN-16
  -> PLAN-18
  -> PLAN-20 skeleton
  -> PLAN-21 LOT-0

PLAN-03
  -> PLAN-04A xor PLAN-04B
  -> PLAN-05
  -> PLAN-06
  -> PLAN-07
  -> PLAN-08
  -> PLAN-09
  -> PLAN-11
  -> PLAN-13
  -> PLAN-17

PLAN-04 + PLAN-05 + PLAN-06 + PLAN-09
  -> PLAN-10

PLAN-13
  -> PLAN-14

PLAN-04 + PLAN-08 + PLAN-14
  -> PLAN-15

PLAN-04..PLAN-11
  -> PLAN-18 full campaign
  -> PLAN-20 final docs

PLAN-14 + PLAN-15 + PLAN-16 + PLAN-18
  -> PLAN-22
  -> GO/NO-GO
```

## 6. Immediate Parallel Work Before Gates

These can proceed without product code changes in frozen zones:

| Work | Type | Output |
| --- | --- | --- |
| PLAN-01 traceability matrix | no-code | finding/task/test/gate matrix |
| PLAN-02 sentinel skeletons | test-only | red/green evidence baseline |
| PLAN-03 gate dossiers | docs/human | 10 gate files ready for signature |
| PLAN-12 legacy guard design | CI/static design | lint/bundle scan plan |
| PLAN-16 hardware prep | ops/human | hardware checklist and lab booking |
| PLAN-18 coverage architecture | QA docs | test matrix |
| PLAN-19 memory discipline | orchestration docs | Graphiti/fallback proof |
| PLAN-20 runbook skeleton | docs | runbook table of contents |
| PLAN-21 LOT-0 quick wins | limited code only if non-frozen and separately planned | discount v-model/RTL tests |

No frozen product change is allowed through this list.

## 7. Critical Plan Details

### PLAN-01 — Traceability Matrix

Objective: no P0/P1 finding can exist without a mapped task, test, owner, and gate.

Required columns:

`Source | Finding-ID | Risk | Severity | Plan-ID | TASK_ID | Test | Gate | Owner | Status | Evidence`

Exit:

- 0 P0 findings without `Plan-ID`.
- 0 P0 findings without test or explicit `PREUVE_MANQUANTE`.
- 0 gate-dependent findings without gate.

### PLAN-02 — Sentinels

Minimum sentinels:

- PaymentConfirmAbilitySentinelTest
- KdsTransitionWhitelistSentinelTest
- OrderListBranchExactnessSentinelTest
- OrderShowBranchGuardSentinelTest
- KioskPromoPreviewCheckoutParitySentinelTest
- OrderStatusNoopSideEffectsSentinelTest
- PosCashEndpointSentinelTest
- CleanupVsConfirmRaceSentinelTest
- PosDiscountReasonBindingSentinelTest
- PaymentComponentPropMutationSentinelTest
- AvailabilityReleaseIdempotencySentinelTest
- ItemAvailabilityChangedPayloadSentinelTest
- PosTotalServerAuthoritativeSentinelTest
- PosSubtotalForgerySentinelTest
- QueueNumberUniquenessSentinelTest
- KioskOfflineIdPrefixSentinelTest
- KioskCbTrOfflineRefusedSentinelTest
- OrderStatusEnumKioskHardcodeSentinelTest

Each sentinel must record:

- expected fail/pass state;
- reason;
- command;
- linked finding;
- linked plan;
- linked gate if any.

### PLAN-04A vs PLAN-04B — Payment Split

Option A, ledger full:

- payment ledger tables;
- state machine;
- idempotency by callback;
- refund/void/split if scoped;
- audit log;
- Stripe money tests if active.

Option B, restricted pilot:

- backend method refusal;
- UI disabled;
- audit attempts;
- documented scope restrictions;
- no silent enablement by env/config.

The super master plan cannot choose A or B without human decision.

### PLAN-05 — OrderQuote

Required contract:

- HMAC-SHA256 or equivalent signature;
- TTL, recommended 60 seconds unless product approves longer;
- intent hash covers branch, actor, items, modifiers, discounts, tax, currency, service fees;
- idempotent consume;
- replay/tamper/expiry tests;
- backend total is only payable amount.

### PLAN-06 — POS Guards

Includes:

- payment-confirm hardening;
- dedicated POS cash collection route;
- stale pending cleanup vs late confirm race;
- no-op status side effects;
- POS forged discount protection;
- client price authority removal.

### PLAN-07 — KDS

Includes:

- `expected_status`;
- transition whitelist;
- branch isolation;
- release predicate;
- pagination/overflow;
- multi-screen conflict;
- feature flag rollout.

### PLAN-08 — Fiscal

Includes:

- kiosk fiscal option A/B/C implementation;
- Z aggregation;
- refund before and after Z;
- voids;
- HMAC chain;
- archive retention;
- NF525 evidence.

### PLAN-09 — Branch Isolation

Surfaces:

- order list;
- order show;
- transaction list;
- KDS list;
- fiscal Z;
- exports/reports;
- OSS/admin branch=0.

### PLAN-12 — Legacy Guards

Required guards:

- route list scan;
- bundle scan;
- static import lint;
- dynamic include check;
- archive marker;
- CI failure on active legacy bypass.

### PLAN-13 / 14 / 15 — Production Safety

Must exist before staging:

- migration rehearsal;
- backup;
- rollback;
- queue/worker/scheduler/broadcast/cache preflight;
- feature flags;
- canary rollout;
- rollback predicates.

## 8. Test And Red-Team Matrix

| Test | Type | Surface | Plan | Blocking |
| --- | --- | --- | --- | --- |
| PaymentConfirmAbilityTest | PHP Feature | Kiosk/payment | PLAN-06 | yes |
| PaymentConfirmCrossBranchTest | PHP Feature | Kiosk/payment | PLAN-06/09 | yes |
| KdsTransitionWhitelistTest | PHP Feature | KDS | PLAN-07 | yes |
| KdsExpectedStatusConflictTest | PHP Feature concurrency | KDS | PLAN-07 | yes |
| OrderListBranchExactnessTest | PHP Feature | Orders | PLAN-09 | yes |
| OrderShowBranchGuardTest | PHP Feature | Orders | PLAN-09 | yes |
| TransactionBranchExactnessTest | PHP Feature | Transactions | PLAN-09 | yes |
| FiscalZBranchExactnessTest | PHP Feature | Fiscal | PLAN-08/09 | yes |
| OrderStatusNoopSideEffectsTest | PHP Feature | order lifecycle | PLAN-06 | yes |
| CleanupVsConfirmRaceTest | PHP concurrency | kiosk/payment | PLAN-06 | yes |
| QuoteExpirationTest | PHP Feature | pricing/payment | PLAN-05 | yes |
| QuoteTamperTest | PHP Feature | pricing/payment | PLAN-05 | yes |
| QuoteReplayIdempotencyTest | PHP Feature | pricing/payment | PLAN-05 | yes |
| QueueNumberUniquenessTest | PHP concurrency | POS/KDS | PLAN-09/13 | yes |
| PaymentLedgerStateMachineTest | PHP Feature | payment | PLAN-04A | yes if option A |
| PaymentMethodRestrictedTest | PHP Feature | payment | PLAN-04B | yes if option B |
| ZAggregationKioskRoutingTest | PHP Feature | fiscal | PLAN-08 | yes |
| RefundPreZTest / RefundPostZTest | PHP Feature | fiscal | PLAN-08 | yes |
| FiscalSealingHmacTest | PHP Unit | fiscal | PLAN-08 | yes |
| PosCollectKioskCashRouteTest | PHP Feature | POS/kiosk | PLAN-06 | yes |
| KioskOfflineIdPrefixTest | Vitest | kiosk | PLAN-11 | yes |
| KioskCbTrOfflineRefusedPlaywrightTest | Playwright | kiosk | PLAN-11 | yes |
| LegacyImportGuardLintTest | static | legacy | PLAN-12 | yes |
| BundleScanLegacyTest | CI/static | legacy | PLAN-12 | yes |
| OpsPreflightCaisseV1Test | shell/CI | ops | PLAN-14 | yes |
| MigrationDryRunTest | CI/DB | migrations | PLAN-13 | yes |
| RolloutCanaryDrillTest | drill | rollout | PLAN-15 | yes |
| HardwareTpeTimeoutTest | manual | TPE | PLAN-16 | yes |
| HardwareEscPosFailoverTest | manual | printer | PLAN-16 | yes |
| HardwareDrawerLockTest | manual | drawer | PLAN-16 | yes |

## 9. Agent Protocol

For each executable `TASK_ID`:

1. Read `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md`, this plan, and the relevant PLAN-ID.
2. Query Graphiti if available, otherwise document fallback via `memory/INDEX.md`.
3. Prepare `missions/<TASK_ID>/input.json`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md`.
4. Declare allowlist and off-limits files.
5. Run activity log `start`.
6. Execute with `codex-extension`.
7. Require Codex self-audit.
8. Validate exact tests.
9. Claude terminal audit with plan-specific prompt.
10. If `REWORK`, run bounded remediation.
11. Close only with `AUDIT_VERDICT: PASS`.

Mandatory markers:

- `EXECUTE_DELEGATION: codex-extension`
- `AUDIT_CHANNEL: claude-terminal`
- `TERMINAL_AUDIT_OK: 1`
- `AUDIT_VERDICT: PASS|REWORK`
- `SYMMETRY_NOTE:` when OrderService/FrontendOrderService touched
- `FALLBACK_REASON:` when terminal path unavailable

## 10. Ready Checklists

### Ready For Phase 0

- [ ] This super master plan exists.
- [ ] Traceability matrix exists.
- [ ] Gate files drafted.
- [ ] Graphiti/fallback documented.
- [ ] Hardware lab prepared.
- [ ] Owners assigned.

### Ready For Implementation

- [ ] 10 gates signed or explicitly resolved.
- [ ] Sentinels created and baseline recorded.
- [ ] Allowlist/off-limits defined per `TASK_ID`.
- [ ] Plan-specific audit prompts ready.
- [ ] Legacy guards designed.

### Ready For Test Campaign

- [ ] Payment path selected and implemented.
- [ ] OrderQuote implemented.
- [ ] POS guards implemented.
- [ ] KDS release implemented.
- [ ] Fiscal path implemented.
- [ ] Kiosk runtime implemented.
- [ ] Branch isolation implemented.
- [ ] Migration rehearsal complete.

### Ready For Staging

- [ ] Full PHPUnit/Vitest critical green.
- [ ] Playwright critical flows green.
- [ ] Hardware smoke signed.
- [ ] Ops preflight green.
- [ ] Rollback drill complete.
- [ ] Documentation/runbooks current.

### Ready For Go-Live

- [ ] Final Claude audit `PASS`.
- [ ] `GATE_GO_NO_GO_CAISSE_V1` signed.
- [ ] Canary plan armed.
- [ ] Alerts and on-call active.
- [ ] Post-launch monitoring live.

## 11. Final Status

`SUPER_MASTER_READY_FOR_PHASE_0: YES`

`SUPER_MASTER_READY_FOR_PRODUCT_CODE: NO`

`SUPER_MASTER_BLOCKER: HUMAN_GATES_FIRST`

Next valid work:

1. PLAN-01 traceability matrix.
2. PLAN-02 sentinel skeletons.
3. PLAN-03 gate dossiers.
4. PLAN-12 legacy guard design.
5. PLAN-16 hardware prep.
6. PLAN-19 memory discipline.

