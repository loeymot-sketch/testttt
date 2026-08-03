# Claude — Demande d'Ultra-Review #2 : Stock + Composition produit (lifecycle admin → runtime) — 2026-05-02

> **Tu reçois cette demande dans ton terminal `claude` (abonnement Anthropic Pro).** Audit ultra-profond. **Pas d'exécution code.** Livrable unique sous `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_<YYYY-MM-DD>.md`.

> Cette mission est **complémentaire** à la mission #1 (`CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_1_CATALOG_SYNC_2026-05-02.md`). La mission #1 cible la SYNCHRONISATION (POS lit-il la même chose que Kiosk ?). La mission #2 cible le LIFECYCLE (peut-on créer / modifier / composer / mettre en rupture un produit depuis l'admin **proprement** et est-ce reflété **sans bug** sur toutes les surfaces, y compris les commandes en cours et l'historique fiscal ?).

---

## 0. Contexte humain (à lire avant tout)

Le propriétaire FoodKing est un restaurateur qui crée et gère ses produits depuis le Dashboard admin (`/admin/items`, `/admin/categories`, `/admin/composer-profiles`, etc.). Il décrit son ressenti actuel :

> « Quand je vais dans une catégorie, je trouve toutes les produits qu'il y a dedans et tous ces produits je pourrais rentrer [pour modifier]. Faudra vraiment un breakdown hyper détaillé pour pouvoir faire ça parce que là actuellement c'est pourri y a rien qui fonctionne dans notre système de gestion. D'après ce que je vois, beaucoup de complexité y a pas quelque chose de bien implémenter et facile à gérer. »

Symptômes observés ou suspectés :

- Composition produit **trop complexe** côté admin (créer un tacos avec viandes, sauces, suppléments, prix, photos, attributs, contraintes min/max — workflow morcelé en plusieurs écrans).
- Modification d'un produit dont le **wizard composer profile** est en cours d'utilisation par des kiosks ou paniers ouverts → comportement non clarifié.
- Mise en **rupture** d'un produit ou d'un choix wizard : le bouton 86 fonctionne (validé `VA-SYS-06 PASS_LOCAL`), mais l'UX admin pour gérer la rupture **avant** une commande (préventif, pas réactif après caisse) n'est pas clair.
- **Suppression** d'un produit référencé par des commandes historiques : risque sur les snapshots fiscaux NF525 (`composition_snapshot` immutable).
- **Stock niveau choix wizard** (ex. plus de viande halal pour les tacos) : la rupture s'affiche-t-elle correctement pendant le wizard sans casser la commande en cours ?

Le propriétaire veut :

1. Un audit **ultra-rigoureux** du lifecycle produit centralisé (création → publication → consommation → rupture → cancel/refund release → archivage).
2. Une **carte des points où ça casse** : workflow admin trop complexe, états transitoires non gérés, races condition entre catalogue mutation et orders en cours.
3. Un **plan de remédiation hiérarchisé** que je puisse donner à exécuter à Codex (`gpt-5.5-pro / xhigh`).

---

## 1. Pré-requis de chargement (parcours obligatoire condensé)

Lecture **obligatoire** avant de produire ton rapport, dans cet ordre :

1. `**AGENTS.md`** § *Parcours obligatoire* + § *Authoritative multi-agent bounded cycle*.
2. `**.cursor/rules/project-invariants.mdc*`* — invariants à protéger (pricing SSOT, branch_id, dispatch after commit, frozen zones).
3. `**docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md**` — spec officielle V1 du lifecycle produit centralisé. Lis intégralement.
4. `**docs/sync/STOCK_SYNC_AND_AVAILABILITY.md**` — modèle stock 2 niveaux + flow runtime stock change → écrans.
5. `**docs/sync/WIZARD_PRODUCT_MODEL.md**` — modèle composer wizard et règles de rejet pricing.
6. `**docs/sync/CATALOG_COMPOSER_DATA_FLOW.md**` — table « Write side: Dashboard to backend » + cache/invalidation.
7. `**docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md**` — symptom runbook officiel (Wizard not visible, Stock not synced, Photo not updated).
8. `**docs/MENU_AVAILABILITY.md**` (référence section MENU_86 V1).
9. `**reports/audit/CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_1_CATALOG_SYNC_2026-05-02.md**` — pour ne pas dupliquer le travail mission #1.

