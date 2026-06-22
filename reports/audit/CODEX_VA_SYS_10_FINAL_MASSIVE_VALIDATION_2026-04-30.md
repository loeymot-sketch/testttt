# Codex — VA-SYS-10 Final Massive Validation — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-10`

## Verdict

`VA_SYS_10_VERDICT: PASS_FINAL_SOFTWARE_CLOSE_POST_VA_SYS_05_RERUN`

`VERSION_A_SYSTEM_SOFTWARE_CLOSE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`

`REASON: VA-SYS-01..05 are now closed, and the final validation pack was rerun after VA-SYS-05.`

This report was originally written before VA-SYS-01..05 closed. It is now updated with the post-VA-SYS-05 rerun evidence. The final run validates the critical sync/core area delivered in VA-SYS-00..09: product/choice rupture, composer constraints, central management authz, catalog/photo sync, dashboard builder hooks, full central management runtime E2E, outbox/realtime, event contracts, after-commit, frontend guards, production build and C3 runtime multi-surface.

It does not claim hardware/provider readiness; hardware remains deferred to industrial UAT.

## Post-VA-SYS-05 rerun evidence

After the original VA-SYS-10 run, VA-SYS-01..05 were completed. The final close then reran the critical software pack:

| Layer | Result |
| --- | --- |
| PHP core sync suites | 160 PASS |
| MySQL surface filtering suite | 6 PASS on isolated DB `foodking_va_sys_final_test` |
| Vitest critical suites | 49 PASS |
| Playwright VA-SYS-05 runtime | 3 PASS with `--repeat-each=3` |
| Playwright C3 runtime | 4 PASS with `--repeat-each=2 --retries=0` |
| Final close hygiene | PASS |

Fresh immutable runtime artifacts:

- `reports/antigravity/va-sys-05-central-management-final-close-2026-04-30.json`
- `reports/antigravity/c3-runtime-multi-surface-final-close-2026-04-30.json`

MySQL command used for the previously skipped surface-filtering contract:

```bash
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=foodking_va_sys_final_test DB_USERNAME=root DB_PASSWORD= CACHE_DRIVER=redis REDIS_CLIENT=predis php artisan test tests/Feature/Menu/FrontendSurfaceFilteringTest.php
```

Result: 6 PASS. This closes the SQLite skip as a local evidence gap without touching the real `foodking` database.

## Original pre-close validation summary

| Layer | Result |
| --- | --- |
| PHP critical suites | 175 PASS; pre-close SQLite run skipped 6 MySQL-only surface-filtering checks that are now closed by the post-close MySQL rerun |
| Vitest critical suites | 42 PASS |
| Playwright C3 runtime | 4 PASS with `--repeat-each=2 --retries=0` |
| Production build | PASS |
| JSONL memory parse | PASS |
| `git diff --check` scoped | PASS |

## Original pre-close PHP validation detail

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Services/Menu` | 23 PASS |
| `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` | 12 PASS |
| `php artisan test tests/Feature/Stock` | 21 PASS |
| `php artisan test tests/Feature/Composer` | 17 PASS |
| `php artisan test tests/Feature/Catalog` | 14 PASS |
| `php artisan test tests/Feature/Menu` | 23 PASS, 6 SKIP |
| `php artisan test tests/Feature/Outbox` | 14 PASS |
| `php artisan test tests/Feature/EventContractTest.php` | 9 PASS |
| `php artisan test tests/Unit/Domain/Events/EventContractUnitTest.php` | 12 PASS |
| `php artisan test tests/Feature/AfterCommitDispatchTest.php` | 14 PASS |
| `php artisan test tests/Feature/DispatchAfterCommitTest.php` | 8 PASS |
| `php artisan test tests/Feature/SyncComprehensiveTest.php` | 6 PASS |
| `php artisan test tests/Feature/KioskRealtimeBroadcastTest.php` | 2 PASS |

Original known skips:

- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`: 6 SKIP under SQLite because the contract relies on MySQL `JSON_CONTAINS`; expected to run in MySQL CI/staging.
- Closed by the post-VA-SYS-05 MySQL rerun above: 6 PASS on isolated database `foodking_va_sys_final_test`.

## Frontend validation detail

Command:

```bash
npx vitest run \
  tests/js/posRuptureUx.spec.js \
  tests/js/kioskRuptureUx.spec.js \
  tests/js/kioskWizardGenericComposer.spec.js \
  tests/js/posWizardComposerProfile.spec.js \
  tests/js/eventContractDedupe.spec.js \
  tests/js/correlationDedupePersistence.spec.js \
  tests/js/correlationDedupeCapacity.spec.js \
  tests/js/realtimeBroadcastFallback.spec.js \
  tests/js/kdsReactsToReconnectStorm.spec.js \
  tests/js/kdsBackoffOn5xx.spec.js \
  tests/js/kdsSyncCadence.spec.js \
  tests/js/kdsVersionGate.spec.js
```

Result:

- 12 files PASS
- 42 tests PASS

Expected warnings:

- negative `eventContract` tests log invalid-envelope warnings by design.
- `baseline-browser-mapping` dependency warning is informational and unrelated to FoodKing logic.

## Runtime C3 validation

Server:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Command:

```bash
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0
```

Result:

- 4 PASS

Original mutable JSON artifact at the time of the pre-close run:

- `reports/antigravity/c3-runtime-multi-surface.json`
- kiosk cash -> KDS/POS/OSS: PASS, KDS 5874 ms, OSS 2880 ms, order_id 667
- POS -> KDS/OSS: PASS, KDS 5879 ms, OSS 4893 ms, order_id 668

Observation:

- C3 proves local DOM/runtime propagation without manual reload.
- KDS timing is still slightly above the ideal 5s budget; keep as staging/provider perf watch, not a local software blocker.
- Final immutable post-VA-SYS-05 artifacts are listed in the rerun evidence section above.

## Build and hygiene

`npm run production`

- PASS

`git diff --check` scoped docs/memory/reports/tests/tasklist

- PASS

JSONL parse check:

- PASS for memory episodes 02, 03, 11, 12.

## Invariants checked

| Invariant | Status |
| --- | --- |
| Backend pricing SSOT | PASS: ComposerStepConstraint and order guards reject forged/stale selections |
| `OrderStatus` enum | PASS: no runtime status edits in this pass |
| `branch_id` isolation | PASS: VA-SYS-07B authz and menu/projection tests still green |
| Dispatch after commit | PASS: AfterCommit and DispatchAfterCommit tests green |
| Frozen zones | PASS: no `OrderService` / `FrontendOrderService` edits in VA-SYS-08/09/10 |
| Outbox retry/replay | PASS: production-like simulation and outbox suite green |
| Stock product + choice rupture | PASS: Stock and Pricing suites green |

## What is validated strongly

- Product and category catalog sync foundations.
- Product photo authz/invalidation path.
- Composer branch authz and published profile projection.
- Product-level rupture and choice-level stockable rupture.
- Backend rejection of stale/forged/unavailable choices.
- POS/Kiosk disabled-state frontend guards.
- Outbox provider failure recovery semantics.
- Event contract/dedupe/backoff/fallback behavior.
- Kiosk/POS to KDS/OSS local runtime propagation.
- Documentation/runbook/memory now maps actual files, events, cache keys and commands.

## What is still not finished for hardware/provider UAT

These are not discovered software regressions. The former VA-SYS-00..05 software plan items were closed after this report's original pre-close run; the current tasklist marks VA-SYS-00..10 PASS. The remaining items are outside local software validation:

| Area | Status | Why it still matters |
| --- | --- | --- |
| TPE physical terminal | Hardware UAT | Real success/refusal/timeout behavior cannot be proven locally. |
| Fiscal printer | Hardware UAT | Paper output and physical reprint behavior require the target printer. |
| Kiosk OS lockdown | Hardware UAT | Touchscreen, URL bar escape and OS policy must be tested on the device. |
| Cloud realtime provider | Provider UAT | Network latency, quota and provider failure modes are outside local runtime. |
| Google Maps live | Provider UAT | Live geocode, quota and provider error handling need staging/live provider checks. |

Hardware/provider UAT remains separate:

- physical payment terminal,
- fiscal printer,
- kiosk OS lockdown,
- cloud realtime provider,
- Google Maps live,
- real network loss/reconnect.

## Decision

`CORE_SYNC_AND_DATA_LAYER: PASS_LOCAL_STRONG`

`VERSION_A_SYSTEM_FINAL_CLOSE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`

Recommended next action:

1. Move to hardware industrial UAT.
2. Validate TPE, fiscal printer, kiosk OS lockdown, cloud realtime provider, Google Maps live, and physical network-loss behavior.
3. Keep the MySQL isolated test command above in the pre-release checklist.
