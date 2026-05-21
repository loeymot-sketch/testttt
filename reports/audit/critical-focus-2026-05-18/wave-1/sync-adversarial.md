# Adversarial RED — Sync — Wave 1

**Mission:** Hostile attack on FoodKing V1 LOCAL Le Cayenne Sync layer (Webhooks, Outbox, Idempotency, Broadcast, Polling).
**Branch:** `v1-0-1-hardening-2026-05-17` HEAD `6908edbde`.
**Date:** 2026-05-18.
**Constraint:** LOCAL-only, no cloud findings.
**Framing:** Hostile, attacker-on-LAN with read of source. We assume the attacker has reached the loopback/internal network of the restaurant tablet/POS host but is NOT authenticated.

The sync layer is **substantially harder than the rest of the codebase** — Wave Z 5C and Sprint 3A swept most of the obvious defects (firstOrCreate idempotency, listener `wasRecentlyCreated` guards, signature enforcement, lockForUpdate claim). Findings below are the residue: log-channel DoS, missing-throttle endpoints, missing audit-log on manual replay, and a query-string hash leak on SenangPay GET.

---

## 1. Findings

### SYNC-RED-01 — **P1 — DoS via Stripe/SenangPay webhook spam (no throttle on signature-verification)**
**Category:** DoS / availability / log-fill.
**Evidence:**
- `app/Http/PaymentGateways/Routes/stripe.php:22-24` — route registers with `middleware(['installed'])` only. NO `throttle:*`.
- `app/Http/PaymentGateways/Routes/senangpay.php:17-19` — same: only `['installed']`. NO throttle.
- `app/Http/PaymentGateways/Gateways/Stripe.php:214` calls `StripeClient\Webhook::constructEvent` (HMAC-SHA-256 over arbitrary-size payload). On failure, `Stripe.php:216-224` writes a fiscal-channel warning log per request.
- `app/Http/PaymentGateways/Gateways/Senangpay.php:107-121` calls `hash_hmac('sha256', $canonical, $secret)` then `hash_equals`. Failure writes `Log::channel('fiscal')->warning` at line 111.

**Reproduction (LAN attacker):**
```
for i in $(seq 1 100000); do
  curl -X POST http://127.0.0.1:8000/payment/stripe-webhook/ \
    -H "Stripe-Signature: bogus" \
    -d '{"id":"evt_x","type":"x"}' &
done
```
HMAC-SHA-256 cycles burn CPU; every failure also writes a `fiscal` log line. The `fiscal` channel is the NF525-critical audit channel — if disk fills, legit fiscal logging stalls and orders cannot close. This is a fiscal-availability attack disguised as a webhook flood.

**Mitigation (local-only):** Attach `throttle:60,1` (per-IP) on both routes. Move attacker-controlled invalid-signature warnings to a non-fiscal `security` channel (or drop them to debug after burst threshold).

**Cross-validation:** Pair with **Zone 2 POS Payment auditor** (downstream impact on order close), and **Zone 1 NF525 auditor** (fiscal-channel disk pressure → audit_logs write stall).

---

### SYNC-RED-02 — **P1 — SenangPay webhook accepts GET, leaks `hash` to access log via query string**
**Category:** Information disclosure (HMAC secret-derivative in plaintext logs).
**Evidence:**
- `app/Http/PaymentGateways/Routes/senangpay.php:18`:
  `Route::match(['get', 'post'], '/senangpay-webhook/', [Senangpay::class, 'webhook'])`
- `app/Http/PaymentGateways/Gateways/Senangpay.php:63` reads `$hash = (string) $request->input('hash', '')`. `Request::input()` reads from query string for GET. No method-guard short-circuit.
- Nginx/Apache access logs ALWAYS log full URI for GET → the HMAC-SHA-256 hash + status_id + order_id + transaction_id end up in `/var/log/...` plaintext indefinitely. Anyone with log-read on the host (or a leaked rotation) can reproduce signed events offline (replay below).

