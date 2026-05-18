# LOYALTY Cross-Surface Integration Audit — STATUS

**Date**: 2026-05-18
**Master**: LCS (Wave C — parallel with 2 other masters)
**Scope**: POS (EARN) + Kiosk (EARN + REDEEM) + Mobile (full UX + Wallet) + Web (mirror)
**Round**: 1
**Specialists**: Architect (LCS-1) + Security (LCS-2) + RED (LCS-3) — all delivered

---

## Executive Summary

Loyalty has a **data SSOT (sound)** but **no service SSOT**: earn lives in `AwardLoyaltyPointsOnDelivery` listener, redeem/check/register/balance/scan in `LoyaltyController`, refund in `LoyaltyService` (80 lines), preview in `DiscountCalculator`. Cross-surface balance consistency works at the column level (`users.loyalty_points`) and ledger level (`loyalty_transactions`). 22 mobile E2E specs PASS. Three structural defects break the cross-surface coherence promise:

1. **QR signature is unverified server-side** — `FK:<loyalty_code>` is a permanent plaintext credential. Mobile 5-min rotation is UX-only. Screenshot/clipboard/OCR of a single victim QR enables indefinite cross-surface redemption fraud.
2. **Web "mirror" does not exist** — `/Users/1millnonstop/Downloads/web/loyalty-v2.jsx` (141 lines) is profile chrome only (ProfileEditor, NotificationSettings, SavedCards). Zero QR, zero balance display, zero redeem UI, zero history. Task framing "web mirror of mobile" is unmet.
3. **`/loyalty/redeem` lacks route-level idempotency** — mobile sends `Idempotency-Key` header (per B-02 spec) but route middleware is `['auth:sanctum']` only. Network retry produces duplicate ledger rows + double-debit.

Wallet (Apple/Google) is deferred per `WALLET_PLAN.md` — only V0 placeholder modal exists. **If** owner intends Wallet for V1, current spec (`barcode.message = "FK:<loyalty_code>"`) inherits the QR signature flaw and makes it lifetime-permanent.

---

## 4-List

### P0 (block V1 ship until owner gate)
- **LCS-S-001 / LCS-R-001** — **QR scan accepts unsigned plaintext FK:`<code>`**. `LoyaltyController::scan` (line 609-635) strips `FK:` prefix and matches `users.loyalty_code` directly — no signature, no nonce, no TTL verification. Mobile `generateSignedQR` is a 100% client-side mock. Concrete exploit chain in RED LCS-R-001 (screenshot -> kiosk replay -> drain). The 5-min mobile rotation is cosmetic. **Fix**: implement `POST /loyalty/qr/sign` returning `{payload, signature, expires_at, nonce}` (RS256 or HMAC via `config('app.key')`); modify `scan()` to verify signature + reject expired/replayed payloads; track consumed nonces.

### P1 (must address before V1.0 ship or document deferral with mitigation)
- **LCS-S-002 / LCS-R-002** — **`/loyalty/redeem` route lacks `IdempotencyKeyMiddleware`**. Mobile sends `Idempotency-Key` header (per `mobile/data/loyalty.js:24` B-02 spec) but route at `routes/api.php:1278` has only `['auth:sanctum']`. Fix is trivial: add `'idempotency'` middleware to `/redeem` and `/add-points`. Existing middleware (per CLAUDE.md §9) handles dedup via `(branch_id, user_id, hash(key))`.
- **LCS-A-002** — **Web loyalty wallet surface absent**. `/Users/1millnonstop/Downloads/web/loyalty-v2.jsx` exports `LoyaltyProfileTab` only. No `LoyaltyDashboardTab` with QR + balance + history + redeem. **OWNER GATE**: confirm V1 scope (port mobile loyalty to web) OR explicit V1.x deferral (then remove "mirror" framing from task descriptions).
- **LCS-A-001** — **No service SSOT**. Earn (listener), redeem (controller), refund (service), preview (pricing). Future loyalty feature additions (tiers, bonuses) will touch 4+ files. V1.0.2 recommend `BackendLoyaltyService` consolidation; keep V1 as-is.

### P2 (V1.0.2 backlog)
- **LCS-A-003** — No reconciliation job comparing `users.loyalty_points` vs `SUM(loyalty_transactions.points)` per user. Silent drift undetected.
- **LCS-S-003** — `loyalty_transactions` ledger has no `BEFORE DELETE` trigger (unlike audit_logs/z_reports). Not NF525-required but useful for fraud investigation trust.
- **LCS-S-005** — `/loyalty/scan` throttle is 20/min per token = 28800/day per stolen kiosk token = mass enumeration window. Recommend adaptive throttle on repeated `customer_not_found` + IP-binding on kiosk Sanctum tokens.
- **LCS-R-004** — **Kiosk redeem-then-abandon leaves orphaned ledger debit**. `LoyaltyController::redeem` writes `order_id=NULL` (line 312). `LoyaltyService::refundPoints` requires `order_id`. Customer abandons wizard after redeem -> 200 points gone, no order, no refund path. Fix: defer redeem decrement to order-commit time (preview only at wizard step), OR add `/loyalty/refund-pending` endpoint hooked to wizard timeout.
- **LCS-A-005** — POS `source_surface` fallback is `'web'` not `'pos'` (`AwardLoyaltyPointsOnDelivery:113`). Verify `OrderService::create` stamps `'pos'` explicitly; if not, analytics mis-attribution.
- **LCS-S-004** — localStorage tamper on mobile balance display — already covered by adv-A3 test spec, document as known UX-level limitation (not server-exploitable).

