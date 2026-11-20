# MISSION ONB-08 — STOCK, INGRÉDIENTS & DISPONIBILITÉ · Rapport de mission
- GOAL : `plans/GOAL_ONB08_STOCK_INGREDIENTS_DISPONIBILITE_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`) — **zone NON auditée en direct** : la reconnaissance est la W1.
- Port : **8808** · Voie : CENTRAL « stock & disponibilité » · Parallèle avec : 01, 02, 05, 06, 07, 09, 10 (vague A)

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-08 (stock, composants, disponibilité). Lis : CONSTITUTION.md, PROJECT_BRAIN.md §2, SYSTEM_MAP.md, SYNC_CONTRACT.md,
PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB08_STOCK_INGREDIENTS_DISPONIBILITE.md, plans/GOAL_ONB08_STOCK_INGREDIENTS_DISPONIBILITE_2026-08-26.md, puis recon/_BRIEF_COMMUN.md, la section Z5
(+ RÉSILIENCE) de recon/_ZONES.md, recon/Z0_modele_catalogue_wizard_reglages.md (§A.1) et recon/Z0_carte_dashboard.md (§1). Pré-vol §0.1 : worktree
.claude/worktrees/onb08-stock depuis HEAD, APP_URL=http://127.0.0.1:8808, .env.testing, liens durs, serveur 8808, PLAYWRIGHT_BASE_URL, filet backup/pre-onb08
+ dump stock_levels/stock_movements/item_branch_availability. ⛔ Jamais d'ajustement sur un stock réel : matière et article de test GOAL-ONB08, compensés ;
mouvements append-only. Vision OpenAI = mock en local. Puis « lance le GOAL » : W0 → W1 = brief Z5 (chronomètre de rupture borne/caisse/KDS, scan mock,
4 écrans, bords ; livrable recon/Z5_stock_ingredients.md) → W2 mouvements validés D'ABORD → W3..W6. Pipeline ultra-audit-profond, spécialistes lecture seule
en un message (SRE + DBA en tête), implémenteur unique, ROUGE avant tout « fini », Jalonneur, matrice §S, deux cycles identiques. Fichiers possédés = §0.2 ;
fiche produit → ONB-02, postes → ONB-10, menu/réglages → ONB-05, idempotence → ONB-13 : fiches §8. Jamais de push. Gates §G : proposer. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
La disponibilité est le lien entre le catalogue et la vente : une rupture non propagée = un client servi d'une promesse. Le backend est solide (25 tests : concurrence, idempotence,
append-only, libération, isolation), mais la **couche commerçant** est éclatée en cinq entrées, deux mutateurs écrivent sans FormRequest, et 11 articles vendables sont invisibles en
cuisine. Ce GOAL rend les trois questions du commerçant évidentes et prouvées avec un chronomètre. Persona Karim, rupture de pain à 20 h.

