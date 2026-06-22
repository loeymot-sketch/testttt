# GPT Self Audit — CV1-LOT-D06-BROADCAST-FALLBACK-DOC

## Scope

- TASK_ID: `CV1-LOT-D06-BROADCAST-FALLBACK-DOC`
- Lot: D-06 DATA
- Delegation: `codex-extension`

## Changes

- `config/broadcasting.php`: added `polling_fallback` config for enabled, interval, and operator hint.
- `resources/js/store/modules/posOrder.js`: added realtime fallback resolver and POS store state/getters/action/mutation.
- `docs/REALTIME_SETUP.md`: documented broadcast-off polling fallback and front contract.
- `docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md`: added handoff notes for fallback config and POS hint.
- `tests/js/realtimeBroadcastFallback.spec.js`: added Vitest coverage.

## Invariants

- branch_id isolation: PASS. Fallback polling does not move branch filtering client-side; API remains authoritative.
- Dispatch after commit: PASS. No event dispatch path changed.
- Pricing backend SSOT: PASS. No pricing path changed.
- OrderStatus enum: PASS. No status path changed.
- Frozen zones/gates: PASS. No frozen service or gate-controlled file touched.
- Payment Ledger Option B: PASS. No payment ledger/M-04A scope introduced.

## Validation

- `php -l config/broadcasting.php` — PASS.
- `git diff --check -- docs/REALTIME_SETUP.md docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md config/broadcasting.php resources/js/store/modules/posOrder.js tests/js/realtimeBroadcastFallback.spec.js` — PASS.
- `npx vitest run tests/js/realtimeBroadcastFallback.spec.js` — PASS, 3 tests. Initial run failed because the new test did not mock `appService`; the test harness was corrected and rerun.
- `npx vitest run tests/js/posOrderIdempotency.spec.js tests/js/realtimeBroadcastFallback.spec.js` — PASS, 5 tests.

## Risk Review

- The store now exposes the hint contract; visual placement in a POS component can be handled by a later UI-scoped lot if required.
- Runtime frontend config still depends on existing `window.foodkingConfig` / Mix env exposure. No Blade/template injection was added because it is outside the D-06 allowlist.
- No schema or backend event changes were introduced.

VERDICT: PASS
