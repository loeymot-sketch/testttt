# AUDIT P-MEGA-15 — A11y kiosk baseline (Phase A.1 du cycle W6)

**Date** : 2026-04-20
**Mode** : READONLY (Phase A.1 du cycle W6)
**HEAD** : c1c89ff89
**Subagent** : explore very thorough

**Périmètre fichiers** : 43 composants `.vue` sous `resources/js/components/frontend/kiosk/` (dont `ds/` et `steps/`). CSS audité : `resources/css/kiosk-wizard.css` + tokens `resources/css/kiosk/tokens.css`.

**Légende matrice** : ✓ = conforme / partiel OK, ✗ = écart mesurable, — = non applicable ou non revu en profondeur sur ce critère.

## 0. Synthèse exécutive (5 lignes max)

Baseline **partielle AA** : tokens (`tokens.css`) et atoms DS (`KsButton`, `KsChip` hors sous-bouton, `KsModal` focus visible) sont globalement alignés 2.4.7 / contraste de marque. **Dette critique** : contrôles « faux boutons » (`KioskAppComponent` barre panier), **toasts sans région live**, **wizard** (cibles < 44 px, modale abandon sans piège focus/Escape, pas de `prefers-reduced-motion` local), **modale DS sans vrai focus trap**. **~43 fichiers Vue** ; **≥35 écarts** recensés (tous niveaux), dont **~8 critiques** sur parcours clavier / annonces AT.

## 1. Composants audités (matrice)

| Composant | Touch | Contraste | Focus visible | Focus order | ARIA | Reduced motion | Keyboard | Labels | Headings | Alt | TOTAL FAIL |
|---|---|---|---|---|---|---|---|---|---|---|---|
| KioskAppComponent.vue | ✗ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | — | — | — | 5 |
| KioskWizardComponent.vue | ✗ | ✗ | ✓ | ✗ | ✗ | ✗ | ~ | — | ✗ | ✓ | 7 |
| KioskPosWizardComponent.vue | = wizard | = wizard | = wizard | = wizard | = wizard | = wizard | = wizard | — | = wizard | = wizard | — |
| KioskCategoriesComponent.vue | ✗ | ~ | ✗ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ~ | 5 |
| KioskCartComponent.vue | ~ | ✓ | ✗ | ✓ | ✓ | ✗ | ✓ | ~ | ✓ | ✓ | 3 |
| KioskProductListComponent.vue | ~ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 3 |
| KioskToastComponent.vue | ✓ | ~ | ✗ | ✓ | ✗ | ✗ | ✗ | — | — | — | 5 |
| KioskIdleScreenComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✓ | ✓ | 1 |
| KioskLoginComponent.vue | ~ | ✓ | ✗ | ✓ | ✓ | ✗ | ✓ | ~ | ✓ | — | 3 |
| KioskLoyaltyComponent.vue | ~ | ✓ | ✗ | ✓ | ✓ | ✗ | ✓ | ~ | ✓ | — | 3 |
| KioskWaitingComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ~ | — | 2 |
| KioskUpsellComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✓ | ✓ | 1 |
| KioskCashInstructionComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✓ | — | 1 |
| KioskInactivityOverlayComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | — | 0 |
| KioskPromoCarouselComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | — | 0 |
| KioskAdminComponent.vue | ~ | ✓ | ✗ | ✓ | ✓ | ✗ | ✓ | ~ | ✓ | — | 3 |
| KioskErrorLayoutComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✓ | — | 1 |
| KioskErrorNetworkComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | — | — | 1 |
| KioskErrorMenuUnavailableComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | — | — | 1 |
| KioskErrorPaymentRefusedComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | — | — | 1 |
| KioskErrorProductRemovedComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | — | — | 1 |
| KsButton.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | — | — | 1 |
| KsChip.vue | ✗ | ✓ | ✓ | ✓ | ~ | ✗ | ✓ | — | — | — | 3 |
| KsFilterChip.vue | ✓ | ✓ | ✓ | ✓ | ~ | ✗ | ✓ | — | — | — | 2 |
| KsModal.vue | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ | ~ | — | ✓ | — | 3 |
| KsConsentModal.vue | ✓ | ✓ | ✗ | ✓ | ✓ | ✗ | ✓ | ~ | ✓ | — | 3 |
| KsA11ySettings.vue | ✓ | ✓ | ✗ | ✓ | ✓ | ✗ | ✓ | — | ✓ | — | 2 |
| KsAllergenBadge.vue | ✓ | ✓ | — | — | ✓ | ✓ | — | — | — | — | 0 |
| KsBadge.vue | ✓ | ~ | — | — | ~ | — | — | — | — | — | 2 |
| KsCard.vue | ✓ | ✓ | ✓ | ✓ | ~ | ✗ | ✓ | — | — | — | 2 |
| KsPriceLine.vue | ✓ | ✓ | — | — | ✓ | — | — | — | — | — | 0 |
| KsStepper.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | — | — | 1 |
| KsVirtualKeyboard.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | ~ | — | — | 2 |
| KioskStepPainComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 2 |
| KioskStepTailleComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 2 |
| KioskStepViandeComponent.vue | ~ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 2 |
| KioskStepSauceComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 2 |
| KioskStepGarnituresComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 2 |
| KioskStepSupplementsComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 2 |
| KioskStepMenuComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✗ | ✓ | 2 |
| KioskPaymentComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ~ | — | ✗ | — | 3 |
| KioskOrderSummaryComponent.vue | ✓ | ✓ | — | — | ✓ | ✗ | — | — | ✗ | ~ | 3 |
| KioskConfirmationComponent.vue | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | — | ✓ | — | 1 |