## 2. ÉTAT CONNU LE 2026-08-26 (code + DB ; **aucune mesure écran** — W1)
**2.1 Surfaces** (Z0 §1) : Produits & Stock (`/admin/catalog-hub?tab=stock` → `StockRuptureDashboardComponent`), Conso & Stock (`/admin/stock/unified`), Ajustement stock (`/admin/stock/raw-material-adjust`), Ingrédients (`/admin/ingredients` + `/:type`), Scan Facture (`/admin/purchasing/scan`), widget `StockLowAlertsWidget`, bascules 86 (`items/AvailabilityToggleComponent.vue`, `ingredients/IngredientAvailabilityToggleComponent.vue`), modale POS pertes (lecture, voie CAISSE).
**2.2 Connu vert (tests)** : `StockConcurrentDecrementTest`, `AvailabilityDecrementConcurrencyTest`, `StockMovementIdempotencyKeyUniqueTest`, `StockMovementsAppendOnlyTest`, `StockReleaseOnCancelTest`/`OnRefundTest`, `StockBranchIsolationTest`, `StockCrossSurfaceSyncTest`, `StockRuptureAvailabilitySyncTest`, `ManualEightySixStickyThroughRestockTest`, `ChoiceAvailabilityResolverIngredientRuptureTest` (+ variation), `LazyDailyQuotaReconcileTest`, `ResetStaleDailyQuotaReenableTest`, `IngredientAvailabilityChangedAfterCommitTest`, `IngredientControllerToggleTest`, `IngredientUsageDrillDownTest`, `UnifiedStockViewServiceTest`, `WizardOptionStockSyncTest`, `StockScanRuptureCommandTest`, `ReconcileOrderReleasesCommandTest`, `NotifyStockLowOnStockLevelChangedTest`, `StockRuptureAlertListenerSentinelTest`, `StockDashboardI18nIntegrityTest`, `AvailabilityTogglePermissionTest`, `ManualVsAutoReasonVocabularySentinelTest`.
**2.3 Constats connus (à reproduire W1)**
| Sév. attendue | Constat | Source |
|---|---|---|
| P1 | `RawMaterialAdjustController` : validation inline (`:117`), `MIN_TARGET = 0` déclaré « contrainte UI, pas service » (`:44-47`) ; `PurchasingScanController` inline (`:51-55`, `:208`) avec commentaire de durcissement RCE ; `AvailabilityController::setMaxDailyQty(Request)` nu (`:118`) | code (Z0 §6 « sans FormRequest ») |
| P1 (produit) | 11 articles vendables `kds_station='none'` (chaud 37 / froid 3 / bar 8) — invisibles en cuisine | SQL 26/08 ; BRAIN 25/08 |
| P2 | 5 entrées de menu pour un domaine ; « Ingrédients » = façade virtuelle (`IngredientService::TYPES`, id `type:id`, dédoublonnage par nom `:53-77`) | Z0 §1, §A.1 |
| P2 | Seuil bas par ligne sans écran global ; `stock_levels` 55 lignes, 0 sous seuil (faux-vide ou réalité ?) | SQL 26/08 |
| P2 | `StockRuptureDashboardController::run` (`:212`) : balayage déclenché depuis l'écran, périmètre/permission à vérifier | code |
**2.4 Angles morts attendus** : différence entre les 4 écrans ; « ingrédient » vs « extra » ; où noter une livraison ; quoi racheter.
**2.5 Cayenne** : matières et libellés de seed (à vérifier W1).

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-07-15 `GOAL_RUPTURE_CARNET_AUDIT`, 2026-07-24 Phase 3d-UI (`stock/unified` lecture seule), 2026-07-31 module repas/pertes (`StockOutflow`, voie CAISSE), 2026-08-06 `GOAL_CUISSON_ET_STOCK_VIANDE`, 2026-08-15 V5 (widget stock-bas faux-vide corrigé), ARCH_STOCK_INTELLIGENT_BOM P3c (scan facture).
- 2026-08-13 NAV1-01/02/03 (hub, unified lecture seule, scan CRUD gated) planifiés, non exécutés.
- Tests : `tests/Feature/Stock/` (25), `Availability/` (6), `Ingredients/` (4), sécurité `ExcelFormulaInjectionGuardTest`, `FileUploadHardenedSentinelTest`.

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Rupture dashboard | `app/Http/Controllers/Admin/StockRuptureDashboardController.php` | `lastSummary :50`, `lowAlerts :92`, `run :212`, `catalogOverview :299` ; routes `api.php:396-409` | Request nu |
| Vue unifiée | `UnifiedStockViewController.php:31` · `app/Services/Stock/UnifiedStockViewService.php` · route `api.php:411` | lecture | |
| Ajustement | `RawMaterialAdjustController.php:44-47,117` · `app/Services/RawMaterials/RawMaterialStockService.php` · routes `api.php:435-437` | inline | P1 |
| Achats | `PurchasingScanController.php:51-55,208` · `app/Services/Purchasing/{PurchaseService,InvoiceClassificationService}.php` · `Vision/{InvoiceVisionContract,MockInvoiceVisionService,OpenAiInvoiceVisionService}.php` · `PurchasingServiceProvider.php:30-34` · routes `api.php:417-425` | inline | mock local |
| Disponibilité | `AvailabilityController.php:52,118,164,194,227` · requêtes `AvailabilityToggleRequest`, `ToggleExtraAvailabilityRequest`, `ToggleVariationAvailabilityRequest` · routes `api.php:357-369,388` (`throttle:menu-availability`) | `setMaxDailyQty` nu | |
| Composants | `app/Services/Ingredients/IngredientService.php:14-36,51-77,132,141,156` · `IngredientController` (`api.php:902-913`, `permission:ingredients_manage`) · `admin/ingredients/*.vue` (3) | façade | |
| Modèles | `app/Models/StockLevel.php:25,35-41,82` · `StockMovement` · `ItemBranchAvailability` (`max_daily_qty`, `daily_consumed_qty`, `daily_reset_at`, `manual_unavailable_since`) · `PurchaseDocument`, `PurchaseLine` | | |
| Résolveur | `app/Services/Stock/ChoiceAvailabilityResolver.php` (consommé par `PricingService` — gelé) | lecture | |
| Stations | `items.kds_station` (migration `2026_04_20_230000`) | 11 `none` | ONB-02/10 |
| UI | `admin/stock/{StockRuptureDashboardComponent,UnifiedStockViewComponent,RawMaterialAdjustComponent}.vue` · `admin/purchasing/PurchaseScanComponent.vue` · `admin/items/CatalogHubComponent.vue` · `stockRoutes.js:19-28` · `admin/dashboard/StockLowAlertsWidget.vue` | | |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Stock|Availability|Ingredients|Purchasing|RawMaterial"` → figer W0 · `stock_levels` 55 · sous seuil 0 · `none` 11 · `SELECT COUNT(*) FROM stock_movements` (W0) · délais de propagation (W1, à figer).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-HUB-STOCK | Hub Stock à 3 onglets ; « Ingrédients » → « Composants du menu » ; Scan/Ajustement hors menu principal | oui | 5 entrées restent |
| G-STATIONS | Poste des 11 articles vendables en `none` (liste fournie en W1) | boissons → `bar`, desserts → `cuisine_froide`, « Frites seules » → `cuisine_chaude` (à confirmer) | sentinelle sautée avec motif |
| G-IDEMP (via ONB-13) | 2 routes stock dans `config/idempotency.php` | oui | double soumission possible |
| G-PERM-STOCK | Permission dédiée ajustement/balayage | `stock_manage` (nouvelle) | `items` reste |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- Un stock à 0 « sous seuil » aujourd'hui peut être un faux-vide (leçon V5) : créer une matière de test sous seuil pour prouver le widget.
- Propagation : mesurer par l'API menu (invalidation cache) ET le DOM borne ; un délai ≠ une panne (serveur mono-requête).
- Les mouvements sont append-only : « annuler » = mouvement inverse, jamais une suppression.
- `PurchasingScanController` porte une garde RCE : la FormRequest ne doit rien relâcher (test de caractérisation avant).
- Un article de test doit avoir une station ≠ `none` pour être visible en cuisine (piège du résolveur E2E, BRAIN 25/08).
- `:8000` = autre worktree ; ta session = **:8808**.


