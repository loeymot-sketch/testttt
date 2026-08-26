# GOAL — ONB-08 STOCK, INGRÉDIENTS & DISPONIBILITÉ
## FoodKing — Onboarding commerçant · « est-ce vendable ? combien il m'en reste ? qu'est-ce que je rachète ? » — trois questions, un parcours, des mouvements validés, une rupture qui se propage en secondes

- **Slug** : `ONB08_STOCK_INGREDIENTS_DISPONIBILITE_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « stock & disponibilité » (`admin/{stock,ingredients,purchasing}/**`, `app/Services/{Stock,Ingredients,Purchasing,RawMaterials}/**`)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB08_STOCK_INGREDIENTS_DISPONIBILITE.md`
- **Port de session** : **8808** · **Persona** : Karim tombe en rupture de pain à 20 h ; la borne doit cesser de vendre les burgers en 10 s ; demain il veut savoir quoi racheter.

> **En cinq lignes.** Le problème : **cinq entrées de menu** (Produits & Stock, Conso & Stock, Ajustement stock, Ingrédients, Scan Facture) pour trois questions ;
> deux contrôleurs qui écrivent des mouvements **sans FormRequest** (`RawMaterialAdjustController:117`, `PurchasingScanController:51,208`, `setMaxDailyQty` sur `Request` nu) ;
> **11 articles vendables invisibles en cuisine** (`kds_station = none`) ; un « Ingrédient » qui n'est pas une table mais une façade sur 3 tables ; une zone **non auditée en direct**
> le 2026-08-26 (brief Z5 prêt). Le socle backend est solide (25 tests Stock : concurrence, idempotence, append-only, libération à l'annulation/remboursement, isolation par filiale).
> FINI = un parcours en trois questions, des mouvements validés et journalisés, une rupture propagée borne/caisse/KDS/web en < 10 s prouvés, seuils réglables (C1..C6).
> Hors : fiche produit (→ 02), sorties de stock caisse (`PosStockOutflow*`, voie CAISSE), IA d'extraction (→ 04). Premier geste : W0 puis exécuter le brief Z5 sur :8808.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb08-stock`, branche `goal/onb08-stock-2026-08-26`, depuis **HEAD**.
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8808` ; `.env.testing` ; liens durs ; `ReflectionClass(App\Services\Stock\StockService::class)` → worktree ; serveur 8808 ; `PLAYWRIGHT_BASE_URL`.
- Base partagée : les mouvements de stock sont **append-only** (`StockMovementsAppendOnlyTest`) — tout ajustement d'essai se fait sur une **matière/article de test `GOAL-ONB08`** puis est compensé à l'identique ; ⛔ jamais d'ajustement sur les 55 `stock_levels` réels ; jamais `migrate:fresh` ; `safe-test.sh --phpunit "Stock|Availability|Ingredients|Purchasing|RawMaterial"`.
- ⚠️ Vision OpenAI désactivée en local (`OPENAI_VISION_ENABLED=false`) : le scan de facture passe par `MockInvoiceVisionService` — prouver le pipeline, pas l'IA.
- Filet : `git branch backup/pre-onb08-2026-08-26` + `mysqldump foodking_e2e stock_levels stock_movements item_branch_availability`.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Un parcours en trois questions | `resources/js/components/admin/stock/{StockRuptureDashboardComponent,UnifiedStockViewComponent,RawMaterialAdjustComponent}.vue`, `admin/ingredients/{IngredientListComponent,IngredientUsageDrawer,IngredientAvailabilityToggleComponent}.vue`, `admin/purchasing/PurchaseScanComponent.vue`, `admin/items/CatalogHubComponent.vue` (onglet Stock — coordination ONB-02), `stockRoutes.js`, `admin/dashboard/StockLowAlertsWidget.vue` |
| S2 Mouvements validés | `app/Http/Controllers/Admin/{RawMaterialAdjustController,PurchasingScanController,AvailabilityController,StockRuptureDashboardController,UnifiedStockViewController,IngredientController}.php`, nouveaux `app/Http/Requests/Stock/{RawMaterialAdjustRequest,PurchasingScanRequest,PurchaseApplyRequest,MaxDailyQtyRequest}.php`, `app/Services/RawMaterials/**`, `app/Services/Purchasing/{PurchaseService,InvoiceClassificationService}.php`, `app/Models/{StockLevel,StockMovement,PurchaseDocument,PurchaseLine}.php` |
| S3 Rupture de bout en bout | `app/Services/Stock/{StockService,ChoiceAvailabilityResolver,UnifiedStockViewService}.php`, `app/Services/Ingredients/**`, `app/Models/ItemBranchAvailability.php`, `app/Http/Requests/Admin/{AvailabilityToggleRequest,ToggleExtraAvailabilityRequest,ToggleVariationAvailabilityRequest}.php` |
| S4 Seuils, alertes, stations | `stock_levels.threshold_low` (exposition), listeners `NotifyStockLowOnStockLevelChanged`, `StockRuptureAlertListener`, écran « articles sans poste » (coordination ONB-10 « Postes de cuisine »), `config/catalog_v15.php:160-170` |

| HORS | Porté par |
|---|---|
| Fiche produit, `ItemRequest` (`kds_station`, canaux), enum `KdsStation` | ONB-02 (ce GOAL **liste** les `none`, ONB-02/10 corrigent) |
| `PosStockOutflowController`, `PosStockOutflowModal.vue` (pertes/repas, voie CAISSE) | voie CAISSE — lecture pour cohérence des mouvements |
| Extraction de menu par IA, contrats Vision (réutilisés, non modifiés) | ONB-04 |
| Réglages typés (seuil bas, quota) — le mécanisme | ONB-05 (ce GOAL déclare) |
| Visibilité / renommage des 5 entrées de menu | ONB-05 |
| `PricingService` (lit `ChoiceAvailabilityResolver` — gelé), `OrderService`/`FrontendOrderService` (décrément — partagés §6) | jamais / coordination |

Zones à coordonner : `routes/api.php`, `stockRoutes.js`, `fr.json` (bloc `label.stock_*`).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (`PricingService`, `OrderService` partagé) · SCOPE-2 3 boucles · SCOPE-3 migration (aucune prévue ; toute colonne = G-DATA) · SCOPE-4 NF525 : les mouvements de stock ne touchent pas le fiscal, mais un décrément lié à une commande ne se « corrige » jamais rétroactivement · SCOPE-5 autre voie.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques** · instrument avant produit (une rupture « non propagée » se prouve par l'API menu ET le DOM).

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Parcours en trois questions | depuis le menu : « vendable ? » / « combien ? » / « racheter ? » atteints en ≤ 2 clics chacun ; libellés FR compris (test utilisateur = 5 questions de Karim) | **3/3** |
| C2 | Mouvements validés | 100 % des mutateurs stock/achats passent par une FormRequest ; 20 payloads invalides → 422 FR, 0 `SQLSTATE` | **100 % / 0** |
| C3 | Rupture propagée | article / extra / ingrédient indisponible → `GET /api/frontend/menu` (borne), `GET /api/admin/item` (caisse), KDS, projection web en < 10 s ; remise en dispo idem | **4/4**, < 10 s |
| C4 | Idempotence et journal | même ajustement rejoué → 1 mouvement ; chaque mouvement porte qui/quand/motif | **VRAI** |
| C5 | Seuils réglables | seuil bas global + par matière ; alerte dashboard cohérente avec `stock_levels` | **VRAI** |
| C6 | Articles sans poste | 11 vendables en `none` → 0 sans décision écrite (liste + affectation en lot via ONB-10) | **0** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · `tests/Feature/Stock/` = **25** (`StockConcurrentDecrementTest`, `StockMovementIdempotencyKeyUniqueTest`, `StockMovementsAppendOnlyTest`, `StockReleaseOnCancelTest`, `StockReleaseOnRefundTest`, `StockBranchIsolationTest`, `StockCrossSurfaceSyncTest`, `ManualEightySixStickyThroughRestockTest`, `ChoiceAvailabilityResolverIngredientRuptureTest`, `NotifyStockLowOnStockLevelChangedTest`, `StockRuptureAlertListenerSentinelTest`, `UnifiedStockViewServiceTest`, `WizardOptionStockSyncTest`, `StockScanRuptureCommandTest`, `ReconcileOrderReleasesCommandTest`, `StockDashboardI18nIntegrityTest`…) · `tests/Feature/Availability/` = 6 · `tests/Feature/Ingredients/` = 4 ·
DB : `stock_levels` **55**, `on_hand <= threshold_low` **0**, articles vendables `kds_station='none'` **11** (chaud 37 / froid 3 / bar 8).

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-NON-MESURÉ** — brief Z5 non exécuté le 26/08 : W1 le fait ; §2 = connu par le code.
- **C-CINQ-ENTRÉES** — 5 entrées de menu pour un domaine (Z0 §1 lignes 3-5, 7, 8) ; le GOAL du 13/08 (NAV1-01/02/03) planifiait leur audit, jamais fait. Tranché : **trois questions, un hub** (Produits & Stock devient le hub à onglets), les renommages/dé-cachages passent par ONB-05.
- **C-VALIDATION** — `PurchasingScanController.php:51-55` porte un commentaire de durcissement (« polyglot RCE finding ») **avec** validation inline : inline ≠ absent. Tranché : migrer vers FormRequest **sans affaiblir** la garde existante (test de caractérisation d'abord).
- **C-INGRÉDIENT** — « Ingrédient » = façade virtuelle (`IngredientService::TYPES = attribute|extra|addon`, id `"{type}:{id}"`, dédoublonnage par nom `:53-77`) ; l'écran laisse croire à une entité. Tranché : le vocabulaire dit la vérité (« composants du menu ») et le drawer d'usage (`IngredientUsageDrawer.vue`, `usageDetailsForGlobalId :156`) devient le pivot.

## §0.8 — Le commerçant-type et ses questions
Karim, 20 h 02, plus de pain : 1. « Comment j'arrête de vendre les burgers, là, tout de suite ? » 2. « La borne le sait quand ? » 3. « Il me reste combien de steaks ? »
4. « Je note mes livraisons où, et si je me trompe ? » 5. « Pourquoi mes desserts n'apparaissent pas en cuisine ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Parcours | **ÉCLATÉ (5 entrées)** | menu `BackendMenuComponent.vue:109,114,119,128,130` · `stockRoutes.js:19-28` · composants `admin/stock/*` (3), `admin/ingredients/*` (3), `admin/purchasing/*` (1), `CatalogHubComponent.vue:8-9,23-66` · widget `StockLowAlertsWidget.vue` | `StockDashboardI18nIntegrityTest` |
| S2 Mouvements | **VALIDATION INLINE / ABSENTE** | `RawMaterialAdjustController.php:44-47` (`MIN_TARGET = 0` « contrainte UI, pas service »), `:117` `$request->validate` · `PurchasingScanController.php:51-55,208` inline · `AvailabilityController.php:118` `setMaxDailyQty(Request)` nu · `StockRuptureDashboardController.php:50,92,212,299` (`run` = exécution de balayage) · `app/Services/RawMaterials/{RawMaterialStockService,RawMaterialConsumptionService,FoodCostService}.php` · `app/Models/StockLevel.php:25,35-41,82` · routes `api.php:396-409,411,417-425,435-437,388` | `StockMovementIdempotencyKeyUniqueTest`, `StockMovementsAppendOnlyTest`, `StockLevelSchemaTest` |
| S3 Rupture | **SOLIDE EN BACKEND, NON PROUVÉE EN DÉLAI** | `AvailabilityController.php:52,164,194,227` + requêtes `AvailabilityToggleRequest`, `ToggleExtraAvailabilityRequest`, `ToggleVariationAvailabilityRequest` · `app/Services/Stock/{StockService,ChoiceAvailabilityResolver}.php` · `app/Services/Ingredients/IngredientService.php:51,132,141,156` · `ItemBranchAvailability` (`max_daily_qty`, `daily_consumed_qty`, `daily_reset_at`, `manual_unavailable_since`) · routes `api.php:357-369` (`throttle:menu-availability`) | `StockCrossSurfaceSyncTest`, `StockRuptureAvailabilitySyncTest`, `ChoiceAvailabilityResolver*RuptureTest`, `ManualEightySixStickyThroughRestockTest`, `LazyDailyQuotaReconcileTest`, `ResetStaleDailyQuotaReenableTest`, `IngredientAvailabilityChangedAfterCommitTest`, `IngredientControllerToggleTest` |
| S4 Seuils / stations | **SEUIL PAR LIGNE, SANS ÉCRAN GLOBAL ; 11 `none`** | `stock_levels.threshold_low` · `NotifyStockLowOnStockLevelChangedTest`, `StockRuptureAlertListenerSentinelTest` · `config/catalog_v15.php:160-170` · `items.kds_station` | 2 |

**Sortie d'ancrage brute** : `ls tests/Feature/Stock | wc -l` → 25 · `Availability` → 6 · `Ingredients` → 4 · `grep -n "validate(" RawMaterialAdjustController.php PurchasingScanController.php` → `:117`, `:51`, `:208` · `grep -n "public function" AvailabilityController.php` → `toggle :52`, `setMaxDailyQty :118`, `toggleExtra :164`, `toggleVariation :194`, `showBranchAvailability :227` ·
`ls app/Services/Stock` → `ChoiceAvailabilityResolver, StockService, UnifiedStockViewService` · `ls app/Services/RawMaterials` → `FoodCostService, RawMaterialConsumptionService, RawMaterialStockService` · SQL : `none=11 | low=0 | stock_levels=55`.

# §2 — ÉTAT CONNU LE 2026-08-26 (non audité en direct — W1 rejoue `recon/_ZONES.md` § Z5)
**Connu vert (tests)** : décrément concurrent sûr, idempotence des mouvements, append-only, libération à l'annulation/remboursement, isolation par filiale, 86 manuel « collant » après réassort, quota journalier lazy + reset, rupture d'ingrédient propagée aux choix (résolveur), invalidation borne après commit.
**Connu à risque (code)** : validation inline ou absente sur ajustement / scan / quota ; `MIN_TARGET` « contrainte UI, pas service » (`RawMaterialAdjustController.php:46`) ; 5 entrées de menu ; 11 vendables en `none` ; seuil bas sans écran global ; widget stock-bas (faux-vide corrigé le 15/08, à re-prouver) ; vocabulaire « Ingrédients » trompeur.
**À mesurer W1 (brief Z5)** : rupture article/extra → délai réel borne/caisse/KDS ; quota ; scan facture mock → cibles → validation sur matière de test ; 4 écrans (compréhension) ; bords (quantité négative, texte, énorme, double soumission, deux onglets).

# §3 — SOUS-SYSTÈME 1 : UN PARCOURS EN TROIS QUESTIONS

## Sub 1.1 — Cartographie et forme cible
**Ancrages** : `BackendMenuComponent.vue:109-130`, `CatalogHubComponent.vue`, `stockRoutes.js`, les 7 composants de la voie.
**Tâches**
- **T-1.1.1** — Table écran → question répondue → données affichées → actions possibles (5 écrans + widget + modale POS en lecture) ; mesure du parcours de Karim (clics, changements d'écran) — MISSION §8.
  • test : (À CRÉER à `tests/js/stockEntryPointsInventory.spec.js`)
- **T-1.1.2** — Forme cible (G-HUB-STOCK) : hub « Stock » à trois onglets **Disponibilité** (86, quotas, composants en rupture — `AvailabilityToggle*`, `IngredientListComponent`), **Matières** (`UnifiedStockView` + ajustement), **Achats** (`PurchaseScan`) ; « Scan Facture » et « Ajustement stock » quittent le menu principal (fiche ONB-05) ; « Ingrédients » renommé « Composants du menu ».
- **T-1.1.3** — Implémenter le hub en réutilisant les composants ; aide contextuelle par onglet (une phrase : « ici vous arrêtez de vendre », « ici vous comptez », « ici vous notez ce que vous recevez »).
  • test : (À CRÉER à `tests/js/stockHubTabs.spec.js`) · visuel : `http://127.0.0.1:8808/admin/stock` (route retenue) à 1366/1024/768
  • au-delà : rechargement sur un onglet ; retour arrière ; onglet Achats sans clé IA (mock) → message honnête.
**Acceptation** : C1 = 3/3 · 2 tests VERTS · captures lues · G-HUB-STOCK tranché.

# §4 — SOUS-SYSTÈME 2 : MOUVEMENTS VALIDÉS ET JOURNALISÉS

## Sub 2.1 — FormRequests sans affaiblir les gardes
**Ancrages** : `RawMaterialAdjustController.php:44-47,117`, `PurchasingScanController.php:51-55,208`, `AvailabilityController.php:118`, `StockRuptureDashboardController.php:212` (`run`), `app/Models/StockMovement`, `StockMovementIdempotencyKeyUniqueTest`.
**Tâches**
- **T-2.1.1** — Caractérisation ROUGE : 20 payloads (quantité négative, texte, `1e9`, décimales, matière inexistante, matière d'une autre filiale, motif vide, fichier de scan `.php`/`.svg`/27 Mo, lignes de facture avec prix négatif, cible inexistante, double soumission, `max_daily_qty` négatif) → consigner (les gardes existantes doivent rester vertes : `ExcelFormulaInjectionGuardTest`, `FileUploadHardenedSentinelTest` côté sécurité).
  • test : (À CRÉER à `tests/Feature/Stock/StockMutationsEdgeCasesTest.php`)
- **T-2.1.2** — `RawMaterialAdjustRequest`, `PurchasingScanRequest`, `PurchaseApplyRequest`, `MaxDailyQtyRequest` : règles, bornes (`MIN_TARGET` porté par le service, pas seulement l'UI), messages FR, `authorize()` réel (cliquet `FormRequestAuthzDriftSentinelTest` inchangé ou resserré) ; contrôleurs délestés.
  • test : le même, VERT · C2
- **T-2.1.3** — Idempotence et traçabilité : clé d'idempotence sur ajustement et application d'achat (routes ajoutées à `config/idempotency.php` `required_routes` — ajout seul, coordination ONB-13) ; chaque `StockMovement` porte `user_id`, motif, source (écran/scan/caisse/commande).
  • test : `StockMovementIdempotencyKeyUniqueTest.php` (existant, étendre) + (À CRÉER à `tests/Feature/Stock/StockMovementTraceabilityTest.php`) · C4
- **T-2.1.4** — `StockRuptureDashboardController::run` (`:212`) : qui peut lancer un balayage, combien de fois, effet (`StockScanRuptureCommandTest` existant) — limiter et journaliser.
**Acceptation** : C2 = 100 % / 0 · C4 VRAI · 3 tests VERTS · question 4 de Karim = OUI.

# §5 — SOUS-SYSTÈME 3 : LA RUPTURE DE BOUT EN BOUT (avec chronomètre)

**Ancrages** : `AvailabilityController.php:52,164,194`, `ChoiceAvailabilityResolver.php`, `StockService.php`, `ItemBranchAvailability`, `SYNC_CONTRACT.md` (propagation), `tests/Feature/Stock/StockCrossSurfaceSyncTest.php`, `ItemUpdateInvalidatesKioskCacheSentinelTest` (Catalog).
**Tâches**
- **T-3.1.1** — Chronomètre : basculer un article de test en 86 → mesurer le délai jusqu'à `GET /api/frontend/menu` (borne), `GET /api/admin/item?branch_id=1` (caisse), KDS (résolveur), projection web ; puis remise en dispo ; consigner les 8 délais.
  • test : (À CRÉER à `tests/Feature/Stock/RupturePropagationDelayTest.php` — assertions sur l'invalidation, délai mesuré en E2E) + (À CRÉER à `tests/e2e/onb08-rupture-propagation.spec.js`) · C3
  • au-delà : bascule pendant qu'un panier borne contient l'article (devis refusé au commit avec message FR — `ProfilePublishMidCartRejectionTest` comme modèle) ; deux bascules en 1 s ; worker arrêté (propagation synchrone ?) ; rupture d'un extra utilisé par 12 articles.
- **T-3.1.2** — Quota journalier (`max_daily_qty`, `daily_consumed_qty`, reset) : parcours écran + preuve du reset (`LazyDailyQuotaReconcileTest`, `ResetStaleDailyQuotaReenableTest` existants) ; validation via `MaxDailyQtyRequest` (S2).
- **T-3.1.3** — Composants (« ingrédients ») en rupture : drawer d'usage → liste des articles impactés → bascule en lot ; vocabulaire des motifs (`ManualVsAutoReasonVocabularySentinelTest`) en FR.
  • test : `IngredientUsageDrillDownTest.php` (existant) + (À CRÉER à `tests/Feature/Ingredients/IngredientRuptureBulkImpactTest.php`)
**Acceptation** : C3 = 4/4 < 10 s · 3 tests VERTS · questions 1, 2 de Karim = OUI.

# §6 — SOUS-SYSTÈME 4 : SEUILS, ALERTES, STATIONS

**Tâches**
- **T-4.1.1** — Seuil bas : réglage global par défaut (déclaré via ONB-05) + seuil par matière dans l'onglet Matières ; alerte dashboard `StockLowAlertsWidget` = `stock_levels WHERE on_hand <= threshold_low` (0 aujourd'hui : prouver le non-faux-vide avec une matière de test).
  • test : `NotifyStockLowOnStockLevelChangedTest.php` (existant) + (À CRÉER à `tests/Feature/Stock/StockLowThresholdSettingsTest.php`) · C5
- **T-4.1.2** — Articles vendables sans poste : liste des 11 (`SELECT name FROM items WHERE deleted_at IS NULL AND status=5 AND kds_station='none'`) consignée ; affectation en lot = écran « Postes de cuisine » de ONB-10 ; ce GOAL fournit la requête et la sentinelle « 0 vendable en `none` sans motif ».
  • test : (À CRÉER à `tests/Feature/Stock/NoSellableItemWithoutStationSentinelTest.php` — sauté tant que G-STATIONS n'est pas tranché, avec motif) · C6
- **T-4.1.3** — Coût matière (`FoodCostService`) et consommation (`RawMaterialConsumptionService`) : ce que « Conso & Stock » montre, définitions (fiche ONB-07 dictionnaire), états vides.
**Acceptation** : C5, C6 · 2 tests VERTS · question 3, 5 = OUI.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur | données vides | volume | réseau/worker coupé | effet borne / caisse / KDS | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 86 (article/extra) | — | état relu | idempotent (`throttle:menu-availability`) | dernière bascule gagne | `availability_toggle` (`AvailabilityTogglePermissionTest`) | — | 59 articles | propagation synchrone prouvée | `RupturePropagationDelayTest` | remise en dispo | bascule ×10 en 1 s |
| Ajustement | annuler → 0 mouvement | idem | clé d'idempotence | conflit → message | `items` requis (à trancher : `items_edit` ?) | quantité vide → 422 | 55 lignes | — | stock affiché caisse | compensation = nouveau mouvement (append-only) | négatif, texte, `1e9`, 0,001 |
| Scan facture | annuler avant application → 0 | — | idem | — | `items_create` (`PurchasingScanController`) | image vide | 6 fichiers × 12 Mo | vision mock | matières incrémentées | application inverse | `.php`, `.svg`, prix négatif, cible inexistante |
| Quota journalier | — | — | idempotent | — | 403 | quota vide = illimité | — | reset lazy sans cron | borne bloque au quota | retirer le quota | 0, négatif, 1e9 |
| Seuil bas | — | — | — | — | `settings` | vide = défaut | — | — | widget | rétablir | négatif, > stock |

# §A — ARMÉE D'AGENTS
Architecte (hub, frontière stock/catalogue/caisse) · Sécurité (upload scan, formules Excel, IDOR sur `stock_levels` d'une autre filiale, `run`) · **SRE/Synchro** (propagation, cache borne, worker) · UX/A11y (hub, drawer, 3 gabarits) ·
**Psychologie commerçant** (urgence de 20 h : un bouton, une confirmation, une preuve ; « Ingrédient » trompeur) · **DBA** (append-only, index, isolation) · Implémenteur unique · ROUGE (rejoue le brief Z5 + chronomètre après chaque vague) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB08_STOCK_INGREDIENTS_DISPONIBILITE/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases, matière/article de test | séquentiel | — |
| **W1** | **Reconnaissance Z5** (brief) : chronomètre de rupture, scan mock, 4 écrans, bords ; livrable `recon/Z5_stock_ingredients.md` | fan-out lecture seule (≤ 2 navigateurs) | — |
| **W2** | S2 mouvements validés (T-2.*) — sécurité d'abord | séquentiel | ONB-13 pour `config/idempotency.php` (append) |
| **W3** | S3 rupture chronométrée (T-3.*) | séquentiel | — |
| **W4** | S4 seuils, alertes, stations (T-4.*) | séquentiel | G-STATIONS (avec ONB-10), réglage typé (ONB-05) |
| **W5** | S1 hub (T-1.*) | séquentiel | G-HUB-STOCK ; menu via ONB-05 |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Stock|Availability|Ingredients|Purchasing"`, Vitest, Playwright `tests/e2e/onb08-*.spec.js`, BRAIN | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-HUB-STOCK** | Hub « Stock » à trois onglets, « Ingrédients » → « Composants du menu », Scan/Ajustement hors menu principal | Propriétaire | choix + renommages | MISSION §6 (exécution menu : ONB-05) | EN ATTENTE — bloque W5 |
| **G-STATIONS** | Affectation des 11 articles vendables sans poste (liste fournie) | Propriétaire | poste par article | MISSION §6 + ONB-10 | EN ATTENTE — bloque T-4.1.2 |
| **G-IDEMP** | Ajout de 2 routes stock à `config/idempotency.php` | Propriétaire (via ONB-13) | accord | `GATE_LOG.md` | EN ATTENTE — bloque T-2.1.3 |
| **G-PERM-STOCK** | Permission dédiée pour l'ajustement et le balayage (`items` aujourd'hui) | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-2.1.4 |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `SYNC_CONTRACT.md` · `SYSTEM_MAP.md §5-6` · `CLAUDE.md §3ter, §9` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-08) · `recon/_ZONES.md` (Z5) · `recon/Z0_carte_dashboard.md §1` · `recon/Z0_modele_catalogue_wizard_reglages.md §A.1` · `recon/Z7_equipement_ops.md` (postes) ·
`PROJECT_BRAIN.md §2` (11 articles `none`) · `plans/GOAL_CUISSON_ET_STOCK_VIANDE_2026-08-06.md` · `plans/GOAL_RUPTURE_CARNET_AUDIT_2026-07-15.md` · `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (NAV1-01..03) · `plans/GOAL_CONFORT_MAX_ET_BASE_PROUVEE_2026-08-15.md` (V5 widget stock-bas).

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C6 VRAIS (délais consignés) ; 3. PHPUnit ≥ 5 194 + ≥ 11 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé 0 (`PricingService`, `OrderService`) ; 5. NF525 ajout seul, mouvements append-only intacts ; 6. gates tranchés ; 7. BRAIN vrai ; 8. deux cycles identiques ; 9. fiches de renvoi (ONB-05 menu/réglages, ONB-10 postes, ONB-02 enum, ONB-13 idempotence, ONB-07 définitions conso/coût).
**Interdit** : ajuster un stock réel · supprimer un mouvement · affaiblir une garde d'upload ou de formule Excel · déclarer une propagation sans chronomètre · approuver un gate.
> Le sens : à 20 h 02, Karim appuie sur un bouton, la borne arrête les burgers avant que le client suivant touche l'écran, et demain matin il sait qu'il faut du pain.
