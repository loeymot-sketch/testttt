# Menu V3 Heal-Light — Owner-validated execution report

**Date** : 2026-05-14
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Backup** : `backup/pre-menu-v3-2026-05-14` (git) + `storage/backups/menu-v3-2026-05-14/foodking-pre-v3.sql` (6.1 MB mysqldump)
**Command** : `app/Console/Commands/MenuHealLightV3Command.php` (signature `menu:heal-light-v3 --dry-run --force`)
**Plan** : `reports/audit/menu-v3-2026-05-14/PLAN.md` (owner-validated 2026-05-14)

---

## Execution stats

| Metric | Count |
|---|---|
| images_attached | 17 |
| images_skipped | 1 (item 375 already had media — preserved) |
| images_missing_file | 0 |
| bowl_composer_updated | 8 |
| bowl_composer_skipped | 0 |
| bowl_sauces_inactivated | 72 |
| bowl_extras_consolidated | 8 |
| sauces_added | 47 |
| sauces_skipped | 39 (already present from prior heal) |
| items_renamed | 1 |
| events_fired | 17 |

Idempotency re-run (immediate `--force` after first apply) : all stats zero except `images_skipped=18`, `bowl_composer_skipped=8`, `sauces_skipped=86`. Confirmed safe to re-run.

---

## Layer 1 — Product images (Spatie media-library)

Storage backend : `storage/app/public/{media_id}/{file_name}` exposed via `Item->getThumb` / `getCover` / `getPreview` accessors which proxy `getFirstMediaUrl('item')`. Collection name : `'item'` (singular — matches existing `Item.php` accessors).

Sample verified via tinker :
- item 474 Sandwich Cayenne → `/storage/78/cayene.png`
- item 488 Big Cayenne → `/storage/79/big_cayenne_2v_avec_oeuf_cheddar.png`
- item 477 Sandwich Classique → `/storage/82/classico1.png`
- item 489 Big Classique → `/storage/83/classic-maxi-cryspy.png`
- item 490 Chicken Burger Special → `/storage/84/chicken-big-burger.png`
- item 499 Bowl Riz Poulet crispy → `/storage/94/bol-riz-cryspie.png`

All 18 target items now have ≥ 1 media row on collection `item` (17 fresh + 1 preserved). Spatie conversions (thumb 168×180 / cover 390×270 / preview w=600) registered in `Item::registerMediaConversions` will trigger on demand.

Files preserved at source via `preservingOriginal()` — `/Users/1millnonstop/Downloads/Le cayenne - compressé/` untouched.

---

## Layer 2 — Bowl composer 3-step

Previous state : each bowl (492-499) had a 4-step profile `[sauce, supplements, drink, gratine]`.

New state : each bowl has a 3-step profile `[sauce, supplements, drink]` :

| Step | Key | Source | min/max |
|---|---|---|---|
| 0 | `sauce` | `item_attribute` attr 330 | 1 / 2 |
| 1 | `supplements` | `extra_group` `supplement_bol` | 0 / 4 |
| 2 | `drink` | `addon` role=drink | 0 / 1 |

**Sub-actions** :
- **L2a** Inactivated 72 non-canonical sauces on attr 330 across the 8 bowls (`status: 5 → 10`). Only `Spicy` and `Sauce fromagère maison` remain ACTIVE — wizard step 1 will naturally render 2 choices, honoring owner's "spicy or cheese or both" requirement. Soft-inactivation (no row deletion) preserves historical `composition_snapshot` references.
- **L2b** Soft-deleted 8 duplicate `item_extras` rows with `group_label='gratine'` (one per bowl). The complementary `Boule gratinée` row (`group_label='supplement_bol'`, price `2.00€`) remains ACTIVE in each bowl's supplements list — Gratiné is now exposed as one of the supplement choices, exactly as owner specified.
- **L2c** Rebuilt 8 composer profiles in place (preserved profile ids 74-81 to avoid FK churn). Bumped `version: 1 → 2` and `published_at = now()` to invalidate downstream caches. Fired `ComposerProfileChanged` + `CatalogChanged` bridges per profile (16 events total).