Mémoire Graphiti / JSONL — appelle au moins :

```
search_memory_facts query="composer profile wizard publish branch scope"
search_memory_facts query="StockService decrement release idempotent ledger"
search_memory_facts query="ChoiceAvailabilityResolver variation extra addon stockable"
search_memory_facts query="composition_snapshot NF525 immutable order_items"
search_memory_facts query="ItemBranchAvailability MENU_86 toggle rupture"
search_memory_facts query="VA-SYS-06 PASS_LOCAL stock rupture proof"
```

JSONL ciblés :

- `memory/episodes/02_architecture_invariants.jsonl` — épisodes 4 (composition_snapshot T07), 5 (allergens snapshot T05), 11 (SSOT pricing), 13 (frozen zones)
- `memory/episodes/03_domain_events_sync.jsonl` — épisode 12 (ItemAvailabilityChanged émission), 15 (VA-SYS-08 outbox runtime)
- `memory/episodes/04_pricing_ssot.jsonl` — formules + edge cases
- `memory/episodes/06_kiosk_features.jsonl` — wizard tacos multi-quantité, allergens, offline
- `memory/episodes/07_pos_features.jsonl` — park orders, multi-tender
- `memory/episodes/08_kds_features.jsonl` — bump/recall, item availability
- `memory/episodes/09_tasks_history.jsonl` — Vague D + cross-wave findings (G-1, G-2, G-3, SYNC-001/002)

---

## 2. Carte du système actuel (état que je viens de cartographier — vérifie-la)

### 2.1 Modèle de produit (V1)

Tables impliquées dans la composition d'un produit :

