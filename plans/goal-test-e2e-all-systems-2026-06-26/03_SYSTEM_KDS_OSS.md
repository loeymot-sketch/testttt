# 03 — SYSTÈME KDS + OSS — plan test-e2e abusif

**Contract** : KDS = écran cuisine (bump/recall/stations/timers). OSS = mur statut
client public. Lentille = 🧑‍🍳 **CUISINIER** (« lit-il SANS ambiguïté quoi
préparer ? ») + 🧑 **client en attente**. Vague **séquentielle** (consomme le bus
sync, tightly coupled à Borne/Caisse). **Aucun fichier frozen** (KDS/OSS éditables).

**Shared** : s'abonne canal privé `branch.{id}` (Echo) si branchId>0 ; OSS public
**polls** (60s) ; events plain → outbox → soketi ; `composition_snapshot` figé,
rendu côté read ; payload `KDSOrderDetailsResource`/`KDSOrderItemsResource`.

**Anchors (vérifiés)** : `KitchenDisplaySystemOrderService` (list/changeStatus/recall/
historyToday), `KitchenDisplaySystemController`, `KdsSyncController`,
`OrderStatusScreenController`, `KitchenReleaseRule` ; resources `KDSOrderItemsResource`,
`KDSOrderDetailsResource`, `CDSOrderDetailsResource` ; front
`components/admin/kitchenDisplaySystem/**` + `orderStatusScreen/**` ;
helpers `kdsCustomization.js`, `kdsDisplay.js`, services `OssSyncService.js` ;
tests `tests/Feature/KDS/**` + `Kds*.php` + `tests/js/kds*.spec.js` (~20) + `oss*.spec.js`.

---

## INVENTAIRE PAGES/SURFACES

| Surface | Composant | Rôle |
|---|---|---|
| `/admin/kitchen-display-system` (alias `/kds`) | `KitchenDisplaySystemComponent.vue` (146 KB) | board : grille, bump, recall, stations, timers, pagination, bannières |
| carte commande | `KdsOrderCard.vue` | badge RAPPELÉ, slot/source, pastille allergène, lignes, bucket d'âge |
| ligne article | `KdsOrderLine.vue` + `kdsCustomization.js` | compo lisible : `Groupe : Valeur`, supplément jaune, addon, instruction |
| grille V2 / drawer | `KdsV2Grid.vue`, `KdsHistoryDrawer.vue`, `KdsStatusBanner.vue`, `KdsUndoToast.vue` | layout V2 (kill-switch), historique, bannières, undo |
| `/admin/order-status-screen` (alias `/order-status`) | `OrderStatusScreenComponent.vue` | mur client : EN PRÉPARATION / PRÊT |
| colonnes mur | `PreparingAndReadyComponent.vue` | `N°{queue}`/`token`, chime 3-tons + flash sur PRÊT, wakelock, autoscroll |

**Endpoints** : `GET kds-order/` (index), `POST kds-order/change-status/{order}`
(idempotency+throttle), `GET kds-order/items`, `GET kds-order/sync` (delta poll),
`GET kds-order/history-today` (60/1), `POST kds-order/recall/{order}`. OSS authed
`admin/oss-order` (`PosShortcutOrderResource` — expose `total`) ; OSS public
`frontend/oss-order` (`CDSOrderDetailsResource` — **0 PII**) + `popular-items`.

---

## DÉCOMPOSITION (4 sous-systèmes)

### Sub 3.a — Board + bump/recall + transitions
- T-3.a.1 Audit `list` (fenêtre TODAY Paris-tz, advance-orders, pagination 50/overflow 51).
- T-3.a.2 Audit `changeStatus` (lock optimiste `expected_status`→409, whitelist ACCEPT→PREPARING→PREPARED, **release-guard** `orderIsReleasedForBoard:447`, post-commit notif).
- T-3.a.3 Audit `recall` (60s, cap N=1, append-only `kitchen_recall`, status JAMAIS muté).
**Acceptance** : `KDSFlowTest`, `KdsChangeStatusConcurrencyTest`, `KdsExpectedStatusConflictTest`, `KdsTransitionWhitelistTest`, `KdsUnreleasedOrderBumpTest`+`P1Test`, `KdsRecallCapNTest`, `KdsPaginationOverflowTest`, `KitchenReleaseRuleTest` PASS + JS `kdsBumpRecall`.

