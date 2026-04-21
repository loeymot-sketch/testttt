# RUN — P_MEGA_W4_B_RTL_AUDIT_FIX_2026-04-20 (P-MEGA-10)

`EXECUTE_DELEGATION: foodking-routine-implementer`

## Phase AUDIT (lecture)

| Composant | CRITIQUE | MOYEN | TEMPLATE | Notes |
|-----------|----------|-------|----------|-------|
| KioskAppComponent.vue | `left:0` admin trigger ; transitions slide-left/right sens fixe LTR ; chevron barre panier | centrages `left:50%` OK (symétriques) | `›` barre panier | Ripple `:style="{ left, top }` — **JS positionnel** (voir ci-dessous) |
| KioskCartComponent.vue | — | — | — | Uniquement `text-align: center` (neutre) |
| KioskWizardComponent.vue | `right` close + index step | — | — | — |
| KioskStepViande/Sauce/Garnitures/Supplements | `right` badges/actions | — | — | — |
| KioskPaymentComponent.vue | `border-top-color` spinner (processing) | — | — | Aligné `border-block-start` |
| KioskOrderSummaryComponent.vue | `padding-left` boisson | — | — | — |
| KioskCategoriesComponent.vue | `margin-left` ; `border-right` sidebar + abandon ; `left`/`right` badges/CTA ; `left`/`right` bottom bar | — | — | — |
| ds/KsA11ySettings | `text-align:left` ; `margin-left` ; thumb `left` | — | — | Switch : thumb ON en `inset-inline-end` |
| ds/KsButton | `border-right-color` spinner | — | — | — |
| ds/KsStepper | `right` check | — | — | — |
| ds/KsVirtualKeyboard | `left`/`right` 0 | — | — | — |
| ds/KsChip, KsAllergenBadge, KsModal | — | — | — | Aucun match directionnel dans `<style>` |
| resources/js/i18n.js | — | — | — | Pas de `watch` sur `locale` → `dir` / `lang` non synchronisés si assignation directe de `locale` |

**Totaux (périmètre audité)** : 16 fichiers Vue + i18n.js — **CRITIQUES ~28 occurrences** corrigées par logical properties / règles `[dir=rtl]` — **MOYENS** documentés (nombres : pas de changement binding prix).

### FINDING_W4B_JS_POSITIONAL_BLOCK

- **Fichier** : `KioskAppComponent.vue` — feedback tactile `.kiosk-touch-ripple` : `rippleStyle` calcule `left` / `top` en pixels depuis l’événement pointer (l. ~197). **Non corrigeable en CSS additif seul** ; laisser tel quel pour ce cycle ; un refactor coordonnées logiques / `inset` serait hors scope routine.

## Phase FIX

- **Composants modifiés (styles)** : KioskAppComponent, KioskOrderSummaryComponent, KioskCategoriesComponent, KioskWizardComponent, KioskPaymentComponent, 4 steps (viande, sauce, garnitures, supplements), KsA11ySettings, KsButton, KsStepper, KsVirtualKeyboard.
- **i18n.js** : `watch(() => locale, setDocumentDirection)` ; fallback `loadMessages()` si `require.context` absent (Vitest / stub Vite) vers `globalThis.require.context` (polyfill setup).
- **Tests** : `tests/js/kioskRtl.spec.js` (4 cas) ; `tests/js/kioskRtl-require-context-polyfill.js` ; `vitest.config.mjs` → `setupFiles` pour le polyfill.
- **Clés i18n** : **aucune** nouvelle clé (0 ajout fr/en/ar/de/bn).

## Vérification

- Vitest global : **554/554** (550 baseline + 4 RTL).
- `npm run i18n:audit` : même profil de dette qu’avant W4.B (fr/en/ar counts inchangés côté intention — **aucune nouvelle clé** introduite par ce cycle).

## Risque résiduel

- Transitions page hors `slide-left`/`slide-right` non revues ici.
- FINDING ripple JS : inchangé jusqu’à cycle dédié.
