# Phase 2 Data Ownership Matrix

TASK_ID: CV2-PH2-01-DATA-OWNERSHIP-MATRIX
Date: 2026-04-27
Train: B - Phase 2 Enhancement
Mode: AUDIT / ADR ONLY
Product code touched: NO

## 0. Verdict

`CV2_PH2_01_VERDICT: PASS_DOC_OWNERSHIP_LOCKED`

Train 2 can start only if the shared data model is explicit before implementing
Dashboard writes, POS/Kiosk projection migration, catalog events, stock, or
realtime control surfaces. This report fixes the first contract:

- one backend owner for prices and quote totals;
- one projection target for POS/Kiosk menu reads;
- one availability owner for branch-level 86/unavailable state;
- one order owner for POS/Kiosk commits;
- one state machine owner for order lifecycle;
- one outbox contract for realtime dispatch;
- explicit role boundaries before adding Dashboard catalog/ops writes.

No runtime implementation is approved by this mission. The output is a control
document and ADR used to unblock the next Train B missions.

## 1. Preconditions Checked

| Precondition | Evidence | Result |
| --- | --- | --- |
| Train A closed | `reports/audit/TRAIN_A_D13_BUSINESS_DAY_FINAL_2026-04-27.md` exists from the previous pass and D-M13 local validation passed. | OK |
| Train B namespace | Active reworked plan defines `CV2-PH2-*` missions. | OK |
| Mission scope | `CV2-PH2-01` allowlist is audit reports + ADR only. | OK |
| Product edit gate | No product code, migration, route, or frontend file required. | OK |

## 2. Sources Inspected

| Area | Source |
| --- | --- |
| Train sequencing | `reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md` |
| Phase 2 centralisation plan | `reports/audit/PHASE2_GLOBALE_CENTRALISATION_SYNC_ULTRA_PLAN_2026-04-27.md` |
| Business invariants | `docs/BUSINESS_RULES.md`, `docs/ORDER_FLOW.md`, `docs/OUTBOX_PATTERN.md`, `docs/EVENT_CONTRACT.md`, `docs/MENU_PROJECTIONS.md`, `docs/MENU_AVAILABILITY.md` |
| Pricing owner | `app/Services/Pricing/PricingService.php`, `app/Services/Order/OrderQuoteService.php` |
| Order owners | `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`, `app/Domain/Order/OrderStateMachine.php` |
| Menu projection | `app/Services/Menu/MenuProjectionService.php`, `app/Services/Menu/MenuSnapshot.php` |
| Availability | `app/Services/Menu/AvailabilityService.php`, `app/Events/ItemAvailabilityChanged.php`, `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` |
| Catalog services | `app/Services/ItemService.php`, `app/Services/ItemCategoryService.php`, `app/Services/ItemVariationService.php`, `app/Services/ItemExtraService.php`, `app/Services/ItemAddonService.php` |
| Realtime/outbox | `app/Domain/Events/EventContract.php`, `app/Models/DomainEvent.php`, `app/Jobs/DispatchDomainEventsJob.php`, `routes/channels.php` |
| Payment owners | `app/Services/PaymentService.php`, `app/Services/PaymentManagerService.php`, `app/Services/TransactionService.php`, `app/Http/Controllers/Frontend/PaymentController.php` |

## 3. Ownership Matrix

### 3.1 Pricing and Quote Totals

| Dimension | Decision |
| --- | --- |
| Data | Item base price, variation price, extra/addon price, taxes, discounts, quote total, order total. |
| Authoritative runtime owner | `PricingService` for calculation, `OrderQuoteService` for sealed quote integrity, `OrderService` and `FrontendOrderService` for final commit. |
| Edit owner | Admin catalog services may edit source fields, but they do not calculate customer totals. |
| Read consumers | POS UI, Kiosk UI, receipts, dashboards, reports. |
| Hard rule | Frontend displays backend prices only. No cart total, discount, tax, delivery, or offer calculation can become authoritative in Vue/JS. |
| Branch rule | Pricing requests carry branch context and availability checks. No cross-branch item availability leakage. |
| Current gap | Catalog price mutations do not yet have a complete catalog event/snapshot contract for item, variation, extra, addon, and offer-like product changes. |
| Next mission | `CV2-PH2-02` and `CV2-PH2-05`. |

