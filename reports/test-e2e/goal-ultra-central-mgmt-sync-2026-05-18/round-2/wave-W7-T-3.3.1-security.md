# T-3.3.1 — Webhook idempotency: SECURITY attack-surface audit (Round 2)

**Specialist**: SECURITY
**Scope**: read-only, hostile mindset, end-to-end attack chains
**Target task**: T-3.3.1 — webhook idempotency
**Date**: 2026-05-18
**Branch**: v1-0-1-hardening-2026-05-17

---

## 0. Threat model recap

Attacker capabilities assumed:
- **External Unauth**: knows public webhook URLs (`/payment/stripe-webhook/`, `/payment/senangpay-webhook/`) — both are guessable from the open-source heritage of the codebase + standard payment-gateway URL patterns.
- **External Unauth (advanced)**: holds the provider's HMAC scheme spec (Stripe + SenangPay are both publicly documented).
- **Authenticated low-priv admin**: any user with `auth:sanctum` admin token but NOT `permission:settings` — can hit the admin payment-gateway *index* route.
- **Compromised host**: not in scope here (DB dump trivially exposes everything regardless).

Attacker goals:
1. Forge a payment-confirmation webhook → free meal / unpaid order moved to PAID.
2. Replay a legitimate webhook → double-credit, NF525 chain pollution.
3. Deny service via webhook spam → drown DLQ, hide real failures.
4. Exfiltrate HMAC secrets → bootstrap forgery offline.
5. Probe for valid signatures → online HMAC oracle.

---

## 1. Inventory: every inbound webhook surface

Surfaces with inbound external POST/GET acceptance, in priority order:

| # | URL | Handler | Auth | CSRF | Throttle | Idempotency | V1 active? |
|---|---|---|---|---|---|---|---|
| 1 | `POST /payment/stripe-webhook/` | `Stripe::handleWebhook` | Stripe-Signature HMAC | excluded | NONE | DB UNIQUE+firstOrCreate | latent (stripe gate not cleared) |
| 2 | `GET\|POST /payment/senangpay-webhook/` | `Senangpay::webhook` | HMAC-SHA-256 over canonical | excluded | NONE | DB UNIQUE+firstOrCreate | latent (web_payment_v1=false) |
| 3 | `GET\|POST /payment/{slug}/{order}/{success,fail,cancel}` | `PaymentController::*` | none | depends on slug | none | none (token-DB lookup) | **`guardWebPaymentV1` → abort 404 in V1** |
| 4 | FCM inbound | n/a | n/a | n/a | n/a | n/a | **NO inbound surface** — PushNotificationController is outbound admin-initiated only (push send, list, export, delete). No `firebase/incoming`, no FCM acknowledgement webhook. Out of scope. |

Anchors:
- Routes: `app/Http/PaymentGateways/Routes/stripe.php`, `app/Http/PaymentGateways/Routes/senangpay.php` — auto-loaded via `RouteServiceProvider::mapWebRoutes` lines 176-194 under the `web` middleware group.
- CSRF exclusion: `app/Http/Middleware/VerifyCsrfToken.php:25-26`.
- Idempotency table: `database/migrations/2026_05_09_120000_create_webhook_events_table.php` — UNIQUE `(provider, webhook_id)` named `uk_webhook_provider_id` at line 83.

---

## 2. Correcting a stale framing claim from the prompt

The prompt says **"Stripe webhook idempotency parity with SenangPay STILL PENDING"** (echoing BRAIN §1 historical V1.x backlog). Code reading contradicts this:

- `app/Http/PaymentGateways/Gateways/Stripe.php:197-328` (`handleWebhook`) is fully implemented under the Sprint 3A 2026-05-16 banner with `\Stripe\Webhook::constructEvent` verification + `WebhookEvent::firstOrCreate` + DB UNIQUE floor.
- `app/Http/PaymentGateways/Gateways/Senangpay.php:50-186` (`webhook`) is also fully implemented in the same sprint with HMAC-SHA-256 + `firstOrCreate` + DB UNIQUE.
- Both share `WebhookEvent` model + `handleFromStoredEvent` DLQ replay (Sprint H3 P1-Z8-02 2026-05-17).

**The historical "Stripe parity gap" finding is closed.** This is a positive — flag it explicitly so subsequent rounds don't re-open a phantom P0.

