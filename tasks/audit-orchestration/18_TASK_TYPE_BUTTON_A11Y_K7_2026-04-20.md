# T18 — `type="button"` audit + a11y K-7 (motion tokens, reduced-motion, i18n bidir)

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

K-7 a livré : motion tokens 280 ms, `useKioskMotion`, whitelist `ui.*` ×3, reduced-motion
gated ×11 surfaces, empty-state Upsell, i18n parité bidir FR⇆EN⇆AR, audit `type="button"`
en CI, cleanup `aria-hidden`. Le diff récent ajoute `type="button"` à `KioskCartComponent`
(K-7 incomplet ?).

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Recherche tous les `<button` SANS `type=` dans Vue kiosk :
   `rg -n "<button(\s|>)(?![^>]*type=)" resources/js/components/frontend/kiosk -g '!*.spec.js'`
2) Vérifier si CI rule K-7 audit `type="button"` existe :
   - .github/workflows/*.yml
   - tests/js/audit_button_type.spec.js (s'il existe)
   - bin/audit-button-type.sh
3) Motion tokens : `--kiosk-duration-fast`, `--kiosk-duration-base`, `--kiosk-ease-standard`
   définis où ?
   - resources/css/kiosk-tokens.css
   - resources/sass/kiosk/_tokens.scss
4) Composable `useKioskMotion` :
   - resources/js/composables/useKioskMotion.js
   - prefers-reduced-motion gate ?
5) Reduced-motion gated × 11 surfaces : recherche `prefers-reduced-motion` ou `useKioskMotion`
   dans les composants kiosk.
6) i18n parité FR/EN/AR :
   - resources/js/languages/fr.json, en.json, ar.json
   - script de comparaison clés (ou diff manuel) → liste des clés présentes dans 1 langue
     mais pas les autres.
7) Empty state Upsell présent ?
8) `aria-hidden` cleanup : `rg "aria-hidden" resources/js/components/frontend/kiosk` →
   pas de violation `aria-hidden=true` sur élément focusable.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK18_TYPE_BUTTON_A11Y_K7_2026-04-20.md
```

## Lecture obligatoire

- `tasks/k-hardening/PLAN_K7_UX_SPLASH_POLISH_2026-04-18.md`
- `reports/execution/VERIFY_K7_UX_SPLASH_POLISH_2026-04-18.md`
- `resources/js/components/frontend/kiosk/*.vue`
- `resources/js/languages/*.json`

## Checklist multi-points

- [ ] V1. 0 `<button>` kiosk sans `type=` (CI ou rg)
- [ ] V2. Audit CI `type="button"` actif
- [ ] V3. Motion tokens définis + utilisés
- [ ] V4. `useKioskMotion` respecte `prefers-reduced-motion`
- [ ] V5. ≥ 11 surfaces avec gate reduced-motion
- [ ] V6. Parité i18n FR/EN/AR (delta = 0)
- [ ] V7. Empty-state Upsell présent
- [ ] V8. Aucune violation `aria-hidden` sur focusable

## Critères PASS / FAIL

- **PASS** : 8 V cochées.
- **FAIL** : ≥ 1 régression a11y / i18n / motion.

## Output

`reports/audit-orchestration/REPORT_TASK18_TYPE_BUTTON_A11Y_K7_2026-04-20.md`

## Si FAIL → action

→ T18b `generalPurpose` : patch composants + ajouter rule CI si absente.
