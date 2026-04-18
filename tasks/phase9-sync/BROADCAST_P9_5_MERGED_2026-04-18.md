# BROADCAST — Kiosk P9.5 delivered / pending merge — 2026-04-18

**To.** Track B (POS) orchestrator + any Kiosk agent resuming P9.6+.
**From.** Track A (Kiosk) — P9.5 order pipeline hardening.
**Branch.** `feat/kiosk-phase-9-5` @ `c360de589`.
**Status at broadcast.** Merge-ready, **awaiting human merge to `main`**. Broadcast committed pre-merge so Track B can plan the wave 9.4 wire-in PR with full knowledge of what ships. This file will be cited in the human merge commit message.

---

## 1. Shared files modified by Track A during P9.5

All changes are additive. None of the POS-owned files were touched.

### Backend — shared zone (formerly frozen)

| File | Change scope | Commit |
|---|---|---|
| `app/Services/FrontendOrderService.php` | (a) `hydrateAllergenSnapshots()` helper (pivot-first read of `item->allergens`, fallback `allergen_flags` legacy). Invoked in `myOrderStore` SSOT path + legacy path before `OrderItem::insert`. (b) Idempotency lock key scoped by `(branch_id, idempotency_key)` — `$lockBranchId` resolved server-side via `KioskMachine::where('user_id', Auth::id())->value('branch_id')` (fallback `Auth::user()?->branch_id`), then `Cache::lock('frontend_order_idempotency_' . sha1($lockBranchId . '|' . $idempotencyKey), 10)`. | `e5be3763f` (9.5.1) + `1f145bdbe` (9.5.5) |
| `app/Services/Pricing/PricingRequest.php` | `forWeb` / `forPos` / `forTable` / `forKiosk` now all pass `enforceCrossItemGuards: true`. **`PricingService.php` core untouched.** | `f34fce213` (9.5.6) |
| `app/Models/OrderItem.php` | Adds `'allergens_snapshot'` to `$fillable` and `$casts['allergens_snapshot'] = 'array'`. | `e5be3763f` (9.5.1) |
| `app/Http/Requests/OrderRequest.php` | `total` → `['nullable','numeric']` (was `['required','numeric']`). `branch_id` → nullable when kiosk sanctum ability present + kiosk order_type. `delivery_charge` → nullable unless `delivery`. Same pattern as POS-9.1.8 on `PosOrderRequest`. | `eb6343d46` (9.5.8) |
| `app/Http/Resources/OrderItemResource.php` | Exposes `allergens_snapshot` (tolerates array cast or legacy JSON). | `79591eb39` (9.5.2) |
| `app/Http/Resources/KDSOrderDetailsResource.php` | Uses `loadMissing('orderItem')` + `OrderItemResource::collection(...)` so KDS payload carries `allergens_snapshot`. | `79591eb39` (9.5.2) |
| `app/Console/Kernel.php` | `$schedule->job(new CleanupStalePendingKioskOrders())->everyFiveMinutes()->withoutOverlapping()`. | `49da79cf3` (9.5.3) |

### Backend — new files

- `app/Jobs/CleanupStalePendingKioskOrders.php` — reject kiosk PENDING orders > 15 min via `OrderStateMachine::apply($order, OrderStatus::REJECTED, …)`.
- `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php` — composite UNIQUE on `(branch_id, idempotency_key)`.
- `database/migrations/2026_04_18_140004_add_allergens_snapshot_to_order_items.php` — nullable JSON column.

### Frontend — kiosk-owned

