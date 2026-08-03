# PLAN_P_MEGA_W6_2026-04-20 — A11y kiosk + Perf kiosk (P-MEGA-15 + P-MEGA-16)

**Cycle parent** : `plans/PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20.md` Vague 6
**Date** : 2026-04-20
**Mode** : RUNNER_MODE single-session + auto-remediation **ACTIVÉE** (UI/CSS/build — pas de critical zone)
**HEAD baseline** : `c1c89ff89` (post-W5)
**Précédents immédiats** :
- W5 closed PASSED — 3 GATE_BRIEFs OUVERTS (TVA / TPE / NF525 receipt) → contrainte transverse W6 : **ne PAS toucher** les composants gated
- W4 REM_3 closed (`781232fb4`)
- 565/566 Vitest verts (1 échec untracked V14 hors scope, persistant cross-cycles)

---

## TASK_ID

`P_MEGA_W6_A11Y_PERF_2026-04-20`

---

## STRATÉGIE GÉNÉRALE — pourquoi EXECUTE direct est autorisé

Les 2 sujets W6 ne touchent **aucune** zone critique listée par `auto-remediation.mdc` :

| Sujet | Zone applicative | Critical zone (`auto-remediation.mdc`) ? |
|---|---|---|
| P-MEGA-15 | UI / CSS / ARIA kiosk Vue | NON |
| P-MEGA-16 | Build config (webpack.mix), lazy imports route, attribut `loading="lazy"` | NON |

→ **Auto-remediation ACTIVÉE** : la boucle DIAGNOSTIC → ROUTE → RE-EXECUTE → RE-AUDIT s'exécute sans halt humain tant que :
1. Aucune touche d'un fichier listé dans les **3 GATES OUVERTES W5** (cf. `SUBSYSTEMS_OFF_LIMITS`)
2. Aucune régression Vitest (564 tests scope-pertinents doivent rester verts)
3. Bundle size kiosk n'augmente pas de plus de **+10 %** vs baseline mesurée Phase B.1
4. Compteur d'essais < 3 par bug_signature (règle MAX 3)

→ **Halt humain** uniquement sur : violation des points 1-4, ou ESCALATION pré-déclarée déclenchée (cf. §ESCALATIONS).

---

## DECOUPAGE — 2 sous-cycles SÉQUENTIELS (W6.A puis W6.B)

### Choix : **SÉQUENTIEL** (W6.A complet → commit → W6.B)

**Justification (3 raisons)** :

1. **Conflit de fichiers prévisible** : P-MEGA-15 (a11y) modifie ARIA + CSS sur les **mêmes** composants kiosk (`KioskCategoriesComponent.vue`, `KioskWizardComponent.vue`, steps, ds/Ks*) que P-MEGA-16 (perf — lazy load images, dynamic imports route). Parallélisation = merge conflicts garantis sur ≥6 fichiers Vue. Coût merge > gain temps.

2. **Baseline B.1 doit inclure les deltas A.2** : la mesure bundle size + cold start de Phase B.1 doit refléter l'état post-a11y (a11y peut ajouter ~5-10 KB de CSS/ARIA strings). Mesurer avant A.2 = baseline obsolète au moment de B.2.

3. **Risque de dilution audit** : 2 audits parallèles sur des dimensions différentes (a11y qualitatif WCAG vs perf quantitatif Lighthouse) = orchestration cognitive double, contre la règle token discipline. Sequentiel = focus mono-dimension par sous-cycle.

**Coût** : +1 round wall-clock vs parallèle (~2× temps). Acceptable car cycle non-urgent (pas de gate fiscal sous-jacent).

### 6 phases au total (3 par sous-cycle)

