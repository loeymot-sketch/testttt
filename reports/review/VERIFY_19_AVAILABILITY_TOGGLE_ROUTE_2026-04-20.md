# VERIFY-19 — Route `menu/availability/toggle` + throttle `kiosk-menu`

**Date :** 2026-04-20
**Mode :** AUDIT-ONLY (aucune modification de code applicatif)
**Tâche :** `tasks/verify-2026-04-20/19_VERIFY_AVAILABILITY_TOGGLE_ROUTE.md`
**Auditeur :** Planner-Orchestrator (Claude Opus 4.7) — passes parallèles backend / rate-limit / front
**Origine :** modif locale `routes/api.php` ajoutant `POST /admin/menu/availability/toggle` + `throttle:kiosk-menu` sur `GET /api/frontend/menu`.

---

## 1. Plan exécuté (5 lignes)

1. Localisation des artefacts cibles (controller, FormRequest, event, listener, provider rate-limit, tests, UI admin).
2. Pass A — backend : `AvailabilityController`, `AvailabilityToggleRequest`, `ItemAvailabilityChanged`, `PersistItemAvailabilityChangedToOutbox`, `EventServiceProvider`.
3. Pass B — limiter & front : `RouteServiceProvider::configureRateLimiting`, tests `MenuControllerRateLimitTest`, audit `resources/js/**` pour UI admin toggle.
4. Confrontation aux hypothèses H1-H5 et matrice route × auth × validation × event × test.
5. Synthèse V1-V7 avec preuve `fichier:ligne` et verdict aligné §6 du task file.

---

## 2. Route câblée — preuve

```238:239:routes/api.php
    Route::post('/menu/availability/toggle', [AvailabilityController::class, 'toggle'])
        ->name('menu.availability.toggle');
```

Ce sous-groupe hérite (ligne 229) de :

```229:229:routes/api.php
Route::prefix('admin')->name('admin.')->middleware(['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation'])->group(function () {
```

⇒ chaîne effective : `installed` + `apiKey` (`x-api-key` requis) + `auth:sanctum` + `localization` + `throttle:admin-mutation` (30/min) **puis** `permission:items_edit` (constructor controller, voir V2). URL résolue : `POST /api/admin/menu/availability/toggle` (alias `admin.menu.availability.toggle`).

```954:957:routes/api.php
    // 1.4 — GET /api/frontend/menu : payload unifié (1 round-trip kiosk).
    Route::get('/menu', [\App\Http\Controllers\Frontend\MenuController::class, 'kiosk'])
        ->middleware(['auth:sanctum', 'throttle:kiosk-menu'])
        ->name('frontend.menu.kiosk');
```

---

## 3. Réfutation des hypothèses

| Hyp. | Énoncé | Verdict | Preuve |
|---|---|---|---|
| H1 | `AvailabilityController` n'existe pas (route 500). | **RÉFUTÉE** | `app/Http/Controllers/Admin/AvailabilityController.php:13` (classe), `:22` (méthode `toggle(AvailabilityToggleRequest): JsonResponse`). |
| H2 | Endpoint sans permission Spatie. | **RÉFUTÉE** | `permission:items_edit` posée dans le constructeur, scope `only('toggle')` — `app/Http/Controllers/Admin/AvailabilityController.php:19`. |
| H3 | Toggle ne broadcast pas l'event de prune kiosk. | **RÉFUTÉE** | Event dispatché en `DB::afterCommit` (controller `:63-72`) → listener `PersistItemAvailabilityChangedToOutbox` push outbox + `DispatchDomainEventsJob` (`app/Listeners/PersistItemAvailabilityChangedToOutbox.php:25-54`) ; canal `private-branch.{branch_id}` ligne `:30`. |
| H4 | Throttle `kiosk-menu` non défini. | **RÉFUTÉE** | `app/Providers/RouteServiceProvider.php:71-73` — `Limit::perMinute(60)->by(user.id ?: ip)`. |
| H5 | Pas de test. | **RÉFUTÉE** | `tests/Feature/Admin/AvailabilityControllerTest.php:31-81` (toggle + Event::assertDispatched) ; `tests/Feature/Routes/MenuControllerRateLimitTest.php:34-72` (middleware câblé + 429 runtime). |

---

## 4. Vérifications V1–V7 (§5)

### V1 — Contrôleur existe + méthode `toggle` typée — **PASS**
```13:23:app/Http/Controllers/Admin/AvailabilityController.php
class AvailabilityController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_edit'])->only('toggle');
    }

    public function toggle(AvailabilityToggleRequest $request): JsonResponse
    {
```
Signature typée (`AvailabilityToggleRequest` injecté, retour `JsonResponse`). Réponse JSON ligne `:75-81`.