**Reproduction:**
```
curl "http://127.0.0.1:8000/payment/senangpay-webhook/?status_id=1&order_id=42&transaction_id=test&msg=ok&hash=<leaked-hash-from-old-access-log>"
```
If `transaction_id` is fresh, `WebhookEvent::firstOrCreate` creates a row, `status_id="1"` writes a `CapturePaymentNotification` → **fake payment success on a known order** without ever needing the secret. The attacker only needs ONE prior leaked hash AND the ability to vary `transaction_id` (the canonical string is reconstructible since the canonical concat order is documented at Senangpay.php:107).

Wait — the hash MUST include the SAME `transaction_id` as the request (the canonical string is `status_id|order_id|transaction_id|msg`). So a leaked hash is bound to a single `transaction_id`. But the duplicate-detection short-circuits at line 139 → returns `duplicate_ignored` 200 → no harm on replay of the SAME txn. So the realistic risk degrades from "fake payment" to "**discovery: confirms that a given (status_id, order_id, transaction_id, msg) tuple was ever a valid SenangPay event**". Still discloses payment metadata correlation. **P1 stays** because it's prevention-of-leak rather than active exploit.

**Mitigation (local-only):**
1. Switch route to `Route::post()` only. SenangPay v2 spec uses POST for server-to-server callbacks; GET was a debug convenience.
2. If GET must remain for ops, add a route guard that 405-rejects GET with non-empty `hash` parameter.

**Cross-validation:** Pair with **Zone 4 BranchScope/Auth auditor** (information-leak chain) and **Zone 1 NF525** (payment-metadata correlation in unrotated logs).

---

### SYNC-RED-03 — **P1 — Webhook DLQ replay writes NO audit log (NF525-adjacent)**
**Category:** Compliance / forensic-trail gap.
**Evidence:**
- `app/Console/Commands/OutboxWebhookRetryFailedCommand.php:42-75` — handler resets `status=pending` + dispatches `ProcessWebhookEventJob`, writes a `Log::channel('fiscal')->info` summary at line 68 but does NOT write to `audit_logs` (no call to `AuditLogService`).
- `app/Console/Commands/OutboxRetryFailedCommand.php:17-40` — same gap for the `domain_events` (non-webhook) DLQ. Resets `attempts=0` + dispatches.
- Both commands re-drive operations that may affect `CapturePaymentNotification` rows → which feed the payment-success path → which writes `audit_logs` on the next `markProcessed`. But the **human/cron decision to replay** is not itself audited.

**Why this matters under NF525:** "Manual financial-adjacent admin actions" expect a tamper-evident trail. Fiscal channel logs are tamper-evident at the file-rotation layer but NOT chain-signed. A future audit ("why did €X reappear on day Y?") cannot prove who/when called replay — the row's `processed_at` will simply move forward.

**Mitigation (local-only):**
- Add `AuditLogService::recordEvent('webhook_dlq_replay', [...])` inside the foreach in `OutboxWebhookRetryFailedCommand.php:51`.
- Same in `OutboxRetryFailedCommand.php:26`.
- Reuses existing append-only audit chain — zero new tables.

**Cross-validation:** Pair with **Zone 1 NF525 Fiscal Chain auditor** (it owns the audit_logs semantic).

---

### SYNC-RED-04 — **P2 — `/api/kds-order/sync` polling endpoint relies on global throttle only**
**Category:** DoS / misconfiguration foot-gun.
**Evidence:**
- `routes/api.php:1010` — `Route::get('/sync', [KdsSyncController::class, 'sync'])` inside `kds-order` group.
- No `throttle:*` on the route or the enclosing group. Inherited middleware (line 193-194) is `auth:sanctum` + `verify.api` — neither rate-limits.
- Laravel's default `api` middleware group does attach `throttle:api` (configured at `app/Providers/RouteServiceProvider.php:54-58` = `config('app.api_throttle_per_minute', 120)` per-user).
- `config/catalog_v15.php:87` — `disconnected_base_ms` defaults to 10000ms but is `env('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', 10_000)`. If an operator sets `FK_CATALOG_KDS_DISCONNECTED_BASE_MS=10` (ten milliseconds) by typo → KDS hammers backend at 100 req/s/token.