| Phase | Nom court | Type | Bloque |
|---|---|---|---|
| A.1 | Mini-audit a11y + baseline | READ-ONLY | rien (point d'entrée) |
| A.2 | EXECUTE fixes a11y (severity-ordered) | WRITE code | A.1 |
| A.3 | Verify a11y 200% (axe-core + manual) | READ-ONLY | A.2 |
| B.1 | Mini-audit perf + baseline metrics | READ-ONLY | A.3 commit OK |
| B.2 | EXECUTE optims (lazy + split + preload) | WRITE code/config | B.1 |
| B.3 | Verify perf 200% (bundle size + approx cold start) | READ-ONLY | B.2 |

---

## RUNNER_MODE / PRIMARY_MODEL / SUBAGENT — par phase

### Phase A.1 — Mini-audit a11y + baseline metrics
- **PRIMARY_MODEL** : Claude (orchestration) → délégué `explore` readonly
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : pure lecture statique CSS/Vue/ARIA. Aucun risque écriture.
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_15_A11Y_BASELINE_2026-04-20.md`
- **LOC report** : ~180 lignes markdown
- **Output requis** : matrice par composant kiosk × {touch target ≥44px / contraste AA / focus order / ARIA / reduced-motion / keyboard nav} avec compteur PASS/FAIL + citation `file:line`

### Phase A.2 — EXECUTE fixes a11y
- **PRIMARY_MODEL** : Composer (`foodking-routine-implementer`)
- **SUBAGENT** : `foodking-routine-implementer`
- **Justification routing** : modifications CSS + attributs ARIA + `tabindex` + `@media (prefers-reduced-motion)` + `min-height/min-width` = catégorie "UI copy / CSS / boilerplate" autorisée à Composer (`routing.md`). Aucune logique pricing/auth/dispatch/branch_id touchée.
- **CODE_FILES (autorisés)** :
  - `resources/css/kiosk-wizard.css`
  - `resources/css/kiosk/*.css` (si découpage par composant existe — à confirmer A.1)
  - `resources/js/components/frontend/kiosk/Kiosk*.vue` (a11y attrs uniquement, **pas** de logique métier)
  - `resources/js/components/frontend/kiosk/steps/Kiosk*.vue`
  - `resources/js/components/frontend/kiosk/ds/Ks*.vue` (focus, ARIA, contraste tokens)
- **TEST_FILES (nouveaux)** :
  - `tests/js/kioskA11yAxeCore.spec.js` (mount + axe-core sur 3 composants critiques min)
  - `tests/js/kioskA11yTouchTarget.spec.js` (computed style ≥44×44 sur boutons critiques)
- **DEPS dev** : ajout `@axe-core/vue` + `axe-core` en devDependencies via `npm install -D` — **ESCALATION E3 si refusé par auto-remediation**
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_15_A11Y_EXECUTE_2026-04-20.md`
- **LOC code estimées** : 150-220 (CSS ~100, ARIA/Vue ~80, tests ~80)

### Phase A.3 — Verify a11y 200%
- **PRIMARY_MODEL** : Claude → délégué `explore` readonly
- **SUBAGENT** : `explore` (thoroughness "medium" — focus diff A.2)
- **Justification routing** : audit indépendant du diff produit en A.2. Lecture seule.
- **REPORT_FILE** : `reports/execution/VERIFY_P_MEGA_15_A11Y_200_2026-04-20.md`
- **LOC report** : ~120 lignes
- **DoD** : 0 violation axe-core severity=critical, 0 violation severity=serious sur 3 composants min ; checklist WCAG 2.5.5 / 1.4.3 / 2.4.3 / 2.4.7 cochée par citation file:line

### Phase B.1 — Mini-audit perf + baseline
- **PRIMARY_MODEL** : Claude → délégué `explore` readonly
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : lecture statique `webpack.mix.js` + `package.json` + `kioskRoutes.js` + composants kiosk lourds + run `npm run prod` (read-only sur arborescence `public/js`).
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_16_PERF_BASELINE_2026-04-20.md`
- **LOC report** : ~180 lignes
- **Output requis** : (1) bundle size actuel par chunk `public/js/*.js`, (2) liste des `import` statiques kiosk qui pourraient être `defineAsyncComponent`, (3) liste des images sans `loading="lazy"`, (4) hot path imports chains (top 5 plus gros), (5) approximation cold-start (`Date.now()` mount kiosk root via vitest harness — pas Lighthouse)

### Phase B.2 — EXECUTE optimisations
- **PRIMARY_MODEL** : Composer (`foodking-routine-implementer`)
- **SUBAGENT** : `foodking-routine-implementer`
- **Justification routing** : config webpack.mix (config), `loading="lazy"` (UI attribute), `defineAsyncComponent` (boilerplate Vue refactor) = scope routine. **CONDITIONAL ESCALATION** : si l'audit B.1 révèle besoin de `splitChunks` non-trivial (cacheGroups custom, runtimeChunk separate, deterministic chunkIds), bascule vers `foodking-complex-implementer` (GPT-5.4) — décision documentée dans REPORT_FILE B.1.
- **CODE_FILES (autorisés)** :
  - `webpack.mix.js` (config bundling)
  - `resources/js/router/modules/kioskRoutes.js` (lazy route imports)
  - `resources/js/components/frontend/kiosk/Kiosk*.vue` (uniquement : conversion `import` statique → `defineAsyncComponent`, ajout `loading="lazy"` sur `<img>`)
  - `package.json` (ajout devDep `webpack-bundle-analyzer` si décidé en B.1)
- **TEST_FILES (nouveaux)** :
  - `tests/js/kioskBundleSizeBudget.spec.js` (lit `public/js/*kiosk*.js`, fail si > seuil mesuré +10%)
  - `tests/js/kioskColdStartApprox.spec.js` (mount + performance.now(), seuil approximatif documenté)
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_16_PERF_EXECUTE_2026-04-20.md`
- **LOC code estimées** : 80-130 (webpack config ~30, route lazy ~20, components lazy ~30, tests ~50)

### Phase B.3 — Verify perf 200%
- **PRIMARY_MODEL** : Claude → délégué `explore` readonly
- **SUBAGENT** : `explore` (thoroughness "medium")
- **REPORT_FILE** : `reports/execution/VERIFY_P_MEGA_16_PERF_200_2026-04-20.md`
- **LOC report** : ~120 lignes
- **DoD** : bundle kiosk post ≤ baseline +10%, idéalement -X% (à fixer en B.1) ; cold start approx ≤ baseline ; 0 régression Vitest (564 verts) ; 0 import circulaire détecté.

---

## SUBSYSTEMS_TOUCHED — par sous-cycle

### W6.A (a11y) — fichiers autorisés en WRITE

| Path | Phase | Intent |
|---|---|---|
| `resources/css/kiosk-wizard.css` | A.2 | write (CSS contrast, min-height, focus-visible, prefers-reduced-motion) |
| `resources/css/kiosk/*` (si existe) | A.2 | write (idem) |
| `resources/js/components/frontend/kiosk/*.vue` | A.2 | write (ARIA labels/roles/live regions, tabindex, **PAS** de modification de logique métier) |
| `resources/js/components/frontend/kiosk/steps/*.vue` | A.2 | write (idem ARIA) |
| `resources/js/components/frontend/kiosk/ds/Ks*.vue` | A.2 | write (focus, contraste tokens, ARIA) |
| `tests/js/kioskA11yAxeCore.spec.js` | A.2 | write NEW |
| `tests/js/kioskA11yTouchTarget.spec.js` | A.2 | write NEW |
| `package.json` | A.2 | write (devDep `@axe-core/vue` + `axe-core`) |
| `package-lock.json` | A.2 | write (lockfile auto) |

### W6.B (perf) — fichiers autorisés en WRITE

| Path | Phase | Intent |
|---|---|---|
| `webpack.mix.js` | B.2 | write (chunking config, **conditionnel** Composer/GPT-5.4 selon audit B.1) |
| `resources/js/router/modules/kioskRoutes.js` | B.2 | write (lazy imports `() => import(...)`) |
| `resources/js/components/frontend/kiosk/Kiosk*.vue` | B.2 | write (`defineAsyncComponent`, `loading="lazy"` sur `<img>`, `decoding="async"`) |
| `tests/js/kioskBundleSizeBudget.spec.js` | B.2 | write NEW |
| `tests/js/kioskColdStartApprox.spec.js` | B.2 | write NEW |
| `package.json` | B.2 (cond.) | write (devDep `webpack-bundle-analyzer` si décidé) |

---

## SUBSYSTEMS_OFF_LIMITS (toutes phases)

**Critical zones (`auto-remediation.mdc` — toute touche = HALT immédiat)** :
- `app/**` (toute logique back) — **TOTAL OFF LIMITS** (W6 = front + build only)
- `database/migrations/**`
- `routes/**`
- `app/Http/Middleware/Auth*`, `routes/auth*`
- `branch_id` filtering, `OrderStatus` strings, `dispatch(...)` hors `afterCommit`, pricing front

**Zones gated W5 (3 GATES OUVERTES — interdiction de touche cosmétique pour ne pas lever de facto les gates)** :
- `app/Services/Pricing/**` — gated P-MEGA-12
- `app/Services/PaymentService.php`, `PaymentManagerService.php` — gated P-MEGA-13
- `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php` — gated P-MEGA-13
- `app/Http/Resources/OrderDetailsResource.php` — gated P-MEGA-14
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — gated P-MEGA-14 (ticket NF525 — **A.2 et B.2 ne doivent PAS éditer ce fichier même pour ARIA/lazy ; si audit identifie besoin → ESCALATION E4**)
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` — gated P-MEGA-13 (idem)

**Zones admin POS** (out of scope plan source — kiosk only) :
- `resources/js/components/admin/pos/**` — out of scope (W6 ciblé kiosk public)

**Zones worktree V14 non commité** :
- ne pas lire/écrire fichiers staged hors HEAD (cf. `git status` initial)

---

## INVARIANTS_AT_RISK

1. **Aucun invariant fiscal/payment/branch_id touché** (W6 = UI/CSS/build only — confirmé par OFF_LIMITS).
2. **Risque visual regression** (R1 majeur) : passage touch target 32→44px peut faire déborder grids existantes (KioskCategoriesComponent grid 4 colonnes notamment). Mitigé par : audit A.1 mesure dimensions actuelles avant fix ; A.2 utilise `min-height` (pas `height`) + `padding` plutôt que `height` fixe ; A.3 vérifie via tests Vitest snapshot des grilles principales.
3. **Risque a11y trop agressif sur reduced-motion** (R2) : désactiver toutes animations peut casser feedback paiement (loader, success). Mitigé par : `prefers-reduced-motion: reduce` désactive **uniquement** animations non-essentielles (carrousel promo, transitions stepper) ; conserve loader paiement + transitions de focus visible.
4. **Risque lazy-load above-the-fold** (R3) : placeholder gris sur premier viewport si trop agressif. Mitigé par : audit A.1/B.1 identifie images "above-the-fold" (hero, première ligne catégories) → exclues du `loading="lazy"` ; règle Composer : `loading="lazy"` ajouté UNIQUEMENT sur `<img>` dans listings/carousels au-delà du fold.
5. **Risque code splitting brise imports circulaires existants** (R4) : conversion `import` statique → `defineAsyncComponent` peut révéler des cycles. Mitigé par : audit B.1 trace les import chains des composants ciblés AVANT conversion ; B.3 vérifie 0 erreur `npm run prod` + 0 warning circular.
6. **Invariant 6 (frozen zones) — non applicable W6** mais rappelé : les fichiers kiosk receipt/payment sont gated W5 → traités comme frozen pour W6.

---

## GATE_CONDITIONS

### Hard gates pré-déclarées : **AUCUNE** (zone non-critique)

### Soft gates W6 (forcent halt même en auto-remediation) :

| ID | Trigger | Action |
|---|---|---|
| SOFT_W6_BUNDLE | Bundle kiosk post-B.2 > baseline B.1 × 1.10 | HALT, write `docs/gates/GATE_W6_BUNDLE_REGRESSION_2026-04-20.md`, demander arbitrage humain (rollback B.2 vs accepter régression vs micro-revert ciblé) |
| SOFT_W6_VITEST | Régression Vitest scope-pertinent (chute < 564 verts hors le 1 untracked posNormalizeIds connu) | HALT, diagnostic + remediation (compteur MAX 3 s'applique) |
| SOFT_W6_VISUAL | Détection visuelle qu'un composant gated W5 (KioskOrderSummaryComponent, KioskPaymentComponent) a été modifié dans un diff A.2 ou B.2 | HALT immédiat, revert du fichier, gate brief obligatoire (ESCALATION E4) |
| SOFT_W6_AXE_REG | A.3 trouve une nouvelle violation axe-core severity=critical introduite par A.2 | HALT, remediation (compteur MAX 3) |

### Soft gates héritées W5 (rappel — ouvertes en parallèle, non décidées) :
- GATE_P_MEGA_12 (TVA), GATE_P_MEGA_13 (TPE), GATE_P_MEGA_14 (NF525 receipt) — W6 **ne lève pas** ces gates ; tout chevauchement = STOP.

---

## ESCALATIONS pré-déclarées (count = 4)

- **E1 (Phase B.1) — Bundler discordance plan source** : le plan source `PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20.md` mentionne `vite.config.js` mais le projet utilise **Laravel Mix (webpack)** — confirmé par présence `webpack.mix.js` à la racine et absence totale de fichier `vite.config*`. ESCALATION pré-déclarée : Phase B.1 doit lire `webpack.mix.js` (pas Vite), B.2 doit utiliser `mix.extract()` / `splitChunks` webpack (pas Vite chunking). Outillage analyse bundle = `webpack-bundle-analyzer` (pas `rollup-plugin-visualizer` ni `vite-bundle-analyzer`). **Documenté ici pour qu'aucun subagent ne perde du temps à chercher Vite.**
- **E2 (Phase B.1/B.3) — Mesure cold start réel impossible sans device kiosk** : pas d'infra Lighthouse CI, pas de Chromebox/Pi physique disponible. Cold start <1.5s ne peut être mesuré que par approximation Vitest (`performance.now()` autour du mount du root composant kiosk via happy-dom). Si la mesure approximation est jugée non-représentative en B.3 → bascule vers proxy `bundle size budget` comme métrique d'acceptation, et le seuil "<1.5s" est requalifié en "bundle gzip ≤ X KB" (X défini par baseline B.1). Décision documentée dans VERIFY_P_MEGA_16.
- **E3 (Phase A.2) — Ajout devDep `@axe-core/vue` + `axe-core`** : ajoute 2 devDependencies au `package.json`. Bien que devDep ≠ runtime, `package.json` est sensible (touche par tous les subagents passés). Si auto-remediation refuse l'ajout (politique non explicitée dans `routing.md`) → ESCALATION : valider l'ajout avec humain. **Pré-validation orchestrateur** : devDep d'outillage tests = même catégorie que `@vue/test-utils` déjà présent → ajout autorisé par défaut, mais flagué par sécurité.
- **E4 (transverse A.2 + B.2) — Touche fichier gated W5** : si l'audit A.1 ou B.1 identifie qu'un fix a11y/perf nécessite d'éditer `KioskOrderSummaryComponent.vue` ou `KioskPaymentComponent.vue` → STOP immédiat, gate brief, demande humaine (sinon W6 lève de facto les gates W5 sans décision). Mitigation : ces 2 fichiers sont **whitelistés OFF_LIMITS** dans le prompt subagent A.2 et B.2 dès l'invocation.

---

## Test strategy

| Phase | Stratégie | Détail |
|---|---|---|
| A.1 | `no-test` (audit) | Lecture statique. Aucun nouveau test. |
| A.2 | `vitest:axe-core + vitest:touch-target` | 2 nouveaux fichiers tests (`tests/js/kioskA11y*.spec.js`). Couvre 3 composants critiques min (KioskCategoriesComponent, KioskWizardComponent root, 1 KsButton DS). DoD : 0 violation axe-core severity=critical, ≥6 cas verts (3 axe + 3 touch target). |
| A.3 | `no-test` + audit | Vérifie diff A.2 vs DoD. Run global Vitest pour confirmer 564+6=570 verts attendus. |
| B.1 | `no-test` (audit) | Lecture statique + `npm run prod` headless pour mesurer `public/js/*.js`. Aucun nouveau test. |
| B.2 | `vitest:bundle-budget + vitest:cold-start-approx` | 2 nouveaux fichiers tests. Bundle budget : test lit fichier `public/js/*kiosk*.js` post-build, fail si > seuil B.1 +10%. Cold start : mount root kiosk via happy-dom, `performance.now()` start→after-mount, fail si > seuil B.1 défini (typiquement ~50-100ms en jsdom, **non comparable** au cold start device). |
| B.3 | `no-test` + audit | Run global Vitest pour confirmer 570+2=572 verts attendus + `npm run prod` 0 erreur, 0 warning circular. |

**Pas de Playwright en W6** : infra Playwright non confirmée stable (cf. ACTIVE_CYCLE), et a11y/perf au sens WCAG demande device réel pour validation finale (déclaré dans E2). Tests Vitest = **proxy local-validation**, pas substitut au Lighthouse CI.

**Nouveau total Vitest attendu** : `565 baseline + 6 a11y + 2 perf = 573` (et 1 untracked posNormalizeIds toujours rouge — pas dans scope W6).

---

## DoD précis par phase

### A.1 — DoD audit a11y
- [ ] `AUDIT_P_MEGA_15_A11Y_BASELINE_2026-04-20.md` produit
- [ ] Matrice composants × critères WCAG (touch target / contraste / focus / ARIA / motion / keyboard) avec PASS/FAIL + citation file:line
- [ ] Compteurs baseline : `touch_target_failed_count`, `contrast_failed_count`, `aria_missing_count`, `keyboard_unreachable_count`
- [ ] Recommandations triées par sévérité (critical → minor) avec estimation LOC fix par item
- [ ] 0 fichier modifié (vérifié `git status`)

### A.2 — DoD EXECUTE a11y
- [ ] `RUN_P_MEGA_15_A11Y_EXECUTE_2026-04-20.md` produit
- [ ] Tous les FAIL `severity=critical` de A.1 fixés ; FAIL `severity=serious` ≥ 80% fixés
- [ ] 2 nouveaux fichiers test verts (≥6 cas total)
- [ ] Vitest global ≥ 570 verts (565 + 6, marge 1)
- [ ] 0 fichier `app/**`, `database/**`, `routes/**` modifié (vérifié `git diff --name-only`)
- [ ] 0 fichier gated W5 modifié (`KioskOrderSummaryComponent.vue`, `KioskPaymentComponent.vue`)
- [ ] devDep `@axe-core/vue` + `axe-core` ajoutées (E3 pré-validée)

### A.3 — DoD verify a11y
- [ ] `VERIFY_P_MEGA_15_A11Y_200_2026-04-20.md` produit (audit indépendant du diff A.2)
- [ ] Confirmation 0 violation axe-core severity=critical sur composants ciblés
- [ ] Cross-check WCAG 2.5.5 / 1.4.3 / 2.4.3 / 2.4.7 / 2.3.3 (motion) avec citation
- [ ] Recommendation CLOSED ou REMEDIATION_NEEDED avec bug_signature explicite

### B.1 — DoD audit perf
- [ ] `AUDIT_P_MEGA_16_PERF_BASELINE_2026-04-20.md` produit
- [ ] Mesure baseline : taille `public/js/*.js` par chunk (KB brut + KB gzip si possible via `gzip -c | wc -c`)
- [ ] Liste imports statiques kiosk candidats `defineAsyncComponent`
- [ ] Liste images `<img>` sans `loading="lazy"` (avec position above/below fold)
- [ ] Approximation cold start vitest mount root kiosk (chiffre informatif, pas critère acceptation)
- [ ] Décision : Composer (routine) ou GPT-5.4 (complex) pour B.2 — selon complexité splitChunks identifiée
- [ ] 0 fichier modifié

### B.2 — DoD EXECUTE perf
- [ ] `RUN_P_MEGA_16_PERF_EXECUTE_2026-04-20.md` produit
- [ ] Bundle kiosk post-build ≤ baseline B.1 (idéalement -X%, X défini en B.1)
- [ ] Routes kiosk lazy : `kioskRoutes.js` utilise `() => import(...)` pour ≥ 80% des routes
- [ ] Images below-fold : `loading="lazy"` + `decoding="async"`
- [ ] 2 nouveaux tests verts (bundle budget + cold start approx)
- [ ] Vitest global ≥ 572 verts
- [ ] `npm run prod` exit 0, 0 warning circular dependency
- [ ] 0 fichier OFF_LIMITS touché

### B.3 — DoD verify perf
- [ ] `VERIFY_P_MEGA_16_PERF_200_2026-04-20.md` produit
- [ ] Confirmation bundle delta vs baseline (KB et %)
- [ ] Confirmation chunks émis : kiosk-app séparé de admin/pos
- [ ] Recommendation CLOSED ou REMEDIATION_NEEDED

---

## Estimation LOC par sous-cycle

| Sous-cycle | Phase | Type | LOC |
|---|---|---|---|
| W6.A | A.1 | Markdown audit | ~180 |
| W6.A | A.2 | Code prod CSS | ~80-100 |
| W6.A | A.2 | Code prod Vue (ARIA) | ~50-80 |
| W6.A | A.2 | Code tests | ~80 |
| W6.A | A.3 | Markdown verify | ~120 |
| W6.A | **Sous-total** | — | **~510-560** |
| W6.B | B.1 | Markdown audit | ~180 |
| W6.B | B.2 | Code config webpack | ~30 |
| W6.B | B.2 | Code router lazy | ~20 |
| W6.B | B.2 | Code components lazy | ~30-50 |
| W6.B | B.2 | Code tests | ~50 |
| W6.B | B.3 | Markdown verify | ~120 |
| W6.B | **Sous-total** | — | **~430-450** |
| **TOTAL W6** | | | **~940-1010** (dont ~210-280 LOC code prod) |

---

## METRICS BASELINE à mesurer en A.1 (a11y) et B.1 (perf) AVANT EXECUTE

### A.1 baseline a11y (à reporter dans `AUDIT_P_MEGA_15_A11Y_BASELINE_*.md`)

| Metric | Méthode mesure | Cible post-A.2 |
|---|---|---|
| `touch_target_failed_count` | grep CSS `min-height` / `height` < 44px sur boutons kiosk + lecture computed style sur 8 composants critiques | 0 sur composants critiques |
| `contrast_failed_count` | axe-core run sur 5 composants critiques (mount + check) | 0 violation critical/serious |
| `focus_visible_missing_count` | grep `:focus` / `:focus-visible` dans CSS kiosk + check sélecteurs interactifs | 0 manquant sur boutons/links |
| `aria_label_missing_count` | grep `<button` / `<a` sans `aria-label` ni texte enfant dans Kiosk*.vue | 0 sur éléments icon-only |
| `aria_live_region_count` | grep `aria-live` / `role="status"` / `role="alert"` | ≥3 (toast, error, loading) |
| `prefers_reduced_motion_respected` | grep `@media (prefers-reduced-motion)` dans CSS kiosk | au moins 1 occurrence couvrant animations majeures |
| `keyboard_traps_count` | audit manuel séquence Tab sur 3 flows (catégorie → wizard → cart) | 0 |

### B.1 baseline perf (à reporter dans `AUDIT_P_MEGA_16_PERF_BASELINE_*.md`)

| Metric | Méthode mesure | Cible post-B.2 |
|---|---|---|
| `bundle_kiosk_total_kb` | `npm run prod` puis `du -k public/js/*kiosk*.js` (sommé) | ≤ baseline (idéalement -10% à -20%) |
| `bundle_kiosk_gzip_kb` | `gzip -c public/js/*kiosk*.js \| wc -c` | ≤ 250 KB cible plan source |
| `chunks_count_kiosk` | `ls public/js/*kiosk*.js \| wc -l` | ≥ 2 (split route-level visible) |
| `lazy_images_count` | grep `loading="lazy"` sur `<img>` kiosk | augmenté de N (N défini en B.1) |
| `dynamic_imports_count_kiosk` | grep `() => import(` dans `kioskRoutes.js` + `defineAsyncComponent` dans Kiosk*.vue | ≥ 80% routes kiosk lazy |
| `cold_start_approx_ms` | vitest harness mount KioskAppComponent + `performance.now()` (jsdom — informatif seulement, pas critère) | ≤ baseline (informatif) |
| `circular_deps_warnings` | `npm run prod` stderr grep `Circular` | 0 |

---

## Risques principaux (3 lignes max)

1. **R1 visual regression touch target 44px** : grids 4-cols KioskCategoriesComponent peuvent déborder → mitigé par `min-height` + `padding` (pas `height` fixe), tests snapshot Vitest sur grilles principales en A.2.
2. **R2 ESCALATION E4 (touche fichier gated W5)** : si audit A.1 ou B.1 trouve des FAIL critiques sur `KioskOrderSummaryComponent.vue` ou `KioskPaymentComponent.vue`, W6 ne peut pas les fixer sans lever les gates W5 — STOP gate brief requis.
3. **R3 cold start non-mesurable** : sans Lighthouse CI ni device kiosk, le critère "<1.5s" est requalifié en bundle size budget (E2) — risque que humain conteste l'acceptation B.3 en disant "pas vraiment vérifié sur device".

---

## Ordre d'exécution

1. **A.1** — invoquer 1× `explore` very thorough sur scope a11y kiosk → produire baseline + recommendations.
2. **Lecture résumé A.1** par orchestrateur → écriture `AUDIT_P_MEGA_15_A11Y_BASELINE_*.md` consolidé.
3. **A.2** — invoquer 1× `foodking-routine-implementer` avec scope strict (whitelist fichiers + OFF_LIMITS gated W5) + DoD précis. Boucle auto-remediation si KO normal (MAX 3 tentatives par bug_signature).
4. **A.3** — invoquer 1× `explore` medium sur diff A.2 → produire VERIFY. Si REMEDIATION_NEEDED → boucle vers A.2.
5. **Commit atomique W6.A** (suggéré, après validation user — orchestrateur ne commit pas seul).
6. **B.1** — invoquer 1× `explore` very thorough sur scope perf + run `npm run prod` headless pour baseline.
7. **Lecture résumé B.1** + décision routing B.2 (Composer vs GPT-5.4 selon complexité splitChunks).
8. **B.2** — invoquer subagent décidé en 7 avec scope + DoD. Boucle auto-remediation si KO.
9. **B.3** — invoquer 1× `explore` medium sur diff B.2 → produire VERIFY.
10. **Synthèse W6** — orchestrateur écrit `SYNTHESE_P_MEGA_W6_2026-04-20.md` agrégeant les 6 phases + final report per `auto-remediation.mdc` template.
11. **Commit atomique W6.B** (suggéré, après validation user).
12. **Update `.cursor/ACTIVE_CYCLE.md`** : PHASE = `CLOSED PASSED` (ou `BLOCKED_HUMAN_GATE` si E4 déclenché ou soft gate W6 hit).

---

## ACTIVE_CYCLE update prévu

À l'ouverture du cycle :
- TASK_ID = `P_MEGA_W6_A11Y_PERF_2026-04-20`
- PHASE = `EXECUTE — sous-cycle A (a11y)` (puis B après commit A)
- PRIMARY_MODEL = `Claude (orchestration) + explore (audits) + foodking-routine-implementer (EXECUTE A.2 + B.2 conditionnel)`
- PLAN_FILE = `plans/PLAN_P_MEGA_W6_2026-04-20.md` (ce fichier)
- REPORT_FILES = 6 (3 audit + 3 verify) + 1 synthèse
- GATE_FILES = aucun pré-déclaré (soft gates W6 conditionnels uniquement)
- RUNNER_MODE = single-session
- AUTO_REMEDIATION = ACTIVÉE (pas de critical zone — UI/CSS/build only)

À la fin de B.3 :
- PHASE = `CLOSED PASSED` (cas nominal) OU `BLOCKED_HUMAN_GATE` si soft gate W6 hit OU `BLOCKED_HUMAN_GATE` si E4 déclenché
- NEXT = "humain commit W6 atomique + W7 (P-MEGA-17 offline) ou retour W5 décisions gates"

---

## Manifeste

> Vague 6 = **2 sous-cycles séquentiels** (a11y puis perf) avec auto-remediation **activée** (zones non-critiques UI/CSS/build only). Chaque sous-cycle suit le triplet **mini-audit baseline → EXECUTE bordé → verify 200%**. Aucun hard gate pré-déclaré, mais 4 ESCALATIONs anticipées (bundler Mix vs Vite, mesure cold start impossible sans device, ajout devDep axe-core, touche fichier gated W5). Soft gates W6 (bundle +10%, axe regression, touche W5-gated, vitest regression) forcent halt même en auto-remediation. Le but n'est pas d'atteindre le score Lighthouse 95+ (impossible à valider sans device) mais de **réduire la dette a11y mesurable (axe-core) + la dette bundle mesurable (KB chunks)** avec preuve par tests Vitest. Cycles d'implémentation futurs (P-MEGA-17 offline) restent indépendants.