### V2 — Permission Spatie appliquée — **PASS**
- Middleware `permission:items_edit` posé sur `toggle` uniquement (`AvailabilityController.php:19`).
- Le task file accepte `permission:menu-manage` **ou équivalent** ; `items_edit` est le rôle Spatie utilisé par tout le module Items (cf. seed in `AvailabilityControllerTest.php:28`). → conforme à §5 V2.
- Couche superposée : `apiKey` + `auth:sanctum` (route group), donc 4 défenses (clé API, session Sanctum, throttle admin-mutation, permission Spatie).
- Garde-fou supplémentaire branche : `resolveScopedBranchIds()` + 403 « Branch scope denied » si `branch_id` payload n'est pas dans la portée user (`AvailabilityController.php:29-35, 87-97`). → invariant **branch_id isolation** respecté.

### V3 — FormRequest validation — **PASS**
```14:22:app/Http/Requests/Admin/AvailabilityToggleRequest.php
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_available' => ['required', 'boolean'],
            'unavailable_reason' => ['nullable', 'string', 'max:32'],
        ];
    }
```
- `item_id` requis + `exists:items,id` (V6 input safety).
- `branch_id` nullable + `exists` (couplé V2 scope check).
- `is_available` boolean requis (cf. task §3 H1 mapping correct, attribut `available bool` du brief = `is_available`).
- `unavailable_reason` borné 32 chars.
- ⚠️ `authorize(): true` — l'autorisation est entièrement déléguée au middleware Spatie + scope branche, ce qui est cohérent (l'autorisation route-level est suffisante).

### V4 — `ItemAvailabilityChanged` event broadcast `private-branch.{id}` — **PASS**
```63:72:app/Http/Controllers/Admin/AvailabilityController.php
            DB::afterCommit(function () use ($dispatches, $itemId): void {
                foreach ($dispatches as [$targetBranchId, $available, $dispatchReason]) {
                    event(ItemAvailabilityChanged::forBranch(
                        itemId: $itemId,
                        branchId: (int) $targetBranchId,
                        isAvailable: (bool) $available,
                        reason: $dispatchReason
                    ));
                }
            });
```
- Émission **après commit** (invariant SECURITY §5 « notifications hors transaction » respecté).
- Émission **idempotente** : aucun event si `$didChange === false` (no-op state == state) — `:50-61` + `toggleBranchAvailability` `:130-133`.
- Constructeur dédié branche : `ItemAvailabilityChanged::forBranch()` `app/Events/ItemAvailabilityChanged.php:71-87` injecte `branchId`, `isAvailable`, `reason`.
- Listener wiring : `app/Providers/EventServiceProvider.php:108-110`
  ```
  ItemAvailabilityChanged::class => [
      PersistItemAvailabilityChangedToOutbox::class,
      BumpMenuSnapshotOnItemAvailabilityChanged::class,
      … InvalidateKioskMenuCacheOnItemAvailabilityChanged::class,
  ]
  ```
- Channel `private-branch.{branchId}` :
  ```25:30:app/Listeners/PersistItemAvailabilityChangedToOutbox.php
          if ($event->branchId !== null) {
              $payload['branch_id']    = $event->branchId;
              $payload['is_available'] = $event->isAvailable;
              $payload['reason']       = $event->reason;
              $channels = ['private-branch.' . $event->branchId];
          } else {
  ```
- Outbox + job : `DomainEvent` créé puis `DispatchDomainEventsJob::dispatch($id)->onQueue('high')` (`:41-55`). Pusher est piloté par ce job (pattern Outbox SSOT V1 — l'event lui-même n'implémente pas `ShouldBroadcast` directement, ce qui est volontaire pour traçabilité + reprise après crash).

### V5 — Throttle `kiosk-menu` défini, valeur cohérente — **PASS**
```71:73:app/Providers/RouteServiceProvider.php
        RateLimiter::for('kiosk-menu', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
```
- 60 req/min par user authentifié (fallback IP guest/kiosk) — aligné avec doc P9.2.8.
- Test runtime de saturation : `tests/Feature/Routes/MenuControllerRateLimitTest.php:72` valide HTTP 429 sur 61e requête.

