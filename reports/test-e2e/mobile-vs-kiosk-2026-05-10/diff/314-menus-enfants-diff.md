# 314 Menus Enfants — Mobile vs Kiosk qualitative diff

**Test item mobile** : `menu-cheese-enfant` (cat 9 mobile = cat 314 DB).
**Template** : omelette (post-V3.8 migration 070000 align).
**Décision owner-gate D2** : has_sauce flip false → true.

## Step-by-step semantic comparison

| Mobile step | Kiosk equivalent | Match | Notes |
|-------------|------------------|-------|-------|
| 01-sauce.png | `314-01-step-sauce.png` | ✓ (après D2) | Sauce 15 options. **Pre-audit mobile avait `has_sauce: false`, le kiosk offrait sauce** → mismatch confirmé par DBA F-DBA-2. D2 owner-gate cleared : mobile flip à `has_sauce: true`. Maintenant aligned. |
| 02-recap.png | `314-02-step-recap.png` | ✓ | Composition. |

## Validation + A11y + Composition

- Mobile cat 9 items 901/902 `has_sauce: true` + `has_supplements: false` (Capri-Sun + frites incluses, pas de step supplements).
- Kiosk : sauce attribute 311 attached items 400/401 (verified tinker).
- **MATCH POST-D2**.

## Note D2 critique

Pré-audit mobile = `has_sauce: false` était une divergence cross-surface : le kiosk borne offrait sauce step sur menu enfant, le mobile non. D2 owner-gate (recommandation A "DB SSOT") rétablit la parity.

## Verdict

**PARITY** ✓ (post owner-gate D2 cleared 2026-05-10)
