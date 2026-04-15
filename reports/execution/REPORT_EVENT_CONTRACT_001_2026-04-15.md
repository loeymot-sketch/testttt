# REPORT_EVENT_CONTRACT_001_2026-04-15

EXECUTE_DELEGATION: app-complex-implementer

## Summary

Implemented the V1 event contract across outbox persistence, realtime dispatch, frontend Echo adapters, documentation, and targeted feature coverage.

## Files Changed

- `app/Enums/EventType.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- `resources/js/services/eventContract.schema.json`
- `resources/js/services/eventContract.js`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `docs/EVENT_CONTRACT.md`
- `tests/Feature/EventContractTest.php`

## Behavior Changed

- Domain events now persist canonical V1 `event_type` values via `EventType` constants.
- Outbox listeners now assign a UUID `correlation_id` when creating `domain_events` rows.
- `DispatchDomainEventsJob` now broadcasts the canonical V1 envelope:
  - `version`
  - `type`
  - `aggregate_id`
  - `branch_id`
  - `occurred_at`
  - `correlation_id`
  - `payload`
- Frontend realtime consumers now subscribe through `eventContract.js` and read business fields from `parsed.payload`.
- `broadcast_as` names remain unchanged: `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`.

## Invariant Checks

- Pricing SSOT: untouched.
- OrderStatus enum usage: unchanged authority preserved; no new hardcoded status strings introduced.
- `branch_id` isolation: preserved. Channel routing remains branch-scoped and branch_id is surfaced in the envelope.
- Dispatch ordering: preserved. Outbox jobs are still dispatched via `DB::afterCommit`.
- Symmetry review: N/A, `OrderService` and `FrontendOrderService` were not modified.

## Validation

- `php artisan test tests/Feature/EventContractTest.php`
- Result: 5 passed
- IDE lints on edited PHP/Vue/JS files: no errors

## Issues

- OutboxTest.php required update: asserted old `event_type` (`App\Events\OrderCreated`) and old raw payload format. Fixed to use `EventType::ORDER_CREATED` and validate canonical envelope shape. All 206 tests now pass.

## Audit: PASSED
Cycle closed 2026-04-15. All invariants respected. 206 tests passed. 0 build errors. OutboxTest fixed as direct consequence of event_type format change.
