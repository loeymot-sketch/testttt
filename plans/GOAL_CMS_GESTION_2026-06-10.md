# GOAL — CMS GESTION : Stock hiérarchique + CRUD catalogue + Builder Wizard (caisse & borne)

**Date:** 2026-06-10 · **Branche exécution:** `goal/cms-gestion-2026-06-10` (worktree `.claude/worktrees/cms-gestion-2026-06-10`) depuis `3ce18f767`
**Auteur:** Claude (ultra-architect-planify) · **Statut:** PLAN + EXÉCUTION AUTORISÉE (owner /goal 2026-06-10 : « tu feras le plan une fois et tu vas exécuter »)
**Fondation P3:** `plans/GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` (extrait du commit `60e4e5426`, anchors re-vérifiés @ `3ce18f767`)

---

## §0 — PRÉAMBULE (gouverne chaque tâche)

### 0.0 ⚠️ PIVOT SPINE (2026-06-10, post-audit topologie)
La base initiale `3ce18f767` (lignée cms-pr1-quickwins) n'avait que 2 commits docs au-dessus du merge-base `ad29e7875`, alors que la **spine `heal/pre-cloud-exec-2026-06-05` est 269 commits devant** et contient DÉJÀ : waves wizard W1/W2/W4/W5/W6 livrées + parité borne convergée (`plans/GOAL_WIZARD_E2E_PARITY_2026-06-09.md`), validation profonde 100% (PHPUnit 3092/0, Vitest 2098/0), DEVDB-GUARD natif. **Exécution re-basée sur la spine** : branche `goal/cms-gestion-2026-06-10-spine` depuis `7ebb1f252` (même worktree, switch in-place, plan cherry-pické). Voir §5 RE-SCOPE. Anti-fragmentation : ne JAMAIS exécuter un GOAL multi-système sans vérifier `git rev-list --left-right --count <base>...<spine>`.

### 0.1 Working-tree / discipline
- **Worktree dédié** `.claude/worktrees/cms-gestion-2026-06-10`, branche **`goal/cms-gestion-2026-06-10-spine`** depuis spine `7ebb1f252`. Anchors P1/P2 re-vérifiés sur la spine (identiques, sauf delete catégorie = `ItemCategoryService::destroy:165` + FK_CHECKS `:196-198`).
- **No push** sans owner explicite (CLAUDE.md §10). Commits checkpoint par wave (`checkpoint-commit`), `git add <fichiers explicites>` jamais `-A`.
- **Pipeline par tâche** = `ultra-audit-profond` (5 specialists RO → implement TDD → RED dispute → test → visual → self-correct ≤3). NON re-décrit ici.
- **Bootstrap worktree (Wave 0, BLOCKERS certains — vérifié RED 2026-06-10)** : le worktree n'a NI `vendor/` NI `node_modules/` NI aucun `.env*` réel (gitignorés). Wave 0 DOIT : (a) `cp -Rc` vendor depuis le checkout principal (vendor réel non-symlink vérifié) ; (b) copier `node_modules` (ou `npm ci`, build = **Mix**) ; (c) copier `.env`, `.env.testing` (→ `foodking_test` ✓) et `.env.e2e` (→ `foodking_e2e` ✓) depuis le checkout principal + vérifier que la DB `foodking_e2e` existe ; (d) **DEVDB-GUARD est ABSENT de cette branche** (`tests/CreatesApplication.php` = vanilla) → le ré-appliquer (port depuis lignée `pre-cloud-exec`) OU contrôle manuel `.env.testing` avant CHAQUE run PHPUnit.
- **E2E mutation** : JAMAIS sur `:8765`/DB `foodking` (NF525 réelle). Harness `:8766`/`foodking_e2e` (`APP_ENV=e2e php artisan serve --port=8766`, re-clone = reset), cf. `[[reference_e2e_harness_foodking_e2e_2026-06-07]]`.

