# Phase 2 Globale — Centralisation + Synchronisation POS/Kiosk/KDS/OSS

Date mission : 2026-04-27  
Exécution audit : 2026-04-26, session Codex locale  
Prompt source : `reports/audit/CLAUDE_PROMPT_PHASE2_GLOBALE_CENTRALISATION_SYNC_2026-04-27.md`  
Mode : AUDIT + MATRICE + PLAN uniquement. Aucun patch produit. Aucune suppression.

## 0. Verdict Exécutif

`PHASE2_STRATEGIE = PRET_POUR_PLANIFICATION_EXECUTION_SEQUENTIELLE_APRES_GATES_P0`

La Phase 2 est faisable, mais pas sous forme d'un "grand switch dashboard centralisé" immédiat. Le système actuel contient déjà des fondations solides : backend pricing SSOT, `MenuProjectionService`, availability branch-scopée, outbox `DomainEvent`, KDS/OSS branch-scopés, queue par branche, tests de parité POS/Kiosk. En revanche, les intersections critiques ne sont pas encore assez verrouillées pour centraliser catalogue, prix, stock et file d'attente sans amplifier les dérives.

Blocages P0 avant centralisation réelle :

1. `queue_number` n'a pas encore de contrainte DB `(branch_id, queue_number)` ; le sentinel existe et reste le gate D-M13.
2. Le catalogue a plusieurs lectures actives : `KioskMenuService`, chemins POS, `MenuProjectionService` admin/projection. Le service unifié existe mais les consommateurs POS/Kiosk ne sont pas encore branchés dessus.
3. Les changements de catalogue ne couvrent pas uniformément snapshot, cache, outbox et frontend cache. Les events category/item flushent le cache kiosk, mais le `MenuSnapshot` n'est bumpé que sur `ItemAvailabilityChanged`.
4. Les variations/extras/addons ont des services CRUD séparés sans preuve d'event de catalogue ; ce sont des mutations de prix/composition et donc des intersections P0 si le Dashboard Phase 2 les centralise.
5. Les catégories ne sont pas branch-scopées ; elles sont globales avec `channels`, `kiosk_sort`, `pos_sort`, `kiosk_label`, mais sans `branch_id` ni pivot branche/catégorie.
6. La documentation de contrat realtime/outbox a du drift par rapport à `EventContract.php`, notamment sur `_origin`, `payment_method`, `queue_number`.
7. Le worktree est encore massivement sale ; c'est acceptable pour cet audit, mais pas pour une exécution multi-missions sans allowlists strictes.

Décision responsable : exécuter Phase 2 par paliers de synchronisation vérifiables, pas par refonte large. Chaque palier doit produire une preuve : sentinel, test ciblé, validation outbox/cache/snapshot, puis audit contradictoire.

## 1. Sources et Mémoire

Graphiti : consulté avant analyse via `search_memory_facts(group_ids=["foodking"])`. Faits récupérés utiles : FoodKing a POS/Kiosk/KDS comme surfaces primaires, `PosKioskPricingParityTest.php` est un sentinel de parité prix, KDS et écran de statut dépendent de l'ordre et de la file.

Secours mémoire lu : `memory/INDEX.md`, notamment les domaines `03_domain_events_sync`, `04_pricing_ssot`, `06_kiosk_features`, `07_pos_features`, `08_kds_features`, `10_tests_coverage`, `12_decisions_log`.

Sources disque lues ou ciblées :

