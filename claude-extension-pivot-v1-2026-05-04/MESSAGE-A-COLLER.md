# Ultra Audit + Ultra Plan + Ultra Review — Pivot V1 FoodKing

Salut Claude. J'ai besoin que tu raisonnes **avec effort maximal** (mode thinking étendu si possible) sur un **pivot stratégique majeur** de mon SaaS de restauration FoodKing. Je veux **un audit profond du code actuel par rapport à la nouvelle vision V1, suivi d'un plan d'exécution détaillé, suivi d'une auto-review critique**.

Tu n'as **aucun contexte**. Tu lis ce message + les fichiers que je te donne en pièce jointe, puis tu produis 3 livrables :
1. **AUDIT** : que faut-il garder, masquer, supprimer, refaire ?
2. **PLAN** : cycles bornés, ordre d'exécution, dépendances, gates.
3. **ULTRA REVIEW** : challenge ton propre plan — risques, invariants, ce qu'on rate.

Format final attendu : un seul gros document Markdown structuré (~3 000-5 000 mots). Pas de code complet, juste les **chemins exacts à toucher** + l'ossature des changements.

---

## 1. CONTEXTE — Qu'est-ce que FoodKing aujourd'hui

FoodKing est un SaaS pour restaurants couvrant :
- **Borne client (Kiosk)** : commande en libre-service, multi-pages, parcours de personnalisation par étape (taille → viande → sauce → garnitures → suppléments → menu).
- **Caisse (POS)** : interface caissier, monolithique single-page wizard.
- **Cuisine (KDS)** : tickets cuisine.
- **Écran statut commande (OSS)** : "votre commande est prête".
- **Admin** : gestion catalogue, stock, utilisateurs, rapports.

**Stack** : Laravel 10 + Vue 3 (`resources/js`) + Vuex + Laravel Mix + Echo/Pusher. Backend = SSOT pour pricing, statuts commande, isolation `branch_id`. Voir `AGENTS.md` et `.cursor/rules/project-invariants.mdc`.

---

## 2. ÉTAT ACTUEL du système (haut niveau, sans rentrer dans le code)

### 2.a. Catégories
Table `item_categories` (modèle `app/Models/ItemCategory.php`) : nom, slug, image, statut, parent (hiérarchie 2 niveaux max), `wizard_template` (NULL ou un enum : `simple|sandwich|tacos|assiette|snacking|menu|custom`), flags upsell kiosk, projection canaux POS/Kiosk.

### 2.b. Produits
Table `items` (modèle `app/Models/Item.php`) : `item_category_id`, nom, prix, taxe, type, dispo, image, allergènes, etc.

### 2.c. Wizard produit (le SUJET du pivot)

**Architecture actuelle (très complexe)** :
- Table `item_wizard_profiles` (1 profile par produit, lié à `item_id`).
- Table `item_wizard_steps` (étapes du wizard : `step_key`, `source_type` ∈ `item_attribute|extra_group|addon`, `source_ref`, `source_item_attribute_id`, `min_select`, `max_select`, `visible_on` JSON `["pos","kiosk"]`, `is_active`).
- Table `item_wizard_step_versions` (snapshots immuables pour publish/rollback).
- Service backend `app/Services/Composer/ComposerProfileService.php`, `ComposerStepService.php`, `ComposerTemplateService.php` (templates de pages prédéfinis), `ComposerDiffService.php`, `ComposerProfileProjection.php`.
- Frontend complet : `resources/js/components/admin/items/composer/` (`ProductComposerEditorComponent.vue`, `ComposerStepListSidebar.vue`, `ComposerStepFormPanel.vue`, `ComposerTemplatePickerModal.vue`, `ComposerPublishDiffModal.vue`, `ComposerVersionConflictBanner.vue`, etc.)
- Permet à l'admin de **personnaliser produit par produit** son wizard, avec versioning, diff, conflict detection, branch overrides.

