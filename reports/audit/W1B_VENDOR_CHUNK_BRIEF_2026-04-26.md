# W1-B — Vendor Chunking + HG-4 Gate Brief — Audit Claude terminal

**Date** : 2026-04-26
**Cycle** : POS_V4_W1B_VENDOR_CHUNK
**Type** : routine (config webpack + Blade head — 0 invariant métier touché)
**EXECUTE_DELEGATION** : cursor-claude-direct (config-only, pattern Mix officiel `extract()`)

---

## 1. Contexte

Audit W1-A (`AUDIT_W1A_CODESPLIT_CLAUDE_2026-04-26.md` PASS-WITH-FIX 8/10) a recommandé en priorité W1-NEXT-2 le **vendor chunking** d'`app.js` (1018 KB gzipped post-W1-A) avec ROI le plus élevé et 0 invariant à risque.

W1-B exécute cette recommandation + livre le **gate brief HG-4** (PaymentComponent prop mutation refactor) demandé par les human gates restants.

## 2. Modifications W1-B

### 2.1 `webpack.mix.js`

Ajout de `mix.extract([...])` avec 9 dépendances stables : Vue, Vuex, Vue Router, axios, vue-toastification, vue3-simple-alert, vue-next-select, vue3-apexcharts, apexcharts.

```js
mix.js('resources/js/app.js', 'public/js')
    .vue()
    .extract([ 'vue', 'vuex', 'vue-router', 'axios', /* ... */ 'apexcharts' ])
    .postCss('resources/css/app.css', 'public/css', [require("tailwindcss")]);
```

API officielle Laravel Mix v6 (`https://laravel-mix.com/docs/main/extract`).

### 2.2 `resources/views/master.blade.php` L143

```diff
+    {{-- [POS-V4 W1-B 2026-04-26] Vendor chunking — order is critical: --}}
+    <script src="{{ mix('js/manifest.js') }}"></script>
+    <script src="{{ mix('js/vendor.js') }}"></script>
     <script src="{{ mix('js/app.js') }}"></script>
```

Ordre critique : `manifest` (webpack runtime) → `vendor` (libs tierces stables) → `app` (code métier).

### 2.3 Gate brief HG-4 livré

Nouveau fichier : `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`. 4 options (A=refactor complet, B=refactor minimaliste, C=différer, D=cancel), 3 cosignataires requis (TL + Backend + QA NF525), date limite proposée 2026-05-15.

## 3. Évidence build prod

```
✔ Compiled Successfully in 28532ms
js/manifest.js   →   4 KB raw   /   1 KB gzipped   (webpack runtime)
js/vendor.js     →  712 KB raw  / 194 KB gzipped   (NOUVEAU — Vue+Vuex+Router+axios+charts)
js/app.js        → 3896 KB raw  / 826 KB gzipped   (vs 4604/1018 pré-W1-B → -708 KB raw, -192 KB gz)
js/pos-shell.js  →  236 KB raw  /  55 KB gzipped   (inchangé — bénéficie du runtime extract)
```

`mix-manifest.json` contient bien `/js/manifest.js` + `/js/vendor.js` (vérifié).

## 4. KPI / impact mesuré

| Surface | Avant W1-B (gz) | Après W1-B (gz) | Δ first-load | Δ retour-visite (vendor cached) |
|---|---|---|---|---|
| Non-POS (admin/KDS/frontend/kiosk shell) | 1018 KB | 1021 KB (1+194+826) | +3 KB (overhead extract) | **-194 KB** (vendor cache hit) |
| POS first-paint | 1073 KB | 1076 KB (1+194+826+55) | +3 KB | **-194 KB** |

