# VERIFY P-MEGA-W6.A — A11y kiosk fixes 200%

**Date** : 2026-04-20
**Mode** : READONLY VERIFY (Phase A.3)
**HEAD** : `1dabfa568` — `[P-MEGA-W6-A] A11y kiosk fixes WCAG AA — barre panier + toasts + wizard + KsModal trap + tests axe-core`
**Subagent** : explore medium

## 0. Verdict global
**PASSED** (avec **DEGRADED** sur métriques RUN vs `git diff` et sur comptage tests).

- Fixes correctement appliqués : **~14/15** (écarts mineurs : libellés i18n flèches wizard ≠ « Étape précédente/suivante » ; hiérarchie zone catégories **h1** + **h2** au lieu de h3→h2 littéral).
- Composants gated breach : **non** (`git diff c1c89ff89..HEAD` vide sur les 3 fichiers).
- Hors-scope breach : **non** (`app/`, `database/`, `routes/`, `store/`, `router/`, `i18n.js`, `helpers/` absents du diff).
- Tests sentinelles qualité : **OK avec réserve** (allowlist axe sur catégories ; `color-contrast` off).
- Pièges logiques détectés : **3** (voir §5).

## 1. Vérification fixes par sévérité

### 🔴 Critical
- **C1 KioskAppComponent** (`KioskAppComponent.vue` ~26-41, ~731-754, ~199-203) : **OK** — `<button type="button">`, `aria-label` via `cartBarAriaLabel`, `@click.stop` conservé, reset bouton (`border: none`, `appearance`, `font: inherit`). Pas besoin de `@keydown` (natif).
- **C2/C3 KioskToastComponent** (`KioskToastComponent.vue` ~2-26) : **OK** — conteneur `role="status"` + `aria-live="polite"` + `aria-relevant` ; par toast `role` status/alert + `aria-live` ; bouton fermer + `aria-label`. **Note** : live sur conteneur **et** items = possible redondance annonces.

### 🟠 Serious
- **S1** fermeture wizard : **OK** — `.kiosk-wizard-close` `min-width/height: 48px` (styles ~1505+ dans `KioskWizardComponent.vue`).
- **S2** flèches stepper : **OK** taille 48px + `aria-label` i18n (`nav_previous` / `nav_next` = « PRÉCÉDENT » / « SUIVANT » en `fr.json` ~652-653) — **partiel** vs libellé audit « Étape précédente/suivante ».
- **S3** KsChip remove : **OK** — `.ks-chip__remove` 44×44 (`KsChip.vue` ~168-187).
- **S4** modale abandon : **OK** — focus premier `button` (`~1374-1376`), Tab trap (`~1348-1370`), Escape → `onAbandonCancel`. **Piège** : listener `document` **capture** (voir §5).
- **S4** grille : **OK** — `grid-template-columns: 48px 1fr 48px` (~1631).
- **S4** reduced motion : **OK** — `@media (prefers-reduced-motion: reduce)` sur `step-slide` (~1832-1843) ; entre états `opacity: 1` / `transform: none` (pas de « modale invisible »).
- **S5 KsModal** : **OK** — `onKeydown` sur `document` (`mounted`/`beforeUnmount`), Tab trap sur `modalRoot`, Escape + `stopPropagation` (~174-179). Nom `_handleKeydown` absent ; équivalent `onKeydown`.
- **S6/S7 KioskCategoriesComponent** : **OK** — `.kiosk-top-chip` `min-height: 48px` (~923-925) ; `.kiosk-active-filter-banner__clear` / `.kiosk-filter-reset` focus `box-shadow` ou `outline` (~797-801, ~829-831).

### 🟡 Moderate
- **M1** wizard `h1` sr-only : **OK** (`~17`, classe `.kiosk-wizard-sr-only` ~1493+).
- **M2** titres : **OK** — `KioskProductListComponent` `h2` produit (~111), header `h1` (~16). `KioskCategoriesComponent` **h1** zone (~163) + **h2** produit (~242) — meilleure hiérarchie que h3→h2 seul.
- **M5 KsButton** : **OK** — `@media (prefers-reduced-motion: reduce)` sur `.ks-btn__spinner` (~169-173).
- **M6 kiosk-wizard.css** : **OK** — `.kiosk-touch-btn-primary:disabled` `color: #555` (~74-77).
- **M7** : **OK** — `KsBadge` `ariaLabel` + `console.warn` si `iconOnly` sans label (~53-65) ; `KsCard` `ariaLabel` + warning (~71-87) ; `KsFilterChip` `aria-labelledby` + `labelId` (~10-17).