### P3 (informational / future)
- **LCS-A-004 / LCS-S-006 / LCS-R-005** — **Wallet integration NOT IMPLEMENTED**. Only `mobile/WALLET_PLAN.md` spec + V0 placeholder modal. No `app/Services/Wallet/`, no `WalletController`, no `wallet_apple_registrations`/`wallet_google_registrations` tables. **OWNER GATE**: confirm V1 ships without Wallet (acceptable — plan frames as Phase 7+). If pursuing V1 Wallet: barcode message MUST be server-issued JWT (not `FK:<code>`), `firebase/php-jwt:^7.0` RS256, key rotation policy documented. The current spec inherits the LCS-S-001 P0 flaw permanently into Apple/Google Wallet.

---

## Cross-Surface Invariants Verdict

| Invariant | Status |
|---|---|
| I1 same code shows same balance everywhere | PASS (single column source) |
| I2 redeem decrements visible cross-surface | PASS (atomic column update) |
| I3 earn-post-order visible in history | PASS (ledger row in same DB transaction) |
| I4 refund on cancel reverses | PASS (`LoyaltyService::refundPoints` called from POS + kiosk + web cancel paths) |
| I5 web mirrors mobile UI | **FAIL** (web has no loyalty wallet surface) |
| I6 Wallet pass balance synced | **N/A** (Wallet not implemented) |
| I7 QR payload unique per session | **FAIL** (static `FK:<code>`, rotation cosmetic) |

---

## Test Coverage Gaps (require new tests on fix)

1. `tests/Feature/LoyaltyQrReplayRejectedTest.php` — assert stale signed-QR rejected after expires_at
2. `tests/Feature/LoyaltyRedeemIdempotencyTest.php` — assert 2x POST with same key produces 1 ledger row
3. `tests/Feature/KioskLoyaltyAbandonRefundTest.php` — assert wizard abandon refunds pre-order redeem
4. `tests/Feature/LoyaltyCrossSurfaceChainTest.php` — end-to-end chain (POS earn -> Mobile balance -> Kiosk redeem -> Web history) as single integration test

---

## Frozen-Zone Status

- `KioskWizardComponent.vue` (frozen kiosk wizard frame) — read-only respected; loyalty step is a separate Vue component (`KioskLoyaltyComponent.vue`, NOT frozen, auditable per 2026-05-07 feedback)
- `pos-wizard.js` (frozen POS wizard) — NOT touched by any loyalty change
- `FiscalSequenceService.php` / `ZReportService.php` / `AuditLogService.php` (NF525 frozen) — NOT touched
- `IdempotencyKeyMiddleware.php` (frozen) — fix LCS-S-002 ADDS middleware to route, does NOT modify the middleware itself
- `BranchScope.php` / `PricingService.php` / `OrderStateMachine.php` (frozen) — unchanged

**Net**: 0 frozen-zone touches required for P0/P1 fixes.

---

## Owner Gate Questions (block V1 ship)

1. **G-LCS-1 (P0)** — QR signature: ship V1 with signed-QR fix OR accept current risk with mitigation (display-only mode, no kiosk redeem)?
2. **G-LCS-2 (P1)** — Web wallet surface: V1 scope (port from mobile) or V1.x deferral (remove "mirror" claim)?
3. **G-LCS-3 (P3)** — Wallet Apple/Google: V1 deferral confirmed? V0 placeholder modal acceptable?
4. **G-LCS-4 (P2)** — Kiosk redeem timing: keep current pre-order debit (with orphan-refund endpoint) OR refactor to commit-time decrement (cleaner architecture)?

---

## Files (absolute paths)

- Architect report: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/loyalty-cross-surface-2026-05-18/round-1/LCS-1/architect.json`
- Security report: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/loyalty-cross-surface-2026-05-18/round-1/LCS-2/security.json`
- RED report: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/loyalty-cross-surface-2026-05-18/round-1/LCS-3/red.json`
- This file: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/loyalty-cross-surface-2026-05-18/round-1/STATUS.md`

---

## Verdict

**LOYALTY cross-surface integration: GO-CONDITIONAL pending Owner Gates G-LCS-1 (P0 QR signature) and G-LCS-2 (P1 web surface).** Data SSOT is sound; service SSOT absent but acceptable for V1. The P0 QR replay flaw is the dominant cross-surface fraud vector — every other defense is downstream of it. P1 idempotency is a trivial route-middleware fix. Mobile UX is solid (22 E2E PASS). Wallet correctly deferred per plan but MUST NOT ship with current barcode-payload spec.

**Recommended action**: implement LCS-S-001 fix (signed QR endpoint) + LCS-S-002 fix (idempotency middleware) as a single P0/P1 sprint BEFORE owner countersign on POS cashier-redeem LOCK plan (which would amplify R-001 blast radius by adding a new redeem surface).
