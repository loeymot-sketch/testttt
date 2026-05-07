# BLUE TEAM R3 — Response to RED-R3 Rupture Stock Live (2026-05-07)

> Document blue team. Réponse publique à `RED_TEAM_R3_RUPTURE_STOCK_2026-05-07.md` (10 findings, 3 P1 confirmés runtime, 4 fondations saines confirmées).

## Méthodologie BLUE

Pour chaque finding RED, vérification source-by-source + reproduction runtime indépendante. Pas de défense aveugle. Faux positifs harness identifiés et documentés.

## Bilan post-vérification

| Finding RED | Verdict BLUE | Evidence |
|---|---|---|
| F1 — Outbox 0 events dispatched | ❌ **RÉFUTÉ** — harness artifact (websockets:serve down) | Reproduction runtime avec BROADCAST_DRIVER=log: event 404 dispatched_at set, attempts=1 |
| F2 — POS UI ne reflète pas OOS post-reload | ✅ **ADMIS** — vrai bug SPA (Vuex/projection) | DOM probe RED + curl backend OK + investigation source partielle |
| F3 — KDS sans marker in-flight | ✅ **ADMIS** — feature manquante, P2 V1.x | Source-grep RED confirmé, pas de pattern "recently_86" |
| 4 fondations RED-OK | ✅ **CONFIRMÉ sain** | R3-02 (toggle persists), R3-05 (assertItemsOrderableForBranch), R3-06 (no double-sell), R3-07 (cascade ingredient) |

## Réfutation F1 — Outbox pipeline

### Claim RED
"617 jobs queue, 131 events pending, 0 events ever dispatched" → "outbox pipeline KO, P1 confirmé".

### Vérification BLUE (reproduction runtime indépendante)

**Étape 1** : reproduit le snapshot RED initial.
```bash
$ php artisan tinker --execute="echo DB::table('domain_events')->whereNull('dispatched_at')->count();"
152
```
✅ 152 pending, cohérent avec RED.

**Étape 2** : crée un nouvel event frais.
```php
event(new ItemAvailabilityChanged(
    itemId: 363, status: 1, price: 5.0, type: 'availability',
    branchId: 1, isAvailable: false, reason: 'red_r3_blue_test_v2'
));
// → fresh event id=404
```

**Étape 3** : dispatch sync direct (broadcast Pusher actif).
```bash
$ php artisan tinker --execute="App\Jobs\DispatchDomainEventsJob::dispatchSync(404);"
Illuminate\Broadcasting\BroadcastException  Pusher error: cURL error 7:
  Failed to connect to 127.0.0.1 port 6001 - Could not connect to server
```
🎯 **Trouvé** — websockets:serve (`laravel-websockets` port 6001) NON démarré dans le harness. Broadcast échoue → exception caught → Phase 3b release claim (`dispatched_at=null` ligne 147) → job va retry.

**Étape 4** : retest avec `BROADCAST_DRIVER=log` (broadcaster qui ne nécessite pas websockets).
```bash
$ BROADCAST_DRIVER=log php artisan tinker --execute="
   App\Jobs\DispatchDomainEventsJob::dispatchSync(404);
   \$ev = DomainEvent::find(404);
   echo \$ev->dispatched_at . ' attempts=' . \$ev->attempts;
"
2026-05-07 05:42:29.000 attempts=1
```
✅ **Pipeline correct** — `dispatched_at` set, attempts=1, single try.

### Diagnostic réel
Le code `DispatchDomainEventsJob.php:140-151` (Phase 3b) **release atomiquement le claim** si le broadcast échoue, pour que la queue retry (6 tries, backoff 1s/5s/15s/60s/300s/300s). C'est un mécanisme de récupération **voulu et correct** pour un broker Pusher temporairement indisponible.

**Conclusion** : F1 est un artefact de l'environnement harness (websockets:serve down). En production avec laravel-websockets UP (ou Pusher.com cloud), le pipeline marche. RED-R3 a lui-même documenté la limitation harness en §7.2 du rapport mais a quand même claim P1 — vérification runtime contredit cette claim.

### Garde anti-régression
Pour éviter que ce faux positif refasse surface, je documente dans le rapport BLUE-R3 que toute future spec stock-sync doit soit :
- Démarrer `php artisan websockets:serve` en background avant les tests (CI-grade)
- Soit utiliser `BROADCAST_DRIVER=log` dans `phpunit.xml` env (test-grade)

## Admission F2 — POS UI ne reflète pas OOS après reload

### Vérification BLUE source

**Code consommer event Pusher** : `PosComponent.vue:1873-1933` `_onItemAvailabilityChanged` est CORRECT — locate item dans `itemsRaw`, met à jour `is_available` + `availability_reason`, prune cart.

**Code projection tile** : `ItemComponent.vue:706-711` `isCatalogTileUnavailable` correct — `row.is_available === false` → `is-unavailable` class + click bloqué (ligne 721).

**Store fetch** : `store/modules/item.js:48-49` injecte automatiquement `branch_id` depuis auth context. URL = `admin/item?branch_id=X`.

**Backend** : `ItemController.php:47,69-70,78` accepte `branch_id` filter et l'autorise. RED a vérifié curl que la réponse contient `is_available=false`.

### Diagnostic provisoire
Le bug est **côté SPA**, mais cause exacte non isolée par RED (Vuex stale vs cache localStorage vs lifecycle async). RED admet en §7.5 qu'il "n'a pas isolé `dispatch('item/lists')` runtime ni inspecté l'axios response interne".

