# PLAN — Caisse V1 Finalisation Super Plan

Date: 2026-04-26  
Parent context: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` + `plans/masterplay/MASTERPLAY_QUEUE.md`  
Review input: `reports/audit/ULTRA_REVIEW_CAISSE_V1_FINALISATION_2026-04-26.md`  
Mode: GPT/Codex-only unless a human explicitly requests Claude  
Product code posture: evidence-first; no new product patch unless a final proof fails.

## 0. Executive Goal

Move FoodKing Caisse V1 from “masterplay implemented and scoped audits passed” to “release candidate ready for human GO/NO-GO”.

This plan does **not** restart the old implementation wave. It converts the completed masterplay work into release-grade evidence.

`PLAN_VERDICT: READY_TO_EXECUTE_FINAL_READINESS`

`IMPLEMENTATION_SCOPE: VERIFICATION_FIRST`

`GO_LIVE_STATUS: HOLD_UNTIL_FINAL_GATE`

## 1. Governing Decisions

| Decision | Source | Effect |
| --- | --- | --- |
| Payment Ledger | `GATE_PAYMENT_LEDGER_V1_2026-04-25` = Option B restricted pilot | Do not implement or enable full ledger flows in V1. `CV1-M04A` remains blocked. |
| Frozen zones | `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25` = Option C partial allowlist | No further frozen edit without a precise gate. |
| Fiscal kiosk | `GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25` = Option B POS finalize | Kiosk does not independently fiscalize paid orders in V1. |
| KDS bump | `GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25` = Option B server authority with `expected_status` | Final tests must prove server authority and conflict behavior. |
| Schema | `GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25` = Option A with rehearsal + backup | No deploy without staging rehearsal evidence. |
| Offline | `GATE_OFFLINE_SCOPE_V1_2026-04-25` = Option A read-only menu, payment disabled | Offline payment must stay refused. |
| Web payment | `GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25` = Option B off V1 | Web payment routes must stay disabled or guarded. |
| Stripe | `GATE_STRIPE_CENTS_ACTIVE_2026-04-25` = Option B inactive prod V1 guard | Stripe must stay inactive unless new gate. |
| Payment prop mutation | `GATE_PAYMENT_PROP_MUTATION_2026-04-26` = Option A refactor | M21B closed; final suite verifies no direct prop mutation regression. |

## 2. Non-Negotiable Invariants

- Backend remains pricing SSOT. No frontend total can be authoritative.
- `OrderStatus` enum is the only status source. No magic status literals.
- `branch_id` isolation must hold on every order/payment/device/KDS/fiscal/export surface.
- Dispatch/events/jobs must stay after DB commit.
- Frozen zones stay frozen unless a gate explicitly opens the exact method/surface.
- `OrderService` and `FrontendOrderService` symmetry stays documented and tested.
- Restricted pilot scope is a product boundary, not a suggestion.

## 3. Final Readiness DAG

```text
FR-00 State Inventory
  -> FR-01 Governance Reconciliation
  -> FR-02 Final Regression Suite
  -> FR-03 Build / Bundle Reproducibility
  -> FR-04 Staging Migration Rehearsal
  -> FR-05 Runtime Ops Proof
  -> FR-06 Hardware Lab Execution
  -> FR-07 Critical E2E Flows
  -> FR-08 Fiscal Evidence Packet
  -> FR-09 Security Red-Team
  -> FR-10 Operator UAT
  -> FR-11 Runbook Drill
  -> FR-12 Release Decision Packet
  -> FR-13 Pilot Watch Setup