| Chemin | Usage |
| --- | --- |
| `AGENTS.md` | Doctrine, invariants FoodKing, no gate self-approval, frozen zones. |
| `.cursor/ACTIVE_CYCLE.md` | Contexte vivant ; ambiguïté W10 + Caisse V1 notée comme risque gouvernance. |
| `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Routage Claude/GPT, Graphiti, discipline d'orchestration. |
| `reports/audit/CLAUDE_PROMPT_PHASE2_GLOBALE_CENTRALISATION_SYNC_2026-04-27.md` | Mandat exact Phase 2. |
| `docs/DEVICE_FLOW.md` | Rôles Kiosk, POS, KDS, OSS. |
| `docs/ORDER_FLOW.md` | SOT commandes, state machine, outbox après commit. |
| `docs/BUSINESS_RULES.md` | Pricing backend SSOT, availability branch-scopée, events. |
| `docs/ARCHITECTURE.md` | Stack, frozen zones, responsabilités. |
| `routes/api.php`, `routes/channels.php` | Surfaces API admin/POS/KDS/OSS/frontend + auth realtime. |
| `app/Services/Kiosk/KioskMenuService.php` | Lecture menu borne actuelle. |
| `app/Services/Menu/MenuProjectionService.php` | Projection unifiée existante, non encore consommée par POS/Kiosk. |
| `app/Services/Menu/AvailabilityService.php` | Mutations availability, outbox/cache/snapshot. |
| `app/Listeners/*Menu*`, `app/Providers/EventServiceProvider.php` | Couverture events/cache/snapshot. |
| `app/Domain/Events/EventContract.php`, `app/Jobs/DispatchDomainEventsJob.php` | Contrat outbox/realtime. |
| `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php` | POS/Kiosk order flow, branch_id, queue_number. |
| `app/Services/KitchenDisplaySystemOrderService.php`, `app/Services/KdsSyncService.php` | KDS list/status/delta. |
| `app/Services/OrderStatusScreenOrderService.php` | OSS branch/global visibility. |
| `database/migrations/*item_categories*`, `*queue_number*`, `*idempotency*` | Contraintes DB critiques. |
| `tests/Feature/*Menu*`, `*Kiosk*`, `*Outbox*`, `*QueueNumber*`, `*PosKioskPricingParity*` | Sentinels existants. |
| `borne (Remix)/ARCHIVE_BANNER.md`, `kiosk_implementation/ARCHIVE_BANNER.md` | Archives legacy déjà quarantined. |

## 2. Modèle Mental Correct Phase 2

La Phase 2 ne doit pas créer une deuxième vérité. Elle doit rendre visibles et administrables les vérités déjà canonisées :

| Domaine | Source autoritaire actuelle | Ce que Phase 2 peut ajouter | Ce qu'elle ne doit pas faire |
| --- | --- | --- | --- |
| Prix | Backend `PricingService` + quote HMAC + commit backend | UI Dashboard d'édition contrôlée | Calculer prix côté frontend ou projection JS. |
| Catalogue | DB items/categories/variations/extras/addons + services backend | Projection unique par surface/canal | Duplicata projection POS/Kiosk divergente. |
| Availability | `item_branch_availability` via `AvailabilityService` | UI centralisée, monitoring, bulk ops | Court-circuiter le modèle branch-item. |
| Commandes | `orders`, `OrderService`, `FrontendOrderService` | Vue cross-canal, actions selon permissions | Contourner state machine ou BranchScope. |
| File | `queue_number` par branche/date, bientôt contrainte DB | Dashboard global de suivi | Inventer numéros non uniques cross-canal sans migration D-M13. |
| Realtime | `domain_events` + `DispatchDomainEventsJob` + Echo | Observabilité, replay, DLQ | Broadcast direct avant commit DB. |
| KDS | `KitchenReleaseRule`, statuses actifs, branch scope | Supervision cuisine | Exposer commandes hors branche. |
| OSS | Orders token/kiosk/takeaway avec queue_number | Vue centrale ou branchée | Afficher globalement hors rôle admin. |

## 3. Matrice d'Intersection Globale

| # | Surface | Type | Write path | Read path | Sync mécanisme | Latence / risque | Test existant | Gap | Criticité |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Dashboard/Admin availability | Stock disponibilité item/branche | `Admin\AvailabilityController` -> `AvailabilityService` -> `item_branch_availability` | Kiosk menu, POS guards, order commit, KDS indirect | `ItemAvailabilityChanged::forBranch`, outbox, `MenuSnapshot`, cache kiosk flush | Bonne base ; latence dépend worker/outbox et cache local frontend | `AvailabilityServiceTest`, `BumpMenuSnapshotListenerTest`, `CacheInvalidationTest` | Vérifier fanout frontend kiosk localStorage/heartbeat en Phase 2 | P0 |
| 2 | Admin Item CRUD | Catalogue/prix/composition | `ItemService::store/update/destroy` | `KioskMenuService`, POS menu, `MenuProjectionService`, order pricing | `ItemCreated/ItemDeleted` -> cache flush ; update émet `ItemAvailabilityChanged::fromItem` | Event update réutilise availability event pour prix/structure ; sémantique floue | Tests menu/projection partiels | Event contract catalog dédié absent ; snapshot catalog non uniforme | P0 avant Phase 2 |
| 3 | Admin Category CRUD | Structure menu | `ItemCategoryService::store/update/destroy` | Kiosk/POS projections, admin projection | `CategoryCreated/Updated/Deleted` -> cache kiosk flush | `MenuSnapshot` non bumpé sur category events ; polling projection peut rester stale | `CacheInvalidationTest` couvre create cache | Ajouter sentinel snapshot category + event coverage | P0 avant Dashboard |
| 4 | Variations / extras / addons | Composition et prix | `ItemVariationService`, `ItemExtraService`, `ItemAddonService` | Pricing, Kiosk menu, POS menu, order item snapshot | Pas de preuve d'event catalog/outbox dans services dédiés | Changement prix/composition peut rester invisible en cache/projection | Parité prix POS/Kiosk existe, pas mutation CRUD | Event/snapshot/cache coverage à créer | P0 |
| 5 | Kiosk menu | Lecture catalogue borne | `/api/frontend/menu`, `MenuController`, `KioskMenuService::build` | Vue kiosk/store/cache local | Cache serveur `kiosk.menu.branch.{id}`, TTL 60 ; cache local frontend snapshot 5 min selon audit antérieur | Dual path avec `MenuProjectionService` ; drift possible | `KioskEndpointsTest`, JS kiosk menu cache specs | Migrer vers projection unifiée seulement après parity sentinel | P0/P1 |
| 6 | POS menu | Lecture catalogue caisse | POS APIs/controllers/store JS | Vue POS, cart, live guard | Echo availability + backend guard | Doit rester strictement pricing SSOT ; pas de stale cart checkout | `posAvailabilityLiveGuard.spec`, `PosKioskPricingParityTest` | Cart prune/projection parity à renforcer avec Dashboard writes | P0 |
| 7 | Kiosk quote/order | Prix/commit borne | `/api/frontend/order/quote`, `/api/frontend/order`, `OrderQuoteService`, `FrontendOrderService` | POS realtime, KDS, OSS | Quote HMAC, order created/status outbox | Bonnes corrections récentes, mais Phase 2 ne doit pas contourner quote | `KioskQuoteIntegrityTest`, `KioskFullFlowE2ETest` | Garder invariant : aucune décision prix depuis `window.POS_WIZARD_CONFIG` | P0 |
| 8 | POS quote/order | Prix/commit caisse | `/api/admin/pos-orders/quote`, `/api/admin/pos-orders`, `OrderService` | KDS, OSS, reports fiscal | Quote HMAC, outbox, state machine | Reste lié au gate D-M13 pour queue unique | POS quote-binding tests, pricing parity | Phase 2 Dashboard ne doit pas créer ordre hors quote | P0 |
| 9 | Queue number | File cross-canal | `OrderService`, `FrontendOrderService` MAX+1 sous cache lock | Tickets POS/Kiosk, KDS, OSS, outbox | App lock + fallback ; pas contrainte DB actuelle | Collision concurrente possible ; sentinel rouge attendu | `QueueNumberUniquenessSentinelTest` | D-M13 gate obligatoire avant migration | P0 bloquant |
| 10 | Order outbox | Sync POS/KDS/OSS | `PersistOrderCreatedToOutbox`, `PersistOrderStatusChangedToOutbox` | Echo private branch, listeners frontend | `DomainEvent`, `DispatchDomainEventsJob`, after commit | Contrat solide en code ; docs anciennes | `EventContractTest`, `KioskRealtimeBroadcastTest` | Docs/examples outbox à aligner | P1 |
| 11 | KDS list/status | Cuisine | KDS change status endpoint -> `KitchenDisplaySystemOrderService` | KDS list/delta | DB transaction + `OrderStatusChanged` dispatch after commit | Branch isolation bonne ; delta repose sur `updated_at` | KDS tests existants | Ajouter sentinel Phase 2 pour Dashboard status edit impossible hors state machine | P0 |
| 12 | KDS sync delta | Sync cuisine | Status/order updates | `/api/admin/kds/sync` | `updated_at` since + deleted_ids | Commentaire TODO sur `status_changed_at`; risque si future mutation ne touche pas updated_at | KDS sync tests à localiser | Phase 2 doit définir version monotonic order-stream | P1 |
| 13 | OSS | Écran statut client | Orders POS/Kiosk/takeaway | `OrderStatusScreenOrderService` | DB read + branch/global scope | Global OSS seulement admin branch 0 ; bon guard | OSS/Kiosk tests partiels | Dashboard global doit réutiliser même scope | P0 |
| 14 | Branch realtime channels | Isolation Echo | `routes/channels.php` | POS/Kiosk/KDS listeners | `private-branch.{branch}` guard machine/staff/admin | Bonne base ; attention permissions backoffice | Channel auth tests à confirmer | Phase 2 rôle catalogue séparé requis | P0 |
| 15 | Frontend caches | Stale UI | Server menu/cache, localStorage/IDB/offline queue | Kiosk/POS UI | TTL + heartbeat + realtime availability | Stale catalogue prix/variation possible si Dashboard write | Vitest kiosk cache/offline | Besoin version monotonic globale par branch/surface | P0/P1 |
| 16 | Legacy archives | Nettoyage/dedup | Aucun write runtime attendu | Potentiel build/import accidentel | Banners + lint legacy | Public bundles peuvent être runtime, archives peuvent être sûres mais à prouver | legacy strict lint | Archive move seulement après manifest + gate | P1 gouvernance |
| 17 | Documentation contracts | Alimentation Claude/Codex | Docs manuelles | Agents, prompts, audits | Aucun sync auto | `docs/EVENT_CONTRACT.md` et `docs/REALTIME_SETUP.md` divergent du code | Code tests passants | Corriger docs avant grosses missions Codex | P1 |
| 18 | Gouvernance repo | Exécution multi-agent | Git + reports + memory | Orchestrateurs | Activity log + reports | 598 entrées status au moment de l'audit ; Phase A encore non close | Rapports Phase A | Ne pas lancer refonte large sans allowlist par mission | P0 process |

## 4. Findings Techniques Priorisés

### P0-1 — D-M13 reste le seul gate de schéma bloquant release/file

Preuve : `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php` exige un index unique contenant `branch_id` et `queue_number`. Les migrations montrent seulement `2026_03_06_170846_add_queue_number_to_orders_table.php` ajoutant la colonne, pas l'unicité. `OrderService` et `FrontendOrderService` génèrent encore par MAX+1 sous lock applicatif.

Impact : toute Phase 2 qui affiche ou orchestre une file cross-canal POS/Kiosk/KDS/OSS doit attendre la décision D-M13. Sans contrainte DB, l'UI centrale peut masquer une collision jusqu'à production.

Décision : ne pas attaquer D-M13 dans ce cycle. Préparer le prompt uniquement après gate humain signé.

### P0-2 — Le "catalogue unique" existe comme service, mais pas comme chemin consommé

Preuve : `MenuProjectionService` porte la projection par canal et branche, mais son commentaire indique que les consommateurs POS/Kiosk ne sont pas encore branchés. `KioskMenuService` construit encore une projection borne séparée.

Impact : Dashboard Phase 2 risque d'écrire une vérité DB qui est lue de façon différente par POS et Kiosk. Le test de parité prix ne suffit pas à garantir parité de structure, tri, labels, variations, extras et disponibilité.

Décision : première mission Phase 2 doit être une mission de parité de projection, pas une migration frontend.

### P0-3 — `MenuSnapshot` ne couvre pas tous les changements de catalogue

Preuve : `EventServiceProvider` bump `MenuSnapshot` uniquement via `ItemAvailabilityChanged`. Les events `ItemCreated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted` vont vers `InvalidateKioskMenuCacheOnCatalogChange`, qui flush le cache mais ne bump pas le snapshot.

Impact : un consommateur Phase 2 qui se fie au snapshot pour savoir si une projection a changé peut rater un changement de catégorie ou de structure.

Décision : créer un event/listener catalog snapshot spécifique ou élargir l'écoute existante avec tests de couverture.

### P0-4 — Variations/extras/addons sont des mutations de prix/composition sans event clair

Preuve : `ItemExtraService`, `ItemAddonService`, `ItemVariationService` font CRUD direct ; la recherche ciblée n'a pas montré d'event catalog/snapshot/cache dans ces services. Ces entités sont consommées par `PricingService`, `KioskMenuService`, POS et order snapshots.

Impact : modifier un extra à 1,50 -> 2,00 depuis Dashboard peut ne pas invalider projection/cache ou bump version. Le commit ordre reste backend SSOT, mais l'UI peut présenter une valeur stale.

Décision : traiter comme intersection P0 avant Dashboard prix/modificateurs.

### P0-5 — Catégories globales, pas branch-scopées

Preuve : `create_item_categories_table` ne contient pas `branch_id`. La migration channel ajoute `channels`, `kiosk_sort`, `pos_sort`, `kiosk_label`, pas de pivot branche.

Impact : si Phase 2 veut des catégories par branche, il faut un modèle explicite. Ajouter une UI "catégorie branche X" sans modèle produira un faux isolement.

Décision : gate design séparé : `category_branch_visibility` pivot ou statu quo global assumé.

### P0-6 — Rôle Dashboard catalogue à définir

Preuve : routes admin actuelles mélangent permissions POS/order/catalog. Les audits antérieurs signalent déjà des risques sur `PosOrderController::show()` et permissions OR.

Impact : la centralisation Dashboard ne doit pas réutiliser les rôles caisse pour éditer catalogue, prix, stock, ou voir toutes branches.

Décision : rôle `backoffice-catalog` / `backoffice-ops` à formaliser dans AUTHZ avant code.

### P1-1 — Docs outbox/realtime stales

Preuve : `EventContract.php` exige `_origin`, `payment_method`, `queue_number` pour order events. `docs/EVENT_CONTRACT.md` et `docs/REALTIME_SETUP.md` contiennent encore des exemples/phrases plus anciens.

Impact : alimente mal Claude/Codex et augmente les faux patches sur fixtures.

Décision : corriger docs avant missions Phase 2 longues.

### P1-2 — Nettoyage legacy doit rester archivé, pas supprimé

Preuve : `borne (Remix)/ARCHIVE_BANNER.md` et `kiosk_implementation/ARCHIVE_BANNER.md` disent déjà legacy archive non-runtime. Les bundles `public/js/kiosk.js`, `public/js/kiosk-wizard.js`, `public/js/pos-wizard.js` ne doivent pas être traités comme supprimables sans preuve build/runtime.

Impact : suppression directe peut casser blades legacy ou shims encore utilisés.

Décision : seulement proposer `archive/phase2-dedup-2026-04-27/` + `MANIFESTE.md`, après gate humain.

## 5. Audit Duplicates / Legacy / Nettoyage

Règle appliquée : aucune suppression. Tout nettoyage est un déplacement proposé vers archive avec manifeste.

| Chemin | Statut observé | Risque suppression | Décision |
| --- | --- | --- | --- |
| `borne (Remix)/` | Archive banner : legacy non-runtime | Moyen si import caché non détecté | Garder. Si nettoyage : déplacer vers `archive/phase2-dedup-2026-04-27/borne-remix/` + manifest après gate. |
| `kiosk_implementation/` | Archive banner : legacy non-runtime | Moyen | Garder. Même protocole archive. |
| `public/js/kiosk.js` | Bundle legacy/lint strict mentionné dans audits | Élevé | Ne pas supprimer. Classifier runtime vs generated. Décider shim signé vs archive. |
| `public/js/kiosk-wizard.js` | Bundle legacy/lint strict mentionné | Élevé | Même décision que `kiosk.js`. |
| `public/js/pos-wizard.js` | Bundle POS possiblement chargé par blade | Élevé | Ne pas déplacer sans map blade/build. |
| `resources/js/bootstrap-kiosk.js` | Import actif côté app | Élevé | Primaire, ne pas archiver. |
| `resources/js/store/modules/kioskCart.js` et helpers offline | Actifs, corrigés récemment | Élevé | Primaire. |
| `resources/js/pos-app.js`, `resources/views/admin/pos-v4/*` | POS V4 actif selon routes/blades | Élevé | Primaire. |
| `reports/audit/*`, `missions/*`, `memory/episodes/*` | Gouvernance, beaucoup d'untracked | Élevé process | Décision Phase A, pas nettoyage technique. |

Manifeste requis pour tout déplacement futur :

```md
# MANIFESTE archive/phase2-dedup-YYYY-MM-DD

## Raison
Déduplication Phase 2 sans suppression.

## Preuves avant déplacement
- rg imports: PASS
- webpack/mix/vite references: PASS
- blade/script references: PASS
- tests/lint ciblés: PASS

## Chemins déplacés
| Origine | Destination | Pourquoi | Gate signé |
| --- | --- | --- | --- |

## Rollback
Commande de retour par chemin, sans `git reset --hard`.
```

## 6. Ultra Plan Phase 2

### Phase 0 — Stabiliser l'orchestration et les gates

Objectif : empêcher la Phase 2 de se mélanger au reliquat Phase A/Caisse.

Actions :
1. Confirmer le cycle actif unique dans `.cursor/ACTIVE_CYCLE.md`.
2. Décider le statut des rapports/memory untracked.
3. Figé : aucun code D-M13 sans décision humaine.
4. Créer une matrice "Data Owner" signée : prix, catalogue, stock, file, order status, fiscal, roles.

Exit binaire :
- `ACTIVE_PRIMARY` unique.
- `git status` bucketé par mission.
- `reports/audit/PHASE2_DATA_OWNERSHIP_MATRIX_YYYY-MM-DD.md` créé et audité.

Gate : humain si modification `.cursor/ACTIVE_CYCLE.md` ou migration.

### Phase 1 — Parité projection catalogue avant migration

Objectif : prouver que `MenuProjectionService` peut devenir la projection canonique sans casser POS/Kiosk.

Actions :
1. Ajouter tests read-only comparant projection kiosk à `KioskMenuService` sur catégories, items, availability, channels, sort, labels.
2. Ajouter tests read-only comparant projection POS aux attentes POS actuelles.
3. Couvrir variations/extras/addons/promos/upsells si consommés par surface.
4. Ne pas migrer encore les consommateurs.

Exit binaire :
- Sentinel `MenuProjectionParityPosKioskTest` PASS.
- Aucun changement de code productif hors tests/projection si rework minimal nécessaire.
- Parité prix existante reste verte.

### Phase 2 — Couverture events/snapshot/cache catalogue

Objectif : chaque mutation catalogue qui change l'expérience utilisateur bump version + invalide caches + est observable.

Actions :
1. Créer ou étendre listener `BumpMenuSnapshotOnCatalogChange`.
2. Couvrir `ItemCreated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted`.
3. Ajouter coverage pour item update, variation, extra, addon, prix, channel, sort.
4. Décider contrat outbox menu : event dédié ou pas. Ne pas détourner `ItemAvailabilityChanged` pour tout.

Exit binaire :
- Tests mutation catalog -> snapshot branch/global bump.
- Tests mutation variation/extra/addon -> projection version change.
- Kiosk cache server invalidation prouvée.

Gate : si nouveau event contract public ajouté.

### Phase 3 — Migration consommateurs POS/Kiosk vers projection unifiée

Objectif : remplacer les lectures divergentes par la projection backend canonique, par surface et sous tests.

Actions :
1. Kiosk : basculer `/api/frontend/menu` vers service projection ou adapter `KioskMenuService` comme wrapper de projection.
2. POS : basculer lecture menu vers projection POS, sans logique prix frontend.
3. Garder les guards de commit backend inchangés.
4. Ajouter métrique version/snapshot dans payload pour refresh local.

Exit binaire :
- Kiosk menu specs PASS.
- POS availability/cart guard specs PASS.
- `PosKioskPricingParityTest` PASS.
- Aucun calcul prix frontend ajouté.

### Phase 4 — Branch scoping catégories et rôles Dashboard

Objectif : décider si catégories restent globales ou deviennent branch-visibles.

Actions :
1. ADR : catégories globales avec availability par item, ou pivot `category_branch_visibility`.
2. Si pivot : migration après gate, UI Dashboard explicite, backfill.
3. Rôle catalogue : `backoffice-catalog` pour CRUD catalogue, `backoffice-ops` pour availability, pas permissions caisse.

Exit binaire :
- ADR signée.
- AUTHZ matrix mise à jour.
- Tests branch visibility.

Gate : humain obligatoire si migration/pivot.

### Phase 5 — Queue / D-M13

Objectif : verrouiller unicité file avant dashboard cross-canal.

Actions :
1. Ne pas coder sans D-M13 signé.
2. Après signature : migration unique `(branch_id, queue_number)` ou stratégie décidée.
3. Retry sur duplicate key dans POS/Kiosk.
4. Backfill et rollback documentés.

Exit binaire :
- `QueueNumberUniquenessSentinelTest` PASS.
- POS/Kiosk concurrent creation sentinel PASS.
- KDS/OSS conservent queue_number.

Gate : D-M13 humain.

### Phase 6 — Dashboard centralisé incrémental

Objectif : livrer un backoffice utile sans casser les chemins runtime.

Ordre conseillé :
1. Read-only dashboard projection : catalogue par branche/canal + availability + snapshot version.
2. Availability write : toggles branchés sur `AvailabilityService`.
3. Catalogue write : item/category/variation/extra/addon seulement après Phase 2.
4. Queue/order read-only : seulement après D-M13.
5. Actions order/status : seulement via services existants et state machine.

Exit binaire :
- Aucun write Dashboard ne bypass un service existant.
- Chaque write a un event/cache/snapshot test.
- Playwright POS + Kiosk + KDS sur même branche.

### Phase 7 — Cleanup / archive dédupliquée

Objectif : réduire le bruit sans perdre de preuve ni casser runtime.

Actions :
1. `rg` imports + blade references + build config.
2. Déplacer uniquement les chemins prouvés non-runtime vers `archive/phase2-dedup-YYYY-MM-DD/`.
3. Ajouter `MANIFESTE.md`.
4. Ne jamais supprimer.

Exit binaire :
- Manifest complet.
- Lint/build/tests verts.
- Gate humain signé.

## 7. Tâches Codex Proposées

Chaque mission ci-dessous doit avoir :
- branche dédiée ;
- allowlist stricte ;
- pas de `git add -A` ;
- self-audit GPT ;
- audit contradictoire Claude ou Codex final ;
- preuve `git diff --check` ;
- aucun élargissement sans escalation.

### PH2-P0-01-DATA-OWNERSHIP-MATRIX

Type : audit/docs only.

Objectif : figer qui écrit/lit quoi avant tout code Phase 2.

Allowlist modifier :
- `reports/audit/PHASE2_DATA_OWNERSHIP_MATRIX_YYYY-MM-DD.md`
- `docs/decisions/D-PH2-DATA-OWNERSHIP.md` si gate humain demandé

Lire seulement :
- `app/Services/**`
- `routes/api.php`
- `docs/BUSINESS_RULES.md`
- `docs/ORDER_FLOW.md`

Critères :
- Matrice prix/catalogue/availability/order/status/file/outbox/roles.
- Pas de code produit.
- Double audit : vérifier chaque owner contre routes + services.

### PH2-P0-02-MENU-CATALOG-EVENT-SNAPSHOT-COVERAGE

Objectif : toute mutation catalogue pertinente bump snapshot et invalide cache.

Allowlist modifier :
- `app/Providers/EventServiceProvider.php`
- `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php`
- `app/Listeners/BumpMenuSnapshotOnCatalogChange.php` (new si choisi)
- `app/Events/*Catalog*` (new si gate event accepté)
- `tests/Feature/Menu/MenuCatalogMutationSyncTest.php` (new)
- `tests/Feature/Cache/CacheInvalidationTest.php`

Lire seulement :
- `app/Services/ItemService.php`
- `app/Services/ItemCategoryService.php`
- `app/Services/ItemVariationService.php`
- `app/Services/ItemExtraService.php`
- `app/Services/ItemAddonService.php`
- `app/Services/Menu/MenuSnapshot.php`

Interdictions :
- Pas de migration.
- Pas de modification pricing.
- Pas de frontend.

Double audit :
1. Audit couverture : comparer toutes routes CRUD catalogue à un event/version bump.
2. Audit anti-drift : vérifier qu'aucun event availability n'est utilisé pour masquer un event prix/structure sans justification.

### PH2-P0-03-MENU-PROJECTION-PARITY-SENTINELS

Objectif : prouver que projection unifiée peut remplacer chemins actuels.

Allowlist modifier :
- `tests/Feature/Menu/MenuProjectionParityPosKioskTest.php` (new)
- `tests/Feature/PosKioskPricingParityTest.php` si helper strictement nécessaire
- `reports/audit/PH2_MENU_PROJECTION_PARITY_REPORT.md`

Lire seulement :
- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Kiosk/KioskMenuService.php`
- POS menu controllers/services identifiés par `rg`

Interdictions :
- Pas de migration consommateurs.
- Pas de changement API runtime.

Critères :
- Parité structure/sort/channel/availability.
- Cas divergence explicitement listés comme blockers.

### PH2-P0-04-KIOSK-POS-CONSUME-MENU-PROJECTION

Précondition : PH2-P0-03 PASS.

Objectif : brancher progressivement Kiosk puis POS sur projection canonique.

Allowlist modifier :
- `app/Http/Controllers/Frontend/MenuController.php`
- `app/Services/Kiosk/KioskMenuService.php` ou adapter wrapper
- POS menu endpoint/service exact après exploration
- tests kiosk/POS menu ciblés

Interdictions :
- Aucun calcul prix frontend.
- Aucun changement `PricingService` sauf test de non-régression.
- Pas de modification order commit.

Double audit :
1. Audit payload : ancien vs nouveau payload sur fixture riche.
2. Audit comportement : Kiosk/POS UI ne perdent ni labels, ni upsells, ni availability.

### PH2-P0-05-VARIATION-EXTRA-ADDON-SYNC-COVERAGE

Objectif : les mutations composition/prix des extras/variations/addons deviennent visibles et versionnées.

Allowlist modifier :
- `app/Services/ItemVariationService.php`
- `app/Services/ItemExtraService.php`
- `app/Services/ItemAddonService.php`
- listener/event choisi en PH2-P0-02
- `tests/Feature/Menu/MenuModifierMutationSyncTest.php` (new)

Interdictions :
- Pas de changement formule prix.
- Pas de DB schema.

Critères :
- update/create/delete extra/variation/addon -> snapshot bump + cache invalidation.
- POS/Kiosk projection reflète changement.

### PH2-P0-06-CATEGORY-BRANCH-SCOPE-ADR

Type : gate design, pas code.

Objectif : décider si les catégories sont globales ou branch-visibles.

Allowlist modifier :
- `docs/decisions/D-PH2-CATEGORY-BRANCH-SCOPE.md`
- `reports/audit/PH2_CATEGORY_BRANCH_SCOPE_OPTIONS.md`

Options :
- A : catégories globales, availability item/branche seulement.
- B : pivot `category_branch_visibility`.
- C : `branch_id` sur catégorie, déconseillé si catégories partagées multi-branches.

Exit :
- décision signée avant tout changement Dashboard UI qui promet "catégorie par branche".

### PH2-P0-07-DASHBOARD-AUTHZ-CATALOG-OPS

Objectif : définir permissions Dashboard sans réutiliser abusivement permissions caisse.

Allowlist modifier :
- `docs/AUTHZ_MATRIX.md`
- `tests/Feature/Sentinels/DashboardCatalogAuthzSentinelTest.php` (new)
- éventuellement seeders permissions si gate accepté

Lire seulement :
- routes admin actuelles
- controllers admin catalogue/availability/order

Critères :
- staff caisse ne peut pas éditer catalogue global.
- backoffice catalog ne peut pas lire commandes hors scope si non autorisé.
- admin branch 0 reste explicite.

### PH2-P0-08-QUEUE-D13-AFTER-HUMAN-GATE

Statut : bloqué volontairement.

Objectif : implémenter D-M13 uniquement après décision signée.

Allowlist future :
- migration unique queue_number
- `OrderService.php`
- `FrontendOrderService.php`
- queue sentinels concurrency
- docs rollout/rollback

Critère :
- `QueueNumberUniquenessSentinelTest` PASS.

### PH2-P1-09-OUTBOX-DOCS-CONTRACT-ALIGNMENT

Objectif : corriger l'alimentation docs pour éviter que Claude/Codex régressent les fixtures.

Allowlist modifier :
- `docs/EVENT_CONTRACT.md`
- `docs/REALTIME_SETUP.md`
- `reports/audit/PH2_OUTBOX_CONTRACT_DOC_DIFF.md`

Lire seulement :
- `app/Domain/Events/EventContract.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`

Critères :
- Docs mentionnent `_origin`, `payment_method`, `queue_number`.
- Pas de code produit.

### PH2-P1-10-LEGACY-DEDUP-ARCHIVE-MANIFEST

Objectif : nettoyer sans supprimer.

Allowlist modifier :
- `archive/phase2-dedup-YYYY-MM-DD/**`
- `archive/phase2-dedup-YYYY-MM-DD/MANIFESTE.md`
- rapport audit cleanup

Lire seulement :
- build config
- blade refs
- `public/js/*`
- `resources/js/*`
- legacy archive dirs

Interdictions :
- Pas de `rm`.
- Pas de déplacement de chemin primaire sans gate humain.

## 8. Tests Finaux Requis Après Implémentation Phase 2

Validation minimale par palier :

```bash
php artisan test --filter='Menu|Kiosk|PosKioskPricingParity|Outbox|EventContract|Kds|OrderStatusScreen'
npx vitest run tests/js/kiosk*.spec.js tests/js/pos*.spec.js
```

Validation release après tous gates :

```bash
php artisan test
npx vitest run
npx playwright test
bash scripts/lint-fk-bundle-legacy.sh strict
bash scripts/foodking-claude-orchestrate.sh audit-brief
```

Critère final :
- 0 fail hors gate explicitement signé.
- Aucun doc contract drift.
- `CLOSED_VS_GIT` sans `REWORK_NOT_PERSISTED`.
- Rapport final avec matrice `fait / fusionné / testé / gate / risque restant`.

## 9. Questions Ouvertes Max 7

1. D-M13 : index unique partial ou full pour `(branch_id, queue_number)` ?
2. Catégories : globales assumées ou branch-visibles via pivot ?
3. Event catalogue : créer `CatalogProjectionChanged` ou plusieurs events métier (`ItemPriceChanged`, `ItemStructureChanged`, etc.) ?
4. Dashboard : rôle unique `backoffice-catalog` ou séparation `catalog` / `ops availability` / `orders monitor` ?
5. Kiosk cache local : TTL 5 min conservé avec heartbeat snapshot ou invalidation realtime obligatoire ?
6. Variations/extras/addons : sont-ils tous éditables Phase 2 ou certains restent hors scope jusqu'au modèle modificateurs ?
7. Legacy bundles publics : shim signé maintenu pour release V1 ou archive après remplacement complet ?

## 10. Conclusion

La vision V1 fonctionnelle puis Phase 2 centralisée est correcte si elle respecte l'ordre suivant :

1. stabiliser gouvernance ;
2. prouver projection unifiée ;
3. couvrir events/snapshot/cache pour toutes mutations catalogue ;
4. migrer consommateurs POS/Kiosk progressivement ;
5. signer catégories/roles/D-M13 ;
6. seulement ensuite livrer Dashboard write global ;
7. nettoyer par archive manifestée, jamais par suppression directe.

`PHASE2_STRATEGIE = PRET_POUR_PLANIFICATION_EXECUTION_SEQUENTIELLE_APRES_GATES_P0`
