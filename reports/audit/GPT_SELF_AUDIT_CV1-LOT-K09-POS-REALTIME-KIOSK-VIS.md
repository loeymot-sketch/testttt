# GPT Self Audit - CV1-LOT-K09-POS-REALTIME-KIOSK-VIS

## Scope

- TASK_ID: `CV1-LOT-K09-POS-REALTIME-KIOSK-VIS`
- Lot: K-09 KIOSK
- Delegation: `codex-extension`
- Result: `SCOPE_PRESSURE`

## Finding

K-09 asks for POS realtime visibility of kiosk transitions with explicit `_origin`, `payment_method`, and `queue_number`.

The actual websocket payload is persisted into `domain_events.payload` by:

- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`

Those files are not in the K-09 allowlist. Editing only the allowed event classes, `OrderResource`, or `posOrder.js` would not guarantee the payload broadcast by `DispatchDomainEventsJob`.

## Decision

Stopped before product edits with `SCOPE_PRESSURE`.

## Required Replan

Expand the K-09 allowlist, or create a preceding data-plane lot, to include:

- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- backend event-contract tests for `_origin`, `payment_method`, `queue_number`
- frontend event adapter tests if POS consumes the canonical envelope

## Invariants

- Pricing backend SSOT: PASS. No pricing code changed.
- OrderStatus enum: PASS. No status code changed.
- branch_id isolation: PASS. No branch logic changed.
- Dispatch after commit: PASS. No dispatch code changed.
- OS/FOS symmetry: PASS. Neither `OrderService.php` nor `FrontendOrderService.php` was modified.
- Frozen zones/gates: PASS. Frozen gate checked; no frozen edit made.
- Payment Ledger Option B: PASS. No M-04A/full ledger work.

## Validation

- Mandatory implementation tests were not run because the lot stopped before edits.

VERDICT: SCOPE_PRESSURE
