# Page 2 — /admin/pos main grid — Evidence

**Verdict** : GREEN
**States** : `02-pos-grid-default`, `02b-pos-grid-after-toutes`

## Capture quartets

| State | PNG | DOM | Console | Network |
|---|---|---|---|---|
| 02-default | `02-pos-grid-default.png` | `.dom.html` | `.console.json` | `.network.json` |
| 02b-after-toutes | `02b-pos-grid-after-toutes.png` | `.dom.html` | `.console.json` | `.network.json` |

All paths under `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/`.

## Visual analysis

Header strip : "Caisse Foodking / À encaisser / Suivi commandes / Écran client / Plan de salle / Ouvrir tiroir / Filiale #1 / Caisse / Articles 0". Right side : "Bonjour Caissier" identity badge.

Category pills row : "Toutes les ... / Sandwich Cayenne / Galette / Sandwich Classique / Tacos / Bols Gourmands / Frites / Burgers" — visible 8 default. After "Toutes" toggle (state 02b), filiale filter expands to "8 articles" + adds the broken category (cat 315 'Frites & Accompagnements') items.

Tile grid : 4 cols × N rows, each tile = hero photo + title (truncated) + price (red brand). Featured 4 tiles : Menu (Frites + Boisson) 2.50€, Frites Seules 2.00€, Boisson Seule 2.00€, Chicken Burger 6.90€. Visible bottom row also has Coca-Cola, etc.

Right rail "Ticket Caisse / Commande en cours" : client selector + "+ add client" / "Mettre en attente" + "Commandes en attente (0)" / type segmented [À emporter / Livraison] / empty-state copy "Aucun article. Sélectionnez un produit dans la grille." / Sous-total 0,00 € / Total 0,00 €.

## Technical analysis

- Raw-label scan : no `label.x` / `kiosk.foo` / `pos.bar` leaks visible.
- Console : 2-3 info entries (Vue mounted, axios warm-up). No errors.
- Network : `[]` — no 4xx/5xx during render.
- Featured-filter toggle works (state 02b shows category 315 expanded — "Réduire" CTA confirms `aria-pressed=true`).
- `[data-testid="pos-category-toggle-all"]` present and functional.

## Verdict

GREEN. No defect on the grid itself. Note : capture 02b exposes the items in cat 315 that become unsellable on click (see page-4 evidence — root cause PG4-P0-001).
