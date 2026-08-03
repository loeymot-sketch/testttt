# Adversarial RED — Sync Heals Wave 2b — Wave 3b dispute

- Scope: 2 commits, read-only.
- Branch: `v1-0-1-hardening-2026-05-17`.
- Targets:
  1. `79e214542` — TrustProxies `$proxies='*'` (P1).
  2. `e264be951` — Outbox audit-then-dispatch (P1). Attribution-only; substantive change in `a50b81db4`.
- Posture: hostile. Heals are guilty until file:line evidence absolves them.
- NO cloud assumptions. Local nginx → PHP-FPM topology.

---

## 2. Heal 2b-2 (TrustProxies `'*'`) — verdict + findings

**Verdict: ACCEPTED FOR LAN. Ships latent attack surface that flips to P0 the day this box gets a non-loopback interface. Two follow-ups required.**

Evidence:
- `app/Http/Middleware/TrustProxies.php:24` — `protected $proxies = '*';`.
- `TrustProxies.php:31-36` — headers: `X_FORWARDED_FOR | X_FORWARDED_HOST | X_FORWARDED_PORT | X_FORWARDED_PROTO | X_FORWARDED_AWS_ELB`.
- `app/Http/Kernel.php:18` — `// \App\Http\Middleware\TrustHosts::class,` (DISABLED).
- `tests/Feature/Middleware/TrustProxiesThrottleIsolationTest.php:67-98` — single test, IP-A vs IP-B.
- `app/Http/PaymentGateways/Routes/stripe.php:26-28` — webhook with `throttle:60,1`.
- `.env:7` — `APP_URL=http://localhost:8000`.

### SYNC-ADV3B-01 (P1 today / P0 latent) — `$proxies='*'` + TrustHosts disabled = unbounded host + scheme spoof

`Kernel.php:18` keeps `TrustHosts` OFF while `TrustProxies::$proxies='*'` (`:24`) accepts every upstream's `X-Forwarded-Host` AND `X-Forwarded-Proto` (`:33,:35`). Commit message claims only per-IP throttle restoration — silent on the host/scheme channel it also opens. Today shielded by `nginx → 127.0.0.1`. Tomorrow on `php artisan serve --host=0.0.0.0`, or any 0.0.0.0 nginx listener, attacker spoofs `Host`/`Proto`:
- cache key poisoning via `$request->getHttpHost()`.
- mail/password-reset links (env without `APP_URL` fall back to `getHttpHost()`).
- Stripe callback HTTPS→HTTP downgrade via attacker `X-Forwarded-Proto: http` → mixed-content + signature mismatch.

Wave 4 must (a) re-enable `TrustHosts` with allowlist, (b) tighten `$proxies` to the nginx CIDR (e.g. `['127.0.0.1/32']`).

### SYNC-ADV3B-02 (P1) — sentinel test has NO negative control

`TrustProxiesThrottleIsolationTest.php:67-98` asserts IP-B≠429 after IP-A bucket burn. Never asserts what `$proxies=null` would do (commit message claims IP-B would be 429 — `:64-65`). A future composer/skeleton overwrite that drops `protected $proxies = '*';` ships a silent regression: test still passes because PHPUnit `REMOTE_ADDR` differs per call by accident. Add a paired test that nullifies `$proxies` via reflection and asserts IP-B IS 429.

### SYNC-ADV3B-03 (P2) — `HEADER_X_FORWARDED_AWS_ELB` honoured with no AWS in scope

`TrustProxies.php:36` ORs `HEADER_X_FORWARDED_AWS_ELB`. No AWS LB in the local stack. Residual surface: attacker-set `X-Forwarded-AWS-ELB-IP` is trusted because `$proxies='*'`. Strip the bit.

### SYNC-ADV3B-04 (P3) — zero test for the host-header side effect this heal opened

No `getHttpHost()` spoof test. Add one before Phase D.

---

## 3. Heal 2b-5 (Outbox audit-then-dispatch) — verdict + findings

