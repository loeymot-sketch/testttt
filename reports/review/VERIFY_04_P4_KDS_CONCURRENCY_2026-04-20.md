# VERIFY-04 — P4 KDS concurrence (lock + 409 + ligne verrouillée + Vuex refresh)

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (read-only, 0 code modifié)
**Origine :** P4 (commit `e18344af4`)  **Priorité :** P0
**Tâche :** `tasks/verify-2026-04-20/04_VERIFY_P4_KDS_CONCURRENCY.md`
**Auditor :** sub-agent AUDIT-ONLY (Claude)

## 0. Méthode (5 lignes)

1. **Pass A back** — `KitchenDisplaySystemOrderService::changeStatus`, `KitchenDisplaySystemController`, `OrderStateMachine`, route `routes/api.php:778`, `OrderStatusRequest`.
2. **Pass B front** — Vuex `kitchenDisplaySystemOrder.js`, composant staff `KitchenDisplaySystemComponent.vue`, composant client OSS `PreparingAndReadyComponent.vue`.
3. **Pass C invariant V3** — grep `event\(|dispatch\(|broadcast\(|Pusher::trigger|::dispatch\(` dans le service ; vérifier que **toutes** les émissions sont **après** la fermeture de `DB::transaction(...)`.
4. **Pass D tests** — `KdsChangeStatusConcurrencyTest`, `OrderStateMachineTest`, `KDSFlowTest`, `KDSScopeRestrictionTest`, `KdsBranchFilterExactTest`.
5. **Pass E scénarios race** — 2 staff simultanés, multi-onglets, multi-stations, cross-branche, offline reconnect, broadcast down, recordTransition crash, transition interdite.

---

## 1. Pass A — Backend

### 1.1 Service `KitchenDisplaySystemOrderService::changeStatus`

`app/Services/KitchenDisplaySystemOrderService.php`

Extrait clé (lock + guard + transition + dispatch après commit) :

```117:177:app/Services/KitchenDisplaySystemOrderService.php
public function changeStatus(Order $order, OrderStatusRequest $request)
{
    try {
        $newStatus = (int) $request->status;
        // Compare expected "from" to the locked row so two tabs / stale SPA state cannot overwrite.
        $expectedFrom = (int) $order->status;

        $result = DB::transaction(function () use ($order, $newStatus, $expectedFrom) {
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $userBranchId = (int) (auth()->user()->branch_id ?? 0);
            if ($userBranchId > 0 && (int) $locked->branch_id !== $userBranchId) {
                abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
            }

            if ((int) $locked->status !== $expectedFrom) {
                abort(409, 'Order status was updated elsewhere — please refresh the KDS.');
            }

            if (!OrderStateMachine::allows((int) $locked->status, $newStatus, auth()->user())) {
                throw new Exception(trans('all.message.invalid_status_transition'), 422);
            }

            $fromLocked = (int) $locked->status;
            $locked->status = $newStatus;
            $locked->save();

            OrderStateMachine::recordTransition(
                Order::class, (int) $locked->id, $fromLocked, $newStatus,
                auth()->check() ? (int) auth()->id() : null, null
            );

            return ['model' => $locked->fresh(), 'from' => $fromLocked];
        });

        $snapshot  = $result['model'];
        $oldStatus = $result['from'];

        SendOrderMail::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
        SendOrderSms::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
        SendOrderPush::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);

        try {
            OrderStatusChanged::dispatch($snapshot, $oldStatus, $newStatus);
        } catch (\Exception $e) {
            Log::warning('[KDS] OrderStatusChanged broadcast failed: ' . $e->getMessage());
        }
    } catch (HttpException $e) {
        throw $e;
    } catch (Exception $exception) {
        Log::info($exception->getMessage());
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    }
}
```

