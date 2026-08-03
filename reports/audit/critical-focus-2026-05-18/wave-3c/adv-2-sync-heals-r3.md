# Adversarial RED — Sync Heals Wave 2c — Wave 3c dispute

**Auditor**: RED-team (adversarial, read-only)
**Date**: 2026-05-18
**Branch**: `v1-0-1-hardening-2026-05-17`
**Commits under review**: `e54368bde` (TrustHosts) + `4a60a06da` (Outbox lock)
**Stance**: hostile / production-shipping gate

---

## 1. Heal 2c-4 — TrustHosts whitelist defense (`e54368bde`)

### Verdict: **REJECT — introduces P0 regex bypass; ships an exploitable whitelist in production**

The intent is correct (close the X-Forwarded-Host poisoning vector opened by `TrustProxies::$proxies='*'`). The implementation is empirically broken because the heal writes plain strings into `hosts()`, and Symfony wraps every entry as an **unanchored, case-insensitive regex**.

### SYNC-ADV3C-01 (P0 — production-exploitable) — unanchored regex whitelist admits attacker hosts

`app/Http/Middleware/TrustHosts.php:21-25` returns:
```php
return [
    $this->allSubdomainsOfApplicationUrl(),  // anchored: ^(.+\.)?...$
    '127.0.0.1',                              // PLAIN STRING
    'localhost',                              // PLAIN STRING
];
```

`vendor/symfony/http-foundation/Request.php:652` transforms each entry via:
```php
self::$trustedHostPatterns = array_map(fn ($hostPattern) => \sprintf('{%s}i', $hostPattern), $hostPatterns);
```

`{127.0.0.1}i` and `{localhost}i` are **unanchored** regex patterns. `.` matches any character; `localhost` matches anywhere in the host string. Empirically verified (single-process PHP):

| Attacker `Host:` header  | Pattern that matched      | Outcome |
|--------------------------|---------------------------|---------|
| `127X0X0X1`              | `{127.0.0.1}i`            | trusted |
| `attacker-localhost.com` | `{localhost}i`            | trusted |
| `evil.localhost-bypass.io` | `{localhost}i`          | trusted |
| `real-127a0a0a1.com`     | `{127.0.0.1}i`            | trusted |

Combined with `TrustProxies::$proxies='*'` at `app/Http/Middleware/TrustProxies.php:24` and `HEADER_X_FORWARDED_HOST` enabled at line 33, the attacker path is: send `X-Forwarded-Host: attacker-localhost.com` → `Request::isFromTrustedProxy()` returns true (any source trusted) → `getTrustedValues(HEADER_X_FORWARDED_HOST)` at `Request.php:1153` picks it up → trustedHostPatterns matches → `Request::getHost()` returns the spoofed value → propagates into `URL::route()`, password reset links, signed URLs, and any `abort_unless($request->getHost() == …)` gate.

**This is strictly worse than the pre-heal state**: before, TrustHosts was commented and the spoof window was conceptual (no validation but also no false sense of security). Now the whitelist is wired in production but admits attacker-controlled values that contain the substring "localhost" or match the dot-globbed `127.0.0.1` pattern.

**Fix required**: anchor the strings:
```php
'^127\.0\.0\.1$',
'^localhost$',
```
(or remove them and let `allSubdomainsOfApplicationUrl()` carry the loopback when `APP_URL=http://localhost`.)

### SYNC-ADV3C-02 (P1 — coverage class) — entire `TrustHostsTest` is a false-positive class

Vendor `Illuminate\Http\Middleware\TrustHosts::shouldSpecifyTrustedHosts()` at `vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustHosts.php:56-60`:
```php
return ! $this->app->environment('local') && ! $this->app->runningUnitTests();
```

In PHPUnit (`runningUnitTests()=true`) **and** in local dev (`APP_ENV=local`, see `.env:2`), the middleware short-circuits before `Request::setTrustedHosts()` fires. The 3 tests at `tests/Feature/Middleware/TrustHostsTest.php:49-135` therefore prove only:
  - Test A: `hosts()` returns *some* regex matching `lecayenne.local` and not `attacker.com`. Does not exercise Symfony's pattern wrapping at `Request.php:652`. Would not have caught SYNC-ADV3C-01.
  - Test B: array contains the literal strings `'127.0.0.1'` and `'localhost'`. **Asserts the bug itself as a feature**.
  - Test C: middleware is registered in `Kernel::$middleware`. Reflection-only; does not exercise runtime.

