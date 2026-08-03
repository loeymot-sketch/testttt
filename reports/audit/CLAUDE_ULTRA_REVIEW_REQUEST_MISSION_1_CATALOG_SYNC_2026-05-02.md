# Claude — Demande d'Ultra-Review #1 : Synchronisation catalogue centrale (POS ↔ Kiosk ↔ KDS) — 2026-05-02

> **Tu reçois cette demande dans ton terminal `claude` (abonnement Anthropic Pro).** Tu n'exécutes pas de code. Tu fais un **audit ultra-profond** du système actuel de centralisation catalogue, tu dis exactement ce qui est bon, fragile, dupliqué, à refondre, et tu produis un **rapport d'audit + plan de remédiation hiérarchisé** que je pourrai ensuite donner à exécuter à Codex (`gpt-5.5-pro / xhigh`).

> **Ne pas démarrer un cycle `run-cycle`** — ce travail est une revue indépendante. Le livrable est un seul rapport sous `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_<timestamp>.md`.

---

## 0. Contexte humain (à lire avant tout)

Le propriétaire FoodKing exploite une chaîne de restaurants (POS encaissement + Kiosk/borne client + KDS cuisine + OSS écran client + Dashboard admin). Il a vécu ces dernières semaines une suite de bugs où il modifie un produit dans l'admin et **ce qu'il voit dans la borne et ce qu'il voit dans la caisse ne correspondent pas**, alors que la base de données est censée être unique.

Exemple symptomatique récent : la liste des **boissons** du wizard POS n'était pas synchronisée avec le catalogue (cycle `POS-V4-WIZARD-DRINKS-SYNC-2026-05-02` clôturé hier). Le fix a été appliqué mais le propriétaire perd confiance dans la chaîne globale et veut un **audit indépendant** par toi avant la mise en production.

Sa question principale, dans ses mots :

> « Tout ce qui est produits, catégories, suppléments, boissons — est-ce que la borne et la caisse lisent vraiment la même base de données ? Pourquoi ce qui est affiché diverge ? Où sont les points faibles de cette centralisation ? »

Ton rôle : répondre **par l'audit**, pas par le code.

---

## 1. Pré-requis de chargement (parcours obligatoire condensé)

Lecture **obligatoire** avant de produire ton rapport, dans cet ordre :

1. `**AGENTS.md`** § *Parcours obligatoire* + § *Authoritative multi-agent bounded cycle* — pour ne pas dérouter le travail.
2. `**.cursor/rules/project-invariants.mdc*`* — les invariants FoodKing que tu auditeras.
3. `**docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`** — carte canonique VA-SYS-09 du flux d'écriture/lecture (Dashboard → Backend → POS/Kiosk/KDS).
4. `**docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`** — symptom runbook officiel (« Product not visible on kiosk », « Product visible on POS but not kiosk », etc.).
5. `**docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md`** — la spec produit de bout en bout (types de produits, cycle de vie, `Definition of done`).
6. `**docs/sync/STOCK_SYNC_AND_AVAILABILITY.md**` — modèle stock 2 niveaux (produit + choix wizard).
7. `**docs/sync/WIZARD_PRODUCT_MODEL.md**` — modèle composer wizard.
8. `**docs/sync/API_VS_MCP_DECISION.md**` — la décision Version A : runtime = API Laravel + outbox, **pas** MCP.
9. `**docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md`** — état des PASS_LOCAL_STRONG par mission.
10. `**docs/MENU_PROJECTIONS.md`** — schéma DB section 5 (colonnes `channels`, `kiosk_sort`, `pos_sort`, `kiosk_label`).

Mémoire Graphiti / JSONL — appelle au moins ces requêtes :

```
search_memory_facts query="POS Kiosk catalog menu projection branch isolation"
search_memory_facts query="DomainEvent outbox correlation_id dédup CatalogChanged"
search_memory_facts query="ItemAvailabilityChanged kiosk cache invalidation"
search_memory_facts query="MenuProjectionService KioskMenuService divergence"
search_memory_facts query="VA-SYS-06 VA-SYS-07 VA-SYS-08 VA-SYS-09"
```