**Lecture** :
- **Première visite** : payload total quasi neutre (+3 KB d'overhead webpack runtime). Pas de gain immédiat first-paint.
- **Retours-visite** (déploiement code app uniquement, vendor inchangé) : utilisateur télécharge **826 KB** au lieu de **1018 KB** → **-19 % perçu**, plus visible avec HTTP/2 multiplexing.
- **Cache CDN** : vendor.js peut être servi via CDN public (cdnjs/jsdelivr) à terme, gain supplémentaire potentiel.

**Bénéfice principal** : déploiement → seul `app.js` (826 KB) est invalidé du cache navigateur. Vendors stables (libs Vue/charts) restent cachés des semaines/mois.

## 5. Non-régression

- `npm run production` : `Compiled Successfully` (1 warning non-régression i18n.js, déjà présent W0+/W1-A)
- `npm run pos:lint:status` : OK clean
- `npm run pos:lint:pricing` : OK + WARN attendu (PosComponent:1779 signoff-pending)
- `mix-manifest.json` : 11 entrées, `/js/manifest.js` + `/js/vendor.js` + `/js/app.js` présentes
- Backups conservés : `webpack.mix.js.bak.w1b`, `master.blade.php.bak.w1b` (rollback : `mv` + `npm run production`)

## 6. Investigation delta `app.js +53 KB` (HG soulevé par audit W1-A)

L'audit W1-A avait flagué un delta non tracé entre baseline W0 (`app.js` = 965 KB gz) et build post-W1-A (`app.js` = 1018 KB gz, +53 KB).

Avec W1-B, la décomposition est :
- `app.js` = 826 KB gz
- `vendor.js` = 194 KB gz
- `manifest.js` = 1 KB gz
- **Total** = 1021 KB gz (vs baseline W0 = 965 KB gz)

Delta restant inexpliqué : **+56 KB gz** (~+5.8%). Causes plausibles :
1. KIOSK-DS V1 Phase 2 (CSS tokens + 7 atoms KsButton/KsCard/etc. mentionné `app.js:10-17`)
2. ConnectionStatusBanner + bootstrap.js Echo/Pusher activé inter-cycles
3. Évolution des dépendances npm (mineures)

**Décision orchestrale** : pas de blocant W1-B. Investigation détaillée différée en W1-C avec `npm run perf:bundle-check` historisé. Documentation à mettre à jour : `reports/baseline/POS_V4_PERF_BASELINE_W0.md` doit ajouter une colonne "build courant" pour permettre le suivi temporel.

## 7. Invariants

| Invariant | Risque | Vérification |
|---|---|---|
| pricing_ssot | Aucun | Config webpack uniquement |
| OrderStatus enum | Aucun | Config webpack uniquement |
| branch_id isolation | Aucun | Config webpack uniquement |
| commit_before_dispatch | Aucun | Config webpack uniquement |
| OrderService symétrie | Aucun | Config webpack uniquement |
| Frozen zones | À VÉRIFIER | `master.blade.php` est-il en frozen zone ? À confirmer en audit |

## 8. Questions pour audit Claude

1. **`master.blade.php` frozen ?** : la modification de la racine SPA (3 lignes script tags ajoutées) nécessite-t-elle une gate clearance, ou est-ce considéré comme évolution config infrastructure ?
2. **Liste extract() suffisante ?** : 9 packages extraits. Manque-t-il des libs JS lourdes (lodash, moment, date-fns, sweetalert2, jquery) à ajouter à la liste vendor ?
3. **Stratégie CDN** : faut-il prévoir une route W1-D ou W2 pour servir `vendor.js` depuis un CDN public (cdnjs) ?
4. **Manifest hash** : Mix `versioning()` n'est pas activé. Cache busting actuellement par `mix()` helper (timestamp). Faut-il activer versioning officiel pour la cohérence W1-B ?
5. **Gate brief HG-4** : la rédaction du gate brief PaymentComponent (`docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`) est-elle complète et actionnable ? Les 4 options sont-elles bien distinctes ? Manque-t-il un cosignataire (UX ?) ?

## 9. Verdict attendu

GO/NO-GO pour W1-C (lazy admin classique routes : Dashboard, Menu, Reports, Staff, etc.) — exécutable avec pattern identique posRoutes.js.
