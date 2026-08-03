# RUN — V14 T03 POS/Kiosk variation parity + G-3 parked recall

**Cycle:** `V14_VAGUE_D_PHASE2_2026-04-20`  
**Task:** `T03_POS_KIOSK_VARIATION_PARITY_TESTS_PLUS_G3`  
**Date:** 2026-04-20  
**Status:** PASSED (scoped deliverables)

## Summary

- **G-3 (P2):** `PosParkedOrderService::recall()` now strips variations that are missing, soft-deleted, or not `Status::ACTIVE` for the parked line’s `item_id`. Dropped rows are logged (`parked.recall.variation_dropped`) and returned under `warnings.unavailable_variations` on the recalled model. Payload `lists` / `items` entries are updated in-memory before the response.
- **Vitest:** `tests/js/posKioskVariationParity.spec.js` — **6/6** green (canonical rules aligned with `ItemComponent` + `kioskViandeCatalog` for slot caps / inactive status).
- **PHPUnit:** `PosKioskPricingParityTest` — **4/4** green (`POST /api/admin/pos` vs `POST /api/frontend/order`, `data.total` within **0.001**). `PosParkedRecallVariationAvailabilityTest` — **3/3** green. `PosParkedOrderTest` updated for `warnings.unavailable_variations` on recall.
- **Regression:** `npx vitest run tests/js/pos*.spec.js tests/js/kiosk*.spec.js tests/js/PosComponent.spec.js` — **516/516** green.  
- **PHPUnit filter:** `php artisan test --filter='Pos|Pricing|Order'` — **known pre-existing fails only:** `DispatchAfterCommitTest` (C9 sentinel) and `OrderAllergenSnapshotComposedTest` (FINDING_BACK_DEFERRED); no new failures attributed to T03.

## PARITY_DIVERGENCE_FOUND / TODOs

1. **Client `availability: false` on variations (86 at projection layer):**  
   - Vitest **case 6** asserts parity for **`status === INACTIVE (10)`**, matching `kioskViandeCatalog`’s `Number(v.status) === 10` skip.  
   - **`kioskViandeCatalog.js` does not filter `availability === false` / `is_available === false`** on variations. POS-facing helpers in tests treat those flags as non-choosable. **TODO (follow-up):** extend `kioskViandeCatalogForItem` (or API projection) to hide branch-86 / client-flagged variations consistently with POS.

2. **Kiosk wizard vs raw `min_select` / `max_select`:**  
   - Parity tests bind kiosk meat slots to the same **N** as `max_select` (simulating `_tailleMeta.viandeCount === max_select`). Real wizard flow can derive **N** from size / name heuristics; **not** fully exercised in these unit-level tests.

3. **Item-level branch availability:**  
   - G-3 recall does **not** call `ItemBranchAvailability`; only variation rows are pruned using `Item` + `ItemVariation` (+ soft delete). Parent item 86-only scenarios remain covered by existing POS availability / checkout paths.

## Files touched

| File | Change |
|------|--------|
| `app/Services/PosParkedOrderService.php` | G-3 prune + `warnings` on recall |
| `tests/Feature/PosKioskPricingParityTest.php` | NEW — 4 parity cases |
| `tests/Feature/PosParkedRecallVariationAvailabilityTest.php` | NEW — 3 recall cases |
| `tests/Feature/PosParkedOrderTest.php` | Assert empty `warnings.unavailable_variations` on recall |
| `tests/js/posKioskVariationParity.spec.js` | NEW — 6 Vitest cases |
| `tests/js/__fixtures__/variationParityFixtures.js` | NEW — shared canonical helpers + display totals |

## Blockers

- None for T03 scope. Frozen zones (`OrderService`, `FrontendOrderService`, `PricingService`, etc.) were **not** modified.

---

`EXECUTE_DELEGATION: foodking-routine-implementer (Composer)`