Et lecture ciblée des épisodes JSONL :

- `memory/episodes/02_architecture_invariants.jsonl` (frozen zones, DispatchableAfterCommit, BranchScope)
- `memory/episodes/03_domain_events_sync.jsonl` (outbox complet, 3 events critiques)
- `memory/episodes/06_kiosk_features.jsonl`
- `memory/episodes/07_pos_features.jsonl`

Si Graphiti MCP n'est pas chargé, lecture directe des JSONL ci-dessus + `memory/INDEX.md`.

---

## 2. Carte du système actuel (état que je viens de cartographier — vérifie-la)

### 2.1 Trois projections parallèles du même catalogue

Le catalogue physique est une seule paire de tables (`items` + `item_categories`) — bon. Mais **trois services** projettent ces données vers le runtime, et **POS et Kiosk en utilisent deux différents** :


| Surface                                                       | Endpoint runtime                             | Service backend                                                         | Branch-aware ?                                                              | Notes                                                                            |
| ------------------------------------------------------------- | -------------------------------------------- | ----------------------------------------------------------------------- | --------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| **POS catégories**                                            | `GET /api/admin/pos-category`                | `PosCategoryController@index` (raw `ItemCategory::with('media')` query) | **Non** (filtre `channels JSON contains 'pos' OR null`, pas de `branch_id`) | Injecte une catégorie virtuelle `id:0` « Toutes les catégories » côté contrôleur |
| **POS items**                                                 | `GET /api/admin/items`                       | `ItemController@index` → `ItemService::simpleList`                      | Oui (`forcePosRuntimeBranchScope` + `authorizeBranchScope`)                 | Resource = `SimpleItemResource`                                                  |
| **Kiosk** (catégories + items + allergens + upsell + locales) | `GET /api/frontend/menu`                     | `KioskMenuService::build($branch)`                                      | Oui (branche résolue via token `KioskMachine`)                              | 1 round-trip unifié + cache `kiosk.menu.branch.{branchId}`                       |
| **Admin SSOT** *(non consommé en V1)*                         | `GET /api/admin/menu-projection?channel={pos | kiosk                                                                   | web}&branch_id=X`                                                           | `MenuProjectionService::forChannel()`                                            |


**Ce que cela veut dire concrètement** : pour V1, POS et Kiosk **ne** lisent **pas** la même projection. POS lit deux endpoints legacy (catégories d'un côté, items de l'autre), Kiosk lit `KioskMenuService`. L'endpoint « SSOT » existe mais personne ne le consomme. C'est exactement le terrain où une divergence peut s'installer sans que personne ne s'en aperçoive.

### 2.2 Où ça pue (mes hypothèses — à challenger ou confirmer dans ton audit)

1. `**PosCategoryController` n'est pas branch-scoped.** Le filtre est uniquement par `channels JSON`. Si un admin crée une catégorie sans renseigner `channels` (cas par défaut, NULL), elle apparaît partout, y compris sur des branches qui ne devraient pas la voir. Cf. `docs/MENU_PROJECTIONS.md` §2 (« back-compat : NULL = visible everywhere »).
2. `**KioskMenuService` duplique la logique de filtrage** (`isVisibleOn`, `whereJsonContains`) au lieu de **déléguer** à `MenuProjectionService`. Tout fix qui doit être appliqué à l'un (ex. tri, langue, image fallback) peut oublier l'autre. Risque structurel de divergence permanente.
3. `**ItemController::itemDetails`** filtre la visibilité surface (`abort_unless($item->isVisibleOn($surface))`) mais `**ItemController::index`** (utilisé par le POS pour la liste) ne fait **pas** ce filtrage côté serveur — c'est `ItemService::simpleList` qui doit s'en charger ; à vérifier.
4. **Cache invalidation asymétrique** : `kiosk.menu.branch.{id}` est invalidé par `InvalidateKioskMenuCacheOnItemAvailabilityChanged` quand `ItemAvailabilityChanged` ou `CatalogChanged` est émis. **POS n'a pas de cache backend équivalent**, il refetch via `_onCatalogChanged` côté JS. Si Echo tombe, POS peut afficher du stale plus longtemps que Kiosk (ou inversement selon la fenêtre de polling).
5. **Catégorie virtuelle id:0** injectée par `PosCategoryController` ET `ItemCategoryController@frontend` côté legacy front : deux sources d'injection, label backend `all.label.all_items` (vient d'être renommé « Toutes les catégories » dans le cycle `POS-V4-DENSITY-VAT-2026-05-02`).
6. `**Item::isVisibleOn()`** : `NULL` channels = visible partout. Cette politique « back-compat » est une bombe à retardement : la première fois qu'un admin oublie de cocher `channels`, son nouveau produit apparaît sur web/POS/kiosk **et** sur toutes les branches.

