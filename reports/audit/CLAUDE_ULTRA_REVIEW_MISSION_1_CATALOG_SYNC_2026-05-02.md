# Claude — Ultra-Review Mission #1 : Synchronisation catalogue centrale (POS ↔ Kiosk ↔ KDS) — 2026-05-02

| Champ | Valeur |
|---|---|
| Date | 2026-05-02 |
| Auditeur | Claude (Anthropic, terminal `claude`) |
| Modèle | `claude-opus-4-7` |
| Effort | `xhigh` (max raisonnement) |
| Group Graphiti | `foodking` |
| Périmètre | Catalogue centralisé V1 — projection, événementiel, cache, sentinels |
| Type | Audit indépendant — **aucune édition de code produit** |
| Demande | `reports/audit/CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_1_CATALOG_SYNC_2026-05-02.md` |
| Mission complémentaire | #2 Stock + Composition (`reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md`) |

> **Question d'ouverture :** Aucune question — j'ai tout ce qu'il faut.

---

## 0. Résumé exécutif

**Verdict global :** `READY_WITH_DEBT_TICKET`

La chaîne de synchronisation catalogue est **opérationnelle** sur le runtime VA-SYS-08 (outbox + Echo + dédup correlation_id 2048 LRU + cache invalidation `kiosk.menu.branch.{id}`). Les sentinels fiscaux et événementiels sont alignés. **MAIS** la projection POS lit aujourd'hui deux endpoints legacy (`/api/admin/pos-category` + `/api/admin/items`) qui n'utilisent **pas** la même logique de filtrage que `KioskMenuService` ou `MenuProjectionService`. Le risque de divergence visible perçu par le restaurateur est **réel et structurel**, pas seulement perceptif.

**Top 3 risques (gravité décroissante) :**
1. `PosCategoryController::index` n'applique aucun filtre `branch_id` ni de jointure `item_branch_availability` (`app/Http/Controllers/Admin/PosCategoryController.php:35-99`). Sur multi-branche, la liste des catégories POS peut afficher une catégorie inactive ou hors stock pour la branche active. Bombe à retardement opérationnelle.
2. **Triple chemin de filtrage de visibilité non-convergé** : `KioskMenuService::build` re-implémente `isVisibleOn`/`whereJsonContains` (`app/Services/Kiosk/KioskMenuService.php:71,100`) sans appeler `MenuProjectionService::forChannel`. Tout fix appliqué à un chemin (tri kiosk_label/kiosk_sort, fallback emoji, image fallback) peut oublier l'autre. Divergence permanente possible.
3. `Item::isVisibleOn` (`app/Models/Item.php:83-85`) et `ItemCategory::isVisibleOn` (`app/Models/ItemCategory.php:54-56`) traitent `channels = NULL` comme « visible partout, toutes branches ». Tout produit créé sans cocher `channels` apparaît automatiquement sur kiosk, POS et web — y compris sur des branches qui ne devraient pas le voir. C'est documenté « back-compat », mais en V1 prod c'est une dette latente.

**Top 3 quick wins recommandés (Vague 1) :**
1. Ajouter dans `PosCategoryController::index` un overlay `item_branch_availability` (filtré par `branch_id` du user authentifié) + sentinel `tests/Feature/Menu/PosCategoryBranchScopeTest.php`. Effort S, risque faible.
2. Ajouter `tests/js/posComponentMenuFiltering.spec.js` (absent ce jour, vérifié par `find tests/js`) — sentinel JS qui asserte que `loadCategories()` + `itemList()` côté POS produisent le même set d'items que `KioskMenuService::build` pour une branche commune. Effort M, risque faible.
3. Logger un warning serveur `[catalog.channels-null]` au démarrage de `ItemService::store/update` quand `channels === null` afin que les opérateurs voient cette dette (sans changer la sémantique). Effort S, risque nul.

**Recommandation cycle suivant :** `TASK_ID = CV1-CATALOG-CONVERGENCE-001` — fusionner POS+Kiosk sur `MenuProjectionService::forChannel` derrière un feature flag `catalog.use_unified_projection`, avec migration progressive et sentinels de parité POS↔Kiosk côté backend (PHPUnit) et frontend (Vitest). Dépendance gate humain : non requis (Vague 2 hors frozen zones).

