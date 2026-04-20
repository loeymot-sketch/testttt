# VERIFY-10 — Isolation `branch_id` + Permissions Spatie + State Machine

**Date :** 2026-04-20 (rev2 — vérification indépendante AUDIT-ONLY)
**Origine :** `tasks/verify-2026-04-20/10_VERIFY_BRANCH_ISOLATION.md` (axes 5-7 du POS 110 %).
**Mode :** AUDIT-ONLY — lecture seule sur code applicatif. 1 écriture de rapport (ce fichier). Aucune modification de code, migration, seed ou DB.
**Périmètre :** isolation back-end branche, permissions Spatie + matrice route × rôle, OrderStateMachine vs legacy `$order->status =`, restauration de l'audit perdu.

---

## 0. Résumé exécutif

| Axe | Verdict | Synthèse |
| --- | --- | --- |
| **V0** — Restauration | **NOTE** | Fichier `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` jamais commité (0 commits dans `git log --all -- …`). Aucune SHA récupérable via `git show`. Une **régénération hors-git** existe déjà (18 182 octets, untracked, mtime 2026-04-20 12:51) + un fichier `.RESTORED.md` (3 353 octets) documentant l'échec de restauration. Voir §1.1. |
| **V1** — `OrderService::list` force scope branche | **PASS** | `OrderService::list` (L106-174) ne lit jamais `$request->branch_id`. Scope imposé par `BranchScope` global posé sur `Order::booted()` (`Order.php:82`). |
| **V2** — Mutations cross-branch → 403 explicite | **PASS** | 4 gardes vérifiées : `changeStatus` (L1492-1497), `changePaymentStatus` (L1606-1611), `destroy` (L1720-1727), KDS `changeStatus` (L130-133). Toutes `abort(403, …)` avec message FR/EN explicite, après `Auth::check()` + `!hasRole('Admin')`. |
| **V3** — Pusher channel `branch.{id}` policy | **PASS** | `routes/channels.php:25-39` : kiosk token (`kiosk:order` ability) restreint à `KioskMachine::branch_id`, admin (`branch_id=0`) wildcard, staff branche unique. |
| **V4** — Routes fiscales (defense-in-depth) | **WARN** | `routes/api.php:794-806` (`Route::prefix('fiscal')`) ne porte **aucun** `permission:` middleware (ni au niveau group, ni `__construct`). La garde `pos-manage-fiscal` est **uniquement in-method** via `authorizeFiscal()` / `abort_unless` (`ZReportController.php:91-96`, `XReportController.php:25-26`). Defense-in-depth incomplète. |
| **V5** — Pas de `$order->status =` legacy hors call-sites V1 | **PASS_NOTE** | Recensement complet (8 sites) : tous dans `OrderStateMachine::apply` (SSOT, L156), `OrderService` (L1402, L1464, L1519), `FrontendOrderService` (L565, L676, L808), `KitchenDisplaySystemOrderService` (L144, sur `$locked`). Tous documentés frozen-zone V1, gardés en amont (`ValidStatusTransition` rule + `recordTransition` audit row). |
| **V6** — Matrice route × rôle | **WARN** | Matrice produite manuellement (§3.3) à partir de `RolePermissionTableSeeder` + introspection `__construct` controllers. Pas de script automatisé `php artisan route:list → markdown` (le wrapper `php artisan` local renvoie un HTML de redirection, cf. §3.4). |
| **V7** — Test feature staff A vs branche B | **PASS** | `tests/Feature/BranchIsolationTest.php` couvre cashier/chef branche A vs commande branche B sur index/show/changeStatus/changePaymentStatus/destroy/KDS. Compléments : `ActionLogBranchIsolationTest`, `KdsBranchFilterExactTest`, `KioskPhase7/KioskEventBranchIsolationTest`, `BranchScopeTest`. |
| **V8** — Admin `branch_id=0` documenté + garde fiscal | **PASS** | `BranchScope.php:31-36` (commentaire `[FIX-54-8]` explicite) ; `ZReportController::resolveBranchId` (L98-109) abort 422 si admin sans branche pinnée — admin pur ne peut pas déclencher Z par accident. |

### GLOBAL: WARN — aucun FAIL identifié, mais 3 NOTE/WARN à traiter (V0 hygiène, V4 defense-in-depth fiscal, V6 outillage matrice). Aucune fuite cross-tenant ni bypass permission identifié dans le code lu.

