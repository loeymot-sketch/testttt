# CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY

TASK_ID: CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY
PHASE: EXECUTE
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8 - proceed to P0 product fixes despite Phase A still STARTED_NOT_CLOSED.
BRANCH: cycle/CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY

## Scope

Allowed write scope:

- `resources/js/helpers/kioskOfflineQueue.js`
- `resources/js/helpers/kioskOfflineQueueDb.js` if needed
- `tests/js/kioskOfflineQueue.spec.js` if needed
- `tests/js/kioskOfflineQueueMigration.spec.js` if needed
- `tests/js/kioskOfflineQueueV2.spec.js` if needed
- mission/report/self-audit artifacts

Backend, Vue components, routes, config, database and EventContract are out of scope.

## Status

EXECUTION_STATUS: IMPLEMENTED

## Repro Before

Command:

```bash
npx vitest run tests/js/kioskOfflineQueue.spec.js tests/js/kioskOfflineQueueMigration.spec.js tests/js/kioskOfflineQueueV2.spec.js
```

Result:

```text
3 failed files, 6 failed tests, 28 passed
```

Log: `missions/CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY/repro_before.log`

## Implementation

Changed `resources/js/helpers/kioskOfflineQueue.js` only.

- Preserved `localKey` exactly when provided, including legacy v1 keys and explicit idempotency keys.
- Added a separate `offlineKey` for UI/offline waiting references so `saveOrder()` still returns an `offline_*` id when the durable `localKey` is not offline-prefixed.
- Kept replay, backoff telemetry, stale marking, force retry and cancel keyed by `entry.localKey`.
- No test assertions were changed.
- No backend, Vue component, route, config, database or EventContract files were changed by this mission.

## Validation

```bash
npx vitest run tests/js/kioskOfflineQueue.spec.js tests/js/kioskOfflineQueueMigration.spec.js tests/js/kioskOfflineQueueV2.spec.js
```

Result:

```text
3 files passed, 34 tests passed
```

```bash
npx vitest run tests/js/sentinels/kioskOfflineIdPrefix.spec.js
```

Result:

```text
1 file passed, 3 tests passed
```

```bash
npx vitest run tests/js/kiosk*.spec.js
```

Result:

```text
55 files passed, 398 tests passed
```

## Scope Notes

The repository already had unrelated dirty files in `app/**`, `routes/**`, `config/**`, `database/**`, and `resources/js/components/**` before this mission. This mission did not edit those paths.

## Verdict

MISSION_VERDICT: PASS