Implication for Dashboard: the Dashboard can edit price source fields only
through backend services. It cannot own checkout pricing, kiosk pricing, or
POS pricing logic.

### 3.2 Catalog Items

| Dimension | Decision |
| --- | --- |
| Data | Product/item identity, name, image, description, visibility, allergens, base price source field. |
| Write owner | `ItemService` through admin controllers/routes. |
| Projection owner | `MenuProjectionService` is the target canonical read projection for POS/Kiosk/Web. |
| Read consumers | POS item picker, Kiosk menu/wizard, future Dashboard catalog read surface. |
| Current realtime event | `ItemAvailabilityChanged::fromItem(...)` is used in some item-update paths as a broad refresh signal. |
| Current gap | The event name conflates availability and catalog structure. It is not a clean public catalog mutation contract. |
| Required guard | Add sentinels before migration: item update must bump the menu snapshot and invalidate relevant branch cache. |
| Next mission | `CV2-PH2-02` then `CV2-PH2-03`. |

Implication for Dashboard: do not build broad item write UI until catalog event
coverage is proven. A read-only catalog view can be built earlier if it consumes
the same backend projection.

### 3.3 Categories

| Dimension | Decision |
| --- | --- |
| Data | Category name, sort order, status, hierarchy/visibility. |
| Write owner | `ItemCategoryService`. |
| Read owner | `MenuProjectionService` for surfaces that render menus. |
| Branch decision | Not finalized in this mission: categories must be declared global or branch-visible through a pivot before migration work. |
| Current risk | If categories are global but Dashboard exposes branch-specific editing language, operators can believe one branch was changed while all branches changed. |
| Required ADR | `CV2-PH2-06-CATEGORY-BRANCH-SCOPE-ADR`. |
| Gate | Human gate if a migration or branch/category pivot is introduced. |

Default until ADR: treat category definitions as global catalog entities and do
not promise branch-specific category write behavior in UI copy.

### 3.4 Variations, Extras, Addons, Offers

| Dimension | Decision |
| --- | --- |
| Data | Product composition, selectable variations, extra options, addons, offer-like bundled products. |
| Write owner | `ItemVariationService`, `ItemExtraService`, `ItemAddonService`, offer/product services where applicable. |
| Runtime price owner | `PricingService`, not the frontend and not the Dashboard component. |
| Read owner | `MenuProjectionService` should become the shared projection once parity sentinels pass. |
| Current gap | Mutation coverage for composition/prices is not proven end-to-end for snapshot bump, cache invalidation, POS/Kiosk parity, and stale-client handling. |
| Required tests | Parameterized sentinels covering create/update/delete/toggle for each composition family. |
| Next mission | `CV2-PH2-05`. |

Implication for offers: if an offer behaves like a product for the operator, it
must still compile into a backend-owned quote and pricing calculation. The UI
may expose an offer editor later, but it cannot calculate the final commercial
value client-side.

### 3.5 Availability and V1 Stock State

| Dimension | Decision |
| --- | --- |
| Data | Branch-level item available/unavailable state, reason, daily counters where currently implemented. |
| Write owner | `AvailabilityService` and `AvailabilityController`. |
| Storage owner | `item_branch_availability` for current V1 availability. |
| Runtime guard | `AvailabilityService::assertItemsOrderableForBranch(...)` is used by order creation paths. |
| Realtime owner | `ItemAvailabilityChanged` persisted through outbox, then broadcast to branch channel. |
| Snapshot owner | `MenuSnapshot` bumps branch-scoped versions on availability change. |
| UX rule | Kiosk/POS must preserve item visibility with unavailable state when possible, not silently drift. |
| Current gap | This is availability, not full quantitative inventory. Do not label it as precise stock decrement. |
| Next mission | `CV2-PH2-02` for event/snapshot proof; quantitative stock remains a later stock subsystem mission. |

