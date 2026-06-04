# LOCK-OSM-PREZ-REFUND — 2026-06-04

## Frozen file under LOCK
`app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7 — order status transitions, NF525-adjacent).

## Owner gate
**AUTHORIZED 2026-06-04** by the owner via AskUserQuestion ("P3 remboursement … J'autorise ?" → **"Oui — autoriser le LOCK (recommandé)"**). Explicit human gate per CLAUDE.md §10 cleared.

## Problem
The owner cannot refund a normal same-day order. The refund modal calls the post-Z `refund-with-counter-entry` path, which requires a sealed (closed-Z) parent → 422 for any pre-Z order. The pre-Z standard refund (`changeStatus → RETURNED`, which does reason-validation + cashback + loyalty refund + `order.returned` audit) only works from `DELIVERED`, because `OrderStateMachine::allows` permits `→ RETURNED` ONLY from `DELIVERED` (line 60-61). **Live DB 2026-06-04:** of 1999 refundable pre-Z orders, only **2 are DELIVERED**; **1994 are PREPARED/ACCEPT/PREPARING** → 99.9% un-refundable. (Foundation for the routing already shipped non-frozen in commit `9e13e6c9d`: `SealedOrderGuard::isSealed` + `PosOrderController` routes pre-Z → `changeStatus RETURNED`; it is blocked solely by this state-machine edge.)

## Scope of change (minimal, additive, permission-gated)
Add `→ RETURNED` as an allowed target from the three kitchen-release statuses, **gated by the `pos-refund` permission** (mirrors how `→ DELIVERED` is gated by `pos` on the same states). Exactly 3 additive guards, no existing transition altered:
- `ACCEPT → RETURNED` if `user->hasPermissionTo('pos-refund')`
- `PREPARING → RETURNED` if `user->hasPermissionTo('pos-refund')`
- `PREPARED → RETURNED` if `user->hasPermissionTo('pos-refund')`

## Fiscal safety (why this is NF525-sound)
- Pre-Z `RETURNED` is captured in the **still-open Z** → no fiscal gap, no sealed-row mutation, HMAC chain untouched.
- The refund path already wires cashback (`OrderService.php:2166`, if `transaction`), `LoyaltyService::refundPoints`, mandatory reason (≤700, logged), and `order.returned` audit.
- `PaymentStateMachine` is NOT touched: `PAID ⇒ []` by design; the canonical "refunded parent" = `status=RETURNED` + cashback + audit (never a payment_status flip). Confirmed by the implementer.

## Blast-radius control (mandatory regression sentinel)
The new edges are **permission-scoped** (`pos-refund` = Admin + Branch Manager only) — closed for regular POS operators, kiosk tokens, customers. The status-dropdown UI already hides `RETURNED`. A sentinel (`OrderStateMachinePreZRefundLockSentinelTest`) pins:
1. A user WITHOUT `pos-refund` **cannot** do ACCEPT/PREPARING/PREPARED → RETURNED (still illegal).
2. A user WITH `pos-refund` **can** (the refund edge).
3. Every pre-existing transition is unchanged (ACCEPT→PREPARING/CANCELED, PREPARING→PREPARED/CANCELED, PREPARED→OUT/DELIVERED, DELIVERED→RETURNED, the `pos`-gated →DELIVERED, the Admin undo override) — byte-for-byte behavior preserved.

## Rollback
Revert the 3 added `if ($to === OrderStatus::RETURNED && $user && …->hasPermissionTo('pos-refund')) return true;` blocks in `allows()`. No data migration involved.

## Verification gate (must all pass before commit)
- PHPUnit `--filter="Refund|PosOrder|CounterEntry|OrderStateMachine|Sealed|Fiscal|PreZRefund"` GREEN, incl. the new sentinel + the flipped P3 limitation case (pre-Z ACCEPT now refunds → 200).
- `php artisan fiscal:verify-chain --all` → CHAIN OK.
- Frozen-diff: `OrderStateMachine.php` is the ONLY frozen file changed, and ONLY the 3 additive guards (no other §7 file touched).
- Visual: refund a real PREPARING order end-to-end → status RETURNED, cashback recorded, reason in audit.
