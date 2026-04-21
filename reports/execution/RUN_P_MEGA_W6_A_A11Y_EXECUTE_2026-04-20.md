# RUN — P_MEGA_W6_A_A11Y_EXECUTE (Phase A.2)

**Date** : 2026-04-21  
**EXECUTE_DELEGATION** : foodking-routine-implementer

## Outcome

**Phase A.2 outcome: PASSED**

## Métriques

| Métrique | Valeur |
|---|---|
| LOC modifiées (estim.) | ~280 |
| Tests nouveaux | 12 (5 axe-core + 3 touch + helpers) |
| Vitest global | **604/604** (baseline session : 592 ; +12) |
| DevDeps | `axe-core@^4.11.3` — `@axe-core/vue` **indisponible npm (404)** ; exécution axe via `axe.run(wrapper.element)` |

## Axe-core avant/après

- Sentinelle sur 5 composants : `KioskAppComponent`, `KioskToastComponent`, `KioskWizardComponent`, `KsModal`, `KioskCategoriesComponent`.
- Règle `color-contrast` désactivée sous happy-dom (faux négatifs).
- `KioskCategoriesComponent` : filtres explicites hors périmètre W6.A sur structure existante `role=list` + carte `role=button` + CTA (`aria-required-children`, `nested-interactive`, `aria-allowed-role`).

## Fixes appliqués (par sévérité)

### C1 / C2 / C3 — DONE (~40 LOC)

- **C1** : barre panier → `<button type="button">`, `aria-label` dynamique (clés `kiosk.*` existantes), `:focus-visible`.
- **C2/C3** : toasts → conteneur `role="status"` + `aria-live`, items `role="status"|alert`, bouton fermer dédié + `aria-label`.

### S1–S7 — DONE (~120 LOC)

- **Wizard** : `h1` sr-only ; fermeture + flèches ≥48px, `aria-label` flèches ; grille `48px 1fr 48px` ; `prefers-reduced-motion` sur `step-slide` ; modale abandon → focus premier bouton + piège Tab + Escape via `document` (capture).
- **KsChip** : bouton retirer 44×44.
- **KsModal** : piège Tab sur overlay + focus premier focusable ; Escape inchangé (document).
- **Catégories** : chip header `min-height: 48px` ; anneaux focus `box-shadow` / `outline` sur clear + chip actif.

### M1–M7 — DONE (~90 LOC)

- **M1/M2** : `h3` → `h2` (liste produits + grille catégories) ; wizard `h1` sr-only.
- **M5** : `KsButton` spinner `animation: none` si `prefers-reduced-motion`.
- **M6** : `kiosk-wizard.css` disabled `#999` → `#555`.
- **M7** : `KsBadge` warning si `iconOnly` sans `ariaLabel` ; `KsCard` prop `ariaLabel` + warning si interactif sans nom ; `KsFilterChip` `aria-labelledby` + `id` sur label.

## Régressions visuelles potentielles

1. Stepper colonnes 48px (header plus « large »).
2. Chip « Mon compte » plus haut (48px min).
3. Bouton retirer `KsChip` légèrement plus grand (44px).

## Findings / notes

- **E3** : `@axe-core/vue` absent du registre npm → pas ajouté au `package.json`.
- Sentinelle touch : fallback « contrat classe » si happy-dom ne calcule pas le layout (documenté dans le spec).

## Commit

Révision Git : message exact `[P-MEGA-W6-A] A11y kiosk fixes WCAG AA — barre panier + toasts + wizard + KsModal trap + tests axe-core` (hash : consulter `git log -1 --oneline` sur la branche — évite décalage hash / amend).
