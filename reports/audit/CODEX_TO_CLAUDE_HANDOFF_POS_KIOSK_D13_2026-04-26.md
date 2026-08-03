# Codex -> Claude Handoff POS + Kiosk + D-M13 - 2026-04-26

HANDOFF_PURPOSE: Claude orchestration/audit continuation
AUTHOR: Codex / codex-extension
DATE: 2026-04-26
CURRENT_BRANCH_OBSERVED: cycle/CV1-FIX-ORDERQUOTE-BRANCH-FORGED-IGNORE
CURRENT_HEAD_OBSERVED: f7694563a
WORKTREE_STATUS_OBSERVED: 595 git status entries

EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8

## 1. Executive Summary For Claude

Codex did not merely audit; it implemented the remaining non-D13 POS/Kiosk correction chain that the human explicitly authorized despite Phase A still being open.

Current true state:

- POS + Kiosk non-schema fixes are technically implemented in the local worktree.
- The broad backend suite has been reduced to one expected failure: `Tests\Feature\Sentinels\QueueNumberUniquenessSentinelTest`.
- That remaining failure is D-M13: no database-enforced uniqueness for `(branch_id, queue_number)`.
- JavaScript and E2E validation were green in the latest global chain.
- The repository is not release-ready because governance is still open: many modified/untracked files, quote subsystem persistence unresolved, active-cycle ambiguity, and memory/versioning policy unresolved.

Correct release posture:

`TECHNICAL_PASS_WITH_D13_GATE_AND_PHASE_A_GOVERNANCE_OPEN`

Incorrect release posture:

`RELEASE_READY`

## 2. Important Boundary

The user later asked Codex to continue without blocking and to decide responsibly, but also said not to attack D-M13 at that point. Codex therefore applied the remaining no-D13 plan and stopped. The responsible decision was recorded:

- No more random no-gate patching.
- D-M13 is now the correct next technical decision.
- Do not weaken the queue-number sentinel.
- Do not declare release-ready while D-M13 is red.

Primary decision report:

- `reports/audit/ORCHESTRATOR_RESPONSIBLE_DECISION_POS_KIOSK_2026-04-26.md`

## 3. Main Artifacts Claude Should Read

| File | Why it matters |
| --- | --- |
| `reports/audit/POS_KIOSK_PLAN_COMPLETION_STATUS_2026-04-26.md` | Mission-by-mission status for R4/R6/#3-#10. |
| `reports/audit/MASSIVE_AUDIT_POS_KIOSK_GLOBAL_POST_ORCHESTRATION_2026-04-26.md` | Broad post-orchestration audit across POS/Kiosk/KDS/outbox/payment/pricing/governance. |
| `reports/audit/ORCHESTRATOR_PLAN_V1_POS_KIOSK_FINALIZATION_2026-04-26.md` | Codex no-gate execution plan created after the massive audit. |
| `reports/audit/ORCHESTRATOR_PLAN_V1_SELF_AUDIT_2026-04-26.md` | Codex self-audit of that plan before execution. |
| `reports/audit/ORCHESTRATOR_PLAN_V1_EXECUTION_RESULT_2026-04-26.md` | Result of quote auth/transaction, kiosk variation validation, and payment transaction guard fixes. |
| `reports/audit/ORCHESTRATOR_NO_D13_FOLLOWUP_EXECUTION_2026-04-26.md` | Result of final no-D13 residual fix: POS reorder historical-pricing sentinel. |
| `reports/audit/ORCHESTRATOR_RESPONSIBLE_DECISION_POS_KIOSK_2026-04-26.md` | Final Codex decision: stop no-gate patching; D13 next. |
| `reports/post_execute_latest.log` | Append-only execution trace with validation summaries. |
| `missions/CV1-FIX-*/report.md` | Per-mission Codex reports for R4, R6, #3, #4, #5, #6, #7, #9, #10. |

## 4. What Codex Implemented In The Recent Chain

### 4.1 Kiosk R4 - Offline Queue Idempotency

Mission:

- `CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY`

Status:

- Implemented and validated.
- Merged earlier into `feat/ton-sujet` per report.

Functional result:

- Offline queue now separates durable idempotency key semantics from UI/offline waiting key semantics.
- Replay preserves the stored `localKey` as the server idempotency key.
- Legacy migration preserves existing keys instead of overwriting them.
- Backoff telemetry and force retry/cancel logic use stored keys coherently.

Validation recorded:

- Target offline queue Vitest suite: 34 pass.
- Kiosk Vitest suite: 398 pass.
- Sentinel `kioskOfflineIdPrefix.spec.js`: 3 pass.

Relevant artifacts:

- `missions/CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY/report.md`
- `reports/audit/GPT_SELF_AUDIT_CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY.md`

### 4.2 Kiosk R6 - Machine Forced Branch

Mission:

- `CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH`

Status:

- Implemented and validated.
- Merged earlier into `feat/ton-sujet` per report.

Functional result:

- Kiosk order payload `branch_id` is ignored/overridden using `KioskMachine.branch_id`.
- Forged branch payload no longer causes the earlier 403 path for a valid kiosk machine/order token.
- Negative auth/machine cases remain rejected.

Relevant files:

- `app/Http/Requests/OrderRequest.php`
- `app/Services/FrontendOrderService.php`

Relevant artifacts:

- `missions/CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH/report.md`
- `reports/audit/GPT_SELF_AUDIT_CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH.md`

### 4.3 #3 - Idempotency Recovery Branch Scope

Mission:

- `CV1-FIX-IDEMPOTENCY-RECOVERY-BRANCH-SCOPE`

Status:

- Implemented and validated.

Functional result:

- POS duplicate-key recovery lookup is scoped by `branch_id`.
- Kiosk/frontend duplicate-key recovery lookup is scoped by the forced kiosk branch.
- Cross-branch collision on the same idempotency key no longer recovers and returns another branch's order.

Relevant files:

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `tests/Feature/Sentinels/IdempotencyRecoveryBranchScopedTest.php`

Validation recorded:

- `IdempotencyRecoveryBranchScopedTest`: 4 pass.
- Idempotency filter: 27 pass.

Relevant artifact:

- `missions/CV1-FIX-IDEMPOTENCY-RECOVERY-BRANCH-SCOPE/report.md`

### 4.4 #4 - Kiosk Loyalty Double Redeem

Mission:

- `CV1-FIX-KIOSK-LOYALTY-DOUBLE-REDEEM`

Status:

- Implemented and validated.

Functional result:

- If kiosk loyalty redeem was already performed, order commit attaches/consumes the matching pending redeem instead of decrementing points a second time.
- Direct order redeem still works.
- Mismatched pending redeem vs requested discount is rejected.

Relevant files:

- `app/Services/FrontendOrderService.php`
- `tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php`

Relevant artifact:

- `missions/CV1-FIX-KIOSK-LOYALTY-DOUBLE-REDEEM/report.md`

### 4.5 #5 - Kiosk Loyalty Ledger Atomic

Mission:

- `CV1-FIX-KIOSK-LOYALTY-LEDGER-ATOMIC`

Status:

- Implemented and validated.

Functional result:

- Loyalty point decrement and ledger creation are no longer allowed to diverge silently.
- Ledger creation failure rolls back the enclosing DB transaction instead of logging and continuing after points were decremented.

Relevant files:

- `app/Services/FrontendOrderService.php`
- `tests/Feature/KioskLoyaltyLedgerAtomicTest.php`

Relevant artifact:

- `missions/CV1-FIX-KIOSK-LOYALTY-LEDGER-ATOMIC/report.md`

### 4.6 #6 - OrderQuote Branch Forged Ignore

Mission:

- `CV1-FIX-ORDERQUOTE-BRANCH-FORGED-IGNORE`

Status:

- Implemented in local worktree.
- Governance hold remains because quote subsystem files are partly untracked.

Functional result:

- Kiosk quote requests resolve branch from `KioskMachine.branch_id`.
- Forged kiosk payload `branch_id` is silently ignored.
- POS branch behavior remains separate.
- Later Codex hardening added ability/status checks for kiosk quote calls.

Relevant files:

- `app/Services/Order/OrderQuoteService.php`
- `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php`

Validation recorded:

- Quote/security targeted tests passed.
- Later full backend had only D-M13 failure.

Relevant artifact:

- `missions/CV1-FIX-ORDERQUOTE-BRANCH-FORGED-IGNORE/report.md`

### 4.7 #7 - Kiosk Quote Token Required

Mission:

- `CV1-FIX-KIOSK-QUOTE-TOKEN-REQUIRED`

Status:

- Implemented in local worktree.
- Governance hold remains because quote subsystem files are partly untracked.

Functional result:

- Kiosk commit requires `quote_token` + `quote_signature`.
- `OrderRequest` enforces request validation for kiosk tokens.
- `OrderQuoteService::sealForCommit()` enforces the same service-side.
- Expired quote token path remains the existing 410 replay path.

Relevant files:

- `app/Http/Requests/OrderRequest.php`
- `app/Services/Order/OrderQuoteService.php`
- `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php`

Relevant artifact:

- `missions/CV1-FIX-KIOSK-QUOTE-TOKEN-REQUIRED/report.md`

### 4.8 #9 - POS Quote-Binding Legacy Tests

Mission:

- `CV1-FIX-R1-POS-QUOTE-BINDING-TESTS`

Status:

- Implemented and validated.

Functional result:

- Legacy POS tests were migrated to use the shared quote-binding path rather than bypassing current quote/HMAC constraints.
- The tests now better preserve pricing SSOT and do not weaken product assertions.

Relevant files:

- `tests/Feature/Concerns/HasPosQuoteBinding.php`
- Multiple POS feature tests.

Validation recorded:

- Targeted POS legacy group: 68 pass.

Relevant artifact:

- `missions/CV1-FIX-R1-POS-QUOTE-BINDING-TESTS/report.md`

### 4.9 #10 - Outbox Fixtures K-09B

Mission:

- `CV1-FIX-R2-OUTBOX-FIXTURES-K09B`

Status:

- Implemented and validated.

Functional result:

- Legacy outbox fixtures were aligned with K-09B event contract.
- Manual payloads now include `queue_number`, `_origin`, and `payment_method` where valid.
- EventContract/listener production contract was not weakened.

Relevant files:

- `tests/Feature/OutboxTest.php`
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`

Validation recorded:

- Outbox/Event/KioskRealtime filter: 26 pass.

Relevant artifact:

- `missions/CV1-FIX-R2-OUTBOX-FIXTURES-K09B/report.md`

### 4.10 Additional Codex No-D13 Hardening After Massive Audit

Task/report:

- `ORCHESTRATOR_PLAN_V1_POS_KIOSK_FINALIZATION`
- `reports/audit/ORCHESTRATOR_PLAN_V1_EXECUTION_RESULT_2026-04-26.md`

Implemented:

1. Kiosk quote auth/active-machine enforcement.
2. Quote/replay/consume wrapped in a DB transaction at the quote service boundary.
3. Kiosk commit variation validation restored before kiosk early return.
4. Payment `transaction_no` cross-order guard added in `PaymentService`.

Relevant files:

- `app/Services/Order/OrderQuoteService.php`
- `app/Http/Requests/OrderRequest.php`
- `app/Services/PaymentService.php`
- `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php`
- `tests/Feature/MultiVariationValidationTest.php`
- `tests/Feature/PaymentNoopIdempotencyTest.php`

Validation recorded:

- PHP lint scoped files: PASS.
- Targeted backend suite: 46 pass.
- Full backend after this pass: 1079 pass, 8 skipped, 1 fail D-M13.

### 4.11 Final No-D13 Residual: POS Reorder Historical Pricing

Task/report:

- `ORCHESTRATOR_NO_D13_FOLLOWUP_EXECUTION`
- `reports/audit/ORCHESTRATOR_NO_D13_FOLLOWUP_EXECUTION_2026-04-26.md`

Implemented:

- `PosOrderController::reorderItems()` now uses the real `orderItems.orderItem` relation.
- It no longer eager-loads nonexistent `item`, `itemVariations`, `itemExtras`.
- It normalizes variations/extras from immutable `composition_snapshot` first, then legacy JSON fallback.
- Added sentinel proving historical reorder prices are display/re-import data only; final POS commit re-quotes and persists current backend SSOT price.

Relevant files:

- `app/Http/Controllers/Admin/PosOrderController.php`
- `tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php`

Validation recorded:

- Syntax: PASS.
- Targeted reorder tests: PASS.
- POS quote/pricing/reorder filter: 12 pass.
- Full backend after this pass: 1080 pass, 8 skipped, 1 fail D-M13.

## 5. Validation Snapshot

Latest recorded global validations in the chain:

| Command | Result |
| --- | --- |
| `php artisan test` | 1080 passed, 8 skipped, 1 failed: `Tests\Feature\Sentinels\QueueNumberUniquenessSentinelTest` |
| `npx vitest run` | 126 files, 853 tests passed |
| `npx vitest run tests/js/kiosk*.spec.js` | 55 files, 398 tests passed |
| `npx playwright test` | 35 passed in latest massive audit; one earlier run had 34 passed + 1 flaky passed on retry |
| `bash scripts/lint-fk-bundle-legacy.sh strict` | exit 0; release warnings remain on `public/js/kiosk.js` and `public/js/kiosk-wizard.js` |
| `git diff --check` on scoped POS/Kiosk/report files | PASS |

This handoff document itself did not rerun the full suites. It consolidates the latest recorded validations above.

## 6. Current Changed/Untracked Surfaces Claude Should Expect

Observed on 2026-04-26 during handoff creation:

- Branch: `cycle/CV1-FIX-ORDERQUOTE-BRANCH-FORGED-IGNORE`
- HEAD: `f7694563a`
- `git status --short | wc -l`: `595`

Known relevant modified/untracked paths from the POS/Kiosk chain:

| Path | Status observed | Notes |
| --- | --- | --- |
| `app/Http/Controllers/Admin/PosOrderController.php` | modified | POS reorder fix and sentinel support. |
| `app/Http/Requests/OrderRequest.php` | modified | Kiosk quote pair validation + variation validation order. |
| `app/Services/FrontendOrderService.php` | modified | Idempotency branch scope + kiosk loyalty fixes. |
| `app/Services/OrderService.php` | modified | Idempotency branch scope and related existing order-service deltas. |
| `app/Services/PaymentService.php` | modified | Payment transaction reference guard plus existing payment deltas. |
| `app/Services/Order/OrderQuoteService.php` | untracked | Critical quote/HMAC service; governance/persistence must be decided. |
| `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php` | untracked | Quote branch/auth tests. |
| `tests/Feature/PaymentNoopIdempotencyTest.php` | untracked | Payment guard test. |
| `tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php` | untracked | POS reorder SSOT sentinel. |
| `tests/Feature/Concerns/HasPosQuoteBinding.php` | untracked | POS quote-binding helper. |
| `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php` | untracked | Kiosk quote token required sentinel. |
| `tests/Feature/OutboxTest.php` | modified | K-09B fixture alignment. |
| `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php` | modified | K-09B fixture alignment. |
| `tests/Feature/BranchIsolationTest.php` | modified | KDS own-branch visibility correction. |
| `tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php` | modified | Kiosk loyalty double redeem test. |
| `tests/Feature/KioskLoyaltyLedgerAtomicTest.php` | modified | Kiosk loyalty ledger atomic test. |

Important: the dirty surface is larger than this table. This table lists the surfaces directly relevant to the recent POS/Kiosk implementation chain.

## 7. Remaining Blockers

### 7.1 D-M13 Queue Number Uniqueness

Status:

- Not implemented by Codex in this final chain.
- It is the only remaining backend test failure in the latest full suite.

Failing sentinel:

- `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest`

Problem:

- Queue numbers are business-visible and KDS/POS/Kiosk relevant.
- There is no DB-enforced unique guard for `(branch_id, queue_number)`.
- Application locks/retries are not sufficient as a final V1 guarantee.
- Current fallback behavior still allows unsafe microtime/random-style queue numbers in collision paths.

Codex recommendation:

1. Preflight duplicate scan grouped by `(branch_id, queue_number)`.
2. Backfill or deduplicate historical conflicts.
3. Add a DB unique constraint:
   - preferred: partial unique `(branch_id, queue_number)` where `queue_number IS NOT NULL` if DB supports it;
   - otherwise: MySQL-compatible generated/functional guard or full unique index after backfill, depending on confirmed production DB capability.
4. Update queue allocation collision handling:
   - deterministic bounded retry under the same branch/date lock, or
   - explicit 409 after bounded retry exhaustion.
5. Remove unsafe random/microtime fallback after the DB constraint exists.
6. Run `QueueNumberUniquenessSentinelTest`, then `php artisan test`.

Claude should not accept a fix that only updates the test or only adds application logic without DB enforcement unless a signed gate explicitly rejects DB uniqueness.

### 7.2 Phase A / Persistence Governance

Status:

- Not closed.
- Worktree still contains hundreds of modified/untracked files.

Risk:

- A clean clone/CI may not reproduce local behavior.
- Critical quote subsystem files are still untracked.
- Audit history can drift if broad untracked files are committed without bucket triage.

Required:

- Bucket triage: commit / discard / gitignore / archive.
- Close `CLOSED_VS_GIT` until `REWORK_NOT_PERSISTED: 0`.

### 7.3 Quote Subsystem Persistence

Status:

- Critical local quote service exists as untracked: `app/Services/Order/OrderQuoteService.php`.
- Earlier reports also identify `app/Models/OrderQuote.php` and `database/migrations/2026_04_25_190000_create_order_quotes_table.php` as quote subsystem persistence items.

Risk:

- Kiosk/POS quote pinning behavior can pass locally but disappear in a clean clone if untracked files are not persisted.

Required:

- Either version the quote subsystem intentionally, or rollback it intentionally.
- Do not leave it as an invisible local dependency.

### 7.4 Active Cycle Ambiguity

Status:

- Earlier audit observed two active primary contexts: W10 and Caisse V1.

Required:

- One active primary only.
- Archive the other explicitly.

### 7.5 Memory / JSONL Policy

Status:

- Earlier audit observed many untracked `memory/episodes/*.jsonl`.

Required:

- Decide whether to version or ignore memory episodes.
- If versioned, run the memory manifest/check script.

### 7.6 Legacy Kiosk Bundle Release Warning

Status:

- `bash scripts/lint-fk-bundle-legacy.sh strict` exits 0 in current environment.
- Release warnings remain for `public/js/kiosk.js` and `public/js/kiosk-wizard.js`.

Required:

- Decide shim vs purge before final release strict mode.

## 8. FoodKing Invariants Status

| Invariant | Current status |
| --- | --- |
| Backend pricing SSOT | Preserved. POS reorder sentinel now proves old prices are display/re-import only and commit re-quotes current backend price. |
| branch_id isolation | Improved and tested for POS/Kiosk idempotency, kiosk machine branch forcing, and KDS own-branch visibility. D-M13 remains DB-level queue-number guard. |
| OrderStatus enum | Not weakened in recent chain. |
| Dispatch after commit | Not touched in final no-D13 patches; earlier K-09B/outbox tests pass. |
| OrderService / FrontendOrderService symmetry | Explicitly handled for idempotency branch scope; quote/pinning surfaces tested. |
| Frozen/migrations | D-M13 migration intentionally not touched; quote migration persistence remains governance blocker. |

## 9. Claude Audit Questions To Answer

Claude should answer these before authorizing final close:

1. Are all recent no-D13 product changes acceptable as minimal and coherent with FoodKing invariants?
2. Does the D-M13 recommendation need to be partial unique, full unique, generated column unique, or another DB-specific strategy based on the actual production database?
3. Are there historical duplicate `queue_number` rows that require backfill before migration?
4. Should queue allocation retry or return 409 after duplicate-key collision?
5. Which quote subsystem files must be persisted together to make CI/clean clone reproducible?
6. Can Phase A be closed after D-M13, or must governance cleanup happen before D-M13 implementation?
7. Should legacy kiosk bundle warnings be converted into release-blocking errors now or after D-M13?

## 10. Recommended Next Claude Output

Claude should produce one of:

### Option A - PASS_TO_D13

Use if Claude agrees that non-D13 POS/Kiosk code is stable enough.

Expected output:

- D-M13 decision document/prompt for Codex.
- Exact DB strategy based on the project DB engine.
- Preflight duplicate query.
- Migration plan.
- Queue allocation collision plan.
- Validation list.

### Option B - REWORK_BEFORE_D13

Use only if Claude finds a concrete product/security/fiscal regression in the recent Codex implementation.

Expected output:

- File/line precise finding.
- Minimal rework prompt.
- Tests required.
- Confirmation whether D-M13 remains blocked until rework passes.

### Option C - GOVERNANCE_FIRST

Use if Claude decides D-M13 cannot be safely executed while the quote subsystem and worktree remain this dirty.

Expected output:

- Bucket triage plan.
- Exact commit/persistence order.
- What to stage explicitly.
- What not to stage.

## 11. Final Codex Position

Codex position for Claude:

- The two mega-plans POS + Kiosk are technically covered locally except D-M13.
- The latest product-code no-D13 residual was POS reorder historical-pricing display vs backend SSOT commit; it is now fixed and tested.
- The only proven remaining backend red signal is D-M13.
- The largest remaining risk is not another hidden application patch; it is schema/gate/governance reproducibility.
- Best responsible next move: Claude audits this handoff, then either signs/produces D-M13 implementation plan or forces governance-first triage.

HANDOFF_VERDICT: READY_FOR_CLAUDE_ORCHESTRATION
RELEASE_VERDICT: HOLD_NOT_RELEASE_READY
