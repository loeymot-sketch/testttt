# ADVERSARY re-verification — dimension "stripe-off" (2026-07-09)

Attempted to REFUTE the prior PASS verdict. Every core claim independently reproduced. NOT refuted.

## Re-run evidence (real commands, this session)

1. **Stripe test suite** — `php artisan test --filter=Stripe` → `34 passed`; separate `EXIT=0` (redirected to /dev/null then `echo $?`). Matches claim.

2. **Live DB gateway state** (tinker):
   - `stripe_secret_len=0` (empty) → `isStripeConfigured()` false
   - `stripe_key_len=0`, `webhook_secret_len=0`
   - `gateway_status=10` (Activity::DISABLE)
   - `Stripe::status()` → `false`

3. **Live webhook curl** — `POST http://127.0.0.1:8000/payment/stripe-webhook`:
   - empty body → `HTTP=503` body `{"status":false,"message":"Stripe non configuré."}`
   - with fake `Stripe-Signature` + charge.refunded payload → `HTTP=503`
   - Lock (c) confirmed END-TO-END on live server, not just unit test. 503 not 500.

4. **Live payment route curl** on EXISTING order 5620 (rules out route-model-binding 404):
   - `GET /payment/5620/pay` → `HTTP=404`
   - `GET /payment/stripe/5620/success` → `HTTP=404`
   - Server-side `guardWebPaymentV1()` abort(404) confirmed live.

5. **Config (no cache; file==runtime)** — `bootstrap/cache/config.php` absent; tinker reports
   `web_payment_v1.enabled=false`, `activation_guard.enabled=true`, `activation_gate_cleared=false`.
   config/payment.php:16, :52-53 match.

6. **PaymentController** app/Http/Controllers/Frontend/PaymentController.php:131-164 —
   `guardWebPaymentV1()` abort(404) when enabled false (:133-134);
   `isGatewayActivationAllowed('stripe')` returns `activation_gate_cleared` (=false) (:151-163);
   `assertGatewayActivationAllowed` abort(404) (:146-147). Called at :36,70-71,89-90,97-98,105-106,114.

7. **Web flag gate** /Users/1millnonstop/Downloads/web:
   - index.html:15 `<meta name="feature-online-card" content="0">`
   - api.js:26 `onlineCardEnabled: metaContent('feature-online-card','0')==='1'` → false
   - funnel.jsx:370 `onlineCard`; :452-457 `methods=[counter]` then push card only `if(onlineCard)`;
     :377 `pm=(onlineCard && method==='card')?4:1`. OFF ⇒ pm=1, no card in DOM.

8. **Mobile flag gate** mobile/screens-modals.jsx:44-45 cardOnline resolution; :60-71
   `cardOnline ? pay-card-online : pay-online-soon "Le paiement en ligne arrive bientôt."`;
   ScreenStripe:82 refuses form when flag OFF. mobile/index.html:73 default
   `onlineCardEnabled: false`; onPickCard handler (:323) also guards `if(!...onlineCardEnabled) return`.

## Adversarial angles checked — none broke the claim
- 500-vs-503 contradiction (`missing webhook secret returns 500` test): different scenario
  (configured gateway, missing webhook signing secret). In V1 secret empty ⇒ 503 branch hits first. No hole.
- Config cache masking enabled state: no config cache; live == file.
- 404 = guard vs missing model: used existing order 5620 → still 404 ⇒ guard fires.
- "Testable ON" is defense-in-depth client-only; server still 404 + gateway status=10 even if flipped.

VERDICT: refuted=false (PASS holds), confidence=high.
