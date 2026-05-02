# PLAN — POS V4 viewport & wizard shell (layout only)

| Champ | Valeur |
| --- | --- |
| **TASK_ID** | `POS-V4-VIEWPORT-UI-2026-05-02` |
| **PRIMARY_EXECUTION_MODEL** | Cursor Composer (layout/CSS — no pricing/order logic) |
| **REASONING_EFFORT** | medium |
| **PLAN_REVIEW** | N/A — scoped UI; no Codex cycle |
| **SUBSYSTEMS_TOUCHED** | `resources/js/components/admin/pos/ItemComponent.vue` (template structure + classes); `resources/css/app.css` (modal override `.pos-v4-item-wizard-modal`); `resources/js/components/admin/pos/PosComponent.vue` (grid + shell spacing scoped CSS) |
| **SUBSYSTEMS_OFF_LIMITS** | `public/js/pos-wizard.js`, pricing, wizard rules, Pinia/order backend |
| **GATE_CONDITIONS** | None anticipated |
| **INVARIANTS_AT_RISK** | None — display/layout only; backend pricing SSOT unchanged |

## Goal

21″ cashier screens: item wizard fills viewport; primary action always visible; scroll only inside wizard content. POS catalog grid slightly larger tiles (2 columns default). Reduce cramped POS shell feel without changing wizard behaviour.

## Steps

1. Split item variation modal: scrollable body + fixed footer (Add to cart).
2. Global CSS overrides for `.modal.pos-v4-item-wizard-modal` to neutralize `.ff-modal` margins/offset on lg.
3. Product grid: `grid-cols-2` / `xl:grid-cols-3`; minor `.fk-pos-v4` padding tweaks.

## VALIDATE

- `npx vitest run tests/js/PosComponent.spec.js`
- `npm run development` (Mix)