### 2.3 Événements temps-réel et invalidation

Pile actuelle (validée VA-SYS-08 PASS_RUNTIME_LOCAL_STRONG) :


| Event                                                    | Émetteur                                                                     | Outbox listener                                                                     | Broadcast                 | Consommateurs                                                       |
| -------------------------------------------------------- | ---------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | ------------------------- | ------------------------------------------------------------------- |
| `ItemCreated` / `ItemUpdated` / `ItemDeleted`            | `ItemService`                                                                | `PersistCatalogChangedToOutbox`                                                     | `CatalogChanged`          | POS (refresh), Kiosk (refresh + cache invalidate), KDS (refresh)    |
| `CategoryCreated/Updated/Deleted`                        | `ItemCategoryController` via service                                         | `PersistCatalogChangedToOutbox`                                                     | `CatalogChanged`          | idem                                                                |
| `ItemAvailabilityChanged` (mode global ou branch-scoped) | `AvailabilityService::toggle()` + `ItemService::update` (admin status/price) | `PersistItemAvailabilityChangedToOutbox`                                            | `ItemAvailabilityChanged` | POS (in-place patch ou refresh), Kiosk (cache invalidate + refresh) |
| `StockLevelChanged`                                      | `StockService::decrementForOrder` / `releaseForOrder`                        | `PersistCatalogChangedToOutbox` (re-routage via `CatalogChanged::fromMenuMutation`) | `CatalogChanged`          | POS, Kiosk catalog refresh                                          |
| `ComposerProfileChanged/Published`                       | `ComposerProfileService`                                                     | `PersistCatalogChangedToOutbox`                                                     | `CatalogChanged`          | POS/Kiosk wizard projection refresh                                 |


Channel : `private-branch.{branch_id}`. Auth : `routes/channels.php`.

Côté front :

- `resources/js/services/eventContract.js` : `BROADCAST_MAP` mappe les noms PHP → types canoniques (`catalog.changed`, `menu.item_availability_changed`). LRU dedupe par `correlation_id` (capacity 2048, TTL 10 min, `sessionStorage` persistance).
- `PosComponent.vue::_onCatalogChanged` : refetch silencieux via `itemList(1, { overlay: false })`.
- `KioskAppComponent.vue` : refetch via `kioskMenu/fetchMenu`.

### 2.4 Couverture tests existante

PHPUnit (sentinels existants) :

