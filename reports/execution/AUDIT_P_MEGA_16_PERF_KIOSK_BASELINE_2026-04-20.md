# AUDIT P-MEGA-16 — Perf kiosk baseline (Phase B.1 du cycle W6)

**Date** : 2026-04-20
**Mode** : READONLY (Phase B.1 du cycle W6)
**HEAD** : `1dabfa568` (post W6.A.2 a11y fixes)
**Subagent** : explore very thorough
**Bundler confirmé** : Laravel Mix (webpack) — pas de `vite.config.js` ; entrée unique dans `webpack.mix.js`.

## 0. Synthèse exécutive (5 lignes max)

Le cold start kiosk est dominé par **`app.js` ~4,5 Mo** + **`pos-wizard.js` ~280 Ko** + chaîne CSS/fonts thème, avant même le chunk **`kiosk.js` ~514 Ko**. Le routeur kiosk est déjà en **`import()`** avec chunk nommé `kiosk`, mais **`KioskAdminComponent` est importé statiquement dans `KioskAppComponent`**, donc l'admin (~1181 LOC) reste dans le graphe du shell kiosk. **`webpack.mix.js` est minimal** (pas d'`extract`, pas de `splitChunks` dédié, pas d'analyseur). **Aucun service worker / manifest PWA** ; **`resources/js/helpers/kioskPerf.js` absent** sur ce worktree.

## 1. Bundle baseline

| Fichier | Octets | Notes |
|---------|--------|--------|
| `public/js/app.js` | 4 623 413 | Build prod présent |
| `public/js/kiosk.js` | 525 755 | Chunk async `webpackChunkName: "kiosk"` |
| `public/css/app.css` | 142 959 | `mix('css/app.css')` |
| `public/js/pos-wizard.js` | 287 207 | **Hors Mix** ; chargé sur toutes les pages via layout |

Chunks JS : **4 fichiers**. Compression transport : non mesurée (fichiers minifiés côté build).

## 2. Routing lazy load actuel

Fichier : `resources/js/router/modules/kioskRoutes.js`.

- **Lazy** : tous les composants route sont `() => import(/* webpackChunkName: "kiosk" */ "...")` — L6–L24.
- **Sous-route wizard** : factory `component: () => { ... return usePosWizard ? KioskPosWizardComponent() : KioskWizardComponent(); }` — L156–159 (les deux restent le **même chunk name `kiosk`**).
- **Écart doc / code** : commentaire L3–L5 annonce un prefetch du chunk sur l'idle screen ; **aucun `prefetch` / `import()` opportuniste** dans `resources/js/components/frontend/kiosk` (recherche `prefetch|preload|import(` → seul le commentaire dans `kioskRoutes.js`).

## 3. Composants kiosk top-5 par LOC

| Rang | Fichier | LOC | Imports |
|------|---------|-----|---------|
| 1 | `KioskWizardComponent.vue` | 1920 | Steps + `KioskOrderSummary` en **import statique** L171–178 |
| 2 | `KioskCategoriesComponent.vue` | 1383 | Shell catalogue + `KioskPromoCarousel` L349 |
| 3 | `KioskAdminComponent.vue` | 1181 | **Import statique** depuis `KioskAppComponent.vue` L126, L161 |
| 4 | `KioskCartComponent.vue` | 1102 | — |
| 5 | `KioskLoyaltyComponent.vue` | 1018 | — |

**Hors analyse détaillée (gated W5)** : `KioskPaymentComponent.vue` (904), `KioskConfirmationComponent.vue` (665), `KioskOrderSummaryComponent.vue` (556).

**Composant orphelin (TOP dead code)** : `KioskProductListComponent.vue` (**693 LOC**) — **aucun import** hors son propre fichier dans `resources/js`.

## 4. Dependencies analysis

