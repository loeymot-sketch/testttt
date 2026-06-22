# PLAN — POS V4 unified category view (no landing variant)

| Champ | Valeur |
| --- | --- |
| **TASK_ID** | `POS-V4-UNIFIED-CATEGORY-VIEW-2026-05-02` |
| **PRIMARY_EXECUTION_MODEL** | Cursor Composer (UI scoped) |
| **REASONING_EFFORT** | low |
| **PLAN_REVIEW** | N/A — display layout, no pricing/order logic |
| **SUBSYSTEMS_TOUCHED** | `resources/js/components/admin/pos/PosComponent.vue` (template only, drop `isLanding` branch) |
| **SUBSYSTEMS_OFF_LIMITS** | wizard, pricing, `addToCart`, store / API |
| **GATE_CONDITIONS** | None anticipated |
| **INVARIANTS_AT_RISK** | None — frontend display only |

## Goal

A single, consistent layout in POS:
- The **category strip** (Toutes + categories) is **always** visible at the top.
- The **products grid** is **always** below the strip.
- Clicking **Toutes** does **not** switch to a different "landing" page; it just unfilters the grid while keeping the same strip + grid layout.
- "Toutes" stays highlighted as active when no category is selected.

## Steps

1. Remove `<template v-if="isLanding">…<template v-else>…</template>` two-branch split in `PosComponent.vue`.
2. Keep only the strip + items grid (used to live under `v-else`). Strip already filters cleanly with `setCategory` / `allCategory`.
3. Drop the now-unused big "landing" cards grid (`pos-v4-category-grid`) and the best-sellers ribbon — both depended on `isLanding` and broke visual consistency. (Computed `isLanding` / `bestSellerItems` left in JS as harmless dead code; can be cleaned in a later janitor pass.)
4. Verify "Toutes" pill highlight rule (`category.id === 0 && search.item_category_id === ''` → `pos-group`) still works.

## VALIDATE

- `npx vitest run tests/js/PosComponent.spec.js`
- `npm run development`
- Manual: open POS → strip with "Toutes" + categories visible. Click any category → strip stays. Click "Toutes" → strip stays, all products in grid. No layout swap.