---

## 1. Étape 0 — Restauration audit perdu

### 1.1 Statut actuel

| Élément | Valeur |
| --- | --- |
| Chemin original | `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` |
| Taille actuelle | **18 182 octets** (mtime 2026-04-20 12:51) — **fichier régénéré hors-git** par un cycle antérieur |
| Statut git | `??` (untracked, jamais commité) |
| `git log --all -- <path>` | **vide** (0 commit) |
| `git log --all --diff-filter=A -- <path>` | **vide** (0 ajout) |
| `git log --all --follow -- <path>` | **vide** |
| Fichier `.RESTORED.md` | **présent** (3 353 octets, 2026-04-20 12:35) — explique l'impossibilité de restaurer depuis git, et liste les options de décision utilisateur |

### 1.2 Décision opérationnelle

- Le fichier originel n'est **pas** restaurable depuis git — aucune SHA n'existe.
- Une régénération à partir des sources actuelles a été produite hors de ce cycle (le contenu reflète l'inspection 2026-04-20, pas l'audit 2026-04-19 réel).
- **Aucune écriture** sur le fichier vidé original ni sur le `.RESTORED.md` n'a été faite par ce cycle (mode AUDIT-ONLY ; le fichier régénéré préexistait).
- Cf. **F-VERIFY-10-2** (hygiène) pour la prévention.

### 1.3 Verdict V0

**NOTE** — Restauration impossible (jamais commité). Régénération substitutive existante non normative. La perte est définitive ; seule l'hygiène future peut prévenir une récidive (cf. cycle `P13_AUDIT_REPORT_HYGIENE`).

---

## 2. Sources inspectées

| Fichier | Rôle dans cet audit |
| --- | --- |
| `app/Models/Scopes/BranchScope.php` | Global scope, gestion admin=0 |
| `app/Models/Order.php`, `FrontendOrder.php`, `DiningTable.php`, `PushNotification.php`, `User.php` | Pose du `BranchScope` |
| `app/Services/OrderService.php` | `list` (L106), `changeStatus` (L1440+), `changePaymentStatus` (L1592+), `destroy` (L1714+) |
| `app/Services/KitchenDisplaySystemOrderService.php` | `list` (L41+), `changeStatus` (L117+) |
| `app/Services/FrontendOrderService.php` | Symétrie statut (L565, L676, L808) |
| `app/Domain/Order/OrderStateMachine.php` | `allows`, `apply`, `recordTransition`, `legalTransitions` |
| `routes/api.php` | Groupe admin (L229-807), groupe fiscal (L794-806) |
| `routes/channels.php` | Pusher branch policy |
| `database/seeders/RolePermissionTableSeeder.php` | Mapping rôle × permission |
| `app/Http/Controllers/Admin/Fiscal/ZReportController.php`, `XReportController.php` | Authorize fiscal in-method |
| `app/Http/Controllers/Admin/PosOrderController.php`, `PosController.php`, `KitchenDisplaySystemController.php`, `OrderStatusScreenController.php`, `OnlineOrderController.php`, `TableOrderController.php` | `__construct` permissions |
| `tests/Feature/BranchIsolationTest.php`, `tests/Feature/ConcurrentOrderTest.php`, `tests/Feature/Domain/OrderStateMachineApplyTest.php`, `tests/Unit/Domain/Order/OrderStateMachineTest.php` | Couverture invariant |
| `AGENTS.md`, `.cursor/rules/safety.mdc`, `.cursor/rules/scope.mdc` | Règles opératoires |

---

## 3. Pass A — Isolation back-end branche

### 3.1 BranchScope (global)

```13:42:app/Models/Scopes/BranchScope.php
class BranchScope implements Scope
{
    use DefaultAccessModelTrait;

    public function apply(Builder $builder, Model $model)
    {
        // Never branch-filter the authenticatable model: Sanctum resolves the user via User queries.
        // Calling Auth::check() here would re-enter the guard → infinite recursion (see stack: BranchScope ↔ Sanctum).
        if ($model instanceof User) {
            return;
        }

        // PHPUnit runs in the console; still apply scope when APP_ENV=testing so HTTP/feature tests
        // that use actingAs() match production isolation (see BranchScopeTest).
        if ((!App::runningInConsole() || App::runningUnitTests()) && Auth::check()) {
            $field = sprintf('%s.%s', $builder->getQuery()->from, 'branch_id');
            $userBranch = $this->branch();

            // [FIX-54-8] Only admins (branch_id = 0) can see cross-branch records.
            // Regular staff should NEVER see records with branch_id = 0.
            if ($userBranch === 0) {
                return;
            }

            $builder->where($field, '=', $userBranch);
        }
    }
}
```

