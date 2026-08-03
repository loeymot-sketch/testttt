# Page 6 — POS Payment modal CARD — Evidence

**Verdict** : BLOCKED
**Blocking finding** : PG4-P0-001
**State captured** : `06-pos-payment-card-NOT-VISIBLE`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/06-pos-payment-card-NOT-VISIBLE.png`
- DOM : `.dom.html`
- Console : `.console.json`
- Network : `.network.json`

## Why blocked

Payment modal never opens (PG4-P0-001 → cart empty → no pay button). Card mode toggle `[data-testid="pos-payment-mode-card"]` cannot be tested.

DOM grep confirms `data-testid="pos-payment-mode-card"` exists in the wizard fragment (verified in `03-pos-wizard-opened.dom.html`) — so the toggle is wired in the FROZEN PaymentComponent. The blocker is data, not code.

## Audit when unblocked

Once PG4-P0-001 healed :
- Open payment modal (via pay btn)
- Click `[data-testid="pos-payment-mode-card"]`
- Verify segmented control active state (border + shadow per `pos-v5-payment-method.is-active` rule)
- Under `POS_SIMULATION_HARDWARE=true` : no real Stripe call expected; visual TPE prompt + confirm button surfaces
- Capture state + ensure no `Stripe.payment ... metadata.order_id` 4xx (P0-POS-01 already healed in commit 606b7aaa7)

## Verdict

BLOCKED-DOWNSTREAM. Re-attest once PG4-P0-001 healed.
