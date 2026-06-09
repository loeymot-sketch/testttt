# CAISSE-01 — gate investigation (why it genuinely needs GATE-FROZEN-1)
2026-06-09. Sharpens the owner's frozen-vs-workaround decision.

## The defect (P1, revenue leak)
POS frites wizard shows "Grande Portion (+1,00 €)" + "Avec Cheddar Fondu (+1,00 €)" = +2,00 € in the recap, but the order is charged **0 €** for the upgrade. Both halves confirmed: the frozen `pos-wizard.js` emits the upgrade as `menu_extras[]` TEXT (empty `item_extras`); `PricingService` (frozen SSOT) prices only `item_extras` and has no `menu_extras` path.

## Is a NON-frozen fix possible? (investigated 2026-06-09)
**No clean one.** Verified on the e2e clone:
- **"Grande Portion" is NOT a catalog ItemExtra** — `item_extras` has no Grande row (only sandwich "Cheddar" toppings @ 0.90€, which are a DIFFERENT construct from the frites +1.00€ Cheddar upgrade).
- **The upgrade prices are hardcoded in the frozen `pos-wizard.js`** (`_cfg.fritesGrandePrice ?? 1.00`), NOT in `config/pos.php`.
- **`menu_extras` has zero backend pricing path** (`grep menu_extras app/` empty).

So to make the server charge the upgrade, the only routes are:
1. **Edit the frozen `pos-wizard.js`** to emit a real priced `item_extras` option_id → **GATE-FROZEN-1** (frozen, strict-no-touch).
2. **Build a non-frozen workaround**: create catalog `ItemExtra` constructs for "Grande Portion"/"Cheddar Fondu" on every frites item (×N) + a non-frozen text→id mapping layer before `PricingService` + ensure the mapping never drifts. This avoids the frozen files but adds a catalog-construct + mapping subsystem with its own correctness/drift risk.

## The owner decision (GATE-FROZEN-1)
This is precisely a "fix the frozen root vs build a non-frozen workaround" architecture choice — which is why it is an owner gate, not an agent fix:
- **Route A (frozen root):** LOCK doc + countersign → edit `pos-wizard.js` to emit the upgrade as a priced option. Cleanest; touches the strict-no-touch wizard.
- **Route B (non-frozen workaround):** create the catalog constructs + mapping. Avoids frozen but heavier + drift-prone.

**Owner picks A or B.** On decision, I implement + DB-assert on the disposable `:8766` clone (order.total == wizard recap; `order_items.item_extra_total == 2.00`).

## Interim mitigation (no code)
Until decided, the cashier sees +2,00 € on the recap but the order under-charges. A purely-operational interim: instruct staff that frites upgrades aren't auto-billed (or disable the upgrade options) — an owner/ops call, not a code change.