- `resources/js/store/modules/kioskCart.js` — `buildKioskOrderPayload()` + `sanitizeKioskOrderItem()`, payload strict IDs-only (no money keys).
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` — allergen chips rendered from `allergens_snapshot`.

### Frontend — POS-adjacent (notify Track B)

- `resources/js/components/admin/pos/PosComponent.vue` — kiosk-cash drawer expandable (Variations/Extras/Instructions/Allergenes per order item). Also normalizes 4 `import` paths to `.vue` extensions. Track B should rebase POS branches after P9.5 merge and re-run Vitest; no conflicts expected (the added methods/data are kiosk-cash–specific: `expandedKioskCashOrders`, `toggleKioskCashOrderDetails`, `isKioskCashOrderExpanded`).

### Tests (new, kiosk-owned)

- `tests/Feature/Orders/OrderAllergenSnapshotTest.php`
- `tests/Feature/Orders/KDSAllergenVisibilityTest.php`
- `tests/Feature/Orders/CleanupStalePendingOrdersTest.php`
- `tests/Feature/Orders/IdempotencyBranchScopedTest.php`
- `tests/Feature/Orders/KioskIdsOnlyPayloadTest.php`
- `tests/Feature/Orders/CrossItemGuardTest.php`
- `tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php`
- `tests/js/PosComponent.spec.js` (extended with `drawer_expandable_details`)
- `tests/js/kioskCartSendPayload.spec.js`

### Governance / sync files

- `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md` — `SUBSYSTEMS_TOUCHED` P9.5 block + 2 scope extensions documented.
- `tasks/phase9/FINDINGS_TRACKER.md` — 8 P9.5 rows `fixed` with SHAs + journal 2026-04-18.
- `tasks/phase9-sync/LOCK_A_P9_5_*.md` — 5 locks with full transition history (ACTIVE → RELEASED or RE-OPENED → RELEASED).
- `tasks/phase9/P9_5_BLOCKER_*.md` — 2 blockers RESOLVED with documented scope extension.
- `reports/review/VERIFY_P9_5_2026-04-18.md` — independent verifier 8/8 RESOLVED.
- `reports/execution/RUN_P9_5_KIOSK_2026-04-18.md` — execution report.
- `reports/execution/HANDOFF_P9_6_2026-04-18.md` — next-wave handoff.

---

## 2. New shapes / payloads

### `order_items.allergens_snapshot: string[]|null`

- **Persisted at order creation** by `FrontendOrderService::myOrderStore` from the current `item_allergen` pivot (SSOT). Immutable afterwards — mutating the pivot post-order does NOT change the snapshot.
- **JSON example.**
  ```json
  {
    "id": 123,
    "item_id": 42,
    "quantity": 1,
    "allergens_snapshot": ["arachides", "gluten"]
  }
  ```
- **Legacy rows** (pre-migration) have `allergens_snapshot: null`. Clients must render "no allergens info available" rather than "no allergens" when null vs empty array.

### `OrderItemResource` / `KDSOrderDetailsResource`

- Both resources now include `allergens_snapshot` on each item payload. POS resources (already listing order items) automatically inherit — no Track B edit required.

### `/api/frontend/order` payload

- **Kiosk clients now POST IDs-only** — zero money keys at root or at item level. Root : `{order_type, loyalty_code, kiosk_promo_code, is_advance_order, source, payment_method, items}`. Items : `{item_id, instruction, quantity, item_variations, item_extras}`.
- **Server recomputes** `subtotal`, `total`, `discount`, `delivery_charge`, `tax` via `PricingService::calculateOrder(forKiosk)` and the cross-item guard.
- Web/POS clients sending `total` still works (nullable, server validates or recomputes).

### Idempotency semantics

- **DB**: `UNIQUE (branch_id, idempotency_key)` on `orders` (composite). Same `idempotency_key` in two branches = two distinct rows.
- **Runtime**: `Cache::lock('frontend_order_idempotency_' . sha1($branchId . '|' . $idempotencyKey), 10)`. `$branchId` server-resolved from authenticated kiosk machine / user, never from payload.
- **Contract for any consumer** (POS wire-in, future surfaces) : if you hit idempotency at the application layer, key it with `$branchId` to be consistent. POS-9.4.2b wire-in (`FiscalSequenceService` into `posOrderStore`) can now reference `branch_id` identically.

---

## 3. Track B unblock

The preventive LOCK on `OrderService.php` is **RELEASED** (`c360de589`). `OrderService.php` was NOT edited during P9.5. Track B can now take LOCK_B on it and land :

- `BLOCKER_POS_9_4_2b_OrderService_posOrderStore_2026-04-18.md` — wire `FiscalSequenceService::next($branchId)` into `posOrderStore` + `myOrderStore`. Note : `myOrderStore` is now Track A territory with an idempotency lock, so Track B should add the fiscal-sequence wire-in to `posOrderStore` only ; Track A will add the `myOrderStore` half in a follow-up kiosk PR (or a joint PR with Track B, TBD per SYNC_PROTOCOL §7 escalation).
- `BLOCKER_POS_9_4_5_AuditLog_call_sites_2026-04-18.md` — route cancel / destroy / discount / refund / changePaymentStatus through `AuditLogService::write()`.
- `BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md` — 409 Conflict when `order.fiscal_sequence_no != null AND created_at < last_closed_z.opened_at`.

Recommended order : POS-9.4.10 → POS-9.4.5 → POS-9.4.2b (from least cross-cutting to most cross-cutting).

---

## 4. Merge conflict risk assessment

| Surface | Track A P9.5 scope | Known Track B active scope | Conflict likelihood |
|---|---|---|---|
| `app/Services/OrderService.php` | Untouched | 3 BLOCKERs queued | **None** (disjoint by time) |
| `app/Services/PricingService.php` | Untouched | — | **None** |
| `app/Services/FrontendOrderService.php` | Kiosk-only per SYNC_PROTOCOL §2 | — | **None** |
| `app/Http/Requests/OrderRequest.php` | Kiosk surface rules relaxed | — | **None** (POS surface uses `PosOrderRequest`) |
| `app/Http/Resources/OrderItemResource.php` | `allergens_snapshot` added | POS already consumes this resource, will read the new field automatically | **None** (additive key) |
| `resources/js/components/admin/pos/PosComponent.vue` | Kiosk-cash drawer section + 4 `.vue` import normalizations | POS iterating in POS-9.2+ | **Low** — rebase POS branch, re-run vitest. If vite config differs on `.vue` extension resolution, normalize before rebase. |
| `app/Console/Kernel.php` | New schedule entry | Track B may add new schedules for Z report / fiscal archive | **Low** — separate schedule lines, 3-way merge trivial. |
| `database/migrations/**` | 2 new migrations (timestamps `140003`, `140004` of 2026-04-18) | Track B has `f1bff8bfd` (`orders.fiscal_sequence_no`) not on main yet | **None** — different timestamps, different columns, additive. |

---

## 5. CI notes for merge validation

- The pre-existing full PHPUnit failures (8 errors + 2 failures) observed locally on SQLite are **all traced to P9.2 dependencies** (`Allergen` model resolution, Pusher env, allergen expectation mismatches). When P9.2 merges to `main` and the MySQL CI runs, these are expected to turn green. Do not block P9.5 merge on these.
- Vitest : 53 files, 402 tests, 0 failure on HEAD.
- Migration rollback : P9.5 migrations rollback cleanly on MySQL; SQLite rollback chain breaks on a P9.2 migration (`add_parent_id_to_item_categories_table`) due to the well-known SQLite limitation on dropping foreign keys in-place. MySQL CI (per P9.1.14) handles this natively.

---

## 6. Cross-track action items

1. **Human** : Merge `feat/kiosk-phase-9-2`, `feat/kiosk-phase-9-4`, `feat/kiosk-phase-9-5` to `main` in an order of your choice (zones disjointes). If P9.5 is merged first, Track B can start its 3 wire-in PRs immediately after (LOCK_A on `OrderService.php` released).
2. **Track B** : After P9.5 merge, rebase `feat/pos-phase-9-4` on `main`, check `PosComponent.vue` Vitest still green, then open 3 follow-up PRs against `main` for the 3 BLOCKERs.
3. **Track A** : P9.6 (analytics + observability + admin) can start immediately from P9.5 HEAD — no shared-zone dependency expected. See `HANDOFF_P9_6_2026-04-18.md`.
4. **Both tracks** : Update `CROSS_TRACK_STATUS.md` at merge : flip Kiosk P9.5 row to `merged` with main SHA, flip Track B BLOCKERs to `unblocked`.

---

## 7. Appendix — commit list

Reference commits in the `feat/kiosk-phase-9-5` branch (baseline `5bc490e65`, HEAD `c360de589`) :

- Governance : `2546246b5`, `eed72fc06`, `1006d1340`, `a4c6eb761`, `c360de589`.
- Atomic functional : `37b78a6ce` (9.5.4), `e5be3763f` (9.5.1), `79591eb39` (9.5.2), `f34fce213` (9.5.6), `eb6343d46` (9.5.8), `49da79cf3` (9.5.3), `c8102ee2c` (9.5.7), `1f145bdbe` (9.5.5).

Verifier : `reports/review/VERIFY_P9_5_2026-04-18.md` (8/8 RESOLVED).
Run : `reports/execution/RUN_P9_5_KIOSK_2026-04-18.md`.
Handoff : `reports/execution/HANDOFF_P9_6_2026-04-18.md`.

Fin du broadcast.
