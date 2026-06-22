# MENU HEAL-LIGHT V2 — ROUND 2 PATCH REPORT

**Date** : 2026-05-14
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Baseline** : commit `62959bfc9` (V2 round 1)
**Command** : `app/Console/Commands/MenuHealLightV2Round2PatchCommand.php`

## Findings healed

Round 1 (Wave KIOSK) adversarial review surfaced four P1 issues. The artisan
command `menu:heal-light-v2-round2` resolves each non-destructively, idempotently,
inside a single `DB::transaction`. Zero frozen files modified, NF525 chain
untouched (max `fiscal_sequence_no` = 325 pre- and post-run).

### P1-A · Big Cayenne (item 488)
Pre-flight detected attr 331 ("Sauce Cayenne (incluse)") had no `ItemVariation`
row for item 488 (only attached to items 474, 476). Heal:
1. Seeded `ItemVariation(488, 331, "Sauce Cayenne maison", 0)`.
2. Created `ItemWizardProfile id=82` (template=`custom`, version=1, published,
   `branch_id_scope=NULL`) with **5 steps**:
   - viande_1 → attr 307, min=1 max=1, 4 choices
   - viande_2 → attr 308, min=1 max=1, 4 choices
   - sauce_cayenne → attr 331, min=1 max=1, **1 choice** (locked)
   - supplements → extra_group `supplement`, min=0 max=6, 9 choices
   - menu → addon role `menu_component`, min=0 max=1, 1 choice (item 360)

### P1-B · Big Classique (item 489)
Created `ItemWizardProfile id=83` with **5 steps**: viande_1 (4), viande_2 (4),
sauce libre (attr 311, 11 choices, min=1 max=1), supplements (9), menu (1).

### P1-C · Tacos L (item 479)
Created `ItemWizardProfile id=84` with **3 steps**: viande_1 (4), viande_2 (4),
menu addon (1). No sauce / supplements per spec.

### P1-D · Menu enfant (cat 350) kiosk leak
Updated `channels` from `NULL` → `["pos","admin","mobile"]`. Verified via
`ItemCategory::isVisibleOn`: `pos=YES admin=YES mobile=YES kiosk=NO`.

## Notes on spec-vs-schema reconciliation
- `addon_role` enum is `['drink','side','dessert','menu_component','upsell']` —
  the task's `menu_full` value would have thrown `InvalidArgumentException`.
  Used `menu_component` (the verified add-on role for combo meals).
- `source_type` enum is `['item_attribute','extra_group','addon','fixed']`. All
  rows respect the constraint.
- "Récap" is frontend-rendered (`KioskWizardComponent.vue`), not a DB step.
  Real step counts in `item_wizard_steps`: **5 / 5 / 3** (not 6/6/4).
- `source_ref='supplement'` matches the `group_label` for sandwich-family
  extras on items 488/489 (bowls use `supplement_bol`).

## Verification
- `ItemWizardProfile::count()` = 13 (10 baseline + 3 new) ✓
- `ItemResource::composerProfilePayload()` returns 5/5/3 steps with non-empty
  `choices[]` arrays for items 488/489/479 ✓
- `git diff --name-only` against the 13 frozen files = empty ✓
- Idempotent re-run: 3 profiles_skipped, 0 events_fired ✓
- 8 sync events fired on first run (3 × `ComposerProfileChanged` + 1 ×
  `CategoryUpdated` + 4 × `CatalogChanged` bridge) ✓
- `npm run dev` compiled successfully (9.36s) ✓

## Files
- `app/Console/Commands/MenuHealLightV2Round2PatchCommand.php` (new)
- Bundles rebuilt under `public/js/*.js`