*« TOTAL FAIL » = nombre de critères ✗ + ~ comptés comme demi-dette (arrondi conservateur dans les agrégats §5).*

## 2. Défauts par sévérité

### 🔴 Critical (bloquant utilisation)

- **[C1]** Barre panier : `<div class="kiosk-cart-bar" @click>` sans rôle, sans `tabindex`, non opérable au clavier seul — `KioskAppComponent.vue` lignes 26-33 — **2.1.1 / 4.1.2**.
- **[C2]** Toasts : aucun `role="status"` / `aria-live` sur le conteneur ni les items — `KioskToastComponent.vue` lignes 1-12 — **4.1.3** (annonces changement de contexte).
- **[C3]** Toasts cliquables sans sémantique `button` / sans annonce de dismiss — même fichier — **2.1.1** (usage clavier ambigu).

### 🟠 Serious (impact majeur UX a11y)

- **[S1]** Bouton fermeture wizard **34×34 px** — `KioskWizardComponent.vue` lignes 1447-1456 — **2.5.5** (pratique kiosk ; < 44 px).
- **[S2]** Flèches étape **36×36 px** et **‹ ›** sans `aria-label` — `KioskWizardComponent.vue` lignes 63-86, 1573-1581 — **2.5.5 / 4.1.2**.
- **[S3]** Bouton « retirer » dans `KsChip` **28×28 px** — `KsChip.vue` lignes 168-176 — **2.5.5**.
- **[S4]** Modale abandon wizard : pas de **piège focus**, pas de **Escape** branché dans le script — `KioskWizardComponent.vue` lignes 131-160, 904-911 — **2.4.3 / 2.1.1**.
- **[S5]** `KsModal` : focus initial sur panneau (`KsModal.vue` lignes 95-99) mais **pas de cycle Tab** documenté ; Tab peut sortir vers le document — **2.4.3**.
- **[S6]** Chip header « Mon compte » **38 px** hauteur — `KioskCategoriesComponent.vue` lignes 918-933 — **2.5.5**.
- **[S7]** `outline: none` sur `:focus-visible` sans anneau de remplacement (filtre actif clear) — `KioskCategoriesComponent.vue` lignes 796-800 — **2.4.7**.
- **[S8]** Libellés étape wizard **10 px / #777** — `KioskWizardComponent.vue` lignes 1553-1560 — **1.4.3** (texte normal < 18 px, ratio limite / fail probable sur fond blanc).