No test in this project ever validates that a spoofed `X-Forwarded-Host` request is rejected by the runtime stack. The commit's verification log "phpunit --filter=TrustHosts 3/3 GREEN" attests to a coverage class that, by Symfony's contract, cannot fail. Same coverage class as a `dd($var); $this->assertTrue(true);`.

### SYNC-ADV3C-03 (P2 — deployment edge) — `0.0.0.0` / IPv6 `::1` not whitelisted

`php artisan serve --host=0.0.0.0` and IPv6 loopback `::1` are not in `hosts()`. Both are unmatched by `^(.+\.)?localhost$` and by the unanchored `127.0.0.1` pattern (`::1` does not contain "localhost"; `0.0.0.0` does not match `127X0X0X1` regex). If the operator binds either in single-restaurant prod, the server returns empty host (per `Request.php:1167-1172`). Acceptable for the documented loopback PHP-FPM deployment; flag for ops doc.

---

## 2. Heal 2c-5 — Outbox `Cache::lock` concurrent retry (`4a60a06da`)

### Verdict: **ADEQUATE in design, test coverage incomplete; one P1 latent**

The lock contract is sound: `Cache::lock(key, 60)->get()` is non-blocking, distinct keys for the two commands at `OutboxRetryFailedCommand.php:29` and `OutboxWebhookRetryFailedCommand.php:51`, release in `finally` at lines 49-51 / 71-73. The "skip then SUCCESS" return path is operationally correct (cron does not page on collision).

The design context that the prompt missed: the scheduler already guards both commands at `app/Console/Kernel.php:65,77` with `withoutOverlapping(10)->onOneServer()`. The new `Cache::lock` is NOT redundant — it defends the **manual `artisan` CLI path** that admins invoke outside the scheduler. The heal's stated threat model ("2 admins running outbox:retry-failed same minute") is exactly this path. Note for the record, not a finding.

### SYNC-ADV3C-04 (P1 — latent) — 60s TTL can expire mid-batch on large DLQ

`OutboxRetryFailedCommand.php:31` sets `LOCK_TTL_SECONDS = 60`. The batch loop at lines 65-113 (and the webhook command analog at lines 87-135) iterates ALL failed events since `--since=1h` with no `take()` cap, each iteration writes an `audit_logs` row through `AuditLogService::write()` (HMAC chain + `Cache::lock` itself per `app/Services/Fiscal/AuditLogService.php`) and dispatches a job. Under DLQ surge (e.g. Stripe outage drained 24h worth of webhooks: `--since=24h` hourly cron at `Console/Kernel.php:75`), the audit-chain lock contention alone can push the batch past 60 seconds. When the TTL expires mid-handle:
  1. A second invocation `Cache::lock(...)->get()` returns true (key vacated).
  2. Both processes now iterate overlapping `failed` rows (no row-level pessimistic lock visible in the `forceFill` block at lines 97-105).
  3. Re-introduces the exact double-audit + double-dispatch defect the heal was supposed to close.

Remediation options: auto-extend via `$lock->block(0, fn() => …)` keep-alive pattern, raise TTL to 5 min, OR add a `take(N)` batch cap so wall-clock is bounded.

### SYNC-ADV3C-05 (P1 — coverage gap) — test driver is ArrayStore, production is redis/file

`phpunit.xml:37` sets `CACHE_DRIVER=array`. `vendor/laravel/framework/src/Illuminate/Cache/ArrayLock.php:37-51` is per-process in-memory (`ArrayStore::$locks` array property). The four tests at `tests/Feature/Outbox/OutboxConcurrentRetryLockTest.php:37-179` validate ONLY same-process semantics:
  - Test A/C ("skip when lock already held") pre-acquires via `Cache::lock(...)` and runs `Artisan::call(...)` in the SAME PHP process. ArrayStore singleton is shared → lock observable. **Cannot regression-detect** a redis-driver mismatch.
  - `.env.testing:70` documents `CACHE_DRIVER=file`; production `.env:18` is `redis`. Neither is exercised by these tests.

Acceptable for V1 LOCAL with `CACHE_DRIVER=redis` (redis SETNX is atomic). Becomes a defect if a future deploy ships `CACHE_DRIVER=file` to a multi-worker PHP-FPM pool — `FileLock` filesystem race window exists (vendor `Illuminate\Cache\FileStore` uses `file_put_contents` without `flock()`). `.env.testing:67-71` already warns of this. Negative-space test missing.