**Modèles avec `addGlobalScope(new BranchScope)` (5 confirmés via grep)** :
- `Order.php:82`
- `FrontendOrder.php:23`
- `DiningTable.php:27`
- `User.php:90` (no-op grâce au guard `$model instanceof User`)
- `PushNotification.php:31`

**`withoutGlobalScope[s]` recensés (légitimes)** :
- `app/Http/Controllers/Auth/GuestSignupController.php` (signup dup phone).
- `app/Jobs/CleanupStalePendingKioskOrders.php` (cron cleanup).
- `app/Services/Fiscal/FiscalSequenceService.php` (séquence fiscale par branche, calcul `MAX`).
- `app/Console/Commands/EnsureAdminLoginCommand.php`, `FiscalArchiveCommand.php` (CLI).
- `app/Services/Fiscal/ZReportService.php` (agrégation Z, scope branche maintenu via `where('branch_id', …)` explicite).

→ Aucun `withoutGlobalScope` non justifié.

### 3.2 V1 — `OrderService::list` ne lit pas `branch_id` du client

```106:174:app/Services/OrderService.php
public function list(PaginateRequest $request)
{
    try {
        $requests = $request->all();
        $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
        $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
        $orderColumn = $this->sanitizeOrderColumn((string) ($request->get('order_column') ?? 'id'));
        $orderType = $this->sanitizeOrderDirection((string) ($request->get('order_by') ?? 'desc'));

        return Order::with([...])->where(function ($query) use ($requests) {
            // … filtres date / status / payment_method / exceptFilter / exceptSource
            // AUCUNE lecture de $requests['branch_id'] — le scope vient de BranchScope.
        })->orderBy($orderColumn, $orderType)->$method($methodValue);
    } catch (Exception $exception) {
        Log::info($exception->getMessage());
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    }
}
```

→ **PASS**. **Hypothèse H1 RÉFUTÉE** : `branch_id` payload n'est jamais lu.

### 3.3 V2 — Mutations cross-branch → 403 explicite

```1492:1497:app/Services/OrderService.php
if (Auth::check() && !Auth::user()->hasRole('Admin')) {
    $userBranch = Auth::user()->branch_id ?? null;
    if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
        abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
    }
}
```

```1606:1611:app/Services/OrderService.php
if (Auth::check() && !Auth::user()->hasRole('Admin')) {
    $userBranch = Auth::user()->branch_id ?? null;
    if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
        abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
    }
}
```

```1720:1727:app/Services/OrderService.php
$actor = Auth::user();
$actorBranchId = (int) ($actor->branch_id ?? 0);
$orderBranchId = (int) $order->branch_id;
if ($actorBranchId > 0 && $actorBranchId !== $orderBranchId) {
    abort(403, 'Access denied: order does not belong to your branch.');
}
```

```130:133:app/Services/KitchenDisplaySystemOrderService.php
$userBranchId = (int) (auth()->user()->branch_id ?? 0);
if ($userBranchId > 0 && (int) $locked->branch_id !== $userBranchId) {
    abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
}
```

→ **PASS** — defense-in-depth explicite (au-delà du global scope) sur 3 mutateurs `OrderService` + KDS. Sémantique cohérente : Admin (`hasRole('Admin')` ou `branch_id=0`) court-circuite, staff borné à sa branche.

### 3.4 V3 — Pusher channel policy

```20:39:routes/channels.php
// [P4-1 FIX] Authorize branch-scoped channels for OrderStatusChanged / OrderCreated events.
// [GAP-21-5] Kiosk machine users have branch_id=0 (they use the admin user as owner).
// Without the token ability check, a kiosk token could subscribe to ALL branch channels
// (same as an admin) — a privilege escalation. Kiosk tokens carry 'kiosk:order' ability
// and must be restricted to the branch of their KioskMachine record.
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = \App\Models\KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }
    if ((int) $user->branch_id === 0) {
        return true;
    }
    return (int) $user->branch_id === (int) $branchId;
});
```