- **`package.json`** : section **`dependencies`** ≈ **24** paquets (L34–58) ; **`devDependencies`** inclut `laravel-mix`, `vue`, `axios`, `lodash`.
- **Lourds / sensibles taille** (prod) : `firebase`, `google-maps`, `swiper`, `vue3-apexcharts`, `vue3-quill`, `pusher-js`, `laravel-echo`, `vue-i18n`, `dompurify`, `@vuepic/vue-datepicker`.
- **Pas de doublon date-lib** (pas de moment + date-fns).
- **Lodash full bundle** : `resources/js/bootstrap.js` L1–2 `import _ from 'lodash'; window._ = _` → entraîne lodash complet dans le bundle principal si non optimisé. Ailleurs : mélange `import _ from "lodash"` (`store/modules/posCart.js` L1) vs `lodash/debounce` ponctuel (`PosComponent.vue` L716).

## 5. Images / assets

- **`public/img/**`** : **0 fichier** (arborescence absente / vide sur ce worktree).
- **Lazy loading** : `loading="lazy"` présent sur plusieurs vues kiosk (`KioskCategoriesComponent.vue` L142/L215, steps `KioskStepMenuComponent.vue` L136/L179/L224, `KioskProductListComponent.vue` L60).
- **WebP/AVIF** : **aucune** occurrence sous `resources/js/components/frontend/kiosk`.
- **Pipeline image** : pas de `imagemin` / `sharp` dans `package.json`.

## 6. Webpack config actuel (`webpack.mix.js`)

```13:14:webpack.mix.js
mix.js('resources/js/app.js', 'public/js').vue().postCss('resources/css/app.css', 'public/css', [require("tailwindcss")])
```

- **Une seule entrée JS** ; pas de `mix.extract()`, pas de `mix.webpackConfig()` pour `splitChunks` / analyse.
- **CSS Tailwind** via `postCss` ; **tokens kiosk** via `import` dans `resources/js/bootstrap-kiosk.js` L31–33.

## 7. Critical CSS / fonts

- **Fonts** : `master.blade.php` L40–47 — Google **Inter** avec `&display=swap` L43 ; feuilles locales FontAwesome / Lab / Rubik.
- **`font-display`** : aucun `@font-face` custom détecté dans `resources/css/**`.
- **Critical CSS inline kiosk** : non.

## 8. Service Worker / cache

- **`public/sw.js`** / **`manifest*.json`** : aucun.
- **PWA kiosk** : non détecté.

## 9. Cold start — charge réseau (proxy)

- **Scripts** sur layout : `mix('js/app.js')` L143 + 5 scripts thème L144–148 + **`pos-wizard.js`** L158 → ≥7 requêtes JS avant logique métier + Google Fonts + ~4–5 CSS L40–52.
- **Budget indicatif** : JS seul **app + kiosk + pos-wizard ≈ 5,4 Mo bruts** (sans gzip réseau).

## 10. Store / helpers (périmètre demandé)

- **Vuex** : `store/index.js` enregistre tous modules ; kiosk-dense : `kioskCart.js` 513, `kioskMenu.js` 320, `kioskSettings.js` 289.
- **Helpers kiosk** : ~2,7k LOC ; plus gros : `kioskPrinter.js` 331, `kioskFilters.js` 297, `kioskAnalytics.js` 279.

## 11. Findings opportunités (par impact estimé)

### 🔴 High impact (>30 Ko économie OU >300 ms cold start proxy)

1. **Réduire / conditionner le bundle `app.js`** : importe globalement Toast, ApexCharts, vue-next-select, design system kiosk L9–31, L155–162 — utile surtout admin/frontend, pas kiosk-minimal. **Risque : MED-HIGH** (refonte entrée).
2. **Éviter `pos-wizard.js` sur `/kiosk*`** si le flux borne n'utilise pas le shim legacy : `master.blade.php` L52, L158 charge **toujours** pos-wizard (~280 Ko JS + CSS L52). **Risque : MED** (touche blade layout, dépendances POS partagées).
3. **`KioskAdminComponent` en async** : retirer l'import statique `KioskAppComponent.vue` L126, L161 — décroche ~1181 LOC du chemin critique. **Risque : LOW**.
4. **Wizard : steps en `defineAsyncComponent` / `import()`** : `KioskWizardComponent.vue` L171–177. **Risque : LOW-MED**.

### 🟠 Medium impact (10–30 Ko ou 100–300 ms)

