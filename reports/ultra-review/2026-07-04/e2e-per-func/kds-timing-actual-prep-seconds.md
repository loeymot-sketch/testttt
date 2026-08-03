# VERIFY — KDS bump board never poses timing timestamps → actual_prep_seconds always null

Verdict: **CONFIRMED** (P2 upheld)

## Claim
Real KDS bump path (`KitchenDisplaySystemOrderService::changeStatus`, POST /api/admin/kds-order/change-status/{order})
saves status without posing accepted_at/preparing_at/prepared_at. Timing instrumentation
lives only in OrderService.php (pos-order path). Coverage test hits the wrong route.

## Replayed evidence (LIVE, read-only, HEAD 3c7145bf4)
1. Frontend real path: KitchenDisplaySystemComponent.vue:2213 & :1627 dispatch
   `kitchenDisplaySystemOrder/changeStatus` → store kitchenDisplaySystemOrder.js:39 POSTs
   `admin/kds-order/change-status/{id}`. This is THE KDS bump board path.
2. Route api.php:1196 → KitchenDisplaySystemController::changeStatus (Admin/) which delegates
   (:45) to $this->kitchenDisplaySystemOrderService->changeStatus.
3. KitchenDisplaySystemOrderService.php:451-452 = `$locked->status = $newStatus; $locked->save();`
   NO accepted_at/preparing_at/prepared_at assignment anywhere in the tx (read L400-464).
4. Timing instrumentation confirmed present ONLY at OrderService.php:2213-2218
   ([KITCHEN-TIMING 2026-07-03], first-write-wins) — served by pos-order/change-status.
5. Columns exist: Schema::hasColumn orders.{accepted_at,preparing_at,prepared_at} = all true.
6. DB reality (foodking_e2e): orders with accepted_at set = 0; preparing_at = 0; prepared_at = 0.
   → actual_prep_seconds computable on 0 orders.
7. Resource KDSOrderDetailsResource.php:51-53: actual_prep_seconds = (accepted_at && prepared_at)
   ? diff : null → structurally always null in real KDS usage.

## Why not downgrade
Genuine feature (productivity analytics socle) broken on the primary real path; the green
coverage test taps pos-order (wrong route) = false confidence (GREEN != correct). Non-fiscal,
additive fix (dup OrderService.php:2213-2218 block before KDSService:451 save, first-write-wins),
outside frozen zones. P2 correct: not fiscal/security (not P1), not cosmetic/latent (not P3).
