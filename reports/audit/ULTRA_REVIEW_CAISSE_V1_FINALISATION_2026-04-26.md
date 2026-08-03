# ULTRA REVIEW — Caisse V1 Finalisation POS / Kiosk / KDS

Date: 2026-04-26  
Mode: GPT/Codex-only review, no Claude call, no product code change  
Scope: reconcile old mega reports with current `MASTERPLAY_QUEUE.md`, identify remaining V1 finish blockers, produce the next super plan.

## 0. Verdict

`ULTRA_REVIEW_VERDICT: READY_FOR_FINAL_READINESS_PLAN`

`PRODUCT_CODE_VERDICT: NO_NEW_PRODUCT_CODE_UNLESS_EVIDENCE_FAILS`

`GO_LIVE_VERDICT: HOLD_UNTIL_RELEASE_EVIDENCE_AND_HUMAN_GO_GATE`

The old consolidated verdicts were correct at the time: **ready to plan, blocked before implementation by gates**. That state has changed materially. The masterplay queue now shows all Caisse V1 missions `CLOSED` except `CV1-M04A-PAYMENT-LEDGER-FULL`, which is intentionally `BLOCKED` because the human decision selected `GATE_PAYMENT_LEDGER_V1 = Option B — Restricted pilot`.

The next work is therefore not another broad implementation wave. It is a release-readiness and proof wave: reconcile governance, run full regression, prove runtime/hardware/fiscal/ops, freeze the restricted pilot scope, and produce a final GO/NO-GO packet.

## 1. Sources Considered

Primary current state:

- `plans/masterplay/MASTERPLAY_QUEUE.md`
- `reports/masterplay/status.json`
- `docs/gates/GATE_LOG.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M05-ORDER-QUOTE.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M06-POS-REVENUE-GUARDS.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M07-KDS-RELEASE.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M08-FISCAL-Z-NF525.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M10-OS-FOS-SYMMETRY.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M11-KIOSK-RUNTIME.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M13-MIGRATIONS-SAFETY.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M14-OPS-PREFLIGHT.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M15-ROLLOUT-CANARY.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M17-WEB-STRIPE-SCOPE.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M21B-PAYMENT-REFACTOR.md`
- `reports/audit/GPT_FINAL_AUDIT_CV1-M22-POST-LAUNCH-OBSERVABILITY.md`

Older audit and planning corpus:

- `reports/audit/CHALLENGE_MASTER_CHECKLIST_DEEP_SINGLE_2026-04-25.md`
- `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`
- `reports/audit/CLAUDE_MAX_ORCHESTRATION_CAISSE_V1_2026-04-25.md`
- `reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md`
- `reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md`
- `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`
- `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md`
- `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md`
- `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md`
- `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`
- `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`

Invalid as fresh Claude evidence:

- `reports/audit/MEGA_DISPUTE_CLAUDE_R2_CAISSE_POS_KIOSK_KDS_2026-04-25.md` — 0 bytes.
- `reports/audit/MEGA_DISPUTE_CLAUDE_R2_COMPACT_CAISSE_POS_KIOSK_KDS_2026-04-25.md` — 0 bytes.

These files remain useful only as evidence that the live Claude R2 attempt produced no usable output.

## 2. Current Masterplay State

`reports/masterplay/status.json`:

- `current_task`: `CV1-M22-POST-LAUNCH-OBSERVABILITY`
- `current_status`: `CLOSED`
- `with_audit`: `0`
- `with_final`: `1`
- timestamp: `2026-04-25T22:50:06Z`

Queue status:

| Area | Mission(s) | Current status | Review decision |
| --- | --- | --- | --- |
| Memory / traceability / sentinels | M19, M01, M02 | CLOSED | Baseline governance exists. |
| Gates | M03 | CLOSED | CV1 gate set created and approved for selected options. |
| Legacy guards | M12 | CLOSED | CI/lint guard artifacts exist. |
| Hardware docs | M16 | CLOSED | Protocols/checklists exist, but field signatures are still evidence work. |
| Test architecture | M18 | CLOSED | Coverage plan exists; final full regression still required. |
| Runbooks | M20 | CLOSED | Runbooks exist after Horizon rework; field drill still required. |
| Quickwins | M21A | CLOSED | Old POS/KDS quickwins addressed in masterplay scope. |
| Branch isolation | M09 | CLOSED | GPT PASS; must still be part of final regression. |
| POS revenue guards | M06 | CLOSED | GPT rework PASS; no remaining scoped blocker. |
| Order quote | M05 | CLOSED | GPT PASS after quote sealing at commit. |
| Payment full ledger | M04A | BLOCKED | Expected: ledger full not selected. Do not unblock without new human gate. |
| Payment restricted pilot | M04B | CLOSED | Governing V1 payment mode. |
| Fiscal Z | M08 | CLOSED | GPT PASS; external/compliance evidence still not self-certifiable. |
| KDS release | M07 | CLOSED | GPT PASS; include in E2E and concurrency final suite. |
| OS/FOS symmetry | M10 | CLOSED | GPT PASS; include contract tests in final suite. |
| Kiosk runtime | M11 | CLOSED | GPT PASS; offline card/TR refusal sealed. |
| Web/Stripe scope | M17 | CLOSED | Web payment off + Stripe inactive guard sealed. |
| Migration safety | M13 | CLOSED | Tooling/runbooks sealed; real staging rehearsal is still release evidence. |
| Ops preflight | M14 | CLOSED | Fail-closed tooling sealed; real target environment proof remains. |
| Rollout canary | M15 | CLOSED | Drill tooling sealed; real canary decision remains. |
| Payment refactor | M21B | CLOSED | Prop mutation refactor + 401 retry sealed. |
| Post-launch observability | M22 | CLOSED | Checker fail-closed; real evidence packet remains. |

## 3. Old Findings Now Reclassified

| Old concern | Current classification | Reason |
| --- | --- | --- |
| “Ready for plan, not ready for code” | Superseded | Code execution wave completed through M22. |
| 7-10 gates block implementation | Mostly resolved for selected V1 scope | CV1 gates logged in `GATE_LOG.md` with decisions; remaining gates are release/product cutover gates. |
| Payment ledger full required | Re-scoped | Human chose Option B restricted pilot; M04A stays blocked by design. |
| POS quote missing | Closed scoped | M05 GPT PASS; quote sealed/consumed at POS and kiosk commit. |
| payment-confirm / revenue guards | Closed scoped | M06 GPT rework PASS. |
| branch_id exactness | Closed scoped | M09 GPT PASS. |
| KDS server authority / expected_status | Closed scoped | M07 GPT PASS. |
| fiscal kiosk policy | Closed scoped | M08 GPT PASS under Option B POS finalize policy. |
| kiosk offline CB/TR unsafe | Closed scoped | M11 GPT PASS under offline scope Option A. |
| Web payment / Stripe active risk | Closed scoped | M17 GPT PASS: web payment off, Stripe inactive prod guard. |
| PaymentComponent prop mutation | Closed scoped | M21B GPT PASS. |
| Runtime/ops proof missing | Still release blocker | M14/M15/M22 provide tooling and fail-closed checks, not real environment proof. |
| Hardware proof missing | Still release blocker | M16 created signable artifacts; not equivalent to executed lab. |
| Claude R2 missing | Still invalid proof | Two R2 files are empty. |

## 4. Remaining Blockers

### P0 — Blocks GO, not necessarily code

1. **Final release evidence is missing.** The repo now has tooling and runbooks, but production-grade GO requires actual evidence logs: regression run, migration rehearsal, preflight against target environment, hardware lab signatures, fiscal scenario evidence, E2E flows, and post-launch checker packet.

2. **Restricted pilot scope must be locked.** Since M04A is intentionally blocked, V1 must not enable split tender, partial refunds, offline card/TR, web Stripe payment, or full ledger-dependent flows without a new gate.

