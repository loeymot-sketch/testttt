# Adversarial RED — Sync Heals Wave 2 — Wave 3 dispute

- **Branch / HEAD** : `v1-0-1-hardening-2026-05-17` @ `f24b49c42`
- **Commits under dispute** : `f24b49c42` (Stripe + SenangPay throttle + POST-only, bundled with KDS Vue) + `8dc6ec331` (Outbox*RetryFailed audit_logs trail)
- **Context** : LOCAL Le Cayenne single-restaurant. NO cloud. P0 ceiling = fiscal-chain corruption / data loss.
- **Posture** : hostile, read-only, file:line strict.

---

## Heal 2 — Webhook routes hardening — verdict **PARTIAL / RED**

Throttle middleware added at `app/Http/PaymentGateways/Routes/stripe.php:26-28` + `senangpay.php:21-23`. SenangPay verb-restricted to POST. Three real defects survive.

### SYNC-ADV3-01 — `TrustProxies::$proxies = null` collapses throttle to ONE LAN bucket → **P1**

- **File:line** : `app/Http/Middleware/TrustProxies.php:15` (`protected $proxies;` — uninitialised = null).
- **Mechanism** : Laravel keys throttle via `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:168-175` → `sha1($route->getDomain().'|'.$request->ip())`. With `$proxies=null`, framework refuses to honour `X-Forwarded-For`. Behind Nginx → PHP-FPM on 127.0.0.1 (standard local deploy), `$request->ip()` returns the upstream socket = `127.0.0.1`. **One throttle bucket for the entire LAN.**
- **Why the heal makes things WORSE** : Stated threat is "LAN HMAC-CPU DoS" (`stripe.php:23-25`, `senangpay.php:20`). Shared 60/min bucket means any LAN host — poisoned KDS tablet, misconfigured cron, guest Wi-Fi attacker — can exhaust the counter and starve legitimate Stripe deliveries. Stripe retries with backoff, eventually drops → fiscal allocation stalls (`PaymentService.php`), `webhook_events` rows stay `pending`, NF525 trail breaks silently.
- **Test coverage** : zero. `WebhookRouteHardeningTest.php:84-103` runs in-process with mocked Cache, never simulates two distinct IP sources sharing one bucket.
- **Fix sketch (NOT applied)** : set `TrustProxies::$proxies = '*'` for local LAN, OR replace `throttle:60,1` with a named `RateLimiter::for('webhook-stripe')` keyed on (provider + webhook_id when present). Owner gate.

### SYNC-ADV3-02 — Test pivots, SYNC-RED-02 root concern NOT verified closed → **P1 incomplete heal**

- **File:line** : `tests/Feature/Webhooks/WebhookRouteHardeningTest.php:54-74`.
- **Quote** : `// HTTP-level URL leak via nginx access log is a separate concern outside this scope.`
- **Attack** : Original SYNC-RED-02 root finding = GET URL leak (hash + transaction_id) into Nginx logs. Route is now POST-only at the **Laravel** layer, but `routes/web.php` SPA catchall (`Route::get` → `RootController`) still returns **200 HTML** for `GET /payment/senangpay-webhook/?hash=X&transaction_id=Y` per the implementer's own test docblock. Nginx `access.log` captures the full URI including query string — the leak vector the finding flagged is **alive**.
- **Verdict** : Commit message claims SYNC-RED-02 fixed; the test explicitly admits it isn't. Either the finding requires reframing (controller-not-reached + log leak still open) or a nginx-side denylist is needed. Neither is delivered.
- **Forensic asymmetry** : `f24b49c42` is bundled with KDS Vue work + WebhookRouteHardeningTest in **one commit** despite covering two unrelated subsystems. Git bisect across either feature becomes impossible without surgical rebase.

### SYNC-ADV3-03 — Distributed-IP CPU saturation still feasible on local LAN → **P2**

- **File:line** : `stripe.php:27`, `senangpay.php:22`.
- **Math** : Assume SYNC-ADV3-01 fixed (per-IP keying). 100 LAN IPs × 60 req/min = 6 000 HMAC verifications/min. Each `StripeClient\Webhook::constructEvent` (`Stripe.php:214`) computes HMAC-SHA256 + payload hash; SenangPay similar. Local PHP-FPM with ~10 workers saturates CPU at sustained 100 req/s, starving POS / Kiosk traffic on the same server. Heal raises the floor but does NOT eliminate the surface.
- **Risk weighting** : LAN attacker model is physical-access constrained → **P2 not P0**. But the threat model invoked by the heal's own comments demands acknowledgement.