### 2.d. Stock
Tables `stock_levels` (par item ou variation par branch), `stock_movements` (audit log immuable). Service `app/Services/Stock/StockService.php` (decrement après commit DB), `ChoiceAvailabilityResolver.php`. Dashboard ruptures : `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue`. Décrément atomique conditionnel à la création de commande.

### 2.e. Ingrédients
**Concept partiel actuel** :
- `item_attributes` (table) = "viandes", "sauces", "tailles" — typés. Reliés au wizard via `source_type = item_attribute`.
- `item_extras` (table) = suppléments (cheddar, bacon, etc.) groupés par `group_label`.
- `item_addons` (table) = add-ons catalogue.
- **Pas de notion claire d'« ingrédient » unifié** côté UI admin. C'est dispersé : "Attributs d'articles" pour les viandes, "Extras" pour les suppléments, etc.

### 2.f. Catalog Studio (page unifiée actuelle)
`resources/js/components/admin/items/CatalogStudioComponent.vue` : page unique gérant catégories + produits + accès au wizard editor. Récemment refondue lors de cycles `CV1-V2-CATALOG-VISION-CLEANUP-001` et `CV1-V2-CATALOG-WIZARD-CORE-001` (voir `reports/execution/RUN_CV1-V2-CATALOG-*.md`).

### 2.g. Ce que voit l'admin aujourd'hui
Sidebar admin :
- Tableau de Bord
- Catalogue (= Catalog Studio)
- Attribut d'articles (= les "viandes/sauces/tailles" — entrée séparée, technique)
- Caisse / Commandes / Cuisine / Écran statut
- Communications / Users / Reports / Setup

**Problème perçu** : l'utilisateur (= moi, le restaurateur) trouve qu'il y a **trop d'options techniques**, le wizard est **trop complexe à personnaliser produit par produit**, "Attribut d'articles" est obscur, "Extras" / "Add-ons" sont dispersés, la gestion des ruptures **par ingrédient** n'existe pas.

---

## 3. LE PIVOT V1 — Ce que je veux (mots du restaurateur, pas du dev)

> « On laisse le wizard pour V2 et on va vraiment refaire le Dashboard plus clair et plus simple. Y aura la gestion de tous les produits, indication go en cas de rupture, modification ou ajout d'un produit, puis le wizard va être automatiquement le même selon la catégorie qui le contient. Ça veut dire ce produit y a pas de quelque chose magique : le produit qui est dans une catégorie va avoir le même wizard de cette catégorie, y aura pas de complexité ni personnalisation ni rien. Ça c'est pour la version 1 que je veux sortir le plus tôt possible.
>
> Quand on finit ces points-là : la gestion du stock très clair avec tous les produits, la liste des produits, puis la gestion des ingrédients. Pas un ingrédient comme un type de viande par exemple, ou bien des crudités, ou bien des suppléments, ou bien une sauce en rupture. Ça doit avoir une fenêtre seule **gestion des ingrédients**, et cela va être directement impliqué pour les wizard et indiqué comme si le produit est en rupture directement. Ils sont en rouge et en rupture.
>
> Voilà, je pense que j'ai couvert tous les aspects ici pour une V1 fonctionnelle. Puis assurer la synchronisation : tous nos travaux sont bien synchronisés et fonctionnels pour gérer les stocks, les catégories, les produits et les ingrédients comme j'ai dit, et tout soit synchronisé. Y a pas besoin de complexité.
>
> Tout ce qu'on a travaillé pour la gestion du wizard ou personnalisation, on le met de côté, dans un bouton qui s'appelle « **Demo** » par exemple, et on laisse ça à côté. Je veux pas de l'autre système principal. Je veux voir que les 3 ou 4 boutons principaux qui sont la gestion du stock (qui contient les produits et les ingrédients), la gestion des produits en cas d'ajout ou modification d'un produit ou d'une catégorie. Voilà le principal vraiment pour cette version. »

### 3.a. Reformulation technique du pivot