**Hypothèse la plus probable** : `localStorage.vuex` rehydrate `state.item.lists` AVANT le fetch initial, ce qui peut maintenir un cache stale jusqu'au prochain rerender. Le fetch arrive après le rerender initial mais ne déclenche pas un re-render car la diff Vue ne détecte pas le `is_available: false` (hash de comparaison potentiellement insuffisant).

### Décision BLUE
- **ADMIS comme P1 réel mais hors scope inline-edit** (>30 lignes, requiert investigation Vuex devtools, refactor potential du flow `auth → lists → projection`).
- **Plan dédié** : créer le cycle `CV1-POS-AVAILABILITY-LIVE-001` pour fix complet avec instrumentation Vuex devtools en runtime.
- **Mitigation V1** : R3-05 confirme que le **backend re-valide au submit** (HTTP 422 avec message clair `"Article 363 indisponible (manual_admin)"`). Donc **pas de risque de commande encaissée pour un item OOS** — juste friction UX caissier (clique avant de voir le rejet 422).
- **Acceptable V1** vu la mitigation backend solide.

## Admission F3 — KDS sans marker in-flight

### Vérification BLUE
RED source-grep correct : `KitchenDisplaySystemComponent.vue` subscribe à `ItemAvailabilityChanged` (broadcastAs OK) mais aucun pattern `recently_?86`, `inflight.*86`, `ITEM_RECENTLY` n'existe.

### Décision BLUE
- **ADMIS comme feature manquante P2 V1.x**.
- **Plan dédié** : créer le cycle `CV1-KDS-INFLIGHT-OOS-MARKER-001` pour ajouter :
  - Vuex flag `kdsInflight/recentlyDeavailable: { itemId, ts, branchId, reason }`
  - Listener Echo `ItemAvailabilityChanged` côté KDS qui set le flag
  - Badge tooltip rouge sur tickets `in_preparation` contenant un item récemment 86
  - TTL 10min (purge auto pour éviter false positives sur tickets terminés)
- **Mitigation V1** : OSS / serveur informe verbalement (process restaurant standard). Pas un blocker hard pour V1, mais polishing essentiel pour V1.x.

## 4 fondations confirmées saines (BLUE valide les claims OK de RED)

1. **R3-02 — Toggle persists DB + payload V1 contract OK** : `POST /api/admin/menu/availability/toggle` → 406ms, row créée, payload complet (item_id, status, price, type, is_available, branch_id, reason).
2. **R3-05 — assertItemsOrderableForBranch rejects 422** : `OrderService::store()` lignes 375/674/1100 + `FrontendOrderService::store()` ligne 284 invoquent avec `useRowLock=true`. Message FR clair retourné.
3. **R3-06 — No double-sell** : 2 commandes parallèles avec `on_hand=1` → 1 succès, 1 échec `stock_rupture`. Mécanisme : 1ère commande déclenche `syncItemAvailabilityForStockLevel` → cascade auto-86 → 2ème commande échoue.
4. **R3-07 — IngredientAvailabilityService cascade** : 20 siblings same-group flippent ensemble par `name+group_label`. Cascade DB OK. Question Q-I1 RED (event domain dispatch) à investiguer (potentiellement P2).

## Plans dédiés (créés post-RED-R3)

| ID | Description | Priorité | Estimation |
|---|---|---|---|
| `CV1-POS-AVAILABILITY-LIVE-001` | Fix POS UI projection après toggle (SPA Vuex flow) | P1 V1.x | 2-4h investigation + fix + spec |
| `CV1-KDS-INFLIGHT-OOS-MARKER-001` | Badge KDS in-flight pour items récemment 86 | P2 V1.x | 1-2h scope défini |
| `CV1-OBSERVABILITY-OUTBOX-001` | Dashboard `/admin/observability/outbox` listant events pending/failed/last_error | P2 V1.x | 2-3h enrichir SyncOverviewController existant |
| `CV1-CI-WEBSOCKETS-HARNESS-001` | Démarrer `websockets:serve` + `queue:work --queue=high` automatiquement avant specs sync rupture | P2 ops | 1h CI script |

## Verdict BLUE final R3

**PROD-READY avec mitigations**.
- Outbox pipeline CONFIRMÉ correct (F1 réfuté avec evidence runtime indépendante).
- Backend re-validation submit (R3-05) MITIGE F2 SPA — pas de cohérence DATA cassée, juste UX caissier dégradée.
- F3 KDS = feature missing V1.x acceptable vu process verbal OSS-cuisine standard restaurant.

**Différentiel adversaire R3** :
- RED a découvert F2 (vrai bug SPA réel) que MEGA-D 10/10 PASS n'a pas vu (sentinels structurels, pas projection runtime).
- RED a aussi détecté l'absence de marker KDS in-flight (vraie limite UX, pas un bug de logique).
- MAIS RED a hit un **faux positif harness sur F1** (qu'il a partiellement reconnu en §7.2). Méthodologie BLUE = vérifier indépendamment avant d'admettre.

## Commit BLUE-R3
- Pas de fix scope-minimal applicable (F1 = pas de bug, F2/F3 = scope dédié)
- Memory `feedback_orchestrator_inline_edit_exception.md` respectée (>30 lignes refusé)
- 4 plans heal/feature documentés ci-dessus
- Rapport BLUE écrit. Commit = rapport + spec RED + screenshots durables.

## Suite

- RED-R4 KDS reception + status transitions (en cours d'orchestration)
- RED-R5 synthèse adversaire + verdict final + commit