Implication for Dashboard: Train 2 can expose availability toggles earlier than
quantitative stock, because the existing owner is already known. It must label
the action as availability/unavailability, not inventory quantity, until a
dedicated stock table/service exists.

### 3.6 Quantitative Stock

| Dimension | Decision |
| --- | --- |
| Data | Available quantity, low threshold, disabled/out status, stock movement ledger. |
| Current owner | No full quantitative SSOT is accepted in current V1 runtime. |
| Prohibited shortcut | Do not derive real-time stock by ad hoc reads from item fields or frontend counters. |
| Future owner | A dedicated `StockService` plus `stock_levels`/`stock_movements` tables if/when that mission is approved. |
| Required invariant | Atomic decrement in DB inside the order transaction; compensating release on cancel/reject/refund; branch isolation. |
| Gate | Requires migration gate and order-service symmetry review. |

Implication for Dashboard: "manage stock quantity" is not Train 2 mission 01.
The safe first Dashboard operation is availability. Precise quantity management
needs a separate migration-backed stock subsystem.

### 3.7 Orders and Queue Numbers

| Dimension | Decision |
| --- | --- |
| Data | `orders`, `order_details`, source surface, branch, business date, queue number, totals, payment fields. |
| Write owners | `OrderService` for POS/admin order paths, `FrontendOrderService` for kiosk/frontend paths. |
| Quote owner | `OrderQuoteService` binds sealed quote input to commit. |
| Queue owner | D-M13 business-date unique rule: `(branch_id, business_date, queue_number)`. |
| Read consumers | POS live list, KDS, OSS, Dashboard reports, fiscal reports. |
| Hard rule | Any order mutation must keep `OrderService` / `FrontendOrderService` symmetry explicit if both surfaces are affected. |
| Current status | Train A completed the queue uniqueness contract locally. |
| Next mission | `CV2-PH2-08` for post-release observability, not another migration. |

Implication for Dashboard: order visibility can be added as a read model before
new write actions. New write actions such as handover, cancel, refund, or status
change must go through existing service/state-machine owners.

### 3.8 Order Lifecycle and Status

| Dimension | Decision |
| --- | --- |
| Data | Order status, transition token, status history/audit, KDS bump state. |
| Owner | `OrderStatus` enum and `OrderStateMachine`. |
| Write services | `OrderService`, `FrontendOrderService`, KDS service paths for allowed bump transitions. |
| Prohibited shortcut | No raw string status writes from Dashboard or frontend. |
| Read consumers | POS, KDS, OSS, Dashboard live boards, fiscal/reporting. |
| Realtime owner | `OrderStatusChanged` via outbox/event contract. |
| Gate | Any new transition must be documented in state machine sentinels before UI wiring. |

Implication for Dashboard: drag/drop live board is not just UI work. Each column
move is a backend transition request governed by the state machine.

### 3.9 Payments and Payment Simulation

| Dimension | Decision |
| --- | --- |
| Data | Payment method, payment status, transaction record, payment attempt/audit, fiscal payment effect. |
| Owners | `PaymentService`, `PaymentManagerService`, `TransactionService`, and guarded order payment methods in `OrderService`/`FrontendOrderService`. |
| Read consumers | POS, Kiosk, receipts, Dashboard reports, fiscal Z. |
| Hard rule | Payment simulation must write the same audited backend fields that the POS/Kiosk expects. It cannot fake UI-only paid state. |
| Current guard | Train A quote/payment tests cover payment confirmation and branch/method restrictions. |
| Current gap | Dashboard payment simulation is not a Train 2 mission until authz and state ownership are locked. |

Implication for Dashboard: a "simulate payment" tool for testing must be
backoffice-only, branch-scoped, auditable, and separate from production payment
gateway behavior.

### 3.10 Delivery Fees and Address-Based Fulfilment