```

Parallelization rule:

- `FR-02`, `FR-03`, `FR-04`, `FR-05`, `FR-06`, `FR-08`, `FR-09` can prepare in parallel after `FR-01`.
- `FR-12` waits for every P0 proof.
- No task may silently widen V1 beyond restricted pilot.

## 4. Task Backlog

| ORDER | TASK_ID | Objective | Depends on | Allowed writes | Required evidence | Close criteria |
| --- | --- | --- | --- | --- | --- | --- |
| 00 | CV1-FR-00-STATE-INVENTORY | Inventory dirty worktree, generated assets, untracked mission files, current queue, current gates. | none | `reports/release/`, `reports/audit/` | `git status --short`, file inventory, masterplay summary | Every modified/untracked file classified as masterplay artifact, generated asset, doc/report, or unknown. Unknown = HOLD. |
| 01 | CV1-FR-01-GOVERNANCE-RECONCILE | Reconcile stale `ACTIVE_CYCLE` and old pending frozen gate with the completed CV1 masterplay. | FR-00 | `.cursor/ACTIVE_CYCLE.md` only if explicitly allowed by human; otherwise `reports/audit/` note | Governance reconciliation report | Future agent can see CV1 masterplay closed and old gate status explained. |
| 02 | CV1-FR-02-FULL-REGRESSION | Run integrated PHP/Vitest/static regression for all CV1 touched surfaces. | FR-01 | `reports/release/` only unless failures require new plan | Test logs | All mandatory tests pass or failures converted into scoped rework tasks. |
| 03 | CV1-FR-03-BUILD-REPRODUCIBILITY | Verify built public bundles match source build and no legacy/pricing bypass was introduced. | FR-01 | `reports/release/`; product build outputs only if running build changes them and user accepts release build refresh | Build log, bundle scan, diff summary | `public/js`, `public/css`, `mix-manifest` changes are intentional and reproducible. |
| 04 | CV1-FR-04-MIGRATION-REHEARSAL | Run dry-run, backup, and staging clone rehearsal for migrations and rollback. | FR-01 | `reports/release/`, `reports/db/` | `scripts/db/*` logs | Rehearsal PASS with restore path documented, or HOLD. |
| 05 | CV1-FR-05-RUNTIME-OPS-PROOF | Prove queue, scheduler, broadcast, cache, outbox rescue, preflight fail-closed behavior. | FR-01 | `reports/release/`, `reports/ops/` | `ops-preflight`, `app:preflight-production --strict`, environment proof | Target-like env passes or blockers listed. |
| 06 | CV1-FR-06-HARDWARE-LAB | Execute M16 hardware protocols on real target devices. | FR-01 | `reports/hardware/` | Signed acceptance grid | TPE, printer, drawer, kiosk, scanner, KDS tablet, network-loss scenarios signed or marked HOLD. |
| 07 | CV1-FR-07-CRITICAL-E2E | Run end-to-end flows POS cash/card-scope, kiosk cash/offline refusal, KDS bump, auth refresh. | FR-02, FR-03, FR-05 | `reports/antigravity/`, `reports/release/` | Playwright report/screenshots/video if configured | All critical flows pass; flaky test = HOLD until classified. |
| 08 | CV1-FR-08-FISCAL-EVIDENCE | Produce fiscal Z/refund/void/HMAC evidence for selected Option B policy. | FR-02, FR-04 | `reports/fiscal/`, `reports/release/` | Scenario logs, HMAC verification | Fiscal sequence proof exists; external NF525/legal review gap stated. |
| 09 | CV1-FR-09-SECURITY-REDTEAM | Negative tests for branch crossover, forbidden payments, web/Stripe disabled, KDS illegal transitions, quote replay. | FR-02 | `reports/security/`, `reports/release/` | Red-team log | No P0 bypass. Any bypass creates new scoped task before release. |
| 10 | CV1-FR-10-OPERATOR-UAT | Operator walkthrough for POS, kiosk, KDS, OSS with restricted pilot language. | FR-07, FR-09 | `reports/uat/` | UAT checklist | Operators can complete V1 pilot flows without accessing disabled features. |
| 11 | CV1-FR-11-RUNBOOK-DRILLS | Drill rollback, outbox blocked, printer failure, TPE failure, KDS desync, kiosk network loss. | FR-05, FR-06 | `reports/runbooks/`, `reports/release/` | Drill transcript | Every runbook executable without invented commands. |
| 12 | CV1-FR-12-RELEASE-DECISION-PACKET | Produce final GO/NO-GO dossier with all gates, proofs, residual risks, and pilot scope. | FR-04..FR-11 | `reports/release/`, `docs/gates/` if human signs final gate | Release packet, final gate brief | Human can decide GO/HOLD/NO-GO from one packet. |
| 13 | CV1-FR-13-PILOT-WATCH-SETUP | Prepare post-launch watch schedule and anomaly thresholds for the restricted pilot. | FR-12 | `reports/release/`, `docs/observability/` | Watch plan | On-call, anomaly thresholds, rollback predicates, and cadence ready before GO. |

## 5. Mandatory Commands / Checks

Run from repo root unless noted.

Baseline:

```bash
npm run verify:boucle
bash scripts/agent-activity-log.sh tail 50
bash scripts/check-traceability.sh
```

Core PHP and invariant suites:

```bash
php artisan test --filter='PaymentConfirmAbilityTest|PaymentConfirmMachineResolverTest|PaymentConfirmCrossBranchTest|OrderStatusNoopSideEffectsTest|PaymentNoopIdempotencyTest|CleanupVsConfirmRaceTest|PosCollectKioskCashRouteTest|PosDiscountForgeryTest'
php artisan test --filter='QuoteExpirationTest|QuoteTamperTest|QuoteReplayIdempotencyTest|QuoteCurrencyOriginTest|QuoteDiscountAuthoritativeTest'
php artisan test --filter='KdsTransitionWhitelistTest|KdsExpectedStatusConflictTest|KitchenReleaseRuleTest|KdsPaginationOverflowTest'
php artisan test --filter='ZAggregationKioskRoutingTest|FiscalSealingHmacTest|RefundPreZTest|RefundPostZTest|VoidPreZTest|FiscalArchiveTtlTest'
php artisan test --filter='OrderBranchIsolationTest|OssAdminBranchPolicyTest|OrderServicesContractTest'
php artisan test --filter='OpsPreflightCaisseV1Test|AfterCommitDispatchTest|OutboxRescueTest|RolloutCanaryDrillTest|PostLaunchObservabilityChecklistTest'
```

Frontend and static guards:

```bash
npm test -- tests/js/paymentComponentPropMutation.spec.js tests/js/paymentComponent401Retry.spec.js tests/js/posPaymentComponentContract.spec.js tests/js/kioskCartOfflinePaymentScope.spec.js
bash scripts/lint-fk-legacy-imports.sh
bash scripts/lint-fk-bundle-legacy.sh
bash scripts/lint-fk-branch-isolation.sh
bash scripts/lint-fk-enum-status.sh
```

Ops and release tools:

```bash
bash scripts/db/dry-run.sh --help
bash scripts/db/backup.sh --help
bash scripts/db/rehearsal.sh --env=staging --connection=sqlite --backup-manifest=<manifest> --step=1 --print-command
bash scripts/ops-preflight-caisse-v1.sh --help
php artisan app:preflight-production --help
bash scripts/rollout-canary-drill.sh --help
bash scripts/post-launch-observability-check.sh --help
```

E2E:

```bash
npx playwright test -c tests/Playwright
```

If a command is unavailable because the environment is not configured, record it as `ENV_BLOCKED` with exact missing prerequisite. Do not mark it PASS.

## 6. Final GO / NO-GO Checklist

GO requires all of:

- `MASTERPLAY_QUEUE.md` remains closed except expected `CV1-M04A` blocked by Option B.
- No P0 test failure in `FR-02`, `FR-07`, `FR-08`, `FR-09`.
- Restricted pilot scope documented and visible to operator/support teams.
- Full ledger features stay disabled: split tender, partial refund ledger, offline card/TR, web Stripe payment.
- Staging migration rehearsal has backup + rollback evidence.
- Runtime preflight passes on target-like environment.
- Hardware acceptance grid signed or explicitly downgraded by human to HOLD.
- Fiscal evidence packet complete for Option B POS-finalize policy.
- Runbook drills have transcripts and no invented commands.
- Final release packet exists and links every proof.
- Human final gate says GO.

NO-GO / HOLD triggers:

- Any branch_id leak.
- Any frontend authoritative pricing path.
- Any forbidden payment method accepted.
- Any KDS illegal transition accepted.
- Any dispatch or broadcast visible before commit.
- Any fiscal sequence/HMAC inconsistency.
- Any migration rehearsal without rollback proof.
- Any critical E2E flow failing without a scoped fix.
- Any unclassified dirty worktree diff in product/runtime files.

## 7. Final Release Packet Shape

Create:

`reports/release/CAISSE_V1_RELEASE_DECISION_PACKET_2026-04-26.md`

Required sections:

1. Scope: V1 restricted pilot definition.
2. Gates: all gate IDs and decisions.
3. Masterplay status: mission table with final audit link.
4. Tests: commands, outputs, pass/fail.
5. E2E: flows, screenshots/report links, failures.
6. Hardware: acceptance grid and signatures.
7. Fiscal: Z/refund/void/HMAC proof and compliance caveat.
8. Runtime: queue/scheduler/broadcast/cache/outbox/preflight proof.
9. Security: red-team negative suite result.
10. Build: source-to-bundle reproducibility and legacy guard result.
11. Residual risks: explicit accepted risks only.
12. Final recommendation: GO, HOLD, or NO-GO.
13. Human final gate signature.

## 8. Audit Protocol

For each `CV1-FR-*` task:

1. Apply no product code change unless the task explicitly discovers a failing proof.
2. If proof fails, open a new scoped rework task with exact files and tests; do not patch opportunistically.
3. Write a GPT audit report under `reports/audit/`.
4. Update the release packet index.
5. Persist durable close facts to `memory/episodes` and Graphiti if MCP is available.

Claude is not required in this GPT-only mode. If a human asks for a second opinion, run a compact review only on `reports/release/CAISSE_V1_RELEASE_DECISION_PACKET_2026-04-26.md`, not on the entire repo.

## 9. Next Immediate Step

Start with `CV1-FR-00-STATE-INVENTORY`.

Do not run a new implementation wave first. The fastest path to a safe V1 is proving whether the completed masterplay wave is deployable under the restricted pilot boundaries.
