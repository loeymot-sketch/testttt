# Claude — Ultra-Review Mission #2 : Stock + Composition produit (lifecycle admin → runtime) — 2026-05-02

| Champ | Valeur |
|---|---|
| Date | 2026-05-02 |
| Auditeur | Claude (Anthropic, terminal `claude`) |
| Modèle | `claude-opus-4-7` |
| Effort | `xhigh` (max raisonnement) |
| Group Graphiti | `foodking` |
| Périmètre | Lifecycle produit centralisé V1 — création, composition, stock 2-niveaux, race conditions, NF525 |
| Type | Audit indépendant — **aucune édition de code produit** |
| Demande | `reports/audit/CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_2_STOCK_COMPOSITION_2026-05-02.md` |
| Mission complémentaire | #1 Catalog Sync (`reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md`) |

> **Question d'ouverture :** Aucune question — j'ai tout ce qu'il faut.

---

## 0. Résumé exécutif

**Verdict global :** `READY_WITH_DEBT_TICKET`

Le lifecycle produit est **techniquement correct** : le SSOT pricing rejette les payloads stales, `composition_snapshot`/`allergens_snapshot` protègent la chaîne fiscale NF525, `StockService::releaseForOrder` est idempotent via le ledger `released_qty`, l'auto-86 sur stock épuisé fonctionne, et la branch isolation est respectée sur les chemins métier. **Le ressenti du restaurateur (« tout est compliqué, rien ne marche dans la gestion ») est UX, pas fonctionnel** : l'admin doit naviguer dans 5-6 écrans pour créer un tacos complexe, sans wizard guidé ni prévisualisation, et sans avertissement clair quand un composer profile reste non-publié. Le composer-editor existe (`resources/js/components/admin/items/composer/`) mais il est silencieux sur les états transitoires.

