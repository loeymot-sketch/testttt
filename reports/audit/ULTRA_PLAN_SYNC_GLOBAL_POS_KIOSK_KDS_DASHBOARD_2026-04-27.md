# Ultra Plan Sync Global POS / Kiosk / KDS / Dashboard — Codex Second-Brain Audit

Date: 2026-04-27  
Mode: audit + orchestration plan, no product patch  
Source input: Claude ultra-plan pasted by user + current repository inspection  
Execution model: codex-extension  
Verdict: `SYNC_V1_FOUNDATION_PASS__SYNC_V2_REQUIRES_SEQUENCED_MISSIONS_AND_SCHEMA_GATES`

## 1. Decision Courte

Le plan Claude est directionnellement bon, mais il doit etre adapte a l'etat reel du code.

FoodKing possede deja une base V1 fonctionnelle:

- catalogue central `items`, `item_categories`, `item_variations`, `item_extras`, `item_addons`;
- disponibilite branche via `item_branch_availability`;
- projection borne machine-scoped via `/api/frontend/menu`;
- projection POS branche-scoped via `/api/admin/item?surface=pos&branch_id=...`;
- garde backend quote/commit via `AvailabilityService` + `PricingService`;
- queue number protegee par index DB unique `(branch_id, queue_number)`;
- outbox `DomainEvent` + fanout branch channel deja utilise pour `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`.

Donc le meilleur choix n'est pas de remplacer brutalement par `stock_levels` maintenant. Le meilleur choix est:

1. Verrouiller V1 en corrigeant les gaps restants de synchronisation live et de details POS.
2. Centraliser le queue allocator sans nouveau schema.
3. Migrer progressivement vers `MenuProjectionService` comme projection catalogue unique.
4. Ajouter le vrai stock quantitatif append-only seulement apres gate schema dedie, en reutilisant/compatibilisant `item_branch_availability`.
5. Construire le Dashboard control plane autour de ces contrats, pas autour d'une deuxieme logique parallele.

## 2. Vision Systeme Corrigee

La caisse et la borne doivent partager les donnees, pas l'interface.

| Domaine | Shared SSOT | POS | Kiosk | Decision |
| --- | --- | --- | --- | --- |
| Categories | `item_categories` + channels/sort | UI dense operateur | UI visuelle client | Shared data, separate UX |
| Produits | `items` | grille rapide + modal POS | cards image + wizard client | Shared data, separate UX |
| Prix | DB + `PricingService` + quote HMAC | preview local pour vitesse, quote avant save | preview local + quote commit | Backend SSOT only |
| Options | variations/extras/addons + visible_on | modal POS/wizard POS | wizard borne | Shared composition data, separate rendering |
| Stock V1 | `item_branch_availability` | greyout/prune + backend guard | badge/disabled + backend guard | Current V1 OK |
| Stock V2 | future `stock_levels` + `stock_movements` | override staff audite | rupture visible client | Gate schema required |
| File attente | `orders.queue_number` DB unique | visible POS/KDS/OSS | visible waiting/receipt | D-M13 local OK |
| Commandes live | events outbox branch channel | POS live/intake | waiting/payment ack | Needs stronger live board |
| KDS | order events + KDS service | command/accept/handover | no KDS UI | Must remain server authority |
| Dashboard | admin routes + permissions | control plane | read-only consumer | POS/Dashboard writes, kiosk reads |

## 3. Evidence Actuelle

### 3.1 Catalogue et disponibilite item-level

Preuves code:

- `app/Services/Menu/AvailabilityService.php`
  - `assertItemsOrderableForBranch()` recharge les items et rejette item introuvable, inactive, `is_available=false`, ou rupture branche.
  - `releaseForOrderItems()` est idempotent via `order_items.released_qty`.
- `database/migrations/2026_04_15_230100_create_item_branch_availability_table.php`
  - `UNIQUE(item_id, branch_id)`.
  - colonnes `is_available`, `unavailable_reason`, `max_daily_qty`, `daily_consumed_qty`.
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
  - persiste payload uniforme dans `domain_events`.
  - dispatch apres commit via `DB::afterCommit`.
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
  - recoit `ItemAvailabilityChanged`, ignore les branches non actives, patch kiosk menu, prune panier/offline queue.