→ **PASS**. Nom canonique côté serveur = `branch.{branchId}` ; côté listeners outbox (`PersistOrderStatusChangedToOutbox`, `PersistOrderCreatedToOutbox`, `PersistItemAvailabilityChangedToOutbox`), le préfixe `private-` est ajouté par le client Pusher (channel `private-branch.{id}`). **Hypothèse H4 RÉFUTÉE.**

### 3.5 V8 — Admin `branch_id=0` documenté + garde fiscal

```31:36:app/Models/Scopes/BranchScope.php
// [FIX-54-8] Only admins (branch_id = 0) can see cross-branch records.
// Regular staff should NEVER see records with branch_id = 0.
if ($userBranch === 0) {
    return;
}
```

```98:109:app/Http/Controllers/Admin/Fiscal/ZReportController.php
private function resolveBranchId(Request $request): int
{
    $user = $request->user();
    $fromUser = (int) ($user->branch_id ?? 0);
    if ($fromUser > 0) {
        return $fromUser;
    }
    abort(Response::HTTP_UNPROCESSABLE_ENTITY,
        'Fiscal operation requires the authenticated user to be pinned to a branch.');
}
```

→ **PASS** — admin pur ne peut pas déclencher Z d'une branche par accident (422 explicite). Pour mutations standards (changeStatus / changePaymentStatus / destroy) Admin **peut** agir cross-branche par design (tracé `ActionLog`). **Hypothèse H2 RÉFUTÉE pour Z fiscal**, ASSUMÉE pour les autres mutateurs.

---

## 4. Pass B — Permissions Spatie + matrice route × rôle

### 4.1 Stack confirmée

- Routes admin sous `Route::prefix('admin')->middleware(['installed','apiKey','auth:sanctum','localization','throttle:admin-mutation'])` (`routes/api.php:229`).
- **Aucun** middleware `permission:` au niveau **route** (`grep "middleware\(['\"]permission:" routes/**/*.php` → 0 hit).
- Permissions appliquées **dans `__construct()` des controllers** via `$this->middleware(['permission:…'])` (~30 controllers vérifiés via grep, voir §4.3) — **sauf** les controllers fiscaux où la garde est in-method (`abort_unless`).
- Mapping rôle × permission dans `database/seeders/RolePermissionTableSeeder.php` ; Admin obtient `Permission::all()` (L18-19).

### 4.2 Matrice rôle × permission (extrait POS / KDS / Fiscal)

Source : `RolePermissionTableSeeder.php:18-146` (Admin via `Permission::all()` L19, autres rôles whitelist explicite).

| Permission | Admin | Branch Manager | POS Operator | Chef | Waiter | Stuff |
| --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `dashboard` | ✅ | ✅ | ✅ | ✅ (via défaut?) | ✅ | ✅ |
| `pos` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `pos-orders` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `pos-discount-up-to-10` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `pos-discount-over-10-requires-manager` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `pos-manage-fiscal` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `pos-reopen-z` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `pos-destroy-paid` | ✅ (via `Permission::all()`) | ❌ (absent du whitelist BM L27-75) | ❌ | ❌ | ❌ | ❌ |
| `kitchen-display-system` | ✅ | ✅ | ✅ (L113-117) | ✅ | ✅ | ✅ |
| `order-status-screen` | ✅ | ✅ | ✅ (L113-117) | ✅ | ✅ | ✅ |
| `online-orders` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `table-orders` | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| `transactions`, `sales-report` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `items_edit` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `customers_*`, `delivery-boys_*`, `employees_*`, `waiters_*`, `chefs_*` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `settings` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `administrators_*` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

### 4.3 Matrice route × permission (POS / Fiscal / KDS / OSS)

