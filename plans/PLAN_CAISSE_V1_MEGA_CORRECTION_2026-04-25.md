# PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25

Status: PRE-CYCLE MASTER PLAN  
Date: 2026-04-25  
Owner strategy: Claude orchestrates, Codex implements, Claude audits  
Execution state: NOT STARTED  
Implementation permission: BLOCKED UNTIL PHASE 0 GATES  
Primary verdict: `READY_TO_PLAN_NOT_READY_TO_EXECUTE`

## 0. Decision Summary

This plan converts the Caisse/POS/Kiosk/KDS mega-audit package into a phase-by-phase correction program for a functional V1.

Final arbitration:

- We are ready to plan.
- We are not ready to implement product code until gates are signed.
- Codex was right about the domain primitives, but Claude correctly reordered the execution: close security/revenue/branch P0 first, then implement targeted contracts.
- The V1 target should be a controlled pilot unless the human gates explicitly authorize a broader production scope.
- The safest default is restricted payment scope for V1: POS cash-first or tightly scoped card, kiosk paid autonomy disabled or POS-finalized, no offline card/ticket-restaurant reconciliation unless a specific gate accepts the ledger cost.

Hard stop before code:

`NO_PRODUCT_CODE_EXECUTION_BEFORE_PHASE_0_GATES`

## 1. Prior Context

Read these files before starting any bounded cycle from this plan:

| Priority | File | Why |
| --- | --- | --- |
| P0 | `AGENTS.md` | FoodKing operating contract, invariants, Claude/Codex roles, run-cycle discipline. |
| P0 | `.cursor/ACTIVE_CYCLE.md` | Avoid phantom cycle; resume current cycle if one is active. |
| P0 | `reports/audit/CLAUDE_MAX_ORCHESTRATION_CAISSE_V1_2026-04-25.md` | Claude terminal Opus 4.7 arbitration; source of final phase order. |
| P0 | `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Readiness verdict and missing evidence. |
| P0 | `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Consolidated dispute report. |
| P0 | `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` | Deep source audit for Caisse V1. |
| P0 | `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md` | POS/caisse focus, especially page-by-page POS/KDS flow from line 529. |
| P0 | `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` | Kiosk connected/offline deep audit. |
| P1 | `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Codex R1 theory: OrderIntent, OrderQuote, PaymentProof, KitchenRelease. |
| P1 | `reports/audit/CHALLENGE_MASTER_CHECKLIST_DEEP_SINGLE_2026-04-25.md` | Master checklist across hidden and indirect surfaces. |
| P1 | `reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Integration of handoff docs from 2026-04-22 and 2026-04-26. |
| P1 | `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` | Existing Claude POS/KDS audit, 15 findings, NOT-READY 4/10. |
| P1 | `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` | Existing POS/KDS finishing lots, useful but subordinate to this V1 plan. |
| P1 | `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Orchestration memory, Graphiti, run-cycle context. |
| P1 | `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md` | Handoff and plan-comparison protocol. |
| P1 | `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md` | Historical agent/workflow index. |

Graphiti status in this Codex session:

`GRAPHITI_RUNTIME_PROOF: MISSING_IN_THIS_SESSION`

If Graphiti MCP is available in the next Cursor/Claude session, query `search_memory_facts(group_ids=["foodking"])` before opening Phase 0. If not available, use `memory/INDEX.md` and JSONL fallback exactly as documented by `AGENTS.md`.

## 2. V1 Functional Definition

A functional Caisse V1 means the following is true at the same time:

1. POS can create, quote, pay, print, and complete an order in a specific branch without client-side price authority.
2. POS payment cannot proceed without a backend quote or an explicitly gated legacy restriction.
3. A kiosk can place an order under the V1 policy chosen by gate: either pay-at-counter, POS-finalized kiosk payment, or explicitly scoped autonomous kiosk payment.
4. Kiosk offline cannot create a fake paid order and cannot replay ambiguous card/ticket-restaurant transactions.
5. KDS only receives kitchen-released orders for its branch and only accepts server-authorized status transitions.
6. Payment status cannot become paid without a valid proof, actor/device branch match, method match, and idempotent transaction reference.
7. `OrderStatus` is the only status authority; no hardcoded numeric status like `16` remains in active V1 paths.
8. `branch_id` isolation is proven across orders, transactions, KDS, devices, fiscal reports, and realtime channels.
9. Fiscal reporting has a coherent policy for kiosk/POS paid orders, refunds, voids, and Z closure.
10. Legacy routes and archives cannot bypass V1 guards.
11. Unit, feature, integration, Playwright critical flows, and hardware smoke evidence exist.
12. Claude terminal audit ends each phase with `PASS`; `REWORK` loops are explicitly bounded.

## 3. Non-Negotiable Invariants

| Invariant | Plan interpretation |
| --- | --- |
| Pricing SSOT | Backend quote and pricing services are the only authority. Frontend may display but never calculate authoritative totals, discounts, tax, or final amount. |
| OrderStatus | Active code uses enum/shared constants only. Numeric status literals are forbidden in V1 surfaces. |
| branch_id | Every read/write/payment/KDS/fiscal/device route resolves branch from actor/device and enforces exact match. |
| Dispatch after commit | KDS, broadcast, fiscal, notification, and queue-side effects occur after DB commit or via outbox. |
| Frozen zones | No edit to OrderService, FrontendOrderService, PaymentService, PricingService, migrations, or related frozen zones before signed gate. |
| Service symmetry | Any OrderService/FrontendOrderService behavior change needs explicit `SYMMETRY_NOTE`. |
| Payment proof | No `paid` mutation without proof, actor, method, branch, and idempotency validation. |
| Kitchen release | KDS visibility is a backend release decision, not a frontend or endpoint accident. |
| Offline safety | Offline may queue intent, not invent paid financial truth. |
| Fiscal trace | Z/reporting must reconcile paid orders, voids, refunds, and kiosk/POS policy. |

## 4. Codex vs Claude Arbitration

| Topic | Codex position | Claude arbitration | Final decision |
| --- | --- | --- | --- |
| Overall readiness | Ready to plan; not ready to implement without gates. | Accepted. | Plan now, execute after gates. |
| OrderIntent | Core primitive for all channels. | Useful, but centralization is P1/P2 for V1. | Use contract tests first; avoid full refactor before P0. |
| OrderQuote | Backend quote signed/expiring is required. | Accepted as P0. | Phase 3 implements quote-first POS. |
| PaymentLedger | Full ledger desirable. | Human split: full ledger costs time; restriction may be V1-safe. | Gate decides Option A ledger or Option B restricted pilot. |
| KitchenRelease | Explicit event/primitive desirable. | Formal rule may be enough V1 unless complex payment scope. | Phase 5 formalizes release; event if gate/scope requires. |
| Phase order | Contracts before patches. | Contested: security/revenue leaks first. | Phase 2 closes direct P0 before deeper contracts. |
| Kiosk fiscal | Needs explicit decision. | Accepted and upgraded to human gate. | `GATE_FISCAL_KIOSK_V1` blocks kiosk paid autonomy. |
| KDS bump | Expected status/server authority needed. | Accepted with rollout flag. | Phase 5. |
| Offline CB/TR | Dangerous without ledger. | Disable for V1 unless signed queue accepted. | Default disable. |
| Graphiti | Missing in Codex session. | Must stay in official cycle if available. | Phase 0 requires Graphiti read or documented fallback. |

## 5. Human Gates Before Implementation

These gates are not optional. They decide the shape of V1 and unblock product code changes.

| Gate | Decision | Options | Recommended default | Blocks |
| --- | --- | --- | --- | --- |
| `GATE_FROZEN_ZONES_CAISSE_V1` | Which frozen zones are open for V1 corrections? | A open under run-cycle, B refuse, C partial method list | A with explicit file/method scope | Phases 2-5 |
| `GATE_FISCAL_KIOSK_V1` | Where do paid kiosk sales enter fiscal/Z? | A kiosk Z autonomous, B POS finalizes/consolidates, C kiosk paid autonomy disabled | B if POS finalization ready, C if not | Phases 4, 6, 8 |
| `GATE_PAYMENT_LEDGER_V1` | Full payment ledger now or restricted pilot? | A ledger minimal, B restricted pilot, C attempts only | B for mono-branch pilot, A for full production | Phases 2, 4 |
| `GATE_KDS_BUMP_V1` | Who owns KDS bump state? | A local single-screen, B server `expected_status` strict | B | Phase 5 |
| `GATE_SCHEMA_MIGRATIONS_V1` | Which migrations are allowed? | A quote/payment/queue/parked all, B subset, C none | A if V1 correction is serious | Phases 1, 3, 4, 8 |
| `GATE_PAYMENT_PROP_MUTATION_2026-04-26` | How to fix PaymentComponent prop mutation? | A emit+parent state, B local copy | A | POS payment UI work |
| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` | Are previous P0 frozen cycles signed? | A all signed, B subset, C rejected | A | Any Order/Payment/Pricing refactor |