Observations vérifiées :
- `lockForUpdate()` à `app/Services/KitchenDisplaySystemOrderService.php:127` produit `SELECT … FOR UPDATE` sur la PK avant **tout** test métier. **V1 ✅**
- Comparaison stricte `(int) $locked->status !== $expectedFrom` ligne 135 → `abort(409, …)` couvre toute dérive (statut avancé/annulé par un autre acteur). **V2 ✅**
- `OrderStateMachine::allows` ligne 139 s'appuie sur **l'état lu et verrouillé** (`$locked->status`), pas sur `$order->status` (anti-stale-SPA).
- `recordTransition` lignes 147-154 est invoqué **dans** la closure transactionnelle (avant le `return` qui clôt la closure). **V4 ✅**
- Closure `DB::transaction(...)` se ferme à la ligne 157 (commit). Tous les `dispatch(...)` (`SendOrderMail`, `SendOrderSms`, `SendOrderPush`, `OrderStatusChanged`) sont émis **lignes 162-167**, **après** réception de `$result`. **V3 ✅**

Grep d'invariant V3 (preuve exhaustive sur le fichier) :

```
$ rg -n 'event\(|dispatch\(|broadcast\(|Pusher::trigger|::dispatch\('
  app/Services/KitchenDisplaySystemOrderService.php
162:  SendOrderMail::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
163:  SendOrderSms::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
164:  SendOrderPush::dispatch(['order_id' => $snapshot->id, 'status' => $newStatus]);
167:  OrderStatusChanged::dispatch($snapshot, $oldStatus, $newStatus);
```

→ Lignes 162/163/164/167 toutes **strictement supérieures à 157** (fin de `DB::transaction`). H1 réfutée formellement.