### 0.2 ⚖️ CONTRATS PORTEURS (toute tâche les respecte)
1. **PRIX = SSOT NF525 backend.** Tout prix vit sur un construct catalogue (`Item`/`ItemVariation`/`ItemExtra`/`ItemAddon→addonItem`), calculé par `PricingService` (frozen). Le builder wizard ne porte JAMAIS un champ prix sur un step (`ComposerStepRequest.php:32` `'price'=>['prohibited']`). Tripwire : un champ `price` proposé sur un step → STOP.
2. **SYNC = Outbox + snapshot_version, pas d'invention.** La propagation catalogue/stock existante : event in-process (`CatalogChanged`, `ItemAvailabilityChanged`, `StockLevelChanged`) → listeners `Persist*ToOutbox` + `BumpMenuSnapshotOnItemAvailabilityChanged` + `InvalidateKioskMenuCacheOnItemAvailabilityChanged` → surfaces consomment `snapshot_version` (`MenuProjectionService.php:324`). Le câblage réel catalogue vit dans **`EventServiceProvider.php:221-291`** (bridge variation/extra/StockLevelChanged → outbox + invalidation ; `SYNC_CONTRACT.md §3` ne documente que les events ORDER → tâche T-C1.5 l'étend). `KioskAppComponent.vue:548-556` (frozen) écoute DÉJÀ `CatalogChanged`/`ItemAvailabilityChanged` — la sync borne marche sans toucher frozen. **Tripwire** : étendre le payload `CatalogChanged` = SHARED ZONE (`SYNC_CONTRACT.md §8`) → LOCK requis, à éviter. Toute nouvelle mutation CMS emprunte ce canal — pas de mécanisme parallèle.
3. **FROZEN intouchables sans LOCK+gate** (CLAUDE.md §7) : `pos-wizard.js`, `admin-pos-v4.blade.php`, `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`, `PaymentComponent.vue`, `PricingService.php`, fiscal services, `BranchScope`, `OrderStateMachine`, `IdempotencyKeyMiddleware`. La caisse : **analyser/comprendre OK, configurer par-dessus OK (data-driven via `composer_profile`), modifier = gate owner**.
4. **SSOT menu = DB items (45 items V1 Le Cayenne).** JAMAIS inventer un produit/catégorie (CLAUDE.md §3bis).
5. **Authz + isolation (précisé post-RED)** : items CRUD = `permission:items_*` (`ItemController:31-35`) mais catégories CRUD = **`permission:settings`** (`ItemCategoryController:27-38`) → décision RBAC en Wave 0 (un gérant sans `settings` ne peut pas gérer les catégories — aligner ou documenter). `Item`/`ItemCategory` ne sont PAS branch-scopés ; BranchScope ne s'applique qu'à `ItemBranchAvailability`/`StockLevel`. Idempotency : les routes catalogue existantes n'en ont pas — les nouveaux endpoints suivent le pattern existant (pas d'idempotency unilatérale), décision documentée Wave 0.

### 0.3 Décisions de cadrage (anchored)
- **Sous-catégories : EXISTENT déjà côté schéma, INVISIBLES côté surfaces.** `item_categories.parent_id` (migration `2026_04_18_120001`), relations `parent():159`/`children():164` + garde profondeur 2 niveaux (`ItemCategory.php:159-193`, enforcement `ItemCategoryHierarchyService`). MAIS : `ItemCategoryResource` n'émet PAS `parent_id`, `MenuProjectionService::forChannel:69-76` projette toutes les catégories À PLAT, les 45 items V1 n'ont aucune sous-catégorie, et le composer matche `item_category_id` EXACT (zéro héritage parent, grep `parent` dans `app/Services/Composer/` = vide). P1/P2 = exposer + fiabiliser + trancher l'héritage (G-0c) et le rendu borne, PAS créer le schéma.
- **« État stock » a 2 mécanismes distincts** (ne pas les confondre) : (a) **rupture manuelle** par construct = `ItemBranchAvailability` + toggles `AvailabilityController` (`:45` item, `:157` extra, `:187` variation) ; (b) **stock compté** = `StockLevel`/`StockMovement` + décrément commande (`StockService::decrementForOrder:27`) + bascule auto rupture au franchissement de seuil (`StockService::syncItemAvailabilityForStockLevel:162`). La vue hiérarchique doit refléter LES DEUX.
- **Surfaces admin existantes** : Catalog Studio `/admin/items` (`CatalogStudioComponent.vue`, 971 l., sidebar catégories + produits), Stock dashboard `/admin/stock-rupture-dashboard` (`StockRuptureDashboardComponent.vue`, 649 l.), composer builder `/admin/items/:id/composer` (`itemRoutes.js:94`). Le GOAL **enrichit ces surfaces**, ne crée pas une 4e page concurrente sans gap-analysis préalable (Wave S1).
- **Builder wizard** : décisions du plan P3 conservées — « type==catégorie » (item_type = flag diététique, pas un axe wizard), box = bundle prix-plein (G-0b=A résolu owner 2026-06-08), POS render = gate G-4, flag `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` flip = gate owner (`[[reference_composer_wizard_hinge_2026-06-07]]`).

### 0.4 Critères de convergence (rejet si non tenu)
Raw label visible · layout cassé · console error · toute touche frozen sans LOCK · champ prix sur step · acceptance sans chemin de test · NF525 chain modifiée (`fiscal:verify-chain`) · sync non prouvée live (mutation admin → surface kiosk/POS SANS reload manuel ou via mécanisme documenté) · persistance non prouvée (reload → donnée toujours là) · 2 cycles convergence à findings différents → re-loop. **Done = production-perfect.** test-e2e (skill) obligatoire à chaque phase : preuve technique + visuelle (screenshots Read+analysés) + sync + persistance/historique.

---

## §1 — MAP PRINCIPAL (3 parties, anchors vérifiés 2026-06-10 @ 3ce18f767)

