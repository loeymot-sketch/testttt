# PLAN — POS V4 wizard viewport fit (no scrolling required, no duplicate CTA)

| Champ | Valeur |
| --- | --- |
| **TASK_ID** | `POS-V4-WIZARD-VIEWPORT-FIT-2026-05-02` |
| **PRIMARY_EXECUTION_MODEL** | Cursor Composer (CSS + 1 line JS to hide a Vue footer when wizard is active) |
| **REASONING_EFFORT** | medium |
| **PLAN_REVIEW** | N/A — UI scoped, no pricing/order logic |
| **SUBSYSTEMS_TOUCHED** | `resources/js/components/admin/pos/ItemComponent.vue` (hide Vue footer when wizard active via class binding), `resources/css/app.css` (id-specificity override of `.ff-modal` margins for `#item-variation-modal`), `public/js/pos-wizard.js` (hide/restore the Vue wizard footer alongside `originalBody`) |
| **SUBSYSTEMS_OFF_LIMITS** | wizard logic, prices, `addToCart`, `Pinia/posCart` |
| **GATE_CONDITIONS** | None anticipated |
| **INVARIANTS_AT_RISK** | None — display only; pricing SSOT untouched, wizard event flow unchanged |

## Root cause (observed)

1. `pos-wizard.js` `interceptModal()` hides `.modal-header` and `.modal-body` of `#item-variation-modal`, but **not** the Vue-added `.pos-v4-item-wizard-footer` (introduced in `POS-V4-VIEWPORT-UI`). Its CTA stays visible above the wizard, ignores the wizard's running total, and writes `temp.total_price = 0` to cart on click → matches user report ("ajouter au panier en haut, pas synchro").
2. `pos-wizard.js` styles set `#item-variation-modal .modal-dialog { max-height: 92vh; overflow: hidden }` with id specificity, beating the previous `.pos-v4-item-wizard-dialog` override → dialog still gets pushed below the visible viewport on a 21" monitor.
3. The wizard's `.wizard-sticky-bar` is `position: sticky` inside `.pos-wizard.single-page` which itself scrolls — sticky scope = inner scroller, not the dialog. When the dialog overflows the viewport, the bar disappears.

## Steps

1. `ItemComponent.vue`: bind a class `pos-v4-item-wizard-shell` on the dialog so external selectors can scope safely; expose `data-wiz-vue-footer` on the new footer so `pos-wizard.js` can hide it like `originalBody`.
2. `pos-wizard.js`: in `interceptModal`, also hide the Vue footer (`[data-wiz-vue-footer]`); in `closeWizard`, restore it.
3. `app.css`: id-specificity override for `#item-variation-modal.pos-v4-item-wizard-modal .modal-dialog` — full viewport height, flex column, internal scroll on the body only, no sticky inside scroller.
4. `ItemComponent.vue` template tweak: ensure footer is `flex-shrink: 0` and `pos-v4-item-wizard-scroll` is `min-height: 0; overflow-y: auto`.

## VALIDATE

- `npx vitest run tests/js/PosComponent.spec.js tests/js/posRuptureUx.spec.js`
- `npm run development`
- Manual: 21" 1080p, open POS → product wizard → CTA bottom always visible, no page-level scroll, only inside scrollable section if content too long.