Sous-réserves :
- `OrderStatusChanged::dispatch` est entouré d'un `try/catch` (l. 166-170) → broadcast best-effort. La persistence outbox est garantie par le listener `PersistOrderStatusChangedToOutbox` (à corréler avec audit P9, hors scope ici).
- Si la file de jobs (`SendOrderMail`, etc.) est `sync` en environnement test/dev et que ces jobs eux-mêmes ouvrent une transaction qui échoue, le commit du KDS reste acquis (l'exception ne remonte pas dans le `try` du service car le commit a déjà eu lieu) — comportement conforme au pattern « after-commit dispatch ».

### 1.2 Controller `KitchenDisplaySystemController::changeStatus`

`app/Http/Controllers/Admin/KitchenDisplaySystemController.php`

```22:44:app/Http/Controllers/Admin/KitchenDisplaySystemController.php
$this->middleware(['permission:kitchen-display-system'])->only('index', 'changeStatus', 'orderItems');
...
public function changeStatus(Order $order, OrderStatusRequest $request): ...
{
    try {
        $this->kitchenDisplaySystemOrderService->changeStatus($order, $request);
        return response('', 202);
    } catch (HttpException $e) {
        return response(['status' => false, 'message' => $e->getMessage()], $e->getStatusCode());
    } catch (Exception $exception) {
        return response(['status' => false, 'message' => $exception->getMessage()], 422);
    }
}
```

- `HttpException` rattrapé **avant** `Exception` générique → `abort(409)` et `abort(403)` sortent avec leur code HTTP intact. **H2 réfutée**, **V2 côté HTTP ✅**.
- Middleware `permission:kitchen-display-system` (Spatie) couvre les 3 routes du module. **V7 partiel**.
- Route : `routes/api.php:776-780` → `Route::post('/change-status/{order}', ...)` en route-binding (Order frais à chaque requête HTTP).

### 1.3 `OrderStateMachine`

`app/Domain/Order/OrderStateMachine.php`

- Méthode `allows(int $from, int $to, ?Authenticatable $user)` (l. 27-72) — table de transitions explicite et fermée :
  - PENDING→{ACCEPT, CANCELED, REJECTED}
  - ACCEPT→{PREPARING, CANCELED} (+ DELIVERED si permission `pos`)
  - PREPARING→{PREPARED, CANCELED} (+ DELIVERED si permission `pos`)
  - PREPARED→{OUT_FOR_DELIVERY, DELIVERED}
  - OUT_FOR_DELIVERY→{DELIVERED}
  - DELIVERED→{RETURNED}
  - terminaux (CANCELED/REJECTED/RETURNED) → bloqués sauf rôle `Admin`.
- Le shortcut `pos` (l. 38, 45) **n'est pas** déclenché pour un Chef KDS sans permission `pos` — pas d'exception silencieuse.
- `recordTransition` (l. 84-111) : INSERT outbox best-effort, `try/catch` log warning si échec — pas d'exception remontée. Choix documenté.
- `apply()` (l. 131-171) ouvre **sa propre** `DB::transaction`. Le service KDS **n'utilise pas** `apply()` (anti-nesting volontaire) — pas de transaction imbriquée dans le chemin P4. **H3 réfutée**.

### 1.4 Permissions et isolation branche

- `OrderStatusRequest::authorize()` (l. 15-35) exige l'un des rôles : `Admin`, `Branch Manager`, `Chef`, `POS Operator`, `Cashier`. Sinon 403.
- Middleware `permission:kitchen-display-system` (controller l. 22) — défense complémentaire.
- Garde branche dans le service (l. 130-133) : `if ($userBranchId > 0 && (int) $locked->branch_id !== $userBranchId) { abort(403, …); }` — admin bypass (`branch_id = 0`).
- **H6 réfutée** : un user sans rôle KDS est bloqué dès `OrderStatusRequest::authorize()`.

---

## 2. Pass B — Frontend

### 2.1 Vuex `kitchenDisplaySystemOrder.js`

`resources/js/store/modules/kitchenDisplaySystemOrder.js`

```36:48:resources/js/store/modules/kitchenDisplaySystemOrder.js
changeStatus: function (context, payload) {
    return new Promise((resolve, reject) => {
        axios.post(`admin/kds-order/change-status/${payload.id}`, payload).then((res) => {
            context.dispatch("lists", payload).then().catch();
            resolve(res);
        }).catch((err) => {
            if (err.response && err.response.status === 409) {
                context.dispatch("lists", payload).catch(() => {});
            }
            reject(err);
        });
    });
},
```

- Sur 409 : `dispatch("lists", payload)` recharge la liste KDS Vuex, puis `reject(err)` pour que le composant affiche un message. **V8 server ✅**.
- **H4 partiellement vraie** : seul `state.lists` est rafraîchi sur 409 ; `state.orderItems` (board des items agrégés) n'est **pas** purgé par le store et reste sur l'ancien snapshot jusqu'au prochain Echo `OrderStatusChanged` ou au `_debouncedRefresh()` côté composant. Impact réel limité, l'UI items board est rafraîchie ailleurs (cf. §2.2).

### 2.2 Composant staff `KitchenDisplaySystemComponent.vue`

`resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:772-803`

```772:803:resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue
orderStatus: function (id, status) {
  try {
    this.loading.isActive = true;
    this.$store.dispatch("kitchenDisplaySystemOrder/changeStatus", { id, status })
      .then((res) => {
        this.loading.isActive = false;
        alertService.successFlip(1, this.$t("label.status"));
        this._debouncedRefresh();
        window.dispatchEvent(new CustomEvent('realtime-order-update', {
          detail: { type: 'status-change', order_id: id, status: status }
        }));
      }).catch((err) => {
        this.loading.isActive = false;
        const msg = err?.response?.data?.message || err?.message || 'Erreur réseau';
        alertService.error(msg);
      });
  } catch (err) { ... }
},
```

- 409 → message backend (`"Order status was updated elsewhere — please refresh the KDS."`) affiché via `alertService.error(msg)`. **V6 ⚠ WARN** : pas de feedback différencié (pas de toast spécifique « rechargé », pas d'animation surlignage de la ligne resync, pas de bouton « Recharger »).
- Pas de retry automatique (correct : un retry à l'identique reproduirait le 409 puisque la racine est la dérive d'état).
- Multi-onglets / multi-stations : la propagation entre fenêtres est portée par Echo (canal `branch.{branchId}` → `OrderStatusChanged`), pas par le `CustomEvent` local qui est intra-window seulement.

### 2.3 OSS `PreparingAndReadyComponent.vue` (consommateur Echo)

`resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` — 273 lignes, présent et fonctionnel (vérifié `wc -l` + `git diff HEAD --stat` = aucun diff).

- Consomme l'événement broadcast `OrderStatusChanged` (l. 132-145) pour pré-marquer une commande comme « PREPARED » avant le re-fetch HTTP.
- Auto-refresh : 60 s en mode WS connecté, 10 s en fallback (l. 105-107).
- Chemin OSS hors mutation (lecture seule) → pas d'incidence directe sur P4 mais valide la chaîne **commit → broadcast → re-render OSS**.

### 2.4 Test `KdsChangeStatusConcurrencyTest`

`tests/Feature/KdsChangeStatusConcurrencyTest.php`

- `RefreshDatabase` (l. 29) → DB réelle, pas de mock occultant. **V5 ✅** sur la sincérité.
- Scénario : crée un `Order(status=ACCEPT)`, mute en DB (`Order::query()->whereKey()->update(['status'=>PREPARING])`) pour simuler l'écriture concurrente, puis appelle **directement** le service avec l'objet `$order` stale → assertion `HttpException 409` + message contenant « refresh ».
- Couvre la garde `expectedFrom !== locked->status` au niveau **service**.

Limites (qualité, pas blocantes) :
- Test au niveau service, pas HTTP. Le commentaire en tête (l. 22-26) reconnaît explicitement : *« HTTP route model binding always loads a fresh Order, so concurrency is asserted against changeStatus() »* — exact, mais **aucun test ne prouve qu'une vraie race HTTP-niveau** (deux `POST` interleaved) renvoie 409. Sur la route HTTP, la fenêtre de race se réduit à l'intervalle entre `Order` route-binding et l'entrée dans `lockForUpdate`, mais elle existe.
- Aucun test multi-acteurs (forks / `Concurrency::run`).
- Aucun test « Chef autre branche → 403 » via la route KDS.
- Aucun test « transition interdite (PREPARED→ACCEPT) → 422 » via la route KDS (couvert sur `OrderStateMachine` en unit, pas sur le chemin KDS HTTP).

---

## 3. Hypothèses H1–H6

| # | Hypothèse | Statut | Preuve |
|---|-----------|--------|--------|
| H1 | dispatch d'event AVANT commit | **Réfutée ✅** | grep `dispatch(` dans `KitchenDisplaySystemOrderService.php` → tous à `:162-167`, **après** fermeture `DB::transaction` à `:157` |
| H2 | 409 mal propagé (transformé en 500) | **Réfutée ✅** | controller `:39-41` rattrape `HttpException` **avant** `Exception` générique et propage `getStatusCode()` |
| H3 | `recordTransition` hors transaction | **Réfutée ✅** | service `:147-154` à l'intérieur de la closure `DB::transaction(function () { … })` (`:124-157`) |
| H4 | Refresh Vuex partiel (caches non purgés) | **Partiellement vraie ⚠** | store `:42-44` ne recharge que `state.lists` ; `state.orderItems` reste stale jusqu'au prochain `_debouncedRefresh()` ou Echo |
| H5 | Test couvre service, pas HTTP | **Vraie ⚠** | `KdsChangeStatusConcurrencyTest.php:75` appelle `app(KitchenDisplaySystemOrderService::class)->changeStatus(...)` directement ; commentaire `:22-26` l'admet |
| H6 | Pas de garde rôle KDS | **Réfutée ✅** | `OrderStatusRequest::authorize()` `:24` exige rôle `Admin\|Branch Manager\|Chef\|POS Operator\|Cashier` ; controller `:22` middleware `permission:kitchen-display-system` |

---

## 4. Synthèse V1–V8

| V | Énoncé | Preuve (file:line) | Statut |
|---|--------|--------------------|--------|
| V1 | `lockForUpdate` couvre la lecture avant guard | `app/Services/KitchenDisplaySystemOrderService.php:125-128` (`Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail()`) | ✅ |
| V2 | 409 retourné dans tous les cas de dérive | service `:135-137` (`abort(409, …)`) ; controller `:39-41` propage `$e->getStatusCode()` | ✅ |
| V3 | Aucun `event/dispatch/Pusher::trigger` DANS la transaction | `DB::transaction(...)` ferme à `:157` ; tous les dispatchs sont à `:162-167` (hors closure) | ✅ |
| V4 | `recordTransition` DANS la transaction | service `:147-154` (avant `return` qui clôt la closure) | ✅ |
| V5 | Test ne mocke pas la DB | `KdsChangeStatusConcurrencyTest.php:29` (`RefreshDatabase`) ; `:62` UPDATE direct ; `:75` service réel | ✅ (sincérité) / ⚠ (étendue HTTP) |
| V6 | Front affiche 409 et déclenche refresh sans flicker | Vuex `:42-44` refresh `lists` ; composant `:794-795` `alertService.error(msg)` générique | ⚠ WARN (UX 409 non différenciée) |
| V7 | Permissions Spatie + isolation branche | controller `:22` middleware ; `OrderStatusRequest:24` rôles ; service `:130-133` 403 cross-branch | ✅ (test feature manquant) |
| V8 | Multi-onglets : un onglet 409 → resynchro | Vuex `:42-44` `dispatch('lists')` ; serveur `:135-137` ; canal Echo `OrderStatusChanged` (broadcasting after commit `:167`) | ✅ (server) / ⚠ (test multi-onglet absent) |

---

## 5. Matrice scénarios race

| # | Scénario | Comportement attendu | Preuve / observé | Verdict |
|---|----------|----------------------|------------------|---------|
| R1 | 2 staff même branche cliquent ACCEPT→PREPARING simultanément | 1 succès 202, l'autre 409 | `lockForUpdate` `:127` sérialise ; second hit voit `locked->status=PREPARING ≠ expectedFrom=ACCEPT` → `abort(409)` `:135` ; controller `:39-40` relaie | ✅ GREEN |
| R2 | Même chef, 2 onglets, statut déjà avancé dans onglet A | Onglet B reçoit 409, recharge silencieuse Vuex | Vuex `:42-44` → `dispatch('lists')` ; alert message backend affiché | ✅ GREEN (UX ⚠) |
| R3 | 2 stations même branche, même order | Idem R1 | Idem R1 | ✅ GREEN |
| R4 | Cross-branche : staff branch X tente order branch Y | 403 immédiat, pas de mutation | service `:130-133` `abort(403)` ; controller `:39-41` propage 403 | ✅ GREEN |
| R5 | Offline pendant POST, reconnect ensuite | Erreur réseau affichée, pas de queue locale | composant `:794` `err?.message ?? 'Erreur réseau'` ; aucune file outbox front pour `changeStatus` | ⚠ WARN UX |
| R6 | Échec broadcast `OrderStatusChanged` (Pusher down) après commit | Mutation persistée, broadcast best-effort | service `:166-170` `try/catch` log warning ; outbox listener garantit la persistance d'event | ✅ GREEN |
| R7 | Crash de `recordTransition` (DB transition log) | Mutation persistée, audit perdu (best-effort) | `OrderStateMachine.php:108-110` `try/catch` log warning | ⚠ WARN observabilité |
| R8 | Transition interdite (ex: PREPARED→ACCEPT) par Chef | 422 « invalid_status_transition » | `OrderStateMachine::allows` `:139` → `throw new Exception(..., 422)` rattrapé `:173-176` → HTTP 422 | ✅ GREEN |
| R9 | User sans rôle KDS POSTe sur la route | 403 dès `OrderStatusRequest::authorize()` | `OrderStatusRequest:24-34` retourne `false` → 403 | ✅ GREEN |
| R10 | `state.orderItems` (board agrégé) après 409 | Items board rafraîchi | Vuex `:42-44` ne recharge que `lists` ; le board items se met à jour via `_debouncedRefresh()` côté composant + Echo | ⚠ WARN (cache partiel) |

---

## 6. Findings

| ID | Sévérité | Titre | Preuve | Recommandation |
|----|----------|-------|--------|----------------|
| **F-VERIFY-04-01** | WARN (P1) | UX 409 non différenciée côté staff KDS | `KitchenDisplaySystemComponent.vue:794-795` toast générique sans bouton resync ni surlignage | Différencier `err.response.status === 409` → toast spécifique + animation ligne resync ; exposer `state.lastConflictId` |
| **F-VERIFY-04-02** | WARN (P1) | Couverture test concurrence limitée au service (pas HTTP) | `KdsChangeStatusConcurrencyTest.php:22-26, 75` ; aucun test `postJson('/api/admin/kds-order/change-status/{id}')` race | Ajouter test HTTP avec `Concurrency::run` ou doubles requêtes interleaved + assertion `409` |
| **F-VERIFY-04-03** | WARN (P2) | Refresh Vuex partiel sur 409 (orderItems board non purgé) | `kitchenDisplaySystemOrder.js:42-44` ne dispatch que `lists`, pas `orderItems` | Sur 409, déclencher également `dispatch('orderItems')` (ou unifier via une action `refreshAll`) |
| **F-VERIFY-04-04** | WARN (P2) | Tests permissions/branche manquants sur la route KDS | `tests/Feature/KdsChangeStatusConcurrencyTest.php` ne couvre pas (a) cross-branche → 403, (b) transition interdite → 422, (c) rôle non KDS → 403 | Ajouter 3 cas de test feature HTTP |
| **F-VERIFY-04-05** | WARN (P3) | `OrderStateMachine::recordTransition` swallow silencieux | `OrderStateMachine.php:108-110` `try/catch` log warning, pas de métrique | Compteur Prometheus / log structuré + alerte si taux d'échec > seuil |
| **F-VERIFY-04-06** | INFO (P3) | Pas de file de retry offline pour `changeStatus` | `KitchenDisplaySystemComponent.vue:794` ; aucune outbox front | Outbox locale (IndexedDB) avec re-jeu à la reconnexion (clarifier doctrine offline KDS) |

---

## 7. Cycles P proposés

| Cycle | Type | Routing modèle | Justification |
|-------|------|----------------|---------------|
| **P11_KDS_409_UX_DIFFERENTIATED** | EXECUTE routine (front Vue) | `foodking-routine-implementer` | Diff toast + animation surlignage + état Vuex `lastConflictId` ; pas de logique métier sensible |
| **P12_KDS_HTTP_RACE_TEST_HARDENING** | EXECUTE complex (tests Feature concurrents) | `foodking-complex-implementer` | Concurrence DB + HTTP, sensible aux conditions de course ; couvre F-VERIFY-04-02 et F-VERIFY-04-04 |
| **P13_KDS_VUEX_FULL_REFRESH_ON_409** | EXECUTE routine (front Vuex) | `foodking-routine-implementer` | Bornée : ajouter `dispatch('orderItems')` sur 409 ; F-VERIFY-04-03 |
| **P14_OBSERVABILITY_RECORD_TRANSITION** | EXECUTE complex (instrumentation backend) | `foodking-complex-implementer` | Touch chemin lifecycle audit ; F-VERIFY-04-05 |
| **P15_KDS_OFFLINE_OUTBOX** (optionnel) | PLAN puis EXECUTE complex | `foodking-planner-orchestrator` → `foodking-complex-implementer` | Décision doctrine offline KDS (frozen zone à clarifier) ; F-VERIFY-04-06 |

> Note routing : conformément à `AGENTS.md`, P11/P13 (UI bornée, low-risk) → routine ; P12/P14 (concurrence/lifecycle/observabilité) → complex ; P15 nécessite plan préalable car touche la doctrine offline.

---

## 8. Conclusion

**Encadré verdict :**

> **GLOBAL : WARN — invariant central V3 (« dispatch-after-commit ») respecté et lock+409+recordTransition prouvés ; warnings sur UX 409 non différenciée, couverture HTTP de la concurrence absente, et refresh Vuex partiel.**

Détail :
- **ALL_GREEN sur le code** : V1, V2, V3, V4, V7 (server), V8 (server) prouvés file:line.
- **WARN levés** : V6 (UX 409 muette / générique) ; V5 et V8 (côté tests : pas de test HTTP race ni multi-onglet) ; H4 partiellement vraie (refresh `state.orderItems` partiel).
- **Pas de FAIL** : aucun `event/dispatch/broadcast/Pusher::trigger` détecté à l'intérieur de la closure `DB::transaction(...)` du service KDS — l'invariant central de la tâche est tenu.

Critère §6 de la tâche : *« WARN si UX 409 muet »* — applicable ici.