- `items` — fillable inclut `name, item_category_id, slug, barcode, tax_id, item_type, price, is_featured, is_upsell, is_chef_pick, is_new, is_available, is_spicy, is_vegetarian, is_pork_free, is_halal, is_gluten_free, chef_pick_order, description, caution, status, order, channels, allergen_flags, kiosk_emoji, kds_station`
- `item_categories` — `parent_id, name, slug, description, status, sort, wizard_template, has_menu, default_menu_kiosk, sauce_included_menu, kiosk_upsell_include, kiosk_upsell_skip_after_cart, channels, kiosk_sort, pos_sort, kiosk_label`
- `item_attributes` (groupes de variations — ex. « Taille », « Sauce ») + colonnes `min_select, max_select, allow_repeat`
- `item_variations` (options dans un attribut — ex. « M », « L »)
- `item_extras` (suppléments simples, plats, ingrédients) + colonnes `visible_on, group_label`
- `item_addons` (addons reliés à un item cible — ex. boisson en supplément d'un menu) + colonne `role`
- `item_wizard_profiles` + `item_wizard_steps` (composer SSOT V1 publié)
- `item_branch_availability` (rupture/86 niveau item × branche)
- `stock_levels` (rupture/stock niveau choix : ItemVariation, ItemExtra, addon target Item) + `stock_movements`
- `item_allergens` (pivot codes FR) + `item_extra_allergens` (pivot)

5 « kinds » de produit définis (cf. `WIZARD_PRODUCT_MODEL.md`) :

1. **Ready product** : aucune compose — boisson, dessert, gâteau, frite. Stockable possible.
2. **Simple option product** : composer profil court — taille, cuisson, sauce simple.
3. **Complex composed product** : composer profile multi-step publié obligatoire — tacos, sandwich configurable, menu.
4. **Addon target product** : peut être autonome ou utilisé comme addon (ex. boisson en addon menu). Stockable.
5. **Ingredient / crudite / sauce / supplement** : choix wizard, stockable si pertinent (ex. plus de viande halal).

### 2.2 Lifecycle dashboard → runtime (flow attendu)

Selon `PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md` §`Cycle de vie dashboard` :

```
1. Créer catégorie si nécessaire.
2. Créer item (statut, visibilité channels).
3. Uploader photo si rôle global autorise (Admin / Tenant Admin).
4. Déclarer stock produit si suivi stock.
5. Déclarer variations / extras / addons OU composer profile selon complexité.
6. Publier le composer profile si produit composé.
7. Vérifier projection Kiosk / POS.
```

Effets attendus à chaque mutation (cache + outbox) :

- Cache `kiosk.menu.branch.{id}` invalidé (si touche un item visible kiosk)
- `MenuSnapshot::bump($branchId)` → `menu:snapshot_version:branch:{id}` incrément
- Event `CatalogChanged` (depuis `ItemCreated`/`Updated`/`Deleted`, `Category*`, `ComposerProfileChanged/Published`, `StockLevelChanged`) persisté outbox + broadcast `private-branch.{id}`
- POS / Kiosk reçoivent l'event → refetch projection silencieux (cf. `_onCatalogChanged` dans `PosComponent.vue`, `kioskMenu/fetchMenu` dans `KioskAppComponent.vue`)

### 2.3 Stock — modèle 2 niveaux (V1)


| Niveau                | Source                                                | Émis par                                                                          | Recouvré par                         |
| --------------------- | ----------------------------------------------------- | --------------------------------------------------------------------------------- | ------------------------------------ |
| Produit (item entier) | `item_branch_availability.is_available`               | `AvailabilityService::toggle` (admin) ou auto-86 sur seuil                        | `ItemAvailabilityChanged::forBranch` |
| Variation stockable   | `stock_levels[stockable_type=ItemVariation]`          | `StockService::decrementForOrder` (à l'order) / `releaseForOrder` (cancel/refund) | `StockLevelChanged`                  |
| Extra stockable       | `stock_levels[stockable_type=ItemExtra]`              | idem                                                                              | idem                                 |
| Addon target          | `stock_levels` + `item_branch_availability` du target | idem                                                                              | idem                                 |


Resolver de visibilité côté projection : `App\Services\Stock\ChoiceAvailabilityResolver`. Backend pricing `PricingService::calculateOrder` rejette toute soumission contenant un choix indisponible (forge frontend, race avec rupture, choix supprimé du composer profile entre ouverture du panier et submit).

### 2.4 Composer profile — wizard SSOT V1

Tables : `item_wizard_profiles` + `item_wizard_steps`. Service : `ComposerProfileService` + `ComposerStepService`. Projection : `ComposerProfileProjection` (consommée par `MenuProjectionService`, `KioskMenuService`, `PricingService`).

Règles documentées :

- Pas de profil publié = pas de wizard forcé.
- Profil publié prioritaire sur l'ancienne heuristique wizard.
- Profil peut être global ou branch-scoped ; un acteur branche ne peut pas muter un profil branche étranger.
- Chaque choix soumis doit exister dans la projection publiée courante.
- Steps `required` ne peuvent pas être satisfaits par des choix indisponibles.
- `repeat`, `min_select`, `max_select`, `role` validés par backend pricing.
- Frontend = aide UX seulement ; backend = autorité de rejet.

Frozen zones liées :

- `app/Services/Pricing/PricingService.php`
- `resources/js/components/admin/pos/ItemComponent.vue` (modale wizard POS — gère le rendu, ne calcule pas le prix)

### 2.5 NF525 et historique

Au moment de la création d'un `OrderItem` :

- `composition_snapshot` (JSON) figé : `item_name`, `item_price`, `variations[]`, `extras[]`. **Immuable**. Cf. épisode 4 JSONL.
- `allergens_snapshot` (JSON) figé : array de codes FR. Cf. épisode 5 JSONL.

Conséquences :

- Renommer un item, désactiver une variation, ou supprimer un extra **après** une commande : la réimpression ticket affichera toujours le nom/prix d'origine.
- Suppression dure d'un item : doit rester rare. Recommandation `PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md` §`Suppression / desactivation` : préférer status hidden/inactive.
- KDS / OSS / réimpression POS lisent **les snapshots**, pas le catalogue live (sinon réécriture de l'histoire).

### 2.6 Hypothèses sur les points faibles (à challenger ou confirmer)

1. **Workflow admin morcelé.** Création complète d'un tacos = passer par `/admin/items/create`, `/admin/item-attributes`, `/admin/item-variations`, `/admin/item-extras`, `/admin/composer-profiles`, `/admin/composer-steps`. Pas de wizard admin guidé. Risque opérationnel : un restaurateur loupe une étape (ex. créer le composer profile mais ne pas le publier, ou publier mais pas pour la bonne branche). Vérifie l'UX `resources/js/components/admin/items/ItemCreateComponent.vue` + `resources/js/components/admin/composerProfiles/*.vue`.
2. **Mutation pendant ouverture panier.** Si un client a ouvert le wizard kiosk avec un composer profile v1 publié, et qu'un admin publie v2 entre temps — le payload submit (qui référence des option_id) peut ne plus matcher. `PricingService` doit rejeter, mais quel est le code retour 422 affiché côté kiosk ? Y a-t-il un fallback UX ou le client voit-il un écran d'erreur cassé ?
3. **Cancel / refund release stock.** `StockService::releaseForOrder` est-il appelé sur **toutes** les voies de cancel : POS cashier cancel, KDS recall, refund partiel ? `tests/Feature/Stock/StockReleaseOnRefundTest.php` + `StockReleaseOnCancelTest.php` couvrent une partie. Détecte un trou éventuel.
4. `**OrderService` / `FrontendOrderService` symétrie** (cf. invariant §5 `project-invariants.mdc`) : si l'un évolue pour gérer une nouvelle règle de stock, l'autre est-il systématiquement mis à jour ? Y a-t-il un sentinel test sur cette symétrie ?
5. **Photo invalidation.** `ItemController::changeImage` émet un event qui invalide le cache kiosk (`PhotoEndToEndKioskInvalidationTest`). Le POS, qui n'utilise pas le même cache, met à jour la photo via quel chemin ? Refetch list ? Image directement par URL signée par Spatie media library ?
6. **Suppression vs hidden.** Le bouton « delete » dans `/admin/items` fait-il une vraie destroy (avec soft delete via `SoftDeletes` trait — confirmé dans `Item.php`) ou un toggle status ? Si soft delete, les commandes historiques continuent-elles à pouvoir réimprimer leur ticket ? Il faut vérifier que `composition_snapshot` est bien la seule source consommée.
7. **Auto-86 sur seuil stock.** Y a-t-il un mécanisme automatique (job scheduled) qui scrute `stock_levels` et bascule `is_available=false` quand on tombe à zéro ? Si oui, dans quel service ? Si non, c'est un manque (les choix wizard tombent à zéro mais l'item entier reste « disponible » dans la projection).
8. `**channels = NULL = visible partout`.** Politique back-compat documentée dans `docs/MENU_PROJECTIONS.md` §2. Pour un nouveau restaurateur qui crée un item sans cocher channels, son nouvel item apparaît partout — y compris sur des branches qui n'ont pas le produit en stock. Risque opérationnel.

---

## 3. Ce que je veux que tu produises

### 3.1 Section A — Vérification de mon état des lieux (1-2 pages)

Pour chacun des **8 points faibles supposés** §2.6, verdict :

- **CONFIRMÉ** (file:line + impact métier observable)
- **PARTIELLEMENT CONFIRMÉ** (file:line + nuance)
- **INVALIDÉ** (file:line qui infirme)

Ajoute tout point faible que tu trouves en plus, sous `2.6 — Points faibles supplémentaires découverts`.

### 3.2 Section B — Atlas du workflow admin produit (1-2 pages)

Trace pas-à-pas le parcours d'un restaurateur qui crée un tacos complet (composer wizard, 4 viandes, 6 sauces, 5 crudités, 3 suppléments fromage, photo, prix, contraintes min/max). Pour chaque étape :


| Étape                           | URL admin                       | Composant Vue                     | Endpoint backend                      | Validation backend    | Message d'erreur si raté |
| ------------------------------- | ------------------------------- | --------------------------------- | ------------------------------------- | --------------------- | ------------------------ |
| 1. Créer la catégorie           | `/admin/categories/create`      | `ItemCategoryCreateComponent.vue` | `POST /api/admin/item-categor[y|ies]` | `ItemCategoryRequest` | ...                      |
| 2. Créer l'item de base         | `/admin/items/create`           | `ItemCreateComponent.vue`         | `POST /api/admin/items`               | `ItemRequest`         | ...                      |
| 3. Créer l'attribut « Viandes » | `/admin/item-attributes/create` | ...                               | ...                                   | ...                   | ...                      |
| ...                             | ...                             | ...                               | ...                                   | ...                   | ...                      |


Identifie les **frictions** :

- Étapes où l'utilisateur peut oublier de publier
- Étapes où le composant Vue ne renvoie pas l'erreur backend de manière exploitable
- Étapes où la donnée doit être ré-saisie alors qu'elle est déjà connue
- Manque de prévisualisation « ce que verra le kiosk / le POS »

### 3.3 Section C — Atlas des états transitoires et race conditions (1 page)

Liste exhaustive des cas où une mutation admin se produit pendant qu'une commande est en cours sur kiosk/POS :


| État au moment T                   | Mutation admin à T+1                              | Effet attendu côté commande T                                                              | Effet observé (citation file:line) | Risque |
| ---------------------------------- | ------------------------------------------------- | ------------------------------------------------------------------------------------------ | ---------------------------------- | ------ |
| Wizard kiosk ouvert avec profil v1 | Publication profil v2                             | Submit en cours soit accepté (si choix valides aussi en v2) soit rejeté 422 avec UX claire | ...                                | ...    |
| Cart kiosk avec item X             | Toggle `item_branch_availability` X = unavailable | Pruning `kioskCart/pruneUnavailable` + toast                                               | ...                                | ...    |
| Cart POS avec variation Y          | Stock niveau Y tombe à 0                          | Retrait variation + toast cashier                                                          | ...                                | ...    |
| Commande POS submit en cours       | Suppression item Z (soft delete)                  | Order avec snapshot Z reste lisible                                                        | ...                                | ...    |
| Refund partiel                     | Item supprimé entre achat et refund               | Release stock idempotent depuis snapshot                                                   | ...                                | ...    |


Tu peux étendre la liste. Cite les sentinels tests qui couvrent (ou pas) chaque ligne.

### 3.4 Section D — Plan de remédiation hiérarchisé (page principale)

**Vague 1 — UX admin sans changement de schéma (≤ 1 cycle masterplay)**

- Améliorations workflow admin produit qui ne touchent ni la DB ni les invariants.
- Exemple : wizard guidé multi-step côté Vue admin pour la création d'un produit composé, qui appelle séquentiellement les endpoints existants sans changer leur contrat.
- Exemple : prévisualisation « rendu kiosk » et « rendu POS » dans `/admin/items/show/{id}` (consomme `MenuProjectionService` qui existe déjà).
- Exemple : avertissement « profil composer non publié » sur la page de détail item.
- Pour chaque action : `file:line` cible + résumé du fix + sentinel à ajouter.

**Vague 2 — Hardening stock + composition (1-3 cycles)**

- Auto-86 sur seuil stock : job scheduled ou listener sur `StockLevelChanged` qui bascule `item_branch_availability` quand toutes les variations sont en rupture.
- Symétrie OrderService / FrontendOrderService : ajout d'un sentinel test qui vérifie qu'une nouvelle règle de stock-release ajoutée à l'un est bien à l'autre (par diff structuré ou par contrat partagé).
- Refactor du flow « publication composer profile pendant panier ouvert » avec un message UX clair côté kiosk (« Le menu a été mis à jour, votre panier sera vérifié »).
- Pour chaque action : effort (S/M/L), risque, gate humain, tests.

**Vague 3 — Refactor structurel (multi-cycles, hard gates)**

- Politique « `channels = NULL = visible partout »` remplacée par `channels = required` + migration de backfill + data audit.
- Modèle de stock unifié : `stock_levels` deviendrait l'unique source pour produit + variation + extra + addon target, en absorbant `item_branch_availability` qui devient une vue dérivée.
- Workflow admin « produit unifié » : un seul écran qui crée item + attributes + variations + extras + addons + composer profile + photo dans une seule transaction guidée. Schéma préservé, mais nouvelle UI. Hard gate UX QA sign-off.
- Pour chaque action : effort, risque, gate humain (cite le brief gate à ouvrir si pas encore fait), tests.

### 3.5 Section E — Definition of Done « Produit centralisé V1 final » enrichie (½ page)

Reprends la `Definition of done produit centralise` de `PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md` (10 items) et propose une version étendue qui couvre les trous découverts. Numérotée. Chaque item testable par un sentinel.

### 3.6 Section F — Verdict final et recommandation (10 lignes)

- Faut-il bloquer la mise en prod V1 sur ce sujet ? Si oui, **quel** point précis bloque.
- Quelle est la dette technique acceptable et l'écart à V1.5.
- Verdict synthétique : `READY_FOR_V1` / `READY_WITH_DEBT_TICKET` / `BLOCK_V1`.
- Recommandation cycle suivant (un seul `TASK_ID` proposé).

---

## 4. Périmètre file-by-file que tu peux ouvrir

**Backend — services produit / catégorie / composer / stock** :

- `app/Services/ItemService.php` (méthodes `store`, `update`, `destroy`, `simpleList`, `show`, `itemDetails`, `changeImage`)
- `app/Services/ItemCategoryService.php` + `ItemCategoryHierarchyService.php`
- `app/Services/ItemAttributeService.php` + `ItemVariationService.php` + `ItemExtraService.php` + `ItemAddonService.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `app/Services/Stock/StockService.php` (lecture intégrale — c'est le cœur)
- `app/Services/Stock/ChoiceAvailabilityResolver.php`
- `app/Services/Menu/AvailabilityService.php`
- `app/Services/Pricing/PricingService.php` (lecture seule — frozen zone — vérifie les guards `assertSelectionsOrderable` / `validateComposerSelections`)

**Backend — controllers admin** :

- `app/Http/Controllers/Admin/ItemController.php`
- `app/Http/Controllers/Admin/ItemCategoryController.php`
- `app/Http/Controllers/Admin/ItemAttributeController.php`
- `app/Http/Controllers/Admin/ItemVariationController.php`
- `app/Http/Controllers/Admin/ItemExtraController.php`
- `app/Http/Controllers/Admin/ItemAddonController.php`
- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Http/Controllers/Admin/ComposerStepController.php`
- `app/Http/Controllers/Admin/AvailabilityController.php`
- `app/Http/Controllers/Admin/PosController.php` (méthode `quote` + lifecycle decrement/release)

**Backend — modèles + migrations** :

- `app/Models/{Item,ItemCategory,ItemAttribute,ItemVariation,ItemExtra,ItemAddon,ItemWizardProfile,ItemWizardStep,ItemBranchAvailability,StockLevel,StockMovement}.php`
- Migrations clés (pour comprendre l'ordre historique) :
  - `2022_11_17_110428_create_item_categories_table.php`
  - `2022_11_17_110514_create_items_table.php`
  - `2022_11_17_110541_create_item_attributes_table.php`
  - `2022_11_17_110621_create_item_variations_table.php`
  - `2022_11_17_110650_create_item_extras_table.php`
  - `2022_11_17_120627_create_item_addons_table.php`
  - `2026_04_15_230100_create_item_branch_availability_table.php`
  - `2026_04_16_200000_add_channel_columns_to_items_and_categories.php`
  - `2026_04_18_120001_add_parent_id_to_item_categories_table.php`
  - `2026_04_22_000010_add_min_max_repeat_to_item_attributes.php`
  - `2026_04_22_000020_add_composition_snapshot_to_order_items.php`
  - `2026_04_27_143100_create_item_wizard_profiles_table.php`
  - `2026_04_27_143110_create_item_wizard_steps_table.php`
  - `2026_04_27_143120_create_stock_levels_table.php`
  - `2026_04_27_143130_create_stock_movements_table.php`
  - `2026_04_27_143140_add_role_to_item_addons_table.php`

**Backend — events / listeners liés au lifecycle** :

- `app/Events/{ItemCreated,ItemDeleted,CatalogChanged,ItemAvailabilityChanged,StockLevelChanged}.php`
- `app/Events/{CategoryCreated,CategoryUpdated,CategoryDeleted}.php` (si présents)
- `app/Events/Composer/ComposerProfileChanged.php` (et `Published` si distinct)
- `app/Listeners/PersistCatalogChangedToOutbox.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`
- `app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php`

**Frontend admin** (UX produit lifecycle) :

- `resources/js/components/admin/items/ItemCreateComponent.vue`
- `resources/js/components/admin/items/ItemListComponent.vue`
- `resources/js/components/admin/items/ItemShowComponent.vue`
- `resources/js/components/admin/items/AvailabilityToggleComponent.vue`
- `resources/js/components/admin/items/ProductComposerSummaryComponent.vue`
- `resources/js/components/admin/items/ItemUploadComponent.vue`
- `resources/js/components/admin/itemCategories/**/*.vue`
- `resources/js/components/admin/itemAttributes/**/*.vue`
- `resources/js/components/admin/itemVariations/**/*.vue`
- `resources/js/components/admin/itemExtras/**/*.vue`
- `resources/js/components/admin/itemAddons/**/*.vue`
- `resources/js/components/admin/composerProfiles/**/*.vue` (si dossier existe)
- `resources/js/store/modules/{item,itemCategory,itemAttribute,itemVariation,itemExtra,itemAddon,composerProfile}.js`

**Frontend kiosk wizard** (lecture seule pour comprendre le contrat consommateur) :

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`

**Tests** (à parcourir pour comprendre les invariants déjà sentinellisés) :

- `tests/Feature/Stock/StockLevelSchemaTest.php`
- `tests/Feature/Stock/StockMovementsAppendOnlyTest.php`
- `tests/Feature/Stock/StockReleaseOnRefundTest.php`
- `tests/Feature/Stock/StockReleaseOnCancelTest.php`
- `tests/Feature/Stock/StockConcurrentDecrementTest.php`
- `tests/Feature/Stock/StockBranchIsolationTest.php`
- `tests/Feature/Stock/StockSymmetryDiffTest.php`
- `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`
- `tests/Feature/Stock/StockAvailabilityAfterCommitTest.php`
- `tests/Feature/Stock/StockDecrementOrderServiceTest.php`
- `tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php`
- `tests/Feature/Composer/ComposerStepServiceContractTest.php`
- `tests/Feature/Composer/ComposerPublishSyncTest.php`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`
- `tests/Feature/Composer/ComposerProfileApiTest.php`
- `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`
- `tests/Feature/Catalog/ProductPhotoAuthzTest.php`
- `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`
- `tests/Feature/Catalog/AddonRolePersistenceTest.php`
- `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php`
- `tests/js/posWizardComposerProfile.spec.js`
- `tests/js/kioskWizardGenericComposer.spec.js`

**Frozen zones (lecture seule) — ne propose pas d'édit dedans** :

- `app/Services/Orders/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/Payments/PaymentService.php`
- `app/Services/Pricing/`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`

---

## 5. Style et discipline du livrable

(Identique à mission #1 — synthèse :)

- Markdown sous `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_<YYYY-MM-DD>.md`.
- Header avec date, modèle, effort, group_id Graphiti.
- Toute affirmation cite `file.php:line` ou `epi#N` JSONL.
- Pas de paste massif de code.
- **Pas d'édition de code.** Tu peux écrire dans `reports/audit/…` et `docs/gates/GATE_`* si tu identifies un nouveau gate humain non encore ouvert.
- Si une violation d'invariant : ne tente pas de fixer, liste-la en Section A et propose le gate brief en Vague 3 §3.4.
- Cross-référence MISSION 1 si un point appartient à la sync POS↔Kiosk.
- Propose en dernière section 1-3 épisodes JSONL à ingérer dans `memory/episodes/12_decisions_log.jsonl` ou `09_tasks_history.jsonl`.

---

## 6. Question d'ouverture (la tienne, propose au début)

Avant ton audit, **maximum 3 questions de clarification** bloquantes. Si rien : « Aucune question — j'ai tout ce qu'il faut » et passe à l'audit.

---

## 7. Sortie attendue (résumé exécutif obligatoire)

Ouvre par un résumé de **20 lignes maximum** :

1. Verdict (`READY_FOR_V1` / `READY_WITH_DEBT_TICKET` / `BLOCK_V1`)
2. Top 3 risques par gravité
3. Top 3 quick wins recommandés
4. Recommandation cycle suivant (un seul `TASK_ID`)

---

## 8. Méta — articulation des deux missions


| Aspect          | Mission #1 (Sync)                                                    | Mission #2 (Lifecycle)                                                              |
| --------------- | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Question maître | POS et Kiosk lisent-ils la même chose ?                              | Le lifecycle admin produit est-il propre et reflété sans bug runtime ?              |
| Focus           | Projections + endpoints + cache + events                             | UX admin + state transitions + stock 2 niveaux + composer publish + NF525 snapshots |
| Frozen zones    | Lecture seule                                                        | Lecture seule                                                                       |
| Livrable        | `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_<date>.md` | `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_<date>.md`           |
| Inputs partagés | `docs/sync/`*, JSONL, invariants                                     | idem                                                                                |


Si une découverte appartient aux deux : fais-en l'autorité dans **une** des deux missions et cite l'autre. Pas de duplication de contenu — référence croisée seulement.

---

**FIN DU BRIEF MISSION 2.** Tu peux ouvrir tous les fichiers du périmètre §4. Si tu sors, justifie-le explicitement dans la section où tu l'utilises.