### 🟡 Moderate

- **[M1]** Hiérarchie titres : **pas de `h1`** dans le wizard (seulement `h2` produit) — `KioskWizardComponent.vue` ligne 19 — **1.3.1**.
- **[M2]** `h1` puis `h3` (pas de `h2`) sur liste produits / catégories — `KioskProductListComponent.vue` lignes 16, 111 ; `KioskCategoriesComponent.vue` lignes 163, 242 — **1.3.1**.
- **[M3]** `KioskPaymentComponent.vue` : `h1` puis `h3` dans les tuiles — lignes 16, 50 — **1.3.1**.
- **[M4]** Transitions wizard `step-slide` sans `@media (prefers-reduced-motion)` local — `KioskWizardComponent.vue` lignes 1727-1740 — **2.3.3**.
- **[M5]** `KsButton` : animation `ks-spin` non coupée localement — `KsButton.vue` lignes 159-167 — **2.3.3**.
- **[M6]** `kiosk-wizard.css` : états `:disabled` primaire **#999 sur #ddd** — lignes 74-77 — **1.4.3** (libellé désactivé).
- **[M7]** `KsBadge` / `KsCard` : `ariaLabel` optionnel — risque d'**icône seule sans nom** si mauvaise utilisation — `KsBadge.vue` lignes 9-10 ; `KsCard.vue` lignes 4-7 — **4.1.2** (pattern).

### 🟢 Minor

- **[m1]** `KsFilterChip` : `role="checkbox"` sans `aria-labelledby` explicite (libellé visible dans slot seulement) — `KsFilterChip.vue` lignes 7-16 — **1.3.1** faible.
- **[m2]** `KioskOrderSummaryComponent.vue` : image avec `alt` mais parent `aria-hidden="true"` — lignes 5-7 — redondant / cohérence AT — **1.1.1** mineur.

## 3. CSS audit

**`kiosk-wizard.css`**
- **Touch** : `.kiosk-touch-btn` / `.kiosk-input` utilisent `var(--kiosk-touch-min)` lignes 44-47, 201-203 — **OK** si token ≥ 44 px (défaut **48 px** dans `tokens.css` ligne 140). Compteurs **56×56** lignes 278-292 — **OK**.
- **Contraste** : fonds désactivés **#ddd/#999** lignes 74-77 — **écart AA** sur libellé. Teinte ombre `rgba(233, 60, 60, 0.15)` ligne 114 — héritage pré-tokens (commentaire lignes 22-26).
- **Focus** : `:focus-visible` anneau sur `.kiosk-touch-btn`, `.kiosk-touch-option`, `.kiosk-counter-btn` lignes 470-475 — **OK**. `.kiosk-input:focus { outline: none; border-color: primary }` lignes 210-213 — **OK** si bordure 2 px contrastée.
- **Reduced motion** : `@media (prefers-reduced-motion: reduce)` lignes 478-489 — couvre **pulse/fadeIn** et **transitions** des classes utilitaires ; **ne couvre pas** les animations définies uniquement dans les SFC (wizard spin, toasts, shell).

**`tokens.css`**
- Couleurs texte / surfaces documentées **AA** (commentaires lignes 28-36). `--kiosk-focus-ring: #2563EB` ligne 47 — anneau lisible sur clair.
- **Reduced motion global** : durées motion → **0 ms** lignes 173-179 — **bonne base** pour tout ce qui consomme les `var()`.

## 4. Composants GATED W5 (fix différé)

Observations **sans recommandation de correctif** (périmètre TPE / reçu).

- **`KioskPaymentComponent.vue`** — **GATED W5** : pattern `role="radiogroup"` + `role="radio"` lignes 22-40 — bonne direction **4.1.2** ; **hiérarchie `h1`→`h3`** lignes 16, 50 — dette **1.3.1** ; `aria-live` sur message TPE ligne 159 — **positif**.
- **`KioskOrderSummaryComponent.vue`** — **GATED W5** : `role="status"` / `aria-live` lignes 94-95 — **positif** ; **`h4` sections sans `h2`/`h3` parent dans le composant** — **1.3.1** ; image/`aria-hidden` — voir **[m2]**.
- **`KioskConfirmationComponent.vue`** — **GATED W5** : racine `role="status"` `aria-live="polite"` — **positif** ; pas d'analyse corrective poussée des CTA / reçu.

