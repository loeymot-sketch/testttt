# TASK_REGISTRY_2026-04-26 — Caisse V1 Ultra Finition

Status: PLAN_ONLY.

Legend:
- `HUMAN`: requires product/governance decision.
- `CODEX`: implementable by Codex after dependencies.
- `BLOCKED_PHASE_A_UNSIGNED`: cannot start before A closes.
- `BLOCKED_GATE`: cannot start without named human gate.
- `DEFERRED_V15`: tracked backlog, not V1 release blocker.

| Order | Task ID | Phase | Owner | Status now | Depends on | Primary allowlist | Mandatory validation |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 001 | CV1-FIX-R0-PERSISTENCE-AUDIT-SIGN | A | HUMAN | READY | none | reports/audit only | git status classification signed |
| 002 | CV1-GOV-ATOMIC-PERSISTENCE-COMMITS | A | HUMAN | READY_AFTER_A1 | A.1 | git index only | V1 product scopes clean or signed residual |
| 003 | CV1-GOV-MEMORY-EPISODES-VERSIONING | A | HUMAN | READY | none | memory docs/reports | memory policy signed |
| 004 | CV1-INFRA-CLOSED-VS-GIT-AUDIT | A | CODEX | READY_AFTER_A2 | A.2 | reports/audit | 0 unhandled REWORK_NOT_PERSISTED |
| 005 | CV1-GOV-SINGLE-ACTIVE-PRIMARY | A | HUMAN | READY | none | .cursor cycle files | one ACTIVE_PRIMARY |
| 006 | CV1-GATE-M13-ORDER-QUOTES-MIGRATION-DECISION | A | HUMAN | READY | none | docs/gates + migration decision | gate signed or rollback signed |
| 101 | CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY | B | CODEX | BLOCKED_PHASE_A_UNSIGNED | A closed | kioskOfflineQueue + tests | targeted Vitest PASS |
| 102 | CV1-FIX-R3-KDS-OWN-BRANCH-VISIBLE | B | CODEX | BLOCKED_PHASE_A_UNSIGNED | A closed, B.1 | KDS service/controller/tests | BranchIsolation + SyncComprehensive PASS |
| 103 | CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH | B | CODEX | BLOCKED_PHASE_A_UNSIGNED | A closed, B.2 | kiosk machine resolver/tests | KioskSecurityTest PASS |
| 104 | CV1-FIX-R1-POS-QUOTE-BINDING-TESTS | B | CODEX | BLOCKED_PHASE_A_UNSIGNED | A closed, C.2 decision stable | tests/helper only | POS legacy filters PASS, app diff empty |
| 105 | CV1-FIX-R2-OUTBOX-FIXTURES-K09B | B | CODEX | BLOCKED_PHASE_A_UNSIGNED | A closed | outbox tests only | Outbox + EventContract filters PASS |
| 106 | CV1-FIX-R5-QUEUE-NUMBER-UNIQUE-MIGRATION | B | CODEX | BLOCKED_GATE | A.6 schema gate | migration + sentinel | QueueNumberUniquenessSentinelTest PASS |
| 201 | CV1-FIX-POS-SHOW-BRANCH-IDOR | C | CODEX | BLOCKED_PHASE_A_UNSIGNED | B.2 | PosOrderController + sentinels | cross-branch show denied |
| 202 | CV1-FIX-QUOTE-EXPIRY-EXPLICIT-REJECT | C | CODEX | BLOCKED_PHASE_A_UNSIGNED | B.1 | OrderQuoteService + quote tests | expired quote returns 409 |
| 203 | CV1-FIX-PAYMENT-IDEMPOTENT-TX-NO | C | CODEX | BLOCKED_PHASE_A_UNSIGNED | B.3 | PaymentService + tests | duplicate transaction_no => one transaction |
| 204 | CV1-FIX-POS-REORDER-REQUOTE | C | CODEX | BLOCKED_PHASE_A_UNSIGNED | C.2 | reorder path + tests | stale price not reused |
| 205 | CV1-CLEANUP-LEGACY-PRICING-PATH | C | CODEX | BLOCKED_PHASE_A_UNSIGNED | B.4, C.2 | OrderService/config/tests | no legacy pricing branch |
| 206 | CV1-FIX-TAX-RATE-CAST | C | CODEX | BLOCKED_PHASE_A_UNSIGNED | B.4 | Tax model/tests | numeric cast tests PASS |
| 207 | CV1-FIX-ORDERSERVICE-ERROR-TAXONOMY | C | CODEX | BLOCKED_PHASE_A_UNSIGNED | C.1-C.5 | OrderService/logging/tests | typed catches + log tests PASS |
| 301 | CV1-REFACTOR-POSCOMPONENT-SPLIT | D | CODEX | BLOCKED_PHASE_A_UNSIGNED | C.1,C.2,C.4,C.5 | POS Vue components/tests | POS E2E + Vitest PASS |
| 302 | CV1-TESTS-VITEST-POS-STORES | D | CODEX | BLOCKED_PHASE_A_UNSIGNED | D.1 boundaries stable | POS stores/tests | store tests PASS |
| 303 | CV1-FRONT-POS-ERROR-BOUNDARY | D | CODEX | BLOCKED_PHASE_A_UNSIGNED | D.1 | POS layout/error tests | cart preserved on error |
| 304 | CV1-FRONT-POS-QUOTE-COUNTDOWN | D | CODEX | BLOCKED_PHASE_A_UNSIGNED | C.2,D.1 | Payment/checkout tests | expired quote disables payment |
| 305 | CV1-FRONT-OBSERVABILITY-SENTRY | D | HUMAN+CODEX | BLOCKED_GATE | D.1 | client logger/config docs | no secrets, no-op safe |
| 306 | CV1-CUTOVER-LEGACY-BUNDLES-DECISION | D/G | HUMAN+CODEX | BLOCKED_GATE | A closed | blade/bundle docs/tests | strict legacy scan PASS or shim signed |
| 401 | CV1-FEAT-POS-CATALOG-SEARCH | E | CODEX | BLOCKED_PHASE_A_UNSIGNED | C.6,D.1 | item search + POS catalog | search tests PASS |
| 402 | CV1-FEAT-POS-BESTSELLERS-CONFIG | E | CODEX | BLOCKED_PHASE_A_UNSIGNED | D.1 | POS catalog/tests | no hardcoded product names |
| 403 | CV1-FIX-PRICING-MANDATORY-ADDONS | E | CODEX | BLOCKED_PHASE_A_UNSIGNED | C.5,C.6 | PricingService/tests | mandatory addon tests PASS |
| 404 | CV1-FEAT-POS-FAVORITES | E | CODEX | BLOCKED_PHASE_A_UNSIGNED | D.1 | POS/catalog existing fields | no schema unless gate |
| 405 | CV1-MIGRATE-POS-TO-MENU-PROJECTION | E | CODEX | BLOCKED_PHASE_A_UNSIGNED | B.2,C.6 | menu projection/POS store | projection tests PASS |
| 406 | CV1-FIX-POS-VISIBILITY-RESPECT | E | CODEX | BLOCKED_PHASE_A_UNSIGNED | E.5 | pricing/catalog tests | visible_on respected |
| 451 | V15-FEAT-CATEGORY-BRANCH-AVAILABILITY | E | BACKLOG | DEFERRED_V15 | V1 closed | none | backlog only |
| 452 | V15-FEAT-PRODUCT-MODIFIERS | E | BACKLOG | DEFERRED_V15 | V1 closed | none | backlog only |
| 501 | CV1-FEAT-KDS-REALTIME-PUSH | F | CODEX | BLOCKED_PHASE_A_UNSIGNED | B.2,C closed | KDS frontend/tests | KDS realtime E2E PASS |
| 502 | CV1-FEAT-OSS-REALTIME-PUSH | F | CODEX | BLOCKED_PHASE_A_UNSIGNED | F.1 | OSS frontend/tests | OSS realtime E2E PASS |
| 503 | CV1-FRONT-REALTIME-DEDUP | F | CODEX | BLOCKED_PHASE_A_UNSIGNED | F.1,F.2 | realtime helper/consumers/tests | dedup Vitest PASS |
| 504 | CV1-OBS-ORDER-LIFECYCLE-CHANNEL | F | CODEX | BLOCKED_PHASE_A_UNSIGNED | C.7 | logging + call sites/tests | lifecycle logs routed |
| 551 | V15-FEAT-SYNC-BACKFILL-ENDPOINT | F | BACKLOG | DEFERRED_V15 | V1 closed | none | backlog only |
| 552 | V15-INFRA-BROADCAST-CIRCUIT-BREAKER | F | BACKLOG | DEFERRED_V15 | V1 closed | none | backlog only |
| 601 | CV1-CLOSURE-FULL-TEST-SUITE | G | CODEX | BLOCKED_PHASE_A_UNSIGNED | A-F closed | reports only | php/vitest/playwright/lints PASS |
| 602 | CV1-REHEARSAL-V1-STAGING | G | HUMAN+CODEX | BLOCKED_PHASE_A_UNSIGNED | G.1 | reports/antigravity | staging rehearsal signed |
| 603 | CV1-HARDWARE-UAT-V1 | G | HUMAN | BLOCKED_PHASE_A_UNSIGNED | G.1 | reports/hardware | hardware grid signed |
| 604 | CV1-FISCAL-PROOF-V1 | G | HUMAN+CODEX | BLOCKED_PHASE_A_UNSIGNED | G.1 | reports/fiscal | fiscal packet signed |
| 605 | CV1-MEMORY-MANIFEST-SIGN-V1 | G | CODEX | BLOCKED_PHASE_A_UNSIGNED | A-F closed | memory manifest/reports | memory manifest signed |
| 606 | CV1-CLOSURE-AUDIT-INDEPENDENT | G | AUDIT | BLOCKED_PHASE_A_UNSIGNED | G.1-G.5 | reports/audit | final dual audit PASS or HOLD |

## Registry Verification Rules

Every CODEX task must have, before execution:

1. A concrete `missions/<TASK_ID>/input.json`.
2. A concrete `missions/<TASK_ID>/execute_brief.md`.
3. `allowlist` containing only files needed for that task.
4. `forbidden_files` or `forbidden_patterns` for invariant risks.
5. `mandatory_tests`.
6. `expected_output_files`.
7. `invariants_considered`.
8. `gate_check` if schema/frozen/payment/fiscal/hardware is in scope.

No task may be merged with another task without human validation.