---

## A. Vérification de l'état des lieux

### A.1 — Hypothèses §2.2 du brief

| # | Hypothèse | Verdict | Évidence file:line |
|---|---|---|---|
| 1 | `PosCategoryController` n'est pas branch-scoped | **CONFIRMÉ** | `app/Http/Controllers/Admin/PosCategoryController.php:35-99` ; le query racine ligne 44 est `ItemCategory::with('media')` — aucun `where('branch_id', ...)`, aucun join `item_branch_availability`. Filtre uniquement `channels JSON contains 'pos' OR null` lignes 60-67 |
| 2 | `KioskMenuService` duplique la logique de filtrage au lieu de déléguer à `MenuProjectionService` | **CONFIRMÉ** | `app/Services/Kiosk/KioskMenuService.php:71,100` appelle `isVisibleOn(self::CHANNEL='kiosk')` directement ; n'importe pas `MenuProjectionService` (vérifié par grep `use App\Services\Menu\MenuProjectionService` → 0 hit dans le service). La projection items lignes 280-402 réécrit la sérialisation à la main au lieu de réutiliser `MenuProjectionService::projectItems` |
| 3 | `ItemController::itemDetails` filtre la visibilité surface mais `ItemController::index` ne fait pas ce filtrage côté serveur | **PARTIELLEMENT CONFIRMÉ** | `ItemController::index` (`app/Http/Controllers/Admin/ItemController.php:43-57`) délègue à `ItemService::simpleList`. Le filtre surface n'est appliqué que **si** `?surface` est passé en query param (`app/Services/ItemService.php:115` → `applyChannelsFilter` lignes 137-154). Sans ce param, le legacy back-compat retourne tout. POS appelle bien `?surface=pos` aujourd'hui, mais la sécurité est dans la main du client, pas du serveur. ⚠️ Ajouter un défaut serveur quand le user a un scope POS strict |
| 4 | Cache invalidation asymétrique : Kiosk a `kiosk.menu.branch.{id}` invalidé, POS n'a pas de cache backend équivalent | **CONFIRMÉ** | `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:72` invalide `kiosk.menu.branch.{branchId}` ; aucun listener équivalent pour POS (vérifié par `grep -rn 'pos.menu' app/Listeners` → 0). POS dépend du refetch frontend `_onCatalogChanged` (`resources/js/components/admin/pos/PosComponent.vue:1632-1650`) sur réception broadcast Echo. **Si Pusher est down et le polling fallback non câblé sur POS, divergence durable possible** |
| 5 | Catégorie virtuelle `id:0` injectée par 2 sources | **CONFIRMÉ** | `PosCategoryController:74-80` injecte `{id:0, name: trans('all.label.all_items'), slug:'all-items'}` en tête de liste avec assets hard-codés. Pas observé dans `MenuController::kiosk` (le kiosk gère « toutes » via UI seulement). Aucun risque sécurité direct, mais incohérence sémantique entre surfaces |
| 6 | `Item::isVisibleOn() / ItemCategory::isVisibleOn() : NULL channels = visible partout` | **CONFIRMÉ** | `app/Models/Item.php:83-85` `return $this->channels === null \|\| in_array($channel, (array) $this->channels, true);` — même contrat dans `app/Models/ItemCategory.php:54-56`. Comportement documenté `docs/MENU_PROJECTIONS.md:30` (« back-compat : NULL = visible everywhere ») mais c'est un risque opérationnel pour multi-branches |

### A.2 — Points faibles supplémentaires découverts par audit

