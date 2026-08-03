# Ultra Audit + Ultra Plan + Ultra Review — Pivot V1 FoodKing

| Champ | Valeur |
|---|---|
| Date | 2026-05-04 |
| Reviewer | Claude (claude-opus-4-7 1M context, Claude Code CLI, effort max) |
| Scope | Pivot stratégique V1 : retrait personnalisation wizard per-item, héritage wizard catégorie, concept "Ingrédient" unifié, dashboard 3-4 boutons |
| MCP Graphiti | OUI — 4 queries `search_memory_facts` (group_id=foodking) |
| Format | AUDIT + PLAN + ULTRA REVIEW dans 1 document, ~4500 mots |
| Cycles précédents pris en compte | `CV1-V2-CATALOG-VISION-CLEANUP-001` (CLOSED 2026-05-04), `CV1-V2-CATALOG-WIZARD-CORE-001` (CLOSED 2026-05-04), `CV1-V1-CLOSEOUT-MASTER-2026-05-02` |

---

## Clarifications nécessaires avant production

Avant de produire le plan, **4 ambiguïtés à trancher** par l'utilisateur. J'attaque ensuite avec des hypothèses raisonnables (notées `[H1] [H2] [H3] [H4]`) que tu corriges si elles ne tiennent pas.

1. **Multi-filiale V1 ou V2 ?** — Le brief mentionne « 1 seule filiale aujourd'hui mais V1 prévoit le multi » et plusieurs invariants sont scoped `branch_id`. **`[H1]`** : V1 livre **mono-filiale en pratique** (1 row `branches`, branch_id = 1 défaut), mais **garde l'isolation `branch_id` partout** dans le code (jamais de query sans filtre). Le multi-filiale réel = V2. Justifie : si on assouplit `branch_id` maintenant, on devra refaire le travail plus tard, et c'est un invariant rouge.

2. **Bouton « Demo » V2 — UX exacte ?** — **`[H2]`** : route `/admin/demo/wizard-advanced/:itemId` (nom interne `admin.demo.composer`), accessible uniquement via flag `FEATURE_WIZARD_PER_ITEM_DEMO=true` (env), masquée du menu principal, badge `BETA · V2` rouge en haut, banner d'avertissement « Cette interface ne sera plus maintenue en V1. Toute personnalisation produit sera ignorée par le runtime. ». Le code Composer reste **techniquement actif** (pas de feature flag backend) mais le wizard catégorie **prime** au runtime (cf. §1.3).

3. **Granularité de la rupture d'ingrédient.** — **`[H3]`** : quand un ingrédient passe en rupture, **on ne masque PAS le produit entier** ; on masque uniquement **l'option correspondante dans le wizard runtime** (POS + Kiosk). Ex : « Saumon » en rupture filiale 1 → option « Saumon » grisée dans le step « Choisis ta viande » du Tacos M, mais le Tacos M reste commandable avec une autre viande. Si l'option en rupture est **obligatoire** (`min_select ≥ 1`) et qu'aucun choix alternatif n'existe, alors le produit devient implicitement indisponible (calculé runtime). Ne déclenche **pas** une mutation `item_branch_availability.is_available=false` automatique sur le produit parent.

4. **Migration des `item_wizard_profiles` per-item existants.** — **`[H4]`** : on **garde** les profiles actuels intacts en BDD. Au runtime POS/Kiosk, la résolution wizard suit l'ordre **catégorie → fallback per-item legacy → wizard vide**. Le mode « Demo V2 » lit toujours le profile per-item (back-compat). Aucune destruction de données, aucun backfill obligatoire.

Si l'une de ces hypothèses ne tient pas, **arrête-moi** avant le démarrage du Cycle 1.

---

# PARTIE 1 — AUDIT

## 1.1 Méthodologie & contexte Graphiti

