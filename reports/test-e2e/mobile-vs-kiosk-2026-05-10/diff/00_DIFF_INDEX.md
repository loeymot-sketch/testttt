# 00 — DIFF INDEX — Mobile vs Kiosk qualitative comparison

Date : 2026-05-10/11
Scope : comparaison qualitative (semantic step-by-step) mobile wizard vs kiosk wizard
sur les 4 catégories disposant de screenshots kiosk en référence.

## Sources

| Catégorie | Kiosk ref (existing) | Mobile capture (this audit) |
|-----------|----------------------|------------------------------|
| 309 Assiettes | `tests/e2e/__screenshots__/test-e2e-borne-B/309-01-step-sauce.png`, `309-02-step-recap.png` | `captures/04-assiettes/01-sauce.png`..`03-recap.png` |
| 310 Ojja | `tests/e2e/__screenshots__/test-e2e-borne-B/310-01-step-sauce.png`, `310-02-step-recap.png` | `captures/05-ojja/01-sauce.png`..`03-recap.png` |
| 311 Omelettes | `tests/e2e/__screenshots__/test-e2e-borne-B/311-01-step-sauce.png`, `311-02-step-recap.png` | `captures/06-omelettes/01-sauce.png`..`03-recap.png` |
| 314 Menus Enfants | `tests/e2e/__screenshots__/test-e2e-borne-B/314-01-step-sauce.png`, `314-02-step-recap.png` | `captures/09-menus-enfants/01-sauce.png`..`02-recap.png` |

Catégories sans kiosk reference (audit indirect via DB+code source) :
- Cat 1 Tacos, 2 Sandwichs, 3 Burgers : pas de PNG kiosk capturé, parity vérifiée via KW.vue template
- Cat 312 Salades, 313 Wings, 315 Frites : pas de PNG kiosk capturé
- Cat 316 Desserts, 317 Boissons : direct-add, pas de wizard

## Limitations

- **Pixel diff non-faisable** : viewport mobile 390×844 ≠ kiosk borne 1080×1920. La comparaison est sémantique (step keys, validation rules, A11y patterns, composition snapshot fields).
- **Captures kiosk pas re-générées** : la version actuelle des PNGs `test-e2e-borne-B/` est suffisante pour la comparaison semantic.
- **Aucune extraction pixel-level brightness/contrast diff** entre mobile et kiosk — différence de viewport rend cette mesure inutile.

## Verdict global

| Catégorie | Step keys match | Validation rules match | Composition output match | Verdict |
|-----------|-----------------|------------------------|---------------------------|---------|
| 309 Assiettes | ✓ sauce + supplements + recap | ✓ sauce.min=1, supp optional | ✓ composition_snapshot align | **PARITY** |
| 310 Ojja | ✓ omelette template | ✓ idem | ✓ idem | **PARITY** |
| 311 Omelettes | ✓ omelette template | ✓ idem | ✓ idem | **PARITY** |
| 314 Menus Enfants | ✓ omelette template (post-D2 has_sauce flip) | ✓ sauce step now offered (était false mobile pre-audit) | ✓ idem | **PARITY** (après owner-gate D2) |

Détail dans les fichiers par catégorie.

## Notes

- Pour les catégories sans kiosk reference, la parity est garantie par :
  1. Mobile data layer aligné DB extraction (cf. 02_dba.md tinker DB live)
  2. Mobile state machine mirror KW.vue template-driven (cf. 01_architect.md mapping)
  3. ChoiceCard ARIA roles mirror KioskStep* role+tabindex+@keydown (cf. 05_a11y.md)
- L'audit cross-agent 6 sub-agents + 15 contestations adversariales (06_adversarial.md) constitue la garantie semantic — plus rigoureuse qu'un pixel-diff pour ce scope.
