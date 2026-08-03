# Page 10 — POS Order Detail / Status Change — Evidence

**Verdict** : AMBER
**Finding** : PG10-P1-001 (i18n_leak, P1)
**State** : `10-pos-order-show`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/10-pos-order-show.png`
- DOM : `.dom.html`
- Console : `.console.json`
- Network : `.network.json`

## Visual analysis

Same shell as page 9 + new section : order detail card.

**Breadcrumb** : `Tableau De Bord / Commandes Caisse / Menu.View`
   ↑↑↑ **PG10-P1-001 — raw i18n key `Menu.View` visible (not translated to FR or EN)**

Order header card :
- "**N° Commande: #1405261491**" (h2, dark navy)
- Status badges : "Payé" (green pill) + "Accepter" (green pill)
- Sub-info icons : date "11:26 AM, 14-05-2026", Type de paiement "Espèces", Type de commande "À emporter", Heure de livraison "14-05-2026 11:26 AM-11:26 AM", N° commande "#1"
- Right side : 3 CTAs — status dropdowns "Payé ▾" + "Accepter ▾" + primary button "🖨 Imprimer La Facture"

Body 2-column :
- Left "Détails Commande" :
  - row 1 : "Bowl Frites Poulet Curry / Mayonnaise: / 8.90€ / Instruction: BOWL FRITES POULET CURRY"
  - row 2 : "Petite Frites / Nature: / 2.50€ / Instruction: PETITE FRITES"
  - row 3 : "Tiramisu / 3.80€" (cut off — bottom of viewport)
- Right Top "Sous-Total 15.20€ / Remise 0.00€ / Total 15.20€"
- Right Bottom "Informations De Livraison / Client Comptoir / walkingcustomer@example.com / +33PENDING_28"

## Technical analysis

- **Raw label** : DOM contains literal lowercase string `menu.view` in breadcrumb : `<span class="text-slate-800 font-medium">menu.view</span>`. The visual renders as "Menu.View" because the surrounding shell applies CSS `capitalize`. The underlying source key is lowercase and **exactly matches** REVIEWER_PROTOCOL category 1 regex `^[a-z]+(\.[a-z_]+){1,4}$`. Severity **P1** per protocol — user-visible key on a primary surface.
- Console : 1 info entry. No errors.
- Network : likely 200 (no entry in network.json since the helper only logs ≥400/2000ms).
- Status change affordance present : 2 dropdowns + Imprimer CTA. Did not click to avoid mutating real fiscal data.
- Status-change action endpoint per Impl F: idempotency middleware now applied → safe re-fire.

## Defect classification

### PG10-P1-001 — i18n leak `Menu.View`

- Category : `i18n_leak` (REVIEWER_PROTOCOL #1)
- Severity : **P1** (user-visible on primary breadcrumb)
- Visible in capture : top of `10-pos-order-show.png`, left of order header card
- Fix : add key `menu.view` to `resources/js/languages/fr.json` (likely "Détails" or "Voir") + same for en.json + ar.json. Verify PosOrderShowComponent.vue breadcrumb partial binding.

## Heal applied

**NONE in this round** — surfaced for triage. P1 doesn't block ship (per CLAUDE.md §10 severity matrix), but should be closed before final GO.

## Verdict

AMBER. Order detail page renders correctly with all expected data, but one raw i18n key leaks into the breadcrumb. No P0 here. Status-change affordance exists (not exercised).
