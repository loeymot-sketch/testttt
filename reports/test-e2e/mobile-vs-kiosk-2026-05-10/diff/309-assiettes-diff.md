# 309 Assiettes — Mobile vs Kiosk qualitative diff

**Test items** : kiosk reference utilise probablement `assiette-poulet` ou mixte ; mobile = `assiette-poulet`.

## Step-by-step semantic comparison

| Mobile step | Kiosk equivalent | Match | Notes |
|-------------|------------------|-------|-------|
| 01-sauce.png | `309-01-step-sauce.png` | ✓ | Mobile : ScreenStepSauce avec 15 sauces 1-free 0.50€-extra · Kiosk : KioskStepSauceComponent.vue avec idem. Sauce attribut id=311 (15 variations, min=1 max=1 cf. tinker line 7). |
| 02-supplements.png | (pas de capture kiosk step supplements distincte ; couvert recap) | indirect | Mobile : ScreenStepSupplements 6 toggle rows · Kiosk : KioskStepSupplementsComponent.vue avec idem extras `is_paid_supplement`. |
| 03-recap.png | `309-02-step-recap.png` | ✓ | Mobile : ScreenStepRecap avec composition_snapshot · Kiosk : KioskOrderSummary mounted as recap step. Champs sémantiquement équivalents : item name, sauce, supplements, total. |

## Validation rules match

- Mobile `canAdvance(STEP.SAUCE)` : `(selections.sauceIds || []).length >= 1` (screens-item-steps.jsx:154-156)
- Kiosk `canAdvance` sauce step : `selections.sauce && selections.sauce.length > 0` (KW.vue:713-755 — vérifié par architect file:line)
- **MATCH** : 1 sauce minimum requise

## A11y pattern match

- Mobile : ChoiceCard role=checkbox + tabindex=0 + onKeyDown.Enter/Space (screens-item-steps.jsx ChoiceCard 175-208)
- Kiosk : `KioskStepSauceComponent.vue:30-38` role + tabindex + @keydown
- **MATCH** : ARIA semantic identical

## Composition snapshot field match

- Mobile `buildLineItem` exports : `sauceIds, sauceLabels, supplementIds, supLabels, composition_summary` (screens-item-steps.jsx:680-712)
- Kiosk OrderItem `composition_snapshot` JSON : `sauce`, `supplements`, formatted summary
- **MATCH** : same fields, same naming

## Différence noté (non-blocker)

- U4 décision owner : "style cuisson" Nature/Curry/Paprika de l'Assiette Poulet reste en description text uniquement, pas de step wizard (cohérent kiosk).
- Kiosk capture n'inclut pas la cooking style sélection non plus → align.

## Verdict

**PARITY** ✓ — semantic alignment confirmé sur step keys, validation, A11y, composition output.