**Verdict: ACCEPTED for in-process ordering. REJECTED as "guarantees dispatch attempted" — under `QUEUE_CONNECTION=redis`, audit row certifies QUEUE INTENT, not dispatch. Contract overclaims.**

Evidence:
- `OutboxRetryFailedCommand.php:30-78` — audit BEFORE dispatch, `continue` on each branch.
- `OutboxWebhookRetryFailedCommand.php:54-101` — same pattern, hard `branch_id=0`.
- `AuditLogService.php:93-98` — `branch_id=null` throws; `0`+ OK.
- `DispatchDomainEventsJob.php:20,46` — `implements ShouldQueue`, `onQueue('high')`.
- `ProcessWebhookEventJob.php:27` — `implements ShouldQueue`.
- `.env:20` — `QUEUE_CONNECTION=redis`.
- `tests/Feature/Outbox/OutboxReplayAuditTest.php:130-216`, `:223-350`.

### SYNC-ADV3B-05 (P1) — "audit row IFF dispatch attempted" is FALSE under Redis queue

`OutboxRetryFailedCommand.php:35-37` comment: "audit row exists IFF dispatch was attempted". Wrong in prod. With `QUEUE_CONNECTION=redis` (`.env:20`) and `DispatchDomainEventsJob` `ShouldQueue` (`DispatchDomainEventsJob.php:20`), `::dispatch()` at `OutboxRetryFailedCommand.php:70` only pushes to `queues:high`. Worker paused/OOM/dead → audit says "attempted 03:30:01", side-effect never runs, no completion row. NF525 chain now contains "attempted" with no "completed" pair — auditor sees an ambiguous trail. Fix: either rename action to `outbox.replay_enqueued` AND require a second row `outbox.replay_completed` written in the job's `handle()`, OR refuse to run unless `Queue::connection()->getConnectionName()==='sync'`. Today does neither.

### SYNC-ADV3B-06 (P1) — no row lock → concurrent runs double-print audit chain

`OutboxRetryFailedCommand.php:23-26` (`DomainEvent::query()->failed(5)->where(…)->get()`) and `OutboxWebhookRetryFailedCommand.php:47-50` have NO `lockForUpdate`, no `Cache::lock`, no PID file. Two admins firing `--since=1h` within the same second → both read 10 rows → both write 10 audit rows (20 total claiming the same 10 events) → both enqueue. `DispatchDomainEventsJob.php:89` mitigates the eventual broadcast double-fire via "already-dispatched" guard, but the chain is permanently double-printed — violates `OutboxReplayAuditTest.php:69` ("Exactly one audit_logs row per replayed event"). Wrap in `Cache::lock('outbox.retry_failed.domain', 60)->get(…)` or `SELECT … FOR UPDATE`.

### SYNC-ADV3B-07 (P2) — `catch (\Throwable)` swallows `Error` family

`OutboxRetryFailedCommand.php:54,71` catch `\Throwable`. Also swallows `OutOfMemoryError`, `AssertionError`, future `TypeError`. Each becomes a single fiscal log line + `continue` — operator reads "audit_log write failed" and assumes chain-lock spike when reality is OOM mid-`performInsert()`. Tighten to `catch (\RuntimeException | \QueryException | \InvalidArgumentException $e)`.

### SYNC-ADV3B-08 (P2) — fiscal log re-entrancy hides its own breadcrumb

`AuditLogService.php:167` writes `Log::channel('fiscal')->info('audit_log.write',…)` INSIDE the successful insert path. If fiscal channel breaks (filesystem RO, future TS rotation typo), the `try` at `OutboxRetryFailedCommand.php:38-53` catches via `\Throwable` (`:54`) and routes to `Log::channel('fiscal')->error(…)` (`:55`) — SAME broken channel. Operator sees silent gap. Use `Log::channel('stack')` or `'emergency'` for the fallback.