5. **Lodash** : remplacer `import _ from 'lodash'` (`bootstrap.js` L1) par imports ciblés ou `lodash-es` + tree-shaking. **Risque : MED** (`window._` utilisé en code legacy).
6. **Scinder chunk `kiosk`** : plusieurs `webpackChunkName` (ex. `kiosk-admin`, `kiosk-errors`, `kiosk-wizard`) dans `kioskRoutes.js` L6–24. **Risque : LOW**.
7. **Supprimer ou brancher `KioskProductListComponent.vue`** (orphelin 693 LOC) — nettoie le repo. **Risque : LOW**.

### 🟡 Low impact (<10 Ko)

8. **`KsChip`** enregistré globalement (`ds/index.js`) mais aucun `<KsChip` utilisé dans `resources/js` hors commentaires — candidat suppression DS si vraiment mort.

## 12. Top fixes recommandées (ROI)

1. **Lazy `KioskAdminComponent` + sous-chunks route kiosk** — impact direct `kiosk.js` / TTI ; risque LOW.
2. **Async steps wizard** — impact TTI wizard ; risque LOW (tests flux étapes existants).
3. **Suppression / déprécation `KioskProductListComponent.vue`** — risque LOW (orphelin).
4. **Refonte entrée `app.js` / code-splitting admin vs storefront vs kiosk** (gros chantier) — impact maximal mais risque HIGH → réservé `complex implementer` cycle séparé.
5. **Gate `pos-wizard` + scripts thème sur routes kiosk** — impact réseau direct ; risque MED → blade touch (à évaluer).

## 13. Tests sentinelles à créer

- **Budget taille** : script Node (`tools/perf/check_bundle_budget.mjs`) lisant `public/js/*.js`, comparant tailles à seuils KB documentés.
- **Présence chunk** : assertion `public/js/kiosk-admin*.js` présent après build (mock `process.env` ou skipping si pas de build CI).
- **Vitest** : assertion code statique — `KioskAdminComponent` est-il importé dynamiquement (`/import\\([\\s\\S]*KioskAdminComponent/`).
- **Vitest** : `KioskProductListComponent` non importé statiquement nulle part.

## 14. Décisions techniques

- **Bundle analyzer** : `webpack-bundle-analyzer` branché via `mix.webpackConfig()` (Mix 6) — meilleur alignement que `source-map-explorer`. **Différé W6.B.2** : trop scope élargi pour routine implementer ; documenter en finding.
- **Cold start** : Lighthouse CI sur `/kiosk/login` ou `/kiosk/idle` (4G throttle). **Différé** : pas d'infra Lighthouse stable confirmée. Fallback W6.B.2 = bundle budget.
- **Async chunks naming** : passer de `kiosk` monolithique à `kiosk-shell`, `kiosk-wizard`, `kiosk-admin`, `kiosk-errors`.

## 15. Périmètre recommandé pour W6.B.2 EXECUTE (routine implementer)

**TARGET LOW RISK ONLY** (~120-150 LOC) :
- F3 KioskAdminComponent → `defineAsyncComponent` dans `KioskAppComponent.vue`
- F4 Wizard steps (KioskStep*Component) → `defineAsyncComponent` dans `KioskWizardComponent.vue`
- F6 Sub-chunks `kiosk-admin`, `kiosk-wizard`, `kiosk-errors` dans `kioskRoutes.js`
- F7 Suppression `KioskProductListComponent.vue` après confirmation orphelin
- Tests sentinelles bundle/code (3-5 nouveaux)

**DIFFÉRÉ** (`complex implementer` ou cycle séparé) :
- F1 refonte `app.js`
- F2 gate `pos-wizard`
- F5 lodash tree-shake (window._ legacy)
- bundle analyzer + Lighthouse CI

---

**Fichiers clés cités** :
`webpack.mix.js`, `package.json`, `resources/js/router/modules/kioskRoutes.js`, `resources/js/app.js`, `resources/js/bootstrap.js`, `resources/views/master.blade.php`, `resources/js/components/frontend/kiosk/KioskAppComponent.vue`, `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`, `reports/audit-orchestration/REPORT_TASK04_KIOSK_PERF_K5_2026-04-20.md`.