**Reproduction:** set env to `10`, disconnect WebSocket (kill Soketi), KDS browser polls at 100 RPS, hits the 120/min ceiling in 1.2 s, then sees 429 storm for the rest of the shift.

**Risk:** The KDS surface goes DARK during dinner service. Not an attack vector — a self-foot-gun. P2 because misconfig-only.

**Mitigation (local-only):**
- Add a dedicated `RateLimiter::for('kds-sync', fn () => Limit::perMinute(20)->by($user))` and attach `throttle:kds-sync` on the route. Caps the polling regardless of env.
- Validate `disconnected_base_ms >= 2000` in `config/catalog_v15.php` (or a config-cache sanity test) to refuse pathological values.

---

### SYNC-RED-05 — **P3 — `WebhookEvent::firstOrCreate` is SELECT+INSERT, not atomic INSERT-ON-DUPLICATE-KEY**
**Category:** Race condition / transient 5xx.
**Evidence:**
- `app/Http/PaymentGateways/Gateways/Stripe.php:247-259` and `app/Http/PaymentGateways/Gateways/Senangpay.php:125-137` both use Eloquent `firstOrCreate(['provider' => x, 'webhook_id' => y], [...])`.
- Under the hood (Laravel 10): SELECT WHERE ... LIMIT 1 → if null, INSERT. Two concurrent retries can both miss the SELECT phase, both attempt INSERT, the loser hits UNIQUE `(provider, webhook_id)` → `QueryException` → caller-side this bubbles up, processing wraps it (Stripe.php:311) → `markFailed` + return 500 → provider retries → row exists → 200 `duplicate_ignored`.

**Functional outcome:** correct (no double-process). **Observable side effect:** transient 500 + a `processing_failed` event row for the loser race.

**Mitigation (local-only):** Replace with `upsert([...], uniqueBy: ['provider', 'webhook_id'])` then re-fetch — Laravel translates to `INSERT ... ON DUPLICATE KEY UPDATE`. Atomic.

**Cross-validation:** None — purely internal sync hygiene.

---

### SYNC-RED-06 — **P3 — `IDEMPOTENCY_FAIL_OPEN=true` bypasses middleware entirely on storage failure**
**Category:** Defense-in-depth foot-gun.
**Evidence:**
- `config/idempotency.php:23` — `'fail_open' => (bool) env('IDEMPOTENCY_FAIL_OPEN', false)`.
- `app/Http/Middleware/IdempotencyKeyMiddleware.php:125-129` — on `IdempotencyStorageUnavailableException` AND `fail_open=true`, returns `$next($request)` straight through.
- Comment on line 128 says "bypass — relies on app-layer UNIQUE". That defense is real (DB UNIQUE on `branch_id, idempotency_key`) BUT it only fires on routes that have the DB-level UNIQUE — not all mutating endpoints do.

**Risk:** if Redis dies + `FAIL_OPEN=true` + the endpoint lacks DB UNIQUE → double-spend / double-charge possible. Default is `false`, so this is a deployment foot-gun, not a current defect.

**Mitigation (local-only):** Document `FAIL_OPEN=true` as a deploy gate ONLY for endpoints with verified DB UNIQUE coverage. Default stays `false`.

---

### SYNC-RED-07 — **P3 — `RevokeTokensOnBranchDeactivated` allows in-flight requests to complete (TOCTOU)**
**Category:** Authorization-edge / window-of-acceptance.
**Evidence:**
- `app/Listeners/RevokeTokensOnBranchDeactivated.php:52-55` — bulk `PersonalAccessToken::query()->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete()`.
- A request that has already resolved `$request->user()` via `auth:sanctum` middleware has the token row loaded into the request context. The DELETE doesn't roll back the in-flight controller — it completes normally.

**Risk:** narrow window (single request-lifetime, ~100-500 ms). A POS operator on a soon-to-be-deactivated branch can finish ONE more order. Owner spec doesn't claim hard-stop semantics. P3 stands unless owner re-spec.

