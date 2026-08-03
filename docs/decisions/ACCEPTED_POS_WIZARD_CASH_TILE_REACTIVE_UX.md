# Accepted: POS Wizard CASH Tile — Reactive UX (Owner Gate G2 = Option B)

**Date**: 2026-05-17
**Sprint**: V1.0.1 Hardening, H2.5 (F-12)
**Decision-maker**: Owner (sign-off via OWNER_GATES.md)
**Status**: ACCEPTED

## Finding

Wave Z audit Z10 F-12 (P1): `public/js/pos-wizard.js` does not proactively disable the CASH payment tile when no `CashDrawerSession` is OPEN for the cashier on their branch. A cashier clicking CASH triggers a backend round-trip that returns 422 (`CashDrawerSessionNotOpenException`) and a toast hint.

## Why this is acceptable

1. **Backend enforcement is fiscal-grade**. Sprint 1B (commit `2e3635d64`) + Wave Z 5B (commit `7e62f7bbc`) ensure:
   - `PosController::store` calls `assertCashDrawerSessionOpenIfCashInvolved` before order creation.
   - `OrderService::posOrderStore` writes `CashMovement` only inside an active OPEN session DB transaction.
   - `SplitPaymentService::persistTranches` mirrors the guard.
   - `PaymentService::recordCashOrderMovement(strict=true)` throws on closed session.

   The invariant cannot be bypassed by a tampered client because the server is the single source of truth.

2. **The user-facing impact is one wasted click + a toast**. Not a security event, not data corruption, not a fiscal breach. The toast text (`label.cash_no_open_session_blocks_sale`) provides clear remediation guidance.

3. **Frozen-zone discipline trumps UX micro-optimization**. `public/js/pos-wizard.js` is in CLAUDE.md §7 frozen-zone list because the POS wizard design is owner-validated as "parfait" (2026-05-06). Editing it requires a LOCK plan (`/lock-plan` skill) and owner sign-off per CLAUDE.md §10. The cost of opening that gate for a 1-click UX gain exceeds the benefit.

4. **Telemetry-driven reversal path is open**. If owner later observes via prod logs that `CASH_NO_OPEN_SESSION` 422 events are common (e.g., > 5% of POS sessions hit the toast in a week), Option A (LOCK + frontend pre-block) can be reopened with data justification.

## What changes operationally

Nothing. The current Sprint 1A POS Cash Drawer Session Dialog (`resources/js/components/admin/pos/PosCashDrawerSessionDialog.vue`) is the proper opening surface for cashiers — they open a session at start of shift, the dialog UX reminds them, and the rare case of forgetting → backend 422 + toast is the safety net.

## Telemetry hook (V1.0.1 acceptance gate)

The 422 path already logs via `Log::warning('[F-003] Cash sale without open session', [...])`. Operations can grep production logs for `CASH_NO_OPEN_SESSION` to measure the toast-firing rate. A SLI dashboard tile may be added in V1.0.2 if useful.

## Reversal trigger conditions (when to re-open Option A)

- Toast-firing rate exceeds 5% of POS sessions across any branch in a week
- Owner-reported user-experience complaint with telemetry evidence
- Fiscal incident traced (even partially) to the lack of pre-block (extremely unlikely given backend enforcement)

## References

- Wave Z `reports/test-e2e/wave-z-2026-05-16-claudemax/CONVERGENCE_FINAL.md` §"V1.0.1 polish backlog"
- `plans/v1-0-1-hardening/OWNER_GATES.md` Gate G2
- `plans/v1-0-1-hardening/MASTER_V1_0_1_HARDENING_2026-05-16.md` §4 G2 + §9 H2.5
- CLAUDE.md §7 (frozen-zones), §10 (human gates)
- Sprint 1B commit `2e3635d64` (backend cash-trail enforcement)
- Sprint 5B commit `7e62f7bbc` (drawer pop forensic, sibling defense-in-depth)
