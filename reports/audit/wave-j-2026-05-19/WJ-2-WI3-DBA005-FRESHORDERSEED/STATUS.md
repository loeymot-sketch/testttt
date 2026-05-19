# WJ-2 — WI-3 DBA-005 / SEC-009 — FreshOrderSeed Production Env Guard

**Status:** GREEN — heal landed, sentinel triple-locked, regression clean.
**Wall-clock:** ~25 min (RECON 5 / TDD-RED 10 / FIX 3 / TDD-GREEN 2 / regression 5).
**Branch:** `v1-0-1-hardening-2026-05-17`
**Frozen-zone diff:** 0 — `app/Console/Commands/FreshOrderSeed.php` is NOT in the frozen list.

## Bug summary

`App\Console\Commands\FreshOrderSeed::handle()` (artisan `seed:fresh-orders`) executed
`SET FOREIGN_KEY_CHECKS=0` + `TRUNCATE orders` + `TRUNCATE order_items` unconditionally.
An operator running the command on a production console would have wiped the entire
fiscal order history — NF525 chain breakage and irreversible data loss.

**File:** `app/Console/Commands/FreshOrderSeed.php`
**Risk class:** P0 destructive / data-loss / NF525 chain integrity.

## TDD discipline (RED → GREEN)

1. **RED 1** — sentinel test at `tests/Feature/Sentinels/FreshOrderSeedProductionGuardSentinelTest.php`
   ran in `APP_ENV=production` (coerced via `$this->app->detectEnvironment(fn() => 'production')`).
   Without the guard, command reached `DB::statement('SET FOREIGN_KEY_CHECKS=0')`; under
   sqlite test DB that emitted `SQLSTATE[HY000]: General error: 1 near "SET": syntax error`.
   The exception itself proved the destructive path executed — the canary `Order` would have
   been TRUNCATEd. RED confirmed.

2. **FIX** — 7 LOC env guard added at top of `handle()`, mirroring the canonical pattern
   from `E2EStressCommand`, `CleanupTestFixturesCommand`, `EnsureKioskMachineCommand`, and
   `Iter15CleanupTestOrdersCommand`:

   ```php
   if (app()->environment('production')) {
       $this->error('seed:fresh-orders refuses to run in APP_ENV=production. Aborting (NF525 / data-loss protection).');
       return self::FAILURE;
   }
   ```

3. **GREEN** — 3/3 sentinel tests pass:
   - `test_production_env_refuses_and_preserves_orders` — coerces prod env, runs cmd, asserts
     `Command::FAILURE` exit + canary `Order` row still present in DB (proves TRUNCATE blocked
     BEFORE the destructive statement).
   - `test_local_env_does_not_emit_production_refusal` — coerces `local`, verifies no
     "refuse to run in APP_ENV=production" branch taken.
   - `test_testing_env_does_not_emit_production_refusal` — default `testing` env, same expectation.

## Regression evidence

- `php artisan test --filter "Seeder|FreshOrder"` → **20/20 PASS** (6.46s).
- `php artisan test --filter "ProductionGuard"` → **26/26 PASS** (1.61s) including
  `PosSimulationHardwareProductionGuardSentinelTest`, `IdempotencyMiddlewareProductionGuardSentinelTest`,
  `CorsAppUrlProductionGuardSentinelTest`, `FreshOrderSeedProductionGuardSentinelTest`.

## Deviations from mission spec (documented)

- **Test path:** mission spec said `tests/Feature/Seeders/FreshOrderSeedProductionGuardSentinelTest.php`;
  I placed it at `tests/Feature/Sentinels/...` to match the canonical convention used by all 4
  other production-guard sentinels (`PosSimulationHardware*`, `IdempotencyMiddleware*`, `CorsAppUrl*`,
  `Bypass*`). The `tests/Feature/Seeders/` directory exists for seeder-content tests, not for
  production-guard sentinels. Same file name as spec, same intent, convergent with codebase
  convention.
- **Implementation pattern:** mission spec offered "throw RuntimeException OR mirror
  POS_SIMULATION_HARDWARE pattern". `POS_SIMULATION_HARDWARE` lives in `AppServiceProvider`
  (a Provider — must throw because `boot()` returns void). FreshOrderSeed is a `Command`; the
  4 existing destructive-command guards all use `$this->error(...) + return self::FAILURE` —
  the canonical Command pattern. I mirrored that, not the throw-from-Provider pattern.
- **Sentinel test count:** spec asked for 3 tests; delivered exactly 3.

## Files touched (2 only)

- `app/Console/Commands/FreshOrderSeed.php` (heal, +12 LOC env guard + comment)
- `tests/Feature/Sentinels/FreshOrderSeedProductionGuardSentinelTest.php` (NEW sentinel, 117 LOC)

## Commit message

```
fix(seeders-WJ-2-P0): FreshOrderSeed env guard refuses production (WI-3 DBA-005)
```

## NF525 / frozen-zone impact

- Frozen-zone diff = **0** (FreshOrderSeed.php not in the frozen list).
- NF525 chain unchanged (this command never even reaches the fiscal layer; it operates
  pre-seed on a clean dev DB and is now hard-refused in prod).
- The guard CLOSES a previously-open vector for accidental fiscal-chain destruction.
