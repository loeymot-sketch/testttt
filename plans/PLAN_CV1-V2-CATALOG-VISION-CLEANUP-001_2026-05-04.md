# PLAN — Catalog Studio Vision Cleanup 001

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V2-CATALOG-VISION-CLEANUP-001` |
| Date | 2026-05-04 01:10 UTC+2 |
| Auteur | Claude (orchestrateur — décisions techniques déléguées par utilisateur) |
| Précédents | `CV1-V2-CATALOG-UX-CLEANUP-001` (CLOSED) |
| RUNNER_MODE | single-session |
| PHASE | EXECUTE |
| EXECUTION_TIER | mixed (2 complex, 2 routine, parallèles) |
| EXECUTE_DELEGATION | sub-agents Q (complex) + R (complex) + S (routine) + T (routine) |

---

## 0. TL;DR

7 problèmes UX/architecture remontés par utilisateur après tour manuel approfondi :
1. **P0 i18n leak critique** : 15 clés `studio.*` manquantes en frontend → labels techniques visibles dans le Studio (et possiblement borne/caisse).
2. **P0 bouton "Ajouter catégorie" non fonctionnel** : formulaire toggle correctement mais hors viewport (en bas sidebar longue).
3. **P0 quick-create produit bloqué** sur "Toutes les catégories" (`:disabled` sur `selectedCategoryId`).
4. **UX nav arbre** : 3 entrées menu redondantes ("Articles" legacy + "Liste Produits" Studio + "Attribut d'articles") = confusion → 1 seul "Catalogue".
5. **UX modale catégorie complexe** : 4 options techniques (parcours/menu/borne) à plier sous "Avancé".
6. **UX stock invisible** : StockRuptureDashboard existe mais aucune route SPA, lien Studio mène ailleurs.
7. **C2 confirmé reporté** (1 seule filiale → pas de besoin business pour overrides matrice).

---

## 1. Vision architecturale (réponse à la demande utilisateur)

L'utilisateur demande : "système d'arbre clair et accessible — gestion produits/catégories/wizards d'un côté, gestion stock de l'autre. Le reste = bruit administratif".

### Arbre cible post-cycle

```
ADMIN
├── Tableau de bord
│
├── Catalogue ← UN SEUL accès, cible Catalog Studio
│   └── (page unique Studio)
│       ├── Sidebar : catégories + bouton "Gérer les catégories" (drawer)
│       ├── Header : titre, sous-titre, "Ajouter catégorie", "Ajouter article" (universal)
│       ├── Grille : produits filtrés par catégorie sélectionnée
│       ├── Bouton "Configurer le wizard" sur chaque produit (drawer composer)
│       └── Carte stock inline + lien "Tableau de bord stock complet"
│
├── Stock ← NEW route, route SPA pour StockRuptureDashboard
│
├── CAISSE ET COMMANDES
│   ├── POS
│   ├── Commandes Caisse
│   ├── Cuisine
│   └── Écran Statut
│
├── (autres modules)
└── Paramètres ← MINIMAL : pas de Catégories, pas d'Attributs (cachés)
```

### Hors menu (cachés mais routes accessibles pour rollback)

- Articles (legacy `ItemListComponent`) → redirect vers Catalog Studio
- Paramètres > Attribut d'articles → déjà caché cycle précédent
- Paramètres > Catégories → caché ce cycle (Studio gère tout)

---

## 2. SUBSYSTEMS_TOUCHED

| Subsystem | Fichiers | Read/Write |
|---|---|---|
| **i18n studio.* (5 langues)** | `resources/js/languages/{fr,en,de,bn,ar}.json` | WRITE (15 clés × 5 = 75 traductions à ajouter) |
| **Sentinel i18n strict** | `tests/js/studioFrontendI18nParity.spec.js` | WRITE (renforcer pour scanner les .vue + comparer) |
| **Catalog Studio** | `resources/js/components/admin/items/CatalogStudioComponent.vue` | WRITE (UX fixes : add category scroll/focus, quick-create universel avec dropdown catégorie, lien stock dashboard) |
| **Modale catégorie** | `resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue` | WRITE (regrouper 4 options sous "Avancé" repliable) |
| **Nav menu** | `resources/js/components/layouts/backend/BackendMenuComponent.vue` | WRITE (cacher "Articles" legacy + clarifier libellé Catalogue) |
| **Hidden modules** | `resources/js/config/v1-hidden-modules.js` | WRITE (ajouter clé pour Articles legacy + ItemCategory list Paramètres) |
| **Routes** | `resources/js/router/modules/itemRoutes.js` | WRITE (redirect Articles legacy → Studio) |
| **Routes stock** | `resources/js/router/modules/` (nouveau ou existant) | WRITE (route /admin/stock/rupture déclarée) |
| **Tests Vitest** | `tests/js/catalogStudioAddCategoryUx.spec.js`, `tests/js/catalogStudioQuickCreateUniversal.spec.js`, `tests/js/itemCategoryCreateAdvancedSection.spec.js`, `tests/js/stockRuptureRoute.spec.js`, `tests/js/v1HiddenMenuModules.spec.js` (UPDATE) | WRITE |

## SUBSYSTEMS_OFF_LIMITS

- Backend (`app/`, `database/`, `routes/`) — RAS, tout est frontend.
- Composants Composer (β1 livré, ne pas re-toucher).
- Frozen zones.

## INVARIANTS_AT_RISK

- RAS (cycle UX/i18n/nav, aucune logique métier touchée).

---

## 3. STRATÉGIE — 4 sub-agents parallèles

```
┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────┐
│ Q (complex)          │ │ R (complex)          │ │ S (routine)          │ │ T (routine)          │
│ i18n studio.* leak   │ │ Studio UX bugs       │ │ Modale catégorie     │ │ Nav arbre + stock    │
│ + sentinel renforcé  │ │ (P2+P3+P5)           │ │ Avancé section       │ │ route + cleanup nav  │
└──────────────────────┘ └──────────────────────┘ └──────────────────────┘ └──────────────────────┘
         │                       │                       │                       │
         └───────────────────────┴───────────────────────┴───────────────────────┘
                                              │
                                              ▼
                                    AUDIT CONSOLIDÉ
                                    Vitest + PHPUnit + npm dev + Playwright
                                              │
                                ┌─────────────┴─────────────┐
                                │                           │
                            PASS │                       REWORK
                                │                           │
                          CLOSE cycle                  correction
                                                       max 5 rounds