---

## 3. Findings (severity-graded, hostile-mindset)

### F-SEC-W7-01 — P1 — HMAC merchant-secret disclosure to read-only admins ("Secret-Exfil-via-Resource")

**Attack**: any authenticated admin user — even one without `permission:settings` — can call `GET /api/admin/payment-gateway/` and receive the plaintext SenangPay merchant secret (and Stripe API secret key) in the response body.

**Chain**:
- `routes/api.php:269` admin prefix applies `auth:sanctum`+`apiKey`+`throttle:admin-mutation`, no role/permission gate at the prefix level.
- `routes/api.php:414-417`: GET → `PaymentGatewayController::index`, no per-route `permission:` middleware (only `update` at line 416 is gated by `permission:settings` via `PaymentGatewayController:21` `$this->middleware(['permission:settings'])->only('update')`).
- `app/Http/Resources/PaymentGatewayResource.php:25` and `app/Http/Resources/GatewayOptionsResource.php:22` return `value` raw.
- `gateway_options.value` is a plain `text` column (`database/migrations/2022_11_17_115341_create_gateway_options_table.php:21`), no `encrypted` cast on `GatewayOption` model (`app/Models/GatewayOption.php:11-20`).

**Exploitation**: with `senangpay_secret_key` in hand, the attacker controls the SenangPay HMAC. They can forge `POST /payment/senangpay-webhook/` with any `status_id`, `order_id`, `transaction_id`, `msg` and a valid `hash`. Signature verification at `Senangpay.php:110` passes. `WebhookEvent` row is written. `CapturePaymentNotification` row is inserted at `Senangpay.php:160-164` for `status_id="1"`.

**Why this is P1 not P0 today**: the consumer-side route `/payment/{slug}/{order}/success` (`routes/web.php:41`, `PaymentController::success`) is hard-blocked by `guardWebPaymentV1` (`PaymentController.php:88-89, 131-136`) which `abort(404)` because `config('payment.web_payment_v1.enabled') = false` per `config/payment.php:14-19`. Forged HMAC reaches the webhook, leaves a row in `capture_payment_notifications`, but no caller path turns that into an `Order` PAID transition.

**Why this becomes P0 the day web_payment_v1 flips on**: the chain becomes:
1. Forged webhook writes `capture_payment_notifications(order_id, token)`.
2. Attacker visits `/payment/senangpay/{order}/success?token=<chosen>` (per `Senangpay::success`-class success controller; the SenangPay gateway success handler reads from `capture_payment_notifications`, mirroring `Stripe::success` at lines 117-129).
3. `PaymentService::payment` flips Order to PAID. Free meal.

**Mitigation candidates** (out of scope to apply, named for next round):
- Gate `PaymentGatewayController::index` with `permission:settings` (parity with `update`).
- Strip secret-bearing options (`*_secret*`, `*_key*`, `*_secret_key*`) from `GatewayOptionsResource::toArray` by allowlist.
- Encrypt `gateway_options.value` at rest with Laravel's `encrypted` cast.

### F-SEC-W7-02 — P1 — No throttle on webhook endpoints ("Spray-and-Saturate" DoS)

**Attack**: attacker floods `POST /payment/stripe-webhook/` and `/payment/senangpay-webhook/` with garbage payloads. Each request triggers signature verification (cheap-ish), then logs to `Log::channel('fiscal')` even on failure paths.

**Chain**:
- `app/Http/PaymentGateways/Routes/stripe.php:22-24` and `app/Http/PaymentGateways/Routes/senangpay.php:17-19` carry only `['installed']` middleware. No `throttle:N,1`.
- The fiscal log channel is a critical NF525 audit lane (`CLAUDE.md §8`). Spamming it with junk drowns real signals.
- Signature verification cost is non-trivial under sustained Mbps (Stripe constructEvent parses + HMAC; SenangPay does hash_hmac + DB read of `PaymentGateway::with('gatewayOptions')`).

**Exploitation**: a $5 VPS can sustain ~5k rps. With each request doing a DB read for gateway options (Senangpay line 83), the DB connection pool gets pinned. Real webhooks back up, providers retry 5xx → bigger surge → cascade.

