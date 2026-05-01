# PHASE B — Test Stabilization And True Bug Fixes

Status: BLOCKED_PHASE_A_UNSIGNED
Owner: Codex after Phase A.
Product posture: fix real bugs first, migrate legacy tests second.

## Goal

Turn current red families into green, without weakening quote binding, EventContract, branch isolation, or schema gates.

## Order

1. B.1 R4 kiosk offline queue idempotency
2. B.2 R3 KDS own-branch visibility
3. B.3 R6 kiosk machine forced branch
4. B.4 R1 POS quote-binding legacy tests
5. B.5 R2 outbox fixtures K09B
6. B.6 R5 queue-number unique migration, only after schema gate

## Tasks

### B.1 `CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY`

Objective: preserve original local/idempotency keys through queue v1/v2 migration, replay, stale marking, force retry, and cancel.

Likely allowlist:
- `resources/js/helpers/kioskOfflineQueue.js`
- `resources/js/store/modules/kioskCart.js` only if required by call contract
- `tests/js/kioskOfflineQueue.spec.js`
- `tests/js/kioskOfflineQueueMigration.spec.js`
- `tests/js/kioskOfflineQueueV2.spec.js`

Forbidden:
- backend payment endpoints
- offline payment scope gates
- card/TR offline enablement

Mandatory tests:
- `npx vitest run tests/js/kioskOfflineQueue.spec.js tests/js/kioskOfflineQueueMigration.spec.js tests/js/kioskOfflineQueueV2.spec.js`
- `npx vitest run tests/js/kioskCartOfflinePaymentScope.spec.js tests/js/sentinels/kioskOfflineIdPrefix.spec.js`

Exit criteria:
- original idempotency key reused as `X-Idempotency-Key`.
- legacy `localKey` preserved at migration.
- stale entry cancel/force retry clears persisted entries.

### B.2 `CV1-FIX-R3-KDS-OWN-BRANCH-VISIBLE`

Objective: own branch orders are visible to KDS chef while foreign branch orders remain hidden.

Likely allowlist:
- `app/Services/KitchenDisplaySystemOrderService.php`
- KDS order controller if query composition requires it
- `tests/Feature/BranchIsolationTest.php`
- `tests/Feature/SyncComprehensiveTest.php`
- a new exact sentinel if missing

Forbidden:
- removing `BranchScope`
- global admin fallback for chef
- weakening foreign branch denial assertions

Mandatory tests:
- `php artisan test --filter=BranchIsolationTest`
- `php artisan test --filter='SyncComprehensiveTest|KdsBranchFilterExactTest|OrderListBranchExactnessSentinelTest|OrderShowBranchGuardSentinelTest'`

Exit criteria:
- chef sees own branch order.
- chef does not see foreign branch order.
- KDS service query has explicit branch predicate.

### B.3 `CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH`

Objective: kiosk order branch is forced from machine context; forged payload branch is ignored.

Likely allowlist:
- kiosk auth middleware / resolver
- `app/Http/Controllers/Frontend/OrderController.php` only if machine branch is bound there
- `tests/Feature/KioskSecurityTest.php`
- machine resolver tests

Forbidden:
- accepting unauthenticated kiosk machine tokens
- trusting request `branch_id`

Mandatory tests:
- `php artisan test --filter=KioskSecurityTest`
- `php artisan test --filter='PaymentConfirmMachineResolverTest|PaymentConfirmCrossBranchTest|KioskOfflinePaymentScopeTest'`

Exit criteria:
- valid kiosk machine returns 201 on forged branch payload with machine branch.
- invalid token still returns 401/403.

### B.4 `CV1-FIX-R1-POS-QUOTE-BINDING-TESTS`

Objective: update legacy POS tests to use server quote token/signature contract.

Allowlist:
- `tests/**`
- shared test helper only

Forbidden:
- `app/Http/Controllers/Admin/PosController.php`
- `app/Services/OrderService.php`
- `app/Services/Order/OrderQuoteService.php`
- weakening quote validation

Mandatory tests:
- `php artisan test --filter='AntiGravity|POSComprehensive|PosDiscount|PosKioskPricingParity|PosOrderRequestNullableTotal|PosOrderTax|PosPricingSsotProof|PosPriorityApi|PosTicketRestaurant|PosUI|SyncComprehensive'`

Exit criteria:
- tests create quote through helper.
- legacy direct `/api/admin/pos` payloads include valid `quote_token` + `quote_signature`.
- product app diff is empty.

### B.5 `CV1-FIX-R2-OUTBOX-FIXTURES-K09B`

Objective: update manual outbox fixtures to satisfy EventContract keys.

Allowlist:
- `tests/Feature/OutboxTest.php`
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`
- fixture helper if shared

Forbidden:
- `app/Domain/Events/EventContract.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`

Mandatory tests:
- `php artisan test --filter='OutboxConcurrentWorkerDedupeTest|OutboxTest|EventContractTest|KioskRealtimeBroadcastTest'`

Exit criteria:
- payload fixtures include `_origin`, `payment_method`, `queue_number`.
- broadcaster failure tests still test broadcast failure, not contract failure.

### B.6 `CV1-FIX-R5-QUEUE-NUMBER-UNIQUE-MIGRATION`

Status: BLOCKED_SCHEMA_GATE.

Objective: add DB unique guard for `(branch_id, queue_number)`.

Exit criteria:
- `QueueNumberUniquenessSentinelTest` PASS.
- migration reviewed under M-13/schema gate.
- concurrency behavior documented.