## 5. Baseline metrics

- **Touch target violations** : **14**
- **Contrast violations** : **6**
- **ARIA / nom accessible manquant ou insuffisant** : **9**
- **`outline: none` sans remplacement sûr** : **6 occurrences** dans le grep SFC
- **Reduced motion incomplet au-delà des tokens** : **~28** composants sans `@media` local
- **Total composants** : **43** ; **défauts moyens par composant** : **~2,1**

## 6. Top fixes recommandées (impact LOC + zones touchées) — ordre priorité

1. **Barre panier** → `button` ou `role="button"` + `tabindex="0"` + libellé accessible (**~15 LOC**, `KioskAppComponent.vue`).
2. **Toasts** → conteneur `role="status"` `aria-live="polite"`, items non-interactifs ou vrai `button` « Fermer » (**~25 LOC**, `KioskToastComponent.vue`).
3. **Wizard** → agrandir fermeture/flèches ≥ **48–60 px**, `aria-label` sur flèches, `prefers-reduced-motion` sur `step-slide` + spinner, modale abandon = `KsModal` ou équivalent focus+Escape (**~80–120 LOC**, `KioskWizardComponent.vue`).
4. **`KsChip` remove** → min **44×44** ou hit-area invisible (**~10 LOC**, `KsChip.vue`).
5. **`KsModal` focus trap** → boucle Tab + `focusin` (**~40–60 LOC**, `KsModal.vue`).
6. **Titres** → un **`h1`** par écran + renumérotation **`h2`/`h3`** (**~30–50 LOC** réparties wizard + listes).
7. **Catégories** chip + clear filter → **min-height 48 px** + anneau focus (**~20 LOC**, `KioskCategoriesComponent.vue`).

## 7. Tests sentinelles à créer (axe-core)

- **`KioskAppComponent`** (monté avec barre panier) : **0** violation `keyboard`, **0** `aria-command-name` sur zone panier.
- **`KioskToastComponent`** après `show()` : **0** violation `region`.
- **`KioskWizardComponent`** (étape quelconque) : **0** `target-size` sur contrôles header/progress ; **0** `aria-required-children` sur modale abandon si migration.
- **`KsModal`** ouvert : **0** `focus-order-semantics` / custom rule focus trap.
- **`KioskCategoriesComponent`** grille + filtres : **0** `color-contrast` sur **10 px** labels si conservés.

## 8. Risques de régression visuelle

Augmenter **fermeture wizard** et **flèches** vers **48–60 px** réduit la grille du **stepper** (colonnes `42px 1fr 42px` ligne 1565) — risque **chevauchement** avec pistes sur petites largeurs. Chips header **48 px** peuvent **décaler** le header 82 px. **`KsChip` remove** plus grand peut **casser** alignement des compteurs. Vérifier **RTL** après changement de `padding`/`gap`.

## 9. Décisions techniques

**`@axe-core/vue` + `jest-axe`** (ou Vitest) en **montage shallow + vrais enfants** pour les flows critiques : meilleure **couverture des règles** liées au DOM réactif et aux transitions, **moins de faux négatifs** que `axe-core` brut sur HTML statique. **`axe-core` seul** en CLI reste utile pour **CI rapide** sur extraits HTML. Impact bundle : **devDependency uniquement** si tests hors prod ; ordre de grandeur **~+50–80 kiB** en dépendances de test — **négligeable** pour le bundle kiosk production.

---

**Référence interne** : tâche `18_TASK_TYPE_BUTTON_A11Y_K7_2026-04-20.md` confirme la piste **K-7** (`type="button"`, motion tokens, reduced-motion) — cohérent avec `tokens.css` L173-179 ; ce baseline va au-delà (kiosk complet + WCAG listée).