3. **Legacy gate reconciliation is still confusing.** The retroactive `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` remains `PENDING_HUMAN_GATE`, while the newer CV1 gate `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25` is approved with Option C. This should be reconciled in docs before a new agent interprets the old pending gate as a live blocker for already-closed CV1 work.

4. **POS V4 product cutover gates remain pending.** `HG-W2-1` and `HG-W2-3` are still pending in `GATE_LOG.md`; these govern product cutover/KPI, not the Caisse V1 backend safety missions.

5. **Dirty worktree and generated bundles need release discipline.** The repository has extensive modified/untracked files from the masterplay wave, including generated public assets. This is not automatically wrong, but the release plan must include diff inventory, build reproducibility, and commit/packaging discipline before deployment.

6. **`.cursor/ACTIVE_CYCLE.md` remains stale at top level.** It still lists `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22` as `IN_PROGRESS`, while the Caisse V1 masterplay section is current. This can confuse future agents and should be reconciled as governance debt.

### P1 — Blocks confidence

1. Run full targeted PHP/Vitest/Playwright suite after all masterplay changes, not only mission-scoped suites.
2. Execute real staging migration rehearsal and backup/restore drill.
3. Execute `ops-preflight` and `app:preflight-production --strict` against the target-like environment.
4. Fill and sign hardware acceptance grid for TPE, printer, drawer, kiosk, KDS tablet, scanner, network loss.
5. Execute fiscal Z/refund/void/HMAC evidence scenarios, and mark what still needs external NF525/legal review.
6. Run security red-team negative tests: branch crossover, payment method out of scope, KDS illegal transitions, expired quote replay, disabled web payment/Stripe access.
7. Produce final release packet tying every old P0 to a green test, explicit deferral, or human gate.

### P2 — Can run after readiness starts

1. Polish POS/KDS UX and i18n tasks not on the critical release path.
2. Broaden observability dashboards after the first pilot data exists.
3. Prepare ledger-full V1.5/V2 plan, but do not mix it into restricted pilot release.

## 5. Risk Review

### Pricing SSOT

Current missions claim scoped PASS on quote sealing and backend authority. Final risk is regression through generated frontend bundles or legacy paths. Required proof: final grep/lint on frontend pricing authority, quote tamper/replay tests, and build reproducibility check.

### Payment / Revenue

Restricted pilot is safe only if enforcement is backend-level and visible to operators. Required proof: attempted forbidden payment methods return deterministic 403/422, Stripe/web payment routes remain off, offline card/TR is refused, and no UI flow presents an unavailable method as usable.

### OrderStatus / KDS

KDS release and expected_status are closed scoped. Required proof: illegal transitions fail, stale expected_status returns conflict, KDS overflow remains visible, and multi-screen E2E does not hide or duplicate orders.

### branch_id Isolation

M09 is closed, but final GO requires a broad sweep because branch isolation spans orders, transactions, KDS, fiscal, OSS, exports, devices, and payment attempts. Required proof: branch negative suite and lint guards pass in the final regression bundle.

### Dispatch After Commit / Sync

M14/M22 created fail-closed ops and observability checks. Required proof: queue worker, scheduler, outbox rescue, broadcast auth, and post-launch anomaly check are executed against the real target environment or a faithful staging clone.

### Fiscal / NF525

M08 closed the scoped implementation. It does not certify legal compliance by itself. Required proof: fiscal sequence scenarios, archive/HMAC verification, refund/void pre/post-Z evidence, and explicit external-compliance disclaimer or signoff.

### Governance / Memory

Graphiti was queried in this review but returned no decisive latest state beyond generic context. Disk artifacts are the authoritative source for this plan. Required proof: final plan and release decision should be persisted in `memory/episodes` and, if MCP is available, Graphiti after close.

## 6. Final Decision

The project is past the old “prepare mega plan” stage. The next correct plan is a **finalisation/readiness plan**:

1. freeze the selected V1 scope;
2. reconcile stale governance;
3. verify the entire integrated diff;
4. prove the runtime/hardware/fiscal path;
5. run critical E2E;
6. produce a final human GO/NO-GO packet.

Do not reopen broad implementation unless one of those proofs fails.