### Probes resolved clean (NOT findings)

- **Probe (d) HEAD verb auto-registration** : `vendor/laravel/framework/src/Illuminate/Routing/Router.php:161-163` — `Route::post()` → `addRoute('POST', ...)`. Only `Route::get()` couples `['GET','HEAD']` (line 150). POST does NOT auto-register HEAD. Clean.
- **Probe (f) Stripe empty secret** : `Stripe.php:199-208` returns explicit 500 + `fiscal` log line. No silent bypass. Clean.
- **Probe (g) Redis atomic INCR** : Laravel `RateLimiter` uses `Cache::lock` + atomic primitives. Race negligible at 60/min. Clean.

---

## Heal 3 — Outbox replay audit_logs trail — verdict **PARTIAL / RED**

Commit `8dc6ec331` writes one `audit_logs` row per replayed event in `OutboxRetryFailedCommand.php` + `OutboxWebhookRetryFailedCommand.php`. Intent: NF525-adjacent traceability. Two real defects survive.

### SYNC-ADV3-04 — Order-of-operations: audit_log is LAST, dispatch happens BEFORE audit → **P1**

- **File:line** : `app/Console/Commands/OutboxWebhookRetryFailedCommand.php:57-85`. Sequence inside `foreach`:
  1. `$event->forceFill([STATUS_PENDING])->save()` (lines 57-60)
  2. `ProcessWebhookEventJob::dispatch($event->id)` (line 62)
  3. `$auditLog->write([...])` (lines 71-85)

  Identical shape at `OutboxRetryFailedCommand.php:29-58`.
- **Attack** : `AuditLogService::write` throws on:
  - `Cache::lock('audit_chain_b0', 10)->block(5)` timeout (`AuditLogService.php:103-108`) — Redis outage or stuck holder.
  - `UNIQUE(branch_id, prev_hash)` violation that fails the single retry (`AuditLogService.php:179-190`).
  - `FISCAL_AUDIT_SECRET` misconfig (`AuditLogService.php:300`).
- **Failure mode** when `write()` throws on event N:
  - Events 1..N-1 : reset + dispatched + audited ✓
  - Event N : reset + **dispatched** + NO audit row → orphan replay. The NF525-adjacent payment re-process happens with zero tamper-evident trail — exactly what the heal claims to close.
  - Events N+1..M : untouched, status stays `failed`, never re-driven. Partial DLQ replay — operator sees non-zero exit code but state is split.
- **Why the heal claim is FALSE** : SYNC-RED-03 was raised because "replay re-broadcasts fiscal-adjacent payloads with no audit trail". The heal **adds** an audit row in the happy path but leaves the failure-path orphan dispatch wide open. The chain-of-custody hole the finding cites is preserved.
- **Fix sketch (NOT applied)** : wrap each iteration in `DB::transaction(audit-then-dispatch)` so audit lock failure aborts BEFORE `dispatch()`. Or `try/catch` per iteration with `Log::channel('fiscal')->emergency()` + `continue` so partial progress is bounded + observable.
- **Test coverage gap** : `tests/Feature/Outbox/OutboxReplayAuditTest.php:41-77, 83-121` validates ONLY the happy path (`Bus::fake`, no audit-write failure injection).

### SYNC-ADV3-05 — Replay-of-replay forensic noise growth → **P2**

- **File:line** : `OutboxWebhookRetryFailedCommand.php:55-86`, `OutboxRetryFailedCommand.php:30-58`.
- **Mechanism** : Stuck event re-replayed across hourly cron runs (default `--since=24h` keeps it in window for a day) gets a NEW audit row each iteration. No dedup by `event_id`. 24 retries of a permanently-broken event → 24 audit rows with identical payloads.
- **Impact** : Chain remains cryptographically valid (each `current_hash` chains correctly), but chain-of-custody narrative becomes noisy. Auditors reconstructing a Q1 incident see N rows per logical replay attempt. Forensic clarity degraded, not destroyed.