| # | Partie | Maturité | Anchors clés (vérifiés) | Frozen ? |
|---|---|---|---|---|
| P1 | Stock hiérarchique (cat→sous-cat→produit→état) + sync | dashboard plat existant, hiérarchie absente de la vue | `StockRuptureDashboardComponent.vue` (649 l.) ; `StockRuptureDashboardController.php:50,87,207,294` (`catalogOverview`) ; `AvailabilityController.php:45,111,157,187,220` ; `StockService.php:27,35,162` ; `ChoiceAvailabilityResolver.php:23,110,124` ; `AvailabilityService.php:40,99,172,196,216` ; `ItemBranchAvailability` + `StockLevel` models | non-frozen |
| P2 | CRUD catalogue (catégorie/sous-cat/produit) | CRUD backend complet, UX hiérarchie + delete-safety à valider | routes `api.php:373-382` (item-category CRUD+sort+import/export) ; `ItemCategoryController.php:40-122` ; `ItemController.php:44-252` (store/update/destroy/duplicate/changeImage/import) ; `CatalogStudioComponent.vue` ; `ItemCreateComponent.vue` + `wizard/ProductCreateWizardComponent.vue` ; `ItemCategory.php:161-193` (parent/children) | non-frozen |
| P3 | Builder Wizard caisse+borne | backend builder ✅ (profiles/steps/versions/templates/publish), media+turnkey+box+10-vrais-wizards à faire | `plans/GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` §1 (7 sous-systèmes A-G, anchors re-confirmés : `ComposerProfileController/StepController`, `ComposerProfileService/StepService/Projection/Diff/TemplateService`, routes `api.php:766-792`, `ProductComposerEditorComponent.vue` + 5 composants composer, modèles `ItemWizardProfile/Step/StepVersion`) | render kiosk steps non-frozen ; `KioskWizardComponent`+`pos-wizard.js` frozen (gates) |

**Tests existants clés (lock points)** : `tests/Feature/Catalog/` (14 fichiers : `CatalogChangedDispatchTest`, `CategoryRenameSyncTest`, `ItemDeletionWithOrderHistoryTest`, `ItemUpdateInvalidatesKioskCacheSentinelTest`, `PhotoEndToEndKioskInvalidationTest`…) · `tests/Feature/Composer/` (13+) · `tests/Feature/Stock` + `tests/Feature/Availability` · Vitest : `stockRuptureDashboardComponent.spec.js`, `adminAvailabilityToggle.spec.js`, `itemListBranchAvailability.spec.js`, `productComposerEditor.spec.js`, `kioskWizardGenericComposer.spec.js` · e2e : `goal-pageby-stock-2026-05-18.spec.js`, `iter15-stock-cascade-regression.spec.js`.

## §2 — SÉPARÉ / HORS-PÉRIMÈTRE
- **Mobile RN + Web standalone** : non câblés (mandate owner), hors scope.
- **POS `pos-wizard.js` + `admin-pos-v4.blade.php`** : frozen « design parfait ». La caisse consomme le `composer_profile` data-driven derrière `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` (default FALSE) — le flip et tout render box POS = gates owner (§G).
- **Legacy menu-ratio kiosk** (`KioskStepMenuComponent` + `config/kiosk.menu_pricing`) : préservé intact (G-2 du plan P3).
- **Ingredients/recettes** (`app/Services/Ingredients/`) : lecture seule pour P1 (la rupture par ingrédient existe via `InvalidateMenuProjectionOnIngredientChange`) — pas de refonte recette dans ce GOAL.

---

## §3 — PARTIE 1 : STOCK HIÉRARCHIQUE + SYNC (waves S)

### Contract
Le gérant voit et pilote, dans UNE navigation hiérarchique : catégorie → sous-catégorie → produits (→ variations/extras/addons) → état (en stock / rupture / stock compté restant), et chaque bascule se propage à POS + Borne + KDS sans reload manuel (mécanismes existants : outbox + snapshot_version + invalidation cache kiosk + handlers live POS `posItemAvailabilityHandler`).

### Sub S.1 — Gap-analysis + design de la vue hiérarchique (read-only d'abord)
- T-S1.1 Audit visuel + fonctionnel des 2 surfaces existantes (`/admin/stock-rupture-dashboard`, `/admin/items` Catalog Studio) : captures Playwright, inventaire de ce qui manque vs besoin owner (hiérarchie sous-catégories ? états par variation/extra visibles ? compteurs stock ?).
  • anchor: `StockRuptureDashboardComponent.vue:1-649`, `CatalogStudioComponent.vue:1-971`, `StockRuptureDashboardController::catalogOverview:294`
  • acceptance: rapport `reports/test-e2e/cms-gestion-2026-06-10/S1-GAP-ANALYSIS.md` + screenshots analysés (Read) ; décision design UNE surface cible (enrichir Studio OU dashboard) justifiée
- T-S1.2 Design UI (skills design + palette Cayenne §3bis : `#F4501E`/`#FFB800`/light) : arbre catégorie→sous-cat→produits avec badge état (vert en-stock / rouge rupture / compteur si StockLevel), toggles inline, filtres (rupture seulement, par catégorie).
  • acceptance: maquette/spec dans le rapport S1 + validation RED-team design (dispute UX)