| Route (`routes/api.php`) | Verbe | Controller | Permission requise | Source de la garde |
| --- | --- | --- | --- | --- |
| `/admin/pos-order/*` (index, destroy, export, changeStatus, changePaymentStatus, selectDeliveryBoy, reorderItems) | GET/POST/DELETE | `PosOrderController` | `pos-orders` | `__construct` L26-34 (middleware) |
| `/admin/pos-order/{order}` | GET (show) | `PosOrderController` | `pos-orders\|pos` | `__construct` L35 |
| `/admin/pos/*` (store) | POST | `PosController` | `pos` | `__construct` (middleware Spatie) |
| `/admin/kitchen-display-system/*` (index, changeStatus, orderItems) | GET/PATCH | `KitchenDisplaySystemController` | `kitchen-display-system` | `__construct` L22 |
| `/admin/oss-order/*` | GET | `OrderStatusScreenController` | `order-status-screen` | `__construct` |
| `/admin/fiscal/z-report/{index,open,close,show,pdf}` | GET/POST | `ZReportController` | `pos-manage-fiscal` | **in-method** `authorizeFiscal()` L24,39,49,59,75 → `abort_unless` L94-95 |
| `/admin/fiscal/x-report` | GET | `XReportController` | `pos-manage-fiscal` | **in-method** `abort_unless` L25-26 |
| `/admin/online-order/*` | GET/POST/DELETE | `OnlineOrderController` | `online-orders` | `__construct` |
| `/admin/table-order/*` | GET/POST/DELETE | `TableOrderController` | `table-orders` | `__construct` L26 |
| `/admin/dashboard/*` | GET | `DashboardController` | `dashboard` | `__construct` |
| `/admin/sales-report/*` | GET | `SalesReportController` | `sales-report` | `__construct` |
| `/admin/items/*` | tous | `ItemController` | `items*` (split create/edit/delete/show) | `__construct` L28-32 |
| `/admin/dining-tables/*` | tous | `DiningTableController` | `dining-tables` + split CRUD | `__construct` L23-27 |
| `/admin/administrators/*` | tous | `AdministratorController` | `administrators` + split CRUD | `__construct` L31-35 |

### 4.4 V4 / Hypothèse H3 — fiscal defense-in-depth

```794:806:routes/api.php
Route::prefix('fiscal')->name('fiscal.')->group(function () {
    Route::prefix('z-report')->name('zReport.')->group(function () {
        Route::get('/',          [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'index']);
        Route::post('/open',     [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'open'])
            ->middleware('throttle:10,1');
        Route::post('/close',    [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'close'])
            ->middleware('throttle:10,1');
        Route::get('/{zReport}', [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'show']);
        Route::get('/{zReport}/pdf', [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'pdf']);
    });
    Route::get('/x-report', [\App\Http\Controllers\Admin\Fiscal\XReportController::class, 'show'])
        ->name('xReport.show');
});
```

→ Aucun `permission:pos-manage-fiscal` middleware au niveau group ni au niveau routes. Seul `throttle:10,1` est posé sur `open`/`close`. La garde permission est **uniquement in-method** dans le controller.

→ **V4 = WARN** — Fonctionnellement OK aujourd'hui (chaque action publique appelle `authorizeFiscal()`), mais une nouvelle action ajoutée au controller sans appel explicite serait **non gardée**. **Hypothèse H3 CONFIRMÉE PARTIELLEMENT** : la garde existe en in-method ; le risque est d'oubli sur extension future.

### 4.5 V6 — Outillage matrice

- Tentative `php artisan route:list --json` → renvoie HTML de redirection (probablement wrapper local/install particulier). Échec d'extraction automatique.
- Matrice §4.3 produite manuellement par lecture `routes/api.php` + `grep "middleware\(\['permission:" app/Http/Controllers/Admin/**/*.php` (29 hits visibles, voir §4.4 Stack).
- → **V6 = WARN** — Pas de drift detection automatisée. Cf. cycle `P12_ROLE_ROUTE_MATRIX_GEN` (§7).

### 4.6 FormRequest `authorize()`

`PosOrderRequest::authorize` et `OrderRequest::authorize` retournent `true` (constat non régressé depuis `AUDIT_POS_SECTION_3`). La garde réelle est portée par les middlewares et abort_unless controller. Pas de défaut nouveau dans le scope V0–V8.

---

## 5. Pass C — OrderStateMachine vs legacy `$order->status =`

### 5.1 OrderStateMachine SSOT