### V6 — Test feature toggle + broadcast — **PASS partiel** (couvre ON→OFF et Event)
```75:80:tests/Feature/Admin/AvailabilityControllerTest.php
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($item, $branch) {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branch->id
                && $event->isAvailable === false
                && $event->reason === 'rupture';
        });
```
- Couvre : permission `items_edit`, header `x-api-key`, persistance `item_branch_availability`, event `ItemAvailabilityChanged::forBranch` avec bons attributs.
- **Gaps** (FINDING-1) :
  - pas de cas OFF→ON (réactivation) ;
  - pas de cas idempotent (re-toggle même état → 0 event) ;
  - pas de cas `branch_id=null` (fan-out toutes branches scope) ;
  - pas de cas 403 cross-branch (`resolveScopedBranchIds`) ;
  - pas d'assertion outbox (`DomainEvent` row + channel `private-branch.X`).
- Le test §V6 OK au sens minimal du critère (un toggle + un event), mais la couverture devra s'étendre.

### V7 — UI admin émet sur cette route + reflète état immédiat — **MISSING (WARN)**
- `resources/js/components/admin/menu/**` : **inexistant** (Glob → 0 fichier).
- Grep `availability/toggle` dans `resources/` → **0 fichier**.
- Grep `toggleAvailability` dans `resources/` → **0 fichier**.
- Grep `is_available|ItemBranchAvailability` dans `resources/js` → consommateurs en lecture seule :
  - `resources/js/components/admin/pos/PosComponent.vue` (POS lecture)
  - `resources/js/store/modules/kioskMenu.js`, `kioskCart.js` (Kiosk lecture)
  - `resources/js/services/eventContract.js`, `kiosk/KioskAppComponent.vue` (Echo subscribers)
- ⇒ aucun POST front vers `POST /api/admin/menu/availability/toggle`. L'endpoint backend est **opérationnel mais non exposé en UI admin**. Le pipeline kiosk/POS consomme déjà l'event Echo (canal correctement préfixé), donc côté **propagation** la chaîne est prête ; il manque uniquement l'émetteur côté Admin Menu.

---

## 5. Matrice route × auth × validation × event × test

| Dim. | État | Preuve `fichier:ligne` |
|---|---|---|
| **Route POST** | présente, prefix `admin`, name `admin.menu.availability.toggle` | `routes/api.php:238-239` |
| **Route GET menu kiosk** | sous `auth:sanctum + throttle:kiosk-menu` | `routes/api.php:955-957` |
| **Auth API key** | hérité du group `admin` | `routes/api.php:229` |
| **Auth Sanctum** | hérité du group `admin` | `routes/api.php:229` |
| **Authz Spatie** | `permission:items_edit` (méthode `toggle` uniquement) | `app/Http/Controllers/Admin/AvailabilityController.php:19` |
| **Branch scope** | rejet 403 si `branch_id` payload hors portée user | `AvailabilityController.php:29-35, 87-97` |
| **Validation** | FormRequest 4 règles (item_id, branch_id, is_available, unavailable_reason ≤32) | `app/Http/Requests/Admin/AvailabilityToggleRequest.php:14-22` |
| **Idempotence** | pas d'event si état inchangé (`$didChange=false`) | `AvailabilityController.php:50-61, 130-133` |
| **Lock** | `lockForUpdate()` sur `item_branch_availability` (item, branch) | `AvailabilityController.php:106-110` |
| **Event class** | `ItemAvailabilityChanged::forBranch()` avec `type='branch_availability'` | `app/Events/ItemAvailabilityChanged.php:71-87` |
| **Dispatch après commit** | `DB::afterCommit(...) event(...)` | `AvailabilityController.php:63-72` |
| **Outbox listener** | `PersistItemAvailabilityChangedToOutbox` câblé | `app/Providers/EventServiceProvider.php:108-110` |
| **Broadcast channel** | `private-branch.{branchId}` injecté dans `DomainEvent.channel` | `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:30` |
| **Broadcast async** | `DispatchDomainEventsJob` queue `high` | `PersistItemAvailabilityChangedToOutbox.php:53-54` |
| **Cache invalidation** | `InvalidateKioskMenuCacheOnItemAvailabilityChanged` listener | `EventServiceProvider.php:114` |
| **Rate limit `kiosk-menu`** | 60/min par user_id ou IP | `RouteServiceProvider.php:71-73` |
| **Test feature toggle** | OK (1 cas ON→OFF + Event::assertDispatched) | `tests/Feature/Admin/AvailabilityControllerTest.php:31-81` |
| **Test rate-limit** | OK (middleware câblé + 429 runtime 61e req) | `tests/Feature/Routes/MenuControllerRateLimitTest.php:34-72` |
| **UI admin emitter** | **ABSENT** | `resources/js/components/admin/menu/` n'existe pas |

---

## 6. Findings hors §5 (signalés sans modification de code)