### Sub S.2 — Implémentation vue hiérarchique
- T-S2.1 Backend : **EXTEND-ONLY** de `catalogOverview:294` (déjà ≤5 requêtes, categories→items+overrides+buckets — PAS de nouvel endpoint doublon) : ajouter `parent_id` au payload + détail constructs PAR item (aujourd'hui dédupliqués par nom de groupe) via `ChoiceAvailabilityResolver::snapshotForItems:23`, sans N+1.
  • anchor: `StockRuptureDashboardController.php:294`, `ChoiceAvailabilityResolver.php:23-110`, `ItemCategory.php:159-167`
  • acceptance: `(test À CRÉER tests/Feature/Stock/HierarchicalStockOverviewTest.php)` — arbre 2 niveaux, états corrects, 0 N+1 (assertions query-count), états `ItemBranchAvailability`/`StockLevel` scoped branch 1
- T-S2.2 Frontend : rendu arbre + badges + toggles (réutilise `AvailabilityToggleComponent.vue` + endpoints toggle existants `:45/:157/:187`). ⚠️ `tests/js/stockRuptureDashboardComponent.spec.js` = **sentinel source-string** (regex sur le .vue, verrouille « no bulk endpoint » + test-ids V2) — la refonte le RÉÉCRIT explicitement, ne « l'étend » pas.
  • anchor: `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue`, `AvailabilityToggleComponent.vue`
  • acceptance: `(test À CRÉER tests/js/hierarchicalStockTree.spec.js)` **mount-level** (rendu arbre + toggle comportemental) + sentinel réécrit GREEN ; visual e2e (badge change instantané après toggle)
- T-S2.3 Décision design **bascule niveau catégorie vs throttle** : toggle catégorie = fan-out N produits, limiter `menu-availability` = 60/min prod (`RouteServiceProvider:181`) → choisir bulk-endpoint dédié OU fan-out séquentiel borné OU raise documenté. Décision Wave S1, impl ici.
  • acceptance: `(test À CRÉER tests/Feature/Stock/CategoryBulkToggleThrottleTest.php)` — bascule catégorie complète sous le plafond
- T-S2.4 i18n FR : toutes nouvelles clés dans `resources/js/languages/fr.json` (+en) namespace `stock_mgmt`, `npm run i18n:audit` propre.
  • acceptance: `tests/Feature/Stock/StockDashboardI18nIntegrityTest.php` GREEN + 0 raw label au visual

### Sub S.3 — Preuve de sync cross-systèmes (le cœur de la demande owner)
- T-S3.1 E2E live (harness :8766) : toggle rupture produit en admin → vérifier kiosk (menu cache invalidé, produit grisé/retiré — `InvalidateKioskMenuCacheOnItemAvailabilityChanged`), POS (`posItemAvailabilityHandler.spec.js` pattern live), retour en-stock idem. Variation + extra idem (`ComposerProfileProjectionVariationRuptureTest` = lock point projection).
  • anchor: listeners `app/Listeners/{InvalidateKioskMenuCache,BumpMenuSnapshot}OnItemAvailabilityChanged.php`, `MenuProjectionService.php:324`
  • acceptance: `tests/e2e/(À CRÉER) cms-s3-stock-sync-live.spec.js` GREEN + captures avant/après analysées + tests précis GREEN : `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`, `tests/Feature/Stock/WizardOptionStockSyncTest.php`, `tests/Feature/Availability/StockReleaseTest.php`
- T-S3.2 Stock compté : commande (e2e :8766) décrémente `StockLevel`, franchissement de seuil bascule la rupture auto + propage (boundary `StockService:451`).
  • acceptance: test Feature existant `tests/Feature/Stock/` filtré GREEN + scénario e2e décrément→rupture auto→kiosk reflète

**Checkpoint Wave S** : 6 points (§X) + sync prouvée live = condition de clôture.

---

## §4 — PARTIE 2 : CRUD CATALOGUE (waves C)

### Contract
Le gérant ajoute/modifie/supprime catégorie, sous-catégorie et produit depuis l'admin, avec persistance prouvée, garde-fous de suppression (historique commandes, wizard profiles liés, enfants), et propagation aux surfaces.

### Sub C.1 — CRUD catégorie + sous-catégorie
- T-C1.1 Audit + complétion UI : création/édition catégorie avec choix parent (sous-catégorie), tri (`sortCategory:93`), statut, images — dans Catalog Studio sidebar.
  • anchor: `ItemCategoryController.php:51,71,82,93`, `CatalogStudioComponent.vue:28` (sidebar-head), garde 2-niveaux `ItemCategory::193`
  • acceptance: `(test À CRÉER tests/Feature/Catalog/CategoryCrudHierarchyTest.php)` — store avec parent_id, refus profondeur 3, update, sort ; Vitest sidebar étendu
- T-C1.2 Delete-safety catégorie — **P1 RED : la primitive actuelle est destructrice.** `ItemCategoryService::destroy:165-183` fait `SET FOREIGN_KEY_CHECKS=0` avant delete → bypasse le `cascadeOnDelete` de `item_wizard_profiles` (migration `2026_05_05_000020:17`) et le `nullOnDelete` de `wizard_profile_id` → profils orphelins + `parent_id` enfants dangling. Fix : supprimer le toggle FK_CHECKS, suppression bloquée/guidée si items actifs, enfants, ou wizard profile lié ; le dialog FR propose le **détachement** du wizard (évite le deadlock catégorie↔profil, cf. T-W5b).
  • anchor: `ItemCategoryService.php:165-183` (la vraie cible), `ItemCategoryController::destroy:82`, `app/Models/ItemWizardProfile.php`
  • acceptance: `(test À CRÉER tests/Feature/Catalog/CategoryDeleteSafetyTest.php)` — incl. régression « aucune désactivation FK » + visual dialog
- T-C1.3 Sync rename/CRUD catégorie → surfaces (lock existant `CategoryRenameSyncTest`) + sidebar kiosk images par catégorie (fix récent `e5067d464` non régressé).
  • acceptance: `tests/Feature/Catalog/CategoryRenameSyncTest.php` GREEN + e2e kiosk sidebar
- T-C1.4 **Rendu sous-catégorie sur les surfaces** : émettre `parent_id` dans `ItemCategoryResource` (aujourd'hui absent → la sidebar ne peut pas construire l'arbre) + décision rendu borne (nested / fusionné dans le parent / plat assumé — `MenuProjectionService::forChannel:69-76` projette à plat).
  • anchor: `app/Http/Resources/ItemCategoryResource.php`, `MenuProjectionService.php:69-76`
  • acceptance: `(test À CRÉER tests/Feature/Catalog/CategoryHierarchyProjectionTest.php)` + e2e visual kiosk sidebar avec sous-catégorie
- T-C1.5 Doc : étendre `SYNC_CONTRACT.md §3` aux events catalogue (`CatalogChanged`/`ItemAvailabilityChanged`/`StockLevelChanged` → `EventServiceProvider:221-291`).
  • acceptance: section ajoutée, revue RED

### Sub C.2 — CRUD produit
- T-C2.1 Audit + polish du flow création produit (`ProductCreateWizardComponent.vue` + `ItemCreateComponent.vue`) : champs requis, catégorie/sous-catégorie, prix, image (`changeImage:186`), TVA, canaux (`2026_04_16_200000_add_channel_columns`), duplicate (`:156`, lock `ItemDuplicationTest`).
  • anchor: `ItemController.php:134,147,156,166,186`, `wizard/ProductCreateWizardComponent.vue`
  • acceptance: `tests/Feature/Catalog/ItemDuplicationTest.php` + `(test À CRÉER tests/Feature/Catalog/ItemCrudFullFlowTest.php)` — create→show→update→persistance reload
- T-C2.2 Delete-safety produit : historique commandes préservé (lock existant `ItemDeletionWithOrderHistoryTest`), wizard profile per-item lié, stock levels orphelins nettoyés proprement.
  • acceptance: `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php` GREEN + extension wizard-profile-linked
- T-C2.3 Sync produit → kiosk/POS : update produit invalide cache kiosk (locks `ItemUpdateInvalidatesKioskCacheSentinelTest`, `PhotoEndToEndKioskInvalidationTest`) ; preuve live e2e :8766 (édite prix/nom/photo → borne reflète).
  • acceptance: les 2 sentinels GREEN + `tests/e2e/(À CRÉER) cms-c2-catalog-sync-live.spec.js` + captures

**Checkpoint Wave C** : CRUD complet prouvé persistant + synchronisé, suites Catalog GREEN, visual gate.

---

## §5 — PARTIE 3 : BUILDER WIZARD CAISSE + BORNE (waves W) — PAR RÉFÉRENCE

**⚠️ RE-SCOPE POST-PIVOT SPINE (vérifié primary-source 2026-06-10 @ 7ebb1f252)** — l'essentiel des waves est DÉJÀ LIVRÉ sur la spine :
- **W1 DONE** : `ItemVariation` porte `description`+`image_path` (tags `[W1]`), thumb precedence stored-first
- **W2 DONE** : `ComposerProfileProjection` émet image+description par choix, prix exclu NF525 (`:98-103,128-130,171`)
- **W3 DONE (core)** : `allow_repeat` exposé dans `ComposerStepFormPanel.vue` (8 occ.) ; re-edit UI page perso livrée (`70f176abc`)
- **W4 DONE** : templates turnkey + **16 wizards réels publiés** (6 catégorie : Sandwich Cayenne 5 steps / Galette 4 / Sandwich Classique 5 / Burgers 4 / Tacos 4 / Bols Gourmands 2 ; + 10 item-level) via `ProvisionCayenneWizardsCommand`
- **W5 DONE** (page perso create+edit) · **W6 DONE (borne)** : `KioskStepGenericChoicesComponent` rend image+desc, box routé générique ; parité borne convergée (`GOAL_WIZARD_E2E_PARITY_2026-06-09.md`)
- **RESTE P3 exécutable** : (a) **T-W5b suppression d'un wizard ENTIER** (aucun `destroy` profil sur la spine — re-vérifié) ; (b) **G-0c héritage sous-catégorie** (décision + warning UI, lié C1) ; (c) audit visuel/UX builder owner-grade. **Gates owner (hors exécution autonome)** : GATE-W6 caisse = `renderGenericChoicesStep` dans `pos-wizard.js` FROZEN + flip flag (LOCK).

**Document de référence** = `plans/GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` + `plans/GOAL_WIZARD_E2E_PARITY_2026-06-09.md` (présents sur la spine). Séquencement original (historique, statuts ci-dessus priment) :

| Wave P3 | Contenu (réf. plan wizard) | Frozen ? |
|---|---|---|
| W0 | Décisions G-0 (type==catégorie ✅, modèle save draft-publish, per-item flag policy) + **G-0c héritage wizard parent→sous-catégorie** (défaut proposé : PAS d'héritage + warning UI au déplacement d'items hors d'une catégorie à wizard — le composer matche `item_category_id` EXACT `ComposerProfileService:106-109`, déplacer un item en sous-catégorie DÉTACHE silencieusement son wizard) + **unpublish-vs-delete profil** + baselines. Note : fix orphelin « publish silent no-op » DÉJÀ présent @ 3ce18f767 (`ProductComposerEditorComponent.vue:955`) — vérifier seulement le 2e fix (inheritance catégorie au render) | non |
| W1 | Media constructs : migration additive description+image sur `item_variations`/`item_extras` + thumb precedence stored>config | non |
| W2 | Projection enrichie (image/desc/prix read-only echo) `ComposerProfileProjection::choices:75-170` | non |
| W3 | Surface édition option dans builder (binding vers endpoints construct existants `api.php:734-749`) **+ T-W3.3 (P1 RED) : exposer `allow_repeat` + presets « Choix unique / Choix multiple » dans `ComposerStepFormPanel.vue`** (le backend l'accepte `ComposerStepRequest.php:25` mais AUCUN contrôle UI — grep vide sur 322 l. ; sans ça l'owner ne configure pas la logique des choix sans dev) | non |
| W4 | Templates turnkey (`source_ref` non-vide, `ComposerTemplateService.php:46`) + **câbler les ~10 VRAIS wizards Le Cayenne** + modèle de sauvegarde (race version/snapshot `ComposerStepService:109-130`) | non |
| W5 | Page personnelle/libre = construct à la volée (`PersonalPageCreatesConstructTest` À CRÉER) **+ T-W5b (P1 RED) : suppression d'un wizard ENTIER** — demande owner explicite, aujourd'hui inexistante (`ComposerProfileController` n'a pas de `destroy`, routes = unpublish + DELETE step seulement). Décision W0 : unpublish-vs-delete ; impl = DELETE profile (ou detach + purge orphelins — `ownerType()='orphan'` existe) + `(test À CRÉER tests/Feature/Composer/ComposerProfileDeleteTest.php)` | non |
| W6 | Box bundle prix-plein (G-0b=A) : escape-hatch `KioskStepGenericChoicesComponent` (id-keyed), découverte par `role='menu_component'`, AddonRoleBindingSentinel GREEN | non (G-3 non déclenché) |
| W7 | Parité per-item + disposition piège `resolveForItem` CATEGORY-WINS mort (`ComposerProfileService:104-120`) | non (flip prod = G-5) |
| W8 | Gates frozen : GATE-G PricingService inheritance (G-1), POS box render `pos-wizard.js` (G-4) | **OUI — owner LOCK requis, documentés PENDING, pas d'exécution sans contreseing** |

**Ajustements 2026-06-10 :**
- Le **POS** (demande owner « une seule page, frozen, configurer par-dessus ») est servi par : (a) l'analyse complète déjà faite (`pos-wizard.js` data-driven via `composer_profile`, hinge documenté), (b) le builder commun W0-W7 (le profil publié EST la config caisse), (c) le flip `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` + render box POS = G-4/G-5 owner. **Aucune ligne de `pos-wizard.js` modifiée dans ce GOAL.**
- La **borne** (multi-pages, ajout/suppression/modif de pages, logique single/multi/payant) est couverte par : steps CRUD (`min_select`/`max_select`/`allow_repeat` = single/multi ; options payantes = prix sur construct, jamais sur step), W4 (10 vrais wizards), W5 (page libre), W6 (box).
- **Reprendre les 2 fixes orphelins** de `[[project_wizard_dynamic_exec_2026-06-08]]` (inheritance catégorie au render + publish silent no-op `window.confirm`) : vérifier s'ils sont dans `3ce18f767`, sinon ré-appliquer en W0 (ils étaient écrits sur disque non-commités, session TCC-bloquée).

---

## §A — ARMÉE D'AGENTS (fan-out)

| Rôle | Type | Tools | Quand |
|---|---|---|---|
| Architect | Plan | RO | début de chaque wave (cohérence contrats §0.2) |
| Implementer | general-purpose | Edit+Bash | 1 par sub-system, JAMAIS 2 en // sur même fichier |
| DBA | general-purpose | RO | W1 (migration), S2.1 (N+1, index arbre) |
| Security | general-purpose | RO | S2/C1/C2 (authz permission:items_*, BranchScope, idempotency), W5 |
| UX/A11y + Design | general-purpose | RO+axe | S1.2, C2.1, W3/W4/W6 (palette Cayenne, WCAG) |
| QA Visual | general-purpose | Playwright | chaque wave frontend (captures + Read) |
| RED Visual | general-purpose | RO | // QA Visual (dispute indépendante des captures) |
| RED-team | general-purpose | RO | après chaque commit implementer, AVANT déclaration DONE |

Dispatch : 5 specialists RO = 1 seul message multi-Agent (parallèle). Rapports persistés `reports/test-e2e/cms-gestion-2026-06-10/<wave>-<role>.md` (synthèse depuis disque, survit aux interruptions). Cap ~1200 mots/agent. **Verify-before-report obligatoire** (tout P0/P1 cité = file:line + grep confirmé, sinon REJECTED).

## §X — VAGUES DE CONVERGENCE (ordre + checkpoints)

**Ordre (corrigé post-RED) :** Wave 0 (pre-flight + bootstrap worktree + décisions) → **C1** (CRUD catégorie/sous-catégorie D'ABORD — S2 a besoin de sous-catégories réelles pour l'arbre, les 45 items V1 sont plats ; pas de seed inventé §3bis) → **S1→S2→S3** (stock) → **C2** (CRUD produit) → **W0→W7** (builder, W8=gates) → **Wave F** (convergence finale cross-surface). W4 (10 vrais wizards) APRÈS C1 = catégories stables (`applyTemplateToCategory` + `availableSourcesForCategory` 422-si-catégorie-vide).
**Parallélisme :** défaut séquentiel. Autorisé : audits RO en // dans chaque wave ; S et C partagent Catalog Studio/ItemCategory → PAS de chevauchement implementer ; W1-W2 (schéma composer) disjoints de S2 (stock UI) → chevauchement lecture OK, 1 implementer/fichier.
**Wave 0 pre-flight :** baselines (PHPUnit count, `audit_logs` count+last_hash, Vitest count) ; backup branche + DB dump ; harness :8766 up ; décisions W0 ; vérif des 2 fixes orphelins (§5).
**Checkpoint par wave (6 pts) :** tasks PASS · frozen-diff = 0 (`git diff --stat <start>..HEAD -- <13 frozen>`) · NF525 chain inchangée · visual gate tiré si frontend · RED dispute clos · BRAIN §2/§3 + commit checkpoint.
**test-e2e (skill) par phase** : fin de S3, C2, W4, W6, et Wave F — preuve technique+visuelle+sync+persistance, adversarial supervisor, loop jusqu'à vert.
**Interrupt-resume :** commit WIP `wip(<wave>)` + manifest `reports/test-e2e/cms-gestion-2026-06-10/INTERRUPT_<wave>.md` + BRAIN §2.
**Convergence-failure :** 3 heals même cluster → STOP → Plan agent → `STUCK_<wave>.md` → surface owner (pas d'auto-pick).
**Wave F finale :** PHPUnit large + Vitest full + e2e cross-surface (admin crée catégorie+produit+wizard → borne commande dessus → KDS reçoit → rupture → borne retire) ; audit final d'amélioration (l'owner demande une boucle améliorative explicite) ; 2 cycles identiques = converged.

## §G — OWNER GATES (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G-0c | Héritage wizard parent→sous-catégorie (défaut : NON + warning UI) + test croisé `(À CRÉER tests/Feature/Composer/CategoryHierarchyWizardResolutionTest.php)` | Owner | confirm défaut | Wave 0 / BRAIN §2 | PENDING — défaut appliqué si silence |
| G-1 | GATE-G `PricingService` enforce composer catégorie-hérité (frozen) | Owner | contresign LOCK | LOCK doc **À RÉGÉNÉRER via skill `lock-plan` en W8** (l'original `GATE-G-…LOCK-REQUEST.md` vit sur la lignée wizard-exec non mergée, absent @ 3ce18f767) | PENDING |
| G-2 | Préserver legacy menu-ratio kiosk intact (box générique À CÔTÉ) | Owner | confirm no-touch | Wave W6 | PENDING (défaut = préservé) |
| G-4 | POS box render (frozen `pos-wizard.js`) | Owner | LOCK + waiver « design parfait » | Wave W8 | PENDING |
| G-5 | Flip prod `FEATURE_WIZARD_PER_ITEM_DEMO` + `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` | Owner | go env-flags | Wave W7/W8 | PENDING |
| G-6 | Stockage media option (Spatie vs path) | Owner | choix (défaut proposé : path simple cohérent `config/menu_images.php`) | Wave W1 | PENDING — défaut appliqué si silence, réversible |
| G-7 | Suppression catégorie/produit en PROD réelle (data réelle Le Cayenne) | Owner | confirmation par cas | runtime | STANDING (delete-safety C1.2/C2.2 = garde-fou logiciel) |

**Protocole gate-pending :** S/C/W0-W7 exécutables SANS aucun gate frozen. Seule W8 attend. Wave F livre production-ready hors-W8, W8 documentée prête-à-contresigner.

## §R — RÉFÉRENCES
- P3 exécutable : `plans/GOAL_WIZARD_DYNAMIC_BUILDER_2026-06-08.md` (le rapport `ULTRA_AUDIT_VERDICT_2026-06-08.md` cité dedans vit sur la lignée wizard-exec, ABSENT @ 3ce18f767 — anchors re-vérifiés directement 2026-06-10, sondage 10/10 CONFIRMÉ)
- Sync : `SYNC_CONTRACT.md` · Gouvernance : `CONSTITUTION.md`, `SYSTEM_MAP.md`, `PARALLEL_PROTOCOL.md`
- Mémoire : `[[reference_composer_wizard_hinge_2026-06-07]]`, `[[project_wizard_dynamic_exec_2026-06-08]]`, `[[reference_e2e_harness_foodking_e2e_2026-06-07]]`, `[[feedback_shared_worktree_git_commit_collision_2026-06-09]]`
- Skills : `ultra-audit-profond` (pipeline/tâche), `test-e2e` (phase), `superpower-gstack`, `lock-plan` (si gate), `verify-before-report`, `checkpoint-commit`

## §S — STATUT FINAL (2026-06-10, post-exécution + audit RED final)

**EXÉCUTÉ ET CONVERGÉ (branche `goal/cms-gestion-2026-06-10-spine`, 0 frozen, 0 P0)** :
- **C1** : sous-catégories bout-en-bout (sélecteur parent + hint G-0c, Resource `parent_id`, arbres Studio + liste settings, delete-safety service FK_CHECKS retiré + guards enfants/wizard 409, rename-sync lock vert) — preuve live :8767 (« ↳ Tacos Signature » DB id=21, guard FR à l'écran)
- **S2/S3** : rail stock hiérarchique (catalogOverview `parent_id`, buckets imbriqués) — **sync prouvée live** : toggle rupture → override DB + outbox `ItemAvailabilityChanged` + snapshot 2→3 → projection borne `available=false` → restore
- **C2** : cycle produit complet prouvé live (create 9,50 € → projeté borne ; edit 10,00 € → projeté ; delete → soft-deleted + retiré projection)
- **W5b** : suppression wizard entier (service 409-si-publié + lock anti-TOCTOU, DELETE route, bouton builder, détache catégorie) ; **G-0c pinné** (pas d'héritage parent→sous-cat, `CategoryHierarchyWizardResolutionTest`)
- **Heals audit final** : P1-1 profondeur-3 par re-parentage bloquée (backend+UI+tests) ; P1-3 presets « Choix unique/multiples » dans le builder ; P2-2 lock destroy ; P2-3 i18n ×5 ; P2-4 message guard
- **Suites** : Catalog 55✓ / Composer 118✓ / Stock 68✓ / Availability 5✓ + Vitest spécs CMS toutes vertes ; frozen diff = 0 sur tout le range.

**DÉCISIONS ACTÉES** : borne rend les catégories À PLAT (sous-catégorie = onglet propre si peuplée+active ; hiérarchie = outil d'organisation admin/stock) — revisiter si l'owner veut un rendu imbriqué borne. RBAC catégories = `permission:settings` (documenté, pas aligné silencieusement).
**BACKLOG (non bloquant, owner)** : P1-4 echo prix read-only des options DANS le builder (le prix est éditable aujourd'hui via la fiche produit — prouvé live sur /admin/items/show) ; P2-1 parentOptions sur liste paginée 50 (falaise >50 catégories) ; presets custom max=10 arbitraire.
**GATES OWNER PENDING** : G-1 (GATE-G PricingService), G-4/GATE-W6 (render POS frozen + flip flag), **G-5** (flip `FEATURE_WIZARD_PER_ITEM_DEMO` — requis aussi pour DELETE des 10 wizards item-level, pinné par test 404).

## §F — RÈGLE FINALE (DONE)
Le GOAL est atteint quand, **sans dev** : (P1) le gérant navigue catégorie→sous-catégorie→produits→état stock (manuel + compté) dans une vue hiérarchique propre (palette Cayenne) et chaque bascule se propage prouvée-live à borne/POS/KDS ; (P2) il crée/modifie/supprime catégories, sous-catégories et produits avec persistance prouvée, delete-safety et sync ; (P3) il compose/ajoute/modifie/supprime des pages wizard (single/multi/payant) sur item/catégorie/box, les ~10 vrais wizards Le Cayenne sont câblés avec vraies images, la page personnelle crée son construct, le tout rendu correct sur borne (POS = gates W8 prêtes à contresigner) ; **0 touche frozen sans LOCK, 0 prix sur step, NF525 chain intacte, suites PHPUnit+Vitest+e2e vertes, test-e2e par phase passé, audit final d'amélioration exécuté, convergence 2 cycles identiques.** Sinon : heal ou block — production-perfect ou rien.