### Sub 3.b — Lisibilité compo / allergènes / snapshot
- T-3.b.1 Audit inversion snapshot (`attribute_name`=GROUPE) vs legacy (`variation_name`=GROUPE) → helpers `kdsVariationGroupValue/Line` (re-test non-régression du heal `d71dfbfe8`).
- T-3.b.2 Audit split allergène (food-safety, hash dans `orderItems()`, `Service:559`).
- T-3.b.3 Audit anti-doublure (`sanitizeKdsInstruction` n'écho pas le blob), **2 viandes distinctes**, supplément visible.
**Acceptance** : `KdsSnapshotImmutableTest`, `KdsAllergenAggregationSplitTest`, `KdsOrderItemsResourceAllergenExposureTest`, `Orders/KDSAllergenVisibilityTest` PASS + JS `kdsCustomization/kdsLineSemantics/kdsAllergens` + *(À CRÉER `tests/js/kdsCardCompositionShapeParity.spec.js` + `kdsTwoMeatsDistinctRender.spec.js`)*.

### Sub 3.c — Sync temps-réel + dégradation/poll
- T-3.c.1 Audit Echo `private-branch.{id}` (OrderStatusChanged/Created/PaidAtCounter/KdsOrderRecalled/ItemAvailabilityChanged), branchId>0 only.
- T-3.c.2 Audit poll fallback (KDS 60s connecté / 5s déconnecté ; OSS 60s/2s ; clamp 250ms–60s).
- T-3.c.3 Audit **bannière dégradation** (suppression locale `Component:70/1308-1335`) — fail-safe-to-visible ?
**Acceptance** : `KdsSyncSargableTest`, `KdsSyncTzAwareTest`, `Admin/KdsSyncControllerTest`, `KdsNotificationFailureTest` PASS + JS `kdsSyncCadence/kdsCadenceFloor/kdsBackoffOn5xx/kdsReactsToReconnectStorm/kdsV2KillSwitch` + *(À CRÉER `tests/js/kdsFallbackBannerFailSafeVisible.spec.js`)*.

### Sub 3.d — OSS mur client + filtrage release
- T-3.d.1 Audit filtrage statut PREPARING/PREPARED + résolution branche publique.
- T-3.d.2 Audit frontière PII (public `CDSOrderDetailsResource` vs admin `PosShortcutOrderResource` expose `total`), enum branchId≤0→public.
- T-3.d.3 Audit chime/flash sur PRÊT, anti-double-chime Echo+poll, wakelock.
**Acceptance** : `KDSDeliveryEnrichmentTest`, `KdsHistoryTodayEndpointTest` PASS + JS `ossChimePublicWall/ossSyncFallback/ossWakeLockOnMount/posOssCadenceCap` + *(À CRÉER `tests/Feature/KDS/OssPublicResourceNoPiiTest.php` + `KdsRecallDoesNotDowngradeOssTest.php`)*.

---

## GERMES ADVERSAIRES (🧑‍🍳 cuisinier + 🧑 client-attente)
- **Board** : bump d'une commande **non-payée** → notif client « en préparation » avant paiement (guard release) ; 2 stations bump simultané → 409 ; recall spam >cap / fenêtre 60s expirée → 422 ; **51ᵉ commande tronquée** (overflow) invisible au cuisinier ; zombie advance d'hier.
- **Lisibilité (cœur)** : **compo blanche/dupliquée/inversée** (« Poulet mariné: » groupe perdu) ; **2 viandes identiques** au lieu de distinctes → mauvaise viande préparée ; **supplément non visible** (Cheddar +0,90) → produit incomplet ; **allergène masqué** (fusion 2 commandes → allergie ratée) ; instruction qui double la compo.
- **Sync** : **perte sync silencieuse en local** (soketi mort, board fige sur poll SANS bannière) → cuisinier croit la file à jour ; reconnexion-storm → dedupe id+version ; cadence misconfig 999999999 → clamp ; bump 202 mais broadcast échoue → self-heal axios.
- **OSS client** : commande au **mauvais statut** sur le mur (PREPARING affiché PRÊT) → client part / réclame ; **fuite PII** si surface authed sert le mur ; ordre disparaît (timing chime) ; double-chime fatigue ; lag >8s (poll 60s au lieu de 2s déconnecté).

---

## PIÈGES & DÉFAUTS CONNUS (file:line)
1. **Bannière dégradation locale** : `KitchenDisplaySystemComponent.vue:70` + gate `:1308-1335` + `config/kds.php:60` — suppression seulement si local **ET** opt-out explicite (par défaut visible ; config non câblée master.blade → différé).
2. **Inversion compo HEALÉE** : `kdsCustomization.js:kdsVariationGroupValue/Line` (discriminant `attribute_name`) ; board via `KdsOrderCard:390`, items via `KDSOrderItemsResource:67-89` snapshot-first. Re-tester non-régression.
3. **Release-filter 4 dimensions** : `KitchenReleaseRule` — `applyBoardReleaseFilter` (SQL list) miroir de `orderIsReleasedForBoard` (in-memory changeStatus:447). Admet PAID(5)|PENDING_COUNTER(15)|POS+CASH. Divergence passée = bump UNPAID invisible.
4. **TZ Paris** : `list:121-124`, `historyToday:226` — bornes Carbon Paris-local (PAS UTC) sinon dernières 2h disparaissent.
5. **Pagination cap 50** : `list:172` limit(51)→take(50), `overflow` meta `Controller:33` — 51ᵉ invisible.
6. **PII** : `KDSOrderDetailsResource:72-75` phone si DELIVERY ; `admin/oss-order` expose `total` ; public `frontend/oss-order` = 0 PII.
7. **Enums** : ACCEPT=4, PREPARING=7, PREPARED=8, OUT=10, DELIVERED=13 ; PAID=5, UNPAID=10, PENDING_COUNTER=15 ; CASH=1.
