# Agent 7 — Livreur (DeliveryBoy) — Phase A Audit
GOAL Production Readiness Le Cayenne, 2026-05-18
Scope: System 6 (Sub 6.1-6.4) READ-ONLY

---

## 1. Anchor verification

### Controllers (exist)
- `app/Http/Controllers/Admin/DeliveryBoyController.php` (118 LOC) — CRUD, password/image, export, myOrder
- `app/Http/Controllers/Admin/DeliveryBoyOrderController.php` (45 LOC) — `deliveredOrder` + `deliveredOrderDetails` (admin viewing a livreur's past orders)
- `app/Http/Controllers/Admin/DeliveryBoyAddressController.php` (70 LOC) — CRUD on a livreur's saved addresses
- `app/Http/Controllers/Frontend/DeliveryBoyOrderController.php` (70 LOC) — livreur's API: `index`, `show`, `orderCount`, `deliveryBoyOrderChangeStatus`

### Services (exist)
- `app/Services/DeliveryBoyService.php` (230 LOC) — User CRUD with `assertTargetRole` WAVE5-SEC-001 defense
- `app/Services/Delivery/DeliveryFeeService.php` (47 LOC) — branch-config aware (Sprint H3 DEL-5)
- `app/Services/Delivery/DeliveryQuoteService.php` (107 LOC) — geocode + distance + fee quote
- `app/Services/OrderDeliveryBoy{Push,Sms,Mail}NotificationBuilder.php` (3 builders, exist)

### Resources / Requests (exist)
- `DeliveryBoyResource.php` (33 LOC) — id/name/email/branch/phone/status/image/country_code
- `SimpleDeliveryBoyOrderResource.php` (40 LOC) — see Sub 6.1 finding #1
- `DeliveryBoyOrderCountResource.php` (25 LOC) — total_delivered/total_returned only
- `DeliveryBoyRequest.php` (70 LOC) — `authorize(): true` (controller middleware-only gate)
- `DeliveryBoyAddressRequest.php` (35 LOC) — `authorize(): true`

### Events + Listeners (exist)
- `Events/SendOrderDeliveryBoy{Push,Sms,Mail}.php` (3 events) + 3 listeners + 3 builders. `AwardLoyaltyPointsOnDelivery` (6.8 KB) wired in `EventServiceProvider:138-140` on `OrderStatusChanged`

### Migrations 2026-05-18 (exist, read in full)
- `2026_05_18_100000_add_delivery_fee_settings_to_branches.php` — 3 nullable decimal(10,2): `delivery_fee_base`, `delivery_fee_per_km`, `delivery_fee_minimum` after `zone`
- `2026_05_18_110000_add_delivery_minimum_order_to_branches.php` — 1 nullable decimal(10,2): `delivery_minimum_order` after `delivery_fee_minimum`
- Rollback order reverse: 110000 down() drops `delivery_minimum_order`, 100000 down() drops the 3 fee columns. Order is **safe** (110000 created after, dropped first). Both are pure nullable adds → **zero-risk on prod data**. `Branch.php` `$fillable` + `$casts` already include `delivery_minimum_order` (and presumably the 3 fee cols).

### Models — CRITICAL ABSENCES (confirmed)
- `app/Models/CashDrawerSession.php` exists, **branch-scoped, POS-bound** (no `delivery_boy_id` column, no `kind` discriminator)
- **`DeliveryBoyCashSession` model: DOES NOT EXIST** (grep -rn → 0 hits in `app/`)
- **`DeliveryBoyShift` / `DeliveryBoyFloat` / `delivery_boy_equipment` / `delivery_boy_bag` / `delivery_capacity` : DOES NOT EXIST** (grep → only plan doc itself)
- Livreur is a `User` with `role_id = Role::DELIVERY_BOY = 3` (Spatie role-based, see `Enums/Role.php:9`)

### Tests (existing)
- `tests/Feature/DeliveryBoyOrderStatusOrderingTest.php` — save→dispatch→event ordering (POS-9.1.7)
- `tests/Feature/Delivery/DeliveryFeeConfigurableTest.php` — 5 paths, legacy fallback + branch config
- `tests/Feature/Delivery/DeliveryMinimumOrderTest.php` — 3 paths, NULL = no block / below = error / at = OK
- `tests/Feature/Delivery/{DeliveryFeeForgePosTest,DeliveryFeeForgeWebTest,DeliveryValidationTest,GeocodeFailureBlocksOrderTest,BranchZoneFallbackTest}.php`
- `tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php`
- `tests/Unit/Services/DeliveryFeeServiceTest.php` (legacy formula only)
- `tests/Feature/Outbox/OutboxDeliveryTest.php`
- `tests/Feature/KDS/KDSDeliveryEnrichmentTest.php`

### Routes (exist)
- Admin prefix `api/admin/delivery-boy/*` — full CRUD + addresses + delivered orders (gate: `permission:delivery-boys`)
- Frontend prefix `api/frontend/delivery-boy-order/*` (auth:sanctum) — 4 routes (index/show/count/change-status). **NO route-level role gate** (sanctum only)
- `api/admin/pos-order/select-delivery-boy/{order}` (idempotency-protected) + `api/admin/online-order/select-delivery-boy/{order}` (idempotency-protected)

### Vue surfaces (exist — ADMIN ONLY)
- `resources/js/components/admin/deliveryBoys/{DeliveryBoyComponent,List,Show,Create,OrderDetails}.vue` + 2 address Vues
- Routes under `/admin/delivery-boys` (admin manages livreurs + views their orders)
- **NO livreur-facing Vue page** anywhere in `resources/js/components/frontend/` — confirmed by `find -iname "*Delivery*"` 12-file output, all in `components/admin/`. The livreur's interface is JSON-only via the frontend API. Consumer is presumably the mobile/native app or an external app (out of this codebase scope).

---

## 2. Sub 6.1 — Interface findings

### F-6.1.1 [P1 Severity] SimpleDeliveryBoyOrderResource MISSING order items
File: `app/Http/Resources/SimpleDeliveryBoyOrderResource.php`. Returns id, serial, type, datetimes, payment_method/status, status, reason, **address**. Does NOT include `items` / `orderItems` / quantities / customizations. **The livreur cannot see what they're carrying** from the `index` payload. The `show` endpoint returns `OrderDetailsResource` which likely carries items — to verify in Phase B. Acceptance gate: livreur `index` must include enough item info to confirm count + dishes at pickup, OR the spec must explicitly require `show` round-trip per order.

### F-6.1.2 [P0 Severity] `selectDeliveryBoy` cross-branch / role validation MISSING
File: `app/Services/OrderService.php:2008-2034`. Non-auth admin path (`$auth=false`):
- Sets `$order->delivery_boy_id = $request->delivery_boy_id` without verifying the chosen User:
  - actually has Role::DELIVERY_BOY (anyone's id could be passed)
  - belongs to the same branch as the order (cross-branch leak — admin in branch A could assign a branch B driver, breaks `BranchScope` semantics on the livreur's `index` query at `OrderService:262-298`)
- Auth path (`$auth=true`) verifies order ownership only, not driver role/branch.
- Acceptance gate: add `User::role(DELIVERY_BOY)->where('branch_id', $order->branch_id)->findOrFail($request->delivery_boy_id)` before assign.

### F-6.1.3 [P1] Frontend status change uses plain `Request`, NOT `OrderStatusRequest`
`Frontend/DeliveryBoyOrderController.php:55` signature: `deliveryBoyOrderChangeStatus(Order $order, Request $request)`. The route group is `auth:sanctum` only. Consequences:
- `OrderStatusRequest::authorize()` role-whitelist (Admin/Branch Manager/Chef/POS Operator/Cashier) is **bypassed** — a livreur (Role::DELIVERY_BOY) can call this, which is the intended behavior but means the whitelist documentation in `OrderStatusRequest.php:25` does not describe the actual auth surface for this endpoint.
- `OrderStatusRequest::withValidator()` cancel/reject/return reason gate is **bypassed** — a livreur could push status=CANCELED/REJECTED/RETURNED without reason. The state machine `OrderStateMachine::allows()` permits `OUT_FOR_DELIVERY → DELIVERED` and `DELIVERED → RETURNED` (admin-only) — and the controller relies on `ValidStatusTransition` rule at `OrderService:1498`. So in practice only `DELIVERED` and `RETURNED` transitions are reachable from a livreur's likely starting state, and `RETURNED` requires Admin role per state machine line 60-67. **Effective surface is limited but the inconsistency should be documented** — switch to `OrderStatusRequest` or add an explicit role check.
- Existing test `DeliveryBoyOrderStatusOrderingTest:46-50` works around this by granting the livreur the "POS Operator" role — a test-only construct, not production parity.

### F-6.1.4 [P2] Notifications dispatched synchronously, NOT afterCommit
`selectDeliveryBoy` (line 2015-2017 + 2025-2027): `SendOrderDeliveryBoy{Mail,Sms,Push}::dispatch()` called immediately after `$order->save()` but OUTSIDE a transaction wrapper. Compare `deliveryBoyOrderChangeStatus` (line 1515 `DB::transaction(...)` with afterCommit notifications per the [POS-9.1.7] pattern). Not a transaction-rollback risk here (no transaction), but inconsistent with the rest of the codebase. Acceptance: wrap save+dispatch and use afterCommit, OR document that `selectDeliveryBoy` is a single-statement save and rollback risk = 0.

### F-6.1.5 [P2] Navigation deep link / Google Maps integration
`SimpleDeliveryBoyOrderResource` returns `order_address` with `latitude` + `longitude` (Address has these). **No backend-rendered `google_maps_url` field**. Mobile/external client builds the deep link. Acceptance: document this as out-of-scope or add a helper field `nav_uri = "geo:lat,lng?q=..." ` for cross-platform.

### F-6.1.6 [P1] Auto-dispatch flag (V1.0.2 deferred — confirm OFF)
No `auto_dispatch_enabled` or `pos_auto_assign_delivery_boy` setting found in grep. T-6.1.3 acceptance: confirm via Settings table dump in Phase B or document "not implemented — manual assign only" for V1.

### F-6.1.7 [P1] Notifications — 3 channels exist, BUT 3 listeners swallow Exception silently
`SendOrderDeliveryBoy{Push,Sms,Mail}Notification.php` listeners wrap `Builder->send()` in try/catch + `Log::info($e->getMessage())`. **Failures are not surfaced** to admin or retried. Acceptance: switch `Log::info` to `Log::warning` minimum (CLAUDE.md §13 evidence rules) + add per-channel sentinel test that asserts builder constructor is invoked with correct args.

---

## 3. Sub 6.2 — Payment Regulation findings

### F-6.2.1 [P0 — must verify] Cash collection at delivery — no escrow flow
No `PaymentService::collectCashOnDelivery` method — `grep "delivery" PaymentService.php` → only `CASH_ON_DELIVERY` enum reference for KIOSK counter pickup, not livreur. Cash collection on the doorstep flow:
- Livreur calls `deliveryBoyOrderChangeStatus` with `status=DELIVERED`. Controller (`OrderService:1525-1528`) auto-flips `payment_status` to PAID if no Transaction row exists and status is UNPAID.
- **No cash amount is recorded** (no overpay handling, no change-given amount, no link to a CashDrawerSession). NF525-CRIT-FAIL risk for a fast-food restaurant collecting cash on doorstep — Z-report cannot reconcile delivery cash.
- Acceptance: Sentinel `PosCashTrailTest`-equivalent for delivery cash. Must be created.

### F-6.2.2 [P1] Card-on-delivery flow — not implemented
No `card_on_delivery` enum or terminal config for livreur-carried TPE. `PaymentTerminal` model referenced in `PosOrderRequest:10` is for POS counter terminals. Acceptance: document V1 scope — "delivery accepts CASH or pre-paid app only — no mobile TPE for V1".

### F-6.2.3 [P0] App-pre-paid flow — double-charge risk
`OrderService:1525-1528`:
```
$transaction = Transaction::where('order_id', $locked->id)->first();
if (!$transaction && $locked->payment_status == PaymentStatus::UNPAID) {
    $locked->payment_status = PaymentStatus::PAID;
}
```
If a Transaction row exists but `payment_status == UNPAID` (race: Stripe webhook lagging), the flip is skipped — correct. If Transaction is null and payment_status was already PAID via some other path, no change — also correct. **Risk: a CASH_ON_DELIVERY order with payment_method set to STRIPE (data error) would flip to PAID without any actual cash collection.** Defense-in-depth: cross-check `$locked->payment_method != CASH_ON_DELIVERY` before the auto-flip, OR require explicit `cash_collected_amount` param.

### F-6.2.4 [P0 — covered] DeliveryFeeService — calculation OK
`DeliveryFeeService::fromDistanceKm` (47 LOC) implements:
- If branch + all 3 cols set → `max(minimum, base + per_km * distance)` rounded(2)
- Else legacy `max(5, ceil(d/5)*5)` (5€ floor, 5€/5km tier)
- Negative or non-numeric → 0.0 (DoS guard)
Covered by `DeliveryFeeConfigurableTest` (5 paths) + `DeliveryFeeServiceTest` (legacy). **NO RISK.**

### F-6.2.5 [P1 — covered] Migrations 2026-05-18 — rollback-safe
- Both pure nullable column adds. Pre-existing rows = NULL → legacy fallback in service. **0 backfill needed.**
- `down()` drops are reverse-order safe.
- Branch model `$fillable` (line 22) + `$casts` (line 48) include the new columns — model is in sync with migrations.
- Covered by `DeliveryFeeConfigurableTest` (5 paths) + `DeliveryMinimumOrderTest` (3 paths) + `OrderRequest::validateDeliveryMinimumOrder` (lines 260-284) enforces the NULL = no-block rule.
- Admin UI to set the columns is **V1.0.2 deferred** (operators use tinker — per migration header comment). T-6.2.5 acceptance: confirm operator-doc exists.

---

## 4. Sub 6.3 — Cash Management — [MINI-DISCOVERY-NEEDED]

**Status: SCHEMA ABSENT.** Confirmed via repeated greps:
- `DeliveryBoyCashSession` model: 0 hits in `app/`
- `CashDrawerSession` model: branch-scoped, POS-bound. No `delivery_boy_id` FK. No `kind` / `actor_type` discriminator. Currently 1 row per cashier-shift per branch. `opened_by_user_id` could be a livreur in theory but the `cash_drawer_sessions` table is owned by POS reconciliation pipeline (`CashDrawerSession::movements()` → `CashMovement`).
- No `delivery_boy_float`, `delivery_boy_shift`, equipment columns.

### Gap report — Sub 6.3
The plan §8 line 355 anticipated this: "extension de `CashDrawerSession` model ? OU nouveau `DeliveryBoyCashSession` ?". Mini-DISCOVERY required before BUILD.

**Owner decision-tree to escalate (T-6.3 mini-DISCOVERY agenda):**

| Path | Pros | Cons |
| --- | --- | --- |
| A. Extend `CashDrawerSession` with `kind` enum (`pos`/`delivery_boy`) + nullable `delivery_boy_id` | Reuses existing forensic + Z10-F-7 hardening | Conflates two flows; Z-report queries must add `WHERE kind='pos'` everywhere (risk of bug) |
| B. New `DeliveryBoyCashSession` + `DeliveryBoyCashMovement` tables (mirror schema) | Clean isolation; livreur reconciliation has own reporting flow | Schema duplication; Z-report must aggregate two sources |
| C. V1.0.1 scope-out — only track cash via `Order.cash_collected_amount` nullable column, defer shift-level reconciliation to V1.0.2 | Smallest delta for V1 ship | Open variance untracked between shifts |

**Acceptance gates (regardless of path):**
- T-6.3.1: start-of-shift float column or row exists; livreur opens shift with declared float amount; row immutable
- T-6.3.2: end-of-shift reconciliation row with `expected = sum(orders.cash_collected_amount) + float` vs `closing_amount declared`; `variance` persisted with explanation
- T-6.3.3: parity sentinel test mirroring `tests/Feature/Pos/PosCashTrailTest.php` (Z10-F-7 forensic discipline) — `DeliveryBoyCashTrailTest` MUST be created
- T-6.3.4: `DeliveryBoyExport.php` currently exports only User CRUD columns (name/email/phone/status) — extend or add `DeliveryBoyCashExport` for shift reconciliation
- T-6.3.5: NF525 chain-sign discipline (`AuditLogService::append`) for shift open/close events if path A or B chosen

**MARK: `[MINI-DISCOVERY-NEEDED]` per plan §8 line 371.**

---

## 5. Sub 6.4 — Equipment + Delivery Details — [MINI-DISCOVERY-NEEDED]

**Status: ENTIRELY ABSENT.** Confirmed:
- No `bag_size` / `hot_compartment` / `cold_compartment` / `max_orders_capacity` columns on `users` (livreurs are Users)
- No `picked_up_at` / `assigned_at` / `out_for_delivery_at` / `delivered_at` timestamp columns on `orders` (only generic `updated_at` and `OrderStatusTransition` audit trail)
- No `late_order_alert_threshold_minutes` setting; no Job/Listener for late-order detection
- No livreur-performance dashboard route or Vue component

### Gap report — Sub 6.4

**T-6.4.1 Equipment tracking — propose schema:**
```sql
ALTER TABLE users ADD COLUMN delivery_bag_size ENUM('small','medium','large') NULL;
ALTER TABLE users ADD COLUMN delivery_has_hot_compartment TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN delivery_has_cold_compartment TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN delivery_max_concurrent_orders SMALLINT DEFAULT 3;
```
Acceptance: admin UI in `DeliveryBoyCreateComponent.vue` adds 4 fields; `selectDeliveryBoy` checks `count(active_orders) < delivery_max_concurrent_orders` before assign.

**T-6.4.2 Delivery time tracking — leverage existing `OrderStatusTransition`:**
- `OrderStateMachine::recordTransition` (referenced `OrderService:1533`) already writes a row per transition with timestamp
- Compute `time_to_deliver = transitions.where(to=DELIVERED).created_at - transitions.where(to=OUT_FOR_DELIVERY).created_at` in reporting layer — no schema change needed
- Acceptance: add `DeliveryBoyPerformanceReportTest` Feature test asserting the SQL query produces the right value

**T-6.4.3 Late-order alerts — propose:**
- Setting: `delivery_late_threshold_minutes` (default 45)
- Cron job `CheckLateDeliveriesJob` scheduled every 5 min: `Order::where('status', OUT_FOR_DELIVERY)->where('out_for_delivery_at_via_transition', '<', now()->subMinutes($threshold))->get()`
- Dispatch `SendLateDeliveryAlertToAdmin` push event
- Acceptance: `LateDeliveryAlertTest` Feature test with `Carbon::setTestNow` + `Event::fake`

**T-6.4.4 Admin reporting interface:**
- `/admin/delivery-boys/performance` route — new Vue: list livreurs with cols (count_delivered, avg_time, late_count, total_revenue, total_cash_collected, variance_total)
- Acceptance: visual capture GREEN; sortable; export Excel

**MARK: `[MINI-DISCOVERY-NEEDED]` per plan §8 line 371.**

---

## 6. Visual capture specs

**IMPORTANT FRAMING**: There is **NO livreur-facing Vue page** in this codebase. The livreur consumes JSON via `/api/frontend/delivery-boy-order/*` — presumably from a native mobile app (out of this audit's scope). The Vue components under `components/admin/deliveryBoys/` are admin-facing: an admin **manages** livreurs and **views** the orders assigned to a chosen livreur.

### Surfaces to capture (admin-facing only)
1. `http://127.0.0.1:8000/admin/delivery-boys` — list (DeliveryBoyListComponent)
2. `http://127.0.0.1:8000/admin/delivery-boys/show/{id}` — livreur profile + assigned/delivered orders (DeliveryBoyShowComponent)
3. `http://127.0.0.1:8000/admin/delivery-boys/show/{id}/{orderId}` — order assigned to livreur details (DeliveryBoyOrderDetailsComponent — 55 LOC, minimal)
4. `http://127.0.0.1:8000/admin/delivery-boys/show/{id}/delivered-order/show/{orderId}` — past delivered order details (DeliveredOrderShowComponent)
5. Admin POS assignment popover: from `/admin/pos` order details modal, the "Assigner livreur" action triggers `POST /api/admin/pos-order/select-delivery-boy/{order}`. Capture the popover.

### NOT to capture (do not invent)
- ~~`/livreur`~~ (does not exist)
- ~~`/delivery-boy/dashboard`~~ (does not exist)
- ~~Driver mobile app screens~~ (out of scope — separate React Native app)

---

## 7. Acceptance gate matrix

| Sub | Test exists | To-be-created | Phase B owner |
| --- | --- | --- | --- |
| 6.1.1 items in resource | NO | `SimpleDeliveryBoyOrderResourceItemsTest` | implementer |
| 6.1.2 selectDeliveryBoy branch/role | NO | `SelectDeliveryBoyBranchIsolationTest` sentinel | security |
| 6.1.3 status change auth | partial (status ordering only) | `DeliveryBoyChangeStatusAuthorizationTest` | security |
| 6.1.4 notifications afterCommit | NO | extend `DeliveryBoyOrderStatusOrderingTest` to cover `selectDeliveryBoy` | tester |
| 6.1.6 auto-dispatch flag OFF | NO | `AutoDispatchFlagDefaultOffTest` | dba |
| 6.1.7 notification listener failure surfaced | NO | `NotificationBuilderFailureLogsWarningTest` × 3 channels | tester |
| 6.2.1 cash collection at delivery | NO | `DeliveryCashCollectionTest` | NF525 |
| 6.2.2 card-on-delivery | N/A (not in V1) | document scope-out | architect |
| 6.2.3 app pre-paid double-charge | NO | `DeliveryPaymentDoubleChargeGuardTest` | security |
| 6.2.4 delivery fee calc | YES `DeliveryFeeConfigurableTest` + `DeliveryFeeServiceTest` | — | — |
| 6.2.5 migrations rollback | YES `DeliveryMinimumOrderTest` (3 paths) | add explicit rollback assertion | dba |
| 6.3.* (all) | NO | mini-DISCOVERY required first | architect + owner gate |
| 6.4.* (all) | NO | mini-DISCOVERY required first | architect + owner gate |

---

## 8. Cross-system flags

1. **Order state machine coupling** (`app/Domain/Order/OrderStateMachine.php`):
   - `PREPARED → OUT_FOR_DELIVERY → DELIVERED` allowed for any caller passing the controller-level ownership check. No DELIVERY_BOY role gate at state-machine level.
   - `DELIVERED → RETURNED` requires Admin role (line 66) — livreur cannot mark returned.
   - **Implication**: state machine is permissive; access control is enforced at the controller (`deliveryBoyOrderChangeStatus`) via ownership check at `OrderService:1488-1495`.

2. **Notification stack coupling**:
   - `selectDeliveryBoy` (line 2008-2034) dispatches `SendOrderDeliveryBoy{Mail,Sms,Push}` synchronously
   - `deliveryBoyOrderChangeStatus` (line 1515) dispatches via `OrderStatusChanged` → fans out to `SendOrderMail/Sms/Push` (generic, not the DeliveryBoy variants) — this is the **customer notification**, not the livreur notification. Asymmetry: livreur is notified on assignment; **customer is notified on status change** — both must verify in Phase B.

3. **Loyalty coupling** (CRITICAL): `AwardLoyaltyPointsOnDelivery` listener fires on `OrderStatusChanged` when `status=DELIVERED`. Livreur action directly triggers loyalty grant. If livreur marks DELIVERED prematurely (or fraudulently for a returned/refunded order), loyalty points accrue. Mitigation: state machine forbids `DELIVERED → RETURNED` from non-Admin, so refund flow goes Admin-only. **Acceptance gate**: `LoyaltyPointsRevocationOnReturnTest` to confirm Admin RETURN action revokes the points.

4. **NF525 coupling**: cash collected on doorstep currently invisible to Z-report (Sub 6.3 GAP). **V1 blocker** if Le Cayenne accepts doorstep cash. Coordinate with NF525 Fiscal sentinel before V1 ship.

5. **Idempotency coupling**: `select-delivery-boy` routes are idempotency-protected (routes/api.php:860, 880). `change-status` frontend route is NOT — repeat-tap by a livreur could trigger duplicate `OrderStatusChanged` events. Mitigation: `OrderService:1515-1522` has idempotent return-if-same-status guard (good). **Acceptance**: `DeliveryBoyChangeStatusIdempotencyTest`.

6. **`OrderStatusRequest` documentation drift**: `OrderStatusRequest::authorize()` whitelist (Admin/Branch Manager/Chef/POS Operator/Cashier) **does not include** DELIVERY_BOY role. The frontend route uses plain `Request` so the whitelist is bypassed in practice. Documentation oddity — either add DELIVERY_BOY to `OrderStatusRequest::authorize` and switch the controller, or document the by-design bypass.

7. **Branch isolation drift in `selectDeliveryBoy`** (cf. F-6.1.2): admin path lacks `BranchScope` enforcement on the chosen driver. Multi-tenant SaaS invariant (CLAUDE.md §9) **violated** if cross-branch admin assigns occur.

---

## Verdict (Phase A only — no implementation, READ-ONLY)

- **Sub 6.1 Interface**: MATURE backend, 1 P0 (selectDeliveryBoy branch/role), 1 P1 missing items, 2 P1 quality. ~50% spec coverage.
- **Sub 6.2 Payment regulation**: 2 P0 risks (no cash collection escrow; potential double-charge if data corrupt), DeliveryFee solid. ~60% spec coverage. **V1 cash discipline depends on 6.3 resolution.**
- **Sub 6.3 Cash management**: **SCHEMA ABSENT — mini-DISCOVERY mandatory**. 0% coverage. **V1 BLOCKER if doorstep cash accepted.**
- **Sub 6.4 Equipment + delivery details**: **SCHEMA ABSENT — mini-DISCOVERY mandatory**. 0% coverage. Could be V1.0.2 deferred per owner gate.

**Wave 6 must split into 6a (mini-DISCOVERY + plans) then 6b (BUILD + audit). Budget 3j-agent per plan line 513.**