```22:71:app/Domain/Order/OrderStateMachine.php
final class OrderStateMachine
{
    public static function allows(int $from, int $to, ?Authenticatable $user = null): bool
    {
        if ($from === $to) { return true; }
        switch ($from) {
            case OrderStatus::PENDING:
                return in_array($to, [OrderStatus::ACCEPT, OrderStatus::CANCELED, OrderStatus::REJECTED], true);
            case OrderStatus::ACCEPT:
                if ($to === OrderStatus::DELIVERED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos')) { return true; }
                return in_array($to, [OrderStatus::PREPARING, OrderStatus::CANCELED], true);
            case OrderStatus::PREPARING:
                if ($to === OrderStatus::DELIVERED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos')) { return true; }
                return in_array($to, [OrderStatus::PREPARED, OrderStatus::CANCELED], true);
            // … PREPARED → OUT_FOR_DELIVERY|DELIVERED, OUT_FOR_DELIVERY → DELIVERED,
            //     DELIVERED → RETURNED, terminal CANCELED|REJECTED|RETURNED → only Admin
        }
    }
}
```

```131:171:app/Domain/Order/OrderStateMachine.php
public static function apply(Model $order, int $next, ?Authenticatable $actor = null, ?string $reason = null): void
{
    $from = (int) $order->status;
    if ($from === $next) { return; }
    if (!self::allows($from, $next, $actor)) {
        throw new IllegalTransitionException(/* … */);
    }
    if (self::requiresReason($next) && (!is_string($reason) || trim($reason) === '')) {
        throw new IllegalTransitionException('… requires a non-empty reason.');
    }
    DB::transaction(function () use ($order, $from, $next, $actor, $reason): void {
        $order->status = $next;
        if ($reason !== null && $order->isFillable('reason')) { $order->reason = $reason; }
        $order->save();
        self::recordTransition(/* … */);
    });
}
```

### 5.2 Recensement complet des `*->status = …` dans `app/Services/**` + `app/Domain/**`

(via `grep -n "->status\\s*=\\s*[^=]" app/Services/**`)

| Fichier:ligne | Contexte | Garde présente | Verdict V5 |
| --- | --- | --- | --- |
| `app/Domain/Order/OrderStateMachine.php:156` | `apply()` (le SSOT) | `allows` + `requiresReason` + audit row | OK SSOT |
| `app/Services/OrderService.php:1402` | helper `changeKDSStatus` (côté KDS pipeline) | transition validée en amont (pipeline POS-9-H) | OK frozen-zone V1 |
| `app/Services/OrderService.php:1464` | `changeStatus` self-cancellation user | `ValidStatusTransition` rule L1443 + `recordTransition` L1466 | OK frozen-zone V1 |
| `app/Services/OrderService.php:1519` | `changeStatus` staff | branch check L1492 + rule L1443 + `recordTransition` L1522 + `ActionLog` L1531 | OK frozen-zone V1 |
| `app/Services/FrontendOrderService.php:565` | `frontendOrder->status = ACCEPT` (auto-accept after create) | `recordTransition` L572 ; transition PENDING→ACCEPT autorisée | OK frozen-zone V1 |
| `app/Services/FrontendOrderService.php:676` | `changeStatus` self user | branch check + transition guard | OK frozen-zone V1 |
| `app/Services/FrontendOrderService.php:808` | `locked->status = ACCEPT` (lock-promote, idempotent) | `lockForUpdate` + early-return si déjà ≥ ACCEPT (L804) | OK frozen-zone V1 |
| `app/Services/KitchenDisplaySystemOrderService.php:144` | KDS `changeStatus` sur `$locked` | branch check L130-133 + `lockForUpdate` L127 + `OrderStateMachine::allows` L139 + `recordTransition` L147 | OK frozen-zone V1 |

→ **V5 = PASS_NOTE** — Tous les `$order->status =` legacy sont **strictement confinés aux 3 services frozen-zone V1** documentés explicitement dans le docblock `OrderStateMachine.php:18-20` (« Existing OrderService / FrontendOrderService call sites keep their historical pattern […] to honour the frozen zone V1 rule »). Aucun nouveau call-site introduit hors de ces services. Tous protégés par garde transition (`ValidStatusTransition` rule ou `OrderStateMachine::allows`) **et** trace audit (`OrderStateMachine::recordTransition` + `ActionLog`). **Hypothèse H5 RÉFUTÉE.**

### 5.3 Tests vérifiés