### 8.9 Le seuil d'alerte de stock — le jumeau qui manquait

Corrigé le 2026-08-28, après avoir été **consigné sans être corrigé** la veille.

`stock_levels.threshold_low` était **lu** à deux endroits :

- `StockRuptureDashboardController::lowAlerts()` — `whereNotNull('threshold_low')`
  puis `whereColumn('on_hand', '<=', 'threshold_low')` ;
- `NotifyStockLowOnStockLevelChanged` — la notification de stock bas.

Et **écrit par personne**. Aucune route, aucun écran, aucune commande. Mesuré en
lecture sur la base en service : **55 lignes, 0 seuil.**

La section « alertes stock bas » du tableau de bord ne pouvait donc
**structurellement rien afficher**, et l'alerte était muette — non pas parce que tout
allait bien, mais parce que personne ne pouvait dire à partir de quand ça n'allait
plus. *C'est le pire genre de silence : celui qui ressemble à une bonne nouvelle.*

**La chaîne complète, pas seulement l'API.** La vue conso unifiée affichait déjà une
colonne « Seuil » — qui ne pouvait montrer qu'un tiret sur les 55 lignes. Une colonne
qui ne peut afficher qu'un tiret est une promesse non tenue. Le champ y est désormais
saisissable, avec son message de refus visible, et la ligne porte l'identifiant du
**niveau de stock** (distinct de celui de l'article, sans quoi l'écran viserait la
mauvaise ligne).

Le banc ne prouve pas seulement l'écriture : il vérifie que **l'alerte s'allume
ensuite**. Un banc d'écriture seule laisserait passer le cas où la valeur atterrit
dans une colonne que le tableau de bord ne regarde pas. Prouvé dans les deux sens —
en cassant l'écriture, puis en inversant le filtre d'alerte.

Le plafond à 100 000 n'est pas cosmétique : un seuil absurde mettrait toute la carte
en alerte en permanence, ce qui revient exactement à n'avoir aucune alerte.

**Motif.** Septième exemplaire cette semaine de *la chaîne complète sauf l'écran où un
humain saisit la vérité* — allergènes, poste de cuisine, matières premières, seuil
matière, tampon halal, logo d'accueil, et maintenant seuil de stock. Le motif est
assez régulier pour mériter d'être cherché systématiquement : **partout où une valeur
est lue par un filtre, demander qui l'écrit.**

