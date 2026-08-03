# PHASE F — Sync Resilience

Status: BLOCKED_PHASE_A_UNSIGNED
Owner: Codex after B and C.

## Goal

Improve realtime latency and frontend dedup while preserving outbox/after-commit guarantees.

## Tasks

### F.1 `CV1-FEAT-KDS-REALTIME-PUSH`

Objective: KDS subscribes to branch events while retaining poll fallback.

Allowlist:
- KDS Vue component/store
- realtime helper
- tests/e2e KDS realtime spec

Mandatory tests:
- `npx playwright test tests/e2e/kds-receives-pos-realtime.spec.ts`
- KDS Vitest if helper added

Exit criteria:
- POS-created order appears on KDS in under 2 seconds when Echo is available.
- poll fallback remains active.
- no pre-commit broadcast dependency.

### F.2 `CV1-FEAT-OSS-REALTIME-PUSH`

Objective: OSS subscribes to order status changes.

Allowlist:
- OSS component/store
- realtime helper
- E2E/Vitest tests

Exit criteria:
- status screen updates without manual refresh.
- fallback poll remains.

### F.3 `CV1-FRONT-REALTIME-DEDUP`

Objective: deduplicate realtime events by `correlation_id` over a 60s TTL window.

Allowlist:
- shared realtime dedup helper
- POS/KDS/OSS consumers
- tests

Mandatory tests:
- `npx vitest run tests/js/realtimeDedupCorrelation.spec.js`

Exit criteria:
- duplicate Echo event is ignored.
- later distinct event is accepted.
- memory map has TTL cleanup.

### F.4 `CV1-OBS-ORDER-LIFECYCLE-CHANNEL`

Objective: route order lifecycle logs to a dedicated channel.

Allowlist:
- `config/logging.php`
- order/KDS/payment log call sites
- tests/lint

Mandatory checks:
- `php artisan config:clear`
- test or smoke for `Log::channel('order_lifecycle')`
- grep audit for old dispersed lifecycle logs

Exit criteria:
- operational logs are searchable by lifecycle channel.

## Deferred V1.5

- `V15-FEAT-SYNC-BACKFILL-ENDPOINT`
- `V15-INFRA-BROADCAST-CIRCUIT-BREAKER`
