# GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26

Date: 2026-04-26
Human approver: Kossay / user kossayelbenna8
Scope: Phase 2 Train A V1 release preparation, Demande 1 + Demande 2 Claude follow-up
Mode: governance decisions only; no product patch in this brief

## Decision Summary

| Gate | Decision | Status |
| --- | --- | --- |
| `HG-ACTIVE-PRIMARY-SELECTION` | Caisse V1 / POS+Kiosk becomes the active primary; W10 should be cleaned into secondary/archive. | Approved with cleanup constraint |
| `HG-MEMORY-EPISODES-POLICY` | Track useful V1 memory decisions, document the policy, avoid memory noise. | Approved with documentation constraint |
| `HG-PHASE-A-CLOSE-SIGNOFF` | Use targeted Phase A close: persist sentinels, quote subsystem, gates, mission briefs; document remaining cleanup. | Approved in principle, final close after A.1/A.2/A.3 evidence |
| `HG-ORDERQUOTE-HMAC-APPKEY` | Fail closed if APP_KEY is empty, provided validation confirms no legitimate flow breaks. | Approved with validation constraint |
| `HG-PAYMENT-V1-SIMULATED-EXTERNAL-TERMINAL` | V1 can use simulated/manual external card payment confirmation to validate the full flow until real gateways are configured. Cash remains normal, including cash drawer flow. | Approved |
| `HG-SENANGPAY-FRANCE-UNUSED-REVIEW` | Senangpay is likely legacy/non-France payment code; audit Bangladesh/legacy payment leftovers and remove/disable unused routes safely. | Approved with audit constraint |
| `HG-DM13-QUEUE-UNIQUE-STRATEGY` | Use DB unique guard plus preflight/backfill/retry strategy; confirm operational fit before migration. | Approved in principle, migration execution deferred |
| `HG-I18N-FR-PRIMARY` | French is the primary V1 language; audit English/technical UI labels across the system. Other languages can be post-V1 unless explicitly required. | Approved |
| `HG-KIOSK-BUNDLE-BUDGET-V1` | Treat kiosk bundle budget as JS file size/performance, not financial budget. Accept warning for V1 if E2E remains green; optimize post-V1. | Approved with performance follow-up |
| `HG-W2_CUTOVER_DECISION_OR_POS_WIZARD_SHIM_ACCEPTANCE` | Accept temporary POS wizard/kiosk shim for V1; strict cleanup after V1. | Approved |
| `HG-HARDWARE-LAB-SIGNOFF` | Hardware lab is required before commercial release: cash drawer, printer, kiosk, KDS screen. Hardware is available; UAT execution still pending. | Approved as required, pending UAT run |
| `HG-TRAIN-A-BOOTSTRAP` | Follow Claude Demande 2 and create Train A mission briefs/allowlists. | Approved |

## Clarifications

### Payment V1

The real payment gateway setup is a final operational step and is not required to prove the full order lifecycle. For V1 validation, card payment may be represented as an external/manual terminal confirmation flow: the operator taps pay/confirm only after the real-world or simulated terminal step. The system must not pretend a live gateway charged money when no gateway is configured.

### Senangpay / Legacy Country Cleanup

Senangpay appears to be legacy payment code that may come from the original non-France deployment. The current audit found a route pointing to a missing gateway class. The decision is not to enable Senangpay. The responsible path is to audit legacy/Bangladesh-specific payment code and remove or disable unused pieces under a dedicated mission, without broad deletion.

### D-M13 / Queue Number

The recommended DB uniqueness strategy remains correct for restaurant ordering systems: queue numbers are customer-visible operational identifiers and must be unique per branch. The migration itself remains gated because historical duplicates, backfill, DB engine behavior, and rollback strategy must be checked first.

### i18n

French is the target V1 language. English technical labels such as dashboard/home/index-like UI text should be audited and translated when they are visible to users. Code identifiers and route names are not automatically UI defects.

### Kiosk Bundle Budget

The kiosk budget is a performance budget in kilobytes for the built JavaScript asset. It is not a money/payment budget. Current Playwright tests pass, so this is a release quality warning unless the user chooses to make it a hard performance gate.

## Non-Approvals

This brief does not approve:

- D-M13 production migration execution.
- Any deletion of legacy payment code without allowlist and audit.
- Any product code patch outside a mission brief.
- Any self-approval by Codex or Claude.

---

## Addendum — 2026-04-27 User Clarifications

Human approver: Kossay / user kossayelbenna8
Conversation decision: "ok je valide le tout" after the Train A unblock questionnaire.

### Confirmed Decisions

| Gate | Final decision | Status |
| --- | --- | --- |
| `HG-ACTIVE-PRIMARY-SELECTION` | Caisse V1 remains the active primary cycle. W10 remains secondary/archive and must not interrupt Caisse V1 completion. | Approved |
| `HG-PHASE-A-CLOSE-SIGNOFF` | Phase A may close only after the Train A release perimeter is clean and evidenced. Unrelated dirty worktree noise is not a blocker if documented and excluded from the release perimeter. | Approved with Train-A-clean constraint |
| `HG-DM13-QUEUE-UNIQUE-STRATEGY` | Business-day queue model selected: queue numbers reset by branch and business day. Target uniqueness is `(branch_id, business_date, queue_number)`, not forever-global `(branch_id, queue_number)`. | Approved |
| `HG-DM13-MIGRATION-SIGNOFF` | Train A may finish D-M13 with staging/production migration path, provided backup, maintenance window, zero-duplicate preflight/backfill, and rollback runbook are documented before production execution. | Approved with rollout constraints |
| `HG-FROZEN-ORDER-HUNKS-TRAIN-A-2026-04-27` | Strict hunks are authorized in `OrderService.php` and `FrontendOrderService.php` only for D-M13 queue allocation, POS walk-in customer, delivery-fee backend authority, and required POS/Kiosk parity. | Approved |
| `HG-POS-WALKIN-CUSTOMER-V1` | POS takeaway/counter orders must not require operator customer selection. A branch-safe/system walk-in customer such as `Client Comptoir` is approved. | Approved |
| `HG-DELIVERY-FEE-V1` | Delivery fee rule approved: `0-5 km = 5 EUR`; above 5 km, add `1 EUR` per started kilometer. Backend must recompute authoritatively; frontend may display an estimate. | Approved |
| `HG-PAYMENT-V1-SIMULATED-EXTERNAL-TERMINAL` | Manual/simulated external card terminal flow remains acceptable for V1 until live gateway configuration. | Approved |
| `HG-I18N-FR-PRIMARY` | French remains the V1 primary language. Other languages are post-V1 unless visible release-critical text blocks the French flow. | Approved |
| `HG-DASHBOARD-AFTER-TRAIN-A` | Full dashboard catalog/category/stock control plane remains after Train A/D-M13. Do not launch dashboard write scope before Train A is closed. | Approved |
| `HG-KIOSK-LOCKED-CUSTOMER-SURFACE` | Customer kiosk must expose no admin route, hidden admin tap, or navigation from kiosk to caisse/admin. Staff intervention happens from caisse/admin only. | Approved |

### Operational Meaning

- Train A is unblocked for strict, mission-scoped frozen edits.
- The current global safety hook may still report staged frozen files, but this addendum provides the human decision required to continue under strict allowlist.
- D-M13 implementation must be checked against the selected business-day model before final close.
- Any broader dashboard/control-plane build remains Train B and must not be mixed into Train A.
