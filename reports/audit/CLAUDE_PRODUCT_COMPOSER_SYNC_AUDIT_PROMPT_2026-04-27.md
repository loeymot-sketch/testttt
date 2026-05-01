# Claude Audit Prompt — Product Composer, Catalogue, Stock, POS/Kiosk Sync

Date: 2026-04-27
Mode: independent audit, no product patch unless a later human gate explicitly asks for implementation.

## Mandatory reading order

1. `AGENTS.md`
2. `.cursor/ACTIVE_CYCLE.md`
3. `plans/masterplay/MASTERPLAY_DISCIPLINE.md`
4. `plans/masterplay/MASTERPLAY_QUEUE.md`
5. `reports/audit/PRODUCT_COMPOSER_SYNC_DEEP_AUDIT_ORCHESTRATION_2026-04-27.md`
6. `plans/PLAN_PRODUCT_COMPOSER_SYNC_MASTER_2026-04-27.md`
7. `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN1_AUDIT_SCHEMA_2026-04-27.md`
8. `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN2_DASHBOARD_COMPOSER_2026-04-27.md`
9. `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN3_PROJECTION_RUNTIME_2026-04-27.md`
10. `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN4_STOCK_ORDER_SYNC_2026-04-27.md`
11. `plans/PLAN_PRODUCT_COMPOSER_SYNC_TRAIN5_E2E_RELEASE_2026-04-27.md`
12. All `missions/PRODUCT-COMPOSER-SYNC-*/execute_brief.md`

## Audit objective

Audit whether the new orchestration correctly captures the user's previously ignored demands:

- central dashboard management of categories, products, prices, images, supplements, extras, addons, and product compositions;
- manual product composer for sandwich, assiette, menu, offer, and custom products;
- POS/kiosk shared catalogue and shared stock while preserving different visual interfaces;
- backend-only pricing authority;
- branch-isolated stock and catalogue availability;
- stock rupture propagation to POS/kiosk with visible `RUPTURE` contract;
- queue number uniqueness and order live visibility across POS, kiosk, KDS, and OSS;
- kiosk lock-down with no admin/caisse navigation from client surface;
- diagnosis path for kiosk connection-lost banner;
- POS order path without arbitrary customer-id blocking for takeaway;
- delivery address and 5 km fee calculation verification;
- Google Maps dependency/fallback verification;
- audit-before and audit-after discipline for each implementation mission.

## Critical questions

1. Is the proposed thin composer layer (`item_wizard_profiles`, `item_wizard_steps`) sufficient, or is there a better schema that still avoids duplicating existing `item_attributes`, variations, extras, and addons?
2. Is stockable stock (`stockable_type`, `stockable_id`) the correct approach for products, extras, variations, addon items, and future ingredients?
3. Are any planned files inside frozen zones without an explicit gate?
4. Do the train boundaries avoid dangerous mixed commits, especially migrations plus OrderService runtime?
5. Does the plan preserve PricingService/backend quote as the only final price authority?
6. Does the plan cover the kiosk/POS parity without forcing identical UI?
7. Is the Claude plan from the user merged safely, or are there contradictions with the current codebase?
8. Which mission should execute first, and which gates must be recorded before implementation?

## Required output format

Return Markdown with:

- `VERDICT: PASS|NEEDS_FIX|ESCALATE`
- `Critical Findings`
- `Missing Gates`
- `Plan Corrections`
- `Implementation Order`
- `Files or Missions to Modify`
- `Do Not Implement Yet`

If you find any high-risk flaw, do not approve execution. Provide a corrected mission split instead.
