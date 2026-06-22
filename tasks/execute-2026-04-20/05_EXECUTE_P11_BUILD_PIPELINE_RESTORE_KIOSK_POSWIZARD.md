# EXECUTE — P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (build config, aucune logique applicative)
**VAGUE:** V1 (parallélisable backend — plan §2 ligne 113)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 ligne 37
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-17-01
- `reports/review/VERIFY_17_I18N_DEPLOY_2026-04-20.md`

## Constat observé (pré-cycle)
```
public/js/app.js         4.6 MB    2026-04-18  (présent, manifesté)
public/js/kiosk.js       526 KB    2026-04-18  (présent, manifesté)
public/js/pos-wizard.js  287 KB    2026-04-20  (présent, NON manifesté)
public/css/app.css       143 KB    2026-04-18  (présent, manifesté)
public/css/pos-wizard.css 41 KB    2026-03-25  (présent, NON manifesté, ancien)

public/mix-manifest.json :
  "/js/app.js":      "/js/app.js",
  "/js/kiosk.js":    "/js/kiosk.js",
  "/css/app.css":    "/css/app.css"
  -> manque: /js/pos-wizard.js, /css/pos-wizard.css
```
Drift : le bundle `pos-wizard` est utilisé en prod (référencé par `PosWizardComponent.vue`, `PosComponent.vue`) mais n'est pas dans `mix-manifest.json` → absence de cache-busting + risque stale en déploiement.

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — configuration tweaks, bounded)
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `webpack.mix.js` (déclarations bundles pos-wizard)
- `public/mix-manifest.json` (régénéré automatiquement par `npm run prod`)
- `resources/sass/pos-wizard.scss` (si absent, à vérifier et créer entry minimal SASS pointant `resources/sass/_pos-wizard.scss` existant ou refs CSS actuelle) — **à arbitrer via lecture**
- `resources/js/pos-wizard.js` (entry JS si absente ; sinon simple vérif)

### SCOPE_FILES (whitelist)
- `webpack.mix.js`
- `resources/sass/pos-wizard.scss` (lecture, création seulement si absent ET nécessaire pour build)
- `resources/js/pos-wizard.js` (idem)
- `public/mix-manifest.json` (auto via npm run prod — pas d'édition manuelle)

### SUBSYSTEMS_OFF_LIMITS
- `app/`, `database/`, `routes/`, `tests/`, `docs/`
- `resources/js/app.js`, `resources/js/kiosk.js` (entries existantes — ne pas toucher)
- Autres fichiers `resources/sass/*.scss`
- `package.json` (sauf si absolument nécessaire d'ajouter dépendance — si oui → SCOPE_PRESSURE)

## Invariants at Risk
- **Aucun invariant applicatif** (config build)
- **Risque build-time:** `npm run prod` doit rester vert. Si nouveau entry casse le build → rollback.
- **Risque prod-time:** cache-busting. Après fix, chaque déploiement doit invalider `pos-wizard.js`/`.css` par hash dans manifest.

## Dependencies
- Aucune (indépendant)

## Plan bref

1. **Lire** `webpack.mix.js` (564 octets) et lister les mix entries actuelles.
2. **Lire** les sources possibles `resources/js/pos-wizard.js` et `resources/sass/pos-wizard.scss` — si absents, vérifier quelle source produit actuellement `public/js/pos-wizard.js` et `public/css/pos-wizard.css` (legacy build ?). Possibilité : import dans `app.js` sous-découpé.
3. **Si entries sources manquantes** : créer fichiers entries minimaux :
   ```
   // resources/js/pos-wizard.js
   import './components/admin/pos/PosWizardComponent.vue';
   ```
   (à ajuster selon structure réelle — SCOPE_PRESSURE si modification hors pos-wizard)
4. **Ajouter à `webpack.mix.js`** :
   ```js
   mix.js('resources/js/pos-wizard.js', 'public/js/pos-wizard.js').vue({version:2});
   mix.sass('resources/sass/pos-wizard.scss', 'public/css/pos-wizard.css');
   ```
5. **Lancer** `npm run prod` et vérifier :
   - Exit code 0
   - `public/mix-manifest.json` contient les 2 entries
   - Taille output raisonnable (≤ 5 MB chacun)
6. **Écrire** `reports/execution/RUN_P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD_2026-04-20.md` avec avant/après mix-manifest + sortie npm.

## Acceptance Tests
- [ ] `npm run prod` termine exit code 0
- [ ] `public/mix-manifest.json` contient `/js/pos-wizard.js` ET `/css/pos-wizard.css` avec hash version (`?id=`)
- [ ] `public/js/pos-wizard.js` + `public/css/pos-wizard.css` régénérés (date récente)
- [ ] `public/js/app.js` et `public/js/kiosk.js` inchangés fonctionnellement (taille ±10%)
- [ ] Grep `pos-wizard` dans blade/vue loaders : aucune ref cassée après rebuild

## Exit Criteria
- [ ] Cache-busting actif sur pos-wizard bundle (présence `?id=<hash>` dans manifest)
- [ ] Rebuild idempotent (2e `npm run prod` = manifest stable au hash près)
- [ ] Pas d'édition hors SCOPE_FILES + mix-manifest
- [ ] `reports/execution/RUN_*.md` avec Final report

## Scope Pressure Protocol
Si `webpack.mix.js` nécessite **ajout de loader npm** (ex. sass-loader manquant) → STOP + écrire SCOPE_PRESSURE + remonter Claude (décision : accepter modif `package.json` = nouveau scope OU trouver alternative).

Si entry source `resources/js/pos-wizard.js` doit importer **>3 fichiers hors `components/admin/pos/*`** → SCOPE_PRESSURE.

## Remediation
- Attempt 1 KO (build fail) → Claude diagnose (souvent : SASS variable manquante, loader config) + replan + Composer re-EXECUTE
- Attempt 2 KO → diagnostic plus profond (peut-être migrer vers Vite — SCOPE_PRESSURE)
- Attempt 3 même bug_signature → HUMAN_GATE bug irrésolu

## Deliverables
- Diff `webpack.mix.js` (≤ 5 lignes attendues)
- Éventuellement 1 ou 2 fichiers entry source minimaux (si nécessaires et dans scope)
- `public/mix-manifest.json` régénéré
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css` régénérés (pas commités manuellement par agent ; commités par CI normalement)
- `reports/execution/RUN_P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD_2026-04-20.md`

## Communication
Subagent renvoie : diff webpack.mix.js, sortie `npm run prod` (tail 30 lignes), manifest avant/après.
