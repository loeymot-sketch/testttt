# HEAL LCS-S-001 — Loyalty QR scan unsigned plaintext

**Date** : 2026-05-19
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Status** : GREEN — 9/9 sentinel pass + 5/5 loyalty regression pass + 254/254 full sentinel suite + migration valid + frozen-zone diff 0
**Scope** : V1 backend heal, mobile-side wire-up DEFERRED to mobile cycle (V1.0.X backlog)

---

## P0 findings closed

| ID | Source | Status |
|---|---|---|
| LCS-S-001 | round-1 STATUS.md L26 | RESOLVED — signed QR endpoint + server-side verify-and-consume |
| LCS-R-001 | round-1 RED LCS-3 | RESOLVED — replay refused via UNIQUE nonce row |

## Design (advisor-confirmed 2026-05-19)

- **Token format** : `lqr.<base64url(payload_json)>.<base64url(hmac_sha256)>`
- **Payload** : `{v:1, cust, code, nonce, iat, exp}` — JSON canonicalised, base64url unpadded (JWT RFC 7515 §3 shape, custom HMAC scheme not full JWT to minimise dependency surface)
- **HMAC** : `hash_hmac('sha256', payload_json, config('loyalty.qr.secret'), true)`
- **TTL** : 300s default (matches mobile rotation UX) + 30s leeway
- **Anti-replay** : INSERT INTO `loyalty_qr_nonces_consumed` and catch `QueryException` on UNIQUE — race-safe, no TOCTOU pre-SELECT
- **HTTP contract** : `/loyalty/scan` STAYS HTTP 200 even on failure (per §12 invariant — failed scan MUST NOT block parcours). Failure surfaces via `data.error_code` :
  - `qr_invalid_format` / `qr_invalid_signature` / `qr_expired` / `qr_replay` / `qr_unsupported_version` / `customer_not_found` / `qr_legacy_rejected`
- **Response header** : `X-Loyalty-QR-Status: signed | legacy-plaintext` — observability without breaking JSON schema
- **Backward compatibility** : legacy plaintext `FK:<code>` and bare `<code>` STILL ACCEPTED while `LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true` (default). Each call logged on `loyalty.qr.legacy_plaintext` channel so V1.0.X retirement can be evidence-driven.

## Files added / changed

### NEW
- `app/Services/Loyalty/LoyaltyQrSigner.php` — sign + verifyAndConsume, race-safe nonce, prod-safe secret guard
- `app/Services/Loyalty/LoyaltyQrInvalidException.php` — typed errorCode surface
- `config/loyalty.php` — qr.secret / qr.ttl / qr.leeway / qr.accept_legacy_plaintext + dev_sentinels (mirrors `config/fiscal.php`)
- `database/migrations/2026_05_19_100000_create_loyalty_qr_nonces_consumed_table.php` — nonce UNIQUE + customer_id FK (audit) + consumed_at + exp_at
- `tests/Feature/Sentinels/LoyaltyQrSigningSentinelTest.php` — 6 sentinel cases

### MODIFIED (all out-of-frozen-zone)
- `app/Http/Controllers/Frontend/LoyaltyController.php` :
  - `scan()` : prepend signed-token verify path, fallback to legacy plaintext (gated)
  - NEW `generateQr()` : POST /api/frontend/loyalty/qr — mints `lqr.*` token for authenticated customer
- `app/Providers/AppServiceProvider.php` : production boot guard refusing empty `LOYALTY_QR_SECRET` (mirrors POS_SIMULATION_HARDWARE pattern)
- `routes/api.php` : new route `POST /api/frontend/loyalty/qr` in the existing `auth:sanctum` group (NOT `kiosk:order` — customer mints their own QR)
- `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` : added `LOYALTY_QR_SECRET=ROTATE_*` + 3 sibling vars + rotation guidance

## Tests

### Sentinel — 9/9 PASS (post advisor-recommended follow-up: 3 HTTP tests for generation endpoint)

```
$ ./vendor/bin/phpunit tests/Feature/Sentinels/LoyaltyQrSigningSentinelTest.php
.........                                                           9 / 9 (100%)
OK (9 tests, 53 assertions)
```

| # | Case | Asserts |
|---|---|---|
| 1 | Valid signed token scan → ok=true + balance + `X-Loyalty-QR-Status: signed` + nonce row inserted | 8 |
| 2 | Expired token (iat=now-1h, TTL=300s, leeway=30s) → ok=false + error_code=qr_expired, nonce NOT consumed | 4 |
| 3 | Tampered HMAC (replaced with 32 zero bytes b64url) → ok=false + error_code=qr_invalid_signature, nonce NOT consumed | 4 |
| 4 | Replay (same valid token twice) → 1st ok, 2nd ok=false + error_code=qr_replay, exactly one row in DB | 6 |
| 5 | Legacy plaintext `FK:<code>` → ok=true + balance + `X-Loyalty-QR-Status: legacy-plaintext` | 5 |
| 6 | Production env + empty `LOYALTY_QR_SECRET` → AppServiceProvider::boot() throws RuntimeException | 2 |
| 7 | Generation endpoint without bearer → 401 (auth:sanctum gate enforced) | 1 |
| 8 | Generation → returns `lqr.*` token, round-trips successfully through /scan, scan returns `X-Loyalty-QR-Status: signed` | 11 |
| 9 | Customer without loyalty_code → endpoint mints one on demand and persists it | 4 |