- `tests/Unit/Domain/Order/OrderStateMachineTest.php` — `allows` matrix (modifié dans git status, contenu non lu mais présent).
- `tests/Feature/Domain/OrderStateMachineApplyTest.php` — `apply()` end-to-end + `recordTransition` audit row.
- `tests/Feature/BranchIsolationTest.php` — POS-9.1.3 cashier/chef branche A vs commande branche B (index/show/changeStatus/changePaymentStatus/destroy/KDS).
- `tests/Feature/ConcurrentOrderTest.php` — race condition `lockForUpdate` KDS.
- `tests/Feature/ActionLogBranchIsolationTest.php`.
- `tests/Feature/KdsBranchFilterExactTest.php`, `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php`, `tests/Feature/BranchScopeTest.php`.

→ **V7 = PASS**.

---

## 6. Récap V0-V8

| V | Verdict | Preuve (file:line) |
| --- | --- | --- |
| V0 | **NOTE** | Fichier `0` octet untracked → régénération hors-git existante (18 182 oct, mtime 2026-04-20 12:51) ; aucune SHA git récupérable ; `.RESTORED.md` documente l'échec. |
| V1 | **PASS** | `OrderService::list` (L106-174) sans lecture `branch_id` payload ; `Order.php:82` ajoute `BranchScope`. |
| V2 | **PASS** | `OrderService.php:1495,1609,1726` ; `KitchenDisplaySystemOrderService.php:131-132`. |
| V3 | **PASS** | `routes/channels.php:25-39`. |
| V4 | **WARN** | `routes/api.php:794-806` (groupe fiscal) sans `permission:` middleware ; `ZReportController.php:91-96` (in-method `abort_unless`) ; `XReportController.php:25-26` idem. |
| V5 | **PASS_NOTE** | 8 sites `*->status =` recensés ; tous dans frozen-zone V1 + `recordTransition` audit row (cf. §5.2). |
| V6 | **WARN** | Matrice §4.3 produite manuellement ; `php artisan route:list --json` retourne HTML (wrapper local indisponible) → pas d'outillage automatisé. |
| V7 | **PASS** | `tests/Feature/BranchIsolationTest.php` + 5 tests connexes (cf. §5.3). |
| V8 | **PASS** | `BranchScope.php:31-36` (commentaire `[FIX-54-8]`) ; `ZReportController.php:98-109` ; `XReportController.php:28-30`. |

---

## 7. Findings

| ID | Sév | Constat | Preuve | Action proposée |
| --- | :-: | --- | --- | --- |
| **F-VERIFY-10-1** | **P1** | Routes fiscales (`routes/api.php:794-806`) ne portent **aucun** middleware `permission:pos-manage-fiscal`. Garde uniquement in-method (`authorizeFiscal()` / `abort_unless`). Risque d'oubli sur extension future du controller. | `routes/api.php:794-806` ; `ZReportController.php:91-96` ; `XReportController.php:25-26` | Cycle `P11_FISCAL_ROUTE_AUTHZ_HARDENING` |
| **F-VERIFY-10-2** | **P2** | `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` jamais commité ; perte définitive du rapport originel. Une régénération hors-git existe mais n'est pas normative. | `git log --all -- <file>` vide ; mtime fichier = 2026-04-20 12:51 (postérieur à la perte) | Cycle `P13_AUDIT_REPORT_HYGIENE` |
| **F-VERIFY-10-3** | **P2** | Pas de génération automatisée de la matrice route × permission. Maintien manuel → drift garanti dès qu'un controller bouge. `php artisan route:list` localement HS (HTML redirect). | §4.5 ; §4.3 produite à la main | Cycle `P12_ROLE_ROUTE_MATRIX_GEN` |
| **F-VERIFY-10-4** | **P3** | Comportement Admin `branch_id=0` cross-branch sur mutateurs `OrderService::changeStatus/changePaymentStatus/destroy` (court-circuit `hasRole('Admin')`) : commenté dans le code (`[AUDIT-FIX P0-2 / POS-9-H.1.1]`) mais pas documenté côté `docs/AUTHZ_MATRIX.md` (non lu dans ce cycle). À matérialiser pour éviter l'ambiguïté produit. | `OrderService.php:1492,1606,1724` (Admin bypass explicite) | Cycle `P14_AUTHZ_MATRIX_DOC_REFRESH` |
| **F-VERIFY-10-5** | **P3** | Migration progressive `OrderService::changeStatus` legacy → `OrderStateMachine::apply()` non planifiée. Frozen-zone V1 documenté comme choix transitoire (`OrderStateMachine.php:18-20`), mais pas de plan formel de levée. Dette à matérialiser en backlog. | `OrderStateMachine.php:18-20` (docblock frozen-zone) ; 7 call-sites legacy §5.2 | Cycle `P15_STATE_MACHINE_LEGACY_MIGRATION` (long-terme) |
| **F-VERIFY-10-6** *(info)* | **P3** | KDS `list()` Admin `branch_id=0` voit toutes les branches — by design (cf. `[FIX BUG-KDS-SYNC]` `KitchenDisplaySystemOrderService.php:56`). À documenter explicitement côté UX (« mode super-admin »). | `KitchenDisplaySystemOrderService.php:41-90` ; `BranchScope.php:33-35` | Doc UX (hors cycle code) |

