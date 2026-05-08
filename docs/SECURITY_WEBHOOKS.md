# Webhook Security Model

> Defence-in-depth for inbound delivery-platform webhooks.
> Track 1 (Phases 1–4). Companion to `docs/DELIVERY_PLATFORMS.md`.

The threat model assumes an attacker can:
- send arbitrary HTTP requests to our public webhook endpoint;
- replay a previously valid request (network capture, MITM);
- spam us with malformed payloads (DoS attempt);
- attempt to spoof a platform identity to inject fake orders.

Every layer below is independently sufficient to drop a malicious
request. Together they are the contract any new platform adapter
must respect.

---

## 1. HMAC signature schemes per platform

All three platforms sign their webhook bodies with HMAC-SHA256, but
the encoding differs. The `VerifyDeliverySignature` middleware
dispatches on the URL `{platform}` segment to pick the right scheme.

### Uber Eats

| Aspect | Value |
|---|---|
| Header | `X-Uber-Signature` |
| Format | `v2,t=<unix_ts>,v1=<hex_hmac>` |
| Body of HMAC | `<unix_ts>.<request_body>` |
| Key | `webhook_secret` from `delivery_platforms.credentials` |
| Window | ±300 s of server clock |

Verification:
```php
$signedPayload = $timestamp . '.' . $rawBody;
$expected = hash_hmac('sha256', $signedPayload, $webhookSecret);
hash_equals($expected, $providedHex);
```

### Deliveroo

| Aspect | Value |
|---|---|
| Header | `X-Deliveroo-Sequence-Guid` (also = nonce) |
| Format | `<hex_hmac>` (raw) |
| Body of HMAC | `<request_body>` |
| Key | `webhook_secret` |
| Window | not enforced (nonce uniqueness is the bound) |

### Delicity

| Aspect | Value |
|---|---|
| Header | `X-Delicity-Signature` |
| Format | `sha256=<hex_hmac>` |
| Body of HMAC | `<request_body>` |
| Key | `webhook_secret` |
| Window | ±600 s |

Constant-time comparison via `hash_equals()` is mandatory — a naive
`==` leaks bytes through timing.

---

## 2. Replay defence (4 layers)

### Layer 1: signature
Without the `webhook_secret`, an attacker cannot forge a signature
that matches a tampered body. Catches the trivial replay-with-mutation.

### Layer 2: timestamp window
Uber Eats and Delicity bind the signature to a server-side timestamp
within ±300/600 seconds. A captured request is unusable once that
window closes — even if the attacker has the raw signed bytes, the
re-presented signature fails the freshness check.

### Layer 3: nonce uniqueness
`delivery_webhook_events.platform + nonce` carries a UNIQUE index. The
ingest path is:
```
DeliveryWebhookEvent::create([... 'nonce' => $headerOrBodyNonce, ...]);
```
A second request with the same `(platform, nonce)` pair is rejected at
the DB layer before any business logic runs. Even if the attacker
captures + replays in under 300 s, the second hit hits the unique
constraint and we return 202 (idempotent shape, but no work performed).

### Layer 4: idempotency at the order layer
`DeliveryPlatformExternalOrder.idempotency_key = "dp:{platform}:{externalId}"`
ensures that even if a platform legitimately retries (network blip),
we never create two FrontendOrder rows for the same external order.

---

## 3. Rate limiting

The webhook route is wrapped in:
```php
->middleware(['throttle:delivery-webhooks', 'delivery.verify-signature'])
```

The `delivery-webhooks` rate limiter (`app/Providers/RouteServiceProvider.php`)
allows 1 000 req/min keyed by `platform + IP`. That is generous for a
healthy platform pushing dozens of orders per minute, but blocks a
flood of forged unsigned bodies (which fail signature anyway, but we
still want to cut the request before reaching the controller).

Failed-signature requests are logged (see Layer 1) and counted
separately so SIEM tooling can alert on a sustained spike.

---

## 4. Audit logging

Every sensitive admin action emits a hash-chained audit row via
`AuditLogService::write()`:

| Event | Action | Trigger |
|---|---|---|
| Config saved | `delivery.platform.updated` | `DeliveryPlatformController::update` |
| Toggle | `delivery.platform.toggled` | `DeliveryPlatformController::toggleEnabled` |
| Reveal | `delivery.platform.credentials_revealed` | `DeliveryPlatformController::reveal` |

The chain is HMAC-SHA256 keyed by `fiscal.audit_secret` and the
`(branch_id, prev_hash)` UNIQUE index forbids forks. A tampered or
forged row is detected by `verifyChain()` even though UPDATE/DELETE
are already blocked at the DB level (POS-9.4.3).

Audit payloads contain ONLY:
- the platform name (`uber_eats` etc.);
- a redacted diff (key names that changed, never values);
- the actor's `user_id` and request `ip`.

The encrypted `credentials` blob is never written to the audit log.

---

## 5. PII redaction

Inbound webhook bodies may contain PII (customer name, phone, address).
Three policies apply:

1. **Storage retention.** `delivery_webhook_events.body` is preserved
   for 30 days for forensic replay; a scheduled cleanup job is the
   recommended downstream addition (not yet wired — see roadmap).

2. **Log redaction.** The fiscal log channel never includes the
   `credentials` blob (audit_log payloads ARE NOT logged in full —
   only `(audit_log_id, action, hash_prefix)` reach the log channel,
   per `AuditLogService::performInsert`).

3. **Masking on read.** The admin UI's `index()` and `show()`
   endpoints mask credentials to the last 4 characters even for
   Admins. Plaintext requires the explicit, audited `reveal()` call.

---

## 6. Secret rotation procedure

Rotating a `webhook_secret` is the most common operational task —
every platform recommends doing it at least once a year, plus on any
suspected compromise.

### Step-by-step

1. **Generate a new secret on the platform dashboard.**
   The platform UI typically shows the new value once; copy it.
2. **Paste it into the FoodKing admin UI.**
   `/admin/delivery-platforms/` → row → *Edit* → *Webhook Secret* input.
3. **Click *Save*.**
   - server merges the new value over the encrypted blob;
   - sets `webhook_secret_rotated_at = now()`;
   - writes `delivery.platform.updated` to the chain with
     `payload.changed.credentials_keys = ["webhook_secret"]`.
4. **Click *Test signature*.**
   Confirms the round-trip works with the just-saved value.
5. **Activate the new secret on the platform side.**
   Most platforms have a brief overlap window where the old + new
   secrets both validate; use it to verify before deactivating the
   old one.
6. **Verify the audit chain.**
   `php artisan audit:verify --branch={id}` (existing utility) — should
   return clean for the impacted branch.

### Rollback
If the new secret breaks signature verification:
1. Re-paste the old value into the *Webhook Secret* input.
2. Click *Save*. The merge keeps everything else unchanged.
3. Re-run *Test signature*. Audit chain captures both writes.

### Emergency: compromise suspected
1. Disable the platform via the toggle on the row (`enabled=false`).
   - The middleware refuses any new webhook for this platform on
     this branch.
2. Generate + paste the new secret as above.
3. Re-enable the platform.
4. Run a `verifyChain()` sweep on every branch the suspected attacker
   could have reached — a mismatch returns the row id of the first
   forged event.
