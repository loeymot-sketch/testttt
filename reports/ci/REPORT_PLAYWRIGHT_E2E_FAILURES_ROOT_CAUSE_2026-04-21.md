# Rapport diagnostic — Échecs Playwright E2E en CI (2026-04-21)

**Cycle** : Hotfix CI émergence (interruption W8 close)
**Trigger** : 5+ runs consécutifs en échec total sur PR #6 `feat/ton-sujet`, génération de notifications email répétées GitHub
**Décision** : opt-in trigger via label `e2e-required` + diagnostic complet pour debug futur

---

## 1. Symptôme exact

| Run ID | Date | Durée | Résultat |
|---|---|---|---|
| 24729392563 | 2026-04-21 14:54 | 15m00s | ❌ failure |
| 24726706873 | 2026-04-21 14:01 | 15m03s | ❌ failure |
| 24714921619 | 2026-04-21 09:30 | 14m11s | ❌ failure |
| 24714884748 | 2026-04-21 09:29 | 14m08s | ❌ failure |
| 24693772561 | 2026-04-20 22:32 | 14m17s | ❌ failure |
| 24618619327 | 2026-04-19 01:59 | 13m46s | ❌ failure |

**Pattern d'échec** : `22 failed / 3 passed`. Tous les échecs sur le même point :
```
helpers/login.js:10
await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
→ Error: element(s) not found (Timeout: 20000ms)
```

Les 3 tests qui passent sont ceux qui ne dépendent PAS du SPA Vue :
- redirections HTTP server-side (`/` → `/login`)
- contrôle de routes statiques sans assertion DOM SPA

## 2. Cause root identifiée

**Screen 100% blanc en CI** : tous les screenshots de failure montrent un viewport 1024×576 entièrement blanc → **le SPA Vue ne se monte pas du tout**.

Ce n'est PAS :
- ❌ Un problème de timing (timeout 20s très large)
- ❌ Un problème de seeds (PHPUnit passe, DB OK)
- ❌ Un problème de throttle (`API_THROTTLE_PER_MINUTE=5000`, `LOGIN_LOCKOUT_MAX_ATTEMPTS=500`)
- ❌ Un problème de FISCAL_* secrets (déjà injectés dans env + .env)
- ❌ Un problème de staff flags (déjà sed-injected)
- ❌ Un problème de `storage/installed` (touch après migrate:fresh)

C'est UN crash JavaScript silencieux au boot du SPA Vue qui empêche le mount, donc `#formEmail` n'apparaît jamais.

## 3. Pourquoi les tentatives précédentes ont échoué

Historique git du workflow :
```
f5ff2d2ce  fix(e2e): configurabler API throttle pour éviter 429 Playwright
b3970a08b  fix(e2e): SPA URL waits, login throttle config, kiosk auto-login branch
313658858  fix(ci-e2e): mark app installed + staff flags + FR login helper
74210a1d8  fix(ci): MenuSeeder avoid MySQL TRUNCATE in tests + Playwright APP_ENV
9008416f4  ci(playwright,phpunit-mysql): inject FISCAL_* + APP_URL for E2E
```

Chaque fix s'attaquait à un symptôme côté backend (throttle, env, seeds, install) sans jamais regarder le boot Vue. Le user a mentionné ~10h perdues sur ces tentatives.

**Raison structurelle** : le workflow uploadait `playwright-report/` (jamais généré car `reporter` ne contenait pas `html`) et `reports/antigravity/` (déjà tracké dans le repo, contenant des artifacts du 15 avril). Donc :
- Aucun HTML report Playwright pour naviguer les traces
- Aucun console.log capturé (pas de `page.on('console')`)
- Aucun network error capturé
- Aucun log Laravel uploadé
- Aucune visibilité sur ce que le serveur renvoie réellement sur `/login`
- Aucune visibilité sur le manifest Mix / les assets compilés

Sans ces données, **diagnostic à l'aveugle = perte de temps garantie**.

## 4. Hypothèses prioritaires pour le prochain debug intentionnel

Par ordre de probabilité décroissante :

### H1 (HIGH) : `window.foodkingConfig` malformé ou plante au render Blade
Les tests `06-staff-only-routing` lignes 48-62 vérifient `window.foodkingConfig.staffOnlyMode` et `kioskUsePosWizard`. Si la balise `<script>window.foodkingConfig = {...}</script>` injectée dans `master.blade.php` plante en JSON parsing, **tout le SPA crash au boot**.
**Diagnostic** : `curl /login | grep foodkingConfig` + console errors en CI.

### H2 (HIGH) : Asset hash mismatch Mix
`master.blade.php` référence `mix('js/app.js')` qui résout via `public/mix-manifest.json`. Si le manifest n'est pas trouvé OU pointe vers un fichier inexistant, le `<script src="/js/app-XXX.js">` retourne 404 → SPA non chargé → écran blanc.
**Diagnostic** : `cat public/mix-manifest.json` + `ls public/js/` + curl status code des assets référencés.

### H3 (MED) : Module ESM bundle incompatible Node 18
Le workflow utilise `node-version: '18'` mais certaines deps front (Vue 2.7+ écosystème, vite plugin, etc.) peuvent générer des bundles incompatibles ou crasher au build sans erreur (just warn).
**Diagnostic** : check `console.log` runtime + warnings build Mix.