## 8. JOURNAL DE MISSION (rempli par la session)

Audit adverse en lecture seule le 2026-08-28, chaque verdict adossé à un
`fichier:ligne` réellement lu. Il a corrigé DEUX affirmations de la mission
elle-même et trouvé trois défauts dans le correctif livré la nuit même.

### 8.1 Les constats §2.3, rejoués contre le code d'aujourd'hui

| Sév. | Constat | Verdict | Preuve |
|---|---|---|---|
| P0 | Une facture « 3 kg » créditait **3 grammes** — facteur mille | **FIXÉ** | `PurchaseService.php:197,250-272` ; `UneFactureEnKilosNeCreditePasDesGrammesTest` (18) |
| P1 | `RawMaterialAdjustController` sans FormRequest | **ENCORE VRAI** | `:117` — `$request->validate([...])` en ligne |
| P1 | `PurchasingScanController` sans FormRequest, deux fois | **ENCORE VRAI** | `:51` et `:208` |
| P1 | `setMaxDailyQty(Request)` nu | **ENCORE VRAI, ET PIRE** | `AvailabilityController.php:118-124` — et `grep max-daily-qty resources/js/` ne rend RIEN : la route `api.php:389` n'a **aucun appelant écran**. Le quota journalier est un point d'entrée mort |
| P1 | 11 articles vendables en `kds_station='none'` | **VRAI (compte confirmé)** | Menu, Frites, Boisson, 7 sodas, 1 artefact E2E |
| P1 | « …donc invisibles en cuisine » | **RÉFUTÉ** | `helpers/kdsDisplay.js:36,44-46` : `STATIONS` inclut `'none'` et le filtre par défaut est `'all'`. Rien n'est invisible — les articles sont **mal routés** quand un cuisinier filtre. Nuance qui change le correctif |
| P2 | Seuil bas sans écran | **ENCORE VRAI, ET PIRE QUE DÉCRIT** | **55/55** `stock_levels` et **20/20** `raw_materials` ont `threshold_low` **NULL**, pas 0 ; or `StockRuptureDashboardController:99` et `NotifyStockLowOnStockLevelChanged:50` filtrent `whereNotNull('threshold_low')` → **100 % des lignes exclues**. L'alerte de stock bas est structurellement muette, et le commentaire `:20-21` du listener qui affirme « threshold_low=0 » est FAUX |
| P2 | « stock_levels 55 · sous seuil 0 » (§5) | **VRAI mais faux-vide** | Zéro sous seuil parce qu'aucun seuil n'existe, pas parce que le stock va bien |
| P2 | `run()` sans garde de permission | **RÉFUTÉ — non-défaut** | `StockRuptureDashboardController:47` porte `permission:items_create` au constructeur |

### 8.2 Trois défauts DANS LE CORRECTIF livré la nuit même

C'est le résultat le plus utile de l'audit, et il porte sur mon propre travail.

| Défaut | Ce que ça coûtait | Correction |
|---|---|---|
| `mb_strtolower` **ne dépouille pas les accents** : « pièce », « unité », « boîte », « kilo », « litre » — l'écriture normale d'un OCR français — ne correspondaient à aucune liste | La RÉCEPTION ENTIÈRE échouait sur une facture parfaitement légitime. J'avais remplacé une corruption silencieuse par un blocage bruyant : un mauvais échange, ça arrête le travail | `ECRITURES_EQUIVALENTES` + dépouillement des accents. « carton », « colis », « caisse » restent VOLONTAIREMENT inconnus : un carton contient N pièces, pas une |
| `InvalidArgumentException` n'est ni `HttpException` ni `QueryException` → `parent::render` → **HTTP 500** | L'écran affichait « Server Error » en anglais. Le message qui nomme la matière et les deux unités n'était lu par PERSONNE | `HttpException(422)`, l'idiome déjà présent (`PurchasingScanController` fait `abort_if($x, 422, …)`) |
| **Mon banc était au mauvais périmètre** : il appelle la méthode privée par réflexion, donc il mesurait le calcul, jamais ce que le commerçant reçoit | Le défaut ci-dessus a vécu une nuit sous un banc vert | `LeRefusDeReceptionEstLisibleParLeCommercantTest` (3) passe par la ROUTE. Prouvé en remettant l'ancienne exception : « Failed asserting that 500 is identical to 422 » |

### 8.3 Le blocage n°1 — LEVÉ le 2026-08-28

