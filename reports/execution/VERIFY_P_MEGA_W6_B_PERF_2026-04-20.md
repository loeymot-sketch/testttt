# VERIFY P-MEGA-W6.B — Perf kiosk fixes 200%

**Date** : 2026-04-20
**Mode** : READONLY VERIFY (Phase B.3)
**HEAD** : `0a3e0b304` (`[P-MEGA-W6-B] Perf kiosk lazy chunks — KioskAdmin async + steps async + sub-chunks routing + dead code cleanup`)
**Subagent** : explore medium

## 0. Verdict global

**PASSED** — F3/F4/F6/F7 cohérents dans le code ; gated W5 / blacklist cible OK ; script budget OK. **Caveats** : test F7 dépend de `grep` POSIX ; test F6 n'assert pas explicitement le chunk `kiosk-admin` ; fichiers hors périmètre `.cursor/` + `reports/` touchés dans le diff commit (procédural, attendu).

## 1. F3 — KioskAdminComponent async

**Présent.** `KioskAppComponent.vue` :
- `import { defineAsyncComponent } from 'vue'` (L124)
- `defineAsyncComponent(() => import(/* webpackChunkName: "kiosk-admin" */ './KioskAdminComponent.vue'))` (L128–129)
- Plus d'`import KioskAdminComponent from` statique
- Enregistrement dans `components: { … KioskAdminComponent … }` (L165)
- Template `<KioskAdminComponent v-if="showAdmin" …>` (L112–115)
- Aucun appel `KioskAdminComponent.*` dans le script (pas de méthode statique)

⚠️ **Risque résiduel** : pas de `loadingComponent` / `errorComponent` → vide possible si chunk lent ou 404 (cf. §8).

## 2. F4 — Wizard steps async

**OK.** `KioskWizardComponent.vue` :
- 7 steps en `defineAsyncComponent` avec `webpackChunkName: "kiosk-wizard-step"` : Pain, Taille, Viande, Sauce, Garnitures, Supplements, Menu (L199–218)
- `KioskOrderSummary` import synchrone (L172) — décision OK
- Anciens imports statiques des steps retirés
- `components` map inclut les 7 + summary (L224–234)
- Support `<component :is>` : `currentStepComponent` renvoie le nom enregistré (`'KioskStepPain'`, …, `'KioskOrderSummary'`) (L450–451, L420–423) — compatible async

## 3. F6 — Sub-chunks routing

`kioskRoutes.js` :
- `kiosk-shell` (app, login, idle, categories, cart, loyalty, upsell, payment, waiting, confirmation, cash…)
- `kiosk-wizard` (wizard + pos wizard L10–11)
- `kiosk-admin` (L18, route `kiosk.admin` L210–214)
- `kiosk-errors` (erreurs L21–24)
- Tous en `() => import(...)`
- Pas de chunk name monolithique `kiosk` seul

## 4. F7 — Suppression KioskProductListComponent

- Fichier supprimé dans le diff
- Aucune trace trackée `git ls-files`
- 0 référence sous `resources/js` (grep)

## 5. Composants gated W5 non-touchés

`git diff 1dabfa568..0a3e0b304 -- KioskOrderSummaryComponent.vue KioskPaymentComponent.vue KioskConfirmationComponent.vue` → **sortie vide**. PAS DE BREACH.

## 6. Hors-scope non-touchés

Diff n'inclut pas : `app/**`, `database/**`, `routes/**`, `webpack.mix.js`, `resources/js/app.js`, `bootstrap.js`, `master.blade.php`, `store/**`, `helpers/**`, `i18n.js`. **OK.**

Fichiers extra (procéduraux, attendus) : `.cursor/ACTIVE_CYCLE.md`, `reports/execution/RUN_…`, `reports/post_execute_latest.log`.

## 7. Tests sentinelles & script budget

`tests/js/kioskPerfChunks.spec.js` :
- 4 `it`, assertions réelles (`toMatch`, `toBe`)
- Pas `it.skip` / `it.todo`
- **Manque** : pas d'`expect` sur `webpackChunkName: "kiosk-admin"` dans `kioskRoutes.js` (sentinel F6 partielle)
- **F7** : `execSync('grep -r …')` — risque non-portable Windows / shells sans `grep`

`tools/perf/check_bundle_budget.mjs` :
- ESM, lit `public/js`, taille KB vs `BUDGETS_KB`
- `exit(1)` si dépassement (L43)
- `exit(1)` si dossier absent (L24–26)
- `kiosk.js` 600 KB — aligné audit (514 → marge ~17%)

`package.json` : script `perf:bundle-check` (L13).

## 8. Pièges logiques

- **Admin async** : aucun état chargement/erreur explicite dans `defineAsyncComponent` → vide possible si chunk slow/404
- **Wizard async** : premier passage sur un step peut charger le chunk partagé — flash possible mais acceptable (1 fois, partagé entre tous steps)
- **Chunk `kiosk-admin`** : même nom route + overlay app → un seul module webpack attendu (même `.vue`) — OK
- **Collision noms** : autres `webpackChunkName` identiques ailleurs non audités en profondeur (mais non kiosk → bas risque)

## 9. Cohérence run report

`RUN_P_MEGA_W6_B_PERF_LOWRISK_EXECUTE_2026-04-20.md` :
- `EXECUTE_DELEGATION:` présent (L3)
- LOC +188 / −720 = match `git diff --stat`
- 4 tests documentés = 4 `it` dans le spec
- Décision 7 steps → 1 chunk documentée

## 10. Findings nouveaux

| ID | Sévérité | Finding |
|----|----------|---------|
| F-VERIFY-W6B-01 | LOW | Sentinel F6 ne couvre pas `kiosk-admin` chunk dans test |
| F-VERIFY-W6B-02 | LOW | Test F7 non portable (grep shell, échouera Windows) |
| F-VERIFY-W6B-03 | INFO | Diff inclut méta (`.cursor`, `reports/`) au-delà du strict bundle JS |
| F-VERIFY-W6B-04 | LOW | Pas de `loadingComponent` / `errorComponent` sur defineAsyncComponent (admin / steps) |

## 11. Recommandations

**CLOSED PASSED** fonctionnellement pour W6.B.

**Améliorations optionnelles (si REM léger souhaité)** :
- Assert `kiosk-admin` dans `kioskPerfChunks.spec.js` (1 ligne)
- Remplacer F7 grep par parcours `fs`/`glob` (5 LOC)
- Ajouter `loadingComponent` minimal (spinner) sur admin overlay (~10 LOC)
- Validation Vitest 608/608 sur env CI (déjà OK selon implementer report, à reconfirmer)

**Suite** : Synthèse W6 finale + commit.