```

**Conflits potentiels** :
- Q et S/T ne touchent pas les mêmes fichiers. Q = JSON langues + sentinel. S = ItemCategoryCreateComponent. T = nav + routes.
- R et S touchent potentiellement deux composants liés (Studio appelle Modale Catégorie). Mais R touche `CatalogStudioComponent.vue`, S touche `ItemCategoryCreateComponent.vue`. **Zéro overlap**.
- Q ajoute clés que R utilise ; mais Q écrit sur fr.json/etc., R sur Studio.vue. Si R utilise une clé qui sera ajoutée par Q, le test ne tombe pas avant que les 2 soient mergés. C'est OK.
- T ajoute `'settings.item-categories'` à v1-hidden-modules ; S touche le composant lui-même. OK.

→ **4 sub-agents en VRAI PARALLÈLE possible**. Lance les 4 d'un coup.

---

## 4. Sub-agent Q — i18n studio.* leak fix (complex)

### Mission

1. **Ajouter 15 clés `studio.*` manquantes** dans les 5 fichiers JSON langues (`resources/js/languages/{fr,en,de,bn,ar}.json`) :

| Clé | FR | EN | DE | BN | AR |
|---|---|---|---|---|---|
| `eyebrow` | "Pilotage catalogue" | "Catalog control center" | "Katalog-Steuerung" | "ক্যাটালগ নিয়ন্ত্রণ" | "مركز التحكم في الكتالوج" |
| `title` | "Catalogue" | "Catalog" | "Katalog" | "ক্যাটালগ" | "الكتالوج" |
| `subtitle` | "Catégories, produits, wizards et stock" | "Categories, products, wizards and stock" | "Kategorien, Produkte, Wizards und Lager" | "ক্যাটাগরি, পণ্য, উইজার্ড এবং স্টক" | "الفئات، المنتجات، المعالجات والمخزون" |
| `all_categories` | "Toutes les catégories" | "All categories" | "Alle Kategorien" | "সব ক্যাটাগরি" | "جميع الفئات" |
| `products_count` | "{n} produits" (pluralisable) | "{n} products" | "{n} Produkte" | "{n} পণ্য" | "{n} منتج" |
| `advanced_settings` | "Paramètres avancés" | "Advanced settings" | "Erweiterte Einstellungen" | "উন্নত সেটিংস" | "إعدادات متقدمة" |
| `stock_link` | "Tableau de bord stock" | "Stock dashboard" | "Lager-Dashboard" | "স্টক ড্যাশবোর্ড" | "لوحة المخزون" |
| `quick_create_product` | "Ajouter rapidement un produit" | "Quick add product" | "Produkt schnell hinzufügen" | "দ্রুত পণ্য যোগ করুন" | "إضافة منتج سريعة" |
| `stock_parallel_title` | "Stock & disponibilité" | "Stock & availability" | "Lager & Verfügbarkeit" | "স্টক এবং প্রাপ্যতা" | "المخزون والتوفر" |
| `daily_quota_hint` | "Quota du jour : illimité par défaut" | "Daily quota: unlimited by default" | "Tageskontingent: standardmäßig unbegrenzt" | "দৈনিক কোটা: ডিফল্টভাবে সীমাহীন" | "الحصة اليومية: غير محدودة افتراضيًا" |
| `stock_parallel_hint` | "Synchronisé avec stock central et borne" | "Synced with central stock and kiosk" | "Synchronisiert mit zentralem Lager und Kiosk" | "কেন্দ্রীয় স্টক এবং কিয়স্কের সাথে সিঙ্ক" | "متزامن مع المخزون المركزي والكشك" |
| `composer_drawer_eyebrow` | "Wizard produit" | "Product wizard" | "Produkt-Wizard" | "পণ্য উইজার্ড" | "معالج المنتج" |
| `composer_drawer_title` | "Configurer les étapes" | "Configure steps" | "Schritte konfigurieren" | "ধাপ কনফিগার করুন" | "تكوين الخطوات" |
| `open_full_page` | "Ouvrir en pleine page" | "Open full page" | "Vollbild öffnen" | "পূর্ণ পৃষ্ঠায় খুলুন" | "فتح الصفحة الكاملة" |
| `select_category_first` | "Choisis une catégorie d'abord" | "Choose a category first" | "Wähle zuerst eine Kategorie" | "প্রথমে একটি ক্যাটাগরি নির্বাচন করুন" | "اختر فئة أولاً" |

**Pattern d'ajout** : insérer ces clés dans la section `studio` existante de chaque fichier (qui contient déjà `composer.*`, `image.*`). Ne pas écraser, fusionner.

2. **Renforcer le sentinel** `tests/js/studioFrontendI18nParity.spec.js` :

Ajouter un test "no leak" qui :
- Scanne `resources/js/components/admin/items/CatalogStudioComponent.vue` via fs.readFileSync.
- Extrait toutes les clés `\$t\(['"]studio\.[^'"]+['"]\)` via regex.
- Vérifie que **chaque clé extraite est définie dans fr.json**.
- Si une clé manque → `fail` avec liste précise des clés manquantes.

Ce sentinel évite que ce bug se reproduise jamais.

3. **Vérifier la fuite borne/caisse** : grep complet de `studio\.` dans `resources/js/components/frontend/kiosk/` et `resources/js/components/admin/pos/`. Si aucun match, la fuite rapportée par l'utilisateur est probablement le bundle qui inclut `CatalogStudioComponent` chargé en background (et son template avec clés bruts non traduites est rendu avant que l'utilisateur soit logué). **Documenter** dans le rapport.

4. **Tests** :
   - Sentinel renforcé `studioFrontendI18nParity.spec.js` PASS avec les 15 clés ajoutées.
   - Vitest globale 0 régression.

5. **Rapport** `reports/execution/RUN_VISION_CLEANUP_I18N_LEAK_FIX_2026-05-04.md`.

### Allowlist Q

- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `resources/js/languages/de.json`
- `resources/js/languages/bn.json`
- `resources/js/languages/ar.json`
- `tests/js/studioFrontendI18nParity.spec.js` (UPDATE renforce)
- `reports/execution/RUN_VISION_CLEANUP_I18N_LEAK_FIX_2026-05-04.md`

---

## 5. Sub-agent R — Studio UX bugs P2+P3+P5 (complex)

### Mission

1. **Fix bouton "Ajouter une catégorie d'articles" (P2)** :
   - Le toggle `showCategoryQuickForm = !showCategoryQuickForm` au L13 fonctionne, mais le formulaire L68 est en bas de sidebar, hors viewport quand la liste est longue.
   - Solutions :
     - (a) Quand `showCategoryQuickForm = true`, faire `this.$nextTick(() => { this.$refs.categoryQuickFormNameInput.focus(); this.$refs.categoryQuickFormNameInput.scrollIntoView({behavior: 'smooth', block: 'center'}); })`.
     - (b) Bonus visuel : ajouter une classe `is-pulse` 1500ms pour attirer l'œil.
   - Lis le composant pour voir si les `ref` existent ou les ajouter.

2. **Fix quick-create produit universel (P3)** :
   - Bouton "Ajouter un article" L17-21 : retirer `:disabled="!selectedCategoryId"`.
   - Ajouter dans le formulaire quick-create un **dropdown catégorie obligatoire** quand `selectedCategoryId === null` :
     ```html
     <div v-if="!selectedCategoryId" class="catalog-studio__quick-create-category-select">
         <label>{{ $t('studio.select_category_first') }}</label>
         <select v-model="quickProduct.categoryId" required>
             <option value="">—</option>
             <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
         </select>
     </div>
     ```
   - `buildQuickProductPayload()` (~L423) : utiliser `quickProduct.categoryId || selectedCategoryId` comme `item_category_id`.
   - `createProduct()` (~L510-534) : valider qu'on a bien une catégorie (soit selectedCategoryId, soit quickProduct.categoryId), sinon afficher erreur.

3. **Lien stock dashboard (P5/P8)** :
   - Le lien `studio.stock_link` actuellement L77-81 pointe vers `name: 'admin.items.list', query: { focus: 'availability' }` — mauvais target. Doit pointer vers `name: 'admin.stock.rupture'` (route déclarée par sub-agent T).
   - Si T n'a pas encore livré la route, utiliser `path: '/admin/stock/rupture'` en fallback. Mais idéalement par `name`.

4. **Tests** :
   - `tests/js/catalogStudioAddCategoryUx.spec.js` (NEW) :
     - test_clicking_add_category_toggles_form_visible
     - test_clicking_add_category_focuses_name_input_after_tick
   - `tests/js/catalogStudioQuickCreateUniversal.spec.js` (NEW) :
     - test_add_product_button_enabled_on_all_categories_view
     - test_quick_create_form_shows_category_dropdown_when_no_category_selected
     - test_create_product_uses_dropdown_category_when_provided
     - test_create_product_falls_back_to_selected_category
     - test_create_product_rejects_when_no_category_at_all

5. **Rapport** `reports/execution/RUN_VISION_CLEANUP_STUDIO_UX_2026-05-04.md`.

### Allowlist R

- `resources/js/components/admin/items/CatalogStudioComponent.vue` (write)
- `tests/js/catalogStudioAddCategoryUx.spec.js` (NEW)
- `tests/js/catalogStudioQuickCreateUniversal.spec.js` (NEW)
- `reports/execution/RUN_VISION_CLEANUP_STUDIO_UX_2026-05-04.md`

---

## 6. Sub-agent S — Modale catégorie "Avancé" repliable (routine)

### Mission

1. **Modifier `ItemCategoryCreateComponent.vue`** :
   - Identifier les 4 champs avancés : `wizard_template` (L63-73), `has_menu` (L77-93), `kiosk_upsell_include` (L98-115), `kiosk_upsell_skip_after_cart` (L117-134).
   - Les **regrouper sous une section dépliable** :
     ```html
     <div class="advanced-section">
         <button type="button" @click="showAdvanced = !showAdvanced" :aria-expanded="showAdvanced.toString()">
             <span>{{ $t('studio.advanced_settings') }}</span>
             <span :class="showAdvanced ? 'icon-chevron-up' : 'icon-chevron-down'">▾</span>
         </button>
         <transition name="expand">
             <div v-show="showAdvanced" class="advanced-section__body">
                 <!-- les 4 champs ici -->
             </div>
         </transition>
     </div>
     ```
   - `data()` : ajouter `showAdvanced: false` (replié par défaut).

2. **Vérifier les valeurs par défaut backend** :
   - Lis `app/Models/ItemCategory.php` ou la migration `create_item_categories_table` pour confirmer que `wizard_template=null`, `has_menu=false`, `kiosk_upsell_include=true` (défaut), `kiosk_upsell_skip_after_cart=false` ont des defaults backend cohérents.
   - Si certains n'ont pas de default → flag dans le rapport (mais ne pas modifier backend).

3. **Tests** :
   - `tests/js/itemCategoryCreateAdvancedSection.spec.js` (NEW) :
     - test_advanced_section_collapsed_by_default
     - test_clicking_toggle_expands_advanced_section
     - test_basic_fields_always_visible (nom, image, statut, description)
     - test_advanced_fields_only_visible_when_expanded

4. **Rapport** `reports/execution/RUN_VISION_CLEANUP_CATEGORY_FORM_2026-05-04.md`.

### Allowlist S

- `resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue` (write)
- `tests/js/itemCategoryCreateAdvancedSection.spec.js` (NEW)
- `reports/execution/RUN_VISION_CLEANUP_CATEGORY_FORM_2026-05-04.md`

---

## 7. Sub-agent T — Nav arbre + route stock (routine)

### Mission

1. **Cacher "Articles" legacy du menu** :
   - Dans `BackendMenuComponent.vue`, le parent "Articles" est rendu via L22-30 depuis la DB (table `menus`). On ne peut pas modifier la DB depuis le frontend.
   - Solution : ajouter une condition `v-if` qui cache l'entrée parent quand son URL est `items` legacy. Ou plus propre : dans `v1-hidden-modules.js`, ajouter un mécanisme pour cacher des entrées **DB-driven** par URL.
   - Lire `v1-hidden-modules.js` pour voir le pattern existant + son intégration dans BackendMenuComponent.
   - Implémentation propre : ajouter une constante `V1_HIDDEN_BACKEND_MENU_URLS = ['items']` dans `v1-hidden-modules.js`. Dans BackendMenuComponent template, ajouter `v-if="!hiddenBackendUrls.includes(menu.url)"` sur le parent.
   - **Préserver** "Liste Produits" (enfant virtuel L88) qui pointe vers Studio — c'est notre catalogue principal.

2. **Renommer "Liste Produits" → "Catalogue"** :
   - L88 dans BackendMenuComponent : `language:'product_list'` → soit changer en `'catalog'`, soit garder le code et ajouter une nouvelle clé i18n `menu.catalog` qui sera la nouvelle référence.
   - Vérifier que `menu.product_list` est utilisé ailleurs (sinon le supprimer, mais probablement utilisé).
   - Plus simple : ajouter clé `menu.catalog` dans les 5 langues + utiliser cette clé.

3. **Cacher Paramètres > Catégories du menu Paramètres** :
   - `MenuComponent.vue` Paramètres : déjà câblé pour `'settings.item-categories'` selon cycles précédents. Vérifier que c'est bien actif et l'item est caché.
   - Si pas câblé : ajouter `v-if="!isSettingHidden('itemCategories')"` sur l'entry "Catégories" + extension du mapping.

4. **Redirect Articles legacy → Studio** :
   - Dans `resources/js/router/modules/itemRoutes.js`, identifier la route `admin.items.list` (ou similaire). Modifier pour redirect vers `admin.items.studio` :
     ```js
     {
         path: 'list',
         name: 'admin.items.list',
         redirect: { name: 'admin.items.studio' },
     }
     ```
   - **Mais préserver** la route si elle est utilisée par d'autres endroits (`AvailabilityToggle`, exports, imports). Lire d'abord.

5. **Déclarer la route SPA stock** :
   - Identifier où ajouter la route. Probable : `resources/js/router/modules/` (chercher un fichier `stockRoutes.js` ou similar, sinon créer une route dans `itemRoutes.js` ou un nouveau fichier).
   - Pattern :
     ```js
     {
         path: '/admin/stock/rupture',
         name: 'admin.stock.rupture',
         component: () => import('@/components/admin/stock/StockRuptureDashboardComponent.vue'),
         meta: { auth: true, breadcrumb: 'stock_rupture' },
     }
     ```
   - **Vérifier** que le composant `StockRuptureDashboardComponent.vue` existe et compile. S'il a des dépendances manquantes (store, props), juste flag dans le rapport.

6. **Ajouter clés i18n** :
   - `menu.catalog` (5 langues : Catalogue / Catalog / Katalog / ক্যাটালগ / الكتالوج)
   - `menu.stock_rupture` (5 langues : Tableau de bord stock / Stock dashboard / Lager-Dashboard / স্টক ড্যাশবোর্ড / لوحة المخزون)
   - **Note** : Q ajoute aussi des clés. Pas de conflit car Q écrit dans la section `studio.*` et T écrit dans `menu.*`. Mais **les 2 sub-agents touchent les mêmes fichiers** (`fr.json` etc.) → potentielle race condition d'écriture.
   - **Solution** : T attend que Q termine, OU T ajoute ses clés dans une section différente. Dans tous les cas, les 2 sub-agents doivent **lire le fichier complet, modifier, écrire** atomiquement. Si race, le 2e à écrire écrase le 1er.
   - **Décision orchestrateur** : T ne lance qu'**après Q PASS** pour éviter race condition sur fr.json/etc. → séquencer Q → T.

7. **Tests** :
   - `tests/js/v1HiddenMenuModules.spec.js` (UPDATE) :
     - Ajouter assertion sur `V1_HIDDEN_BACKEND_MENU_URLS.includes('items')` si ce nouveau mécanisme est créé.
     - Préserver les assertions précédentes.
   - `tests/js/stockRuptureRoute.spec.js` (NEW) :
     - test_admin_stock_rupture_route_is_defined
     - test_route_resolves_stock_rupture_dashboard_component
   - `tests/js/articleListLegacyRedirect.spec.js` (NEW) :
     - test_admin_items_list_redirects_to_studio

8. **Rapport** `reports/execution/RUN_VISION_CLEANUP_NAV_TREE_2026-05-04.md`.

### Allowlist T

- `resources/js/components/layouts/backend/BackendMenuComponent.vue` (write)
- `resources/js/components/admin/settings/MenuComponent.vue` (write si nécessaire)
- `resources/js/config/v1-hidden-modules.js` (write extension)
- `resources/js/router/modules/itemRoutes.js` (write redirect)
- `resources/js/router/modules/` — soit créer `stockRoutes.js`, soit ajouter dans existant
- `resources/js/languages/{fr,en,de,bn,ar}.json` (write `menu.catalog` + `menu.stock_rupture`)
- `tests/js/v1HiddenMenuModules.spec.js` (UPDATE)
- `tests/js/stockRuptureRoute.spec.js` (NEW)
- `tests/js/articleListLegacyRedirect.spec.js` (NEW)
- `reports/execution/RUN_VISION_CLEANUP_NAV_TREE_2026-05-04.md`

### Séquencement T

**T attend que Q ait terminé** avant de toucher aux JSON langues. Pour le reste (BackendMenuComponent, routes), T peut tourner en parallèle de Q.

→ **Décision finale** : Q + R + S en parallèle, **puis T seul** (pour éviter race sur JSON langues).

---

## 8. Audit consolidé

1. `npx vitest run` → 1093+X PASS.
2. `php artisan test tests/Feature/Composer/ tests/Feature/Items/ tests/Feature/I18n/` → 0 régression.
3. `npm run dev` → rebuild bundles.
4. `npx playwright test tests/e2e/catalog-studio-create-product-flow.spec.js` → critical-flow.
5. **Rapport final consolidé**.

### CLOSE

- Vitest +X PASS (estimé +18-22 tests).
- 0 régression backend.
- Playwright PASS.
- 0 fichier hors allowlist.

### REWORK

- Tests fail → correction localisée, max 5 rounds.

---

## 9. Mémoire post-cycle (Graphiti)

À pousser à CLOSE :
1. **i18n leak fix** : 15 clés `studio.*` ajoutées (eyebrow, title, subtitle, all_categories, products_count, advanced_settings, stock_link, quick_create_product, stock_parallel_*, daily_quota_hint, composer_drawer_*, open_full_page, select_category_first) dans 5 langues. Sentinel renforcé scanne le SFC pour détecter futures clés non définies.
2. **Studio UX fixes** : bouton "Ajouter catégorie" scroll+focus, quick-create produit universel (dropdown catégorie inline si "Toutes" sélectionné), lien stock dashboard pointant vers route SPA réelle.
3. **Modale catégorie** : 4 options techniques (parcours/menu/borne) regroupées sous section "Avancé" repliée par défaut.
4. **Nav arbre simplifié** : "Articles" legacy caché du menu (route conservée + redirect vers Studio), "Liste Produits" renommé "Catalogue", route SPA `/admin/stock/rupture` déclarée.
5. **C2 (branch overrides matrice)** : confirmé reporté V2+ par l'utilisateur (1 seule filiale actuellement).