## 2. Composants gated W5 (non-touche)
- `KioskOrderSummaryComponent.vue` / `KioskPaymentComponent.vue` / `KioskConfirmationComponent.vue` : **`git diff c1c89ff89..HEAD -- <fichier>`** → **vide** — **PAS DE BREACH**.

## 3. Hors-scope
- Aucun fichier sous `app/`, `database/`, `routes/`, `resources/js/store/`, `router/`, `i18n.js`, `helpers/` dans le diff. **OK**.
- Fichier extra : **`.cursor/ACTIVE_CYCLE.md`** modifié (hors liste stricte ; gestion procédurale orchestrateur).

## 4. Tests sentinelles
- **Imports** : `import axe from 'axe-core'` (`kioskA11yAxe.spec.js` ~10) — **OK**.
- **`runAxe`** : `axe.run(el, { rules: { 'color-contrast': { enabled: false } }, ... })` (~28-35) — **OK** ; `expectAxeClean` filtre violations → `expect(bad).toEqual([])` (~44-47) — **OK** (pas `toBeDefined` seul).
- **Scénarios axe** : **5** `it` — App, Toast, Wizard, KsModal, Categories — **OK**.
- **Touch** : **3** `it` — Wizard, Categories chips, KsChip remove — **OK** (≥ 3).
- **`it.skip` / `it.todo`** : **aucun**.
- **Filtre axe** : allowlist `AXE_ALLOW_IDS_CATEGORIES` (3 règles) pour grille catalogue — **documenté** ; qualité **DEGRADED** vs « zéro exception ».
- **Écart RUN** : déclaration **12** tests nouveaux ; décompte **`it`** dans les 2 specs = **8**. À réconcilier (ou RUN compte autrement, ex: scénarios `expect` multiples).

## 5. Pièges logiques
1. **S4 abandon — Escape document capture** : peut intercepter Escape avant d'autres handlers (comportement voulu pour fermer la modale ; risque si autre couche attend Escape).
2. **KsModal — un seul focusable** : `first === last` ; Tab/Shift+Tab recentrent le même nœud — **OK**, pas de boucle infinie.
3. **C2 toasts** : double annonce possible (conteneur + item `aria-live`).

## 6. devDeps + lock
- `package.json` : `"axe-core": "^4.11.3"` en **devDependencies** — **OK**.
- **`package-lock.json`** : **absent** du workspace (glob 0) — pas de vérification de résolution verrouillée ici (à recréer si politique npm le impose).

## 7. Cohérence run report (`RUN_P_MEGA_W6_A_A11Y_EXECUTE_2026-04-20.md`)
- **`EXECUTE_DELEGATION`** : présent (l.4).
- **LOC** : déclaré ~280 ; **`git diff --stat c1c89ff89..HEAD`** → **828 insertions, 29 suppressions, 18 fichiers** — **non aligné** (déclaration sous-estime, déclaration probablement = LOC nettes ajoutées sur composants ciblés vs total).
- **Tests** : déclaré 12 ; **8** `it` dans `kioskA11y*.spec.js` — **non aligné** (sauf autre définition).
- **Axe avant/après** : décrit (désactivation `color-contrast`, allowlist catégories) — **OK**.
- **bug_signatures** : non listés explicitement dans le RUN lu.

## 8. Findings nouveaux
- **F-VERIFY-W6A-01** (LOW) : RUN vs git métriques LOC/tests incohérentes — purement docs.
- **F-VERIFY-W6A-02** (LOW) : libellés i18n flèches wizard accessibles (« PRÉCÉDENT/SUIVANT ») mais pas le libellé exact de l'audit baseline (« Étape précédente/suivante ») — UX différente.
- **F-VERIFY-W6A-03** (LOW) : `color-contrast` désactivé dans axe → contrastes non vérifiés en sentinelle (audit baseline en a relevé 6 dont libellés wizard 10px).
- **F-VERIFY-W6A-04** (MED) : Allowlist `AXE_ALLOW_IDS_CATEGORIES` (3 règles) — perméable ; à supprimer ou justifier précisément si chaque règle relève d'une fausse positive structurelle.

## 9. Recommandations
- **REM léger optionnel** : aligner RUN (LOC, nombre de tests) ; documenter pourquoi 8 vs 12 ; ajouter `package-lock` si politique npm l'impose ; supprimer ou justifier nominativement chaque entrée `AXE_ALLOW_IDS_CATEGORIES` ; améliorer libellés i18n flèches wizard pour rester proches de l'audit baseline.
- **CLOSED** sur présence des fixes et non-régression gated/hors-scope.
- **Suite** : Phase B.1 (audit perf baseline) + B.2 (EXECUTE perf) — peuvent commencer en parallèle de la REM légère ci-dessus si on souhaite.