| Dimension | Decision |
| --- | --- |
| Data | Order type, customer address, delivery distance, delivery fee. |
| Runtime owner | Backend order/quote services. |
| Frontend role | Collect address and display backend quote. |
| Business rule requested by human | 0-5 km = 5 EUR. Above 5 km, add 1 EUR per km beyond the first 5 km. |
| Current mission decision | Documented as required product behavior, but not implemented by this docs-only mission. |
| Required next work | Separate delivery quote mission with tests for pickup/no-client-id, delivery address validation, Google Maps fallback, branch isolation. |

Implication for Dashboard/POS/Kiosk: delivery fee calculation must be centralized
with pricing/quote. No UI surface should calculate or override it locally.

### 3.11 KDS and OSS

| Dimension | Decision |
| --- | --- |
| KDS owner | KDS service/controller paths may advance only approved preparation/status transitions. |
| OSS owner | OSS is read/display oriented; it should not own order lifecycle mutation except explicit future signed flow. |
| Realtime owner | Order events through outbox and branch channel. |
| Branch rule | KDS/OSS views must filter by branch and authorized channel. |
| Current gap | Future live Dashboard must not duplicate KDS state mutation logic. |

Implication for Dashboard: Dashboard can observe KDS/OSS state. It must delegate
state changes to the same backend transition owners.

### 3.12 Outbox, Realtime, and Snapshot Versions

| Dimension | Decision |
| --- | --- |
| Event persistence owner | Outbox listeners writing `domain_events`. |
| Dispatch owner | `DispatchDomainEventsJob` scheduled after DB commit. |
| Public contract owner | `EventContract` and docs under `docs/EVENT_CONTRACT.md` / `docs/OUTBOX_PATTERN.md`. |
| Current order events | `OrderCreated`, `OrderStatusChanged`, `OrderTableChanged` patterns exist. |
| Current menu event | `ItemAvailabilityChanged` exists and is persisted to outbox. |
| Current gap | Catalog structure/price mutations need a clear event/snapshot/cache contract before Dashboard write UI. |
| Required guard | No event may be broadcast before DB commit; no stale branch/global event may invalidate wrong branches. |
| Next mission | `CV2-PH2-02`, `CV2-PH2-09`. |

Implication for Dashboard: after a Dashboard catalog write, the UI cannot merely
refresh itself. POS/Kiosk/KDS relevant surfaces must receive branch-scoped,
versioned, post-commit events or detect a snapshot mismatch.

### 3.13 Roles and Authorization

| Dimension | Decision |
| --- | --- |
| Current risk | Reusing cashier/POS permissions for Dashboard catalog/ops would blur write authority. |
| Future roles | `backoffice-catalog` for catalog source edits, `backoffice-ops` for availability/stock operations, scoped POS permissions for order actions. |
| Branch rule | Every write must be branch-scoped unless the data owner is explicitly global. |
| Kiosk rule | Kiosk is a customer surface. It must not expose admin/catalog/POS access. |
| Next mission | `CV2-PH2-07-DASHBOARD-AUTHZ-CATALOG-OPS`. |

Implication for Dashboard: the operator control plane should be accessible from
POS/admin surfaces, not from the customer kiosk. Kiosk lock-down work is a
separate product fix, not part of this docs-only ownership mission.

## 4. Immediate Dashboard Boundary

The Dashboard is approved conceptually as the control plane, but the write
surface must be staged:

| Stage | Allowed after this mission? | Reason |
| --- | --- | --- |
| Read-only catalog projection | Yes, after `CV2-PH2-03` parity sentinels. | Safe read path if shared projection is proven. |
| Availability toggle | Yes, after `CV2-PH2-02` coverage. | Existing backend owner exists. |
| Price/item/category CRUD | Not yet. | Needs catalog event/snapshot coverage and authz. |
| Variation/extra/addon CRUD | Not yet. | Needs composition sync coverage. |
| Quantitative stock quantity management | Not yet. | Requires stock subsystem migration/service gate. |
| Payment simulation | Not yet. | Requires authz, audit trail, and clear test-only/prod behavior. |
| Order live board read | Yes after branch-scope/authz sentinels. | Read-only is lower risk. |
| Order status/handover write | Not yet. | Needs explicit state-machine endpoint and sentinels. |