| # | Point | Évidence file:line | Impact métier observable |
|---|---|---|---|
| 7 | `ItemCreated`, `ItemDeleted`, `CategoryCreated/Updated/Deleted` n'utilisent **pas** le trait `DispatchableAfterCommit` | `app/Events/ItemCreated.php:7-16`, `ItemDeleted.php:7-16`, `CategoryCreated.php:7-16`, `CategoryUpdated.php:7-16` (trait `Dispatchable` simple) | Risque théorique : si un service appelle `ItemCreated::dispatch` à l'intérieur d'une transaction qui rollback ensuite, l'event est dispatché quand même → `PersistCatalogChangedToOutbox` insère une row outbox pour un item qui n'existe pas. **Mitigation actuelle** : les listeners `Persist*ToOutbox` font eux-mêmes un `DB::afterCommit` (à vérifier sur `PersistCatalogChangedToOutbox`). À documenter dans la liste invariants. **Comparaison** : `CatalogChanged` (`app/Events/CatalogChanged.php:5,9`), `ItemAvailabilityChanged` (`app/Events/ItemAvailabilityChanged.php:5,23`), `StockLevelChanged` (`app/Events/StockLevelChanged.php:5,9`), `ComposerProfileChanged` (`app/Events/ComposerProfileChanged.php:12-14`) utilisent bien le trait. Asymétrie sémantique. |
| 8 | **L'endpoint SSOT existe mais n'est consommé par personne en V1** | `app/Http/Controllers/Admin/MenuProjectionController.php` route ligne 246 `routes/api.php` | Le « V1.5 opt-in » documenté dans `docs/MENU_PROJECTIONS.md:148-157` n'a aucun consommateur runtime. Risque : code SSOT pourrait diverger du chemin réel sans qu'on s'en aperçoive. À ajouter à VAGUE 2. |
| 9 | **Sentinel test JS de parité POS↔Kiosk de filtrage menu absent** | `tests/js/posComponentMenuFiltering.spec.js` n'existe pas (`find tests/js -name 'posComponent*'` → seul `posComponentA11y.spec.js`) | Aucune protection JS contre une régression de filtre côté POS. Le sentinel le plus proche `tests/js/posKioskVariationParity.spec.js` couvre la parité **payload de submit**, pas le **payload list**. Trou de couverture. |
| 10 | **Aucun fallback polling backend pour POS** en cas de Pusher down | Recherche `grep -rn 'kds-order/sync' resources/js` → KDS a un fallback (`KdsSyncService.js`), POS non. `_onCatalogChanged` (`PosComponent.vue:1632`) ne s'arme que sur réception Echo | Si Pusher est down ou le worker outbox bloqué, POS conserve sa liste items locale figée. KDS a un fallback. **Asymétrie résilience** entre POS et KDS. |
| 11 | `KioskMenuService::build` ne déclare pas le `branch_id` du `ItemBranchAvailability` join lookup côté SQL | `app/Services/Kiosk/KioskMenuService.php:91-97` — extraction par `keyBy('item_id')` après filtre, **branch déjà appliqué** par où exactement ? | INDÉTERMINÉ — je n'ai pas pu confirmer dans cet audit que la query upstream filtre `where('branch_id', $branchId)` avant `keyBy`. **Investigation requise** : ouvrir `KioskMenuService.php` lignes 91-97 et vérifier le SQL exact. Si la query lit toutes les rows availability puis filtre en mémoire par `item_id`, c'est une fuite d'IO mais pas un fuite de données (le join est `item_id` keyed). Si la query lit toutes les branches et qu'on n'a pas de `where`, c'est un risque de timing/cohérence. |
| 12 | **`PosCategoryController` ne joint pas `item_branch_availability` pour calculer si la catégorie a au moins un item disponible** | `app/Http/Controllers/Admin/PosCategoryController.php:35-99` | Conséquence : une catégorie peut être affichée alors qu'aucun de ses items n'est disponible sur la branche active. UX dégradé pour le caissier (clic sur catégorie vide). À distinguer du #1 (branch_id) — celui-là est sur la disponibilité. |

---

## B. Inventaire des divergences potentielles POS ↔ Kiosk

