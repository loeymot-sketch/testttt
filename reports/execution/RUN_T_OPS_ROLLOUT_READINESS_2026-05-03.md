# RUN — T-OPS-POS-POLLING-01 + T-OPS-POS-WIZARD-COMPOSER-01

Date: 2026-05-03
TASK_ID: CV1-V2-REMAINING-MISSIONS-001
Phase: EXECUTE (ops rollout readiness)

## Objective

Prepare a safe, reversible rollout of:

1. POS fallback polling runtime flag
2. POS wizard composer-aware runtime flag

No schema change in this batch.

## Runtime flags and current defaults

Source: `config/catalog_v15.php` + `resources/views/admin-pos-v4.blade.php`

- `FK_CATALOG_POS_FALLBACK_POLLING_ENABLED` (default `false`)
- `FK_CATALOG_POS_FALLBACK_INTERVAL_MS` (default `30000`)
- `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` (default `false`)

Injected at runtime in POS entry:

- `window.foodkingConfig.posFallbackPolling.enabled`
- `window.foodkingConfig.posFallbackPolling.intervalMsWhenDisconnected`
- `window.foodkingConfig.posWizardComposerAware.enabled`

## Staging rollout checklist

1. Set env:
   - `FK_CATALOG_POS_FALLBACK_POLLING_ENABLED=true`
   - `FK_CATALOG_POS_FALLBACK_INTERVAL_MS=30000` (or 20000 for initial soak)
   - `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true`
2. Rebuild/reload assets and config:
   - `php artisan config:clear`
   - `php artisan optimize:clear`
   - `npm run dev` (or prod pipeline equivalent)
3. Validate targeted tests:
   - `npx vitest run tests/js/runtimeSyncFlagsWiring.spec.js tests/js/ossSyncFallback.spec.js tests/js/kdsSyncCadence.spec.js tests/js/catalogStudioRouting.spec.js`
   - `php artisan test tests/Feature/Menu/PosMenuProjectionFeatureFlagTest.php tests/Feature/Menu/PosKioskProjectionParityTest.php`
4. Smoke on staging UI:
   - POS with websocket disconnected: polling resumes and data refreshes
   - POS wizard on composer-profile item: composer path used (no regression legacy item)
5. Observe logs/metrics for 24h soak:
   - No spike in `/api/admin/items` errors
   - No increase in POS wizard runtime JS errors

## Production rollout checklist (after staging soak PASS)

1. Apply same env flips in production.
2. Deploy during low-traffic window.
3. Keep rollback command ready (below).
4. Monitor 60 minutes post-flip:
   - POS ordering path
   - Composer-based wizard rendering
   - API error rate and latency

## Rollback plan (instant)

Set:

- `FK_CATALOG_POS_FALLBACK_POLLING_ENABLED=false`
- `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=false`

Then:

- `php artisan config:clear`
- `php artisan optimize:clear`

Behavior returns to legacy-safe path without data migration.

## Validation evidence run in this session

- JS sentinels: `26/26 PASS` (includes runtime flags and Studio sentinels)
- PHP features:
  - `PosMenuProjectionFeatureFlagTest`: `5/5 PASS`
  - `PosKioskProjectionParityTest`: `5/5 PASS`

## Verdict

OPS rollout readiness: **PASS (ready for staging flip)**.

Known blocker outside this batch:

- `SOURCE-FK` gate remains pending human approval (`docs/gates/GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md`).