## 5. Blockers and Required Decisions

| Blocker | Impact | Owner mission |
| --- | --- | --- |
| Catalog event contract is overloaded through `ItemAvailabilityChanged` for some item changes. | Kiosk/POS may drift after price/category/composition edits. | `CV2-PH2-02` |
| POS/Kiosk do not yet have proven projection parity on the canonical service. | Migrating consumers could change menu payload unexpectedly. | `CV2-PH2-03` |
| Category branch scope is not signed. | Dashboard UI could imply branch-specific category management incorrectly. | `CV2-PH2-06` |
| Dashboard catalog/ops roles are not separated from cashier permissions. | A kiosk/cashier route could gain inappropriate catalog write capability. | `CV2-PH2-07` |
| Quantitative stock has no accepted V1 SSOT. | Stock quantity UI would be fake or race-prone. | Future stock subsystem mission |
| Delivery fee/address flow needs product implementation. | Pickup/delivery POS/Kiosk behavior remains unresolved from user report. | Separate delivery quote mission |

## 6. Sentinel Requirements For Next Missions

| Mission | Minimum sentinel |
| --- | --- |
| `CV2-PH2-02` | Every catalog/availability mutation that changes client menu data bumps snapshot and invalidates branch cache. |
| `CV2-PH2-03` | POS/Kiosk projections are structurally equivalent for shared fields, with intentional differences documented. |
| `CV2-PH2-04` | POS and Kiosk consume canonical projection without frontend price calculation. |
| `CV2-PH2-05` | Variation/extra/addon/offer mutations propagate to projection and snapshot. |
| `CV2-PH2-06` | Category branch/global behavior is locked by ADR and tests. |
| `CV2-PH2-07` | Dashboard catalog/ops permissions reject kiosk/customer/cashier-only actors and enforce branch scope. |
| `CV2-PH2-08` | Queue allocation duplicate/retry metrics are observable after D-M13. |
| `CV2-PH2-09` | Event docs match actual `EventContract` and outbox payloads. |

## 7. Recommended Train 2 Order

1. `CV2-PH2-02-MENU-CATALOG-EVENT-SNAPSHOT-COVERAGE`
2. `CV2-PH2-03-MENU-PROJECTION-PARITY-SENTINELS`
3. `CV2-PH2-06-CATEGORY-BRANCH-SCOPE-ADR`
4. `CV2-PH2-07-DASHBOARD-AUTHZ-CATALOG-OPS`
5. `CV2-PH2-04-KIOSK-POS-CONSUME-MENU-PROJECTION`
6. `CV2-PH2-05-VARIATION-EXTRA-ADDON-SYNC-COVERAGE`
7. `CV2-PH2-08-QUEUE-D13-POSTRELEASE-OBSERVABILITY`
8. `CV2-PH2-09-OUTBOX-DOCS-CONTRACT-ALIGNMENT`
9. `CV2-PH2-10-LEGACY-DEDUP-ARCHIVE-MANIFEST`

Reason: event/snapshot correctness and projection parity must precede consumer
migration. Authz must precede Dashboard writes. Archive/dedup remains last and
requires a human archive gate.

## 8. Execution Trace

```json
{
  "delegation": "codex-extension",
  "task_id": "CV2-PH2-01-DATA-OWNERSHIP-MATRIX",
  "mode": "docs-only",
  "files_modified": [
    "reports/audit/PHASE2_DATA_OWNERSHIP_MATRIX_2026-04-27.md",
    "docs/decisions/D-PH2-DATA-OWNERSHIP.md"
  ],
  "invariants_considered": [
    "backend_pricing_ssot",
    "order_status_enum_and_state_machine",
    "branch_id_isolation",
    "dispatch_after_commit",
    "order_service_frontend_order_service_symmetry",
    "frozen_zones_require_gate"
  ],
  "product_code_touched": false
}
```

## 9. Close Condition

`CV2-PH2-01_STATUS: CLOSED`

The mission closes because the ownership matrix and ADR are now recorded, no
product code was changed, and the remaining implementation work is decomposed
into explicit follow-up missions.