**`RawMaterial` n'avait aucun CRUD.** `routes/api.php:436-441` n'exposait que
`movements` (lecture) et `adjust` (correction de quantité) ; les seules sources de
création étaient `RawMaterialBaselineSeeder` et une commande console. **Un nouveau
commerçant ne pouvait déclarer aucun ingrédient** — le domaine entier lui arrivait
pré-rempli avec celui de Le Cayenne.

Ce blocage n'apparaissait dans AUCUN constat §2.3. Il a été trouvé en demandant à un
auditeur « qu'est-ce qui empêche un commerçant de partir de rien ».

⚠️ Le commentaire de `RawMaterialAdjustComponent` (`stockRoutes.js:10-14`) affirmait
être « la seule porte d'écriture manquante du domaine matière première ». C'était
faux : la déclaration en était une autre, et elle manquait depuis plus longtemps.

| Livrable | Fichiers | Preuve |
|---|---|---|
| CRUD complet, gardé `items_show` / `items_create` | `RawMaterialController`, `RawMaterialRequest`, 4 routes | `UnCommercantPeutDeclarerSesIngredientsTest` (11) |
| Écran de déclaration + sa **porte** depuis Conso & Stock | `RawMaterialListComponent.vue`, `stockRoutes.js` | `tests/js/lEcranDesMatieresDistingueVideEtZero.spec.js` (8) |

**`threshold_low` a enfin un chemin d'écriture** — le trou du §8.1 est donc fermé du
même coup. Mais avec un piège qu'il fallait éviter : en ouvrant ce chemin, il devenait
facile d'écrire `0` là où le commerçant laisse le champ vide. Ce serait **pire que
l'état d'avant** — au lieu d'une alerte muette sur 100 % des lignes, il en recevrait
une au premier gramme manquant, sur chaque matière, sans l'avoir demandée. Vide part
en `null`, revient vide, s'affiche « Aucune alerte », et les deux moitiés sont testées.

**Trois refus, chacun pour une raison mesurée** : changer l'unité d'une matière qui a
du stock (le stock est un nombre sans son unité — 3 kg deviendraient 3 g, le facteur
mille exact qui a mis onze matières en négatif) ; une unité hors conversion ; une
matière encore utilisée par une recette (la déduction de stock cesserait en silence,
motif du `tax_id` orphelin).

### 8.4 Ce qui reste — classé par coût pour un commerçant qui compte son stock
1. **`raw_material_recipe_lines` (126 lignes en base) n'a ni contrôleur ni composant.** Le commerçant ne peut pas dire « 1 burger = 150 g de viande » : la déduction de stock repose sur des recettes qu'il ne verra jamais.
2. **Réparer les 11 stocks négatifs** (Poulet **-9 600 g**). Le correctif de conversion arrête l'hémorragie, il ne recoud pas. Tant que ce n'est pas fait, « Conso & Stock » continue d'annoncer des ruptures que la borne dément — donc le correctif reste invisible pour l'utilisateur. **Décision propriétaire** : c'est de la donnée d'exploitation, pas du code.
3. ~~Ouvrir un chemin d'écriture pour `threshold_low`~~ — **FAIT** (§8.3). Reste à faire de même pour `stock_levels.threshold_low`, côté produits revendus : 55/55 lignes toujours NULL.
4. **Affecter un poste aux 11 articles** — le champ existe désormais dans le formulaire produit (livré ce soir, ONB-02) : c'est devenu un travail de données, plus de code.
5. **Trois FormRequest** (`RawMaterialAdjust`, `PurchasingScan`/`Apply`, `SetMaxDailyQty`), et trancher le sort de `setMaxDailyQty` — point d'entrée sans écran : lui en donner un, ou le retirer.
6. ~~Normaliser `raw_materials.unit`~~ — **PARTIELLEMENT FAIT** : la colonne reste `string(16)` libre en base, mais `RawMaterialRequest` borne désormais la saisie aux unités que la conversion sait traiter, et `PurchaseService` accepte les variantes d'écriture d'un OCR (accents, synonymes). Reste le cas des lignes déjà en base avec une unité hors liste.

**État final ONB-08 : le P0 de conversion est CLOS et prouvé aux deux bouts (calcul ET route HTTP) ; le blocage majeur de la mission « depuis zéro » est LEVÉ — un commerçant peut déclarer ses matières, et `threshold_low` a enfin un chemin d'écriture. Un constat P1 est RÉFUTÉ (la cuisine ne perd rien, elle route mal). Restent : les recettes sans écran, les 11 stocks négatifs à réparer (décision propriétaire, c'est de la donnée), et trois FormRequest — renvoyés à ONB-13, qui les audite.**