### Full sentinel suite — 254/254 PASS

```
$ ./vendor/bin/phpunit tests/Feature/Sentinels/
........................................................... 254 / 254 (100%)
Tests: 254, Assertions: 705, Skipped: 2
```

Touched `tests/Feature/Sentinels/CorsAppUrlProductionGuardSentinelTest.php` to neutralize the new `loyalty.qr.secret` prod guard (mirrors the same neutralization the existing test already does for sibling guards). 2 skipped tests are pre-existing baseline.

### Scan-controller status-active alignment heal (incidental)

Pre-heal, `LoyaltyController::scan` used `(int) ($target->status ?? 1) !== 1` to gate customer activity — legacy from before the `Status::ACTIVE = 5` enum / `EnsureUserStatusActive` (H1 Z6-06) middleware. With the QR generation endpoint authenticating the customer directly, the inconsistency surfaced as a 401 on the round-trip test. Extracted to a private `isCustomerActive()` helper that accepts BOTH legacy `1` and `Status::ACTIVE` — backward-compatible with existing production rows on either value, forward-compatible with full enum migration.

### Regression — 5/5 PASS

```
$ ./vendor/bin/phpunit tests/Feature/LoyaltyApiTest.php \
    tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php \
    tests/Feature/KioskLoyaltyLedgerAtomicTest.php \
    tests/Feature/OrderCancellationLoyaltyTest.php \
    tests/Feature/KioskPhase1/LoyaltyOptInEndpointTest.php \
    tests/Feature/KioskPhase1/LoyaltyConsentTest.php
.....                                                               5 / 5 (100%)
OK (5 tests, 11 assertions)
```

(Plus sibling sanity check : `IdempotencyMiddlewareProductionGuardSentinelTest` + `KioskEventTest` = 8/8 PASS.)

### Migration validity

```
$ php artisan migrate --pretend --path=database/migrations/2026_05_19_100000_create_loyalty_qr_nonces_consumed_table.php
[GREEN — create table + UNIQUE(nonce) + 2 indexes printed]
```

## Frozen-zone diff

```
$ git diff --stat HEAD -- app/ config/ routes/ database/migrations/ tests/Feature/Sentinels/ docs/cloud/
 app/Http/Controllers/Frontend/LoyaltyController.php  | 182 +++++++++++++++---
 app/Providers/AppServiceProvider.php                 |  20 +++
 docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt           |  12 ++
 3 files changed, 191 insertions(+), 23 deletions(-)
```

None of the touched files appears in CLAUDE.md §7 FROZEN ZONES (NF525 services, BranchScope, PricingService, KioskWizardComponent, POS pos-wizard.js, etc).

## Mobile / web wire-up — DEFERRED V1.0.X

The backend signed-QR generation endpoint is live and fully tested. The mobile and customer-web clients still ship the legacy plaintext `FK:<code>` path — that's the cosmetic 5-min rotation in `mobile/components/LoyaltyQR.jsx`. Until those clients call `POST /api/frontend/loyalty/qr` and present the returned `lqr.*` token to `/loyalty/scan`, the legacy path remains the dominant code path in production.

The `LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true` flag (default) keeps production working. Once mobile cycle ships and field logs (`loyalty.qr.legacy_plaintext` channel) show zero plaintext volume, set the flag to false to lock down the surface.

**This is not "done end-to-end for users"** — it is "backend ready, V1 cross-surface fraud vector demoted from open to behind-deprecation-flag with full server-side verification path proven and ready to enforce." Concrete next-step backlog items :

- V1.0.X — mobile : wire `useLoyaltyQR.js` to call `POST /loyalty/qr` and present `data.token`
- V1.0.X — customer web : same wire-up via the customer-portal Vue component
- V1.0.X — kiosk : reads scan response `X-Loyalty-QR-Status` header to display a deprecation banner when legacy
- V1.0.X — ops : monitor `loyalty.qr.legacy_plaintext` count → 0, then flip `LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=false` in production

## Out of scope (explicitly NOT touched)

- LCS-S-002 (throttle tightening 28800/day) — separate ticket, separate commit
- Mobile QR widget — deferred to mobile cycle
- POS cashier-loyalty-redeem UI LOCK (Option B) — owner countersign still needed; this heal removes the LCS-S-001 blast-radius amplification that was blocking it
- LCS-A-004 (Apple/Google Wallet) — Phase 7+

## Sign-off

- Read-cited file:line at every step (LoyaltyController.php:609-635, LoyaltyController.php:560-573 invariant, AppServiceProvider.php:78-203 guard pattern, FiscalSealingService.php:37 HMAC pattern)
- Sentinel-first TDD : RED 1/6 first run (test bug, real signer accepted the flipped char due to padding lottery), fixed to robust 32-byte-zero substitution, GREEN 6/6
- Frozen zones : 0 diff
- NF525 chain : 0 diff (no fiscal service touched, no audit_logs / z_reports touched)
- DIRTY files : none touched (LoyaltyController.php NOT in PROJECT_BRAIN.md dirty list)

Commit message :
```
fix(loyalty-P0): sign QR scan with JWT-like HMAC + nonce + TTL (LCS-S-001)
```
