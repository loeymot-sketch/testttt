# PLAN — CV1-KIOSK-VISUAL-REDESIGN-001

**Refonte visuelle complète de la borne FoodKing — direction "Bold Appétissant" — 4 vagues, light + dark, zero pricing-logic touched**

| Champ | Valeur |
|---|---|
| Cycle ID | `CV1-KIOSK-VISUAL-REDESIGN-001` |
| Date plan | 2026-05-02 |
| Auteur plan | Claude (Anthropic, IDE Cursor, modèle `claude-opus-4-7`, effort `xhigh`) |
| Périmètre | Refonte visuelle 100 % surface borne client (idle → catégories → wizard → panier → paiement → confirmation + erreurs) |
| Direction esthétique | Bold appétissant (boussole : O'Tacos / KFC / Five Guys premium) |
| Mode couleur | Light + Dark (toggle a11y manuel + auto-luminosité possible en V2) |
| Stratégie livraison | Par vague — V1 foundations / V2 attraction-catalogue / V3 wizard / V4 checkout |
| Audit source | `reports/audit/_TERMINAL_CONTEXT_BRIEF.md` (synthèse exploration kiosk 24 écrans) |
| Maquette de prévisualisation | `reports/design/KIOSK_REDESIGN_BOLD_PREVIEW_2026-05-02.html` |
| Frozen zones touchées | **Aucune.** `KioskPaymentComponent.vue` modifié visuellement uniquement (pas d'état/props/computed — symmetry POS préservée). |
| Gates humains anticipés | V1 : aucun. V2 : aucun. V3 : aucun. V4 : aucun (skin only sur paiement). Voir §5 conditions de déclenchement. |
| Estimation | V1 ≈ 1 sprint / V2 ≈ 1 sprint / V3 ≈ 2 sprints / V4 ≈ 1 sprint — total ≈ 5 sprints |
| Effort cumulé | XL |

---

## TASK_ID
CV1-KIOSK-VISUAL-REDESIGN-001

## PRIMARY_EXECUTION_MODEL
gpt-5.5-pro (codex-extension) pour V2-V4 ; Claude orchestrator pour V1 foundations (CSS tokens + design tokens doc — admis comme orchestration sous `.cursor/rules/global.mdc § Token Discipline`)

## REASONING_EFFORT
xhigh

## EXECUTION_TIER
**complex** — 4 vagues, ~30 fichiers, motion + dark mode + a11y AAA + cross-cutting tokens. Aucune vague n'est éligible "routine" car toutes touchent des composants Kiosk avec contrats UX critiques (composition live wizard, paiement symétrie POS, erreurs métier).

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: codex-extension
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PENDING

---

## 0. Lecture rapide pour Codex / Cursor / Claude audit

**But :** doter la borne FoodKing d'une identité visuelle premium qui soutient la conversion (rapidité de choix, désir alimentaire, confiance) sans toucher la logique métier (prix backend, statuts commande, file offline, symmetry paiement).

**Quatre clés du plan :**

1. **V1 = Fondations.** On installe le système (palette bold appétissante light+dark, typographie display+body, espacement étendu, ombres warm, motion library, atoms `ds/` enrichis). Aucun écran client n'est encore touché. Risque ≈ nul.
2. **V2 = Attraction & navigation.** Idle screen cinématique + grille catalogue éditoriale + carousel promos premium + shell `KioskAppComponent` (timer panier, theme toggle, transitions). C'est la première impression client.
3. **V3 = Wizard cœur de conversion.** Refonte complète de la coquille `KioskWizardComponent` + 8 step components + récap. Hero strip produit, composition live "stack visuel", cards d'option avec photographie héro, transitions spring entre étapes.
4. **V4 = Checkout & confirmation.** Panier premium, upsell cinématique, paiement (skin only — gate symétrie POS respectée), waiting cuisine, confirmation cérémoniale, erreurs claires.

**Fondations existantes (à reprendre, NE PAS recréer) :**
- Tokens : `resources/css/kiosk/tokens.css` (`--kiosk-*` complet) + `tokens-aaa.css` + `tokens-pmr.css` + `foundations/cv1-tokens.css` (`--cv1-*` partiel — pas encore importé dans `app.css`).
- Atoms `resources/js/components/frontend/kiosk/ds/` : `KsButton`, `KsCard`, `KsChip`, `KsBadge`, `KsAllergenBadge`, `KsFilterChip`, `KsModal`, `KsStepper`, `KsPriceLine`, `KsConsentModal`, `KsVirtualKeyboard`, `KsA11ySettings`.
- Composables a11y : `useKioskA11y`, `useKioskSpeech`, helper `resources/js/helpers/a11y/announcer.js` (untracked, en cours CV1-LIFECYCLE-UX-001).
- Convention `data-kiosk-contrast="aaa"` + `data-kiosk-reduced-motion="true"` propagés sur `<html>`.
- Stack typographique chargée : Inter (latin), Noto Sans Arabic (RTL), JetBrains Mono ; Rubik / Public Sans (admin).

**Règles d'or de ce cycle :**
- **Zéro logique de prix nouvelle côté Vue.** Le wizard continue d'afficher `formatPrice(serverValue)` issu du `createKioskPricingPreview` backend. Toute amélioration visuelle d'un total reste un **affichage**, pas un calcul.
- **`KioskPaymentComponent.vue` = SKIN ONLY.** Couleurs / typo / layout visuel autorisés ; props / state / computed / events / refs **gelés** (gate symmetry POS active).
- **Coordination CV1 en cours.** Les missions `CV1-LIFECYCLE-UX-001` (tasks 1.3 / 1.7 / 1.9) et `CV1-CATALOG-CONVERGENCE-001` touchent le même domaine. `CatalogChangeToastComponent.vue` est en cours de complétion Codex — V2 doit consommer la sortie finale, pas la réécrire.
- **A11y AA + AAA non-négociable.** Chaque écran refondu passe `axe-core` (Vitest + Playwright) avant audit. Touch ≥ 44×44 (V1), ≥ 56×56 (variant CTA hero). Focus ring 3px. `prefers-reduced-motion` neutralise toutes les animations. Synthèse vocale (`useKioskSpeech`) testée si activée.

---

## 1. Vision & direction esthétique "Bold Appétissant"

### 1.1 Boussole

L'inspiration est un fast-food premium qui assume sa générosité visuelle : couleurs chaudes saturées, photographie héro plein cadre, typographie display avec caractère, motion confiante mais courte. Pas de design générique "AI slop" : pas de gradient violet, pas de typographie Inter en titre, pas de cartes flottantes vides. Chaque écran a **un point focal fort** (héro, total, CTA principal) et un **rythme typographique** affirmé entre display et body.

### 1.2 Manifeste visuel

| Principe | Traduction |
|---|---|
| **Photographie d'abord** | Toute card produit a une photo héro (cropping carré ou 4:5). Si la photo manque : fallback gradient warm + emoji catégorie (jamais carré gris). |
| **Hiérarchie typographique radicale** | Display (Fraunces / Recoleta) pour titres / totaux / brand mark. Body (Inter) pour libellés / corps. Mono (JetBrains) pour montants détaillés (alignement chiffres). |
| **Couleurs chaudes, jamais grises pures** | Tous les blancs et noirs ont une teinte warm (off-white `#FFF8F1`, near-black `#1A1410`). Le pur gris est interdit. |
| **Élévation dramatique** | Les cards interactives ont une ombre warm prononcée (`shadow-cta` étendue). Hover/focus : translation Y -2px + ombre étendue. |
| **Motion confiante** | Transitions step-slide ≤ 320 ms avec easing spring (`cubic-bezier(0.34, 1.56, 0.64, 1)`) sur sélections. Aucune anim > 500 ms hors confirmation cérémoniale. |
| **Brand red sans peur** | Rouge primaire saturé pour CTA principal (`#E63946`). Pas de bouton "tertiaire" gris fade — soit primary, soit ghost. |
| **Total comme évènement** | Le total courant est en Fraunces, taille hero, même rythme typographique que le brand mark. Le client doit voir le prix comme une réponse, pas une note de bas de page. |
| **Composition live = stack visuel** | Dans le wizard, l'aperçu de la composition est un visuel "construit" (chips empilés avec sépas warm) plutôt qu'une simple liste textuelle. |

### 1.3 Système typographique

| Rôle | Police | Poids | Échelle | Usage |
|---|---|---|---|---|
| Display | **Fraunces** (Google Fonts, free) variable | 600-900 | 72px → 144px (hero) | Brand mark, titres écran, totaux, numéro commande |
| Body | **Inter** (déjà chargée) | 400-700 | 14px → 24px | Libellés, descriptions, hints |
| Mono | **JetBrains Mono** (déjà chargée) | 500-700 | 14px → 18px | Montants tabulaires, codes (commande, ticket) |
| Arabic | **Noto Sans Arabic** (déjà chargée) | 400-700 | échelle parallèle | RTL — toutes surfaces |

> **Justification Fraunces** : licence SIL OFL (libre), variable font (un seul fichier woff2 ≤ 80 KB), dispose des opentypes "soft" / "loud" qui alignent parfaitement avec "appétissant + confiant". Alternative de fallback : **Recoleta** (Indian Type Foundry, payante) ou **DM Serif Display** (Google, free). Le plan retient Fraunces V1, ré-évaluable V3 si la lecture allergènes en arabe pose problème.

### 1.4 Palette V1 (proposition committée — révisable au plan review)

#### Light mode

| Token | Valeur | Usage |
|---|---|---|
| `--kiosk-bold-bg` | `#FFF8F1` | Fond principal (warm off-white, jamais pur blanc) |
| `--kiosk-bold-surface` | `#FFFFFF` | Cards et modals élevés |
| `--kiosk-bold-surface-subtle` | `#FBF2E6` | Sections secondaires (sidebar catégories) |
| `--kiosk-bold-surface-strong` | `#1A1410` | Footer dramatique, ribbons promo |
| `--kiosk-bold-primary` | `#E63946` | CTA principal, brand mark, total |
| `--kiosk-bold-primary-hover` | `#C82333` | Hover/active CTA |
| `--kiosk-bold-accent` | `#FFB627` | Badges promo, points fidélité, highlights |
| `--kiosk-bold-text-primary` | `#1A1410` | Texte principal (warm near-black) |
| `--kiosk-bold-text-secondary` | `#6B5D52` | Texte secondaire (warm taupe) |
| `--kiosk-bold-border` | `#E8DDD4` | Bordures par défaut warm |
| `--kiosk-bold-border-strong` | `#1A1410` | Bordures cards sélectionnées |
| `--kiosk-bold-success` | `#2D6A4F` | Confirmation, dispo |
| `--kiosk-bold-warning` | `#F59E0B` | Alertes warm |
| `--kiosk-bold-danger` | `#9A0E2A` | Erreurs (séparé du brand pour éviter confusion) |

#### Dark mode

| Token | Valeur | Usage |
|---|---|---|
| `--kiosk-bold-bg` | `#0E0A07` | Fond principal warm dark |
| `--kiosk-bold-surface` | `#1F1611` | Cards élevés |
| `--kiosk-bold-surface-subtle` | `#150F0B` | Sections secondaires |
| `--kiosk-bold-surface-strong` | `#FFF5E8` | Inversion (rares ribbons clairs sur fond sombre) |
| `--kiosk-bold-primary` | `#FF6B6B` | CTA (slightly desaturated pour contraste AAA dark) |
| `--kiosk-bold-primary-hover` | `#FF8585` | Hover CTA dark |
| `--kiosk-bold-accent` | `#FFC857` | Highlights warm gold |
| `--kiosk-bold-text-primary` | `#FFF5E8` | Texte principal warm white |
| `--kiosk-bold-text-secondary` | `#B8A99A` | Texte secondaire warm taupe |
| `--kiosk-bold-border` | `#2A1F18` | Bordures warm dark |
| `--kiosk-bold-border-strong` | `#FFF5E8` | Bordures cards sélectionnées |
| `--kiosk-bold-success` | `#4ADE80` | Vert dispo |
| `--kiosk-bold-warning` | `#FBBF24` | Warning |
| `--kiosk-bold-danger` | `#F87171` | Erreurs |

> **Validation contraste WCAG AA / AAA** : chaque combinaison texte / fond ≥ 4.5:1 (AA) et ≥ 7:1 (AAA escalation). Validation manuelle avant V1 close, automatisée via `axe-core` rule `color-contrast` + `color-contrast-enhanced` sur sentinel.

### 1.5 Échelle d'élévation et rayons étendus

Extension de `--kiosk-shadow-*` et `--kiosk-radius-*` :

```
--kiosk-shadow-card-bold:   0 4px 16px rgba(26, 20, 16, 0.08), 0 1px 4px rgba(26, 20, 16, 0.04);
--kiosk-shadow-cta-bold:    0 12px 32px rgba(230, 57, 70, 0.32), 0 4px 12px rgba(230, 57, 70, 0.16);
--kiosk-shadow-hero:        0 24px 64px rgba(26, 20, 16, 0.18), 0 8px 24px rgba(26, 20, 16, 0.10);
--kiosk-shadow-sticky-bold: 0 -8px 24px rgba(26, 20, 16, 0.06);
--kiosk-radius-2xl:         32px;     /* Hero cards */
--kiosk-radius-3xl:         48px;     /* Hero CTA, total panel */
```

### 1.6 Motion library

```
--kiosk-motion-spring:  cubic-bezier(0.34, 1.56, 0.64, 1);    /* Sélections, "pop" CTA */
--kiosk-motion-smooth:  cubic-bezier(0.4, 0, 0.2, 1);         /* Step transitions */
--kiosk-motion-emphasis: cubic-bezier(0.2, 0, 0, 1);          /* Already in CV1, conservé */

--kiosk-duration-tap:        120ms;   /* Micro-interaction */
--kiosk-duration-card:       240ms;   /* Card hover, selection */
--kiosk-duration-step:       320ms;   /* Step transition */
--kiosk-duration-ceremony:   480ms;   /* Confirmation finale uniquement */
```

> **Reduced motion** : `[data-kiosk-reduced-motion='true']` neutralise les `--kiosk-duration-*` à `1ms` (déjà câblé dans `cv1-tokens.css`, à étendre aux nouveaux tokens).

---

## 2. INVARIANTS_AT_RISK

| # | Invariant | Risque dans ce cycle | Mitigation |
|---|---|---|---|
| 1 | **Backend = SSOT prix** | Le redesign du wizard et du panier expose les prix de manière plus dramatique (Fraunces, taille hero) — risque cosmétique de faire croire à un calcul local. | Audit Codex / Claude vérifie qu'aucune nouvelle expression Vue ne calcule un prix. Tous les `formatPrice()` consomment uniquement `serverValue` issu de `createKioskPricingPreview`. |
| 2 | **OrderStatus enum autoritaire** | Les écrans `KioskWaitingComponent` et `KioskConfirmationComponent` ré-affichent les statuts. Risque de hardcoder un libellé. | V4 doit référencer l'enum via un mapping i18n (déjà en place) ; aucune string littérale FR/EN dans `<template>`. |
| 3 | **`branch_id` business isolation** | Aucun changement d'accès données prévu — le redesign est CSS + templates. | N/A — déclaré non affecté. |
| 4 | **Dispatch après DB commit** | Aucun changement de logique dispatch. | N/A. |
| 5 | **`OrderService` / `FrontendOrderService` symmetry** | `KioskPaymentComponent.vue` est sous gate symmetry POS. | **SKIN ONLY** : seuls `<style>` et structure visuelle DOM modifiables. Props/state/refs/events/computed conservés à l'identique. Audit Claude vérifie le diff `KioskPaymentComponent.vue` à l'aide d'un guard sentinel. |
| 6 | **Frozen zones** | Aucun fichier backend touché. `PricingService.php` / `FrontendOrderService.php` / `OrderController.php` non lus, non modifiés. | N/A. |
| 7 | **A11y WCAG AA + AAA + EAA 2025** | Refonte complète = risque de régression contraste / focus / touch / live regions. | Sentinel `tests/e2e/cv1-axe-sweep.spec.ts` étendu pour couvrir chaque écran kiosk refondu. Test manuel UAT NVDA + VoiceOver + zoom 200% à chaque vague. |

---

## 3. SUBSYSTEMS_TOUCHED

### Vague 1 — Foundations

| Subsystem | Scope | Read/Write | branch_id | Dispatch |
|---|---|---|---|---|
| `resources/css/foundations/cv1-tokens.css` | Étendre tokens CV1 (motion library, durées tap/card/step/ceremony) | Write | No | No |
| `resources/css/kiosk/tokens.css` | Ajout `--kiosk-bold-*` light + dark, ajout shadows, radii 2xl/3xl | Write | No | No |
| **NEW** `resources/css/kiosk/tokens-bold.css` | Nouvelle couche bold appétissant, importée après `tokens-pmr.css` | Write | No | No |
| **NEW** `resources/css/kiosk/typography-bold.css` | `@font-face` Fraunces + scale typo display + body refinement | Write | No | No |
| `resources/views/master.blade.php` | Lien preconnect Google Fonts + woff2 Fraunces (V1 light) | Write | No | No |
| `resources/js/bootstrap-kiosk.js` | Import `tokens-bold.css` + `typography-bold.css` après PMR | Write | No | No |
| `resources/css/app.css` | **Conditionnel** : import `cv1-tokens.css` si pas déjà fait (audit V1) | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/KsButton.vue` | Variantes `hero` / `ghost-bold` / `pop` ; spring easing | Write (style + props) | No | No |
| `resources/js/components/frontend/kiosk/ds/KsCard.vue` | Variantes `hero` (photo bg) / `option` / `summary` ; selection state bold | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/KsChip.vue` | Variantes `composition` (chip stack visuel) ; warm border | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/KsBadge.vue` | Variantes `promo` / `included` / `quota` ; bold contrast | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/KsModal.vue` | Backdrop blur warm + surface elevated | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/KsStepper.vue` | Refonte visuelle (dot + label + progress fill) | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/KsPriceLine.vue` | Variant `total-hero` (Fraunces 72px) | Write | No | No |
| **NEW** `resources/js/components/frontend/kiosk/ds/KsHero.vue` | Composant hero strip (photo + title + price + composition live) | Write | No | No |
| **NEW** `resources/js/components/frontend/kiosk/ds/KsThemeToggle.vue` | Toggle light / dark / auto (consommé par `KsA11ySettings`) | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` | Ajouter contrôle thème + persistance localStorage `kiosk_theme` | Write | No | No |
| `resources/js/composables/useKioskTheme.js` (**NEW**) | Composable theme (light / dark / auto avec `prefers-color-scheme`), propage `data-kiosk-theme` sur `<html>` | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/index.js` | Export nouveaux atoms `KsHero`, `KsThemeToggle` | Write | No | No |
| `resources/js/components/frontend/kiosk/ds/README.md` | Doc usage nouveaux atoms + variants | Write | No | No |

### Vague 2 — Idle + Catalog

| Subsystem | Scope | Read/Write | branch_id | Dispatch |
|---|---|---|---|---|
| `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` | Refonte template + style (hero plein cadre, dual CTA cards, langue/a11y top-right) | Write (template + style) | No | No |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | Shell : ajouter `data-kiosk-theme` propagation, refonte panier sticky bar | Write (template + style) | No | No |
| `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` | Refonte sidebar catégories iconographiée + grille produits 2 ou 3 cols hero photo | Write (template + style) | No | No |
| `resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue` | Refonte carousel premium (arrows custom, indicators, photo héro) | Write | No | No |
| `resources/css/kiosk-fallback.css` | Nettoyage règles obsolètes remplacées par bold tokens | Write | No | No |
| `resources/css/kiosk-wizard.css` | **Lecture seule** en V2 (refonte en V3) | Read | No | No |

### Vague 3 — Wizard

| Subsystem | Scope | Read/Write | branch_id | Dispatch |
|---|---|---|---|---|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | Refonte coquille : hero strip top, composition live "stack", nav bar bottom hero, transitions spring | Write (template + style ; **script lecture seule** sauf classes CSS) | No | No |
| `resources/css/kiosk-wizard.css` | Refonte complète CSS wizard (nouvelle architecture flex/grid, tokens bold) | Write | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue` | Refonte cards taille (radio cards bold, price impact visible) | Write (template + style) | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue` | Refonte cards pain (photo héro pain, single select bold) | Write | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` | Refonte grille viande (4 cols, photo + +/- counter, badge quota) | Write | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue` | Refonte chips sauces (multi-select avec compteur "1 gratuite") | Write | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue` | Refonte toggles garnitures (cards horizontales avec icône + check) | Write | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue` | Refonte lignes suppléments (visual price impact, +/- bold) | Write | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue` | Refonte cards menu (Frites/Boisson/Complet/Sans) + sous-flux boisson | Write | No | No |
| `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue` | Refonte grille générique composer (min/max badges visibles) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` | Refonte récap (sections cards + composition live grand format + total hero) | Write | No | No |

### Vague 4 — Cart + Checkout + Confirmation

| Subsystem | Scope | Read/Write | branch_id | Dispatch |
|---|---|---|---|---|
| `resources/js/components/frontend/kiosk/KioskCartComponent.vue` | Refonte panier (lignes hero photos, totaux Fraunces, CTA hero) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | Refonte upsell cinématique (cards 1-click add) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` | Refonte fidélité (numpad refondu, success cérémonial) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | **SKIN ONLY** — `<style scoped>` + structure DOM, props/state/computed/events/refs IDENTIQUES | Write (style + DOM uniquement) | No | No |
| `resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue` | Refonte (countdown hero, montant Fraunces) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` | Refonte (anneaux chef bold, transition fade-scale ready) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` | Refonte (numéro Fraunces XXL, animation cérémoniale ≤ 480ms, ticket fallback) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskErrorLayoutComponent.vue` | Refonte layout erreurs (illustration + message + CTA retry) | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue` | Refonte messaging | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue` | Refonte messaging | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue` | Refonte messaging | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue` | Refonte messaging + actions claires | Write | No | No |
| `resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue` | Refonte (countdown bold, microcopy chaleureuse) | Write | No | No |

---

## 4. SUBSYSTEMS_OFF_LIMITS

| Subsystem | Raison |
|---|---|
| `app/**` (PHP backend) | Aucun changement nécessaire — refonte 100 % CSS/templates. Frozen zones backend protégées. |
| `routes/web.php` | Routage kiosk 100 % Vue Router ; `/kiosk/*` servi par catch-all `master`. Pas de changement. |
| `resources/js/store/modules/kioskCart.js` | Logique panier + persistance + offline queue. **Lecture seule** pour comprendre les états affichés. |
| `resources/js/store/modules/kioskMenu.js` | Logique menu + cache. **Lecture seule.** |
| `resources/js/store/modules/kioskFilter.js` | Logique filtres allergènes / promos. **Lecture seule.** |
| `resources/js/store/modules/kioskSettings.js` | Modifications **autorisées uniquement** pour ajouter le state `theme: 'light'\|'dark'\|'auto'` + persistance localStorage. Aucun autre champ touché. |
| `resources/js/store/plugins/kioskAnalyticsPlugin.js` | Plugin analytics — aucun changement. |
| `resources/js/router/modules/kioskRoutes.js` | Routes — aucun changement (pas de nouvelle route). |
| `resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue` | Sous gate `GATE_OFFLINE_SCOPE_V1_2026-04-25.md`. **Lecture seule.** |
| `resources/js/components/frontend/kiosk/CatalogChangeToastComponent.vue` | Mid-cycle CV1-LIFECYCLE-UX-001 task 1.3 (handoff Codex 2026-05-02). V2 doit consommer la sortie finale, sans réécriture. Si V2 démarre avant fin task 1.3 : coordination Codex obligatoire. |
| `resources/js/components/admin/**` | Hors scope — refonte borne uniquement. |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (LOGIQUE) | Skin autorisé (style + DOM). **Props / state / computed / events / refs / methods / data() : INTERDITS** (gate symétrie POS). |

---

## 5. GATE_CONDITIONS

Aucun gate humain anticipé en V1 (foundations CSS / atoms cosmétiques). Les conditions de déclenchement à surveiller :

| Vague | Condition de gate | Action si déclenchée |
|---|---|---|
| V1 | Si l'import `cv1-tokens.css` dans `app.css` impacte les surfaces admin (les classes `.cv1-warning-badge` etc. sont déjà utilisées admin) → cross-surface impact. | Stop. Documenter le diff visuel admin. Gate `GATE_CV1_TOKENS_BOLD_CROSS_SURFACE_*.md`. Décision humaine : isoler le bundle kiosk ou propager l'admin. |
| V1 | Si Fraunces est rejetée par revue (licence, performance, lecture arabe) → décision typographique. | Stop. Proposer alternative (DM Serif Display / Recoleta payante / fallback system). Gate soft. |
| V2 | Si la refonte `KioskAppComponent` modifie l'ordre / la signature des transitions de route → impact tests E2E existants. | Coordination avec sentinels `tests/e2e/c3-runtime-multi-surface.spec.js`. Si breaking : gate. |
| V3 | Si le wizard refondu doit modifier le contrat des step components (props / events) au-delà des classes CSS → contrat composer. | Stop. Le wizard est cœur métier — gate `GATE_KIOSK_WIZARD_CONTRACT_*.md`. |
| V3 | Si le `kioskUsePosWizard` feature flag (KioskPosWizardComponent) demande convergence visuelle → décision portée. | Évaluer en début V3. Si oui : étendre scope V3 explicitement (pas en cours de cycle). |
| V4 | Si `KioskPaymentComponent.vue` `<script>` doit être touché (au-delà du `<style>` et du DOM) → violation gate symmetry POS. | Stop immédiat. Gate `GATE_KIOSK_PAYMENT_REFACTOR_*.md` obligatoire avant. |
| V4 | Si l'animation cérémoniale de `KioskConfirmationComponent` viole `prefers-reduced-motion` (durée > 1ms en reduced) → a11y régression. | Stop. Audit reduced-motion + correction. |
| Toute vague | Si deux validations consécutives échouent (axe, build, sentinel) → règle FoodKing arrêt. | Halt + escalade humaine via `ESCALATION` block du plan. |

---

## 6. Pile CSS cible (post-V1)

```
1. resources/css/app.css                     ← Tailwind base + admin (intouché)
2. resources/css/kiosk/tokens.css            ← Tokens kiosk de base (étendu V1 : --kiosk-bold-*)
3. resources/css/kiosk/tokens-aaa.css        ← Escalation contraste WCAG AAA
4. resources/css/kiosk/tokens-pmr.css        ← Réduction de mobilité
5. resources/css/kiosk/tokens-bold.css       ← NEW V1 — couche bold appétissant (light + dark)
6. resources/css/kiosk/typography-bold.css   ← NEW V1 — Fraunces @font-face + scale display
7. resources/css/foundations/cv1-tokens.css  ← Tokens CV1 sémantiques (étendu V1 motion library)
8. resources/css/kiosk-wizard.css            ← Refondu V3 (consomme tokens bold)
9. resources/css/kiosk-fallback.css          ← Réduit V2 (règles obsolètes purgées)
```

Ordre de cascade garanti via `bootstrap-kiosk.js` (imports explicites).

---

## 7. Tableau de bord exécutif (V1 → V4)

| Vague | Tâche | Cible | Effort | Risque | Sentinels nouveaux |
|---|---|---|---|---|---|
| V1 | 1.1 Tokens bold light + dark | `tokens-bold.css`, `cv1-tokens.css` extension | M | Faible | `tests/js/kioskBoldTokensSnapshot.spec.js` (smoke + contrast) |
| V1 | 1.2 Typo Fraunces + scale | `typography-bold.css`, `master.blade.php` (preconnect/woff2) | S | Faible | Performance metric (Lighthouse FCP / LCP local) |
| V1 | 1.3 Composable `useKioskTheme` | `composables/useKioskTheme.js`, `kioskSettings.js` (state `theme`) | S | Faible | `tests/js/useKioskTheme.spec.js` |
| V1 | 1.4 KsThemeToggle + intégration KsA11ySettings | `KsThemeToggle.vue`, `KsA11ySettings.vue` | S | Faible | `tests/js/KsThemeToggle.spec.js` |
| V1 | 1.5 KsButton variantes hero/ghost-bold/pop | `KsButton.vue` | S | Faible | `tests/js/KsButton.spec.js` (étendu) |
| V1 | 1.6 KsCard variantes hero/option/summary | `KsCard.vue` | M | Faible | `tests/js/KsCard.spec.js` (nouveau) |
| V1 | 1.7 KsChip composition + KsBadge promo/included/quota | `KsChip.vue`, `KsBadge.vue` | S | Faible | snapshot |
| V1 | 1.8 KsModal backdrop blur warm | `KsModal.vue` | S | Faible | snapshot |
| V1 | 1.9 KsStepper refonte visuelle | `KsStepper.vue` | S | Faible | `tests/js/KsStepper.spec.js` (nouveau) |
| V1 | 1.10 KsPriceLine variant total-hero | `KsPriceLine.vue` | S | Faible | snapshot |
| V1 | 1.11 KsHero new atom | `KsHero.vue`, `ds/index.js`, `ds/README.md` | M | Faible | `tests/js/KsHero.spec.js` |
| V2 | 2.1 KioskIdleScreenComponent refonte | `KioskIdleScreenComponent.vue` | M | Faible | `tests/js/KioskIdleScreen.spec.js` (nouveau) + axe |
| V2 | 2.2 KioskAppComponent shell refonte | `KioskAppComponent.vue` | M | Modéré | extension `tests/e2e/c3-runtime-multi-surface.spec.js` |
| V2 | 2.3 KioskCategoriesComponent refonte | `KioskCategoriesComponent.vue` | L | Modéré | `tests/js/KioskCategoriesRestyle.spec.js` (étendu) |
| V2 | 2.4 KioskPromoCarouselComponent refonte | `KioskPromoCarouselComponent.vue` | M | Faible | snapshot |
| V3 | 3.1 KioskWizardComponent shell refonte | `KioskWizardComponent.vue`, `kiosk-wizard.css` | XL | Modéré | `tests/js/KioskWizard.spec.js` (étendu) + Playwright |
| V3 | 3.2-3.9 Step components refonte (8) | tous `steps/Kiosk*Component.vue` | XL | Modéré | sentinels existants (`posWizardComposerProfile`, etc.) |
| V3 | 3.10 KioskOrderSummaryComponent refonte | `KioskOrderSummaryComponent.vue` | M | Faible | snapshot |
| V4 | 4.1 KioskCartComponent refonte | `KioskCartComponent.vue` | M | Faible | snapshot |
| V4 | 4.2 KioskUpsellComponent refonte | `KioskUpsellComponent.vue` | M | Faible | snapshot |
| V4 | 4.3 KioskLoyaltyComponent refonte | `KioskLoyaltyComponent.vue` | M | Faible | snapshot |
| V4 | 4.4 **KioskPaymentComponent SKIN ONLY** | `KioskPaymentComponent.vue` | S | Modéré | guard sentinel diff `<script>` invariant |
| V4 | 4.5 KioskCashInstructionComponent refonte | `KioskCashInstructionComponent.vue` | S | Faible | snapshot |
| V4 | 4.6 KioskWaitingComponent refonte | `KioskWaitingComponent.vue` | M | Faible | snapshot |
| V4 | 4.7 KioskConfirmationComponent refonte | `KioskConfirmationComponent.vue` | M | Faible | snapshot |
| V4 | 4.8 5x KioskError* refonte | tous les error components | M | Faible | snapshot |
| V4 | 4.9 KioskInactivityOverlayComponent refonte | `KioskInactivityOverlayComponent.vue` | S | Faible | snapshot |

---

## 8. Vague 1 — Foundations (détail tâche par tâche)

> **Goal V1** : poser le système. Aucun écran client ne change visuellement. Les atoms `ds/` exposent de nouvelles variantes consommables ensuite par V2-V4. À la fin de V1, un développeur doit pouvoir importer `<KsButton variant="hero">Commander</KsButton>` ou `<KsHero :photo="..." :title="..." />` et obtenir le rendu bold appétissant.

### 1.1 — Tokens bold light + dark

**Fichier(s) cible(s) :**
- **NEW** `resources/css/kiosk/tokens-bold.css`
- `resources/css/kiosk/tokens.css` (extension)
- `resources/css/foundations/cv1-tokens.css` (extension motion library)

**Contrat :**
- Le fichier `tokens-bold.css` déclare `--kiosk-bold-*` sous `:root` (light) et `[data-kiosk-theme='dark']` (dark mode).
- `--kiosk-shadow-card-bold`, `--kiosk-shadow-cta-bold`, `--kiosk-shadow-hero`, `--kiosk-shadow-sticky-bold` ajoutés.
- `--kiosk-radius-2xl`, `--kiosk-radius-3xl` ajoutés.
- `--kiosk-motion-spring`, `--kiosk-duration-tap|card|step|ceremony` ajoutés.
- `[data-kiosk-reduced-motion='true']` neutralise les nouvelles `--kiosk-duration-*` à `1ms` (pattern existant cv1-tokens).

**Étapes Codex :**
1. Créer `tokens-bold.css` avec la palette §1.4 + extension shadows/radii/motion.
2. Vérifier qu'aucun `--kiosk-bold-*` ne shadow un token existant.
3. Importer `tokens-bold.css` dans `bootstrap-kiosk.js` après `tokens-pmr.css`.
4. Tester contraste manuellement (chrome devtools / axe-core) ≥ 4.5:1 AA / ≥ 7:1 AAA pour chaque combinaison texte/fond.
5. Sentinel `tests/js/kioskBoldTokensSnapshot.spec.js` : monte un composant test, vérifie présence des CSS variables sur `:root` et `[data-kiosk-theme='dark']`.

**Critères d'acceptation :**
- Light mode + dark mode tokens présents.
- AAA escalation préservée via `[data-kiosk-contrast='aaa']`.
- Reduced motion neutralise les nouvelles durées.
- Aucune régression sur tokens existants (`--kiosk-primary`, etc.).

### 1.2 — Typographie Fraunces + scale display

**Fichier(s) cible(s) :**
- **NEW** `resources/css/kiosk/typography-bold.css`
- `resources/views/master.blade.php` (preconnect Google Fonts + woff2 hosted local optionnel)
- `resources/js/bootstrap-kiosk.js` (import)

**Contrat :**
- Fraunces chargée via Google Fonts (CSS @import) OU self-hosted woff2 sous `public/fonts/fraunces/` (préférence : self-hosted pour CSP strict + offline kiosk).
- Échelle display : `--kiosk-display-size-hero: 9rem; --kiosk-display-size-xl: 5rem; --kiosk-display-size-l: 3.5rem; --kiosk-display-size-m: 2.25rem;`
- Classes utilitaires : `.kiosk-display-hero`, `.kiosk-display-xl`, `.kiosk-display-l`, `.kiosk-display-m` (font-family Fraunces, weight 700, line-height tight 1.1, letter-spacing -0.02em).
- Body Inter conservé inchangé.
- Mono JetBrains conservé inchangé pour montants tabulaires.

**Étapes Codex :**
1. Décision (à arbitrer Plan Review) : Google Fonts CDN vs self-hosted. Recommandation **self-hosted** (CSP, offline kiosk, perf).
2. Si self-hosted : télécharger Fraunces variable woff2 (font_face SIL OFL — autorisé) sous `public/fonts/fraunces/`.
3. Créer `typography-bold.css` avec @font-face + scale display + classes utilitaires.
4. Importer dans `bootstrap-kiosk.js` après `tokens-bold.css`.
5. Mesurer LCP / FCP avant/après (Lighthouse local ou Playwright `page.metrics()`).

**Critères d'acceptation :**
- Fraunces affichée correctement sur Chrome stable kiosk.
- LCP régression ≤ 100ms (target ≤ 50ms).
- Fallback `serif` si Fraunces ne charge pas.
- Arabic / RTL : Noto Sans Arabic conservé pour les surfaces RTL (Fraunces n'a pas d'arabic).

### 1.3 — Composable `useKioskTheme`

**Fichier(s) cible(s) :**
- **NEW** `resources/js/composables/useKioskTheme.js`
- `resources/js/store/modules/kioskSettings.js` (extension state)

**Contrat :**
- Composable Vue 3 Composition API (cohérent avec `useKioskA11y`, `useKioskSpeech`).
- État : `theme: 'light' | 'dark' | 'auto'` (default `auto`).
- En mode `auto` : suit `window.matchMedia('(prefers-color-scheme: dark)')`.
- Propage `data-kiosk-theme="light|dark"` (jamais `auto` — résolu) sur `<html>`.
- Persistance localStorage `kiosk_theme` (key versionnée `kiosk_theme_v1`).
- Émet `theme-change` event pour analytics éventuel.

**Étapes Codex :**
1. Créer le composable avec `ref`, `watchEffect`, `onMounted`, `onBeforeUnmount`.
2. Ajouter au state `kioskSettings` : `theme: { type: String, default: 'auto' }` + mutation + action.
3. Persister via `vuex-persistedstate` (déjà utilisé pour `kioskCart` / `kioskSettings`).
4. Sentinel `tests/js/useKioskTheme.spec.js` : vérifie résolution auto, switch manuel, persistance, propagation attribut.

**Critères d'acceptation :**
- Switch light / dark / auto fonctionne sans rechargement.
- `prefers-color-scheme: dark` détecté et appliqué en mode auto.
- Persistance survit reload.
- Aucun flash of unstyled content (FOUC) au chargement (résolution synchrone précoce).

### 1.4 — `KsThemeToggle` + intégration `KsA11ySettings`

**Fichier(s) cible(s) :**
- **NEW** `resources/js/components/frontend/kiosk/ds/KsThemeToggle.vue`
- `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` (intégration)
- `resources/js/components/frontend/kiosk/ds/index.js` (export)

**Contrat :**
- Toggle 3 états : light / dark / auto (avec icônes ☀ / 🌙 / 🌗).
- Émet `update:theme`.
- Touch ≥ 44×44 (V1 spec).
- Aria-label dynamique selon état actif.
- Animation tap spring 120ms (réduit en reduced-motion).

**Étapes Codex :**
1. Créer le composant avec slot icônes.
2. Intégrer dans `KsA11ySettings.vue` (panel a11y existant).
3. Wirer avec `useKioskTheme`.
4. Sentinel `tests/js/KsThemeToggle.spec.js`.

### 1.5 → 1.11 — Atoms `ds/` enrichis

(Détails par atom — chacun suit le même pattern : variantes nouvelles, propriétés, sentinel snapshot, doc README.)

| Atom | Variantes ajoutées | Notes UX |
|---|---|---|
| `KsButton` | `hero` (taille 64px, Fraunces, ombre cta-bold, scale spring) / `ghost-bold` (border 2px warm, hover fill) / `pop` (micro-anim succès post-tap) | Touch 44 min / 56 standard / 72 hero |
| `KsCard` | `hero` (photo bg + gradient overlay + content over) / `option` (sélectionnable, border bold sur état) / `summary` (récap, dividers warm) | Élévation `--kiosk-shadow-card-bold` |
| `KsChip` | `composition` (chip stack visuel ingrédient avec photo mini + nom) / `included` (chip inclus, fond accent doux) | Border warm, padding 12 16 |
| `KsBadge` | `promo` (orange accent, semibold) / `included` (vert success light) / `quota` (compteur X/Y bold) | Bold contrast AA min |
| `KsModal` | Backdrop blur warm `backdrop-filter: blur(12px) saturate(150%)` + surface elevated `--kiosk-bold-surface` | Anim entrée 240ms scale 0.95 → 1 |
| `KsStepper` | Refonte : dots numérotés + label sous, fill bar entre les dots avec animation, états completed (✓ vert) / active (warm primary) / pending (warm taupe) | Aria-current step |
| `KsPriceLine` | `total-hero` : Fraunces 72px primary | Mono JetBrains pour décimales |
| **NEW** `KsHero` | Composant "hero strip" : photo héro + titre + sous-titre + price + slot composition live | Réutilisé dans wizard V3 et pages produit |

---

## 9. Vague 2 — Idle + Catalog (détail tâche par tâche)

### 2.1 — `KioskIdleScreenComponent` refonte

**Vision :** premier contact client. Cinématique sans être trop long. Le client voit instantanément : où il est (brand mark), ce qu'on lui demande (Sur place / À emporter), comment configurer (langue, a11y).

**Layout cible :**
- Pleine viewport. Vidéo loop ou photo héro plein cadre (priorité photo si vidéo n'est pas fournie par tenant).
- Top-right : sélecteur langue (FR/EN/AR/BN/DE) compact + bouton a11y (ouvre `KsA11ySettings` modal incluant `KsThemeToggle`).
- Centre vertical : Brand mark "FoodKing" en Fraunces hero (144px), tagline en Inter 24px ("Compose, savoure, recommence.").
- Centre-bas : 2 large cards CTA "SUR PLACE" / "À EMPORTER" (KsCard variant hero), photo héro intérieur restaurant / sac à emporter, label Fraunces XL.
- Bottom : 3 dots animés (pulse) suggérant qu'il faut toucher.

**Sentinel :**
- `tests/js/KioskIdleScreen.spec.js` (Vitest) : vérifie présence brand mark, 2 CTAs, langue selector, a11y button.
- `tests/e2e/cv1-axe-sweep.spec.ts` : axe sur l'idle screen.

### 2.2 — `KioskAppComponent` shell refonte

**Vision :** la coquille doit véhiculer le bold appétissant en arrière-plan tout en restant invisible (le client ne pense pas à la coquille, il pense à son tacos).

**Changements :**
- Propagation `data-kiosk-theme` sur `<html>` (consomme `useKioskTheme`).
- Refonte panier sticky bar (visible sur catégories / wizard / upsell) : fond warm subtle, total Fraunces, CTA primary.
- Transitions slide-left/right entre routes : passe en `--kiosk-motion-smooth` 320ms.
- Conserver intégralement `ROUTE_ORDER`, `kioskStableShell`, timers idle, file offline (lecture seule).

### 2.3 — `KioskCategoriesComponent` refonte

**Vision :** une "carte du restaurant" éditoriale. Sidebar gauche affirmée avec catégories iconographiées. Grille produits droite avec photos héro.

**Layout cible :**
- Header brand : logo + fil d'Ariane + bouton retour idle (si autorisé).
- `KioskPromoCarouselComponent` en top (réduit en hauteur sur ce layout).
- Sidebar gauche (240px desktop / 200px portrait kiosk) : liste catégories sticky, chaque item = icône + label + count, état actif barre verticale primary + fond subtle.
- Grille produits droite : 2 cols (1080px portrait) ou 3 cols (>1280px paysage), `KsCard` variant `option` avec photo héro (4:5), nom Fraunces 28px, allergens badges, prix accent or, CTA "Personnaliser →" subtle.
- Loading : skeleton cards (warm taupe pulsing).
- Empty : illustration + microcopy + CTA retour.
- Erreur : illustration + message + CTA retry.

**Sentinel :**
- `tests/js/KioskCategoriesRestyle.spec.js` (déjà existant — étendre).

### 2.4 — `KioskPromoCarouselComponent` refonte

Carousel premium : arrows custom, indicators dots warm, photos héro plein cadre, transitions smooth fade + slide. Auto-play désactivé en `prefers-reduced-motion`.

---

## 10. Vague 3 — Wizard (détail tâche par tâche)

### 3.1 — `KioskWizardComponent` shell refonte

**Vision :** le wizard est le cœur de la conversion. Le client doit ressentir le produit qu'il construit, pas un formulaire. Trois zones : (1) le contexte (qu'est-ce que je construis), (2) le choix (qu'est-ce qu'on me propose), (3) le résultat (combien ça coûte / continuer).

**Layout cible :**
- **Top hero strip** (160px hauteur) : `KsHero` instance — photo produit (gauche), nom produit Fraunces XL + base price (centre), composition live "stack visuel" (droite, voir ci-dessous).
- **Stepper bar** (just under hero) : `KsStepper` refondu, scroll horizontal si > 7 étapes.
- **Question titre** : Fraunces L (3.5rem), centré, animation enter + fade.
- **Zone d'étape** : composant step actif, transition `step-slide` 320ms `--kiosk-motion-smooth`.
- **Composition live "stack"** (sidebar droite ou dessous selon viewport) : empilement visuel des sélections, chaque chip = photo mini + nom + price impact si > 0.
- **Nav bar bottom** sticky (96px) : "PRÉCÉDENT" (ghost-bold), TOTAL Fraunces hero, "CONTINUER →" (hero CTA primary).
- **Modal abandon** (`KsModal`) : refonte messaging warm "Tu pars vraiment ? Ta commande sera annulée." + 2 CTAs.

**Constraint critique :** ne pas modifier le `<script>` au-delà des classes CSS. Toute modification de `activeSteps`, `canAdvance`, `prevStep`, `nextStep`, `composition`, `runningTotal` est interdite. Ce sont des contrats logiques validés par les sentinels existants (`posWizardComposerProfile`, etc.).

### 3.2 → 3.9 — Step components refonte (8)

Pour chaque step, le pattern est identique :
1. Refonte `<template>` avec atoms `ds/` (KsCard option, KsChip, KsBadge, KsButton).
2. Refonte `<style scoped>` avec tokens bold.
3. **Préservation totale** du `<script>` : props, data, computed, methods, watchers.
4. Snapshot Vitest existant doit rester vert (sauf si snapshot visuel — alors regénérer).

| Step | Spec UX |
|---|---|
| **Taille** | Cards radio horizontales avec lettre taille en Fraunces XL (S/M/L/XL), prix impact subtle. État sélectionné : border bold primary + scale 1.02. |
| **Pain** | Cards photo pain (4:5), nom, allergens. Single select. |
| **Viande** | Grille 4 cols cards photo viande, nom, allergens, +/- counter (KsButton ghost-bold). Badge quota X/Y top-right (KsBadge variant quota). État rupture : carte greyscale + label "Indisponible". |
| **Sauce** | Grille chips horizontales (KsChip composition), photo mini sauce, nom. Multi-select. Compteur "1ère gratuite • 2è +0,50 €" en hint. |
| **Garnitures** | Liste verticale de toggles (KsCard horizontal), check icon left, nom, allergens, "Inclus" badge (KsBadge included). |
| **Suppléments** | Liste verticale, photo mini supplément, nom, prix Fraunces M, +/- counter. |
| **Menu** | 4 cards XL : Frites / Boisson / Complet / Sans, photo héro, label Fraunces XL, prix +X €. Sous-flux boisson en sub-grid. |
| **Generic choices** | Grille adaptative selon `composer_step.layout`, KsCard option avec min/max badges visibles. |

### 3.10 — `KioskOrderSummaryComponent` refonte

Récap final premium : titre Fraunces XL "Voici ton tacos", sections cards par catégorie (pain / viandes / sauces / garnitures / suppléments / menu), composition live grand format avec photo principale au centre, total Fraunces hero, CTA "Ajouter au panier →" primary hero.

---

## 11. Vague 4 — Cart + Checkout + Confirmation (détail tâche par tâche)

### 4.1 — `KioskCartComponent` refonte

**Vision :** le panier est l'instant de la décision finale. Tout doit être lisible, modifiable, rassurant. Le total est l'évènement central.

**Layout cible :**
- Header : "Votre commande" Fraunces XL + count "X articles" Inter M.
- Liste lignes : chaque ligne = photo héro (4:3 small) + nom Fraunces M + composition résumée Inter SM + +/- counter + prix Fraunces M.
- Bouton edit per line (ouvre wizard pré-rempli).
- Sidebar droite (ou bottom portrait) : type commande toggle (KsButton segmented) / promo code input / totaux dégressifs (sous-total / promo / TVA / TOTAL Fraunces hero).
- CTA bottom hero : "PAYER X,XX €" (primary hero, ombre cta-bold, anim spring tap).

### 4.2 — `KioskUpsellComponent` refonte

Cards 1-click add cinématiques : photo héro grande, nom Fraunces L, prix accent or, "+ Ajouter" CTA. Auto-skip si 0 sugg (logique conservée). Progressbar auto-skip subtle bottom (warm primary).

### 4.3 — `KioskLoyaltyComponent` refonte

Numpad refondu : touches 80×80px (warm surface, focus ring 3px), input Fraunces XL, success state cérémonial (anim spring + ✓ vert + microcopy "Bienvenue Maxime !").

### 4.4 — **`KioskPaymentComponent` SKIN ONLY**

**CONTRAINTE NON-NÉGOCIABLE :** seul `<style scoped>` et la structure DOM de `<template>` sont modifiables. **Aucun changement** dans `<script>` (data, methods, computed, watch, mounted, props, emit). Le diff sera audité par Claude terminal avec une commande `git diff KioskPaymentComponent.vue` filtré pour vérifier qu'aucune ligne en dehors de `<style>` et `<template>` n'a changé en logique.

**Refonte visuelle :**
- Méthodes paiement (CB / Cash / Tickets resto) en cards XL avec icône + label + (logo BC partenaire si CB).
- Montant à payer en Fraunces hero (centré, dramatique).
- État offline : alerte warm warning, microcopy explicite.
- Loading post-tap : KsButton variant pop avec spinner.

### 4.5 — `KioskCashInstructionComponent` refonte

Montant à insérer en Fraunces hero (rouge primary), countdown bold sous (warm warning si < 30s), illustration insertion espèces.

### 4.6 — `KioskWaitingComponent` refonte

État préparation : illustration animée chef (3 anneaux pulse warm), microcopy "On prépare votre tacos…", numéro commande Fraunces L.
État prêt : transition `fade-scale` 480ms (cérémonial), illustration ✓ + numéro Fraunces hero, CTA "Récupérer →".

### 4.7 — `KioskConfirmationComponent` refonte

Cérémonie : anim "Merci !" Fraunces hero + numéro commande XXL + montant + ticket (impression auto, fallback si imprimante HS), points fidélité gagnés (badge accent), CTA retour idle auto-timer 30s.

### 4.8 → 4.9 — Erreurs + inactivité

Layout commun (`KioskErrorLayoutComponent`) : illustration warm SVG + titre Fraunces L + message Inter L + CTA retry (primary) + CTA retour idle (ghost-bold).

Variants : Network / MenuUnavailable / ProductRemoved / PaymentRefused (chacun avec illustration et microcopy spécifiques).

`KioskInactivityOverlayComponent` : countdown bold (Fraunces L), microcopy chaleureuse "Tu es toujours là ?", CTA "Continuer" (primary).

---

## 12. Stratégie a11y par vague

| Vague | Sentinels axe | Test manuel UAT |
|---|---|---|
| V1 | Smoke contraste tokens, focus ring sur KsButton/KsCard | NVDA + lecture des nouveaux atoms isolés |
| V2 | Axe sur idle, categories, app shell | NVDA bout en bout idle → categories. Zoom 200%. |
| V3 | Axe sur wizard + chaque step | NVDA bout en bout wizard. VoiceOver iOS portrait. Reduced motion ON. |
| V4 | Axe sur cart, payment, waiting, confirmation, errors | NVDA bout en bout commande complète. UAT humain (QA) sur borne réelle. |

**Sentinel transverse à étendre :** `tests/e2e/cv1-axe-sweep.spec.ts` doit injecter chaque écran kiosk refondu et exécuter axe avec rules `wcag2aa`, `wcag21aa`, `color-contrast`, `color-contrast-enhanced`, `focus-order-semantics`, `target-size`.

---

## 13. Stratégie test par vague

| Vague | Tests Vitest | Tests Playwright |
|---|---|---|
| V1 | Snapshots atoms ds/, composable theme, contrast tokens | — |
| V2 | Snapshots idle/categories, mocks store kioskMenu | E2E idle → categories → tap product (extension `c3-runtime-multi-surface`) |
| V3 | Snapshots wizard + steps, mocks composer profile | E2E wizard complet tacos 4 viandes (extension `tacos-4-viandes-cash-flow`) |
| V4 | Snapshots cart/payment/waiting/confirmation, guard sentinel KioskPayment script invariant | E2E commande complète bout en bout (extension `composer-mega-flow`) |

**Performance** : Lighthouse local après chaque vague (FCP ≤ 1.5s, LCP ≤ 2.5s sur kiosk hardware ref).

---

## 14. Plan d'exécution (delegation per wave)

| Vague | Channel d'exécution | Justification |
|---|---|---|
| **V1.1 → V1.4** (tokens, typo, composable theme) | **Claude orchestrator** (cette session) | Foundations = orchestration / config. Pas de logique métier. Admis par `.cursor/rules/global.mdc § Token Discipline` (orchestration files). |
| **V1.5 → V1.11** (atoms ds/) | **codex-extension** (`npm run codex:complex -- CV1-KIOSK-VISUAL-REDESIGN-001-V1`) | Composants Vue produit, même skin only — discipline FoodKing exige délégation. |
| **V2** | **codex-extension** | Composants client kiosk. Coordination CV1 task 1.3 nécessaire (toast). |
| **V3** | **codex-extension** | Wizard cœur conversion. PLAN_REVIEW + EXECUTE complex obligatoire. |
| **V4** | **codex-extension** | Composants client kiosk. Vigilance gate paiement. |

**Avant chaque vague EXECUTE** :
- Réservation `bash scripts/agent-activity-log.sh start codex-extension CV1-KIOSK-VISUAL-REDESIGN-001-V<N> execute "<files CSV>" "<note>"`.
- Préparation `missions/CV1-KIOSK-VISUAL-REDESIGN-001-V<N>/input.json` (+ `graphiti_context.md`, `plan_excerpt.md`, `execute_brief.md`).
- Lancement `npm run codex:complex -- CV1-KIOSK-VISUAL-REDESIGN-001-V<N>`.

**Après chaque vague** :
- VALIDATE : `npm test`, `npx playwright test --project=kiosk` (sélectif), Lighthouse local.
- AUDIT Claude terminal (`bash scripts/foodking-claude-orchestrate.sh audit-brief`) — invariants + diff guard pour V4.4.
- GPT_FINAL_AUDIT (`npm run codex:final-audit -- CV1-KIOSK-VISUAL-REDESIGN-001-V<N>`).
- Double PASS requis avant passage à la vague suivante.
- `bash scripts/agent-activity-log.sh done codex-extension CV1-KIOSK-VISUAL-REDESIGN-001-V<N> done "Vague <N> close"`.

---

## 15. Risques & mitigations

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Fraunces refusée (perf / arabic / licence revue) | Faible | Moyen | Plan B : DM Serif Display (Google Fonts free, similaire warm). Décidé au plan review. |
| Conflit avec CV1 task 1.3 (CatalogChangeToast) | Moyenne | Faible | Coordination Codex avant V2 ; consommer la sortie finale, pas réécrire. |
| Régression visuelle admin (si cv1-tokens.css est partagé) | Faible | Moyen | V1 isole les nouveaux tokens dans `tokens-bold.css` (kiosk-only). cv1-tokens reste neutre. |
| Wizard `<script>` accidentellement modifié | Moyenne | Élevé | Sentinel guard + audit Claude terminal sur diff `KioskWizardComponent.vue` |
| `KioskPaymentComponent` `<script>` accidentellement modifié | Moyenne | **Très élevé** (gate symmetry POS) | Sentinel script invariant (compare hash `<script>` block avant/après). HALT immédiat sur diff. |
| Performance LCP régression > 100ms | Faible | Moyen | Self-hosted Fraunces woff2 + preload ; mesure Lighthouse à chaque vague. |
| A11y régression contraste dark mode | Moyenne | Élevé | axe-core sur chaque écran refondu, AAA escalation testée. |
| Animation cérémoniale viole reduced-motion | Faible | Élevé | Tests reduced-motion automatisés sur `KioskConfirmationComponent`. |
| Scope expansion (tentation refactor admin "tant qu'on y est") | Moyenne | Moyen | Discipline FoodKing : `SUBSYSTEMS_OFF_LIMITS` strict. Toute déviation déclenche `SCOPE_PRESSURE` + halt. |

---

## 16. Critères de close (par vague et global)

### Par vague
- [ ] Tous les sentinels Vitest verts.
- [ ] Tous les sentinels Playwright concernés verts.
- [ ] axe-core score 0 violation A/AA, ≤ 3 violations AAA non-bloquantes documentées.
- [ ] Lighthouse FCP ≤ 1.5s, LCP ≤ 2.5s.
- [ ] AUDIT_VERDICT Claude : PASS.
- [ ] GPT_FINAL_AUDIT_VERDICT : PASS.
- [ ] `agent-activity-log.sh done` exécuté.
- [ ] Mémoire Graphiti mise à jour (`memory/episodes/`) avec décisions durables.

### Global (cycle close)
- [ ] V1 + V2 + V3 + V4 toutes closed (4× double PASS).
- [ ] UAT humain sur borne réelle (au moins 1 commande tacos 4 viandes complète bout en bout).
- [ ] Visual regression vs maquette `KIOSK_REDESIGN_BOLD_PREVIEW_2026-05-02.html` confirmée.
- [ ] Documentation `docs/design/DESIGN_SYSTEM_FOUNDATIONS_CV1.md` mise à jour avec nouveaux tokens bold + atoms.
- [ ] Archivage cycle : `docs/orchestration/cycles/CYCLE_CV1-KIOSK-VISUAL-REDESIGN-001_2026-05-02.md`.
- [ ] `ACTIVE_CYCLE.md` reset.

---

## SYMMETRY_NOTE

**N/A.** Ce cycle ne touche ni `OrderService` ni `FrontendOrderService`. `KioskPaymentComponent.vue` est touché en SKIN ONLY (style + DOM uniquement), `<script>` strictement préservé pour respecter la gate symétrie POS active (`docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`).

## SCOPE_PRESSURE

Vide à plan time. À renseigner mid-cycle si scope pressure détectée.

## ESCALATION

Vide à plan time. À renseigner mid-cycle si escalation requise (gate trigger, conflict invariant, scope expansion).

---

## Audit Status

[ ] Pending
[ ] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS (V1)
[ ] AUDIT_VERDICT: PASS (V2)
[ ] AUDIT_VERDICT: PASS (V3)
[ ] AUDIT_VERDICT: PASS (V4)
[ ] GPT_FINAL_AUDIT_VERDICT: PASS (V1)
[ ] GPT_FINAL_AUDIT_VERDICT: PASS (V2)
[ ] GPT_FINAL_AUDIT_VERDICT: PASS (V3)
[ ] GPT_FINAL_AUDIT_VERDICT: PASS (V4)
[ ] Cycle closed
[ ] Gate opened — `docs/gates/GATE_CV1-KIOSK-VISUAL-REDESIGN-001_*.md` (le cas échéant)

---

**Fin plan CV1-KIOSK-VISUAL-REDESIGN-001 — 2026-05-02 — Claude (Opus 4.7, xhigh).**