### SYNC-ADV3B-09 (P2) — action key `'outbox.replay'` shared by two commands

`OutboxRetryFailedCommand.php:42` and `OutboxWebhookRetryFailedCommand.php:66` both use `'outbox.replay'`. Disambiguation requires reading `resource` (`domain_event` vs `webhook_event`) AND `payload.command`. Rename to `outbox.domain.replay` / `outbox.webhook.replay`. One-line test update at `OutboxReplayAuditTest.php:73,118`.

### SYNC-ADV3B-10 (P3) — no idempotency across retries

Re-running `--since=24h` writes a NEW row per event id every run. Cron stall + manual retry → N audit rows per event = forensic noise embedded in the immutable chain. Either add `attempt_counter` to payload + UNIQUE `(resource, resource_id, payload->attempt_counter)`, or pre-filter via `AuditLog::where('action','outbox.replay')->where('resource_id',$event->id)->where('created_at','>=',$cutoff)->doesntExist()`.

### SYNC-ADV3B-11 (P2) — test 4 installs custom Bus dispatcher with no tearDown restore

`OutboxReplayAuditTest.php:233-294` binds a throwing dispatcher via `$this->app->instance(Dispatcher::class, …)`. No `tearDown` cleanup. `RefreshDatabase` resets DB but NOT container bindings. PHPUnit `--filter` or randomized order could let the throwing dispatcher leak to neighbour tests. Add explicit `$this->app->forgetInstance(\Illuminate\Contracts\Bus\Dispatcher::class)` in `tearDown`.

---

## 4. P0 / P1 findings (new)

| ID            | Sev | File:line                                                | Summary |
|---------------|-----|----------------------------------------------------------|---------|
| SYNC-ADV3B-01 | P1  | Kernel.php:18 + TrustProxies.php:24,33,35                | `$proxies='*'` + TrustHosts disabled = unbounded host/scheme spoof. P0 in 0.0.0.0/cloud. |
| SYNC-ADV3B-02 | P1  | TrustProxiesThrottleIsolationTest.php:67-98              | Sentinel has no negative control — silent regression cone. |
| SYNC-ADV3B-05 | P1  | OutboxRetryFailedCommand.php:35-37, .env:20              | "Audit IFF dispatch attempted" false under Redis queue; chain attests enqueue intent, not side-effect. |
| SYNC-ADV3B-06 | P1  | OutboxRetryFailedCommand.php:23-26, OutboxWebhookRetryFailedCommand.php:47-50 | No row lock → concurrent runs double-print audit rows. |

P2/P3: ADV3B-03, 04, 07, 08, 09, 10, 11 inline above.

---

## 5. Negative space (NOT tested, NOT challenged)

1. No `X-Forwarded-Host` spoof test (`TrustProxies.php:33` honoured). Pending until TrustHosts is re-enabled.
2. No `X-Forwarded-Proto` downgrade test on Stripe callback.
3. No test for dispatch-succeeds-then-Redis-dies — chain attests "attempted" with no "completed".
4. No test for concurrent `Artisan::call('foodking:outbox:retry-failed')` from same boot (ADV3B-06).
5. No assertion on `audit_logs.ip` / `user_agent` for CLI rows. `AuditLogService.php:140-142` derives from `request()` which is null under `artisan`. NF525 inspection sees blank — either pass `'ip'=>'cli:'.gethostname()` or document.
6. No `\Error`-family test on `AuditLogService::write` (OOM/CompileError) — `\Throwable` catch (ADV3B-07) swallows silently.
7. No fiscal-channel-outage test — cascading log failures hide breadcrumbs (ADV3B-08).
8. **Critical: `OutboxReplayAuditTest` never calls `AuditLogService::verifyChain()` post-scenario.** Whole point of write-then-dispatch is chain integrity. Add `assertNull($svc->verifyChain($branchId))` after each test.

End of dispute. Both heals proceed to Wave 4 conditional on ADV3B-01/02/05/06 close or formal residual acceptance.