- **FINDING-DUP : Duplication logique controller ↔ service.** `AvailabilityController::toggleBranchAvailability()` (`:99-142`) duplique presque intégralement `App\Services\Menu\AvailabilityService::toggle()` (`app/Services/Menu/AvailabilityService.php:31-73`). Divergences subtiles :
  - Controller : si la ligne n'existe pas et `$forceUpsert=false` et `$isAvailable=true` → **no-op** (`:113-115`).
  - Service : crée toujours la ligne quand absente.
  Le bon design serait que le controller délègue au Service (SSOT). Risque actuel : drift comportemental futur entre les deux chemins (auto-86 vs toggle manuel). → cycle `P11_AVAILABILITY_REFACTOR_DEDUPE` recommandé (priorité P2).

- **FINDING-EVENT-NO-SHOULDBROADCAST** : `ItemAvailabilityChanged` n'implémente pas `ShouldBroadcastNow` ; le broadcast réel passe par l'outbox (`DomainEvent` → `DispatchDomainEventsJob`). C'est volontaire (pattern V1 outbox = traçabilité, reprise crash, replay), mais à documenter explicitement pour éviter qu'un futur dev tente d'ajouter un second canal de broadcast direct.

- **FINDING-TEST-COVERAGE** : `AvailabilityControllerTest` couvre 1/4 chemins critiques (cf. V6 §gaps). À renforcer avant prod.

- **POSITIF — Branch isolation** : la combinaison FormRequest + `resolveScopedBranchIds` + scope branch=0 (admin global) respecte l'invariant FoodKing « branch_id = isolation business data ». Aucun staff branche A ne peut couper la dispo branche B (vérifié `:31-35`).

- **POSITIF — Dispatch après commit** : invariant SECURITY §5 (« notifications APRÈS DB::transaction ») respecté via `DB::afterCommit` ligne `:63`. Pas de risque de notification fantôme sur rollback.

---

## 7. Conclusion

```
GLOBAL: WARN
```

| Vérif | Statut |
|---|---|
| V1 Contrôleur + méthode typée | PASS |
| V2 Permission Spatie | PASS |
| V3 FormRequest | PASS |
| V4 Event broadcast `private-branch.{id}` | PASS |
| V5 Throttle `kiosk-menu` | PASS |
| V6 Test feature | PASS (couverture minimale, gaps documentés) |
| V7 UI admin emitter | **MISSING (WARN)** |

Critère §6 du task file :
- ALL_GREEN exigeait V1-V7 OK.
- WARN si V7 manquant côté UI → **état actuel**.
- FAIL aurait nécessité V1, V2, V4 ou V5 absents : aucun ne l'est.

L'endpoint backend est **production-safe** (auth, autorisation, validation, idempotence, lock, dispatch hors transaction, broadcast outbox, cache invalidation, rate-limit côté lecture menu, test feature présent). Il **manque le composant Vue admin** qui POSTe vers cette route et reflète immédiatement l'état après réception de l'event Echo de retour.

---

## 8. Cycles P recommandés

| Priorité | Cycle | Objet | Routage suggéré |
|---|---|---|---|
| **P11_AVAILABILITY_TOGGLE_UI_ADMIN** | P0 | Construire le composant Admin Menu (Vue) qui appelle `POST /api/admin/menu/availability/toggle`, gère l'état optimiste, écoute le retour Echo `ItemAvailabilityChanged` sur `private-branch.{id}`, restitue le badge « rupture » + raison. Permission Spatie `items_edit` côté guard route admin. | `foodking-routine-implementer` (UI bornée) |
| **P11b_AVAILABILITY_REFACTOR_DEDUPE** | P2 | Faire déléguer `AvailabilityController` à `Services\Menu\AvailabilityService::toggle/toggleForAllBranches` pour SSOT ; conserver le scope `resolveScopedBranchIds` comme garde-fou controller ; backfill tests pour garantir parité comportementale. | `foodking-complex-implementer` (touche service + controller) |
| **P11c_AVAILABILITY_TEST_BIDIRECTIONAL** | P1 | Étendre `AvailabilityControllerTest` : OFF→ON, idempotence (no-event), `branch_id=null` fan-out scope, 403 cross-branch, assertion outbox `DomainEvent.channel = ["private-branch.X"]`. | `foodking-routine-implementer` (tests bornés) |

Aucune `SCOPE_PRESSURE` détectée pendant cet audit (aucun fichier hors périmètre n'a été modifié — audit read-only strict). Aucune `ESCALATION` ouverte. Aucun gate humain déclenché : la décision UI admin (cycle P11) reste un travail produit normal, pas un gate de gouvernance.

---
*Fin du rapport VERIFY-19.*