### SYNC-ADV3-06 — `branch_id` chain asymmetry between commands → **P2 / informational**

- **File:line** : `OutboxWebhookRetryFailedCommand.php:72` (`branch_id => 0` always) vs `OutboxRetryFailedCommand.php:45` (`branch_id => $event->branch_id ?? 0`).
- **Observation** : Webhook replays land on chain 0 (system/CLI). Domain-event replays land on the event's branch chain. Defensible — `webhook_events` lacks `branch_id` column by schema (`2026_05_09_120000_create_webhook_events_table.php:41-89`). But auditors investigating per-branch incidents must query TWO chains. Undocumented in `docs/AUTHZ_MATRIX.md`.

### SYNC-ADV3-07 — `user_id => null` collapses actor attribution → **P2**

- **File:line** : `OutboxWebhookRetryFailedCommand.php:73`, `OutboxRetryFailedCommand.php:46`.
- **Attack** : Shell access (or compromised admin `php artisan tinker`) lets any actor invoke the command. The heal does not capture WHO. `posix_geteuid()`, `get_current_user()`, `getmypid()` are all available cheap. Heal's "tamper-evident trail" claim weakened — the row proves a replay happened, not who caused it.

### Probes resolved clean

- **Probe (c) action key collision** : `grep "'action'.*=>.*'outbox" app/` returns ONLY the two new sites. Clean.
- **Probe (d) branch_id default to 0** : forced by `webhook_events` schema, defensible.

---

## P0 / P1 findings summary

| ID | Sev | Heal | One-liner |
|---|---|---|---|
| SYNC-ADV3-01 | **P1** | 2 | TrustProxies=null collapses throttle to one LAN bucket; heal makes stated threat WORSE |
| SYNC-ADV3-02 | **P1** | 2 | Test pivots away from SYNC-RED-02 root concern (nginx URL leak still open) |
| SYNC-ADV3-04 | **P1** | 3 | Audit-write is LAST; failure → dispatched-without-audit + half-replayed batch |
| SYNC-ADV3-03 | P2 | 2 | Distributed-IP CPU saturation feasible on local LAN |
| SYNC-ADV3-05 | P2 | 3 | Repeated replays of stuck event pollute audit chain (24×/day) |
| SYNC-ADV3-06 | P2 | 3 | branch_id chain asymmetry between commands; docs gap |
| SYNC-ADV3-07 | P2 | 3 | user_id=null; no actor attribution beyond "the command ran" |

**Zero P0** — local-only ceiling reserved for fiscal corruption. SYNC-ADV3-04 borderline (NF525-adjacent silent dispatch) but contained to manual cron invocation.

---

## Negative space — what Wave 2 didn't even attempt to test

1. **No test asserts audit-write failure path doesn't strand dispatched jobs.** `Bus::fake` hides the dispatch outcome.
2. **No test for throttle survival under proxy collapse.** No `X-Forwarded-For` variation, no two-source IP simulation, no per-route bucket sentinel.
3. **No assertion that nginx logs don't capture URL params** on GET to `/payment/*-webhook/`. Original SYNC-RED-02 leak vector untested.
4. **No metric/alert on `outbox.replay` volume.** `SyncMetricsRecorder.php:29` tracks dispatch latency but no replay-storm signal.
5. **No `verifyChain()` sentinel after replay batch** — heal trusts chain integrity across N writes without smoke-testing (`AuditLogService.php:199-231` exists, unused).
6. **No actor attribution capture** (`posix_*`, artisan auth) — "tamper-evident" weakened.
7. **No `--dry-run` mode** on either replay command — operator cannot preview what would be re-driven.
8. **No upper bound on per-batch replay size.** 10 000 failed `webhook_events` → 10 000× `dispatch()` with zero inter-call rate-limit → self-DoS via the heal's own re-drive mechanism.

---

**Verdict** : Both heals **PARTIAL RED**. Threat models invoked in commit messages are not actually closed. Owner gate required before V1 ship. Recommend a SYNC-ADV3-fix batch (atomic audit-then-dispatch, named RateLimiter, nginx denylist for GET on webhook URIs) before claiming convergence.
