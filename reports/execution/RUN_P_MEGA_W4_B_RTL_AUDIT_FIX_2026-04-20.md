# RUN — P_MEGA_W4_B_RTL_AUDIT_FIX_2026-04-20 (P-MEGA-10)

`EXECUTE_DELEGATION: foodking-routine-implementer`

## Phase AUDIT (lecture)

### Mécanisme `dir` / `lang`

- `resources/js/i18n.js` : `setDocumentDirection(locale)` + `watch(() => i18n.global.locale, …)` — **OK** (pas de changement requis).
- `KioskAppComponent.vue` : `_wireA11yWatchers` → `document.documentElement` `lang` + `dir` depuis `kioskSettings.locale` — **OK** (câblage confirmé, pas de `dir` sur la racine `.kiosk-app` ; SSOT = `<html>`).

### Composants audités (liste demandée)

| Composant | Défauts CRITIQUES | MOYENS | Template |
|-----------|-------------------|--------|----------|
| KioskAppComponent.vue | `left: 50%` ×3 (indicateurs + barre panier) — préférer axe logique | — | Ripple : style inline `left` px (voir finding) |
| KioskCartComponent.vue | — | `align-items: flex-end` sur contrôles ligne | — |
| KioskWizardComponent.vue | Anim `step-slide` translateX non miroité RTL | — | — |
| KioskStepViande/Sauce/Garnitures/Supplements | Aucun padding/margin physique pertinent | — | — |
| KioskPaymentComponent.vue | Aucun | — | — |
| KioskOrderSummaryComponent.vue | — | Step +/- : ordre visuel en RTL | — |
| KioskCategoriesComponent.vue | — | `align-items: flex-end` sous `.kiosk-sidebar-name` | Déjà `inset-inline-*` sur badges grille |
| ds/*.vue | KsModal footer + KsA11y backdrop : `justify-content: flex-end` | Autres atoms déjà cohérents | — |

**Synthèse comptage (schéma mission)** : composants ciblés par fichier **23** (incl. 12 fichiers `ds/*.vue` + `i18n.js`).  
Défauts **CRITIQUES** traités par patch : **3** (`left: 50%` → `inset-inline-start: 50%`) + **1** animation (step-slide, **2** règles `[dir="rtl"]`).  
Défauts **MOYENS** : **4** (`flex-end` → `end`, `direction: ltr` sur sélecteurs quantité ×2).  
Défauts **TEMPLATE** (flèches Fa, etc.) : **0** nouveau hors déjà traité (ex. `›` barre panier via `[dir="rtl"]` existant).

### FINDING_W4B_JS_POSITIONAL_BLOCK

- **Fichier** : `KioskAppComponent.vue`
- **Nature** : `rippleStyle` retourne `{ left: ripple.x + 'px', top: ripple.y + 'px' }` pour `.kiosk-touch-ripple` (positionnement depuis coordonnées tactiles).
- **Décision** : **pas de refactor JS** (hors scope routine). Le ripple reste correct visuellement (coords viewport) ; un cycle **complex** pourrait unifier avec propriétés logiques + calcul si besoin un jour.

## Phase FIX (CSS additif / propriétés logiques)

| Fichier | Modification |
|---------|----------------|
| KioskAppComponent.vue | `inset-inline-start: 50%` sur `.kiosk-offline-indicator`, `.kiosk-abandoned-indicator`, `.kiosk-cart-bar` |
| KioskWizardComponent.vue | `[dir="rtl"]` pour `.step-slide-enter-from` / `.step-slide-leave-to` (miroir translateX) |
| KioskCartComponent.vue | `align-items: end` ; `[dir="rtl"] .kiosk-qty-ctrl { direction: ltr; }` |
| KioskOrderSummaryComponent.vue | `[dir="rtl"] .kiosk-qty-controls { direction: ltr; }` |
| KioskCategoriesComponent.vue | `align-items: end` sur `.kiosk-sidebar-name` |
| ds/KsModal.vue | `justify-content: end` sur `.ks-modal__footer` |
| ds/KsA11ySettings.vue | `justify-content: end` sur `.ks-a11y-backdrop` |

**Invariant 1** : aucune modification d’affichage des montants (pas de `toLocaleString`, pas de réordonnancement des bindings prix).

## Phase VÉRIFICATION

- Vitest : `tests/js/kioskRtl.spec.js` (4 cas : dir rtl, dir ltr retour, lang ar, présence `kiosk` dans `ar.json`). Suite globale : **554/554** (550 baseline + 4).
- Post-fix : `npm run i18n:audit` — **exit 1** inchangé vs dette W4.A (clés `en` manquantes globales, pas de nouvelle clé introduite par ce cycle ; aucune modification des fichiers `resources/js/languages/*.json`).

## Playwright

- **Deferred** (inchangé vs plan : optionnel si infra active).