- `resources/js/components/admin/pos/PosComponent.vue`
  - recoit `ItemAvailabilityChanged`, greyout/prune panier POS, refetch en cas `type='full'`.

Tests relances pendant cet audit:

```text
php artisan test --filter='CatalogStockCentralSyncEndToEndTest|AdminItemBranchAvailabilityProjectionTest|AvailabilityControllerTest|AvailabilityServiceTest|BumpMenuSnapshotListenerTest|MenuProjectionControllerTest|MenuProjectionServiceTest|OrderRejectsUnavailableBranchItemTest|QueueNumberConcurrencyTest|QueueNumberUniquenessSentinelTest'
Result: 43 passed
```

```text
npx vitest run tests/js/posAvailabilityLiveGuard.spec.js tests/js/posItemAvailabilityHandler.spec.js tests/js/posCartPrune.spec.js tests/js/posCartPruneScoped.spec.js tests/js/adminAvailabilityToggle.spec.js tests/js/KioskCategoriesRestyle.spec.js tests/js/kioskOfflineQueueV2.spec.js
Result: 7 files passed, 56 tests passed
```

### 3.2 Queue number / D-M13

Preuves:

- `database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`
  - ajoute `orders_branch_queue_number_unique`.
  - preflight bloque la migration si doublons historiques.
- `tests/Feature/QueueNumberConcurrencyTest.php`
  - duplicate meme branche rejete.
  - meme numero sur branches differentes autorise.
  - `queue_number = null` reste permis pour legacy rows.
- `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`
  - confirme l'index unique.

Risque restant:

- L'allocation est encore dupliquee dans `OrderService` et `FrontendOrderService`.
- Le fallback microtime de queue number a ete retire, mais il manque une classe unique `QueueNumberAllocator`.
- Le lock actuel est `queue_lock_{branchId}` sans date; fonctionnel, mais plus large que necessaire.

Decision:

- V1: DB uniqueness + retry = acceptable.
- V2 hardening: centraliser l'allocateur.

### 3.3 Projection catalogue unique

Preuves:

- `app/Services/Menu/MenuProjectionService.php` existe et porte deja:
  - channel `pos|kiosk|web`;
  - `channels` null = visible partout;
  - `item_branch_availability`;
  - `snapshot_version`.
- Le commentaire dit explicitement que POS/Kiosk ne consomment pas encore cette projection en production.

Decision:

- Ne pas basculer d'un coup.
- D'abord tests de parite `KioskMenuService` / `MenuProjectionService`.
- Puis adapter une surface a la fois.

## 4. Audit Critique Du Plan Claude

| Proposition Claude | Verdict Codex | Raison / adaptation |
| --- | --- | --- |
| Un seul catalogue edite Dashboard/POS, lu par POS/Kiosk/KDS | ACCEPT | C'est la cible correcte. |
| Stock atomique DB + mouvements append-only | ACCEPT_FOR_V2 | Necessaire pour stock quantitatif reel, mais ne pas casser `item_branch_availability` V1. |
| `stock_levels` immediat | REWORK | Risque de double source de verite. Introduire via adapter/migration apres gate. |
| `branch_realtime_versions` | ACCEPT_LATER | Utile, mais `MenuSnapshot`/outbox existent deja. Ajouter quand consommateurs utilisent vraiment versioning. |
| Canaux `.stock`, `.catalog`, `.orders` separes | ACCEPT_LATER | Aujourd'hui `branch.{id}` marche. Splitter par concept quand observability/SLO est pret. |
| QueueNumberAllocator central | ACCEPT | A faire sans attendre stock V2, car D-M13 est deja localement vert. |
| POS live board complet | ACCEPT | Vraie valeur operationnelle: POS doit voir commandes kiosk/POS/web en cours. |
| Order handover explicite | ACCEPT | Necessaire pour sortie de commande propre et fiscal/OSS. |
| Dashboard CatalogManager/StockManager | ACCEPT | Mais construire sur APIs existantes d'abord, puis stock V2. |
| Offline POS park orders | DEFER | Plus risque que valeur V1; payment/stock offline doit etre gate. |
| 28 sentinels nouveaux d'un coup | REWORK | Trop large. Faire par train et stopper au premier invariant rouge. |