### SYNC-ADV3C-06 (P2 — latent batch-continuity invariant) — long-batch + ttl-expiry → audit row exists without dispatch attempt

Tied to ADV3C-04. The write-then-dispatch ordering at `OutboxRetryFailedCommand.php:73-95` ensures audit row IFF dispatch attempted, IF the lock holds. If TTL expires between the audit write (line 88) and the dispatch (line 105) of one event, and a second process restarts iteration from offset 0, the dispatched event is double-counted but the FIRST audit row is still in chain. Tamper trail consistent (good), but downstream KDS/OSS see duplicate events without a "replay-recovery" indicator. Note for ops runbook.

### SYNC-ADV3C-07 (P3 — informational) — `Log::channel('fiscal')` failure-mode

`OutboxRetryFailedCommand.php:39,90,107` and webhook analog all log to `fiscal` channel only. If `config/logging.php:182` `fiscal` channel throws (disk full, permissions), the warn / error message is lost AND the calling try is silently swallowed. CLAUDE.md §13 prefers `stack` channel for NF525-traceable observability. Not a defect (warn path is non-fatal) but a downgrade vs `Log::channel('fiscal')->error(...)->info(...)` resilience.

---

## 3. P0/P1 findings — summary

| ID            | Sev | File:line                                                              | Defect                                                              |
|---------------|-----|------------------------------------------------------------------------|---------------------------------------------------------------------|
| SYNC-ADV3C-01 | P0  | `app/Http/Middleware/TrustHosts.php:23-24`                             | Unanchored regex strings admit `attacker-localhost.com` etc.        |
| SYNC-ADV3C-02 | P1  | `tests/Feature/Middleware/TrustHostsTest.php:49-135`                   | Coverage class cannot fail in test env (runningUnitTests short-circuit). |
| SYNC-ADV3C-04 | P1  | `OutboxRetryFailedCommand.php:31`, `OutboxWebhookRetryFailedCommand.php:53` | 60s TTL < worst-case batch wall-clock on DLQ surge.            |
| SYNC-ADV3C-05 | P1  | `phpunit.xml:37` + test file at lines 37-179                           | Test driver is ArrayStore; production drivers (redis/file) untested. |
| SYNC-ADV3C-03 | P2  | `app/Http/Middleware/TrustHosts.php:23-24`                             | `0.0.0.0` / `::1` not whitelisted; ops-doc gap.                     |
| SYNC-ADV3C-06 | P2  | `OutboxRetryFailedCommand.php:73-105`                                  | Audit-row-without-replay invariant if TTL expires mid-event.        |
| SYNC-ADV3C-07 | P3  | `OutboxRetryFailedCommand.php:39,90,107` + webhook analog              | Single-channel logging swallow if `fiscal` channel fails.           |

---

## 4. Negative space — tests that should exist and don't

1. **Spoof integration**: ON top of vendor `TrustHosts` (or via `Request::setTrustedHosts()` directly), a feature test that POSTs with `X-Forwarded-Host: attacker-localhost.com` to a route generating an absolute URL, asserting the URL contains `lecayenne.local` and NOT the spoofed value. Would have failed at SYNC-ADV3C-01.
2. **Signed-URL spoof resistance**: `URL::temporarySignedRoute()` under spoofed host — asserts the signature host is the canonical APP_URL.
3. **Production-driver lock regression**: parameterised test running `OutboxConcurrentRetryLockTest::test_outbox_retry_failed_skips_when_lock_already_held` under `CACHE_DRIVER=file` (and `redis` in CI ext). Currently 0 coverage.
4. **Batch-exceeds-TTL**: seed N failed events such that one full pass exceeds 60s wall-clock (mock `AuditLogService::write` with `usleep`), assert no double-dispatch via `Bus::assertDispatchedTimes(1)` per event id.
5. **Reflection-on-Kernel anti-pattern** (SYNC-ADV3C-02 Test C): replace with a request-level assertion that a non-whitelisted host returns 400 (via temporary local `setTrustedHosts` injection in setUp).

---

**Final disposition**: Heal 2c-4 **MUST NOT SHIP** in current form. Heal 2c-5 may ship if SYNC-ADV3C-04 is acknowledged in V1.0.2 backlog OR mitigated by adding `->take(500)` to the eloquent query at `OutboxRetryFailedCommand.php:58-61` and webhook analog at `:80-83`.
