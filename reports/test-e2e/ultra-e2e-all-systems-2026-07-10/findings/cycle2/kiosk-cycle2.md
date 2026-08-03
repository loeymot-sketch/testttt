# KIOSK BORNE — Cycle 2 convergence check (2026-07-10)

## Fix 1 — quote throttle bucket split: CONFIRMED HEALED
- routes/api.php:1356 → POST /frontend/order/quote uses middleware `throttle:kiosk-quote`
- routes/api.php:1357 → POST /frontend/order uses `throttle:kiosk-orders` + idempotency
- RouteServiceProvider.php:60 kiosk-orders by() key prefix `kiosk:`  (cap config kiosk.order_rate_limit=5)
- RouteServiceProvider.php:78 kiosk-quote  by() key prefix `kioskq:` (cap config kiosk.quote_rate_limit=120)
- Distinct prefixes => independent buckets. No bootstrap/cache/routes-*.php => live, not stale.

## Fix 2 — admin session cancel of kiosk order (free-text reason): CONFIRMED HEALED
- OrderStatusRequest.php:80-102 actorIsKioskMachine() now requires
  currentAccessToken() instanceof PersonalAccessToken AND abilities contain 'kiosk:order' AND NOT '*'.
- A session admin carries TransientToken (not PersonalAccessToken) => actorIsKioskMachine()=false => free-text reason accepted.
- Test PASS: tests/Feature/Order/CancelReasonEnforceTest "admin via sanctum session cancels with free text reason".
- Kiosk whitelist still enforced (kiosk cancel unknown reason -> 422).

## Test gate
- php artisan test tests/Feature/Kiosk => 34 passed
- CancelReasonEnforceTest + F004 sentinel => 7 passed
- KioskSecurity 6, QuoteIntegrity 2, QuoteTokenRequired 4, ConcurrentOrder 3, PaymentReconcile 9 => all green

## NF525 / robustness spot-checks (all intact)
- composition_snapshot immutable: OrderItem.php:51-54 model boot guard + DB trigger 2026_05_24_040211
- queue_number: Cache::lock(30)+block(15) + 5x retry on unique-violation (FrontendOrderService.php:1059-1099)
- pricing recomputed server-side; cross-item variation/extra injection guarded (FrontendOrderService.php:395-425)

## Noted non-finding (omitted per "unsure->omit", pre-existing, not NEW P0/P1)
- Kiosk store path (FrontendOrderService) does NOT enforce composer min_select/max_select at creation;
  IDs validated + price recomputed from DB, so no fiscal loss. Only reproducible via tampered client;
  kiosk = trusted physical terminal in V1 LOCAL. Not a new regression, below P1 bar.

## VERDICT: KIOSK CONVERGED — both P0/P1 healed & verified GONE; no NEW P0/P1 found.