## 5. Matrice Fonctionnelle Detaillee

### 5.1 Gestion catalogue centrale

Etat actuel: solide pour CRUD produit/categorie/prix/images/availability item-level, avec tests recents.

Gaps:

- POS detail product ne passe pas encore `branch_id`; la liste POS est correcte, mais le modal detail peut etre moins strict.
- Create/delete produit/categorie ne provoque pas encore un refresh live POS aussi systematique qu'une mutation `type='full'`.
- `MenuProjectionService` pas encore consomme par les read paths actifs.

Plan:

1. `SYNC-V1-01-POS-DETAIL-BRANCH-OVERLAY`
2. `SYNC-V1-02-CATALOG-CHANGE-LIVE-REFRESH`
3. `SYNC-V1-03-MENU-PROJECTION-PARITY-LOCK`
4. `SYNC-V1-04-MENU-PROJECTION-KIOSK-WRAPPER`
5. `SYNC-V1-05-MENU-PROJECTION-POS-CONSUMER`

### 5.2 Gestion prix

Etat actuel:

- `PricingService` reste SSOT.
- `OrderQuoteService` scelle les commits POS/Kiosk.
- Les UI calculent seulement un running total ergonomique.

Decision:

- Ne pas introduire de calcul prix dans Dashboard.
- Dashboard edite seulement `items.price`, variations/extras/addons price, puis event/snapshot.
- Quote backend prouve le prix au paiement/commit.

### 5.3 Gestion stock V1 vs V2

V1 actuelle:

- Rupture item/branche via `item_branch_availability`.
- Daily cap simple via `max_daily_qty` / `daily_consumed_qty`.
- Release annulation/refund idempotente.
- Backend refuse si stale UI.

V2 cible:

- `stock_levels` quantitatif par branche/item.
- `stock_movements` append-only.
- event versionne avec correlation_id.
- reconciliation horaire.

Decision importante:

`stock_levels` ne doit pas etre ajoute comme deuxieme verite en parallele. Il doit devenir la verite quantitative, et `item_branch_availability` doit soit:

- rester une projection UX/admin de rupture item-level; soit
- etre migre vers `stock_levels.status`.

Gate requis:

- `HG-STOCK-V2-SOURCE-OF-TRUTH`
  - Option A recommande: `stock_levels` devient SSOT quantite/statut, `item_branch_availability` devient legacy projection/compat jusqu'a migration complete.
  - Option B: garder `item_branch_availability` comme V1 et ajouter seulement mouvements d'audit sans changer comportement.

### 5.4 Queue / file d'attente

Etat actuel:

- Unicite DB locale OK.
- POS et kiosk utilisent deux implementations similaires.

Gap:

- Pas encore une classe `QueueNumberAllocator`.
- Pas encore de test grep dedie "pas de queue fallback microtime".

Plan:

- `SYNC-V1-06-QUEUE-ALLOCATOR-CENTRALIZE`
- Allowlist:
  - `app/Services/Order/QueueNumberAllocator.php`
  - `app/Services/OrderService.php`
  - `app/Services/FrontendOrderService.php`
  - `tests/Feature/Order/QueueNumberAllocatorContractTest.php`
  - `tests/Feature/Sentinels/QueueNumberMicrotimeFallbackRemovedSentinelTest.php`

### 5.5 Commandes live POS / KDS / OSS

Etat actuel:

- POS ecoute `OrderCreated`/`OrderStatusChanged` sur branch channel pour kiosk cash orders.
- KDS ecoute order + availability et rafraichit.
- OSS a un service d'ecran commande avec `queue_number`.