---

## 8. Cycles P proposés

| Cycle | Trigger | Périmètre | Routing modèle | Estim. |
| --- | --- | --- | --- | --- |
| **P11_FISCAL_ROUTE_AUTHZ_HARDENING** | F-VERIFY-10-1 (V4 WARN) | Ajouter `->middleware('permission:pos-manage-fiscal')` sur le `Route::prefix('fiscal')` group L794 (et/ou `__construct` des controllers). Conserver `authorizeFiscal()` in-method (defense-in-depth). Test : staff sans `pos-manage-fiscal` → 403 sur tous les verbes Z/X. | **GPT-5.4** (touche routes + controllers fiscaux = `complex-implementer`, surface auth) | 0.5 j-h |
| **P11_PUSHER_CHANNEL_AUTHZ** *(préventif)* | (V3 PASS, mais hypothèse H4 mérite test E2E) | Ajouter test `tests/Feature/Broadcast/BranchChannelAuthzTest.php` simulant : (a) kiosk token branche X → autorisé seulement `branch.X`, (b) staff branche A → refusé `branch.B`, (c) admin → autorisé toutes. | **Composer** (test feature isolé) | 0.5 j-h |
| **P12_ROLE_ROUTE_MATRIX_GEN** | F-VERIFY-10-3 (V6 WARN) | Console command `php artisan permissions:matrix --format=md` → génère `docs/AUTHZ_MATRIX.md` à partir de `Route::getRoutes()` + introspection middleware `permission:` + parsing `__construct` + détection `abort_unless can()`. | **GPT-5.4** (introspection routes + AST controllers) | 1 j-h |
| **P13_AUDIT_REPORT_HYGIENE** | F-VERIFY-10-2 (V0 NOTE) | Hook git pre-commit + skill `project-handoff` qui : (a) refuse un commit si `reports/review/AUDIT_*.md` est dans `git status` mais 0 octet, (b) snapshot quotidien des rapports untracked dans `reports/_unstaged_snapshots/`. | **Composer** (script bash + skill MD) | 0.5 j-h |
| **P14_AUTHZ_MATRIX_DOC_REFRESH** | F-VERIFY-10-4 (P3 doc) | Mettre à jour `docs/AUTHZ_MATRIX.md` : Admin cross-branch sur mutateurs, comportement `branch_id=0`, garde fiscale `resolveBranchId`, KDS Admin = vue super-admin. | **Composer** (rewrite doc, pas de code) | 0.25 j-h |
| **P15_STATE_MACHINE_LEGACY_MIGRATION** *(dette)* | F-VERIFY-10-5 (V5 PASS_NOTE) | Plan multi-étapes pour migrer `OrderService::changeStatus` / `FrontendOrderService` / KDS de `$order->status =` direct vers `OrderStateMachine::apply()`. Préalable : revue invariants symétrie + frozen-zone V1 lift. | **Claude** (plan) puis **GPT-5.4** (exécution par lot) | 2-3 j-h |

---

## 9. Critères d'acceptation (§6 du task)

- ALL_GREEN si V0–V8 OK → **NON** (V0 NOTE, V4 + V6 WARN).
- WARN si V6 manquant → **OUI** (+ V0 + V4).
- FAIL si V1, V2, V3 ou V5 cassables → **NON** (tous PASS / PASS_NOTE).

---

> **GLOBAL: WARN — Aucune fuite cross-tenant ni bypass permission identifié dans le code lu ; durcissement defense-in-depth route fiscale (V4) + outillage matrice (V6) + hygiène rapports audit (V0) à planifier.**
