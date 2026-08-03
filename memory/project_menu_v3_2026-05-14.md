---
name: project-menu-v3-2026-05-14
description: Menu V3 owner-file ingest 2026-05-14 — heal-light V3 shipped GO-CONDITIONAL, 1 P1 bowl-drink-step-mapping awaiting owner decision
metadata:
  type: project
---

Menu V3 heal-light shipped commit `7f06224af` (branch `feature/mobile-app-le-cayenne-2026-05-10`).

**Source** : owner file `/Users/1millnonstop/Downloads/Le cayenne - compressé/` — 4 menu mockups + 60 product photos validés.

**Delivered** :
- 17 product images attached via Spatie media-library `item` collection (sandwiches/galettes/burgers/tacos/bowls + Big variants)
- 8 bowl composer profiles rebuilt 4-step → 3-step canonical (Sauce 2-choice Spicy+Fromagère / Suppléments+Gratiné / Boisson)
- 2 nouvelles sauces : Barbecue + Ail sur attr 311 (43 items, scoped pour ne PAS inflate bowl wizard attr 330)
- Item 490 renamed Big Chicken → Chicken Burger Special
- PHPUnit 188 PASS / Vitest 21 PASS / Mix compiled / Frozen-zone diff = 0 / NF525 chain untouched

**Verified (Playwright + DB)** :
- 6/6 target items render `<img.kiosk-product-image>` (no `+` placeholder)
- Bowl 493 wizard step 1 = 2 sauces visibles (Spicy + Fromagère)
- Bowl 493 wizard step 2 = Boule gratinée €2.00 visible
- Sandwich Classique 477 wizard sauce step = Barbecue + Ail visible
- Burgers grid shows "Chicken Burger Special" (no "Big Chicken" remnant)

**P1 open — owner decision required** : Bowl wizard step 3 renders "Quel menu ?" (MENU COMPLET / +FRITES / SANS MENU) instead of pure-drink picker. Root cause = `STEP_KEY_REGISTRY` line 333 in [[reference-frozen-zones]] `KioskWizardComponent.vue` maps `addon_role='drink' → type='menu'` + `source_type='addon'+source_ref='drink'` hardcodes combo bundle.

Why: existing wizard architecture assumes "drink at end" = Menu Combo upsell offer, not standalone drink picker.

How to apply: 3 remediation paths documented in `reports/audit/menu-v3-2026-05-14/CONVERGENCE.md` §3 P1-001 :
1. `/lock-plan` KioskWizardComponent.vue + add new step type `'drink_picker'` mapped to a new KioskStepDrink component reading boissons cat 317
2. DB-side rework : change bowl step 3 `step_key=boisson_libre source_type=item_category source_ref=317`, requires KioskStepGenericChoicesComponent to handle category-driven choices (verify supports)
3. Accept-as-is : Menu Combo at end of bowl is UX-acceptable until V1.0.1

**Backup** : git branch `backup/pre-menu-v3-2026-05-14` + DB dump `storage/backups/menu-v3-2026-05-14/foodking-pre-v3.sql` (6.1 MB).

**Out-of-scope V1.0.1 polish** :
- Menu Nuggets (item 491) image — no source PNG in owner folder
- Suppléments items 464-473 + 487 icons — no exact filename mapping
- Viande variations images — `item_variations` lacks `image` column + Spatie model

Related : [[project-audit-ultra-review-v2-2026-05-08]] [[project-pos-audit-2026-05-09-no-go]] [[reference-frozen-zones]] [[feedback-gstack-pipeline-methodology]]