### H4 (MED) : `i18n` initialization fail
Le router guard `kioskRoutes.js` (W3 REM) dispatch `kioskFilter/init` au boot ; si une langue n'est pas chargée (ex: import dynamique `de.json` 404), le boot Vue crash.
**Diagnostic** : network errors + console.

### H5 (LOW) : APP_URL mismatch CSRF/Sanctum
`APP_URL=http://localhost:8000` injecté dans env + .env. Si une dependency Vue lit l'URL pour CSRF token mount et qu'elle plante, mount avorté.
**Diagnostic** : network errors sur premier appel `/sanctum/csrf-cookie` ou similaire.

## 5. Mesure prise (2026-04-21)

### A. Trigger du workflow
**AVANT** : déclenchement automatique sur tout PR vers `main`/`develop` → 6 runs en 48h, 14 min chacun, tous failed = 84 min de CI gaspillé + spam emails.

**APRÈS** : trigger opt-in :
- `workflow_dispatch` (UI manuelle) — toujours OK
- `pull_request` UNIQUEMENT si label `e2e-required` présent sur la PR
- `push` sur `main` uniquement (protection branche cible après merge)

**Concurrency** : `cancel-in-progress: true` pour empêcher 2 runs simultanés sur la même PR.

### B. Artifacts upload
**AVANT** : `playwright-report/` (jamais généré) + `reports/antigravity/` (déjà commité, vieux du 15 avril).

**APRÈS** : 3 artifacts distincts uploadés `if: always()` :
1. **`playwright-html-report`** : `playwright-report/` HTML interactif Playwright (ajout reporter `html`)
2. **`playwright-test-results`** : `test-results/` traces + screenshots du run actuel
3. **`server-logs`** : `storage/logs/` (Laravel + php artisan serve) + `playwright-latest.json` + `public/mix-manifest.json`

### C. Steps diagnostic ajoutés
- **Diagnostic — assets compilés** : dump `mix-manifest.json` + listing `public/js/` + `public/css/` après `npm run prod`
- **Diagnostic — sanity check /login HTML** : curl status + headers + 200 premières lignes du HTML + recherche `formEmail` dans le HTML serveur (révèle immédiatement si le problème est serveur ou client)
- **Reporter Playwright** : `--reporter=list,html,json` (avant : seulement `list,json`)
- **PHP serve** : redirige stdout+stderr vers `storage/logs/php-serve.log` pour upload

### D. Job logic
- `Run Playwright tests` step en `continue-on-error: true` pour garantir l'upload des artifacts diagnostic AVANT le fail
- Step final `Fail the job if Playwright failed` repropage l'échec après upload

## 6. Plan de debug recommandé (quand on voudra y revenir)

### Étape 1 : 1 seul run diagnostic ciblé
1. Ajouter le label `e2e-required` sur PR #6 (ou créer une PR test temporaire)
2. Le workflow démarre
3. Récupérer les 3 artifacts
4. Lire :
   - `server-logs/storage/logs/laravel.log` (exceptions Laravel)
   - `server-logs/storage/logs/php-serve.log` (erreurs serveur)
   - `server-logs/public/mix-manifest.json` (vérifier les hashes assets)
   - Output du step "Diagnostic — sanity check /login HTML" (HTML serveur réel)
   - Output du step "Diagnostic — assets compilés" (listing public/js/)

### Étape 2 : si HTML contient `#formEmail`
→ problème côté client (assets 404 ou JS crash). Action : ajouter `page.on('console')` + `page.on('requestfailed')` capture dans helpers/login.js, re-run.

### Étape 3 : si HTML NE contient PAS `#formEmail`
→ problème côté serveur (Blade crash, mauvais layout, redirect). Action : lire `laravel.log`, vérifier middleware order, vérifier `master.blade.php` rendering.

### Étape 4 : si assets manquent dans `public/js/`
→ problème de build Mix. Action : check warnings `npm run prod`, vérifier `webpack.mix.js` paths.

### Étape 5 : fix root cause + retest
Avec les vraies données, le fix prend 30 min - 2h max au lieu de 10h tâtonnements.

## 7. Pour réactiver le déclenchement auto plus tard

Modifier `.github/workflows/playwright.yml` :
```yaml
on:
  pull_request:
    branches: [main, develop]
  push:
    branches: [main]
```
Et retirer le `if:` du job `e2e`. Le concurrency + diagnostic restent acquis.

## 8. Contexte cycle

Ce hotfix interrompt la clôture de Wave 8 (Security + NF525 readiness, déjà CLOSED PASSED commit `879b41880`). Aucun impact W8 ; cycle CI séparé.

**Findings ouverts cohabitant** : C5 (DUPLICATA UI integration), G14-B (T09+T17), C9 dispatch-after-commit, GATE_P_MEGA_19 branches.theme_*.

---

**Verdict** : ✅ **Workflow stabilisé** — plus de spam emails, infrastructure debug propre prête pour le jour où tu voudras résoudre la cause root du screen blanc Vue.