**Décisions stratégiques V1** :

1. **Le wizard NE peut PLUS être personnalisé produit par produit.** Le wizard est défini **au niveau catégorie**. Tous les produits de la catégorie "Tacos" partagent le même wizard "Tacos". Point.

2. **Toute la machinerie wizard existante** (composer profiles per-item, steps versioning, diff, conflict detection, branch overrides) **n'est PAS supprimée du code** — elle est :
   - Cachée derrière un bouton "**Demo / Avancé V2**" séparé, isolé du flux principal.
   - Préservée pour V2 (réactivation future).
   - **Inactive** par défaut sur le flux V1.

3. **Le wizard de catégorie** est une chose simple : 1 catégorie = 1 wizard hérité automatiquement. Quand on ajoute un produit à une catégorie, il prend automatiquement le wizard de la catégorie. **Aucune action de l'admin requise au niveau produit**.

4. **Notion d'« ingrédient » unifiée** : créer une vue admin qui regroupe **viandes + sauces + suppléments + crudités + boissons-éléments** sous un seul concept appelé « Ingrédient ». Quand un ingrédient passe en rupture (toggle ON/OFF), **tous les produits qui le contiennent dans leur wizard** sont automatiquement marqués "en rupture" (badge rouge en POS, Kiosk, Admin). Le client ne peut plus le commander.

