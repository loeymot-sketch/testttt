# delta-(B) Gate-Check + Build Verdict — Walk-in → Counter Collection

**Date**: 2026-05-30 · **GOAL**: caisse-unifiée (model B) · **Branch**: heal/cms-pr1-quickwins-2026-05-18

## Owner decision recap
Model **(B)**: route POS walk-in through the SAME deferred counter-collection
queue as the Borne (kiosk Plan B), so EVERY payment (Borne + Caisse, cash +
card) is collected from the unified `/admin/encaissement` page. Inline-pay
deprecated.

## The advisor's 3 frozen-status checks — answered with file:line evidence

| # | Check | Verdict | Evidence |
|---|---|---|---|
| 1 | Does the BACKEND decide payment routing? | **YES, backend-decided** | `OrderService::posOrderStore` hardcoded `payment_status => PaymentStatus::PAID` (was line ~722). The frozen `pos-wizard.js` is the cart-builder; `PaymentComponent.vue` (frozen) only *emits* payment data; `PosComponent.vue` (NON-frozen) orchestrates the POST. → routable from non-frozen OrderService. |
| 2 | Does the frozen wizard break without inline-pay? | **NO (backend path) / frontend-trigger = owner-gate** | The deferred-create needs no frozen edit — the backend creates PENDING_COUNTER. The wizard still builds the cart; it never POSTs. The only frozen-adjacent concern is HOW the cashier triggers "defer" — that lives in non-frozen `PosComponent.vue`, but changing the visible checkout UX is owner-protected design → **escalated** (not built autonomously). |
| 3 | Is PENDING_COUNTER legal for POS? | **YES** | `PaymentStateMachine` (NOT frozen) `TRANSITIONS[PENDING_COUNTER] = [PAID]`. `OrderStateMachine` (frozen) governs order STATUS, untouched. `AutoPrepareOnPaidPolicy` already accepts an `isCounterCollect` param. |
| Escape-Z | Can a walk-in PENDING_COUNTER be sealed PAID WITHOUT allocating fiscal-seq? | **NO new door** | Sealed ONLY via `PaymentService::confirmCounterPayment` which allocates `fiscal_sequence_no` at collection (`if null → FiscalSequenceService::next()`). Same path as kiosk Plan B. The known `changePaymentStatus` escape is PRE-EXISTING + owner-gated; delta-(B) adds no new seal route. The deferred-create explicitly SKIPS fiscal allocation so no number is burned for an unpaid order. |

## VERDICT: backend = NON_FROZEN_BUILDABLE → BUILT (default OFF)

**Non-frozen files changed** (zero frozen touched):
- `config/pos.php` — `walkin_route_to_counter` flag (default **false**) + `POS_WALKIN_ROUTE_TO_COUNTER` env.
- `app/Services/OrderService.php` — `posOrderStore` deferred branch: PENDING_COUNTER + COUNTER_DEFERRED + CASH_ON_DELIVERY markers + SKIP fiscal alloc when deferring. Per-request `defer_to_counter` opt-in too.
- `app/Services/PaymentService.php` — `assertCounterDeferredOrder` accepts `source_surface IN ('kiosk','pos')` (canonical deferred TRIPLE marker still required).
- `routes/api.php` — `counter-collect/pending` closure: additive OR-clause surfaces pos-origin deferred orders (Borne clause byte-identical → existing behavior preserved).

**Evidence**: 7 feature tests PASS (`PosWalkinDeferredCreateTest` 3 + `PosWalkinCounterCollectTest` 4, incl. escape-Z guard + non-deferred rejection). 299-test fiscal/POS/counter-collect regression suite PASS, 0 fail. Live SEAL proven earlier (A0031 → fiscal 168). Frozen 0. Default OFF preserves inline-paid POS.

## OWNER GATE — activation (NOT done autonomously)
Flipping `POS_WALKIN_ROUTE_TO_COUNTER=true` (or wiring a per-order "Payer à la
caisse" control in the non-frozen PosComponent checkout) changes the
owner-protected POS checkout UX (cashier collects later instead of inline).
Per CLAUDE.md §10/§12 this is surfaced to the owner rather than flipped
autonomously. The capability is built, tested, and reversible — ready to
activate on owner sign-off.