- `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` — toggle stock central → kiosk + POS + order guard
- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`
- `tests/Feature/Menu/AvailabilityServiceTest.php`
- `tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php`
- `tests/Feature/Menu/BumpMenuSnapshotListenerTest.php`
- `tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php`
- `tests/Feature/Catalog/CatalogChangedDispatchTest.php`
- `tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php`
- `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php`
- `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`

Vitest :

- `tests/js/eventContractDedupe.spec.js`
- `tests/js/posRuptureUx.spec.js` / `tests/js/kioskRuptureUx.spec.js`

Playwright :

- `tests/e2e/c3-runtime-multi-surface.spec.js`

---

## 3. Ce que je veux que tu produises

### 3.1 Section A — Vérification de mon état des lieux (1-2 pages)

Pour chacun des **6 points faibles supposés** ci-dessus (§2.2), réponds avec un verdict :

- **CONFIRMÉ** (et cite file:line)
- **PARTIELLEMENT CONFIRMÉ** (cite file:line + nuance)
- **INVALIDÉ** (cite file:line qui démontre que ma lecture était fausse)

Si tu trouves d'autres points faibles que je n'ai pas listés, ajoute-les sous `2.2 — Points faibles supplémentaires découverts par audit`. Pour chaque nouveau point, donne **file:line + impact métier observable** (« le caissier verrait X alors qu'en réalité Y »).

### 3.2 Section B — Inventaire des divergences potentielles POS ↔ Kiosk (1 page)

Tableau croisé. Pour chaque attribut catalogue qui peut diverger entre POS et Kiosk, dis si la divergence est :

- **By design** (kiosk_label, kiosk_emoji, kiosk_sort, pos_sort — c'est documenté §2 de `docs/MENU_PROJECTIONS.md`)
- **Bug latent** (ex. cache invalidation asymétrique, branch_id pas appliqué côté POS)
- **Hors scope V1** (ex. variations multi-langue)

Format minimum :

```
| Attribut | POS | Kiosk | Divergence | Cause | Statut |
|----------|-----|-------|------------|-------|--------|
| name     | items.name | items.name OR kiosk_label | by design | section 5 dual-channel | OK |
| status   | items.status | items.status | aucun | unique colonne | OK |
| availability | item_branch_availability via ChoiceAvailabilityResolver | idem + cache TTL | latence | Echo vs polling | bug latent ? |
| ...      | ... | ... | ... | ... | ... |
```

Couvre au moins : `name`, `status`, `is_available`, `price`, `tax_id`, `channels`, `image/photo`, `variations`, `extras`, `addons`, `composer_profile`, `allergens`, `category.name`, `category.sort`, `category.image`.

### 3.3 Section C — Risques fiscaux / NF525 induits par une désynchro (½ page)

Si le POS et le Kiosk lisent des prix qui peuvent diverger même temporairement, quelles sont les conséquences fiscales (`composition_snapshot`, Z report, audit_log chain hash) ? Cite les invariants à risque (`docs/orchestration/MEMORY_MATRIX.md` + `memory/episodes/02_architecture_invariants.jsonl` épisodes 4, 12, 16).

### 3.4 Section D — Plan de remédiation hiérarchisé (page principale)

**3 vagues** sur **3 horizons**.

**Vague 1 — Quick wins sans changement de schéma (≤ 1 cycle masterplay)**

- Ce qui peut être fait sans toucher la DB, sans toucher les invariants, et qui réduit le risque de divergence visible.
- Pour chaque action : `file:line` cible + résumé du fix + sentinel test à ajouter.

**Vague 2 — Convergence vers la SSOT projection (1-3 cycles)**

- Faire en sorte que POS et Kiosk consomment **tous les deux** `MenuProjectionService::forChannel()` (le « V1.5 opt-in » prévu dans la doc, mais activé maintenant).
- Détailler : feature flag, migration progressive, parité contractuelle des payloads, ordre de livraison (POS d'abord ou Kiosk d'abord ?), tests de régression.
- Identifier les **frozen zones** touchées (`ItemComponent.vue`, `PaymentComponent.vue` ne devraient pas l'être ici, mais valide-le).

**Vague 3 — Refactor structurel (multi-cycles, hard gates)**

- Ce qui nécessite un schema migration ou un changement d'invariant.
- Exemple : politique « `channels = NULL` = visible partout » remplacée par un `channels = required` avec migration de backfill.
- Exemple : `tax_id` per-item étendu pour gérer le cas TVA emporter 5,5% / sur place 10% (note : ce point est déjà sous gate `docs/gates/GATE_POS_V4_VAT_HT_TTC_2026-05-02.md` — référence-le, ne le reproduis pas).

Pour chaque action des 3 vagues, indique :

- **Estimation effort** (S / M / L / XL)
- **Risque** (faible / modéré / élevé / critique)
- **Gate humain requis ?** (oui / non) avec rappel de quel gate
- **Tests de référence** à étendre ou créer

### 3.5 Section E — Definition of Done « Catalogue centralisé V1 final » (½ page)

Réécris la `Definition of done` produit centralisé de `PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md` §`Definition of done produit centralise` en l'enrichissant avec les éléments que tu auras découverts. Numérotée. Chaque item testable par un sentinel.

### 3.6 Section F — Verdict final et recommandation (10 lignes)

- Faut-il bloquer la mise en prod V1 sur ce sujet ? Si oui, **quel** point précis bloque.
- Si non, quelle est la dette technique acceptable et quel est l'écart à V1.5.
- Un seul verdict synthétique : `READY_FOR_V1` / `READY_WITH_DEBT_TICKET` / `BLOCK_V1`.

---

## 4. Périmètre file-by-file que tu peux ouvrir

**Backend services** (lecture intégrale) :

- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Menu/MenuSnapshot.php`
- `app/Services/Menu/AvailabilityService.php`
- `app/Services/Kiosk/KioskMenuService.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `app/Services/Stock/ChoiceAvailabilityResolver.php`
- `app/Services/Stock/StockService.php`
- `app/Services/ItemService.php` (méthodes `store`, `update`, `destroy`, `simpleList`)
- `app/Services/ItemCategoryService.php`
- `app/Services/ItemCategoryHierarchyService.php`

**Backend controllers** :

- `app/Http/Controllers/Admin/PosCategoryController.php`
- `app/Http/Controllers/Admin/PosController.php`
- `app/Http/Controllers/Admin/ItemController.php` (méthodes `index`, `itemDetails`, `lookupBarcode`)
- `app/Http/Controllers/Admin/ItemCategoryController.php`
- `app/Http/Controllers/Admin/MenuProjectionController.php`
- `app/Http/Controllers/Admin/AvailabilityController.php`
- `app/Http/Controllers/Frontend/MenuController.php` (méthode `kiosk`)
- `app/Http/Controllers/Frontend/ItemController.php`
- `app/Http/Controllers/Frontend/ItemCategoryController.php`

**Backend modèles** :

- `app/Models/Item.php` (lis attentivement `isVisibleOn`)
- `app/Models/ItemCategory.php` (lis `isVisibleOn`, `displayNameFor`, `sortFor`)
- `app/Models/ItemBranchAvailability.php`
- `app/Models/ItemVariation.php` / `ItemExtra.php` / `ItemAddon.php` / `ItemAttribute.php`
- `app/Models/ItemWizardProfile.php` / `ItemWizardStep.php`
- `app/Models/StockLevel.php` / `StockMovement.php`

**Backend events / listeners** :

- `app/Events/CatalogChanged.php`
- `app/Events/ItemAvailabilityChanged.php`
- `app/Events/{ItemCreated,ItemDeleted,StockLevelChanged}.php`
- `app/Listeners/PersistCatalogChangedToOutbox.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`
- `app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php`

**Backend resources / requests** :

- `app/Http/Resources/{ItemResource,SimpleItemResource,NormalItemResource,ItemCategoryResource,ItemCategoryMenuResource,ItemVariationResource,ItemExtraResource,ItemAddonResource}.php`
- `app/Http/Requests/ItemRequest.php`

**Frontend Vue (lecture ciblée — ne pas re-déchirer Wizard et Receipt qui sont validés)** :

- `resources/js/components/admin/pos/PosComponent.vue` (méthodes `itemList`, `_onCatalogChanged`, `_onItemAvailabilityChanged`, `loadCategories`)
- `resources/js/components/admin/pos/ItemComponent.vue` (frozen zone — lecture seule, surveille seulement le contrat de drinksCatalog)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (méthodes `mounted`, listeners CatalogChanged)
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/store/modules/item.js`
- `resources/js/store/modules/posCategory.js` (si existant)
- `resources/js/services/eventContract.js`