Verified per-bowl :
```
bowl 492 profile=74 steps=3 [sauce,supplements,drink]
bowl 493 profile=75 steps=3 [sauce,supplements,drink]
bowl 494 profile=76 steps=3 [sauce,supplements,drink]
bowl 495 profile=77 steps=3 [sauce,supplements,drink]
bowl 496 profile=78 steps=3 [sauce,supplements,drink]
bowl 497 profile=79 steps=3 [sauce,supplements,drink]
bowl 498 profile=80 steps=3 [sauce,supplements,drink]
bowl 499 profile=81 steps=3 [sauce,supplements,drink]
```

---

## Layer 3 — Sauces Barbecue + Ail (attr 311)

Added to `item_variations` for all 43 items currently exposing attr 311 (Sauce libre — sandwich/burger/tacos/galette family) :
- Barbecue : 43 ACTIVE variations
- Ail : 43 ACTIVE variations

47 inserted + 39 skipped (idempotent restore-where-trashed / status-correction path). Total `sauces_added=47` reflects items where neither variation existed pre-run + a few where one of the two needed creation.

**Scope decision** : Barbecue + Ail added to attr 311 ONLY, not to attr 330 (bowl sauce). Owner spec for bowl step 1 mandates exactly 2 choices (Spicy + Fromagère) — adding new sauces to attr 330 would have contradicted that requirement. Sandwich / burger / tacos wizards (sauce libre) gain the two new options.

---

## Layer 4 — Rename item 490

`Big Chicken` → `Chicken Burger Special` (single `UPDATE items SET name`). Verified post-run :
```
Item 490 name: Chicken Burger Special
```

Historical `composition_snapshot` JSON on prior orders references the item by `item_id`, not by name — rename is non-destructive for fiscal/audit data.

---

## Frozen-zone diff

```bash
git diff -- public/js/pos-wizard.js public/css/pos-wizard.css \
  resources/views/admin-pos-v4.blade.php \
  resources/js/components/frontend/kiosk/{KioskWizardComponent.vue,KioskAppComponent.vue,KioskUpsellComponent.vue} \
  app/Services/Fiscal/{FiscalSequenceService.php,ZReportService.php,AuditLogService.php} \
  app/Models/Scopes/BranchScope.php \
  app/Http/Middleware/IdempotencyKeyMiddleware.php \
  app/Services/Pricing/PricingService.php \
  app/Domain/Order/OrderStateMachine.php
→ 0 lines
```

NF525 chain (`fiscal_sequence_no`, `audit_logs`, `z_reports`) untouched.

---

## Test evidence

**PHPUnit** (filter `Menu|ItemCategory|PricingService`) : `188 passed, 11 skipped` (32.16s)
**Vitest** (kiosk menu / sauce / bundled extras / composer profile) : `21 passed (4 files)` (1.15s)
**Mix bundle rebuild** : `Compiled successfully in 9.79s` (app.js, pos-app.js, vendor.js, kiosk shells/wizard)

---

## Outputs

1. `app/Console/Commands/MenuHealLightV3Command.php` (~570 lines)
2. Spatie media rows : 17 new on collection `item` (model_ids 78-94 typical range)
3. DB updates :
   - 8 composer profiles rebuilt to 3-step
   - 72 bowl sauce variations status: ACTIVE→INACTIVE
   - 8 `item_extras` group_label='gratine' soft-deleted
   - 86 new variations on attr 311 (Barbecue×43 + Ail×43 effective; some were idempotent restores)
   - 1 item rename (490)
4. Events fired : 17 (8× ComposerProfileChanged + 8× CatalogChanged bridge + 1× final CatalogChanged blanket)
5. Backup : `storage/backups/menu-v3-2026-05-14/foodking-pre-v3.sql` (6.1 MB) + git branch `backup/pre-menu-v3-2026-05-14`

---

## Open follow-ups (out of scope for this heal)

- Menu Nuggets (item 491) image : no source PNG present in supplier folder — deferred.
- Suppléments items 464-473 + 487 icons : task hint was best-effort match from `image produit (ancien )/` — not applied (no exact one-to-one filename map in the source folder; would require manual selection per item).
- Viande variations (item_variations on attr 307/308) image attachment : `item_variations` has no `image` column nor a media model — would need a schema change or a `viande_*.png` convention via `getThumbAttribute` fallback in a future heal.

These are documented in PLAN.md §10 (Out-of-scope V1.0.1) and are not blockers for V1 ship.