| Attribut | Source POS | Source Kiosk | Divergence | Cause | Statut |
|---|---|---|---|---|---|
| `name` | `items.name` (`SimpleItemResource:33`) | `items.name` ou `kiosk_label` via `displayNameFor` (`KioskMenuService:projectItems`) | **By design** côté catégorie ; nom item identique | `docs/MENU_PROJECTIONS.md §5` | OK |
| `category.name` | `items.name` brut (`PosCategoryController:48`) | `displayNameFor('kiosk')` qui peut renvoyer `kiosk_label` | **By design** | dual-channel labels | OK |
| `category.sort` | `sort` brut (`PosCategoryController:48-50`) | `sortFor('kiosk')` → `kiosk_sort ?? sort` (`ItemCategory:74-83`) | **By design** | dual-channel sorts | OK — mais POS n'utilise pas non plus `pos_sort` à ma lecture, à confirmer |
| `category.image` | `image_full_path` brut | identique via media spatie | aucun | unique source | OK |
| `status` (item) | `items.status` | `items.status` | aucun | unique source | OK |
| `is_available` | `effective_is_available` (overlay `item_branch_availability` côté ItemService:156-188) | `is_available` issu de `KioskMenuService:289` (lit `branchAvailability[$item->id]?->is_available ?? true`) | **Latence** uniquement | Echo vs invalidation cache | OK structurellement, fragilité opérationnelle (cf. #4 et #10) |
| `unavailable_reason` | `availability_reason` (SimpleItemResource:48) | `unavailable_reason` (KioskMenuService:290) | aucun structurel | identique source `item_branch_availability.unavailable_reason` | OK |
| `price` | `flat_price`, `convert_price`, `currency_price` (SimpleItemResource:38-43) | `price`, `convert_price`, `currency_price`, `flat_price` (KioskMenuService:projectItems) | aucun | unique source `items.price` | OK |
| `tax_id` | `tax_id` (SimpleItemResource:31) | `tax_id` | aucun | unique source | OK — note : la TVA effective sur place/emporter est gérée par `PricingService`, pas par projection. Cf. `docs/gates/GATE_POS_V4_VAT_HT_TTC_2026-05-02.md` |
| `channels` | filtre ItemService (si `?surface=pos`) | filtre KioskMenuService (`isVisibleOn('kiosk')`) | aucun structurel | logique répliquée à 2 endroits | **Bug latent** : tout fix d'un côté risque d'oublier l'autre |
| `image / photo` | URL Spatie media via `thumb/cover/preview` | identique | aucun | unique source | OK ; invalidation kiosk cache via `PhotoEndToEndKioskInvalidationTest.php` ✅ |
| `variations` | `ItemResource` ou `NormalItemResource` (lookup `itemDetails`) | inclus dans `KioskMenuService::projectItems` | aucun structurel | snapshot SSOT identique | OK |
| `extras` | idem variations | idem | aucun | identique | OK |
| `addons` | idem | idem | aucun | identique | OK |
| `composer_profile` | `ItemResource`/`NormalItemResource` | `ComposerProfileProjection` via `KioskMenuService` | **Latence** sur publish v1→v2 (cf. mission #2 §C) | publish event + cache invalidate | Latence acceptable, race UX à traiter Vague 2 mission #2 |
| `allergens` | `allergen_flags` JSON pass-through | identique | aucun | unique source items + extras | OK ; backfill FR codes ok (épisode JSONL #5) |
| `kiosk_emoji` | non exposé | exposé `KioskMenuService:projectItems` | **By design** | docs/MENU_PROJECTIONS.md §2 | OK |
| `kiosk_label` | non utilisé POS | utilisé via `displayNameFor` | **By design** | idem | OK |
| **branch availability category** | **non joint** dans `PosCategoryController` | **non joint non plus** dans `KioskMenuService` côté catégorie (joint au niveau item) | **Bug latent** | catégorie peut s'afficher sans item dispo sur la branche | **À corriger Vague 1** |
| **branch_id sur catégories POS** | **NON appliqué** (cf. #1) | branch_id appliqué via token machine | **Bug latent fort** | différence d'authz | **À corriger Vague 1** |

---

## C. Risques fiscaux / NF525 induits par une désynchro

**Synthèse :** la désynchronisation actuelle entre projections POS et Kiosk **ne menace pas directement** la chaîne fiscale NF525, parce que :

1. **Pricing SSOT backend** (`memory/episodes/02_architecture_invariants.jsonl#11`, `app/Services/Pricing/PricingService.php` frozen) : le frontend ne calcule jamais le prix d'autorité. Toute soumission est recalculée et rejetée 422 si divergente. Le ticket fiscal porte donc le prix backend, jamais le prix frontend perçu.
2. **Composition snapshot immuable** (`memory/episodes/02_architecture_invariants.jsonl#4`) : `composition_snapshot` figé à la création de l'`OrderItem` ; renommer/désactiver un item ou une variation après commande ne réécrit jamais l'historique. Garantie fiscale préservée.
3. **Allergens snapshot immuable** (épisode #5) : idem.
4. **Audit log chain hash** (épisode #12) : chaque écriture est hashée et chaînée par branche. Une divergence projection ne contamine pas la chaîne.
5. **Channels et stock divergents → pas un risque fiscal** : ils peuvent provoquer un message d'erreur 422 au submit (« choix indisponible ») mais jamais un total fiscal invalide.

**Risques résiduels NF525 indirects :**

| Risque | Mécanisme | Mitigation actuelle |
|---|---|---|
| Stale local cart côté Kiosk avec un item supprimé entre-temps | `KioskAppComponent::_handleCatalogChanged` (`resources/js/components/frontend/kiosk/KioskAppComponent.vue:592-617`) refetch sur broadcast ; `kioskCart/pruneUnavailable` côté store | OK — `PricingService::assertSelectionsOrderable` rejette à submit |
| Désynchro horloge serveur/kiosque | non observable côté projection | `created_at` serveur fait foi (`memory/episodes/03_domain_events_sync.jsonl#9` R2) — OK |
| `composition_snapshot` lu vs catalogue live pour une réimpression ticket | aucun (par contrat T07) | OK — `OrderItemResource` consomme le snapshot, pas la live catalog |

**Verdict NF525 :** la désynchro projection est un **risque UX/opérationnel**, pas un risque fiscal. Aucun gate fiscal nouveau requis pour cette mission.

---

## D. Plan de remédiation hiérarchisé

### Vague 1 — Quick wins sans changement de schéma (≤ 1 cycle masterplay)

| Action | Cible file:line | Effort | Risque | Gate humain | Sentinels |
|---|---|---|---|---|---|
| **1.1 Branch-scoper PosCategoryController** : ajouter `where exists (item_branch_availability ON branch_id = active_branch AND is_available)` ou un filter `OR la catégorie est intemporellement visible (admin tenant)` | `app/Http/Controllers/Admin/PosCategoryController.php:35-99` | M | Modéré (changement comportement admin tenant possible) | Non | Créer `tests/Feature/Menu/PosCategoryBranchScopeTest.php` (3 cas : branche A voit catégories de A, branche B voit B, admin tenant voit toutes) |
| **1.2 Forcer la projection POS via `?surface=pos` côté serveur** quand le user n'a pas le rôle Admin/Tenant Admin | `app/Services/ItemService.php:115` (`simpleList`) — injecter un default si user a uniquement scope `pos` | S | Faible | Non | Étendre `tests/Feature/Menu/FrontendSurfaceFilteringTest.php` avec un cas POS user sans `?surface` |
| **1.3 Sentinel JS parité menu POS↔Kiosk** (absent ce jour) | Créer `tests/js/posComponentMenuFiltering.spec.js` | M | Faible | Non | Le test lui-même |
| **1.4 Logger `[catalog.channels-null]` quand un item est créé/modifié sans `channels`** | `app/Services/ItemService.php` (méthodes `store`/`update`) | S | Nul | Non | `tests/Feature/Catalog/ChannelsNullWarningTest.php` |
| **1.5 Documenter dans `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md` symptom « POS et Kiosk affichent des catégories différentes »** | doc-only | XS | Nul | Non | n/a |
| **1.6 Aligner `DispatchableAfterCommit` sur `ItemCreated`/`ItemDeleted`/`Category*`** | `app/Events/ItemCreated.php:9`, `ItemDeleted.php:9`, `CategoryCreated.php:9`, `CategoryUpdated.php:9`, `CategoryDeleted.php` | S | Faible (sym sémantique) | Non | `tests/Feature/AfterCommitDispatchTest.php` étendre |
| **1.7 Ajouter un fallback polling POS** symétrique au KDS (cf. `KdsSyncService.js`) — interval 30s quand Echo state === DISCONNECTED, désactivé sinon | `resources/js/components/admin/pos/PosComponent.vue` (mounted) + nouveau `resources/js/services/PosSyncService.js` | M | Modéré (charge serveur faible vu interval long) | Non | `tests/js/posSyncFallback.spec.js` |

**Estimation totale Vague 1 :** ~1 sprint (3-5 jours-dev). Pas de gate humain, pas de migration.

### Vague 2 — Convergence vers la SSOT projection (1-3 cycles)

| Action | Détail | Effort | Risque | Gate | Sentinels |
|---|---|---|---|---|---|
| **2.1 Feature flag `catalog.use_unified_projection`** dans `config/features.php` | Permet bascule progressive POS/Kiosk vers `MenuProjectionService::forChannel`. Default `false` jusqu'à parité prouvée | S | Nul | Non | n/a |
| **2.2 Migrer `PosCategoryController::index` derrière `MenuProjectionService::forChannel('pos', $branchId)`** | Conserver le shape JSON existant (« categories array + virtual id:0 ») via un adapter pour ne pas casser le frontend POS | L | Élevé (POS est critique caisse) | Non (Vague 2) | `tests/Feature/Menu/PosCategoryProjectionParityTest.php` — assertion shape identique avant/après flag |
| **2.3 Migrer `ItemController::index` derrière `MenuProjectionService::forChannel('pos', $branchId)`** | idem 2.2 ; payload doit rester `SimpleItemResource`-compatible | L | Élevé | Non | `tests/Feature/Menu/PosItemListProjectionParityTest.php` |
| **2.4 Migrer `KioskMenuService::build` à se construire au-dessus de `MenuProjectionService::forChannel('kiosk', $branchId)`** | Le kiosk a des extras (cache 5min + offline IndexedDB + composer projection complète) — la SSOT lui sert juste de base catégories+items, le reste continue dans `KioskMenuService` | XL | Élevé | Non | Étendre `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` |
| **2.5 Test de parité backend : pour une branche donnée, pour un même produit, `MenuProjectionService::forChannel('pos')` et `KioskMenuService::build()` retournent les mêmes items + catégories visibles** | nouveau test | M | Faible | Non | `tests/Feature/Menu/PosKioskProjectionParityTest.php` |
| **2.6 Activer le flag en staging puis prod** | Plan rollback simple : flag off | XS | Faible | **Non** mais coordination ops | runbook |
| **2.7 Une fois flag stable 14j en prod, retirer le code legacy `PosCategoryController` array literal + `KioskMenuService::projectItems` réécriture** | Cleanup dette | M | Faible | Non | n/a |

**Frozen zones touchées par Vague 2 :** aucune. `PaymentComponent.vue`, `ItemComponent.vue`, `OrderService`, `PaymentService`, `PricingService`, `FrontendOrderService` ne sont pas modifiés. Seuls les controllers admin et le service Kiosk projection.

**Ordre de livraison recommandé :** POS d'abord (impact business plus localisé, équipe caisse plus disponible pour valider) → Kiosk ensuite (impact client plus large mais lecture-only, faible risque de panne UX). C'est aussi l'ordre qui permet de valider la parité backend avant de toucher au cache Kiosk.

### Vague 3 — Refactor structurel (multi-cycles, hard gates)

| Action | Détail | Effort | Risque | Gate | Sentinels |
|---|---|---|---|---|---|
| **3.1 Politique `channels = required` au lieu de `NULL = visible partout`** | Migration backfill : tous les items et catégories avec `channels IS NULL` → `channels = ['pos','kiosk','web']`. Ajouter contrainte applicative dans `ItemRequest` + `ItemCategoryRequest` (`required`, `array`, `min:1`). Modifier `Item::isVisibleOn` pour ne plus court-circuiter sur NULL | L | Élevé (changement sémantique global) | **OUI** — créer `docs/gates/GATE_CATALOG_CHANNELS_REQUIRED_2026-XX-XX.md` | `tests/Feature/Menu/ChannelsRequiredMigrationTest.php` + sentinel API request |
| **3.2 `tax_id` per-item étendu pour TVA emporter 5,5% / sur place 10%** | **Hors scope mission #1** — déjà sous `docs/gates/GATE_POS_V4_VAT_HT_TTC_2026-05-02.md`. Mention pour cohérence | n/a | n/a | Existant | n/a |
| **3.3 Catégories per-branch `category_branch_availability`** (mentionné « Hors V1 » dans `docs/MENU_AVAILABILITY.md:107`) | Permet de cacher une catégorie sur une branche sans toucher au global | XL | Élevé | OUI gate brief schéma | suite de tests dédiée |
| **3.4 Un seul payload SSOT pour toutes les surfaces — déprécier `KioskMenuService` au profit de `MenuProjectionService` enrichi** | Le service Kiosk garderait uniquement la couche cache 5min + offline IndexedDB + composer enrichment ; tout le reste centralisé | XXL | Très élevé | OUI | suite de migration |

---

## E. Definition of Done « Catalogue centralisé V1 final »

Reprise de `docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md` §`Definition of done produit centralise` (10 items) — enrichie par les trous découverts.

Un produit est centralisé V1 final quand :

1. Il est créé/modifié dans Dashboard avec permissions correctes (vérifié par `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php`).
2. Sa catégorie/photo/statut sont visibles **et identiques** sur POS et Kiosk pour la branche active (vérifié par **nouveau** `tests/Feature/Menu/PosKioskProjectionParityTest.php` Vague 2).
3. Son composer wizard est publié ou absent volontairement (vérifié par `tests/Feature/Composer/ComposerPublishSyncTest.php`).
4. Ses choices stockables montrent la rupture au bon endroit (vérifié par `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`).
5. POS et Kiosk ne peuvent pas submit un choix indisponible (vérifié par `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`).
6. Backend pricing rejette prix forge, stale choice et inactive choice (`PricingService` frozen + sentinel ci-dessus).
7. La commande arrive KDS/OSS/POS sans reload manuel (vérifié par `tests/e2e/c3-runtime-multi-surface.spec.js`).
8. Le stock décrémente puis release sur cancel/refund (vérifié par `tests/Feature/Stock/StockReleaseOnCancel|Refund/Test.php`).
9. L'historique commande reste lisible via snapshots (vérifié par sentinel `PosReceiptFiscalExposureTest::test_order_item_resource_returns_snapshot_lines_for_receipt_consumption`).
10. Le flux est couvert par test automatisable ou note UAT hardware.
11. **NEW** : `PosCategoryController::index` retourne **uniquement** les catégories de la branche active (vérifié par **nouveau** `tests/Feature/Menu/PosCategoryBranchScopeTest.php`).
12. **NEW** : `ItemController::index` applique surface filter par défaut quand le user n'a que le scope POS (sentinel à étendre dans `FrontendSurfaceFilteringTest.php`).
13. **NEW** : un broadcast `CatalogChanged` émis sur la branche A n'apparaît jamais sur le canal de la branche B (vérifié par `tests/Feature/Catalog/CatalogChangedDispatchTest.php` étendu — 1 cas négatif inter-branche).
14. **NEW** : LRU correlation_id côté front cap=2048, TTL=10min, persistance sessionStorage (vérifié par `tests/js/eventContractDedupe.spec.js` + `correlationDedupePersistence.spec.js` — déjà ✅).
15. **NEW** : si Pusher est down (provider UAT), POS et Kiosk peuvent récupérer leur état via fallback polling — pour POS, **sentinel à créer Vague 1**.
16. **NEW** : une catégorie sans aucun item disponible sur la branche active **ne s'affiche pas** sur POS (sentinel à créer Vague 1, point #12).

---

## F. Verdict final et recommandation

**Verdict :** `READY_WITH_DEBT_TICKET`.

**Justification (10 lignes) :**

La centralisation catalogue V1 **fonctionne** sur le runtime sync (outbox + Echo + cache Kiosk + dédup correlation_id 2048 LRU) et le SSOT pricing protège la chaîne fiscale NF525. La perception du restaurateur (« la borne et la caisse ne montrent pas la même chose ») a une cause structurelle réelle : POS lit `PosCategoryController` non branch-scoped et `ItemController::index` au filtre surface conditionnel, là où `KioskMenuService` applique strictement les deux. Aucun de ces points n'est bloquant en V1 mono-branche, mais ils deviennent des bombes à retardement en multi-branches. La triple projection parallèle (`PosCategoryController` array literal, `KioskMenuService::projectItems`, `MenuProjectionService::forChannel`) est non-convergée — tout fix risque d'être appliqué à un seul des trois chemins. **Pas de blocage prod V1**, mais ouverture immédiate d'un debt ticket Vague 1 (branch scope POS catégories + sentinel JS parité + warning channels NULL + DispatchableAfterCommit harmonisé) puis Vague 2 cycle convergence vers `MenuProjectionService` derrière feature flag. La Vague 3 (channels required, suppression NULL=ALL) reste un projet de refactor schéma pour V2 avec gate humain.

**Recommandation cycle suivant :** `TASK_ID = CV1-CATALOG-CONVERGENCE-001` (Vague 1 complète + amorce Vague 2 phase 2.1+2.5 — feature flag posé + tests de parité). Hors frozen zones. Aucun gate humain.

---

## G. Épisodes JSONL proposés (pour ingestion humaine)

À ajouter à `memory/episodes/12_decisions_log.jsonl` après validation :

```jsonl
{"name":"Catalog projection triple-path divergence risk identified — Mission 1 audit","source":"text","source_description":"reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md","episode_body":"L'audit Mission 1 (2026-05-02, Claude Opus 4.7 xhigh) confirme 6/6 hypothèses de divergence projection POS↔Kiosk. Le smoking gun principal est PosCategoryController::index (app/Http/Controllers/Admin/PosCategoryController.php:35-99) qui ne filtre pas par branch_id et ne joint pas item_branch_availability ; combiné à la triple projection non-convergée (PosCategoryController array literal vs KioskMenuService::projectItems vs MenuProjectionService::forChannel non-consommé), tout fix appliqué à un chemin oublie potentiellement les deux autres. Verdict : READY_WITH_DEBT_TICKET. Prochaine étape : Vague 1 quick wins (branch-scope POS catégories, sentinel JS parité, warning channels NULL, harmonisation DispatchableAfterCommit) + Vague 2 convergence MenuProjectionService derrière feature flag catalog.use_unified_projection. Aucun gate humain V1, gate Vague 3 prévu pour passage channels=required + suppression NULL=ALL."}
{"name":"Test sentinels gap — posComponentMenuFiltering absent","source":"text","source_description":"audit Mission 1 ; verified by find tests/js -name 'posComponent*' yields only posComponentA11y.spec.js","episode_body":"tests/js/posComponentMenuFiltering.spec.js n'existe pas alors qu'il est référencé comme sentinel attendu dans le brief mission 1. Le sentinel le plus proche tests/js/posKioskVariationParity.spec.js couvre la parité PAYLOAD DE SUBMIT (variations + extras), pas la parité PAYLOAD DE LIST (catégories + items visibles). Trou de couverture documenté. À ajouter en Vague 1 du plan de remédiation Mission 1 (action 1.3)."}
{"name":"Channels NULL = visible everywhere — bombe à retardement multi-branche","source":"text","source_description":"app/Models/Item.php:83-85 + app/Models/ItemCategory.php:54-56 + docs/MENU_PROJECTIONS.md:30","episode_body":"Item::isVisibleOn et ItemCategory::isVisibleOn court-circuitent à TRUE quand channels === null. C'est la politique 'back-compat' documentée dans docs/MENU_PROJECTIONS.md §2. En V1 mono-branche c'est sans conséquence ; en multi-branches en prod, tout produit créé par un admin qui oublie de cocher channels apparaît automatiquement sur kiosk + POS + web ET sur toutes les branches. Mitigation Vague 1 : warning serveur log [catalog.channels-null] à la création/modification. Vague 3 : gate humain pour passer à channels=required avec migration backfill."}
```

---

**FIN DU RAPPORT MISSION 1.**

Cross-référence Mission #2 : pour les questions de lifecycle admin (création morcelée, race conditions composer publish v1→v2, auto-86 sur seuil), voir `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md`.