**Routes** :

- `routes/api.php` (lis les blocs `prefix('items')`, `prefix('item-category')`, `prefix('pos-category')`, `/menu-projection`, le bloc `frontend` ligne ~1080-1136)
- `routes/channels.php` (autorisation `branch.{branchId}`)

**Tests à parcourir pour comprendre les invariants déjà sentinellisés** :

- `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php`
- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`
- `tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php`
- `tests/Feature/Catalog/CatalogChangedDispatchTest.php`
- `tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php`
- `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`
- `tests/Feature/Composer/ComposerPublishSyncTest.php`
- `tests/Feature/SyncComprehensiveTest.php`
- `tests/Feature/KioskRealtimeBroadcastTest.php`
- `tests/js/posComponentMenuFiltering.spec.js` (si existe — sinon mentionne le manque)

**Doc de référence projet** :

- `docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`
- `docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md`
- `docs/MENU_PROJECTIONS.md`
- `docs/MENU_AVAILABILITY.md`

**Frozen zones à respecter (lecture seule, pas de proposition d'édit dedans)** :

- `app/Services/Orders/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/Payments/PaymentService.php`
- `app/Services/Pricing/` (read-only sauf si la remédiation y touche — alors gate brief obligatoire)
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`

---

## 5. Style et discipline du livrable