Phase 0 output must include signed markdown files under `docs/gates/` or explicit human decision references in `docs/gates/GATE_LOG.md`.

## 6. Priority Matrix

### P0 — Blocks V1

| ID | Problem | Risk | Proof required | Target phase |
| --- | --- | --- | --- | --- |
| P0-01 | `payment-confirm` missing ability/machine/method/branch proof | Direct fraud and false paid orders | 403/422 feature tests + idempotency test | 2 |
| P0-02 | `OrderService::list` LIKE branch filter and show/export gaps | Cross-branch data leak | Multi-branch feature tests | 2 |
| P0-03 | `branch_id=0` accessible without Admin role | Global data exposure | 403 tests for staff | 2 |
| P0-04 | POS discount subtotal forged from client | Revenue loss | Backend refuses forged subtotal | 2 |
| P0-05 | POS payment total local before backend quote | Wrong tax/discount/payment | Quote required test | 3 |
| P0-06 | OrderQuote missing signed/expiring consume contract | Replay/divergent pricing | Expired/modified quote tests | 3 |
| P0-07 | Payment ledger missing or payment scope too broad | Refund/split/TPE/fiscal ambiguity | Gate + ledger or restriction evidence | 4 |
| P0-08 | Stripe cents bug if active | Wrong charge | 10.99 EUR -> 1099 cents test | 4 |
| P0-09 | Public web payment by raw order id | Enumeration/fraud | Signed PaymentIntent test | 4 |
| P0-10 | Kiosk fiscal/Z policy undefined | Fiscal hole | Gate + Z tests | 6 |
| P0-11 | Cleanup stale pending vs late payment confirm | Paid but rejected order | Confirm-after-cleanup test | 2 |
| P0-12 | KDS can accept broad status changes | Chef can cancel/deliver wrongly | KDS whitelist tests | 5 |
| P0-13 | KDS expected_status not mandatory | Race/double bump | 409 conflict test | 5 |
| P0-14 | Kiosk hardcoded status `16` | Enum drift | Lint/test for enum parity | 6 |
| P0-15 | Offline CB/TR non-reconciliable | Fake paid offline orders | Backend and UI disable tests | 2 |
| P0-16 | POS collects kiosk cash via KDS endpoint | Payment/kitchen coupling | Dedicated POS route + KDS refusal | 2 |
| P0-17 | `POS_WIZARD_CONFIG` exposes client pricing | Frontend price authority | Guard/lint active bundles | 2 |
| P0-18 | Kiosk offline id format mismatch | Offline orders routed wrong | Vitest route/id test | 6 |
| P0-19 | Kiosk legacy menu endpoints active | Wrong availability/promo | `/frontend/menu` unique source test | 6 |
| P0-20 | Frozen gates unresolved | Cannot safely patch core services | Gate signatures | 0 |