Gaps:

- Pas encore un vrai `OrderLiveBoardComponent` cross-origin avec colonnes.
- Handover/remise client reste implicite.
- `PosOrderController::show()` utilise `withoutGlobalScope(BranchScope::class)` puis depend de `OrderService::show()`. A auditer avant live board pour eviter leak.

Plan:

- `SYNC-V1-07-POS-LIVE-LIST-BRANCH-SCOPE`
- `SYNC-V1-08-ORDER-HANDOVER-ENDPOINT`
- `SYNC-V1-09-KDS-OSS-HANDOVER-FANOUT`

### 5.6 Dashboard control plane

Etat actuel:

- Admin item/category/availability existe.
- Image produit modifiable et global refresh deja durci dans une mission recente.
- Composer produit: fondation min/max/repeat livree, pas builder complet.

Gaps:

- Pas encore un CatalogManager unifie "Shopify-like".
- Pas encore un StockManager quantitatif.
- Pas encore un assistant creation produit qui mappe clairement preset -> wizard steps -> options -> prix -> stock.

Plan:

- V1: renforcer l'existant sans schema nouveau.
- V2: builder complet apres audit data-model.

Missions:

- `SYNC-DASH-01-CATALOG-MANAGER-UNIFIED-SHELL`
- `SYNC-DASH-02-PRODUCT-COMPOSER-BUILDER-V1`
- `SYNC-DASH-03-STOCK-MANAGER-V1-ITEM-AVAILABILITY`
- `SYNC-DASH-04-STOCK-MANAGER-V2-QUANTITATIVE` apres gate stock V2.

## 6. Ordre D'Execution Responsable

### Train 0 — Etat et gates

Status actuel:

- Phase A targeted close: documentee.
- D-M13 local: verte.
- D-M13 prod rollout: gate operationnel encore ouvert.
- Safety-check global: bloque par `app/Services/OrderService.php` staged en zone frozen preexistante.

Action:

- Ne pas lancer de migration stock tant que le worktree/gate schema n'est pas propre.

### Train 1 — Sync V1 Hardening, sans nouvelle migration critique

Objectif: rendre la V1 vraiment robuste et utilisable sans introduire un gros schema stock.

1. `SYNC-V1-01-POS-DETAIL-BRANCH-OVERLAY`
   - Corrige le modal POS detail pour recevoir `branch_id`.
   - Risque faible.
   - Impact UX/operationnel direct.

2. `SYNC-V1-02-CATALOG-CHANGE-LIVE-REFRESH`
   - Standardise event create/delete/update categorie/item vers POS et kiosk.
   - Debounce refresh.
   - Pas de nouveau schema.

3. `SYNC-V1-03-QUEUE-ALLOCATOR-CENTRALIZE`
   - Cree un allocator unique.
   - Supprime duplication POS/kiosk.
   - Conserve D-M13 DB unique.

4. `SYNC-V1-04-POS-LIVE-LIST-CONTRACT`
   - Endpoint read-only branch-scoped pour commandes en cours.
   - Sentinelle kiosk order visible POS.

5. `SYNC-V1-05-ORDER-HANDOVER-DESIGN-AND-SENTINEL`
   - D'abord sentinel/contract autour transition READY -> DELIVERED/HANDOVER selon enum existant.
   - Pas de UI lourde avant decision status/terminologie.

### Train 2 — Projection catalogue unique

1. `SYNC-CATALOG-01-PROJECTION-PARITY-LOCK`
2. `SYNC-CATALOG-02-KIOSK-MENU-WRAPPER`
3. `SYNC-CATALOG-03-POS-MENU-CONSUMER`
4. `SYNC-CATALOG-04-MENU-VERSION-HEADER-AND-CLIENT-STALE-BANNER`

### Train 3 — Dashboard control plane utilisable

1. `SYNC-DASH-01-CATALOG-MANAGER-SHELL`
2. `SYNC-DASH-02-PRODUCT-COMPOSER-BUILDER-V1`
3. `SYNC-DASH-03-AVAILABILITY-MANAGER-V1`
4. `SYNC-DASH-04-PRODUCT-IMAGE-WORKFLOW-UAT`