- **Format** : Markdown, dans `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_<YYYY-MM-DD>.md`. Header en haut avec date, modèle, effort, group_id Graphiti.
- **Citations** : tout verdict cite `file.php:line` ou `epi#N` (épisode JSONL). Pas de « il semble que », pas de « peut-être ». Si tu n'es pas sûr : « INDÉTERMINÉ — investigation requise via X ».
- **Token discipline** : ne paste pas de gros blocs de code. Cite la ligne, dis ce qu'elle fait, dis ce qui cloche.
- **Pas d'édition de code dans cette mission.** Tu ne touches qu'à `reports/audit/…` et éventuellement `docs/gates/GATE`_* si tu identifies un gate humain non encore ouvert.
- **Si tu trouves une violation d'invariant** : ne tente pas de fixer. Liste-la sous Section A point dédié, et propose le gate brief en Vague 3 §3.4.
- **Cross-référence MISSION 2.** Si un point appartient plus à la gestion stock + composition admin (mission #2 séparée), dis-le explicitement avec un lien vers `reports/audit/CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_2_STOCK_COMPOSITION_2026-05-02.md`.
- **Mémoire** : à la fin de ton audit, propose 1 à 3 épisodes JSONL à ajouter à `memory/episodes/12_decisions_log.jsonl` ou `09_tasks_history.jsonl` (format au minimum `{"name":"...", "source":"text|json", "source_description":"...", "episode_body":"..."}`). Ne les écris pas toi-même — propose-les en dernière section pour que je puisse les ingérer après validation humaine.

---

## 6. Question d'ouverture (la tienne, propose au début)

Avant ton audit, lis tout, puis pose-moi **maximum 3 questions de clarification** que tu juges bloquantes pour la qualité du livrable. Ne pose pas plus de 3. Si rien n'est bloquant, écris « Aucune question — j'ai tout ce qu'il faut » et passe à l'audit.

---

## 7. Sortie attendue (résumé exécutif obligatoire)

Ton fichier d'audit doit ouvrir par un résumé de **20 lignes maximum** qui contient :

1. Verdict (`READY_FOR_V1` / `READY_WITH_DEBT_TICKET` / `BLOCK_V1`)
2. Top 3 risques par ordre de gravité
3. Top 3 quick wins recommandés
4. Recommandation cycle suivant (un seul `TASK_ID` proposé)

Le reste du document = profondeur. Pas de redite. Pas de remplissage.

---

**FIN DU BRIEF MISSION 1.** Tu peux ouvrir tous les fichiers que tu juges utiles dans le périmètre §4. Si tu sors de ce périmètre, justifie-le explicitement dans la section où tu l'utilises.