# Sync + KDS + OSS + Outbox Validation Report

Date: 2026-04-26  
Scope: POS/Kiosk to KDS synchronization, realtime contracts, outbox/event payloads, order status propagation, operational screens.

## Verdict

`SYNC_KDS_OUTBOX_VERDICT: PASS_WITH_TRACKED_PHASE3_DEBT`

The tested synchronization path is functional. Full PHPUnit passes all sync/outbox/event areas except the unrelated D-M13 queue-number sentinel, and Playwright validates KDS visibility plus kiosk-to-POS realtime contract.

## Evidence

| Check | Result | Evidence |
| --- | ---: | --- |
| Full PHPUnit | PASS for sync/outbox areas | `10-phpunit-full.log`; only failing file is D-M13 sentinel |
| Full Playwright | PASS | `35 passed`; includes KDS and realtime contract tests |
| Event/outbox contract | PASS in suite | EventContract, Outbox, realtime tests included in full run |
| Branch isolation lint | PASS | `45-lint-branch-isolation.log` |
| API health live | PASS | `32-api-health-live.log` |

## Confirmed Behaviors

- KDS login/surface does not crash in browser E2E.
- POS receives kiosk realtime contract in Playwright.
- Kiosk quote-pin and explicit order-type contracts pass in Playwright.
- Outbox K-09B fixtures have been brought into the passing PHPUnit surface.
- Event contract required keys remain tested in the full suite.
- API health live route is reachable as `/api/health/live`.

## Findings

### P1 — D-M13 still affects sync correctness indirectly

Even though sync tests pass, queue numbers are part of event payload and display identity. Until `(branch_id, queue_number)` is DB-unique, a rare collision could affect KDS/POS display semantics.

### P2 — KDS version stamp has known Phase 3 limitation

File:
- `app/Services/KdsSyncService.php:129-133`

The code documents a future move to `status_changed_at` for stronger per-order versioning. Current implementation uses `updated_at` for order version and `microtime` for batch response version.

Current release reading:
- Not a blocker because current tests pass.
- Keep as Train B / Phase 3 hardening unless a real missed-update reproduction is found.

### P2 — Route-list failure can hide sync route regressions

Route introspection currently fails because of the missing Senangpay gateway class. That is not a sync bug, but it weakens operational auditability of all routes until fixed.

## Required Post-D-M13 Validation

After queue-number migration:

1. Re-run full PHPUnit.
2. Re-run Playwright KDS/realtime specs.
3. Add or confirm a sentinel proving two branches can reuse the same visible queue number only if scoped correctly, and cannot collide within one branch.
4. Confirm outbox payloads still include `_origin`, `payment_method`, and `queue_number`.