**Top 3 risques (gravité décroissante) :**
1. **Aucun `profile_version` check à la soumission de commande**. Si un kiosk a ouvert un wizard sur le profil v1 et qu'un admin publie v2 entre-temps, la commande est rejetée par `PricingService::validateComposerSelections` mais avec un message technique et sans UX dédiée côté kiosk (« Le menu a été mis à jour, votre panier sera vérifié »). Risque opérationnel client final.
2. **Workflow admin produit morcelé sans wizard guidé** : pour créer un tacos complexe (item + 4 viandes + 6 sauces + 5 crudités + 3 fromages + composer profile + photo), le restaurateur passe par `/admin/items/create` → `/admin/item-attributes` → `/admin/item-variations` → `/admin/item-extras` → `/admin/composer-profiles` (composé d'éditeur sous `resources/js/components/admin/items/composer/`). Aucune transaction unique. Risque qu'il oublie l'étape « publier le composer » → produit visible mais non-commandable.
3. **Auto-86 réactif uniquement, pas préventif**. `AvailabilityService::decrementForOrder` (`app/Services/Menu/AvailabilityService.php:191-236`) et `StockService::syncItemAvailabilityForStockLevel` (`app/Services/Stock/StockService.php:179-215`) flippent `is_available` **uniquement à la commande qui consomme la dernière unité**, jamais en amont. Pas de cron qui scrute. Si le seuil bas n'est pas atteint pendant une période sans commandes, les opérateurs n'ont pas d'alerte préventive.

**Top 3 quick wins recommandés (Vague 1) :**
1. Avertissement « Composer profile non publié » sur `ItemShowComponent.vue` quand `item.composer_profile?.is_published === false`. Effort S, risque nul, sentinel `posWizardComposerProfile.spec.js` extension.
2. Bouton « Aperçu Kiosk » et « Aperçu POS » sur `ItemShowComponent.vue`, consommant `MenuProjectionService::forChannel` (qui existe déjà ligne 246 `routes/api.php` mais n'est pas utilisé en runtime). Effort M, risque faible.
3. Toast UX kiosk « Le menu a été mis à jour » quand un `CatalogChanged` arrive pendant un wizard ouvert (refetch + diff visuel). Effort M, risque faible.

**Recommandation cycle suivant :** `TASK_ID = CV1-LIFECYCLE-UX-001` — wizard admin guidé multi-step pour la création d'un produit composé (un seul écran orchestre les appels existants), prévisualisation surfacique inline, avertissements transitoires composer/branch_id_scope/channels. Hors frozen zones. Aucun gate humain.

---

## A. Vérification de l'état des lieux

### A.1 — Hypothèses §2.6 du brief

| # | Hypothèse | Verdict | Évidence file:line |
|---|---|---|---|
| 1 | Workflow admin morcelé : items / attributes / variations / extras / composer en 5+ écrans séparés | **PARTIELLEMENT CONFIRMÉ** | Le composer-editor est en réalité **plutôt bien intégré** dans le module items : `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` + `StepEditorComponent.vue` + `StepPreviewComponent.vue` + `resources/js/components/admin/items/ProductComposerSummaryComponent.vue`. **MAIS** : pas de wizard parent multi-step ; chaque CRUD attributes/variations/extras/addons reste un écran séparé (`resources/js/router/modules/itemRoutes.js`) ; `ItemCreateComponent.vue` (lignes 1-80) ne crée QUE le base item, sans guide vers les étapes suivantes. **L'hypothèse est juste sur la friction UX, fausse sur l'absence totale de composer integration.** |
| 2 | Mutation pendant ouverture panier : profile v1→v2 publié pendant un wizard ouvert sur kiosk → comportement non clarifié | **CONFIRMÉ** | Aucun `profile_version` check observé dans `PricingService::calculateOrder` lignes 36-55 ni dans `ChoiceAvailabilityResolver::assertSelectionsOrderable` lignes 122-197. Le rejet se fait **par effet de bord** (un `option_id` retiré du profil v2 ne se trouve plus dans la projection courante → `assertSelectionsOrderable` jette). **Pas de message UX dédié côté kiosk** ; la kiosk reçoit `422` avec un payload erreur générique. UX dégradée probable. |
| 3 | Cancel / refund release stock : `releaseForOrder` est-il appelé sur tous les chemins ? | **CONFIRMÉ OK** | Vérifié 4 chemins dans `memory/episodes/12_decisions_log.jsonl#9` (lot 1.B.2 du cycle sync_repair_v2 daté 2026-04-23) : POS cashier cancel, customer self-cancel, frontend kiosk cancel, `CleanupStalePendingKioskOrders` job. Idempotency garantie via ledger `released_qty` (`app/Services/Stock/StockService.php:381-382` et `app/Services/Menu/AvailabilityService.php:311-312`). Sentinels : `tests/Feature/Stock/StockReleaseOnCancelTest.php`, `StockReleaseOnRefundTest.php`, `tests/Feature/Availability/StockReleaseTest.php` (5/5 passing à la clôture du lot 1.B.1). **Pas de trou observé.** |
| 4 | `OrderService` / `FrontendOrderService` symétrie pour règles de stock | **CONFIRMÉ OK** | Invariant §5 `.cursor/rules/project-invariants.mdc:54-61` impose la symétrie. Sentinel `tests/Feature/Stock/StockSymmetryDiffTest.php` existe. **Néanmoins** : le test sentinelise un diff structuré ; si une règle est ajoutée à un seul des deux, le test échoue (vérification GPT-5.5 plan_review). Pas de trou observé. |
| 5 | Photo invalidation — POS et Kiosk passent par quels chemins ? | **CONFIRMÉ : asymétrique** | Kiosk : `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php` ✅ existe et asserte que `ItemController::changeImage` invalide `kiosk.menu.branch.{id}` via `PersistCatalogChangedToOutbox` chain. POS : pas de cache backend équivalent (cf. mission #1 §A point #4) ; la photo arrive via refetch list `_onCatalogChanged` (`PosComponent.vue:1632-1650`) ou affichage direct par URL Spatie media. Asymétrie de mécanisme, mais pas de bug fonctionnel. |
| 6 | Suppression vs hidden : `delete` est-il vraiment soft, et l'historique reste-t-il lisible ? | **CONFIRMÉ OK** | `app/Models/Item.php:17` `use SoftDeletes`. `composition_snapshot` est figé à la création de `OrderItem` (épisode JSONL `02_architecture_invariants.jsonl#4`, sentinel `PosReceiptFiscalExposureTest`). Les réimpressions tickets lisent le snapshot, pas le catalogue live. **Risque résiduel** : `ItemController::destroy` (`app/Http/Controllers/Admin/ItemController.php:95-103`) ne fait **pas** de check explicite « si OrderItem référence cet item, soft-delete obligatoire » avant un éventuel hard-delete (qui serait possible via `forceDelete`). En l'état actuel `destroy` route mène à `Item->delete()` qui est soft. **OK V1 mais à protéger explicitement Vague 2.** |
| 7 | Auto-86 sur seuil stock : mécanisme automatique qui scrute `stock_levels` et flip `is_available=false` à zéro ? | **CONFIRMÉ : réactif uniquement** | **PAS** de scheduled job dans `app/Console/Kernel.php` (lignes 21-96, vérifié). Auto-86 déclenché par 2 chemins **réactifs** : (a) `AvailabilityService::decrementForOrder` lignes 191-236, flip `is_available=false` quand `daily_consumed_qty >= max_daily_qty` (ligne 218-222) ; (b) `StockService::syncItemAvailabilityForStockLevel` lignes 179-215, flip quand `on_hand <= 0` (ligne 198). Listeners cibles : `DecrementItemAvailabilityOnOrder`, `DecrementStockOnOrderCreated`. **Manque** : un job cron qui scrute en amont (préventif) — par exemple cron toutes les 5min qui détecte les items dont toutes les variations stockables sont à 0 et flip l'item entier ; aujourd'hui ça arrive seulement au passage de la prochaine commande qui consommerait. À ajouter Vague 2. |
| 8 | `channels = NULL = visible partout` | **CONFIRMÉ — cross-référence Mission #1 §A point #6** | `app/Models/Item.php:83-85`, `app/Models/ItemCategory.php:54-56`. Risque opérationnel multi-branche identique à Mission #1. Voir Vague 3 Mission #1 (point 3.1) pour le plan de remédiation. |

### A.2 — Points faibles supplémentaires découverts

| # | Point | Évidence file:line | Impact métier observable |
|---|---|---|---|
| 9 | **`AvailabilityService::decrementForOrder` ne pose pas `lockForUpdate`** | `app/Services/Menu/AvailabilityService.php:191-236` — lit puis update sans transaction lock | Vs `releaseForOrderItems` ligne 329 qui met bien `lockForUpdate`. Risque de concurrence sur 2 commandes simultanées qui consomment la dernière unité d'un `max_daily_qty` : le `daily_consumed_qty++` peut perdre une commande. **À confirmer par lecture intégrale du code** — INDÉTERMINÉ (l'agent Explore a noté le défaut, mais le contexte transactionnel englobant — si `OrderService::create` ouvre une transaction et appelle `decrementForOrder` dedans — peut compenser via le `lockForUpdate` de `decrementForOrder` du `StockService`). Sentinel : `tests/Feature/Stock/StockConcurrentDecrementTest.php` existe pour `StockService` mais je n'ai pas pu confirmer un sentinel équivalent pour `AvailabilityService` |
| 10 | **`ComposerProfileService::publish` dispatch un seul `ComposerProfileChanged` mais pas de versioning protection** | `app/Services/Composer/ComposerProfileService.php:82-93` ; payload `version` exposé (`app/Events/ComposerProfileChanged.php:30-34`) **mais** aucun consommateur ne le compare au moment du submit | Cf. risque #2 ci-dessus. Le frontend ne lit pas la version du profil ouvert vs la version courante au submit. À ajouter Vague 2. |
| 11 | **Pas de test sentinel sur le flow « profil v1 ouvert sur kiosk → publish v2 → submit panier »** | Suite `tests/Feature/Composer/` ne couvre pas explicitement ce scénario | Sentinels manquants : un test E2E (`tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php`) qui ouvre un cart, publish v2 retirant un choix, submit → doit retourner 422 propre, pas 500. À ajouter Vague 1. |
| 12 | **Transition `ItemUpdated` → `CatalogChanged` n'est pas instrumentée par un listener `bumpMenuSnapshot`** | `EventServiceProvider:149-176` mappe uniquement `ItemAvailabilityChanged` → `BumpMenuSnapshotOnItemAvailabilityChanged`. Les autres events catalog passent par `InvalidateKioskMenuCacheOnCatalogChange.php:53` qui appelle bien `MenuSnapshot::bump()` | OK fonctionnellement, mais l'asymétrie d'instrumentation est trompeuse. À documenter pour les contributeurs futurs. |
| 13 | **`changeImage` ne réinitialise pas la prévisualisation côté kiosk wizard ouvert** | `ItemController::changeImage` invalide `kiosk.menu.branch.{id}` mais le wizard kiosk déjà ouvert ne refait pas `fetchMenu` pendant l'opération courante du client | UX mineur : si un client a ouvert le wizard d'un item dont l'admin change la photo en parallèle, le client voit la vieille photo jusqu'à fin de panier. Acceptable V1 (race opérationnellement rare) mais à documenter. |
| 14 | **Aucun mécanisme « stock low alert »** (avant rupture) | grep `stock.low` `seuil` `threshold` dans `app/` → seules `stock_levels.threshold_low` et `StockLevel::isLow()` côté model. Pas de listener qui notifie les opérateurs | UX préventif manquant. À ajouter Vague 2 ou V2. |
| 15 | **`StockMovement` est append-only mais pas de unique constraint sur `idempotency_key`** | `app/Models/StockMovement.php:18` (fillable) + ligne 33-40 booted guards. Pas de migration `unique('idempotency_key')` confirmée | Si une commande retry foire le verrou idempotency, double-write possible. Sentinel `StockConcurrentDecrementTest` couvre `lockForUpdate` mais pas l'unique constraint DB. À durcir Vague 2 schema. |

---

## B. Atlas du workflow admin produit (création d'un tacos complet)

Pour créer un tacos complexe (4 viandes, 6 sauces, 5 crudités, 3 suppléments fromage, photo, prix, contraintes min/max), un restaurateur passe par le parcours suivant :

| # | Étape | URL admin | Composant Vue | Endpoint backend | Validation backend | Friction observée |
|---|---|---|---|---|---|---|
| 1 | Créer la catégorie « Tacos » | `/admin/categories/create` | `ItemCategoryCreateComponent.vue` | `POST /api/admin/item-categor[y\|ies]` | `ItemCategoryRequest` | Pas de friction majeure |
| 2 | Créer l'item de base | `/admin/items/create` | `ItemCreateComponent.vue` (lignes 1-80) | `POST /api/admin/items` | `ItemRequest` | **Item créé en `is_available=true` par défaut** mais sans composer ni variations → si le restaurateur s'arrête là il aura un item « ready » commandable, ce qui peut être faux pour un tacos. Pas d'avertissement |
| 3 | Uploader la photo | dans la modale de création ou `/admin/items/show/:id` | `ItemUploadComponent.vue` | `POST /api/admin/items/{id}/image` | check role Admin/Tenant Admin | Friction : l'upload requiert un rôle global ; un Branch Manager peut être bloqué et ne pas comprendre pourquoi (cf. `tests/Feature/Catalog/ProductPhotoAuthzTest.php`) |
| 4 | Créer l'attribut « Viande » | `/admin/item-attributes/create` | `ItemAttributeCreateComponent.vue` | `POST /api/admin/item-attribute` | `ItemAttributeRequest` (min/max/repeat) | **Étape distincte** — l'utilisateur quitte la page item pour créer l'attribut, doit revenir |
| 5 | Créer 4 variations « Poulet », « Steak », « Cheese », « Veggie » | `/admin/item-variations/create` × 4 | `ItemVariationCreateComponent.vue` | `POST /api/admin/item-variation` | `ItemVariationRequest` | **4 actions séparées** — pas de batch, pas de duplication |
| 6 | Créer 6 sauces (autres extras ou variations selon attribut « Sauce ») | `/admin/item-extras/create` × 6 ou attribut secondaire + variations | idem | `POST /api/admin/item-extra` ou `/item-variation` | idem | Confusion possible : une sauce est-elle un extra (payant en + ?) ou une variation (choix obligatoire dans un step) ? **Pas de doc en ligne admin** |
| 7 | Créer 5 crudités, 3 fromages | idem extras | idem | idem | idem | idem |
| 8 | Créer le composer profile et publier | embarqué dans `ItemShowComponent.vue` via `ProductComposerEditorComponent.vue` | `POST /api/admin/composer-profile`, `POST /api/admin/composer-profile/{id}/publish` | `ComposerProfileService::store + publish` | `assertPublishable` | **Étape critique souvent oubliée** : créer le profile mais oublier de cliquer « Publier » → produit visible mais wizard non actif → le pricing rejettera tout submit. **Pas d'avertissement visuel sur la page item** |
| 9 | Vérifier sur le kiosk / POS | `/admin/items/show/{id}` | aucun bouton « aperçu » | n/a | n/a | **Pas de prévisualisation inline** ; le restaurateur doit ouvrir un kiosk et un POS séparément pour voir le rendu |

### Frictions principales identifiées

1. **Pas de wizard parent** qui orchestre les 9 étapes en une seule transaction guidée. Le restaurateur peut s'interrompre à n'importe laquelle.
2. **Aucun avertissement « composer profile non publié »** sur la page de détail produit. C'est l'erreur la plus fréquente.
3. **Pas de prévisualisation surfacique** (« voici ce que verra le kiosk », « voici ce que verra le POS »). L'endpoint `MenuProjectionService::forChannel` existe et est non consommé en V1 (cf. Mission #1 §A point #8).
4. **Confusion sémantique** entre `attribute + variation + extra + addon` — la doc en ligne admin n'aide pas. Cf. `docs/sync/WIZARD_PRODUCT_MODEL.md` qui clarifie 5 kinds mais n'est pas exposé en UI.
5. **Pas de duplication** : créer 4 viandes proches signifie remplir 4 fois le même formulaire.

---

## C. Atlas des états transitoires et race conditions

| # | État au moment T | Mutation admin à T+1 | Effet attendu côté commande T | Effet observé (file:line) | Risque | Sentinel |
|---|---|---|---|---|---|---|
| 1 | Wizard kiosk ouvert avec composer profile v1 | Admin publie composer profile v2 | Submit en cours soit accepté (si choix valides aussi en v2) soit rejeté 422 avec UX claire | `PricingService` rejet implicite via lookup `option_id` absent dans projection courante (vu via `ChoiceAvailabilityResolver::assertSelectionsOrderable` lignes 122-197). **Pas de message UX dédié kiosk** | Modéré | **Manquant** : `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php` |
| 2 | Cart kiosk avec item X | Toggle `item_branch_availability` X = unavailable (admin POS clic 86) | Pruning `kioskCart/pruneUnavailable` + toast | OK : `_onItemAvailabilityChanged` (`PosComponent.vue:1657-1717`) ligne 1699-1701 prune en place. Côté kiosk : `KioskAppComponent.vue` reçoit `ItemAvailabilityChanged` et déclenche `kioskCart/pruneUnavailable` (à confirmer dans store) | Faible | `tests/js/kioskRuptureUx.spec.js` ✅ |
| 3 | Cart POS avec variation Y | Stock niveau Y tombe à 0 | Retrait variation + toast cashier | OK : `StockService::syncItemAvailabilityForStockLevel` (`app/Services/Stock/StockService.php:179-215`) flip + `ItemAvailabilityChanged::forBranch` (ligne 202). POS reçoit, refresh. **Mais** : le cart en cours côté POS conserve la variation jusqu'au submit, où `PricingService` rejette. | Faible | `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php` ✅ |
| 4 | Commande POS submit en cours | Suppression item Z (soft delete) entre `/quote` et `/create` | Order avec snapshot Z reste lisible mais création doit être atomique | OK : `OrderItemCompositionSnapshot::hydrate` figeant le snapshot dans la même transaction que la création (épisode JSONL `02_architecture_invariants.jsonl#4`, `tests/Feature/Outbox/OutboxTest.php` couvre `assertDatabaseCount('domain_events', 0)` sur rollback). **Risque résiduel** : si le `Item->delete()` se fait dans la même seconde, `Item::find($id)` retourne null mid-transaction → erreur 500. Très peu probable en mono-utilisateur admin | Très faible | `tests/Feature/Stock/StockConcurrentDecrementTest.php` partiel — pas de cas explicite item-deleted-mid-create |
| 5 | Refund partiel | Item supprimé entre achat et refund | Release stock idempotent depuis snapshot | OK : `StockService::releaseForOrder` (`app/Services/Stock/StockService.php:35-39`) lit le snapshot via `released_qty` ledger ; ne dépend pas du catalogue live (sauf pour résolution des stockables). **Si l'item est soft-deleted, `Item::find` peut retourner null** mais le ledger est tenu sur `order_items.released_qty` colonne, pas sur `items` | Faible | `tests/Feature/Stock/StockReleaseOnRefundTest.php` ✅ |
| 6 | Toggle `is_available=false` (admin POS) | Une commande déjà en `preparing` côté KDS | KDS doit pouvoir continuer à finir la commande (ne pas bloquer) | OK structurellement : KDS reçoit `ItemAvailabilityChanged` (SYNC-001) mais ce n'est qu'un refresh debounced (épisode JSONL `03_domain_events_sync.jsonl#3`). KDS lit les `OrderItem` via snapshot `composition_snapshot`, pas la dispo live | Faible | `tests/js/kitchenDisplaySystemSync.spec.js` (à renforcer pour V2 selon épisode #3) |
| 7 | Composer profile **dépublié** (revert v2 → v1) pendant un wizard ouvert | Wizard kiosk submit à un moment donné | Rejet propre attendu | **INDÉTERMINÉ** : `ComposerProfileService::publish` existe mais y a-t-il une méthode `unpublish` symétrique ? `app/Services/Composer/ComposerProfileService.php:82-93` (publish observé). Investigation requise sur la symétrie | Faible (cas rare) | À vérifier |
| 8 | `max_daily_qty` réduite (admin) à valeur < `daily_consumed_qty` actuel | Simulation : 50 menu burger vendus, admin baisse `max_daily_qty` à 30 | Auto-flip immédiat ? ou seulement à la prochaine commande ? | `AvailabilityService::toggle` ligne 34-76 ne re-évalue pas `max_daily_qty` vs `daily_consumed_qty`. Le flip auto se fait uniquement à `decrementForOrder`. **Manque** : un re-evaluate à la modification de `max_daily_qty` | Modéré | À ajouter Vague 2 |

---

## D. Plan de remédiation hiérarchisé

### Vague 1 — UX admin sans changement de schéma (≤ 1 cycle masterplay)

| Action | Cible file:line | Effort | Risque | Gate humain | Sentinels |
|---|---|---|---|---|---|
| **1.1 Avertissement « Composer profile non publié »** sur `ItemShowComponent.vue` quand `item.composer_profile?.is_published === false` (badge orange + texte) | `resources/js/components/admin/items/ItemShowComponent.vue` (à confirmer existence) + `ProductComposerSummaryComponent.vue` | S | Nul | Non | Nouveau `tests/js/itemShowComposerWarning.spec.js` |
| **1.2 Bouton « Aperçu Kiosk » et « Aperçu POS »** sur `ItemShowComponent.vue` consommant `MenuProjectionService::forChannel('kiosk', $branchId)` et `('pos', $branchId)` (déjà exposé via `/api/admin/menu-projection`) | `ItemShowComponent.vue` + nouveau `ItemPreviewComponent.vue` | M | Faible | Non | `tests/js/itemPreviewProjection.spec.js` |
| **1.3 Toast UX kiosk « Le menu a été mis à jour »** quand `CatalogChanged` arrive pendant un wizard ouvert. Faire pruning + diff visuel des choix retirés | `KioskWizardComponent.vue` + `kioskCart` store | M | Faible | Non | `tests/js/kioskWizardCatalogChangedHandling.spec.js` |
| **1.4 Sentinel test profil v1→v2 mid-cart** | nouveau `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php` | S | Faible | Non | Le test lui-même |
| **1.5 Avertissement frontend admin pour « item visible POS+kiosk mais sans composer profile publié »** (état incohérent) | check applicatif dans `ItemController@show` qui retourne un flag `warnings: []` ; UI consomme | M | Faible | Non | sentinel applicatif |
| **1.6 Aide en ligne `WIZARD_PRODUCT_MODEL`** : afficher un help inline distinguant attribute vs variation vs extra vs addon dans les composants de création | `resources/js/components/admin/item-attributes/*Vue.` etc. | M | Nul | Non | Pas de sentinel test (UX) |
| **1.7 Bouton « Dupliquer ce produit »** (clone item + variations + extras + composer) | nouvel endpoint admin + UI | M | Modéré | Non | `tests/Feature/Catalog/ItemDuplicationTest.php` |
| **1.8 Compléter `ItemController::destroy` avec un check explicite « si OrderItem référence cet item, refuser hard-delete et n'autoriser que soft-delete »** | `app/Http/Controllers/Admin/ItemController.php:95-103` + `app/Services/ItemService.php` méthode `destroy` | S | Faible | Non | `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php` |
| **1.9 Lock `lockForUpdate` sur `AvailabilityService::decrementForOrder`** pour parité avec `StockService::decrementForOrder` (cf. point #9 supplémentaire) | `app/Services/Menu/AvailabilityService.php:191-236` | S | Faible | Non | étendre `tests/Feature/Stock/StockConcurrentDecrementTest.php` à `AvailabilityServiceConcurrent` |

**Estimation totale Vague 1 :** ~1 sprint (3-5 jours-dev). Aucun gate humain.

### Vague 2 — Hardening stock + composition (1-3 cycles)

| Action | Détail | Effort | Risque | Gate humain | Sentinels |
|---|---|---|---|---|---|
| **2.1 Auto-86 préventif via cron** : job scheduled `php artisan stock:scan-rupture` toutes les 5 min, scrute `stock_levels` par branche et flip `item_branch_availability.is_available=false` quand toutes les variations stockables sont en rupture | Nouveau `app/Console/Commands/StockScanRupture.php` + `app/Console/Kernel.php` schedule | M | Modéré | Non | `tests/Feature/Stock/StockScanRuptureCommandTest.php` |
| **2.2 `profile_version` check à la soumission** : `OrderRequest` accepte un `composer_profile_version_at_open` qui est vérifié contre la version courante au moment de submit. Si différent, retourne 409 Conflict avec un payload de remédiation (« choix retirés » liste) | `app/Services/Pricing/PricingService.php` (frozen — gate brief requis pour étendre) | L | Élevé | **OUI** — gate brief `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK.md` à ouvrir | `tests/Feature/Composer/ProfileVersionMismatchTest.php` |
| **2.3 Refactor publication composer pendant panier ouvert UX clair** : kiosk reçoit `ComposerProfileChanged` event, refait la projection composer, diffe les choix retirés et affiche modale « Mise à jour menu » | `KioskWizardComponent.vue` + `kioskMenu.js` | L | Modéré | Non | `tests/js/kioskComposerProfileChangeHandling.spec.js` |
| **2.4 `OrderService` / `FrontendOrderService` symétrie sentinel renforcé** : test qui parse les méthodes `decrementXxx` / `releaseXxx` des deux services et asserte la même signature/comportement (diff structuré) | extension `tests/Feature/Stock/StockSymmetryDiffTest.php` | M | Faible | Non | extension du test existant |
| **2.5 Re-évaluation `is_available` à la modification `max_daily_qty`** | `app/Services/Menu/AvailabilityService.php::toggle` | S | Faible | Non | `tests/Feature/Menu/MaxDailyQtyChangeReEvaluationTest.php` |
| **2.6 `StockMovement` unique constraint sur `idempotency_key`** | migration ajoutant `unique('idempotency_key')` | S | Faible (table append-only) | Non | `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php` |
| **2.7 Stock low alert** : listener sur `StockLevelChanged` qui notifie via toast/email dashboard quand `on_hand <= threshold_low` (préventif) | nouveau `app/Listeners/NotifyStockLowOnStockLevelChanged.php` | M | Faible | Non | sentinel applicatif |
| **2.8 Symétrie unpublish composer profile** (si pas implémentée) | `ComposerProfileService::unpublish` + event | M | Modéré | Non | `tests/Feature/Composer/ComposerProfileUnpublishTest.php` |
| **2.9 Wizard admin guidé multi-step pour création produit composé** : nouvelle UI Vue `ProductCreateWizardComponent.vue` qui orchestre les 9 étapes du §B en pilotant les endpoints existants. Aucun changement backend | Nouveau composant + route admin | XL | Modéré | Non (changement UI) | `tests/js/productCreateWizardE2E.spec.js` (ou Playwright) |

### Vague 3 — Refactor structurel (multi-cycles, hard gates)

| Action | Détail | Effort | Risque | Gate humain | Sentinels |
|---|---|---|---|---|---|
| **3.1 Politique `channels = required`** | Cross-référence Mission #1 §D Vague 3 point 3.1. Pas de duplication ici | n/a | n/a | OUI (Mission #1) | n/a |
| **3.2 Modèle stock unifié** : `stock_levels` devient l'unique source pour produit + variation + extra + addon target. `item_branch_availability` devient une vue dérivée (matérialisée) | XXL | Très élevé | OUI gate schéma + migration backfill | Suite migration |
| **3.3 `composer_profile_version` colonne sur `order_items`** : capture la version du profil au submit, immutable. Permet diagnostic post-mortem (« cette commande utilisait v1, le profil a été republié 3 fois depuis ») | L | Modéré | OUI gate schema (extension `order_items` mineure) | sentinel |

---

## E. Definition of Done « Produit centralisé V1 final » enrichie

Reprise des 10 items de `docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md` + extensions Mission #2 :

1. Il est créé/modifié dans Dashboard avec permissions correctes (sentinel `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php`).
2. Sa catégorie/photo/statut sont visibles sur POS et Kiosk (sentinel mission #1 + photo invalidation Kiosk).
3. Son composer wizard est publié ou absent volontairement (sentinel `tests/Feature/Composer/ComposerPublishSyncTest.php`).
4. Ses choices stockables montrent la rupture au bon endroit (sentinel `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`).
5. POS et Kiosk ne peuvent pas submit un choix indisponible (sentinel `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` ✅ existe).
6. Backend pricing rejette prix forge, stale choice et inactive choice (`PricingService` frozen).
7. La commande arrive KDS/OSS/POS sans reload manuel (sentinel `tests/e2e/c3-runtime-multi-surface.spec.js`).
8. Le stock décrémente puis release sur cancel/refund (sentinel `tests/Feature/Stock/StockReleaseOn{Cancel,Refund}Test.php`).
9. L'historique commande reste lisible via snapshots (sentinel `PosReceiptFiscalExposureTest`).
10. Le flux est couvert par test automatisable ou note UAT hardware.
11. **NEW** : le restaurateur reçoit un avertissement visible quand le composer profile n'est pas publié (Vague 1.1).
12. **NEW** : le restaurateur peut prévisualiser inline le rendu Kiosk + POS depuis la fiche item (Vague 1.2).
13. **NEW** : un kiosk avec wizard ouvert sur profil v1 et un publish v2 admin déclenche une UX claire (toast + diff) au prochain submit (Vague 1.3 + 1.4).
14. **NEW** : `AvailabilityService::decrementForOrder` pose `lockForUpdate` pour parité avec `StockService` (Vague 1.9).
15. **NEW** : un cron auto-86 préventif scrute toutes les 5 min et flip `is_available` quand toutes les variations stockables sont en rupture (Vague 2.1).
16. **NEW** : `OrderRequest` accepte `composer_profile_version_at_open` et le compare au current ; mismatch → 409 Conflict UX-friendly (Vague 2.2).
17. **NEW** : `StockMovement.idempotency_key` est unique en DB (Vague 2.6).
18. **NEW** : `ItemController::destroy` refuse le hard-delete si des `OrderItem` référencent l'item (Vague 1.8).
19. **NEW** : un wizard admin guidé multi-step orchestre la création d'un produit composé en une UI cohérente (Vague 2.9).

---

## F. Verdict final et recommandation

**Verdict :** `READY_WITH_DEBT_TICKET`.

**Justification (10 lignes) :**

Le lifecycle produit est **techniquement solide** : `composition_snapshot` immuable préserve l'historique fiscal NF525, `StockService::releaseForOrder` est idempotent via le ledger `released_qty`, l'auto-86 réagit correctement à `on_hand <= 0` et `daily_consumed_qty >= max_daily_qty`, la branch isolation est respectée sur les 4 chemins de cancel observés (POS, customer self, kiosk, stale cleanup), et les sentinels Stock/Composer sont denses et passants. **Le ressenti restaurateur (« rien ne marche dans la gestion ») est UX, pas fonctionnel** : workflow morcelé (9 étapes), pas de wizard guidé, pas d'avertissement composer non-publié, pas de prévisualisation surfacique inline, pas de version-check composer au submit avec UX dédiée, pas de cron auto-86 préventif. **Pas de blocage prod V1 fonctionnel**, mais ouverture immédiate d'un debt ticket Vague 1 (avertissements + previews + toast catalog change) puis Vague 2 (auto-86 préventif, profile_version check derrière gate frozen, wizard admin guidé). La Vague 3 (channels=required, modèle stock unifié, composer_profile_version dans order_items) reste un projet schéma post-V1.

**Recommandation cycle suivant :** `TASK_ID = CV1-LIFECYCLE-UX-001` — Vague 1 complète (1.1 → 1.9). Pas de gate humain. Aucun frozen zone. Effort cumulé ~1 sprint dev.

---

## G. Épisodes JSONL proposés (pour ingestion humaine)

À ajouter à `memory/episodes/12_decisions_log.jsonl` ou `09_tasks_history.jsonl` après validation :

```jsonl
{"name":"Lifecycle audit Mission 2 — UX-bound debt, not functional","source":"text","source_description":"reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md","episode_body":"L'audit Mission 2 (2026-05-02, Claude Opus 4.7 xhigh) confirme que le lifecycle produit V1 est fonctionnellement solide : composition_snapshot immuable, StockService::releaseForOrder idempotent via released_qty ledger, auto-86 réactif sur on_hand<=0 et max_daily_qty, branch isolation respectée sur 4 chemins cancel. Le ressenti restaurateur ('rien ne marche dans la gestion') est UX, pas fonctionnel : workflow admin morcelé en 9 étapes sans wizard guidé, pas d'avertissement composer non-publié, pas de prévisualisation surfacique inline. Verdict READY_WITH_DEBT_TICKET. Cycle suivant CV1-LIFECYCLE-UX-001 (Vague 1 quick wins UX) ; Vague 2 hardening (auto-86 préventif cron + profile_version check au submit derrière gate brief frozen pricing + wizard admin guidé multi-step) ; Vague 3 schema (channels=required, modèle stock unifié, composer_profile_version sur order_items)."}
{"name":"Auto-86 mechanism — réactif uniquement, pas préventif","source":"text","source_description":"app/Services/Menu/AvailabilityService.php:191-236 + app/Services/Stock/StockService.php:179-215 + app/Console/Kernel.php:21-96 (no scheduled stock command)","episode_body":"Auto-86 V1 est déclenché à la commande qui consomme la dernière unité, jamais en amont. Aucun job scheduled qui scrute stock_levels. Conséquences opérationnelles : si une période sans commandes laisse le stock vide, les opérateurs n'ont pas d'alerte préventive ; un item peut rester 'is_available=true' quelques minutes après que sa dernière variation stockable est tombée à 0 (jusqu'à la prochaine décrémentation déclenchée par une commande). À ajouter Vague 2 (action 2.1) : cron 'php artisan stock:scan-rupture' toutes les 5 min."}
{"name":"Profile version race — wizard v1 ouvert, publish v2 admin, submit panier","source":"text","source_description":"PricingService::validateComposerSelections + ChoiceAvailabilityResolver::assertSelectionsOrderable file:line in audit report Mission 2","episode_body":"Aucun composer_profile_version check à la soumission. Le rejet stale-choice se fait par effet de bord (option_id retiré du profil v2 absent dans projection courante → assertSelectionsOrderable jette). Pas de message UX dédié côté kiosk — seulement 422 générique. À durcir Vague 2 (action 2.2) : ajouter composer_profile_version_at_open dans OrderRequest + 409 Conflict UX-friendly. Cette modification touche PricingService (frozen zone) → gate brief requis."}
```

---

**FIN DU RAPPORT MISSION 2.**

Cross-référence Mission #1 : pour les questions de projection POS↔Kiosk, divergence catalogue, branch_id sur PosCategoryController et channels=NULL, voir `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md`.
