# Page 3 — POS Wizard popup (Chicken Burger) — Evidence

**Verdict** : GREEN (attest — FROZEN zone)
**State** : `03-pos-wizard-opened`
**Frozen zone** : `public/js/pos-wizard.js` + `public/css/pos-wizard.css` + `resources/views/admin-pos-v4.blade.php` (strict-no-touch per CLAUDE.md §7)

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/03-pos-wizard-opened.png`
- DOM : `.dom.html`
- Console : `.console.json`
- Network : `.network.json`

## Visual analysis

Modal popup (centered, semi-transparent backdrop). Header : Chicken Burger thumbnail + name + €6.90 + quantity stepper (1, +/− buttons orange).

5 contract sections render simultaneously (single-page wizard, not stepped) :

1. **Viande** (required 0/1, orange badge) — 4 options listed : Poulet mariné, Poulet curry, Poulet tandoori, Poulet crispy. Each row has emoji + name + qty pad (− 0 +).
2. **Viande supplémentaire** — dashed red banner "+ Viande supplémentaire (+€2.50/viande) ▼" (expandable).
3. **Crudités** — pre-selected pills (Salade, Tomate, Oignon, Salade — green badges with check mark, "Cliquez pour retirer" hint copy).
4. **Sauce** — "1ère gratuite" teal badge top-right. 13 chips in 3 rows : Ketchup, Mayonnaise, Algérienne, Curry, Andalouse, Samouraï, Barbecue, Hannibal, Harissa, Blanche, Sauce fromagère maison, Spicy, Ail.
5. **Comment** + **Supplements** sections also confirmed in DOM (`comment-section`, `supplements-section`).

Sticky footer : `× Annuler` (left, ghost), Total `€6.90` (center, large), `🛒 Ajouter au panier` (right, primary teal-green CTA).

## Technical analysis

- Wizard sections verified via DOM regex match in `03-pos-wizard-opened.dom.html` :
  - `wizard-section viande-section` : present
  - `crudites-section` : present
  - `sauce-section` : present
  - `supplements-section` : present
  - `comment-section` : present
- Console : 1 info entry. No errors.
- Network : `[]` — no 4xx/5xx on wizard open.
- No raw-label leaks ("Viande", "Crudités", "Sauce", "Ajouter au panier" all proper FR copy).
- Sticky footer wrapped in `pos-v4-item-wizard-footer pos-add-to-cart-sticky pos-v5-item-modal__foot` (consistent V5 design tokens).

## Verdict

GREEN. All Sub 1.1 contract sections render. Required-Viande gate (0/1) is product-correct behaviour, not a defect. Frozen zone respected — 0 lines touched.
