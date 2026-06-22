# K14 — Catalog / RTL / Allergens helpers

> Branch `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `6a33a9763`.
> Mode read-only — file:line citations vérifiées en lecture, pas mémorisées.

## Files audited

- `resources/js/helpers/kioskFilters.js` — 297 lignes (filters + allergens merge + variation gate)
- `resources/js/helpers/kioskItemDisplayOrder.js` — 79 lignes (sort items in a category)
- `resources/js/helpers/kioskCategoryOrder.js` — 75 lignes (tier sort sidebar)
- `resources/js/helpers/kioskDisplayText.js` — 27 lignes (customer-facing sanitization)
- `resources/js/helpers/kioskViandeCatalog.js` — 142 lignes (variation+extra meat fusion)
- `resources/js/helpers/kioskSauceCatalog.js` — 61 lignes (sauce variations rows)
- `resources/js/helpers/kioskDrinkAddons.js` — 69 lignes (drink addon partitioning)
- `tests/js/kioskAllergenMerge.spec.js` — 11 cases (mergeAllergens contract)
- `tests/js/kioskRtl.spec.js` — 6 cases (i18n dir/lang sync)
- `tests/js/kioskCategoryOrder.spec.js` — 4 cases (tiers + sort)
- `tests/js/kioskViandeCatalog.spec.js` — 11 cases (variation+extra rows)

## Findings

### P0 (blocker pre-merge V1)

- **K14-P0-01: EAA 2025 — variations/extras allergens never merged at runtime [BACKEND CONTRACT GAP]**
  - File: `resources/js/helpers/kioskFilters.js:186-201` ; `app/Services/Kiosk/KioskMenuService.php:337-377`
  - Issue: `mergeAllergens(item, variations, extras)` extrait correctement les codes allergènes côté JS, et `KsAllergenBadge.vue:96-101` l'appelle correctement avec `selections.variations` + `selections.extras`. Mais la projection backend (`KioskMenuService::projectItemRow`) ne renvoie le tableau `allergens` QUE sur l'item parent (ligne 330), JAMAIS sur les rangées `variations` (lignes 342-356) ni `extras` (lignes 364-376). Conséquence directe : un client allergique au lait sélectionne un extra "Fromage" → aucune alerte visuelle, aucun pulse rouge, conformité EAA 2025 trompée (UE 1169/2011 art. 9 — l'information allergène doit être lisible AVANT décision).
  - Evidence: Service ligne 330 (`'allergens' => $item->allergens->map(...)`) absent des projections variation/extra. Spécification `kioskAllergenMerge.spec.js:13-16` envoie un `extra: { allergens: [{code:'lait'}] }` factice qui n'est pas représentatif du payload réel.
  - Suggested fix: ajouter `'allergens' => $v->allergens ?? []` et `'allergens' => $e->allergens ?? []` dans `KioskMenuService`. Migration BDD : pivot `item_extra_allergens` + `item_variation_allergens` (probable Eloquent `BelongsToMany`) ou snapshot inline. Hors scope K14 — flag pour K17 (Menu API) + DB schema audit.

### P1 (high — V1.0.1 sprint)

- **K14-P1-01: Canonical allergen order désaligné de la SSOT (`poissons` vs `poisson`)**
  - File: `resources/js/helpers/kioskFilters.js:146` (et `:157` `anhydride_sulfureux`)
  - Issue: `KIOSK_ALLERGEN_CANONICAL_ORDER` liste `poissons` (pluriel) alors que `database/seeders/AllergensSeeder.php:25` enregistre `poisson` (singulier) — code FR officiel UE 1169/2011 = singulier. `sortAllergenCodesUnique` ne trouve pas l'index canonique pour `poisson`, retombe sur tri alphabétique → le badge "🐟 Poisson" peut apparaître avant `lait` ou `oeufs` selon les codes présents, contredisant l'ordre UE.
  - Evidence: `kioskFilters.js:142-158` const liste `poissons` ; `AllergensSeeder.php:25` insère `poisson`. La constante `ICONS` dans `KsAllergenBadge.vue:55` mappe `poisson: '🐟'` (singulier — correct).
  - Suggested fix: `'poissons'` → `'poisson'` ligne 146. Ajouter test régression assertion `sortAllergenCodesUnique(['poisson','lait']) === ['lait','poisson']` (lait code 2, poisson code 4).

- **K14-P1-02: `applyKioskFilters` dead export — risque de drift catalogue**
  - File: `resources/js/helpers/kioskFilters.js:79-84` + export ligne 289
  - Issue: La fonction `applyKioskFilters` (filtrage catégorie grille) n'a AUCUN appelant dans `resources/js/**` (vérifié grep récursif). Or `KioskCategoriesComponent.vue` est censé filtrer les produits visibles selon les chips actives (cf. `kioskFilterPersist.spec.js`). Seuls les filtres wizard (`isVariationAllowedByFilters`) sont câblés (4 step components). Conséquence : un client allergique gluten qui active le filtre "gluten_free" ne voit AUCUN effet sur la grille catégorie, seulement à l'intérieur du wizard → trompeur.
  - Evidence: `grep -rn "applyKioskFilters" resources/js` retourne uniquement la définition + l'export par défaut. Aucun call-site.
  - Suggested fix: soit câbler dans `KioskCategoriesComponent.vue` au-dessus du `v-for` produits, soit supprimer l'export et son test associé pour signaler honnêtement l'absence de feature.

- **K14-P1-03: `kioskItemDisplayOrder` ignore `base_price_cents` (DATA_CONTRACT V2 drift)**
  - File: `resources/js/helpers/kioskItemDisplayOrder.js:16-19`
  - Issue: `getKioskItemUnitPrice` ne lit que `convert_price` puis `price` ; `kioskFilters.js:37-39` privilégie `base_price_cents` (DATA_CONTRACT V2 — finest precision). Si le backend bascule au format V2 cents-only (déjà supporté par filters.js et `kioskFormatPrice`), tous les items auront unit price = 0 dans le tri d'affichage → ordre par taille uniquement, plus de tri prix croissant cassé silencieusement.
  - Evidence: Comparaison ligne 17 (display order) vs `kioskFilters.js:37-49` (filters). Drift documenté.
  - Suggested fix: aligner `getKioskItemUnitPrice` sur le pattern `kioskFilters.getItemPriceEuros` — privilégier `base_price_cents/100` en premier.

- **K14-P1-04: `KsAllergenBadge` n'absorbe pas les codes EN legacy (`crustaceans`, `eggs`, `fish`, …) dans la collision**
  - File: `resources/js/helpers/kioskFilters.js:142-158` ; `KsAllergenBadge.vue:60-77`
  - Issue: `extractAllergenCodes` lowercase mais ne normalise PAS EN→FR (`peanuts → arachides`, `milk → lait`, …). La migration `2026_04_18_140002_rename_allergen_codes_to_fr.php` indique que des codes EN persistent en snapshots. Conséquence : un `composition_snapshot` legacy avec `['peanuts']` + un client déclarant `['arachides']` → collision NON détectée par `getAllergenCollision`. EAA 2025 = compliance issue sur recap commande.
  - Evidence: `kioskFilters.js:118-129` ne mappe pas, juste lowercase. `KsAllergenBadge.vue:60-77` ICONS contient les alias EN mais c'est cosmétique — `mergeAllergens` ne les unifie pas.
  - Suggested fix: table de mapping EN→FR dans `extractAllergenCodes` (cf. migration `backfill_fr_codes_in_order_items_allergens_snapshot.php:26-XX` qui contient déjà la table). Ajouter test : `extractAllergenCodes({allergens:['peanuts']}) === ['arachides']`.

### P2 (medium — backlog priorisé)

- **K14-P2-01: AR i18n catalogue helpers — regex FR-only**
  - File: `kioskSauceCatalog.js:13-21` (`isSauceLikeAttributeName`), `kioskDrinkAddons.js:12-14` (`FOOD_LIKE_REGEX`, `DRINK_LIKE_REGEX`), `kioskExtrasPartition.js:46-55` (`kioskIsViandePaidExtra`), `kioskCategoryOrder.js:22-44` (`kioskCategoryDisplayTier`)
  - Issue: Si la borne bascule AR via `kioskSettings.locale='ar'` persisté (cf. `i18n.js:14-19` et `kioskRtl.spec.js:47-63` qui prouvent que ça contourne FR-lock), les heuristiques regex `viande/meat/protein`, `boisson/drink`, `sandwich/burger`, etc. n'auront aucun équivalent arabe. Aucune sauce ne sera détectée, aucune viande extra payante ne sera promue, aucun drink addon ne sera filtré → étapes wizard vides.
  - Evidence: 4 fichiers, 0 chaîne arabe.
  - Suggested fix: l'audit FR-lock V1 garde ce point en P2 (AR pas en V1 production). Si V1.0.x débloque AR : prioriser `group_label` SSOT backend, supprimer les heuristiques `name.includes(...)` (déjà fait dans `kioskDrinkAddons.js` ligne 43-50 mais pas dans `kioskExtrasPartition.kioskIsViandePaidExtra`).

- **K14-P2-02: `kioskCategoryOrder` confond les noms de catégories techniques avec des marqueurs**
  - File: `resources/js/helpers/kioskCategoryOrder.js:36-44`
  - Issue: Une catégorie nommée "Snacking" tombe dans tier 0 (regex ligne 37 contient `snacking`), une catégorie "Box du midi" tombe en tier 0 aussi (regex contient `box `). Mais la regex `nugget` ligne 37 catche aussi une catégorie "Nuggets-extras" qui devrait probablement être tier 1 (accompagnement). Subtle classification noise.
  - Evidence: `kioskCategoryOrder.js:37` regex large.
  - Suggested fix: prioriser le slug si présent (déjà fait via concat ligne 19), publier une `data-category-tier` au niveau backend si la catégorie a un sort_kiosk_tier explicite.

- **K14-P2-03: `kioskItemDisplayOrder` rank size ne distingue pas tacos vs sandwich vs assiette**
  - File: `resources/js/helpers/kioskItemDisplayOrder.js:25-43`
  - Issue: La regex `(^|[\s\-–—/(])l([\s\-–—)/]|$)` (ligne 35) matche `Hot Dog L` ET `Salade L` ET `Tacos L` uniformément avec rank 40. Si la catégorie est mixte (improbable mais possible — promo carousel), le tri se mélange.
  - Evidence: Ligne 35 — match contextuel ouvert.
  - Suggested fix: parameter optionnel `categorySlug` pour scope, ou stocker `kiosk_size_tier` admin-side.

### P3 (low — nice-to-have)

- **K14-P3-01: `anhydride_sulfureux` orphan dans canonical order**
  - File: `kioskFilters.js:157`
  - Issue: Pas dans `AllergensSeeder.php`. Code mort.
  - Suggested fix: supprimer ligne 157, garder `sulfites` qui est le code SSOT.

- **K14-P3-02: `matchFilter` dead param `customerAllergens`**
  - File: `kioskFilters.js:51`
  - Issue: signature `matchFilter(item, filter, customerAllergens = [])` mais le paramètre n'est jamais lu — laisse penser que les filtres masquent par allergie (faux, c'est `getAllergenCollision` qui sert au badge).
  - Suggested fix: supprimer le paramètre, le commentaire ligne 73-75 explique déjà la doctrine "no masking".

- **K14-P3-03: `sanitizeKioskCustomerFacingText` ne couvre pas les raw labels i18n type `Label.X` / `kiosk.foo`**
  - File: `resources/js/helpers/kioskDisplayText.js:10-27`
  - Issue: 8 paires regex couvrent les termes techniques EN (`addon`, `upgrade`, `extras`, `variation`) mais aucun garde-fou contre une clé i18n non-résolue qui leak via `name` brut (ex. `t('Label.X')` retourne `Label.X` si manquante). Le projet l'a déjà rencontré en mobile audits — cf. CLAUDE.md §6 visual mandate.
  - Suggested fix: ajouter détection `/^(kiosk\.|Label\.|kds\.|pos\.)[\w.]+$/` → retourner chaîne vide + console.warn dev-only.

## Existing E2E coverage

- `tests/js/kioskAllergenMerge.spec.js` — 11 cas couvrant mergeAllergens (item-only, item+extra, item+variation+extra, dedup, string CSV, casse mixte, null/undefined rétrocompat). **Solide.**
- `tests/js/kioskRtl.spec.js` — 6 cas couvrant `<html dir>` / `<html lang>` switch FR↔AR via `setLocale` + `applyKioskA11yFromStore` (reload scenario). **Solide.**
- `tests/js/kioskCategoryOrder.spec.js` — 4 cas : tiers, sort dans tier, classification connue, sandwich froid. **Léger** — manque assiettes, snacking, ambigus.
- `tests/js/kioskViandeCatalog.spec.js` — 11 cas : null, variations free, extras paid, status 10, dédup, no-attr+extra fallback, surcharge sum. **Solide.**
- `tests/js/kioskFilterPersist.spec.js` (hors scope mais touche) — couvre Vuex `kioskFilter` + `isVariationAllowedByFilters`.
- **Absent** : aucun spec pour `applyKioskFilters`, `kioskItemDisplayOrder`, `kioskSauceCatalog`, `kioskDrinkAddons`, `kioskDisplayText`. Coverage trou.

## Proposed new E2E tests

- **T-K14-01: Allergen contract gap regression (backend↔frontend)**
  - Steps: stub `KioskMenuService` à renvoyer `extras: [{id:1, name:'Fromage', allergens:[{code:'lait'}]}]`, mount `KsAllergenBadge` avec item.allergens=['gluten'] + selections.extras=[{id:1, allergens:['lait']}].
  - Assertions: `effectiveAllergens` contient `['gluten','lait']`, role=alert si customerAllergens=['lait']. **Bloque la régression silencieuse de P0-01 quand le backend déploie le fix.**

- **T-K14-02: Canonical order alignment**
  - Steps: `sortAllergenCodesUnique(['poisson','crustaces','lait','gluten'])` (codes SSOT seeder).
  - Assertions: résultat = `['gluten','lait','crustaces','poisson']` strictement (gluten=0, lait=1, crustaces=3, poisson=4). **Détecte le drift P1-01.**

- **T-K14-03: Display order with base_price_cents V2**
  - Steps: `sortKioskItemsForDisplay([{name:'X', base_price_cents:1200}, {name:'Y', base_price_cents:800}])`.
  - Assertions: ordre `[Y,X]` (8€ avant 12€). **Aujourd'hui retourne [X,Y] car cents ignoré — fail attendu jusqu'à fix P1-03.**

- **T-K14-04: Drink addon partitioning robustness**
  - Steps: `kioskDrinkAddonRowsFromItem({addons:[{name:'Boisson 33cl', group_label:'boisson'}, {name:'Frites M', group_label:'menu_frite'}, {name:'Coca', group_label:''}]})`.
  - Assertions: 2 rows (Boisson + Coca), Frites exclu. **Couvre les 3 chemins : group_label OUI, group_label NON+name regex, ambiguïté résolue.**

- **T-K14-05: Sanitize raw-label leak**
  - Steps: `sanitizeKioskCustomerFacingText('kiosk.wizard.confirmation.title')` ; `('Label.unknown')` ; `('Sauce Algérienne add-on')`.
  - Assertions: les deux premiers retournent `''` (raw label détecté), le 3e retourne `'Sauce Algérienne options'`. **Couvre P3-03 + régression sanitize existante.**

## Risks & open questions

- **OWNER GATE** P0-01 (allergen extras backend gap) — décision business : ajouter pivot `item_extras_allergens` / `item_variations_allergens` ou snapshot inline lors du seeder catalogue ? EAA 2025 effet 28 juin 2025, V1 production prévue ≤ Q3 2025 → décision urgente.
- AR locale en V1 ? Si NON (FR-lock immutable confirmé `i18n.js:21`), tous les P2 catalogue regex restent backlog. Si OUI, plusieurs heuristiques à durcir.
- `applyKioskFilters` — décision design : doit-elle masquer les items grille (UX paternaliste) ou rester informationelle uniquement (WCAG-friendly, badge collision) ? Cf. doctrine `kioskFilters.js:73-75`.
- Mobile recall (defaultAllergensFor smart-default par cat — `mobile/data/menu.js:254-292`) : kiosk EST server-driven via `item.allergens` pivot (`KioskMenuService.php:330`) — pas de hardcoding. Intégrité OK pour items, faille à propager sur variations/extras (cf. P0-01).
