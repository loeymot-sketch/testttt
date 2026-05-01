# Codex C1/C2 Process Audit — Kiosk + POS — 2026-04-27

Source plan: `reports/audit/CLAUDE_MEGA_AUDIT_PLAN_PROCESS_AND_SYNC_2026-04-27.md`.

Verdict: PASS.

## Implemented Artifacts

| Area | File |
| --- | --- |
| Shared fixture/audit helper | `tests/e2e/helpers/process-audit.js` |
| C1 kiosk process suite | `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js` |
| C2 POS process suite | `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js` |
| Kiosk process documentation | `docs/process/KIOSK_FULL_PROCESS_2026-04-27.md` |
| POS process documentation | `docs/process/POS_FULL_PROCESS_2026-04-27.md` |

## C1 Coverage

| Scenario | Result | Notes |
| --- | --- | --- |
| K1 card simple | PASS 5/5 | Confirms waiting -> confirmation, paid fiscal order, stock decrement. |
| K2 tacos composition | PASS 5/5 | Confirms immutable `composition_snapshot` with viande/sauce/extra. |
| K3 cash-at-counter | PASS 5/5 | Confirms kiosk leaves waiting while `payment_status=PENDING_COUNTER` and fiscal remains null. |
| K4 rupture | PASS 5/5 | Confirms stock zero blocks decrement and kiosk projection reports `stock_rupture`. |
| K5 abandon/new order | PASS 5/5 | Confirms return to locked `/kiosk/idle` and no visible admin/cashier shortcut text. |

## C2 Coverage

| Scenario | Result | Notes |
| --- | --- | --- |
| P1 dine-in/walk-in cash | PASS 5/5 | POS surface loads; paid fiscal cash order; stock decrement. |
| P2 takeaway card | PASS 5/5 | Card-paid takeaway invariant and composition snapshot. |
| P3 delivery quote | PASS 5/5 | Forged delivery fee rejected by recompute: `5.01 km -> 10`. |
| P4 counter collect confirm | PASS 5/5 | POS collects kiosk pending cash, allocates fiscal sequence, emits `OrderPaidAtCounter`. |
| P5 counter collect cancel | PASS 5/5 | POS cancels pending cash, status canceled/refunded, fiscal null, stock released. |

## Validation Commands

Syntax:

```bash
node --check tests/e2e/helpers/process-audit.js
node --check tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js
node --check tests/e2e/pos-full-process/c2-pos-process-audit.spec.js
```

Run-many gate:

```bash
npx playwright test tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js tests/e2e/pos-full-process/c2-pos-process-audit.spec.js --project=chromium --repeat-each=5 --retries=0
```

Result:

```text
50 passed (3.8m)
```

Regression check:

```bash
npx playwright test tests/e2e/kiosk-post-payment-auto-return.spec.js tests/e2e/composer-mega-flow.spec.js --project=chromium --retries=0
```

Result:

```text
3 passed (52.7s)
```

## Issue Found And Fixed In Test Harness

The first C1 attempt exposed a fixture ownership error: kiosk orders created with the POS operator `user_id` are rejected by `GET /api/frontend/order/show/{id}` with `Access denied: you do not own this order.` The helper now assigns `surface=kiosk` orders to the configured kiosk machine user and keeps `surface=pos` orders under the POS operator.

This is a useful guard for future audits: kiosk post-payment tests must preserve frontend ownership, otherwise waiting-page behavior is falsely reported as stuck.

## Invariants Checked

- Backend remains pricing/payment authority; frontend only observes totals and states.
- `PaymentStatus`/`OrderStatus` enums are used in assertions; no new status strings.
- Kiosk cash-at-counter remains non-fiscal until POS confirm.
- POS cancel path releases stock and does not allocate a fiscal sequence.
- Delivery charge is recomputed server-side for the POS quote path.
- Test data is branch-scoped through kiosk machine or POS operator ownership.

## Residual Scope

C1/C2 are process-level audits. They do not replace C3-C10:

- C3 still needs explicit realtime Kiosk/POS/KDS/OSS cross-channel timing.
- C4 still needs deeper concurrent stock stress.
- C5 still needs queue-number concurrency stress.
- C6 still needs audit/fiscal/outbox persistence review.
- C9 still needs dashboard management workflows.
