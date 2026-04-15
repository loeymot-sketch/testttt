# Double-check validation report — Index V1 FoodKing MVP — 2026-04-15

## Method

9-point deep audit covering every V1 implementation across all 4 vagues:
1. PHP syntax check on all 36 new/modified V1 files
2. Line-by-line PricingService vs legacy inline code parity (4 surfaces)
3. OrderStateMachine transition matrix vs original ValidStatusTransition
4. SoftDeletes + BranchScope coexistence (no global scope leak)
5. Menu 86 AvailabilityService + listener wiring
6. Outbox + event contract + domain event dispatch ordering (post-commit)
7. Security (XSS, CORS, rate limits) preservation after frozen-zone edits
8. Observability (health, correlation_id, JSON logs) integrity
9. Full PHPUnit regression suite

---

## Results

### 1. PHP syntax — 36/36 PASS

All files in `app/Services/Pricing/`, `app/Domain/`, new models, observers, listeners, config, migrations, services, providers — zero syntax errors.

### 2. PricingService parity — 4/4 surfaces PASS (with fixes applied)

| Surface | Item lookup | Variation/extra | Rounding | Tax | Coupon/manual | Total formula | OrderItem rows |
|---------|------------|-----------------|----------|-----|---------------|---------------|----------------|
| Web | PASS | PASS | PASS (no round) | PASS | PASS | PASS | PASS |
| POS | PASS | PASS | PASS (round all) | PASS | PASS | PASS | PASS |
| Table | PASS | PASS | PASS (no round) | PASS | PASS | PASS | PASS |
| Kiosk | PASS | PASS | PASS (round all) | PASS | PASS | PASS | PASS |

**Divergences found and resolved:**
- Error messages: short-form → long-form with item context (fixed in `PricingService`)
- Kiosk `OrderItem` timestamps: SSOT explicitly sets `created_at`/`updated_at` vs legacy omission — accepted as **improvement** (no fix needed; DB defaults would fill them anyway)
- `itemPrice` float cast: SSOT always casts `(float)` — accepted as **normalization improvement**

### 3. OrderStateMachine — PASS

Full transition matrix verified for all states:
- PENDING → ACCEPT, CANCELED, REJECTED (allowed); all others (blocked)
- ACCEPT → PREPARING, CANCELED (allowed); DELIVERED with POS permission (allowed)
- PREPARING → PREPARED, CANCELED (allowed); DELIVERED with POS (allowed)
- PREPARED → OUT_FOR_DELIVERY, DELIVERED (allowed)
- OUT_FOR_DELIVERY → DELIVERED (allowed)
- DELIVERED → RETURNED (allowed)
- Terminal states → Admin override (allowed); non-Admin (blocked)
- Same-status → always allowed (no-op)

`ValidStatusTransition::passes()` correctly delegates to `OrderStateMachine::allows()`.

`recordTransition()` now called in ALL 5 status-change paths:
- `OrderService::changeStatus` ($auth=true)
- `OrderService::changeStatus` ($auth=false)
- `OrderService::deliveryBoyOrderChangeStatus` (**fixed** during double-check)
- `FrontendOrderService::changeStatus`
- `FrontendOrderService::finalizePaidKioskOrder` (**fixed** during double-check)
- Kiosk auto-accept in `myOrderStore` (**fixed** during double-check)
- `KitchenDisplaySystemOrderService::changeStatus`

### 4. SoftDeletes + BranchScope — PASS

| Model | SoftDeletes | BranchScope | Coexistence |
|-------|-------------|-------------|-------------|
| Order | Yes | Yes (booted) | PASS |
| FrontendOrder | Yes | Yes (booted) | PASS |
| OrderItem | Yes | N/A | PASS |
| Branch | Yes | N/A | PASS |
| ItemCategory | Yes | N/A | PASS |

- `withoutGlobalScopes()` usage on Order/FrontendOrder: **ZERO** (only on User model elsewhere)
- `SoftDeleteAuditObserver`: correctly checks `isForceDeleting()` before logging
- Observer registered on all 5 models in `AppServiceProvider`

### 5. Menu 86 — PASS

- `DecrementItemAvailabilityOnOrder` registered on `OrderCreated` in `EventServiceProvider`
- `AvailabilityService::decrementForOrder()`: gracefully skips items without availability rows (`continue` if `!$row || max_daily_qty === null`)
- Daily reset logic correct: resets counter when `daily_reset_at !== today`

### 6. Outbox + event contract — PASS (16/16 checks)

- `OrderCreated::dispatch()` remains AFTER `DB::transaction()` in all 4 store methods
- `OrderStatusChanged::dispatch()` remains AFTER `DB::transaction()` in all `changeStatus` paths
- `implements ShouldBroadcastNow`: **0 runtime matches** (comment-only)
- `EventType` enum: all 6 types present
- `EventServiceProvider`: all 7 listeners correctly wired including `DecrementItemAvailabilityOnOrder`

### 7. Security — PASS (4/4)

- `v-html`: 3 occurrences, all via `safeHtml()` — no raw usage
- `innerHTML`: 0 occurrences in `resources/js/`
- CORS: `allowed_origins` from env variables, no `*`
- Rate limiters: `admin-mutation`, `pos-order-create`, `pos-order-update`, `login-lockout` — all defined and applied

### 8. Observability — PASS (4/4)

- `HealthController`: `full()`, `live()`, `ready()` present
- `CorrelationIdMiddleware`: UUID generation + `Log::withContext`
- Registered in `web` + `api` middleware groups
- `HasCorrelationId` trait used in `DispatchDomainEventsJob`

### 9. PHPUnit regression — 221/221 PASS

```
Tests:  221 passed
Time:   136.66s
```

Zero failures, zero errors, zero skipped.

---

## Fixes applied during this double-check

| Fix | File | Description |
|-----|------|-------------|
| Error message parity | `app/Services/Pricing/PricingService.php` | Short-form → long-form with item context for variation/extra not-found errors |
| Missing audit trail | `app/Services/OrderService.php` | Added `recordTransition()` to `deliveryBoyOrderChangeStatus` |
| Missing audit trail | `app/Services/FrontendOrderService.php` | Added `recordTransition()` to `finalizePaidKioskOrder` and kiosk auto-accept in `myOrderStore` |

---

## V1 completion status (post double-check)

| Vague | Tasks | Status |
|-------|-------|--------|
| 1 — Synchro | SYNC_BACKBONE, OUTBOX, EVENT_CONTRACT | 3/3 PASS |
| 2 — Domain SSOT | PRICING_SSOT, STATUS_MACHINE, MENU_86 | 3/3 IMPLEMENTED |
| 3 — Security | SEC_XSS, SEC_CORS_RATELIMIT | 2/2 PASS |
| 4 — Data/Obs/Tests | DATA_SOFTDELETE, OBS_HEALTH_CORR, TEST_PW_5FLOWS, TEST_PRICING_STATE | 4/4 IMPLEMENTED |

**12/12 Index V1 tasks have production code in the repository.**

---

## Remaining operational steps

1. `php artisan migrate` to apply 3 new migrations (`order_status_transitions`, `item_branch_availability`, `v1_soft_deletes_and_deletion_log`)
2. Sign gate batch checklist: `docs/gates/GATE_BATCH_V1_APPROVAL_CHECKLIST.md`
3. `npx playwright test` for E2E validation on running server
4. `npm run prod` for frontend asset compilation

## Sign-off

| Role | Name | Date |
|------|------|------|
| Double-check author | Cursor Agent | 2026-04-15 |
