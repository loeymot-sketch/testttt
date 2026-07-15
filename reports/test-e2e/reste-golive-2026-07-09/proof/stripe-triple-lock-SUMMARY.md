# Stripe triple-locked OFF but flag testable ON — verification 2026-07-09

## 1. Stripe test suite
`php artisan test --filter=Stripe` → **34 passed**, exit 0 (STRIPE_TEST_EXIT=0).
Incl. webhook guard 503 test: `Tests\Feature\Stripe\StripeWebhookUnconfiguredTest`
- "webhook with empty stripe secret returns 503 json not 500" PASS
- "webhook with missing stripe secret option returns 503 json" PASS
Raw: proof/stripe-tests.txt

## 2. Triple lock (server-side)
### (a) config/payment.php
- `web_payment_v1.enabled = false` (config/payment.php:16)
- `stripe.activation_guard.enabled = true`, `activation_gate_cleared = false` (config/payment.php:52-53)

### (b) DB gateway row
`payment_gateways` id=4 Stripe **status=10** (=Activity::DISABLE; Activity::ENABLE=5, DISABLE=10 per app/Enums/Activity.php:7-8).
`Stripe::status()` (Stripe.php:158-165) queries `status => Activity::ENABLE` → returns false. Proof: proof/gateway-db.txt

### (c) Stripe.php webhook 503
handleWebhook Stripe.php:257-264 → `if(!isStripeConfigured()) return unconfiguredResponse()` = HTTP 503 (Stripe.php:70-76). isStripeConfigured = `isset($this->gateway)`, only set when stripe_secret non-empty (disabled → 503, never 500).

### Enforcement is REAL (not dead code)
app/Http/Controllers/Frontend/PaymentController.php calls at top of EVERY public payment action:
- `guardWebPaymentV1()` (:36,70,89,97,105,114) → abort(404) when web_payment_v1 disabled (:133-134)
- `assertGatewayActivationAllowed('stripe')` (:71,90,98,106) → abort(404), returns activation_gate_cleared=false (:151-164)

## 3. Client flag = REAL runtime gate
### Web standalone (/Users/1millnonstop/Downloads/web)
- default OFF: index.html:15 `<meta name="feature-online-card" content="0">`
- read: api.js:26 `onlineCardEnabled: metaContent('feature-online-card','0') === '1'`
- gate: funnel.jsx:370 `onlineCard = !!(api.config.onlineCardEnabled)`; :452-457 methods=[counter]; `if(onlineCard) methods.push({id:'card'})`
- payment_method honnête: funnel.jsx:377 `pm = (onlineCard && method==='card') ? 4 : 1`
- Simulated gate: OFF → ["counter"], ON → ["counter","card"] (proof/web-flag-gate.txt)

### Mobile (mobile/screens-modals.jsx)
- read: :44-45 `cardOnline` from onlineCardEnabled/flag/window.LC.config.onlineCardEnabled
- gate: :60-71 `{cardOnline ? <button data-testid="pay-card-online"> : <p data-testid="pay-online-soon">Le paiement en ligne arrive bientôt.</p>}`
- defense-in-depth: ScreenStripe :82 refuses to render form if flag OFF

## VERDICT: PASS
OFF enforced server-side (404 guards + DB disabled + webhook 503), NOT just UI.
Flag genuinely gates the option (OFF=counter-only DOM, ON=card reappears) in both web + mobile.
