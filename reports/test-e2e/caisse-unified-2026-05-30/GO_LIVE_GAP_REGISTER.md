# Le Cayenne — Go-Live Gap Register (Supervisor Verdict)

**Date**: 2026-05-30 · **Scope**: what really stands between the current code/DB and a real cash-and-card shift. **EXCLUDED by owner**: cloud/VPS, domain/DNS/TLS, CB payment terminals + acquirer contract.

**Method**: 6-dimension evidence sweep + firsthand verification (tinker / schedule:list / .env / config / grep). Every item below is grounded in a confirmed fact, not speculation.

---

## 🔴 REAL BLOCKERS — must close before charging the first real customer

### B1 — VAT regime is 0% across the entire menu (legal only if franchise-en-base)
**Evidence**: `Item` tinker — **42 of 45 items have `tax_id = NULL`, the other 3 point to tax_id=1 = "No-VAT 0%"**. `Tax` table has VAT 5% / VAT 10% rows defined but **assigned to zero items**. So every order computes and prints **0,00 € de TVA**.
**Why it matters**: A French restaurant that is VAT-registered must charge TVA (typically **10% à emporter / sur place**, 20% on alcohol). Running at 0% is legal **only** if Le Cayenne operates under the *franchise en base de TVA* (micro turnover, non-assujetti). If it is VAT-registered, **every receipt is fiscally wrong** and the signed Z reports understate collected tax — a real NF525/tax-administration exposure.
**Owner action**: Confirm the legal VAT regime. If VAT-registered → assign the correct Tax (10% food, 20% alcohol) to all 45 items, then re-verify `ZReportService total_by_tax_rate` decomposes correctly. If franchise-en-base → no change, but document the regime. **Only the owner can answer this — it is a legal-status fact, not a code fact.**

### B2 — Clean fiscal-sequence start (production must not begin on top of test data)
**Evidence**: tinker — **353 orders in DB, 168 already carry a `fiscal_sequence_no` (MAX=168), 29 synthetic/soak orders, 60 PENDING_COUNTER**. NF525 requires a gap-free, 6-year-retained chain *of real sales*.
**Why it matters**: If the box goes live as-is, the production fiscal chain continues at 169 with hundreds of test/soak orders permanently interleaved in the archived, signed record.
**Owner action**: On the production box, **`php artisan migrate:fresh --seed` (or a clean fiscal reset) BEFORE the first real sale**, then confirm the seed restores the canonical **45-item Le Cayenne menu** (verified present). After reset: `fiscal:verify-chain` should show an empty/fresh chain.

### B3 — Production .env flips (the boot guard will refuse to serve otherwise)
**Evidence**: current `.env` — `APP_ENV=local`, `APP_URL=http://localhost:8000`, **`POS_SIMULATION_HARDWARE=true`**. `AppServiceProvider` boot guard (CLAUDE.md §8) REFUSES TO BOOT in production if these are wrong. `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` ✓, `CACHE_DRIVER=redis` ✓, `QUEUE_CONNECTION=redis` ✓ (already correct).
**Why it matters**: `POS_SIMULATION_HARDWARE=true` is the NF525-critical one — with it ON, cash sales **bypass the cash-drawer-open requirement**, so there is no enforced cash trail. It is acceptable in dev, **forbidden in prod**.
**Owner action**: Flip `APP_ENV=production`, `APP_URL=<real box address>`, **`POS_SIMULATION_HARDWARE=false`**. (Once false, every cash sale requires an open `CashDrawerSession` — see O3.)

---

## 🟠 STRONG RECOMMEND — close to avoid customer disputes / operational surprise

