# Page 7 — POS Payment modal SPLIT (multi-tender) — Evidence

**Verdict** : BLOCKED
**Blocking finding** : PG4-P0-001
**State captured** : `07-pos-payment-split-NOT-VISIBLE`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/07-pos-payment-split-NOT-VISIBLE.png`
- DOM : `.dom.html`
- Console : `.console.json`
- Network : `.network.json`

## Why blocked

Same root cause as pages 5+6. Payment modal never opens (cart empty due to PG4-P0-001).

Split mode `[data-testid="pos-payment-mode-multi"]` is wired (verified in earlier DOM dumps). The `.pos-v5-split-divider__input` + `.pos-v5-split-divider__btn` (equal-split helper) are CV1-POS-SPLIT-PAYMENT-001 anchors per `public/js/pos-shell.js` style block.

## Audit when unblocked

Add 2 items to cart (Frites Seules €2.00 + Boisson Seule €2.00 → total €4.00). Open payment modal.

- Click `[data-testid="pos-payment-mode-multi"]`
- Equal-split path : fill `.pos-v5-split-divider__input` = 2, click divider btn → 2 tranches each €2.00
- Mixed-split path : add tranche cash €2.50 + tranche card €1.50 → verify `Reste dû = 0` (success-soft pill)
- Sentinel check : SplitPaymentService TOLERANCE_OVERPAY=1.00€ (per Agent 1 P1 finding) — author may consider testing overpay €0.99 to confirm no silent skim

## Verdict

BLOCKED-DOWNSTREAM. Re-attest once PG4-P0-001 healed.