5. **Le Dashboard admin V1** doit avoir **3 ou 4 boutons principaux maximum**, pas plus. Proposition :
   - **Stock** (vue unifiée : produits + ingrédients, indication rupture rouge)
   - **Catalogue** (catégories + produits, ajout/modif rapide)
   - **Ingrédients** (gestion des viandes/sauces/suppléments, toggle rupture)
   - **Demo Wizard avancé** (V2 — caché par défaut, accessible si besoin)
   
   Tout le reste (Attribut d'articles, technique) → **caché** ou **fusionné** dans Ingrédients.

6. **Synchronisation forte** : un changement de stock ingrédient → propagation immédiate aux produits concernés → propagation immédiate aux POS / Kiosk via les events existants (`ItemAvailabilityChanged`, `CatalogChanged`, `StockLevelChanged`).

7. **Objectif** : **V1 livrable rapidement**, focus sur la gestion catalogue + stock + ingrédients qui **fonctionnent réellement et de façon synchronisée**, pas sur des features cosmétiques.

---

## 4. CE QUE JE VEUX QUE TU PRODUISES

### Livrable 1 — AUDIT (1/3 du document)

Pour chaque sous-système actuel, dis-moi explicitement :

| Composant | Décision V1 | Pourquoi |
|---|---|---|
| `ItemWizardProfile` (per-item) | À MASQUER / SUPPRIMER / GARDER MAIS DÉSACTIVER ? | Justifie |
| `ItemWizardStep` (per-item) | … | … |
| `ComposerTemplateService` | … | … |
| `ComposerProfileService` | … | … |
| `ProductComposerEditorComponent.vue` | … | … |
| `CatalogStudioComponent.vue` | … | … |
| `StockRuptureDashboardComponent.vue` | … | … |
| `item_attributes` (table) | … | … |
| `item_extras` (table) | … | … |
| `item_addons` (table) | … | … |
| Sidebar admin "Attribut d'articles" | … | … |
| Tests E2E `catalog-studio-create-product-flow.spec.js` | … | … |

**Question critique** : pour appliquer un wizard **au niveau catégorie**, on a 2 grandes voies architecturales :
- **Voie A** : ajouter le wizard sur `item_categories` (nouvelle relation `item_categories → item_wizard_profiles`) et faire pointer chaque produit vers le wizard de sa catégorie via une jointure runtime. **Pas de duplication.**
- **Voie B** : garder `item_wizard_profiles → item_id` mais à la création/modif produit, copier automatiquement le wizard de la catégorie sur le produit. **Duplication mais isolation.**

**Recommande la voie** avec pour/contre détaillés (impact migration, runtime, sync, complexité, retour en V2 perso per-item).

**Autre question critique** : le concept « Ingrédient » V1. On le construit comment ?
- **Option I.1** : nouvelle table `ingredients` qui unifie viandes/sauces/etc., avec une migration qui consolide.
- **Option I.2** : vue agrégée sur `item_attributes` ∪ `item_extras` ∪ `item_addons`, sans nouvelle table.
- **Option I.3** : refactor complet : tout devient `ingredients` avec un `type` (viande/sauce/supplément/crudité), suppression progressive des 3 tables.

Recommande, justifie.

---

### Livrable 2 — PLAN d'exécution (1/3 du document)

Format : **séquence de cycles bornés**, chacun avec :
- TASK_ID proposé (ex `CV1-V1-PIVOT-INGREDIENTS-001`)
- Description courte
- `SUBSYSTEMS_TOUCHED` (chemins fichiers)
- `INVARIANTS_AT_RISK` (les 6 invariants FoodKing : pricing SSOT backend, OrderStatus enum, branch_id isolation, dispatch after commit, OrderService/FrontendOrderService symmetry, frozen zones)
- Dépendances (quel cycle après quel cycle)
- Gates anticipés (schema migration ? auth ? UX manuel ?)
- Effort approximatif (S/M/L/XL)
- Stratégie de tests (unit/feature/E2E Playwright)

Ordonnance les cycles dans l'ordre **où je dois les exécuter** pour aboutir à la V1 livrable.

**Cible** : un nombre **réaliste** de cycles (probablement 6-10), pas un mega-cycle, pas non plus 30 micro-cycles.

Identifie aussi les **gates humains obligatoires** (migrations schema, suppression de UI accessible, etc.).

---

### Livrable 3 — ULTRA REVIEW (1/3 du document)

Challenge ton propre plan. Cherche les **angles morts**. Section structurée :

**3.a. Risques techniques majeurs**
- Compatibilité descendante (wizards déjà créés en BDD → comment on les migre ?)
- Synchronisation runtime POS / Kiosk / KDS quand un ingrédient passe en rupture (events existants suffisent ?)
- Régression possible sur les commandes en cours quand on bascule architecture wizard
- Tests E2E qui cassent
- Permissions / rôles Spatie impactés

**3.b. Risques produit / UX**
- Le restaurateur va-t-il **vraiment** vouloir ce niveau de simplification, ou regretter d'avoir perdu la perso per-item dans 3 mois ?
- Le concept "Ingrédient" est-il vraiment intuitif ? Comment l'admin sait-il qu'un ingrédient existe dans un wizard de catégorie sans drill-down ?
- Le bouton "Demo / Avancé V2" — est-ce qu'on le cache vraiment, ou est-ce qu'on doit prévoir une migration BDD bloquante pour empêcher l'utilisation accidentelle ?

**3.c. Risques de planning**
- Le pivot V1 est-il **vraiment** plus rapide à livrer que de finir le wizard per-item existant ?
- Si non, dis-le franchement et propose une voie alternative.

**3.d. Invariants FoodKing à risque**
- Branch isolation : un wizard de catégorie sans branch override change-t-il le comportement multi-filiale (1 seule filiale aujourd'hui mais V1 prévoit le multi) ?
- Dispatch after commit : la propagation rupture ingrédient → produit doit dispatcher des events après commit DB.
- Pricing SSOT : aucun changement frontend autorisé.

**3.e. Recommandations finales**
- Top 3 décisions à valider avec l'humain avant de commencer.
- Top 3 raccourcis à NE PAS prendre ("ne pas faire X car Y").
- Une formulation claire de la **Definition of Done** V1 que je dois valider avant le démarrage.

---

## 5. CONTRAINTES IMPÉRATIVES (à respecter dans tout le plan)

1. **Ne casse pas le flux de commande existant.** POS, Kiosk, KDS, OSS doivent continuer à fonctionner pendant et après le pivot. Un client qui passe une commande Tacos pendant qu'on migre l'architecture ne doit pas crasher.
2. **Le code wizard existant ne doit PAS être supprimé pour V1** — il doit être préservé, masqué derrière un feature flag ou un bouton "Demo".
3. **Synchronisation realtime garantie** : Echo/Pusher events `ItemAvailabilityChanged`, `CatalogChanged`, `StockLevelChanged` continuent à fonctionner.
4. **Backend = SSOT pour pricing.** Pas de logique de prix côté frontend.
5. **OrderStatus enum reste authoritative**, pas de strings hardcoded.
6. **Branch isolation** : toute requête tenant-scoped doit rester filtrée par `branch_id` (même si 1 seule filiale aujourd'hui).
7. **Dispatch after DB commit** : tous les events doivent partir après commit transaction.
8. **Frozen zones** : si tu identifies un fichier frozen impacté, ouvre un gate brief obligatoire (`docs/gates/`).
9. **PHPUnit + Vitest doivent rester verts** à chaque cycle. Aucun cycle ne ferme avec régression.
10. **Tests E2E Playwright** mis à jour pour refléter la nouvelle UX V1 (3-4 boutons).

---

## 6. FICHIERS QUE JE TE DONNE EN PIÈCE JOINTE

(je vais les attacher ou les copier-coller selon ce que tu peux ingérer — la liste exacte est dans `LISTE-FICHIERS.md` du même dossier)

### Doctrine & règles de cycle
- `AGENTS.md` (root) — contrat agent + workflow multi-agents
- `CLAUDE.md` (root) — opérations Claude
- `.cursor/rules/project-invariants.mdc` — invariants FoodKing
- `.cursor/rules/global.mdc` — règles globales
- `.cursor/routing.md` — routage modèles

### Architecture & vision
- `docs/PROJECT_CONTINUITY_AND_VISION.md`
- `docs/ARCHITECTURE.md`
- `docs/BUSINESS_RULES.md`
- `docs/SAAS_VISION.md`
- `docs/orchestration/MEMORY_MATRIX.md`

### Code wizard / catégories / produits / stock (résumé)
- `app/Models/Item.php`
- `app/Models/ItemCategory.php`
- `app/Models/ItemAttribute.php`
- `app/Models/ItemExtra.php`
- `app/Models/ItemAddon.php`
- `app/Models/ItemWizardProfile.php`
- `app/Models/ItemWizardStep.php`
- `app/Models/ItemWizardStepVersion.php`
- `app/Models/StockLevel.php`
- `app/Models/StockMovement.php`
- `app/Models/ItemBranchAvailability.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Composer/ComposerTemplateService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `app/Services/Composer/ComposerDiffService.php`
- `app/Services/Stock/StockService.php`
- `app/Services/Stock/ChoiceAvailabilityResolver.php`
- `app/Services/Menu/PosMenuProjection.php`
- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Menu/AvailabilityService.php`
- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Http/Controllers/Admin/PosCategoryController.php`

### Frontend admin clé
- `resources/js/components/admin/items/CatalogStudioComponent.vue`
- `resources/js/components/admin/items/ItemPreviewComponent.vue`
- `resources/js/components/admin/items/AvailabilityToggleComponent.vue`
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue`
- `resources/js/components/admin/items/composer/ComposerStepListSidebar.vue`
- `resources/js/components/admin/items/composer/ComposerStepFormPanel.vue`
- `resources/js/components/admin/items/composer/ComposerTemplatePickerModal.vue`
- `resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue`
- `resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue`
- `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue`
- `resources/js/components/layouts/backend/BackendMenuComponent.vue`
- `resources/js/config/v1-hidden-modules.js`
- `resources/js/router/modules/itemRoutes.js`
- `resources/js/router/modules/stockRoutes.js`

### Migrations clés (référence schema)
- `database/migrations/2022_11_17_110428_create_item_categories_table.php`
- `database/migrations/2022_11_17_110514_create_items_table.php`
- `database/migrations/2022_11_17_110541_create_item_attributes_table.php`
- `database/migrations/2022_11_17_110650_create_item_extras_table.php`
- `database/migrations/2022_11_17_120627_create_item_addons_table.php`
- `database/migrations/2026_03_12_080617_add_wizard_config_to_item_categories.php`
- `database/migrations/2026_04_27_143100_create_item_wizard_profiles_table.php`
- `database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php`
- `database/migrations/2026_04_27_143120_create_stock_levels_table.php`
- `database/migrations/2026_04_27_143130_create_stock_movements_table.php`
- `database/migrations/2026_04_15_230100_create_item_branch_availability_table.php`
- `database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php`

### Plans & rapports récents (contexte des 2 derniers cycles)
- `plans/PLAN_CV1-V2-CATALOG-VISION-CLEANUP-001_2026-05-04.md`
- `plans/PLAN_CV1-V2-CATALOG-WIZARD-CORE-001_2026-05-04.md`
- `reports/execution/RUN_CV1-V2-CATALOG-WIZARD-CORE-001_2026-05-04.md`
- `audit-claude-ultra-review-2026-05-03/00-base-foodking/architecture-docs/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md`

### MCP Graphiti
Tu as accès au MCP **Graphiti** (`group_id=foodking`). **Utilise-le obligatoirement** :
- `search_memory_facts` avec des requêtes naturelles (ex : "frozen zones", "branch_id isolation rules", "wizard architecture decisions", "item_attributes vs item_extras semantics", "stock decrement after commit").
- `search_memory_nodes` pour les invariants.
- Mentionne **dans ton AUDIT** quels facts Graphiti tu as récupérés et comment ils ont influé tes décisions.

---

## 7. STYLE / FORMAT DE RÉPONSE

- **Français** (le projet est francophone).
- **Structuré markdown** avec sections H2 / H3 / tables.
- **Concret** : chemins exacts, lignes, noms de méthodes.
- **Pas de bullshit** : si tu ne sais pas, dis-le. Si une décision est difficile, expose les arbitrages.
- **Pas de code complet** : juste l'ossature des changements, les noms de méthodes/champs/migrations à créer.
- **Severity levels** explicites sur chaque risque (P0 critique / P1 majeur / P2 mineur).
- Termine par une **section "Top 3 questions humaines à clarifier"** que je dois trancher avant le démarrage du premier cycle.

---

## 8. CRITÈRES DE QUALITÉ POUR TON LIVRABLE

Tu seras jugé sur :
1. **Clarté du diagnostic** — tu m'as donné une photo précise et juste de l'état actuel.
2. **Pertinence des décisions architecturales** — tu as choisi la bonne voie (A vs B, I.1 vs I.2 vs I.3) avec arguments solides.
3. **Réalisme du plan** — les cycles sont bornés, dépendances claires, effort estimable.
4. **Profondeur de l'auto-review** — tu as challengé ton propre plan, pas juste validé.
5. **Respect des invariants FoodKing** — chaque cycle protège les 6 invariants ou justifie le gate.
6. **Pragmatisme V1** — tu as gardé en tête que je veux **livrer vite** et **fonctionnel**, pas une perfection théorique.

---

## 9. SI QUELQUE CHOSE EST AMBIGU

Avant de produire les 3 livrables, **liste les ambiguïtés** que tu détectes dans ma demande. Ne pars pas sur des hypothèses cachées. Si je dois clarifier 1 ou 2 points avant que tu te lances, dis-le clairement en tête de ta réponse, sous le titre `## Clarifications nécessaires avant production`.

Si tout est clair, attaque directement avec les 3 livrables.

---

**Démarre avec un effort de raisonnement maximal. Prends le temps. La qualité prime sur la vitesse.** Ce que tu produis va orchestrer les 2-3 prochaines semaines de développement et déterminer si ma V1 sort à temps ou pas.

Merci.
