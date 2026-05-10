# 310 Ojja — Mobile vs Kiosk qualitative diff

**Test item mobile** : `ojja-merguez` (cat 5 mobile = cat 310 DB).
**Template** : omelette (V3.8 align cf. migration `2026_05_10_060000` revert menu/frites, frites incluses dans le prix Ojja).

## Step-by-step semantic comparison

| Mobile step | Kiosk equivalent | Match | Notes |
|-------------|------------------|-------|-------|
| 01-sauce.png | `310-01-step-sauce.png` | ✓ | Sauce 15 options, 1-free 0.50€-extra. Sauce attr 311 attached à items 385-388 cf. tinker `02_dba_tinker.txt`. |
| 02-supplements.png | (couvert dans recap kiosk) | indirect | Suppléments optional 6 rows. |
| 03-recap.png | `310-02-step-recap.png` | ✓ | Composition snapshot équivalent. |

## Validation rules match

- Mobile template `omelette` : `sauce → crudites? → supplements → recap` (screens-item-steps.jsx:81-86)
- Kiosk template `omelette` : `sauce → garnitures → supplements → recap` (KW.vue:590-601)
- **MATCH** : sauce + supplements + recap. Crudités mobile skip car `item.has_crudites: false` sur items Ojja (cohérent DB has_crudites false pour cat 310).
- **PAS DE STEP `menu` ni `frites_style`** côté mobile cohérent avec V3.8 revert (frites déjà incluses dans prix Ojja).

## A11y pattern match

- Mobile ChoiceCard pour sauce + supplements : role=checkbox + tabindex=0 + onKeyDown.
- Kiosk KioskStepSauceComponent / KioskStepSupplementsComponent : idem.
- **MATCH**.

## Composition snapshot match

- Mobile `buildLineItem` : `sauceIds, supplementIds, composition_summary` (sans `menuChoice`, sans `fritesStyleId`, sans `drinkId`).
- Kiosk OrderItem post-V3.8 cat 310 : sauce + supplements seulement.
- **MATCH**.

## Notes V3.8 critiques

- **Frites_style DB rows présents mais dormants** : items 385-388 carry frites_style extras (cf. F-DBA-3 + F-15 audit). Mobile NE LES RENDS PAS, cohérent V3.8 omelette template (cf. CONTEST-03 adversarial reconciliation).
- **has_menu false** : Ojja `has_menu=0` après migration 060000, mobile cat 5 `has_menu: false` aligned.

## Verdict

**PARITY** ✓ — alignement V3.8 omelette template confirmé. Frites_style dormant respecté.