**Sources lues** :
- Brief utilisateur intégral + `claude-extension-pivot-v1-2026-05-04/{README,LISTE-FICHIERS,MESSAGE-A-COLLER}.md`.
- Repo réel : 17 migrations Catalog/Stock/Wizard, modèles Item/ItemCategory/ItemWizardProfile/ItemWizardStep, services Composer/Stock/Menu, controllers Admin, sidebar `BackendMenuComponent.vue`, config `v1-hidden-modules.js`.
- Plans récents : `PLAN_CV1-V2-CATALOG-VISION-CLEANUP-001_2026-05-04.md`, `PLAN_CV1-V2-CATALOG-WIZARD-CORE-001_2026-05-04.md`, `PLAN_CV1-V1-CLOSEOUT-MASTER-2026-05-02.md`.
- Audit précédent : `reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` (mon livrable d'hier — verdict ESCALATE schema migration `published_steps_snapshot`, **résolu** par migration `2026_05_04_000010_create_item_wizard_step_versions_table.php`).
- Graphiti `foodking` : 4 queries (wizard simplification, ingredient unification, frozen zones NF525, version snapshot). Facts récupérés confirmant : (a) `OrderItemAllergenSnapshot` est utilisé en export NF525 (immuable post-paiement), (b) frozen zones validées : `app/Services/Pricing/`, `app/Services/Orders/`, `app/Services/Payments/`, `app/Services/FrontendOrderService.php`, (c) le concept « ingrédient » n'est pas dans le graph aujourd'hui — c'est un concept produit nouveau, pas un héritage technique.

**État réel au 2026-05-04** :
- Migrations wizard complètes (`item_wizard_profiles`, `item_wizard_steps`, `item_wizard_step_versions` créée aujourd'hui).
- `item_categories` a déjà 4 colonnes wizard depuis mars : `wizard_template` (enum string `simple|sandwich|tacos|assiette|snacking|menu|custom`), `has_menu`, `default_menu_kiosk`, `sauce_included_menu`. **Mais aucune FK vers `item_wizard_profiles`** — c'est un enum décoratif, pas un lien data.
- `v1-hidden-modules.js` cache déjà `settings.item-categories` et `settings.item-attributes` du menu admin — donc les concepts « Attributs » et gestion des catégories sont déjà semi-enterrés.
- Sidebar admin (`BackendMenuComponent.vue:86-92`) injecte des `VIRTUAL_CHILDREN_BY_URL` qui exposent Catalog Studio + une entrée « item_attributes » sous le top-link `items`.
- Composer wizard per-item est techniquement complet (CRUD + diff + version + drag&drop) mais **expérimentalement instable** (cycles `WIZARD-CORE-001` listent 5 problèmes UX résiduels).

## 1.2 Décisions par composant (`AUDIT TABLE`)

Convention : **GARDER** = utilisé V1 sans changement | **DÉSACTIVER** = code intact, route/menu cachés, V2-exposed | **ADAPTER** = refactor V1 nécessaire | **CRÉER** = nouveau code V1 | **SUPPRIMER** = à dégager.

### 1.2.a Couche données (BDD)

| Composant | Décision V1 | Justification + chemin |
|---|---|---|
| `items` (table) | **GARDER** | Aucun changement structure. Reste source produit. `database/migrations/2022_11_17_110514_create_items_table.php`. |
| `item_categories` (table) | **ADAPTER** | Ajouter colonne `wizard_profile_id` FK nullable (Voie A retenue, cf. §1.3). Conserver `wizard_template` enum existant pour back-compat / fallback UX. Migration : `2026_05_05_*_add_wizard_profile_id_to_item_categories.php`. |
| `item_wizard_profiles` (table) | **ADAPTER** | Rendre `item_id` **nullable**, ajouter `item_category_id` nullable + index, ajouter check « exactement un de (item_id, item_category_id) NOT NULL ». Permet au profile d'être attaché à une catégorie OU à un item (mode V2 demo). Migration : `2026_05_05_*_make_item_wizard_profiles_polymorphic_owner.php`. |
| `item_wizard_steps` (table) | **GARDER** | Aucun changement. Steps continuent à pointer `profile_id`. |
| `item_wizard_step_versions` (table) | **GARDER** | Snapshot publish — créé aujourd'hui (migration `2026_05_04_000010_*.php`), résout B3 audit hier. Sera utilisé dans le diff publish wizard catégorie. |
| `item_attributes` (table) | **ADAPTER** | Ajouter `is_available` boolean default true + `unavailable_reason` string nullable (rupture manuelle ingrédient). NB: existe déjà `min_select`, `max_select`, `allow_repeat` (migration `2026_04_22_*`). Migration : `2026_05_05_*_add_availability_to_item_attributes.php`. |
| `item_extras` (table) | **ADAPTER** | Idem `item_attributes` — ajouter `is_available` + `unavailable_reason`. Existing : `group_label`, `visible_on`. Migration : `2026_05_05_*_add_availability_to_item_extras.php`. |
| `item_addons` (table) | **GARDER** | Déjà liée à `addon_item_id → items` ; la dispo addon = dispo de l'item parent (cascade naturelle via `ChoiceAvailabilityResolver::availabilityForAddonItem`). Pas de colonne `is_available` propre nécessaire. |
| `item_branch_availability` (table) | **GARDER** | Reste source dispo per-item per-branch. Sera étendue conceptuellement (V1.5+) pour les ingrédients via une table sœur. Pas de migration V1 immédiate. |
| `stock_levels`, `stock_movements` (tables) | **GARDER** | Stock V2 schema solide, append-only, atomique. Inchangé V1. |

### 1.2.b Couche backend services

| Composant | Décision V1 | Justification + chemin |
|---|---|---|
| `app/Models/Item.php` | **GARDER** | Mapping inchangé. |
| `app/Models/ItemCategory.php` | **ADAPTER** | Ajouter relation `wizardProfile(): BelongsTo` (vers `ItemWizardProfile`). Ajouter accessor `getEffectiveWizardProfile()` qui retourne le profile catégorie ou null. |
| `app/Models/ItemWizardProfile.php` | **ADAPTER** | Ajouter `$fillable += ['item_category_id']`, `$casts += ['item_category_id' => 'integer']`, relation `category(): BelongsTo`, scope `scopeForCategory()`. Aucun champ supprimé. |
| `app/Models/ItemAttribute.php`, `ItemExtra.php` | **ADAPTER** | Ajouter `$fillable += ['is_available', 'unavailable_reason']`, `$casts += ['is_available' => 'boolean']`. Modèle ingrédient unifié vit côté service (cf. `IngredientAvailabilityService`). |
| `app/Services/Composer/ComposerProfileService.php` | **ADAPTER** | Ajouter méthode `createForCategory(ItemCategory $cat, array $payload): ItemWizardProfile` symétrique à `createForItem`. Ajouter `showForCategory(ItemCategory $cat): ?ItemWizardProfile`. La logique publish/unpublish/diff reste identique (orientée profile, agnostique de l'owner). |
| `app/Services/Composer/ComposerStepService.php` | **GARDER** | CRUD step inchangé. Le profile peut maintenant être catégorie ou item, le service ne s'en préoccupe pas. |
| `app/Services/Composer/ComposerProfileProjection.php` | **ADAPTER** | Modifier `project($profile, $item, $surface, $branchId)` pour prendre en compte la rupture ingrédient via le nouveau `IngredientAvailabilityService`. Pour chaque option projetée, si `attribute.is_available=false` ou `extra.is_available=false` → `is_available=false, unavailable_reason='ingredient_rupture'`. |
| `app/Services/Composer/ComposerTemplateService.php` | **GARDER** | 7 templates conservés. Utilisés à la fois par catégorie (V1) et item (V2 demo). |
| `app/Services/Composer/ComposerDiffService.php` | **GARDER** | Snapshot publish opère désormais sur `item_wizard_step_versions` (B3 résolu). Indépendant de l'owner du profile. |
| `app/Services/Stock/StockService.php` | **GARDER** | Décrément atomique inchangé. Frozen-adjacent (touché récemment), à ne pas modifier en V1. |
| `app/Services/Stock/ChoiceAvailabilityResolver.php` | **ADAPTER** | Étendre `availabilityFromLevel()` avec un check préalable `is_available` sur `ItemAttribute`/`ItemExtra` (rupture manuelle ingrédient). Order de précédence : `ingredient_rupture` (manuel) > `stock_rupture` (auto) > `stock_low` > `available`. |
| `app/Services/Menu/{MenuProjectionService,PosMenuProjection,AvailabilityService}.php` | **GARDER** | Pipeline projection branche → menu inchangé. |
| **NEW** `app/Services/Ingredients/IngredientService.php` | **CRÉER** | Service unifié qui agrège lecture sur `item_attributes` ∪ `item_extras` ∪ `item_addons.addonItem` (cf. §1.4 Option I.2). Méthodes : `listByType(?string $type)`, `listAll(int $branchId)`, `findByGlobalId(string $globalId)` (où `globalId = "${type}:${id}"`). |
| **NEW** `app/Services/Ingredients/IngredientAvailabilityService.php` | **CRÉER** | Service mutation : `toggle(string $type, int $id, bool $available, ?string $reason)` qui dispatch `IngredientAvailabilityChanged` (DispatchableAfterCommit). Wrapper sur les `update` `item_attributes` / `item_extras` selon `$type`. |
| **NEW** `app/Events/IngredientAvailabilityChanged.php` | **CRÉER** | Event DispatchableAfterCommit, payload `{type, id, branchId, isAvailable, reason}`. Listener invalide cache projection + broadcast `private-branch.{id}` channel pour POS/Kiosk runtime refresh. |
| `app/Http/Controllers/Admin/ComposerProfileController.php` | **ADAPTER** | Ajouter routes / méthodes `showForCategory`, `storeForCategory`, `applyTemplateToCategory`. Préserver les routes per-item existantes (V2 demo). |
| **NEW** `app/Http/Controllers/Admin/IngredientController.php` | **CRÉER** | CRUD/list/toggle. Méthodes : `index()`, `byType(string $type)`, `availability($type, $id, Request)` (toggle). Permission `ingredients_manage` (à créer). |
| `app/Http/Controllers/Admin/PosCategoryController.php` | **GARDER** | Liste catégories pour POS. Aucun changement V1. |

### 1.2.c Couche frontend admin (Vue)

| Composant | Décision V1 | Justification + chemin |
|---|---|---|
| `resources/js/components/admin/items/CatalogStudioComponent.vue` | **ADAPTER** | Devient la page « Catalogue ». Retrait du bouton « Configurer le wizard » par produit (déplacé à la catégorie). Ajout d'un bouton « Wizard de la catégorie » dans la sidebar catégories. Le drawer iframe composer (vu hier comme F1) est désormais ouvert au niveau catégorie, plus produit. |
| `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` | **ADAPTER** | Réutilisé tel quel pour éditer le wizard d'une catégorie. Renommer interne (header) : « Wizard de la catégorie : Tacos » au lieu de « Produit : Tacos M ». Endpoint API change : `/admin/composer/categories/{id}` au lieu de `/admin/composer/items/{id}`. |
| `resources/js/components/admin/items/composer/ComposerStepListSidebar.vue` | **GARDER** | Aucun changement, reçoit `steps` agnostique. |
| `resources/js/components/admin/items/composer/ComposerStepFormPanel.vue` | **GARDER** | Plan WIZARD-CORE-001 (5 problèmes UX) reste à terminer mais ne dépend pas du pivot. |
| `resources/js/components/admin/items/composer/ComposerTemplatePickerModal.vue` | **GARDER** | Sélection template applicable catégorie ou item. |
| `resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue` | **GARDER** | Diff utilise désormais `item_wizard_step_versions`. Logique inchangée. |
| `resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue` | **GARDER** | OK, agnostique de l'owner. |
| `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` | **ADAPTER** | Devient page « Stock » V1 unifiée. Ajouter section « Ingrédients en rupture » au-dessus de « Produits en rupture ». Connecter aux nouveaux endpoints `IngredientController`. |
| **NEW** `resources/js/components/admin/ingredients/IngredientListComponent.vue` | **CRÉER** | Page « Ingrédients ». Tabs/filtres par type (Viandes / Sauces / Suppléments / Crudités / Boissons). Toggle rupture rapide. Tableau avec : nom, type, dispo (toggle), produits qui le contiennent (count + drill-down). |
| **NEW** `resources/js/components/admin/ingredients/IngredientAvailabilityToggleComponent.vue` | **CRÉER** | Toggle réutilisable, optimistic update, rollback sur erreur, prompt « Pourquoi ? » si rupture activée. |
| **NEW** `resources/js/components/admin/ingredients/IngredientUsageDrawer.vue` | **CRÉER** | Drawer qui montre quels produits + catégories utilisent cet ingrédient (drill-down depuis la page liste). |
| `resources/js/components/admin/items/AvailabilityToggleComponent.vue` | **GARDER** | Toggle dispo produit, inchangé. |
| `resources/js/components/admin/items/ItemPreviewComponent.vue` | **GARDER** | Aperçu kiosk/POS. Plan WIZARD-CORE-001 prévoit déjà l'amélioration. |
| `resources/js/components/layouts/backend/BackendMenuComponent.vue` | **ADAPTER** | Réorganiser sidebar V1 : 4 entrées principales (Tableau de bord / Catalogue / Stock / Ingrédients) + section « Caisse & Commandes » + entrée bas de menu « Demo V2 (avancé) » conditionnée flag. Cacher tout le reste via `V1_HIDDEN_MENU_MODULES`. |
| `resources/js/config/v1-hidden-modules.js` | **ADAPTER** | Ajouter `'reports.*'`, `'subscribers'`, `'pushNotifications'`, `'messages'`, et tout ce qui est non listé dans les 4 piliers V1. Conserver l'export `V1_HIDDEN_MENU_MODULES` Object.freeze. |
| `resources/js/router/modules/itemRoutes.js` | **ADAPTER** | Ajouter route `/admin/categories/:id/wizard` (nom `admin.categories.wizard`). Conserver `/admin/items/:id/composer` MAIS la déplacer sous `/admin/demo/wizard-advanced/:itemId` (nom `admin.demo.composer`). |
| `resources/js/router/modules/stockRoutes.js` | **GARDER** | Route `/admin/stock/rupture` existante (créée par CV1-V2-CATALOG-VISION-CLEANUP-001). Pointe désormais vers la nouvelle vue Stock V1 unifiée. |
| **NEW** `resources/js/router/modules/ingredientRoutes.js` | **CRÉER** | Routes `/admin/ingredients`, `/admin/ingredients/:type`. Permission `ingredients_manage`. |
| Tests E2E `tests/e2e/catalog-studio-create-product-flow.spec.js` | **ADAPTER** | Mettre à jour pour ne plus cliquer « Configurer wizard » sur produit. Pivot vers création produit → vérification que le wizard catégorie est appliqué automatiquement. |
| **NEW** Tests E2E `tests/e2e/category-wizard-inheritance.spec.js` | **CRÉER** | Critical-flow : créer catégorie → appliquer template → créer produit dans cette catégorie → ouvrir kiosk → vérifier que le wizard est appliqué. |
| **NEW** Tests E2E `tests/e2e/ingredient-rupture-propagation.spec.js` | **CRÉER** | Critical-flow : passer ingrédient (« Saumon ») en rupture → vérifier dans kiosk que l'option « Saumon » est masquée pour le produit Tacos M dans la step viande. |

### 1.2.d Sidebar admin & nav (avant / après)

**Avant (état actuel post `CV1-V2-CATALOG-VISION-CLEANUP-001`)** :
- Tableau de Bord
- Catalogue (= Catalog Studio avec wizard per-item accessible)
- Stock (route /admin/stock/rupture vers StockRuptureDashboard)
- Caisse / Commandes / Cuisine / Écran statut
- Communications / Users / Reports / Setup
- (Catégories & Attributs déjà cachés via `v1-hidden-modules.js`)

**Après pivot V1** :
- Tableau de Bord
- **Catalogue** (gestion catégories & produits, sans wizard per-item)
- **Stock** (vue unifiée : produits + ingrédients en rupture)
- **Ingrédients** (nouveau — gestion centralisée des viandes/sauces/etc.)
- Caisse / Commandes / Cuisine / Écran statut (en bloc « Opérations »)
- Users / Setup minimal
- **Demo V2 (avancé)** ← bas de menu, badge BETA, accessible si flag

## 1.3 Question architecturale 1 — Voie A vs Voie B (wizard catégorie)

**Voie A** : nouvelle relation `item_wizard_profiles → item_category_id` (FK nullable). Au runtime, un produit hérite via `Item → ItemCategory → ItemWizardProfile`. Pas de duplication.

**Voie B** : à la création/modif produit, copier le wizard de la catégorie sur le produit (1 nouveau profile per-item). Duplication mais isolation per-produit.

| Critère | Voie A (FK catégorie) | Voie B (copy on attach) |
|---|---|---|
| Duplication data | Aucune (1 profile / catégorie) | Élevée (1 profile / produit) |
| Modif catégorie → propagation | Instantanée (1 UPDATE) | N UPDATE (un par produit + dispatch event) |
| Migration des wizards per-item existants | Aucune obligatoire (`item_id NOT NULL` → garde V2 demo) | Doit re-évaluer chaque profile |
| Complexité runtime | +1 jointure (Item.category.wizardProfile) | Aucune (Item.wizardProfile direct) |
| Retour V2 perso per-item | Trivial (le profile per-item existant ressort) | Trivial aussi |
| Risque incohérence diff | Faible (1 source de vérité par catégorie) | Moyen (drift entre profiles per-item d'une même catégorie) |
| Effort migration BDD | M (1 colonne FK + nullable item_id + check constraint) | M (backfill copies + script propagation) |
| Effort runtime POS/Kiosk | S (ajouter résolution catégorie dans `ComposerProfileService::resolveForItem`) | S (pas de changement runtime, juste data path) |

**Recommandation** : **Voie A** (P0).

Raisons :
1. **Aligne sur l'invariant produit** : « 1 catégorie = 1 wizard » est une propriété **de la catégorie**, pas du produit. Modéliser au niveau catégorie respecte la structure conceptuelle voulue par l'utilisateur.
2. **Modif/édit instantanée** : si tu changes le wizard « Tacos » à 19h, les 8 produits Tacos en BDD voient le changement immédiatement (un seul UPDATE catégorie). Voie B = N events `ComposerProfileChanged` à dispatcher → bruit Pusher + incohérence transitoire entre produits le temps que la propagation finisse.
3. **Symétrie avec back-compat V2** : la table `item_wizard_profiles` accepte alors **deux owners** (`item_id` ou `item_category_id`), exactement le même contrat que pour les `addons` ou `extras` polymorphics. C'est une extension cohérente du modèle.
4. **Voie B = plus de code à écrire** (copy logic + sync triggers + propagation events). Voie A = moins de code et moins de surface de bug.

**Migration concrète Voie A** (cycle 2 du plan) :
```
ALTER TABLE item_wizard_profiles
  ADD COLUMN item_category_id BIGINT UNSIGNED NULL AFTER item_id,
  ADD INDEX item_wizard_profiles_category_idx (item_category_id),
  ADD FOREIGN KEY (item_category_id) REFERENCES item_categories(id) ON DELETE CASCADE;

ALTER TABLE item_wizard_profiles
  MODIFY item_id BIGINT UNSIGNED NULL;

-- Check constraint (MySQL 8+ ou Postgres) :
ALTER TABLE item_wizard_profiles
  ADD CONSTRAINT item_wizard_profiles_owner_xor_check
  CHECK ((item_id IS NOT NULL) <> (item_category_id IS NOT NULL));
```

Gate humain DDL : **OUI** (Schema Migration). Ouvrir `docs/gates/GATE_CV1-V1-PIVOT-WIZARD-CATEGORY-OWNER_2026-05-04.md`.

## 1.4 Question architecturale 2 — Option I.1 / I.2 / I.3 (concept Ingrédient)

| Option | Description | Effort | Réversibilité | Risque V1 |
|---|---|---|---|---|
| **I.1** | Nouvelle table `ingredients` qui unifie viandes/sauces/etc. + migration consolidation | XL | Très faible (data migration BDD) | Élevé : risque casser data path POS/Kiosk runtime, ChoiceAvailabilityResolver, Composer projection |
| **I.2** | Vue agrégée backend sur `item_attributes` ∪ `item_extras` ∪ `item_addons.addonItem`, exposée par un service `IngredientService` + UI unifiée | M | Total (aucune migration BDD lourde) | Faible : code additif, zéro destruction |
| **I.3** | Refactor complet : tout devient `ingredients` avec un `type`, suppression progressive des 3 tables | XXL | Faible | Énorme : touche la projection, tests, fixtures, runtime POS/Kiosk |

**Recommandation** : **I.2** (P0).

Raisons :
1. **Pragmatisme V1 = priorité du brief**. Le restaurateur veut « livrer vite », pas une refonte data lourde. I.2 livre la **promesse UX** (un seul endroit pour gérer ingrédients) sans refondre la BDD.
2. **Zéro destruction** : `item_attributes`, `item_extras`, `item_addons` restent en l'état. La projection `ComposerProfileProjection::choices()` continue à les lire selon `source_type`. C'est ainsi qu'on respecte l'invariant **« ne pas casser le flux de commande existant »** (contrainte 1 du brief).
3. **Réversibilité totale** : si en V2 on découvre que I.1 ou I.3 est meilleur, on peut migrer plus tard sans rien jeter. I.2 est un **adapter** par-dessus l'existant.
4. **I.3 prendrait 3-4 semaines** et casserait un nombre indéterminé de tests E2E POS/Kiosk. Inacceptable pour un objectif « V1 vite ».

**Implémentation I.2** :
- `IngredientService::listByType($type)` retourne un tableau d'`Ingredient` (DTO PHP, pas Eloquent) avec `{global_id, type, name, is_available, unavailable_reason, used_by_count}`.
- `global_id` = string du type `"attribute:42"` ou `"extra:128"` ou `"addon:7"` — clé stable cross-type.
- Sur le frontend, l'admin voit une seule grille filtrable. Le toggle dispo appelle `PUT /api/admin/ingredients/{globalId}/availability` qui route vers le bon service interne (`ItemAttributeService::toggle` ou `ItemExtraService::toggle`).
- Pour la propagation rupture → wizard runtime : ajouter colonne `is_available` à `item_attributes` et `item_extras` (migrations légères, default true). `ChoiceAvailabilityResolver` lit ces colonnes en plus du stock.

**NB sur les addons** : `item_addons.addon_item_id → items.id`. La rupture d'un addon = rupture de l'item parent (déjà géré par `ItemBranchAvailability`). Donc « ingrédient » de type `addon` réutilise le mécanisme produit existant. **Pas de colonne supplémentaire** côté `item_addons`.

---

# PARTIE 2 — PLAN D'EXÉCUTION

## 2.1 Vue d'ensemble — 8 cycles bornés

```
Cycle 0 (PRÉ) : Décisions humaines + gates ouverts
   ↓
Cycle 1 : Foundations BDD wizard catégorie + ingrédients (migrations + modèles)
   ↓
Cycle 2 : Backend services Composer category-aware + IngredientService
   ↓
Cycle 3 : Adapter ChoiceAvailabilityResolver + IngredientAvailabilityChanged event
   ↓
Cycle 4 : Frontend page Ingrédients (liste + toggle + drawer usage)
   ↓
Cycle 5 : Frontend Catalog Studio refonte (wizard catégorie au lieu de per-item)
   ↓
Cycle 6 : Sidebar V1 + masquage routes + page Demo V2
   ↓
Cycle 7 : Tests E2E nouveaux + adaptation existants
   ↓
Cycle 8 (POST) : Audit final + RUN report + cycle close
```

Effort agrégé : ~3 sprints calendaires (3 semaines à 5 j/sem si une seule personne ; 1.5-2 semaines avec 2 implémenteurs en parallèle où la dépendance le permet).

## 2.2 Détail cycle par cycle

### Cycle 0 — Décisions humaines + gates (avant tout code)

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-PRE-DECISIONS-001` |
| Owner | Humain (utilisateur) |
| Effort | S (1-2 h) |
| Sortants | 4 hypothèses tranchées (`[H1]`-`[H4]` § Clarifications) + gate brief schema migration ouvert |
| Bloque | Tous les cycles suivants |

Action : valider les 4 hypothèses, ouvrir `docs/gates/GATE_CV1-V1-PIVOT-WIZARD-CATEGORY-OWNER_2026-05-04.md` avec décision DDL approuvée.

### Cycle 1 — Foundations BDD wizard catégorie + ingrédients

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-FOUNDATIONS-001` |
| Description | 4 migrations + 3 modèles adaptés. Aucun changement runtime. |
| `SUBSYSTEMS_TOUCHED` | `database/migrations/2026_05_05_*` (4 nouvelles), `app/Models/{ItemCategory,ItemWizardProfile,ItemAttribute,ItemExtra}.php` |
| `INVARIANTS_AT_RISK` | Aucun (data-only, schema additif). I3 `branch_id` non touché car les colonnes ajoutées sont à scope catalogue global. |
| Dépendances | Cycle 0 (gates cleared) |
| Gates | **Schema Migration cleared** (Cycle 0). Pas d'autre. |
| Effort | M (4-6 h) |
| Tests | PHPUnit migrations smoke (`tests/Unit/Migrations/V1PivotMigrationsTest.php`) + factories adaptées. Aucun test E2E. |
| DoD | `php artisan migrate` PASS, factories `ItemWizardProfile::factory()->forCategory(ItemCategory $cat)` opérationnelle, vitest + PHPUnit verts (zéro régression). |

### Cycle 2 — Backend services Composer category-aware + IngredientService

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-BACKEND-CATEGORY-WIZARD-001` |
| Description | Adapter `ComposerProfileService` pour supporter owner = catégorie. Créer `IngredientService` agrégeant attributes/extras/addons. Endpoint `IngredientController`. |
| `SUBSYSTEMS_TOUCHED` | `app/Services/Composer/ComposerProfileService.php` (+`createForCategory`, `showForCategory`, `applyTemplateToCategory`), `app/Services/Composer/ComposerProfileProjection.php` (résolution catégorie), `app/Services/Ingredients/IngredientService.php` (NEW), `app/Http/Controllers/Admin/{ComposerProfileController,IngredientController}.php`, `routes/api.php` (nouvelles routes), `app/Http/Resources/IngredientResource.php` (NEW) |
| `INVARIANTS_AT_RISK` | I1 pricing SSOT (vérifier qu'aucun calcul prix ne fuit), I3 `branch_id` (filtre IBA dans IngredientService::listAll($branchId)), I4 dispatch after commit (events catégorie wizard). |
| Dépendances | Cycle 1 |
| Gates | Aucun (additif). Confirmer que `app/Services/Pricing/`, `app/Services/Orders/`, `app/Services/FrontendOrderService.php` ne sont **pas** touchés (frozen zone). |
| Effort | L (1-2 j) |
| Tests | PHPUnit `tests/Feature/Composer/ComposerProfileServiceCategoryTest.php` (createForCategory, applyTemplate, resolveForItem fallback), `tests/Feature/Ingredients/IngredientServiceListTest.php`, `tests/Feature/Ingredients/IngredientControllerToggleTest.php`, smoke contract `tests/Feature/Composer/ProjectionCategoryFallbackTest.php`. |
| DoD | API `GET /api/admin/categories/{id}/wizard` retourne le profile catégorie + steps. `GET /api/admin/ingredients` retourne grille unifiée avec dispo. Vitest + PHPUnit verts. |

### Cycle 3 — Adapter ChoiceAvailabilityResolver + IngredientAvailabilityChanged event

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-RUPTURE-PROPAGATION-001` |
| Description | Étendre `ChoiceAvailabilityResolver` pour lire `is_available` sur ItemAttribute / ItemExtra. Créer event `IngredientAvailabilityChanged` (DispatchableAfterCommit). Listener invalide cache projection + broadcast. |
| `SUBSYSTEMS_TOUCHED` | `app/Services/Stock/ChoiceAvailabilityResolver.php`, `app/Services/Composer/ComposerProfileProjection.php` (réceptive aux nouveaux signals), `app/Events/IngredientAvailabilityChanged.php` (NEW), `app/Listeners/InvalidateMenuProjectionOnIngredientChange.php` (NEW), `app/Services/Ingredients/IngredientAvailabilityService.php` (NEW). |
| `INVARIANTS_AT_RISK` | **I4 dispatch after commit critique** : `IngredientAvailabilityChanged` doit utiliser `DispatchableAfterCommit` trait obligatoirement. I3 `branch_id` (broadcast ciblé). |
| Dépendances | Cycle 2 |
| Gates | Aucun (additif événementiel). |
| Effort | M (4-8 h) |
| Tests | PHPUnit `tests/Feature/Ingredients/IngredientAvailabilityChangedTest.php` (after-commit fired only on commit, payload correct, listener invalide cache), `tests/Feature/Stock/ChoiceAvailabilityResolverIngredientRuptureTest.php` (resolver retourne `unavailable_reason='ingredient_rupture'` quand attribute.is_available=false). |
| DoD | Toggle ingrédient via API → wizard runtime (POS/Kiosk) reçoit le signal en <2 s via Pusher. Sentinelle confirmant après commit only. |

### Cycle 4 — Frontend page Ingrédients

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-INGREDIENTS-UI-001` |
| Description | Page Vue admin Ingrédients : liste, filtres par type, toggle rupture, drawer usage. |
| `SUBSYSTEMS_TOUCHED` | `resources/js/components/admin/ingredients/{IngredientListComponent,IngredientAvailabilityToggleComponent,IngredientUsageDrawer}.vue` (NEW), `resources/js/router/modules/ingredientRoutes.js` (NEW), `resources/js/store/modules/ingredients.js` (NEW Vuex module), `resources/js/services/ingredientService.js` (NEW Axios wrapper), `resources/js/languages/{fr,en,de,bn,ar}.json` (clés `ingredient.*`). |
| `INVARIANTS_AT_RISK` | I1 pricing SSOT (zéro calcul prix dans la grille — uniquement affichage backend). |
| Dépendances | Cycle 2 (API endpoints), Cycle 3 (rupture propagation visible dans le drawer usage) |
| Gates | Aucun. |
| Effort | L (1-2 j) |
| Tests | Vitest `tests/js/ingredientListComponent.spec.js`, `ingredientToggleOptimistic.spec.js`, `ingredientUsageDrawer.spec.js`, i18n parity sentinelle adaptée. |
| DoD | Page `/admin/ingredients` ouvre, filtre par type fonctionne, toggle rupture optimiste avec rollback, drawer usage liste les produits/catégories impactés. |

### Cycle 5 — Frontend Catalog Studio refonte (wizard catégorie au lieu de per-item)

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-CATALOG-STUDIO-CATEGORY-WIZARD-001` |
| Description | Le bouton « Configurer le wizard » par produit disparaît. Nouveau bouton « Wizard de la catégorie » dans la sidebar catégories. Drawer composer ouvre désormais le wizard catégorie. |
| `SUBSYSTEMS_TOUCHED` | `resources/js/components/admin/items/CatalogStudioComponent.vue`, `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` (header + endpoints API), `resources/js/router/modules/itemRoutes.js` (ajout route catégorie). |
| `INVARIANTS_AT_RISK` | I1 pricing SSOT (vérifier inchangé), F1 anti-pattern iframe (déjà documenté hier, conservé V1). |
| Dépendances | Cycle 2 |
| Gates | Aucun. |
| Effort | M (4-8 h) |
| Tests | Vitest `tests/js/catalogStudioCategoryWizardEntry.spec.js`, `categoryComposerEditorContract.spec.js`, MAJ `catalogStudioRouting.spec.js`. |
| DoD | Bouton « Configurer wizard » par produit non visible dans le DOM. Bouton « Wizard catégorie » fonctionnel sur chaque catégorie. Drawer iframe charge `/admin/categories/{id}/composer`. |

### Cycle 6 — Sidebar V1 + masquage routes + page Demo V2

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-SIDEBAR-DEMO-V2-001` |
| Description | Réorganiser sidebar admin V1 (4 piliers + Opérations + Demo V2 conditionnel). Ajouter route Demo V2 + flag env. Masquer reports/messages/etc. |
| `SUBSYSTEMS_TOUCHED` | `resources/js/components/layouts/backend/BackendMenuComponent.vue`, `resources/js/config/v1-hidden-modules.js`, `resources/js/router/modules/itemRoutes.js` (route `admin.demo.composer`), `.env.example` (flag `FEATURE_WIZARD_PER_ITEM_DEMO=false`). |
| `INVARIANTS_AT_RISK` | Aucun (UX). |
| Dépendances | Cycle 5 |
| Gates | **UX manuel** : screenshot avant/après sidebar à valider humain avant merge. |
| Effort | S (3-4 h) |
| Tests | Vitest `tests/js/v1SidebarLayout.spec.js`, `v1HiddenMenuModules.spec.js` (MAJ), `demoV2RouteVisibility.spec.js`. |
| DoD | Sidebar admin V1 affiche **exactement** 4 piliers principaux + section Opérations. Demo V2 invisible si flag OFF, accessible si flag ON. |

### Cycle 7 — Tests E2E nouveaux + adaptation existants

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-E2E-VERIFICATION-001` |
| Description | Playwright critical-flows nouveaux + adaptation des existants. |
| `SUBSYSTEMS_TOUCHED` | `tests/e2e/category-wizard-inheritance.spec.js` (NEW), `tests/e2e/ingredient-rupture-propagation.spec.js` (NEW), `tests/e2e/admin-sidebar-v1-pillars.spec.js` (NEW), `tests/e2e/catalog-studio-create-product-flow.spec.js` (UPDATE). |
| `INVARIANTS_AT_RISK` | I3 `branch_id` (les E2E doivent valider l'isolation), I4 dispatch after commit (event timing). |
| Dépendances | Cycles 1-6 |
| Gates | Aucun (tests). |
| Effort | M (1 j) |
| Tests | C'est ce cycle. |
| DoD | 3 nouveaux specs Playwright PASS. `catalog-studio-create-product-flow.spec.js` PASS adapté (sans le clic « configurer wizard »). Vitest + PHPUnit + Playwright **tous verts**. |

### Cycle 8 — Audit final + RUN report + cycle close

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V1-PIVOT-CLOSEOUT-001` |
| Description | RUN report final, MAJ Graphiti, cycle close. |
| `SUBSYSTEMS_TOUCHED` | `reports/execution/RUN_CV1-V1-PIVOT-CLOSEOUT_2026-05-XX.md`, `.cursor/ACTIVE_CYCLE.md`, MAJ `MEMORY.md` user. |
| `INVARIANTS_AT_RISK` | Aucun. |
| Dépendances | Cycle 7 |
| Gates | Audit Claude (ce livrable) → AUDIT phase post-execution si l'utilisateur souhaite. |
| Effort | S (2 h) |
| Tests | N/A. |
| DoD | RUN report écrit, ACTIVE_CYCLE basculé, Graphiti facts ajoutés (5 facts proposés). |

## 2.3 Gates humains obligatoires

| Gate | Type | Cycle | Justification |
|---|---|---|---|
| `GATE_CV1-V1-PIVOT-WIZARD-CATEGORY-OWNER` | **Schema Migration** (DDL) | Cycle 0 → bloque Cycle 1 | Ajout FK `item_category_id` à `item_wizard_profiles`, modification nullable `item_id`, check constraint XOR. |
| `GATE_CV1-V1-PIVOT-INGREDIENT-AVAILABILITY-COLUMNS` | **Schema Migration** (DDL) | Cycle 0 → bloque Cycle 1 | Ajout colonnes `is_available` + `unavailable_reason` sur `item_attributes` et `item_extras`. |
| Sidebar UX validation | **UX manuel** | Cycle 6 | Screenshot avant/après à valider visuellement. |
| Demo V2 flag activation prod | **Ops** (post-V1) | post-V1 | Décision humaine ultérieure : activer ou laisser OFF en prod. |

**Aucun gate frozen zone**, **aucun gate `branch_id`** (le pivot ne touche pas l'isolation), **aucun gate auth** (Spatie permission `ingredients_manage` à créer mais pas de modif `LoginController`).

## 2.4 Stratégie globale Definition of Done

À chaque cycle :
1. **Vitest + PHPUnit verts** (zéro régression baseline cycle précédent).
2. **Playwright critical-flows verts** au cycle 7+.
3. **Aucun invariant FoodKing violé** (audit interne via grep des frozen zones, vérification `DispatchableAfterCommit` sur events nouveaux, scope `branch_id` sur queries multi-tenant).
4. **Migrations rollback testées** (`php artisan migrate:rollback` puis re-`migrate` retourne au même état).
5. **i18n parity** : toute clé Vue ajoutée existe dans les 5 locales (fr, en, de, bn, ar).
6. **Pas de feature flag bloquant** : V1 doit fonctionner avec **valeurs par défaut** des configs (le flag Demo V2 est OFF par défaut, donc le menu V1 est ce que verra le restaurateur).

---

# PARTIE 3 — ULTRA REVIEW (auto-challenge)

## 3.a Risques techniques majeurs

### R-T1 — Compatibilité descendante avec les wizards per-item existants en BDD `[P1]`

**Scénario** : la BDD prod a déjà N rows dans `item_wizard_profiles` avec `item_id` non-null (créés Phase α-β). Quand on rend `item_id` nullable et qu'on ajoute la check constraint XOR « exactement un de (item_id, item_category_id) NOT NULL », les rows existantes (`item_id NOT NULL, item_category_id NULL`) **passent** la contrainte naturellement. ✅

**Mais** : au runtime, quel profile est résolu pour le produit X catégorie Y ?
- Si `category_Y.wizardProfile` existe → on l'utilise (V1 desired behavior).
- Sinon, fallback `item_X.wizardProfile` per-item legacy.
- Sinon, wizard vide (produit affiche en POS/Kiosk sans étapes, vente directe).

**Risque** : un restaurateur ayant déjà personnalisé le wizard du Tacos M vs Tacos L individuellement va voir **un wizard uniforme** une fois la catégorie wizard configurée. C'est **voulu** par le pivot, mais **silencieusement déstabilisant** si non communiqué.

**Mitigation** :
- Lors de la création/édition d'un wizard catégorie, **avertir** : « Cette opération va remplacer les wizards personnalisés de N produits dans cette catégorie. Continuer ? » (avec liste des produits impactés).
- Préserver l'accès Demo V2 pour réviser/restaurer les profiles per-item.
- Documenter dans `docs/V1_PIVOT_RELEASE_NOTES.md`.

### R-T2 — `IngredientAvailabilityChanged` event ne se propage pas vers POS pos-wizard.js (vanilla JS) `[P1]`

**Scénario** : POS runtime = `public/js/pos-wizard.js` vanilla JS qui consomme `composer_profile.steps` payload depuis backend (cf. audit hier R1). Quand un ingrédient passe en rupture, on broadcast `IngredientAvailabilityChanged`. Mais pos-wizard.js écoute aujourd'hui uniquement `CatalogChanged` et `ItemAvailabilityChanged` via `useCatalogChangeNotifier` (Vue 3 composable).

**Le runtime POS ne va pas recevoir le signal de rupture ingrédient.**

**Mitigation** :
- Le listener `InvalidateMenuProjectionOnIngredientChange` doit aussi dispatcher un **event sœur** `CatalogChanged` (déjà écouté par POS) pour forcer le re-fetch menu. C'est un coût Pusher légèrement plus élevé mais garantit la propagation.
- **Alternative** : modifier pos-wizard.js pour écouter directement `IngredientAvailabilityChanged`. Cela touche un fichier semi-frozen (refactor récent). Gate non requis si refactor mineur (juste ajouter un nouvel event listener). À évaluer en Cycle 3.

### R-T3 — Cache catalog projection invalide partiellement `[P2]`

**Scénario** : `MenuProjectionService` cache `kiosk.menu.branch.{id}` (CV1 doc §2). Un toggle ingrédient invalide ce cache. Mais le cache a-t-il une granularité par branche ou par item ?

**Vérification** : aujourd'hui, l'invalidation est branche-scoped. Donc tous les items de la branche sont re-projetés. Acceptable mais pas optimal.

**Mitigation** : ajouter un cache key invalidation `kiosk.menu.branch.{id}.ingredients` que `IngredientAvailabilityService` cible précisément. V2 candidate, pas V1 obligatoire.

### R-T4 — Migration check constraint XOR pas supportée sur SQLite `[P2]`

**Scénario** : tests CI utilisent SQLite (RefreshDatabase + factories). Les check constraints SQL sont émulées. Le pattern conditionnel `if (DB::getDriverName() !== 'sqlite')` (déjà utilisé dans `2026_04_27_143110_create_item_wizard_steps_table.php:33-36`) doit être appliqué à notre migration.

**Mitigation** : copier le pattern existant. Tests SQLite passent. Tests staging Postgres/MySQL appliquent la contrainte. Sentinel : `tests/Feature/Migrations/WizardProfileOwnerXorTest.php` valide que côté Eloquent on ne peut pas créer un profile avec ni l'un ni l'autre.

### R-T5 — Tests Vitest existants cassent (composer per-item entry points) `[P2]`

**Scénario** : `tests/js/composerEditorV2.spec.js`, `tests/js/composerProductDrawerEntry.spec.js` (existants) testent l'ouverture du composer DEPUIS un produit. Avec le pivot, l'entry point déménage côté catégorie.

**Mitigation** : Cycle 7 explicitement adapte ces specs. Si la couverture composer per-item reste utile pour Demo V2, dupliquer les specs sous `tests/js/demo/` plutôt que les remplacer. Préserve la valeur de test sans bloquer le pivot.

### R-T6 — Permission Spatie `ingredients_manage` à créer côté backend `[P2]`

**Scénario** : `IngredientController` middleware `permission:ingredients_manage` qui n'existe pas en BDD aujourd'hui.

**Mitigation** : Cycle 2 inclut un seeder Spatie `IngredientPermissionSeeder` qui crée la permission + la rattache au rôle `Admin` et `Manager`. Pas de gate humain requis (seeders sont code, pas DDL data critique). **NB** : aligner ce nom avec la matrice design Claude v2 si elle est utilisée (cf. mon audit hier D6 — la matrice design utilisait des noms snake_case incompatibles, à régler à part).

## 3.b Risques produit / UX

### R-P1 — Le restaurateur va-t-il regretter d'avoir perdu la perso per-item ? `[P1]`

**Question franche** : aujourd'hui le code permet de personnaliser le wizard du Tacos M différemment du Tacos L. Demain, V1 = uniforme par catégorie. Si dans 3 mois le restaurateur veut « pas de sauce sur le Tacos Diet », il va devoir :
- Soit créer une nouvelle catégorie « Tacos Diet » (overhead).
- Soit basculer en mode Demo V2 (réversible mais hors flux principal).

**Mitigation** :
- Prévoir une « porte de sortie » UX dans le drawer wizard catégorie : « Une variante particulière ? Active le mode avancé pour ce produit ». Un seul clic vers Demo V2 pour ce produit. Ce chemin existe déjà (route `admin.demo.composer`), il s'agit juste de l'exposer contextuellement.
- Documenter la décision en `docs/PROJECT_CONTINUITY_AND_VISION.md` (« Pourquoi la perso per-item est en V2 »).

### R-P2 — Le concept « Ingrédient » est-il vraiment intuitif ? `[P1]`

**Question** : un admin sait-il qu'un ingrédient « Saumon » existe dans 5 wizards de catégories différentes (Tacos, Sandwich, Salade…) sans drill-down ?

**Mitigation** :
- Le drawer `IngredientUsageDrawer.vue` (Cycle 4) liste explicitement les catégories + produits impactés. Affichage instantané au clic.
- Sur la page Ingrédients, ajouter une colonne « Utilisé dans » qui affiche `5 catégories · 12 produits` cliquables.
- Sur la modal de toggle rupture, **avant** de confirmer, afficher : « Marquer Saumon en rupture va masquer cette option dans 5 wizards (12 produits affectés). Continuer ? ».

### R-P3 — Le bouton « Demo V2 » doit-il vraiment être un bouton, ou complètement caché ? `[P2]`

**Hypothèse `[H2]`** disait : visible bas de menu + flag env. Mais peut-être trop visible ? Risque qu'un restaurateur curieux clique, configure un wizard per-item, et soit confus parce que **le runtime ne le respecte pas** (le wizard catégorie prime).

**Mitigation alternative** : ne pas exposer Demo V2 dans le menu du tout. Accès uniquement par URL directe `/admin/demo/wizard-advanced/:itemId`. Le développeur ou le power-user qui en a besoin connaît l'URL. Limite drastiquement le risque de confusion. **À trancher humainement Cycle 0.**

### R-P4 — Le « 3-4 boutons principaux maximum » est-il tenable ? `[P2]`

Le brief liste : Stock + Catalogue + Ingrédients + Demo V2 = 4. Mais le restaurateur va aussi avoir besoin de Caisse (POS), Commandes, Cuisine, Écran statut, Tableau de bord. Cela fait 9 entrées au minimum.

**Mitigation** : regrouper sous 2 sections macro : « Gestion » (Tableau / Catalogue / Stock / Ingrédients) et « Opérations » (POS / Commandes / Cuisine / Écran). Le brief « 3-4 boutons » concerne probablement la **Gestion**, pas l'opération en cuisine/caisse. À reformuler avec l'utilisateur pour éviter malentendu.

## 3.c Risques de planning

### R-PL1 — Le pivot V1 est-il vraiment plus rapide à livrer que de finir le wizard per-item ? `[P0]`

**Calcul honnête** :
- **Finir le wizard per-item** (état actuel) : terminer les 5 problèmes UX du `WIZARD-CORE-001` (1 cycle, 1-2 jours), ajouter critical-flow E2E manquant, livrer.
- **Pivot V1** : 8 cycles, ~3 sprints estimés.

**Le pivot est PLUS LONG** que de finir l'existant. Le bénéfice du pivot :
- Simplification UX restaurateur (moins de complexité visible).
- Concept Ingrédient nouveau (vraie valeur produit).
- Infrastructure pour V2 (per-item demo conservé).

**Tradeoff honnête** : si la priorité absolue est « livrer une V1 fonctionnelle dans 1 semaine », **finir le wizard per-item + cacher Demo V2 par flag** est plus rapide. Le concept Ingrédient peut venir en V1.5.

**Recommandation** : valider avec l'utilisateur si « rapide » signifie 1 semaine ou 1 mois. Si 1 semaine : version réduite (Cycles 0+1+5+6+7 seulement, ~5 jours, sans Ingrédients). Si 1 mois : plan complet 8 cycles.

### R-PL2 — Les cycles 4-5 ont des dépendances bloquantes `[P1]`

Cycle 4 (UI Ingrédients) dépend de Cycle 2 (API). Cycle 5 (Catalog Studio refonte) dépend de Cycle 2 (API catégorie). Donc Cycle 2 est un goulot.

**Mitigation** : paralléliser Cycle 2 entre 2 implémenteurs (Composer category-aware d'un côté, IngredientService de l'autre). Réduit le chemin critique de ~2 jours.

### R-PL3 — Les tests E2E (Cycle 7) sont historiquement les premiers à céder `[P2]`

L'audit hier notait : Playwright a pivoté pour utiliser un produit existant car la création produit échoue silencieusement (validation backend tax/branch). Si le seed E2E n'est pas robuste, les nouveaux specs Cycle 7 vont tomber.

**Mitigation** : Cycle 7 commence par auditer le seed E2E (`database/seeders/E2E*Seeder.php`) et garantir tax/branch défaut. Sinon les critical-flows vont skip silencieusement.

## 3.d Invariants FoodKing à risque

### Branch isolation (I3) — `[P0]`

**Risque** : si l'ingrédient « Saumon » est en rupture filiale 1 mais OK filiale 2, le toggle doit être **per-branch**. Aujourd'hui, mon design ajoute `is_available` directement sur `item_attributes` (table globale, mono-marque). C'est **incompatible** avec multi-filiale.

**Correction nécessaire** : la rupture ingrédient per-branch doit vivre dans une table sœur **`ingredient_branch_availability(stockable_type, stockable_id, branch_id, is_available, unavailable_reason)`** (similaire à `item_branch_availability`). Le `is_available` global sur `item_attributes`/`item_extras` reste utile pour la rupture **globale** (l'ingrédient est en rupture sur **toutes** les filiales).

**Impact plan** :
- Cycle 1 : ajouter une 5e migration `2026_05_05_*_create_ingredient_branch_availability_table.php` polymorphic.
- Cycle 2 : `IngredientService` doit lire les deux niveaux (global + per-branch).
- Cycle 3 : `IngredientAvailabilityService::toggle()` accepte un paramètre `branchId` optionnel (null = global).
- Cycle 4 : UI ajoute un sélecteur de filiale sur le toggle.

**À trancher Cycle 0** avec hypothèse `[H1]` : si V1 = mono-filiale en pratique, le `branch_id` peut être hardcodé à 1 dans les seeds mais **le code doit déjà supporter le cas générique**. Coût supplémentaire : ~30% de scope sur Cycles 1-4. **Significatif.**

### Dispatch after commit (I4) — `[P0]`

`IngredientAvailabilityChanged` event nouveau **doit obligatoirement** utiliser le trait `App\Events\Concerns\DispatchableAfterCommit` (pattern confirmé sur `ComposerProfileChanged.php:5,12`). Sentinelle PHPUnit Cycle 3 : `IngredientAvailabilityChangedAfterCommitTest::test_event_not_fired_in_open_transaction()`.

### Pricing SSOT (I1) — `[P1]`

**Aucune logique prix ne doit être ajoutée frontend.** Les colonnes `is_available` n'affectent pas les prix, mais l'UI Ingrédients ne doit afficher aucun calcul (ni « impact prix moyen », ni « manque à gagner estimé »). Si un dashboard analytics est demandé V1.5, faire venir les valeurs du backend exclusivement.

### OrderService / FrontendOrderService symmetry (I5) — `[P0]`

**Les deux services sont dans la frozen zone confirmée Graphiti.** Aucun cycle de ce plan ne les touche. Si un développeur tente de modifier `ChoiceAvailabilityResolver` d'une manière qui change la signature consommée par `OrderService::makeOrder` ou `FrontendOrderService::myOrderStore`, **stop** et ouvrir gate Frozen Zone.

### Frozen zones (I6) — `[P0]`

Frozen confirmées : `app/Services/Pricing/`, `app/Services/Orders/`, `app/Services/Payments/`, `app/Services/FrontendOrderService.php`, snapshots NF525 (`OrderItemAllergenSnapshot`). Ce plan **ne touche aucune** de ces zones. Si un cycle dérive vers ces zones (ex. Cycle 3 voudrait modifier la signature `StockService::decrementForOrder` consommée par OrderService), STOP + gate humain.

### OrderStatus enum (I2) — `[P2]`

Hors scope direct. Aucun cycle ne touche au lifecycle commande. Vérifier en Cycle 8 audit final : `grep -r "'pending'\|'preparing'\|…" resources/js/components/admin/{ingredients,items}/` retourne 0.

## 3.e Recommandations finales

### Top 3 décisions humaines à valider AVANT de commencer (Cycle 0)

1. **Multi-filiale en V1 ou V2 ?** — Si V2 (recommandé pragmatiquement), j'écris le code pour 1 filiale mais avec scoping `branch_id` en place. Si V1 multi (ambitieux), Cycle 1 ajoute une 5e migration `ingredient_branch_availability` et Cycles 2-4 augmentent leur scope ~30%. **Tranche à Cycle 0.**

2. **Bouton Demo V2 visible ou invisible ?** — `[H2]` propose visible bas de menu + flag env. Alternative : invisible (URL directe seulement). La 2e est plus sûre UX (zéro confusion restaurateur) mais moins découvrable. **Tranche à Cycle 0.**

3. **Migration des wizards per-item existants** — `[H4]` propose : on garde, le runtime privilégie le wizard catégorie. Alternative : on les détruit explicitement (`UPDATE item_wizard_profiles SET is_published = false WHERE item_id IS NOT NULL` au moment du pivot). La 1ère préserve la possibilité de retour Demo V2 ; la 2e est plus propre conceptuellement. **Tranche à Cycle 0.**

### Top 3 raccourcis à NE PAS prendre

1. **Ne PAS supprimer le code Composer per-item** — même s'il est « caché derrière Demo V2 », il représente ~3 mois de cycles α-β + tests + migrations. Le préserver coûte ~0 (un flag, un masquage menu). Le supprimer est **irréversible**.

2. **Ne PAS skipper le gate Schema Migration** — la check constraint XOR `(item_id, item_category_id)` doit être validée DDL avant Cycle 1. Si on la skippe « par pragmatisme V1 », on ouvre la porte à des rows incohérentes en BDD prod (un profile sans owner OU avec deux owners simultanés) qui crashent silencieusement la projection runtime.

3. **Ne PAS tenter Option I.1 ou I.3 (refactor Ingrédient lourd) en V1** — ce serait reprendre 3-4 semaines pour un bénéfice nul côté UX restaurateur. I.2 (vue agrégée backend) livre le même UX en 1-2 jours. Le refactor data peut venir V2 sans rien casser de V1.

### Definition of Done V1 (à valider AVANT Cycle 0)

- [ ] Sidebar admin V1 : 4 piliers Gestion (Dashboard / Catalogue / Stock / Ingrédients) + section Opérations (POS / Commandes / Cuisine / OSS).
- [ ] Le restaurateur peut, en 5 clics depuis la page Catalogue : créer une catégorie, lui appliquer un template wizard (Tacos), créer un produit dans cette catégorie. Le produit hérite automatiquement du wizard catégorie. **Aucun bouton « Configurer wizard » par produit visible.**
- [ ] Page Ingrédients : liste filtrée par type (Viandes / Sauces / Suppléments / Crudités / Boissons-éléments), toggle rupture par ingrédient, drawer usage drill-down.
- [ ] Toggle rupture sur « Saumon » → option « Saumon » masquée en moins de 5 secondes dans le wizard runtime POS et Kiosk pour la branche concernée.
- [ ] Page Stock : vue unifiée produits + ingrédients en rupture (avec tabs).
- [ ] Bouton Demo V2 : invisible si flag OFF, accessible avec banner BETA si flag ON. Le wizard catégorie prime au runtime, même si un wizard per-item existe.
- [ ] **Aucune régression** : Vitest 1054+ passing, PHPUnit Composer + Items + Stock + Ingredients tous verts, Playwright critical-flows verts (4 nouveaux + adaptation existant).
- [ ] **Invariants 6/6** respectés : I1 pricing SSOT, I2 N/A, I3 branch_id (même si mono-filiale en pratique), I4 dispatch after commit (`IngredientAvailabilityChanged` use trait), I5 N/A, I6 frozen zones intactes.
- [ ] Documentation : `docs/V1_PIVOT_RELEASE_NOTES.md` créé, `docs/PROJECT_CONTINUITY_AND_VISION.md` mis à jour pour expliquer V1 vs V2 distinction.
- [ ] Migrations rollback testées (round-trip migrate/rollback OK).
- [ ] Sentinelle Graphiti : 5 facts ajoutés post-cycle (pivot V1 décisions, ingrédient unifié I.2, voie A wizard catégorie, frozen zones intouchées, Demo V2 conservé).

---

# Top 3 questions humaines à clarifier (synthèse finale)

> **Q1 — Multi-filiale : V1 ou V2 ?**  
> Si V2, je code mono-filiale en pratique avec scoping `branch_id` en place. Si V1 multi, j'ajoute la migration `ingredient_branch_availability` et augmente le scope Cycles 1-4 de ~30%. Tranche STP avant Cycle 0.

> **Q2 — Vitesse de livraison V1 : 1 semaine ou 1 mois ?**  
> Si 1 semaine, version réduite : Cycles 0+1+5+6+7 (sans Ingrédients), juste cacher composer per-item + simplifier sidebar. Le concept Ingrédient vient V1.5. Si 1 mois, plan complet 8 cycles. Tranche STP avant Cycle 0.

> **Q3 — Visibilité du bouton Demo V2 dans le menu admin ?**  
> Visible bas de menu (découvrable mais risque confusion) ou invisible accessible URL directe (sûr mais opaque pour power-users) ? Tranche STP avant Cycle 0.

**Statut livrable** : AUDIT + PLAN + ULTRA REVIEW complets. **Ne pas lancer l'exécution sans avoir tranché Q1, Q2, Q3.** Reviens vers moi (ou Cursor Claude) pour valider et démarrer Cycle 0.

— Claude (claude-opus-4-7, effort max, 2026-05-04)