**Why this is P1 not P0**: real impact is "noisy log + brief degradation," not data loss. Idempotency and signature checks remain correct.

**Mitigation candidate**: `->middleware('throttle:300,1')` per webhook route (Stripe + SenangPay both retry on 429 with backoff — safe to apply).

### F-SEC-W7-03 — P1 — DLQ silent-payment-drop ("DLQ-Mark-Without-Replay")

**Attack**: a webhook arrives. Live handler enters `WebhookEvent::firstOrCreate` (row written, status=pending). The DB transaction in `Senangpay.php:149-168` (or `Stripe.php:271-310`) then fails — DB deadlock, OOM mid-tx, or InnoDB lock-wait timeout. `markFailed` runs. Row is now `status=failed`, the `CapturePaymentNotification` was NOT written.

Some hours later, `OutboxWebhookRetryFailedCommand` flips the row to `pending` and `ProcessWebhookEventJob` dispatches to `handleFromStoredEvent`. That method at `Stripe.php:353-381` and `Senangpay.php:224-251` **simply calls `$event->markProcessed($event->order_id)` without re-running the business logic**. The V1.0.2 TODO comments at `Stripe.php:347-351` and `Senangpay.php:219-223` explicitly admit this.

**Result**: the row is now `status=processed`, attempts incremented, but the `CapturePaymentNotification` row was never created. A real customer paid; the consumer-side `Order` capture never fires.

**Severity**: this is NOT an attacker-exploit per se, but a security-adjacent **silent payment loss / NF525 chain integrity risk**. Attackers can amplify it by deliberately causing brief DB pressure (see F-SEC-W7-02) to push more rows into the DLQ → more silent drops.

**Why P1 not P0**: today both gateways are latent (V1 web_payment_v1=false, Stripe activation_gate_cleared=false). The DLQ replay is a future-V1.x silent footgun, not active-V1 exploit.

**Mitigation candidate**: refactor `handleWebhook` to extract `processStripeEvent(StripeEvent $e, WebhookEvent $event)` (and SenangPay equivalent) so `handleFromStoredEvent` calls the same internal processor. Re-emit `CapturePaymentNotification` upserts on replay. The V1.0.2 TODO already calls this out.

### F-SEC-W7-04 — P1 — Failed-signature attempt NOT recorded ("Silent Probe Oracle")

**Attack**: attacker fuzzes the SenangPay HMAC against `/payment/senangpay-webhook/` looking for accidental hash collisions / weak secrets. Stripe is more robust (random secret), but SenangPay merchant secret is admin-provisioned and could be weak in dev/staging configurations bled to prod.

**Chain**: invalid-signature responses at `Senangpay.php:110-121` and `Stripe.php:215-224` write a `Log::channel('fiscal')->warning` line but do **NOT** persist a `WebhookEvent` row.

**Consequence**:
- No DB record of attack traffic. Forensic reconstruction relies on log retention (volatile if log channel rotates).
- No rate-limit counter built on top of "failed-signature attempts per IP" can exist (because nothing in DB to count).
- Combined with F-SEC-W7-02 (no throttle), an attacker can probe at high speed against a "silent" attack surface.

**Why P1 not P0**: signature is HMAC-SHA-256 — practically unforgeable without the secret. The risk is operational forensics + ability to layer behavioural defenses.

**Mitigation candidate**: write a `WebhookEvent` row with `status=failed`, `signature=<truncated supplied>`, `error_message='invalid_signature'` BEFORE returning 400. Future cron could detect IPs with > N failed-sig rows/min and IP-block.

### F-SEC-W7-05 — P2 — SenangPay accepts GET ("URL-Embedded-HMAC Leak")