### Train 4 — Stock V2 quantitatif, schema gated

Gate required: `HG-STOCK-V2-SOURCE-OF-TRUTH`.

1. `SYNC-STOCK-V2-00-ADR-AND-BACKFILL-PREFLIGHT`
2. `SYNC-STOCK-V2-01-MODEL-MIGRATIONS`
3. `SYNC-STOCK-V2-02-STOCK-SERVICE-ATOMIC-DECREMENT`
4. `SYNC-STOCK-V2-03-RELEASE-AND-RECONCILIATION`
5. `SYNC-STOCK-V2-04-REALTIME-VERSIONS`
6. `SYNC-STOCK-V2-05-UI-RUPTURE-CONTRACT-POS-KIOSK`

### Train 5 — Realtime, observability, offline, E2E, hardware

1. `SYNC-RT-01-CHANNEL-SPLIT-OR-METRIC-MAPPING`
2. `SYNC-OBS-01-MULTI-SURFACE-SLO-DASHBOARD`
3. `SYNC-OFFLINE-01-DEGRADATION-POLICY`
4. `SYNC-E2E-01-ADMIN-EDIT-KIOSK-POS-KDS-OSS`
5. `SYNC-HW-01-HARDWARE-LAB-UAT`

## 7. Missions Immediates Pretes Pour Codex

### Mission A — SYNC-V1-01-POS-DETAIL-BRANCH-OVERLAY

Objectif:

Le detail produit POS doit utiliser le meme overlay branche que la liste POS. Si un produit est en rupture branche, le modal ne doit jamais le presenter comme disponible.

Allowlist:

- `resources/js/store/modules/item.js`
- `resources/js/components/admin/pos/ItemComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `app/Http/Controllers/Admin/ItemController.php`
- `app/Services/ItemService.php`
- `app/Http/Resources/NormalItemResource.php`
- `tests/Feature/Menu/PosItemDetailBranchAvailabilitySentinelTest.php`
- `tests/js/posItemDetailBranchAvailability.spec.js`

Interdictions:

- ne pas toucher au wizard POS;
- ne pas toucher pricing;
- ne pas toucher queue;
- ne pas toucher migrations.

Validation:

- produit branche out -> liste POS grisee;
- ouverture detail directe avec `branch_id` -> `is_available=false`;
- add blocked or explicit staff override path only if already present;
- backend quote reste 422.

### Mission B — SYNC-V1-02-CATALOG-CHANGE-LIVE-REFRESH

Objectif:

Admin create/update/delete categorie/produit doit rafraichir POS + kiosk sans F5, avec debounce.

Allowlist:

- `app/Events/ItemAvailabilityChanged.php` ou nouveau `CatalogChanged` si vraiment necessaire;
- `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php`;
- `app/Services/ItemService.php`;
- `app/Services/ItemCategoryService.php`;
- `resources/js/components/admin/pos/PosComponent.vue`;
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`;
- `tests/Feature/Catalog/CatalogChangeFanoutSentinelTest.php`;
- `tests/js/posCatalogChangeLiveRefresh.spec.js`;
- `tests/js/kioskCatalogChangeLiveRefresh.spec.js`.

Decision:

Preferer reutilisation `ItemAvailabilityChanged type='full'` pour V1 si suffisant. Ne creer `CatalogEventContract` qu'en Train 2.

### Mission C — SYNC-V1-03-QUEUE-ALLOCATOR-CENTRALIZE

Objectif:

Une seule classe alloue `queue_number` pour POS et kiosk. D-M13 reste la protection DB.

Allowlist:

- `app/Services/Order/QueueNumberAllocator.php`
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `tests/Feature/Order/QueueNumberAllocatorContractTest.php`
- `tests/Feature/Sentinels/QueueNumberMicrotimeFallbackRemovedSentinelTest.php`

Validation:

- meme branche concurrente -> unique;
- branches differentes -> meme label possible;
- lock timeout -> 409/503 clair;
- aucun `microtime(true) * 10` dans allocator/order services.

## 8. Gates Humains Necessaires

| Gate | Question | Recommandation |
| --- | --- | --- |
| `HG-STOCK-V2-SOURCE-OF-TRUTH` | `stock_levels` remplace-t-il `item_branch_availability` ou cohabite-t-il? | Option A: `stock_levels` devient quantite/statut SSOT, `item_branch_availability` legacy projection temporaire |
| `HG-CATALOG-EVENT-CONTRACT` | Creer un contrat catalog events separe ou reutiliser `ItemAvailabilityChanged type='full'` V1? | V1 reutilise; V2 contrat dedie |
| `HG-AUTHZ-DASHBOARD-CATALOG-OPS` | Qui peut modifier catalogue/stock/prix/photos? | Owner/Director catalog, Manager stock ops, Cashier lecture/vente |
| `HG-HANDOVER-SEMANTICS` | Utiliser `DELIVERED` comme remise client magasin ou ajouter concept `handed_over_at`? | Garder enum existant si possible + ajouter `handed_over_at` |
| `HG-OFFLINE-DEGRADATION-POLICY` | POS offline peut-il vendre avec stock potentiellement stale? | Non pour V1, sauf park draft non encaissable |
| `HG-HARDWARE-LAB-SIGNOFF` | Test reel TPE/printer/cash drawer/KDS/kiosk | Obligatoire avant commercial release |

## 9. Ce Qu'il Ne Faut Pas Faire

1. Ne pas lancer `stock_levels` sans decideur humain: risque de double stock SSOT.
2. Ne pas supprimer `item_branch_availability` maintenant: il protege deja la V1.
3. Ne pas fusionner POS et kiosk visuellement: partager data, garder UX separee.
4. Ne pas ajouter de prix frontend dans le Dashboard: edition source seulement.
5. Ne pas introduire un canal `everything`: les metrics deviennent impossibles.
6. Ne pas changer OrderStatus sans audit KDS/OSS/fiscal.
7. Ne pas faire un builder produit complet avant de figer le modele de donnees wizard.
8. Ne pas ignorer le safety-check frozen `OrderService`: toutes missions qui touchent `OrderService` demandent gate/allowlist propre.

## 10. Go / No-Go Actuel

| Objectif | Statut |
| --- | --- |
| Meme catalogue central POS/Kiosk | `GO V1`, avec projection unique a migrer |
| Meme prix backend | `GO` |
| Rupture produit centrale vers POS/Kiosk | `GO V1` |
| Stock quantitatif atomique complet | `NO-GO`, schema/gate requis |
| Double queue_number | `GO local`, DB unique verte; allocator a centraliser |
| POS voit commandes kiosk | `GO partiel`, live board a renforcer |
| KDS/OSS sync | `GO partiel`, handover explicite a ajouter |
| Dashboard produit/photo/prix | `GO foundation`, builder complet a faire |
| Commercial release | `HOLD`, hardware/payment/i18n/legacy bundle gates encore ouvertes |

## 11. Conclusion

Claude a raison sur la cible long-terme: un FoodKing moderne doit avoir catalogue, stock, commandes, queue et realtime centralises. La correction Codex est dans l'ordre d'execution: ne pas jeter la base V1 deja prouvee. Il faut d'abord verrouiller les petits gaps qui peuvent encore generer une experience stale, puis seulement ouvrir les migrations stock V2.

Le chemin responsable est:

1. V1 hardening sans schema: POS detail branch overlay, live refresh catalog, queue allocator.
2. Projection catalogue unique.
3. Dashboard control plane utilisable.
4. Stock V2 quantitatif avec gate source-of-truth.
5. Order handover + POS live board + KDS/OSS.
6. Observability/offline/E2E/hardware.

SYNC_GLOBAL_CODEX_VERDICT: `READY_FOR_SEQUENCED_MISSIONS__DO_NOT_RUN_STOCK_V2_BEFORE_GATE`
