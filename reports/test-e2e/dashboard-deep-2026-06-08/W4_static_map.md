# Wave 4 — Static Code-Map (Commandes + Caisse) — LIVE branch
All controls resolve to live route+method; RBAC present everywhere. Admin group `api.php:295`.

## Surfaces → endpoints (OK unless flagged)
1. POS-ORDERS (`PosOrderController`, RBAC pos-orders/pos): list/show/destroy/export/changeStatus/changePaymentStatus/selectDeliveryBoy — OK. **Refund** → `@refundWithCounterEntry:47` = FISCAL (fresh fiscal_sequence_no + mirror) + double-gated `can('pos-refund')`. Loyalty redeem → PosLoyaltyController.
2. HISTORIQUE (`OrderHistoryController`, RBAC pos-orders||pos): list+date/channel filters / row→detail (reuses pos-orders show). GET-only, no mutation. No export/reprint on this surface.
3. POS-ORDERS-TRACKER: board fetch/show/changeStatus/cancel-with-reason — OK. **Reprint** → `PosReceiptPrintController@increment` (route :911) = EXTERNAL(ESC-POS)+FISCAL (writes audit_logs every impression, DUPLICATA marker). **Encaisser** → PosCounterCollectModal (see §4).
4. ENCAISSEMENT (`EncaissementComponent` + `PosCounterCollectModal`, RBAC can('pos')): pending queue / open modal / mode picker CASH/CARD/MOBILE/TICKET / **Confirm** → `PaymentService@confirmCounterPayment:193` = **FISCAL** (alloc fiscal_sequence_no :321, payment_status=PAID, OrderPayment+CashMovement+audit_logs :389; idempotency-keyed; 409 if already PAID). Cancel → cancelCounterPayment (reversible). **Single-tender only** (no split).
5. CASH-OVERVIEW (`CashOverviewController`, RBAC cash-sessions-report): pure read-only (index + filters + client-only cashier-count display). Drawer open/close lives under POS surface, not here.
6. CASH-SESSION-REPORT (`CashSessionReportController`, RBAC cash-sessions-report): read-only flat table. **No Z-link / no export / no session→report drill** (control-coverage gap vs spec).
7. DELIVERY-BOY-CASH-SESSIONS (`DeliveryBoyCashSessionController`, RBAC delivery-boys_show/delivery-boys): list/show/open/close/reconcile — mutating cash-float (BranchScoped, idempotency-keyed), no fiscal alloc → clone-preferred.

## FISCAL-touch (QA = clone-only DESTRUCTIVE; RESEED after W4)
1. Encaissement confirm → confirmCounterPayment (alloc seq + audit_logs) — `PaymentService.php:321,389`.
2. Refund → refundWithCounterEntry (fresh seq + mirror) — `PosOrderController.php:47`.
3. Reprint → print-receipt (audit_logs each call) — `PosReceiptPrintController.php:81-99`.
EXTERNAL: reprint = physical ESC-POS only. No mail/SMS.

## FINDINGS (all P3)
- [P3] Orphaned route `pos-order/reorder-items/{order}` — `api.php:992` → `PosOrderController@reorderItems:354`, ZERO JS consumer (grep negative). Live but unreachable.
- [P3] Cash-session-report: no Z-link/export/drill control (flat read-only table) — coverage gap vs spec.
- [P3] Encaissement single-tender only — `PosCounterCollectModal.vue:19-27` (split needs unbuilt confirmCounterPaymentSplit). Known V1.0.2 limitation.

Counts: P0=0 P1=0 P2=0 P3=3. No dead controls (1 orphan route). RBAC complete.