**Attack**: `app/Http/PaymentGateways/Routes/senangpay.php:18` registers `Route::match(['get', 'post'], '/senangpay-webhook/', ...)`. An attacker who tricks an admin (or the provider's misconfigured retry queue) into hitting the URL as GET — with `?status_id=1&order_id=X&transaction_id=Y&msg=Z&hash=H` — will succeed.

**Chain**: GET params logged in:
- Web server access logs (default Nginx/Apache logs full URL incl. query).
- Reverse-proxy logs (Cloudflare, ELB).
- Browser history (if any admin views the URL).
- Referer headers if an attacker can trigger a redirect chain.

The `hash` value is the HMAC output, not the secret — but combined with the canonical input it's a known-plaintext sample of the HMAC. Not directly secret-recovery, but reduces the attacker's blind-corner.

**Why P2 not P1**: provider only POSTs in production; GET is legacy compat. Real exploit chain is contrived.

**Mitigation candidate**: change to `Route::post(...)` only. If GET is needed for a historical reason (it isn't per SenangPay v2 spec), gate it behind a feature flag.

### F-SEC-W7-06 — P2 — DLQ-as-attack-vector amplification ("Junk-Inflation Pretext")

**Attack**: attacker triggers F-SEC-W7-03 conditions deliberately (DoS in F-SEC-W7-02 induces DB pressure → mid-tx failures → DLQ inflation). DLQ telemetry (the still-pending V1.0.2 telemetry referenced at `Stripe.php:350`) → if any alerting threshold is wired to DLQ size, the attacker can mask a real payment failure by burying it under junk.

**Why P2 not P1**: DLQ is queryable by `webhook_event_id` so forensic forensic reconstruction remains possible. But operator fatigue is real.

**Mitigation candidate**: per-provider per-cause DLQ partition; alerting on novel `error_message` patterns, not raw row count.

### F-SEC-W7-07 — P3 — 500 on missing-secret leaks deployment state ("Missing-Secret Probe")

**Attack**: unauth attacker hits `POST /payment/stripe-webhook/` with empty body. If `STRIPE_WEBHOOK_SECRET` env is unset, response is `500 {"error":"misconfigured"}` per `Stripe.php:200-208`. Same for SenangPay when `gateway_options.senangpay_secret_key` is unset (`Senangpay.php:96-104`).

**Information leaked**: which payment provider is configured in this deployment; whether a deployment is mid-migration or partial-config. Useful for triaging which environment is "easier" to attack.

**Why P3**: low-value intelligence; expected behaviour during legitimate misconfig diagnostics.

**Mitigation candidate**: return 400 `invalid_request` uniformly for any path that fails pre-signature-verification, and emit `fiscal.warning` server-side only.

### F-SEC-W7-08 — P3 — Cross-provider injection benignly fails ("Cross-Provider Confusion")

**Test**: POST a Stripe-style JSON payload to `/payment/senangpay-webhook/`.

**Result**: `Senangpay.php:65-79` rejects with 400 `invalid_payload` because `transaction_id`, `order_id`, `hash` form-encoded fields are absent. Inverse: POST SenangPay form-urlencoded to Stripe → `\Stripe\Webhook::constructEvent` parses raw body as JSON, signature header absent, 400 `invalid_signature`.

**Conclusion**: cross-provider injection is blocked by structural rejection. No further action.

### F-SEC-W7-09 — POSITIVE — Replay-race correctness

**Test**: two concurrent identical webhook POSTs (same provider, same webhook_id).

**Trace**:
- Both hit `firstOrCreate` (`Stripe.php:247-259`, `Senangpay.php:125-137`).
- DB UNIQUE `uk_webhook_provider_id` ensures only ONE INSERT wins; second one raises a unique-violation that Eloquent catches inside `firstOrCreate` and returns the existing row.
- `$event->wasRecentlyCreated` is FALSE for the loser; 200 `duplicate_ignored` is returned.

**Conclusion**: race-correct. The UNIQUE constraint is the atomicity floor; firstOrCreate is the app-layer fast path. Both gateways correctly check `wasRecentlyCreated` before processing.

### F-SEC-W7-10 — POSITIVE — Stripe webhook secret NOT exposed via admin API

Stripe webhook secret lives in `.env` → `config('services.stripe.webhook_secret')` (`config/services.php:68`). It is NOT stored in `gateway_options`. So the F-SEC-W7-01 disclosure attack on `gateway_options` exposes the Stripe **API secret key** (chargeable, but webhook-irrelevant), not the **webhook secret**. Stripe webhook forgery requires server-side env-var exfil, which is a separate (higher) bar.

SenangPay does NOT have this separation — the merchant secret is the HMAC secret AND the API auth. Hence the asymmetry making F-SEC-W7-01 P1 (SenangPay-effective) rather than full-fledged P0 (Stripe-effective).

---

## 4. Severity summary

| ID | Severity | Active-V1 exploit? | Latent-future exploit? |
|---|---|---|---|
| F-SEC-W7-01 (Secret-exfil) | **P1** | Disclosure yes; forgery no (success route 404) | YES — once web_payment_v1=true |
| F-SEC-W7-02 (No throttle DoS) | **P1** | YES (degradation) | YES |
| F-SEC-W7-03 (DLQ silent drop) | **P1** | latent (no V1 web payments) | YES |
| F-SEC-W7-04 (Silent probe oracle) | **P1** | partial | YES |
| F-SEC-W7-05 (GET on senangpay) | P2 | low | low |
| F-SEC-W7-06 (DLQ junk inflation) | P2 | latent | yes |
| F-SEC-W7-07 (500 info leak) | P3 | yes | yes |
| F-SEC-W7-08 (Cross-provider) | P3 (closed by code) | n/a | n/a |
| F-SEC-W7-09 (Replay race) | POSITIVE | n/a | n/a |
| F-SEC-W7-10 (Stripe secret isolation) | POSITIVE | n/a | n/a |

**No P0 findings** in active V1 because `web_payment_v1.enabled=false` blocks the consumer-side forgery chain. The day that flag flips, F-SEC-W7-01 becomes P0 — gate that flip explicitly behind a security-review LOCK.

**Stripe-parity-with-SenangPay claim from BRAIN §1**: closed. Both providers have HMAC + UNIQUE + firstOrCreate + DLQ replay scaffold. The remaining gaps are the four P1s above, NOT the "missing Stripe webhook" of the historical claim.

---

## 5. Recommended next-round actions (not in this audit's scope)

1. **Gate F-SEC-W7-01 first**: it's the only finding with a direct attacker-controlled exfil path. `permission:settings` on `PaymentGatewayController::index` + secret-allowlist redaction in `GatewayOptionsResource` are both 1-line / 5-line changes, no migration needed.
2. **Throttle the webhook routes** (F-SEC-W7-02): 1-line route change. Validate provider retry tolerance to 429.
3. **DLQ replay completeness** (F-SEC-W7-03): refactor `handleWebhook` into `parse + processStored*Event` — V1.0.2 TODO, deserves promotion to V1.0.1 hardening if web payment activation is on the roadmap.
4. **Add failed-signature audit rows** (F-SEC-W7-04): minor migration not required; just persist the WebhookEvent before the 400.
5. **LOCK on `web_payment_v1.enabled` flip**: any PR toggling this flag MUST cite F-SEC-W7-01 + F-SEC-W7-03 closure as exit criteria.

---

## 6. Anchors used (all read-only)

- `app/Models/WebhookEvent.php`
- `app/Jobs/ProcessWebhookEventJob.php`
- `app/Http/PaymentGateways/Gateways/Stripe.php`
- `app/Http/PaymentGateways/Gateways/Senangpay.php`
- `app/Http/PaymentGateways/Routes/stripe.php`
- `app/Http/PaymentGateways/Routes/senangpay.php`
- `app/Http/PaymentGateways/Requests/Stripe.php`
- `app/Http/PaymentGateways/Requests/Senangpay.php`
- `app/Http/Middleware/VerifyCsrfToken.php`
- `app/Http/Middleware/Installed.php`
- `app/Http/Controllers/Frontend/PaymentController.php`
- `app/Http/Controllers/Admin/PaymentGatewayController.php`
- `app/Http/Controllers/Admin/PushNotificationController.php` (confirmed no inbound FCM)
- `app/Http/Resources/PaymentGatewayResource.php`
- `app/Http/Resources/GatewayOptionsResource.php`
- `app/Models/GatewayOption.php`
- `app/Providers/RouteServiceProvider.php` (mapWebRoutes auto-loader)
- `app/Console/Kernel.php` (webhook-prune + retry-failed schedule)
- `config/payment.php` (web_payment_v1 + stripe activation guard)
- `config/services.php` (stripe.webhook_secret env-driven)
- `routes/api.php` (admin prefix + payment-gateway routes)
- `routes/web.php` (payment.success/fail/cancel)
- `database/migrations/2026_05_09_120000_create_webhook_events_table.php`
- `database/migrations/2022_11_17_115341_create_gateway_options_table.php`

No code modified. No tests run. Read-only.
