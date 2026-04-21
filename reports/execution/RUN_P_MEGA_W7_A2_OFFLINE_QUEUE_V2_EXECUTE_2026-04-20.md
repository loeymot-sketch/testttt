# RUN_P_MEGA_W7_A2_OFFLINE_QUEUE_V2_EXECUTE_2026-04-20

EXECUTE_DELEGATION: foodking-complex-implementer
TASK_ID: P_MEGA_W7_RESILIENCE_HARDWARE_BRANCH_2026-04-20
SUBCYCLE: W7.A.2 offline queue v2
PRIMARY_MODEL: foodking-complex-implementer (GPT-5.4)
OUTCOME: PASSED

## bug_signatures
- localstorage_only_queue_backend
- retry_without_exponential_backoff_jitter
- missing_offline_queue_cross_tab_lock
- stale_offline_entries_not_invalidated_on_availability_event
- missing_conflict_resolution_ui_for_stale_queue_entries

## Scope executed
- `resources/js/helpers/kioskOfflineQueue.js`
- `resources/js/helpers/kioskOfflineQueueDb.js`
- `resources/js/store/modules/kioskCart.js`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue`
- `package.json`
- `package-lock.json`
- `tests/js/kioskOfflineQueue.spec.js`
- `tests/js/kioskOfflineQueueV2.spec.js`
- `tests/js/kioskOfflineQueueMigration.spec.js`

## Technical decisions
- Replaced v1 localStorage persistence with an IDB-backed queue wrapper using `idb-keyval`, guarded by 5s timeouts and a localStorage fallback for legacy environments.
- Kept existing public queue entrypoints (`saveOrder`, `syncQueue`, `getPendingCount`, `getAbandonedCount`, `startAutoSync`, `stopAutoSync`, `clearQueue`) while moving replay semantics to v2 entry metadata (`attempts`, `lastAttemptAt`, `abandoned*`, `staleItems`).
- Implemented replay throttling with exponential backoff + jitter, a lock key with TTL for cross-tab protection, and queue invalidation hooks wired from the existing `ItemAvailabilityChanged` listener.
- Surfaced queue conflicts in kiosk UI through a warning CTA + dedicated accessible modal; resolution actions mutate only offline queue state and do not touch payment or receipt flows.

## Validation
- `git log -1 --oneline` confirmed `9c8f9e202`
- Observed baseline Vitest: `628/628`
- Targeted queue specs: `30/30`
- Full Vitest after execute: `649/649`

## Invariant-sensitive checks
- Pricing SSOT: untouched, no frontend price logic added.
- Order status enum usage: untouched.
- `branch_id` isolation: replay payload contract preserved; no branch filter logic changed.
- Dispatch-after-commit: untouched, no backend dispatch path edited.
- W5 gated files: untouched (`KioskPaymentComponent.vue`, `KioskOrderSummaryComponent.vue`, `KioskConfirmationComponent.vue`).

## Residual risks
- Broadcast invalidation still depends on existing Echo wiring; this execute extends the listener but does not add frontend Echo bootstrap.
- When IndexedDB is unavailable, the queue falls back to localStorage with a guarded warning; browser-specific quota behavior still depends on runtime limits.