### P1 — Should Be In V1 Unless Scope Is Pilot-Reduced

| ID | Problem | Risk | Proof required | Target phase |
| --- | --- | --- | --- | --- |
| P1-01 | Queue number collision under rush | Duplicate queue numbers | Unique index/retry test | 2 |
| P1-02 | Idempotency catch not branch-scoped | Cross-branch replay | Branch idempotency test | 2 |
| P1-03 | KDS cap 50 overflow | Hidden tickets | Pagination/overflow alert | 5 |
| P1-04 | KDS version second precision | Lost same-second event | Monotonic version test | 5 |
| P1-05 | Admin KDS global polling/realtime mismatch | Operational delay | Admin realtime test | 5 |
| P1-06 | Kiosk promo display reads wrong field | Wrong promo UI | UI/Vitest promo test | 6 |
| P1-07 | Kiosk analytics v2 auth/whitelist gaps | Funnel blind spots | Beacon/auth test | 6 |
| P1-08 | POS/KDS feature tests missing | Regression risk | New feature test files | 8 |
| P1-09 | Hardware lab missing | Go-live unknown | Signed lab smoke | 8 |
| P1-10 | Receipt persists too long kiosk | PII exposure | TTL test | 6 |
| P1-11 | Credit wallet callback concurrency | Double debit/credit | Concurrency test | 4 |

### P2 — After V1 Or If Time Allows

| ID | Problem | Risk | Target |
| --- | --- | --- | --- |
| P2-01 | Full OrderIntent centralization | Refactor cost | Post-V1 |
| P2-02 | Full PaymentLedger gateway abstraction | Overbuild for pilot | V1.5/V2 unless gate A |
| P2-03 | Aggregator contracts | Scope creep | V2 |
| P2-04 | Full KDS virtualization | Performance polish | V1.5 |
| P2-05 | Full APM/OpenTelemetry | Nice-to-have observability | V1.5 |
| P2-06 | Bengali/RTL/a11y polish | UX quality | Phase 8 if cheap, else V1.5 |
| P2-07 | `sync_metrics` TTL | DB hygiene | Phase 8 or V1.5 |
| P2-08 | `pos_parked_orders.expires_at` | Ops hygiene | Phase 8 if migration gate allows |

## 7. Execution Phases

Each phase must be split into bounded `TASK_ID` cycles. No phase can close without validation and Claude terminal audit.

### Phase 0 — Gates, Scope, Runtime Evidence

Target week: 1  
Execution permission: documentation/gate only  
Primary owner: human + Claude orchestrator  
Codex role: document gates and plan artifacts only

Objective:

Set the V1 scope and unblock or deny code paths before any product code edit.

Required outputs:

- `docs/gates/GATE_FROZEN_ZONES_CAISSE_V1.md`
- `docs/gates/GATE_FISCAL_KIOSK_V1.md`
- `docs/gates/GATE_PAYMENT_LEDGER_V1.md`
- `docs/gates/GATE_KDS_BUMP_V1.md`
- `docs/gates/GATE_SCHEMA_MIGRATIONS_V1.md`
- Updates or decisions for existing payment/frozen gates from 2026-04-20 and 2026-04-26.
- `reports/audit/PHASE0_SCOPE_AND_GATES_CAISSE_V1_2026-04-25.md`
- Graphiti read evidence or local memory fallback evidence.

Tasks:

1. Decide V1 payment scope: cash-only pilot vs card/Stripe/TPE vs ledger.
2. Decide kiosk paid autonomy: disabled, POS-finalized, or kiosk fiscal.
3. Decide whether Stripe/web/table payment is active in V1.
4. Decide KDS bump authority and rollout strategy.
5. Approve migrations allowed for quote/payment/queue/parked.
6. Approve frozen zones opened and exact method/file boundaries.
7. Define pilot: mono-branch or multi-branch.
8. Confirm hardware lab availability: TPE, printer, drawer, kiosk device, scanner.
9. Create `TASK_ID` backlog.

Tests:

- No product tests required.
- Gate review checklist required.
- `npm run verify:boucle` before any next phase.

Exit criteria:

- 7 gates signed or explicitly rejected with plan adaptation.
- V1 payment/kiosk/fiscal scope no longer ambiguous.
- Claude terminal audit returns `PASS` for plan coherence.

Failure mode:

- If any core gate is unresolved, implementation remains blocked.

Recommended TASK_ID:

`CAISSE_V1_P0_GATES_SCOPE_2026-04-25`

### Phase 1 — Sentinel Tests And Minimal Domain Contracts

Target week: 1-2  
Execution permission: tests/contracts only, minimal production code if gate permits  
Primary owner: Codex CLI  
Audit owner: Claude terminal

Objective:

Turn audit findings into executable proofs before fixing production code. Establish minimal contracts without a broad refactor.

Probable files:

- `tests/Feature/Sentinel/*`
- `tests/Feature/KioskPaymentStateMachineTest.php`
- `tests/Unit/OrderStateMachineTest.php`
- `tests/js/*sentinel*`
- `docs/CONTRACTS_V1.md`
- Optional gated skeletons: `app/Domain/Pricing/OrderQuote.php`, `app/Domain/Payment/PaymentIntent.php`

Tasks:

1. Add 16 sentinel tests listed in section 8.
2. Add contract documentation for OrderQuote, PaymentProof/PaymentLedger/restriction, KitchenRelease.
3. Add enum parity guard for active JS/PHP surfaces.
4. Add branch-isolation negative test templates.
5. Add outbox/after-commit assertion template.

Exit criteria:

- Sentinels exist and initially fail for the documented reason or are marked already passing with evidence.
- No hidden implementation patch is mixed into this phase unless explicitly noted and audited.

Recommended TASK_ID:

`CAISSE_V1_P1_SENTINELS_CONTRACTS_2026-04-25`

### Phase 2 — Security, Revenue, Branch, And Direct Fraud P0

Target week: 2-3  
Execution permission: product code only after gates  
Primary owner: Codex CLI  
Audit owner: Claude terminal

Objective:

Close direct exploit and leak paths before deeper architecture.

Probable files:

- `routes/api.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Services/OrderService.php`
- `app/Services/TransactionService.php`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `app/Http/Controllers/Admin/PosController.php`
- `app/Http/Requests/PosOrderRequest.php`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/kiosk/KioskPaymentComponent.vue`
- `resources/views/admin/pos/pos-v4.blade.php`
- `public/js/pos-wizard.js`
- `app/Jobs/CleanupStalePendingKioskOrders.php`

Tasks:

1. Harden `payment-confirm` with ability, kiosk machine resolution, branch match, order ownership, payment method match, allowed source status, unique transaction reference.
2. Replace branch LIKE filters with exact branch enforcement where confirmed by tests.
3. Guard `show`, export, reports, and transaction services against cross-branch reads.
4. Enforce `branch_id=0` only for authorized admin/global roles.
5. Prevent forged POS discount subtotal and require backend validation.
6. Create dedicated POS route for collecting kiosk cash; KDS endpoint must refuse payment collection.
7. Disable offline CB/TR in UI and backend unless the ledger gate chooses a safe implementation.
8. Remove active frontend pricing from POS wizard configuration.
9. Fix stale pending cleanup vs late payment confirmation behavior.
10. Add branch-scoped idempotency catches.

Tests:

- Sentinels 1-11.
- Cross-branch list/show/export/report tests.
- Kiosk payment confirm negative tests.
- POS discount forged subtotal tests.
- Outbox after-commit regression if event path touched.

Exit criteria:

- All Phase 2 P0 sentinels green.
- `SYMMETRY_NOTE` completed if OrderService/FrontendOrderService parity is touched.
- Claude audit `PASS`.

Recommended TASK_IDs:

- `CAISSE_V1_P2_PAYMENT_CONFIRM_HARDEN_2026-04-25`
- `CAISSE_V1_P2_BRANCH_ISOLATION_2026-04-25`
- `CAISSE_V1_P2_POS_REVENUE_GUARDS_2026-04-25`
- `CAISSE_V1_P2_KIOSK_OFFLINE_PAYMENT_RESTRICT_2026-04-25`

### Phase 3 — Backend Quote-First POS

Target week: 3-4  
Primary owner: Codex CLI  
Audit owner: Claude terminal

Objective:

Make backend quote the required source of payment truth for POS.

Probable files:

- `database/migrations/*_create_order_quotes_table.php`
- `app/Models/OrderQuote.php`
- `app/Services/OrderQuoteService.php`
- `app/Services/PricingService.php`
- `app/Http/Controllers/Admin/PosController.php`
- `routes/api.php`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `app/Services/OrderService.php`

Tasks:

1. Create `order_quotes` schema if gate allows.
2. Create signed intent hash with branch, actor, items, discounts, tax, currency, total, expiration.
3. Add `POST /api/admin/pos/quote`.
4. Make POS payment modal read `quote.total_ttc`.
5. Reject payment/order create without valid quote when `POS_QUOTE_REQUIRED=true`.
6. Consume quotes idempotently and prevent replay.
7. Keep frontend display-only; remove authoritative total logic from payment path.

Tests:

- Quote expired refuses payment.
- Quote intent tampered refuses payment.
- Double consume safe.
- POS modal refuses without quote.
- Discount/tax/currency from backend only.

Exit criteria:

- No active POS payment path can pay a non-quoted total.
- Claude audit accepts pricing SSOT.

Recommended TASK_ID:

`CAISSE_V1_P3_ORDER_QUOTE_POS_2026-04-25`

### Phase 4 — Payment Scope, Ledger Or Restriction, Public Payment Hardening

Target week: 4-5  
Primary owner: Codex CLI  
Audit owner: Claude terminal

Objective:

Implement the payment gate decision and prevent unsafe external payment paths.

Two implementation branches:

Option A, ledger minimal:

- `payment_attempts`
- `payment_transactions`
- state machine: pending, authorized, captured, voided, refunded
- transaction/order binding
- idempotency key per gateway callback

Option B, restricted pilot:

- disable or hide non-supported methods;
- backend refuses unsupported payment methods;
- document restrictions in `docs/V1_SCOPE_RESTRICTIONS.md`;
- leave full ledger to V1.5/V2.

Common tasks:

1. Replace public raw `/payment/{order}/pay` with signed PaymentIntent token or disable if web payment is out of scope.
2. Fix Stripe cents with `round($amount * 100)` if Stripe is active.
3. Add CreditWallet lock + idempotency if wallet remains active.
4. Ensure fiscal/payment state cannot drift from order state.
5. Add reconciliation report for late/failed payment if needed.

Tests:

- PaymentIntent invalid token -> 403.
- Stripe 10.99 -> 1099 cents if active.
- Unsupported V1 payment method refused by backend.
- Double callback idempotent.
- Wallet concurrency safe.

Exit criteria:

- Gate decision implemented exactly.
- Payment methods not in V1 cannot be reached by UI or API.

Recommended TASK_ID:

`CAISSE_V1_P4_PAYMENT_SCOPE_LEDGER_OR_RESTRICT_2026-04-25`

### Phase 5 — KDS Status, Kitchen Release, Concurrency

Target week: 5-6  
Primary owner: Codex CLI  
Audit owner: Claude terminal

Objective:

Ensure KDS only shows and mutates kitchen-authorized orders for the correct branch.

Probable files:

- `app/Http/Requests/OrderStatusRequest.php`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Models/OrderStateMachine.php`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `resources/js/services/KdsSyncService.js`
- `tests/Feature/KDS/*`

Tasks:

1. Whitelist KDS transitions to allowed kitchen statuses only.
2. Require `expected_status` and return 409 on mismatch.
3. Use monotonic versioning or sufficient precision for sync.
4. Formalize `isReleasedToKitchen()`.
5. Ensure pending/non-paid/non-released orders never appear in KDS.
6. Add pagination/overflow behavior for >50 tickets.
7. Add admin realtime parity if global admin view is in V1.

Tests:

- Chef cannot cancel/deliver.
- Mismatch expected_status -> 409.
- Pending order does not create kitchen ticket.
- >50 tickets visible with pagination/alert.
- Branch isolation on KDS list and events.

Exit criteria:

- KDS status surface is narrowed and audited.
- Claude audit `PASS`.

Recommended TASK_ID:

`CAISSE_V1_P5_KDS_RELEASE_STATUS_CONCURRENCY_2026-04-25`

### Phase 6 — Kiosk Runtime, Offline, Menu, Fiscal Policy

Target week: 6-7  
Primary owner: Codex CLI  
Audit owner: Claude terminal

Objective:

Make kiosk behavior consistent with backend truth and fiscal/payment gates.

Probable files:

- `resources/js/stores/kioskCart.js`
- `resources/js/router/modules/kioskRoutes.js`
- `resources/js/components/kiosk/KioskPaymentComponent.vue`
- `resources/js/helpers/kioskPricing.js`
- `resources/js/services/kioskMenu.js`
- `resources/js/services/kioskAnalytics.js`
- `resources/js/components/kiosk/KioskWaitingComponent.vue`
- `resources/js/helpers/kioskFormatPrice.js`
- `resources/js/domain/OrderStatus.js`
- `app/Services/FrontendOrderService.php`
- `app/Http/Controllers/Auth/KioskMachineLoginController.php`

Tasks:

1. Replace hardcoded status `16` with shared enum.
2. Align offline id prefix and route regex.
3. Ensure kiosk menu source is `/frontend/menu`.
4. Remove local pricing fallbacks from kiosk active paths.
5. Fix promo display to use backend field.
6. Disable offline CB/TR end-to-end unless safely implemented.
7. Apply fiscal gate to paid kiosk finalization.
8. Add receipt TTL and privacy guard.
9. Harden analytics beacon/auth if active.
10. Validate machine binding and admin PIN policy.

Tests:

- Kiosk offline id route.
- Kiosk enum parity.
- Promo display.
- Offline card/ticket-restaurant blocked.
- Kiosk fiscal behavior according to gate.
- Active bundle no longer consumes legacy menu endpoint.

Exit criteria:

- Kiosk can no longer create ambiguous paid state.
- Kiosk pricing is display-only and backend-fed.

Recommended TASK_ID:

`CAISSE_V1_P6_KIOSK_RUNTIME_OFFLINE_FISCAL_2026-04-25`

### Phase 7 — Legacy Cutover And Feature Flags

Target week: 7  
Primary owner: Codex CLI  
Audit owner: Claude terminal

Objective:

Prevent archived/legacy code paths from bypassing corrected V1 behavior.

Probable files:

- `public/js/pos-wizard.js`
- `resources/views/admin/pos/*`
- `routes/web.php`
- `kiosk_implementation/**`
- `borne (Remix)/**`
- `_archive/ignored_legacy_web_orchestration/**`
- `config/features.php`
- `docs/V1_FEATURE_FLAGS.md`
- `docs/V1_SCOPE_RESTRICTIONS.md`

Tasks:

1. Add visible `ARCHIVE_NON_AUTHORITATIVE` markers to legacy directories.
2. Disable or route-guard active legacy web payment and POS wizard paths.
3. Centralize V1 feature flags.
4. Add production fail-fast for incompatible flag combinations.
5. Add lint/grep guard against active imports of legacy pricing/payment paths.
6. Document cutover behavior for operators.

Tests:

- Guard verifies active bundle does not contain `pos-wizard` pricing.
- Raw `/payment/{order}/pay` no longer public or is signed.
- Feature flag config coherent in production.

Exit criteria:

- No active legacy path bypasses quote/payment/KDS guards.

Recommended TASK_ID:

`CAISSE_V1_P7_LEGACY_CUTOVER_FLAGS_2026-04-25`

### Phase 8 — Qualification, Tests, Hardware, Observability

Target week: 7-9  
Primary owner: Codex CLI + QA/human hardware  
Audit owner: Claude terminal

Objective:

Prove V1 under test, browser, and hardware conditions.

Probable files:

- `tests/Feature/Pos/VoidOrderTest.php`
- `tests/Feature/Pos/CashDrawerTest.php`
- `tests/Feature/Pos/CustomerNfcLookupTest.php`
- `tests/Feature/Pos/ParkedOrderResumeTest.php`
- `tests/Feature/KDS/KdsStatusTransitionTest.php`
- `tests/Feature/KDS/KdsStationRoutingTest.php`
- `tests/Feature/KDS/KdsConcurrentUpdateTest.php`
- `tests/e2e/playwright/*`
- `resources/js/languages/bn.json`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `database/migrations/*_add_expires_at_to_pos_parked_orders.php`
- `app/Jobs/SyncMetricsPurgeJob.php`
- `config/logging.php`
- `reports/hardware/LAB_SMOKE_*.md`

Tasks:

1. Add missing POS feature tests.
2. Add missing KDS feature tests.
3. Add Playwright flows: POS, kiosk, KDS, fiscal Z, branch isolation.
4. Run hardware lab: TPE, printer, drawer, kiosk device, scanner.
5. Add correlation_id propagation.
6. Add observability minimum: outbox latency, websocket auth failures, KDS fallback.
7. Finish a11y/i18n quick wins if not already complete.
8. Add parked order expiration and sync metrics TTL if gates allow.

Tests:

- Full PHPUnit.
- Full Vitest.
- Playwright critical flows.
- Hardware smoke.
- `bash scripts/check-invariants.sh`.
- `npm run verify:boucle`.

Exit criteria:

- Test suite and critical browser flows pass.
- Hardware smoke signed.
- Claude audit `PASS`.

Recommended TASK_ID:

`CAISSE_V1_P8_QUALIFICATION_HARDWARE_OBSERVABILITY_2026-04-25`

### Phase 9 — Preflight, Final Audit, Go/No-Go

Target week: 9-10  
Primary owner: Claude + human gate  
Codex role: runbook/preflight fixes only if scoped  
Audit owner: Claude terminal final

Objective:

Close the V1 correction cycle with production readiness evidence.

Probable files:

- `docs/RUNBOOK_V1_GO_NOGO.md`
- `reports/execution/V1_CAISSE_GO_NOGO_2026-04-25.md`
- `reports/audit/AUDIT_FINAL_CAISSE_V1_CLAUDE_2026-04-25.md`
- `.cursor/ACTIVE_CYCLE.md`
- `memory/episodes/*.jsonl`
- `docs/gates/GATE_GO_NO_GO_CAISSE_V1.md`

Tasks:

1. Run strict production preflight.
2. Verify workers, scheduler, queue, broadcast, cache, fiscal config.
3. Verify dashboards and alerting.
4. Produce runbook.
5. Produce final Claude audit.
6. Human Go/No-Go gate.
7. Ingest durable memory into JSONL/Graphiti.

Tests:

- `APP_ENV=production php artisan app:preflight-production --strict`
- smoke staging
- post-close memory validation

Exit criteria:

- `AUDIT_VERDICT: PASS`.
- `GATE_GO_NO_GO_CAISSE_V1` signed.
- `ACTIVE_CYCLE` closed or updated according to run-cycle.

Recommended TASK_ID:

`CAISSE_V1_P9_GO_NOGO_2026-04-25`

## 8. Sentinel Test Matrix

| Sentinel | Type | Target | Command pattern | Blocks |
| --- | --- | --- | --- | --- |
| `SentinelPaymentConfirmOwnership` | Feature PHP | Kiosk payment-confirm | `php artisan test --filter=SentinelPaymentConfirmOwnership` | P0 |
| `SentinelPaymentConfirmCrossBranch` | Feature PHP | branch/device mismatch | `php artisan test --filter=SentinelPaymentConfirmCrossBranch` | P0 |
| `SentinelPaymentConfirmCashRefuse` | Feature PHP | cash order cannot fake paid | `php artisan test --filter=SentinelPaymentConfirmCashRefuse` | P0 |
| `SentinelPaymentConfirmIdempotent` | Feature PHP concurrency | duplicate transaction | `php artisan test --filter=SentinelPaymentConfirmIdempotent` | P0 |
| `SentinelPosForgedDiscount` | Feature PHP | POS discount | `php artisan test --filter=SentinelPosForgedDiscount` | P0 |
| `SentinelPosQuoteRequired` | Feature + Vitest | quote-first payment | `php artisan test --filter=SentinelPosQuoteRequired` | P0 |
| `SentinelBranchIsolation` | Feature PHP | list/show/export/report | `php artisan test --filter=SentinelBranchIsolation` | P0 |
| `SentinelTransactionScope` | Feature PHP | TransactionService | `php artisan test --filter=SentinelTransactionScope` | P0 |
| `SentinelKdsWhitelist` | Feature PHP | KDS status | `php artisan test --filter=SentinelKdsWhitelist` | P0 |
| `SentinelPosKioskCashCollect` | Feature PHP | POS/kiosk cash route | `php artisan test --filter=SentinelPosKioskCashCollect` | P0 |
| `SentinelDoubleCancelIdempotent` | Feature PHP concurrency | cancel/refund | `php artisan test --filter=SentinelDoubleCancelIdempotent` | P0 |
| `kioskOfflineId` | Vitest | kiosk route/id | `npm run test:unit -- kioskOfflineId` | P0 |
| `kdsOverflow` | Vitest + Feature | KDS >50 | `npm run test:unit -- kdsOverflow` | P1/P0 if multi-branch |
| `SentinelKitchenReleaseRule` | Feature PHP | pending not in KDS | `php artisan test --filter=SentinelKitchenReleaseRule` | P0 |
| `SentinelKioskIdempotentDispatch` | Feature PHP | outbox/payment retry | `php artisan test --filter=SentinelKioskIdempotentDispatch` | P0 |
| `SentinelQueueNumberUnique` | Feature PHP concurrency | queue sequence | `php artisan test --filter=SentinelQueueNumberUnique` | P1/P0 if rush |

## 9. Validation Strategy By Phase

| Phase | Minimum validation | Audit |
| --- | --- | --- |
| 0 | Gate files, context, `npm run verify:boucle` | Claude terminal plan audit |
| 1 | Sentinel tests created; expected red/green state documented | Claude terminal |
| 2 | Targeted PHP feature tests + branch isolation + invariant guard | Claude terminal |
| 3 | Quote feature tests + POS Vitest + pricing guard | Claude terminal |
| 4 | Payment tests + Stripe/wallet tests if active | Claude terminal |
| 5 | KDS feature/concurrency + Vitest | Claude terminal |
| 6 | Kiosk Vitest + backend payment/fiscal tests | Claude terminal |
| 7 | grep/lint/bundle active path guards | Claude terminal |
| 8 | PHPUnit, Vitest, Playwright, hardware smoke | Claude terminal |
| 9 | strict preflight, staging smoke, final audit | Claude terminal + human |

## 10. Implementation Protocol

Each `TASK_ID` follows the FoodKing bounded cycle:

1. Read `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md`, and this plan.
2. Query Graphiti if available; otherwise document fallback.
3. Create `missions/<TASK_ID>/input.json`, `execute_brief.md`, `plan_excerpt.md`, and `cycle_snapshot.md`.
4. Run `run-cycle <TASK_ID>` or follow `.cursor/commands/run-cycle.md` steps explicitly.
5. Start activity log before product edits:
   `bash scripts/agent-activity-log.sh start <TASK_ID> <files...>`
6. Execute with `codex-extension` primary:
   `npm run codex:complex -- <TASK_ID>`
7. Require Codex self-audit:
   `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md`
8. Validate with declared tests.
9. Audit with Claude terminal:
   `bash scripts/foodking-claude-orchestrate.sh context`
   `bash scripts/foodking-claude-orchestrate.sh audit "<phase audit prompt>"`
10. If `REWORK`, loop with bounded delta plan.
11. If `PASS`, close phase, update reports, and write memory.

Required markers:

- `EXECUTE_DELEGATION: codex-extension`
- `AUDIT_CHANNEL: claude-terminal`
- `TERMINAL_AUDIT_OK: 1`
- `AUDIT_VERDICT: PASS|REWORK`
- `SYMMETRY_NOTE:` if order services touched
- `FALLBACK_REASON:` if primary route fails

## 11. Mission Backlog

| Sequence | TASK_ID | Phase | Scope | Predecessor |
| --- | --- | --- | --- | --- |
| 1 | `CAISSE_V1_P0_GATES_SCOPE_2026-04-25` | 0 | Gates, scope, runtime evidence | none |
| 2 | `CAISSE_V1_P1_SENTINELS_CONTRACTS_2026-04-25` | 1 | Sentinel tests and contracts | Phase 0 plan accepted |
| 3 | `CAISSE_V1_P2_PAYMENT_CONFIRM_HARDEN_2026-04-25` | 2 | payment-confirm security | frozen/payment gates |
| 4 | `CAISSE_V1_P2_BRANCH_ISOLATION_2026-04-25` | 2 | branch/order/transaction isolation | frozen gate |
| 5 | `CAISSE_V1_P2_POS_REVENUE_GUARDS_2026-04-25` | 2 | POS discount/client total direct guards | frozen/payment gate |
| 6 | `CAISSE_V1_P2_KIOSK_OFFLINE_PAYMENT_RESTRICT_2026-04-25` | 2 | disable unsafe offline methods | payment/kiosk gates |
| 7 | `CAISSE_V1_P3_ORDER_QUOTE_POS_2026-04-25` | 3 | quote-first POS | schema gate |
| 8 | `CAISSE_V1_P4_PAYMENT_SCOPE_LEDGER_OR_RESTRICT_2026-04-25` | 4 | payment ledger or restriction | payment ledger gate |
| 9 | `CAISSE_V1_P5_KDS_RELEASE_STATUS_CONCURRENCY_2026-04-25` | 5 | KDS release/status | KDS bump gate |
| 10 | `CAISSE_V1_P6_KIOSK_RUNTIME_OFFLINE_FISCAL_2026-04-25` | 6 | kiosk runtime/fiscal/offline | fiscal/payment gates |
| 11 | `CAISSE_V1_P7_LEGACY_CUTOVER_FLAGS_2026-04-25` | 7 | legacy quarantine and flags | phase 2-6 decisions |
| 12 | `CAISSE_V1_P8_QUALIFICATION_HARDWARE_OBSERVABILITY_2026-04-25` | 8 | tests/hardware/observability | phase 2-7 mostly complete |
| 13 | `CAISSE_V1_P9_GO_NOGO_2026-04-25` | 9 | preflight and final gate | all previous PASS |

## 12. Risk Register

| Risk | Severity | Trigger | Mitigation |
| --- | --- | --- | --- |
| Gates delayed | High | No human decision within Phase 0 | Freeze implementation, continue only docs/tests not touching product. |
| Overbuilding payment ledger | High | Full ledger chosen for pilot without capacity | Use restricted pilot Option B unless multi-branch/full payment is mandatory. |
| Underbuilding payment proof | Critical | UI disables method but backend still accepts | Backend guard required for every UI flag. |
| Quote refactor breaks POS | High | Quote required without rollout flag | Use `POS_QUOTE_REQUIRED` flag and tests; remove fallback after acceptance. |
| Branch leakage remains in secondary reports | Critical | Only list/show fixed | Explicit tests for export/report/transaction/KDS/fiscal. |
| KDS clients break on expected_status | Medium | Mandatory rollout without legacy handling | Feature flag plus deprecation log. |
| Kiosk paid autonomy unclear | Critical | Fiscal gate unresolved | Disable paid autonomy until gate signed. |
| Offline retry creates duplicate payment | Critical | CB/TR offline allowed | Disable or implement signed queue + ledger. |
| Legacy path bypasses guards | High | Old route still public | Phase 7 route/bundle guards. |
| Hardware unavailable | High | Lab not ready by Phase 8 | Move go-live; no substitute for hardware smoke. |
| Graphiti unavailable | Medium | MCP absent | Use memory/INDEX + JSONL, document fallback. |
| Claude terminal stalls | Medium | No output or quota | Use shorter prompts and fallback documented per `AGENTS.md`. |

## 13. What Not To Overbuild In V1

| Area | Avoid | V1 approach |
| --- | --- | --- |
| OrderIntent | Central refactor for all channels | Contract tests + documentation; centralize later. |
| PaymentLedger | Generic gateway abstraction | Gate chooses minimal ledger or restricted pilot. |
| KitchenTicketCreated | New full event pipeline if not needed | Formal release rule + tests; event only if scope requires. |
| Queue sequencer | Distributed sequencer | DB lock/index/retry unless multi-branch rush proves more. |
| KDS virtualization | Full virtualized UI | Pagination/overflow alert. |
| Observability | Full APM | Correlation_id + key dashboards. |
| Aggregators | Uber/Deliveroo integration cleanup | Out of V1. |

## 14. Where Minimal Patches Are Dangerous

| Area | Dangerous shortcut | Required correction |
| --- | --- | --- |
| payment-confirm | Add ability only | Ability + machine + branch + method + status + transaction idempotency. |
| OrderService branch | Replace LIKE only | Actor branch default + list/show/export/report/transaction tests. |
| Stripe cents | Cast decimal to int | `round(amount * 100)` with tests. |
| Quote | Signature without expiry | Expiry + intent hash + consume idempotency. |
| Offline card disable | UI-only flag | UI + backend refusal + tests. |
| Fiscal kiosk disable | Hide button only | Backend refusal and fiscal gate coherence. |
| KDS expected_status | Force immediately | Flagged rollout + 409 tests. |
| POS wizard pricing | Remove one config value | Add guard against active frontend pricing. |
| CreditWallet | Lock only | Lock + callback idempotency. |
| Legacy menu | Change current import only | Bundle/route guard against old endpoint. |

## 15. Documentation Outputs To Produce During Execution

| Phase | Document |
| --- | --- |
| 0 | `reports/audit/PHASE0_SCOPE_AND_GATES_CAISSE_V1_2026-04-25.md` |
| 1 | `reports/audit/PHASE1_SENTINELS_STATUS_CAISSE_V1_2026-04-25.md` |
| 2 | `reports/audit/PHASE2_SECURITY_REVENUE_AUDIT_CAISSE_V1_2026-04-25.md` |
| 3 | `reports/audit/PHASE3_ORDER_QUOTE_AUDIT_CAISSE_V1_2026-04-25.md` |
| 4 | `reports/audit/PHASE4_PAYMENT_SCOPE_AUDIT_CAISSE_V1_2026-04-25.md` |
| 5 | `reports/audit/PHASE5_KDS_RELEASE_AUDIT_CAISSE_V1_2026-04-25.md` |
| 6 | `reports/audit/PHASE6_KIOSK_RUNTIME_AUDIT_CAISSE_V1_2026-04-25.md` |
| 7 | `reports/audit/PHASE7_LEGACY_CUTOVER_AUDIT_CAISSE_V1_2026-04-25.md` |
| 8 | `reports/audit/PHASE8_QUALIFICATION_AUDIT_CAISSE_V1_2026-04-25.md` |
| 9 | `reports/audit/AUDIT_FINAL_CAISSE_V1_CLAUDE_2026-04-25.md` |

## 16. Final Human Questions

These questions change implementation shape:

1. Is kiosk paid autonomy in V1 required, or can kiosk orders be paid/finalized at POS?
2. Is V1 mono-branch pilot or immediate multi-branch rollout?
3. Is Stripe/web payment active at go-live?
4. Is full ledger mandatory now, or is restricted pilot acceptable?
5. Are migrations for quotes/payment/queue/parked allowed?
6. Is the hardware lab ready in the first execution window?
7. Can POS legacy/wizard routes be removed, or only quarantined?

## 17. Final Plan Verdict

`PLAN_VERDICT: READY_FOR_PHASE_0`

`IMPLEMENTATION_VERDICT: BLOCKED_UNTIL_GATES`

`CLAUDE_ALIGNMENT: READY_TO_WRITE_MEGA_PLAN`

`CODEX_ALIGNMENT: READY_TO_PLAN_NOT_READY_TO_EXECUTE`

The next valid action is Phase 0: gates, scope, runtime evidence, and creation of bounded `TASK_ID` missions. Product code edits before Phase 0 approval would violate the repo operating contract.

