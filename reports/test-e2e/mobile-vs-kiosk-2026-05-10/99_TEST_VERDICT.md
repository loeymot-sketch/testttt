# 99 — TEST VERDICT — E2E mobile wizard suite 2026-05-10

Date : 2026-05-10
Suite : `lc-e2e-wizard-suite.mjs` (Playwright, viewport iPhone 14 390×844)
Audit white-on-white : `lc-wow-audit.mjs` (alpha-blending PNG sweep)

## Résumé

| Métrique | Valeur |
|----------|--------|
| Catégories testées | 12 / 13 (cat 13 Suppléments couvert via item-sauce-sup) |
| Steps capturés | 38 PNGs total |
| Verdict | **12 / 12 GO** ✓ |
| Pricing assertions | **12 / 12 PASS** (totaux affichés == attendus) |
| Raw label hits | **0** (Label.X / kiosk.X / 0undefined / NaN€) |
| White-on-white offenders | **0 / 38 PNGs** (>95% blanc) |
| Page errors | **0** |
| Console errors (filtrés noise) | **0** (404 image-slots.state.json filtré comme bruit pré-existant) |

## Matrice GO/NO-GO par catégorie

| ID | Slug item testé | Cat | Steps capturés | Total affiché | Total attendu | Verdict | Notes |
|----|-----------------|-----|----------------|---------------|---------------|---------|-------|
| 1 | tacos-xxl | nos-tacos | 6/6 | 12,50 € | 12,50 € | ✓ GO | Wizard tacos template OK |
| 2 | le-terminator | nos-sandwichs | 6/6 | 9,00 € | 9,00 € | ✓ GO | 2 viandes step OK |
| 3 | cheese-burger | nos-burgers | 5/5 | 6,00 € | 6,00 € | ✓ GO | Pas de step viandes (template burger) |
| 4 | assiette-poulet | nos-assiettes | 3/3 | 12,50 € | 12,50 € | ✓ GO | sauce + supplements (U4 cooking style = description) |
| 5 | ojja-merguez | ojja | 3/3 | 13,50 € | 13,50 € | ✓ GO | Template omelette (V3.8) — pas de menu/frites_style |
| 6 | omelette-fromage | omelettes | 3/3 | 8,50 € | 8,50 € | ✓ GO | Template omelette OK |
| 7 | salade-royale | nos-salades | 3/3 | 7,50 € | 7,50 € | ✓ GO | D1 simplified scope (sauce + supplements) |
| 8 | wings-12 | chicken-tenders | 3/3 | 10,50 € | 10,50 € | ✓ GO | U2 confirmed: 15 sauces génériques (pas de BBQ/Nashville) |
| 9 | menu-cheese-enfant | nos-menus-enfants | 2/2 | 6,00 € | 6,00 € | ✓ GO | D2 has_sauce flipped to true |
| 10 | frites-grande | frites-accompagnements | 2/2 | 4,00 € | 4,00 € | ✓ GO | F-03 frites_style step (Nature default OK) |
| 11 | tiramisu | nos-desserts | 1 (direct) | 3,80 € | 3,80 € | ✓ GO | Direct add (no wizard) |
| 12 | coca | nos-boissons | 1 (direct) | 1,50 € | 1,50 € | ✓ GO | Direct add (no wizard) |
| 13 | item-sauce-sup | supplements | (couvert via cat 1 sauce step) | — | — | indirect | Pas testé en standalone — sauce seule confirmée via cat 13 sauce sup item |

## Cross-cat invariants validés

✅ **0 console error** (hors 404 image-slots.state.json, bruit debug pré-existant noté en audit Adv)
✅ **0 page error** (no React unhandled exception)
✅ **0 raw label** (Label.X / kiosk.X. / 0undefined / NaN€) sur tous DOM bodies
✅ **0 white-on-white offender** sur 38 PNGs scannés (alpha-blending pixel sweep <95% near-white)
✅ **A11y baseline**: tous les ChoiceCard/ToggleRow ont `role + tabindex + onKeyDown` (vérifié via inspection screens-item-steps.jsx)
✅ **Focus styles** `:focus-visible` actifs (mobile/styles.css:36-45) — outline orange 3px

## Pricing combo validation supplémentaire (smoke test antérieur)

Tacos XXL combo complet (validation dans `lc-wizard-smoke.mjs`):
- Base : 12,50 €
- 4 viandes au choix : 0 € (Mz/Kefta/Cordon/Tenders)
- 2 sauces (Ketchup gratuit + Mayonnaise +0,50 €) : 0,50 €
- Œuf supplément : 1,00 €
- Menu complet (Frites + Boisson) : 3,00 €
- Style frites Cheddar fondu : 1,00 €
- Sauce frites Barbecue (1ʳᵉ gratuite) : 0,00 €

**Total = 18,00 €** (validé dans recap step 9/9)

## Comparaison kiosk reference

Kiosk screenshots disponibles (existing) :
- `tests/e2e/__screenshots__/test-e2e-borne-A/01-13-cat-318-supplements.{png,dom.html}` (idle + cat browsing)
- `tests/e2e/__screenshots__/test-e2e-borne-B/15-16-cart*.png` + `309-310-311-312-313-{step}.png` (wizard flows cats 309 assiettes, 310 ojja, 311 omelettes, 312 salades, 313 wings)

La comparaison côte-à-côte mobile vs kiosk est out-of-scope formelle (architectural diff plutôt que pixel diff — viewport mobile 390×844 ≠ kiosk borne 1080×1920). Mais :
- Step keys mobile mirroir kiosk (vérifié 06_adversarial.md C-08 : 7 canonical types matched STEP_KEY_REGISTRY KW.vue:301-325)
- Validation rules mirroir kiosk (canAdvance() screens-item-steps.jsx:152-168 mirrors KW.vue:713-755 canAdvance)
- ARIA roles mirroir kiosk (role=radio/checkbox + tabindex=0 + onKeyDown — kiosk pattern KioskStepFritesStyleComponent.vue:11-17)

## Output structure

```
reports/test-e2e/mobile-vs-kiosk-2026-05-10/
├── 99_TEST_VERDICT.md (ce fichier)
└── captures/
    ├── _RESULTS.json (full E2E results)
    ├── _WOW_AUDIT.json (38 PNGs alpha-blending audit)
    ├── 01-tacos/01-viandes.png … 06-recap.png
    ├── 02-sandwichs/01-viandes.png … 06-recap.png
    ├── 03-burgers/01-sauce.png … 05-recap.png
    ├── 04-assiettes/01-sauce.png … 03-recap.png
    ├── 05-ojja/01-sauce.png … 03-recap.png
    ├── 06-omelettes/01-sauce.png … 03-recap.png
    ├── 07-salades/01-sauce.png … 03-recap.png
    ├── 08-snacking/01-sauce.png … 03-recap.png
    ├── 09-menus-enfants/01-sauce.png … 02-recap.png
    ├── 10-frites/01-fritesStyle.png 02-recap.png
    ├── 11-desserts/01-direct-add.png
    └── 12-boissons/01-direct-add.png
```

## Verdict global

**E2E SUITE : 12/12 GO ✓**

Le wizard mobile multi-page est techniquement et visuellement validé sur les 12 catégories. Tous les invariants critiques passent (totaux, raw labels, white-on-white, console errors, pricing combos). Aucun blocker P0/P1 ouvert post-tests.

Prochaine étape : commit-5 + BRAIN.md update + Graphiti episode.