### S1 — Offers feature shows a discount but PricingService never applies it (CAT-01)
**Evidence**: display side computes `offer.convert_price = price - price/100*amount` (`ItemResource.php:111-118`, `pos-wizard.js:1051` — frozen, renders it) but **`PricingService::calculateOrder` (PricingService.php:134) uses raw `items.price` with zero offer lookup**. DB: `Offer::count()=0` → **dormant today**, but the admin "Offres" module is live.
**Why it matters**: the first promo the owner creates makes the customer see a discounted price and get **charged full price** — a consumer-law/dispute risk, and the receipt won't match the display.
**Owner action**: before using "Offres" in production, either (A) wire `PricingService` to apply active offers server-side (preferred, NF525 SSOT), or (B) hide the Offers module for V1. **Do not create an offer until one of these is done.** (Backend fix — does not touch frozen pos-wizard.js.)

### S2 — Receipt printer not configured (ticket-on-request)
**Evidence**: `EscPosPrinterService` + `PosReceiptPrintController` exist, but **`Printer::count() = 0`**. EOD PDF clôture exists (EodPdfRecapSentinelTest).
**Why it matters**: French law (2023 anti-gaspillage) = ticket **on request**, not systematic — so this is not a hard blocker, but a customer asking for a paper ticket can't be served one until a thermal printer is configured.
**Owner action**: configure one thermal printer (ESC/POS) in admin, or accept digital/on-request-only and document it.

### S3 — delta-B activation gated behind cross-Z-window policy
**Evidence**: the unified-caisse delta-B (walk-in → counter collection) is built but **default OFF**. The pre-existing `changePaymentStatus` PENDING_COUNTER→PAID escape-Z is owner-gated **detect-only** (`fiscal:verify-z-membership` cron, confirmed scheduled 06:05).
**Owner action**: if activating delta-B (`POS_WALKIN_ROUTE_TO_COUNTER=true`), first decide the cross-window settlement policy (reject-late vs current-window counter-entry). Until then, leave OFF — zero live exposure.

---

## 🟢 OPERATIONAL — confirm in place on the box (mostly done)

- **O1 — OS-level cron**: all app crons are wired (`schedule:list`: `fiscal:close-all-active-branches` 23:59, `fiscal:open-all-active-branches` 00:01, `fiscal:verify-z-membership` 06:05, `backup-daily` 03:00, outbox rescue/monitor/retry, stock:scan-rupture). **They only fire if `* * * * * php artisan schedule:run` is in the box's system crontab** — confirm that one OS entry exists.
- **O2 — Queue worker supervised**: `QUEUE_CONNECTION=redis`; a `queue:work` must run as a supervised service (systemd/supervisor) so KDS/OSS live-sync doesn't silently degrade to 60s poll. (Poll reads `orders` directly → no data loss, but confirm the worker is auto-restarted.)
- **O3 — Cash-drawer discipline**: enforcement exists (`assertCashDrawerSessionOpenIfCashInvolved`) and **activates once `POS_SIMULATION_HARDWARE=false`** — the cashier must **open a drawer session at shift start**, reconcile at close. Train the operator.

---

## ✅ ALREADY GOOD (verified, not gaps)
- Seeded staff passwords are **NOT** the test `123456` (already changed). 16 users.
- Menu SSOT = **45 active items**, canonical (no fictional products).
- **NF525 chain: CHAIN OK** (SWEEP COMPLETE, branch=1).
- Sanctum token TTL 480min/8h **with proactive 2h refresh** (no mid-shift logout); V1.0.1 roadmap tightens to 1h.
- Frozen zones intact (pos-wizard.js / PaymentComponent / OrderStateMachine untouched).
- Caisse-unifiée (history + encaissement) converged, gates green.

---

## Bottom line
**Besides cloud, domain, and terminals, the honest go-live blockers are 3:** (B1) confirm/fix the **VAT regime**, (B2) **clean fiscal-sequence reset** on the box, (B3) **3 production .env flips**. Plus 3 strong-recommends (offers, receipt printer, delta-B gate) and 3 operational confirms (OS cron, supervised worker, drawer discipline). B1 and B2 are the ones that make a real customer's receipt legally correct — everything else is operational.
