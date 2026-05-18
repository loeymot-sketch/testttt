# Page 5 — POS Payment modal CASH — Evidence

**Verdict** : BLOCKED
**Blocking finding** : PG4-P0-001
**State captured** : `05-pos-payment-NO-PAY-BTN`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/05-pos-payment-NO-PAY-BTN.png`
- DOM : `.dom.html`
- Console : `.console.json`
- Network : `.network.json`

## Why blocked

The cart is empty (PG4-P0-001 prevented item 361 add). The `[data-testid="pos-v5-pay"]` button is suppressed when `cart.length == 0`. The cash-overpay scenario cannot be exercised until a sellable item lands in the cart.

The capture shows the same view as page 4 (grid + empty cart + red error toast still visible). The spec captured `05-pos-payment-NO-PAY-BTN` as evidence of the bail path.

## Audit when unblocked

After healing PG4-P0-001 (data fix Option A : `channels=NULL` on cat 315), re-run the spec. Expected attestation :
- Click Frites Seules tile → auto-add (no wizard for simple template)
- `[data-testid="pos-v5-pay"]` becomes visible with grand total €2.00
- Click pay → modal opens with hero "À encaisser €2.00"
- Select cash mode → enter tendered 50 → expect "Monnaie / Rendre" indicator showing €48.00
- Confirm not exercised (do not commit fiscal data in audit run)

## Verdict

BLOCKED-DOWNSTREAM. Will reattest GREEN/AMBER once PG4-P0-001 is healed.