**Mitigation (local-only):** none required for V1 LOCAL Le Cayenne (single branch).

---

## 2. Cross-Validation Candidates

| Finding | Pair with | Why |
|---|---|---|
| SYNC-RED-01 | Zone 2 POS Payment | Fiscal-log disk pressure cascades into order-close failures. |
| SYNC-RED-01 | Zone 1 NF525 Fiscal | Same channel collision (`Log::channel('fiscal')`). |
| SYNC-RED-02 | Zone 4 BranchScope/Auth | Payment-metadata leak chain. |
| SYNC-RED-03 | Zone 1 NF525 Fiscal | `audit_logs` semantic ownership. |

---

## 3. Negative Space — Vectors that DID NOT find defects

Recording these explicitly so the next adversarial wave doesn't waste cycles.

- **Vector #1 (Stripe forge, missing secret bypass):** `Stripe.php:199-208` returns HTTP 500 `misconfigured` when secret is empty — NO silent bypass. Signature verification is mandatory. **CLEAN.**
- **Vector #4 (listener double-fire, Wave Z 5C residue):** `grep -L "wasRecentlyCreated" app/Listeners/Persist*.php` returns EMPTY. All 11 Persist*ToOutbox listeners carry the guard. Wave Z swept completely. **CLEAN.**
- **Vector #5 (DispatchDomainEventsJob race):** `app/Jobs/DispatchDomainEventsJob.php:65-86` uses `lockForUpdate + dispatched_at` guard inside a transaction; loser observes `dispatched_at != null` post-lock and returns silently. **CLEAN.**
- **Vector #7 (idempotency cross-user replay):** `IdempotencyKeyMiddleware.php:77-82` scoped key includes `branch_id`, `user_id`, and SHA-256 of the raw key. Cross-user replay impossible. **CLEAN.**
- **Vector #8 (Soketi auth bypass via APP_DEBUG):** `routes/channels.php:25-39` `Broadcast::channel('branch.{branchId}', ...)` always runs auth callback regardless of APP_DEBUG. Kiosk tokens are pinned to `KioskMachine::branch_id`. Admins pinned to `branch_id=0`. Staff pinned to own branch. **CLEAN.**
- **Vector #2 (Stripe replay attack):** `WebhookEvent::firstOrCreate` keyed on `(provider, webhook_id)` with DB UNIQUE — duplicates return 200 `duplicate_ignored` at Stripe.php:261-268 and Senangpay.php:139-146. Race window is SYNC-RED-05 (P3 transient 500), but never double-processes. **CLEAN on the security axis.**
- **Vector #12 (PruneOutboxCommand too aggressive):** `app/Console/Commands/PruneOutboxCommand.php:35` default `--older-than-days=90`; safe-set query (lines 50-60) preserves `audit_logs` + `z_reports` entirely (only touches `domain_events`). Comment block at lines 25-28 explicitly invokes NF525 invariant. **CLEAN.**

---

## 4. Summary

| Priority | Count | Findings |
|---|---|---|
| P0 | 0 | — |
| P1 | 3 | SYNC-RED-01, SYNC-RED-02, SYNC-RED-03 |
| P2 | 1 | SYNC-RED-04 |
| P3 | 3 | SYNC-RED-05, SYNC-RED-06, SYNC-RED-07 |

**No P0.** The sync layer's frozen-zone-adjacent surfaces (signature verification, idempotency-key scoping, listener replay guards, broadcast channel authz, lockForUpdate claim race) are **all defended** at the level the source claims. Residual P1 cluster is operational-discipline (route throttle, GET method, audit-log on manual replay) — not core-invariant breaks.

**Owner recommendation:** SYNC-RED-01 (fiscal-channel DoS) and SYNC-RED-03 (DLQ replay missing audit_logs) are the only two that touch NF525 surface. Schedule both into Wave H or V1.0.2 heal — they're each ~10 LOC fixes with zero frozen-zone touch.
