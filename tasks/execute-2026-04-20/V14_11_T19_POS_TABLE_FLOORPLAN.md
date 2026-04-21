# V14 #11 — T19 — `P14_POS_TABLE_FLOORPLAN`

## Header

```
TASK_ID: V14_11_T19_POS_TABLE_FLOORPLAN
WAVE: C-β — Finalisation caisse opérateur (sub-vague β)
GATE_REFERENCE: aucun (extension surface existante dining_tables, pas de OrderService LOCK_B)
PRIMARY_MODEL: GPT-5.4 (foodking-complex-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_10_T15_HARDWARE_PRINTER_ESC_POS, V14_12_T21_POS_RECEIPT_REDESIGN
DEPENDS_ON: aucun (réutilise dining_tables + dining_table_id existants)
SEVERITY: P1
EFFORT_EST: 1 j
```

## Contexte

Le système possède déjà :
- Table `dining_tables` (id, name, slug, size, branch_id, status active/inactive, qr_code) — migration `2023_09_05_133748_create_dining_tables_table.php`
- Colonne `orders.dining_table_id` — migration `2023_11_18_154743_add_dining_table_id_to_order_table.php`
- Modèle `DiningTable` avec `BranchScope` ✅
- `DiningTableService` (CRUD)
- Routes `dining-table` (admin + frontend) dans `routes/api.php` lignes 776 et 1019
- Dans `PosComponent.vue` un sélecteur radio dine-in gated par `pos_dine_in_enabled` + commentaire explicite "floor-plan + table management" comme prérequis manquant
- KDS regroupe déjà par `dining_table_id`

**Ce qui manque** (gap T19) :
- **État d'occupation temps réel** (`free` / `occupied` / `reserved` / `cleaning`) — le `status` actuel est ACTIVE/INACTIVE catalogue, pas opérationnel
- **Page `/admin/pos/floorplan`** : grille visuelle des tables avec couleurs par état
- **Click table → assign commande en cours / ouvrir commande existante**
- **Transfer table A → B** avec **audit log** (sans toucher OrderService LOCK_B)

T19 livre une **extension non-destructive** :
1. Ajouter colonnes `occupancy_status`, `occupied_at`, `occupied_order_id` à `dining_tables` (nullable, défaut FREE — backward-compat)
2. Ajouter table `dining_table_audit_logs` pour tracer transfers (NF525 traçabilité)
3. Étendre `DiningTableService` avec `occupy()`, `release()`, `transfer()` — **sans toucher OrderService**
4. Nouveau `FloorplanComponent.vue` + 4 endpoints
5. Hook minimal dans `PosComponent.vue` (1 bouton "Floor plan" ouvre modale/route)

## SUBSYSTEMS_TOUCHED

- `database/migrations/2026_04_20_xxxxx0_extend_dining_tables_occupancy.php` (CREATE — colonnes occupancy_status, occupied_at, occupied_order_id)
- `database/migrations/2026_04_20_xxxxx1_create_dining_table_audit_logs_table.php` (CREATE — log transfers)
- `app/Models/DiningTable.php` (EDIT — fillable + casts pour nouvelles colonnes + helper isFree() / isOccupied())
- `app/Models/DiningTableAuditLog.php` (CREATE)
- `app/Services/DiningTableService.php` (EDIT — ajouter méthodes `occupy`, `release`, `transfer`, `floorplanState`)
- `app/Http/Controllers/Admin/Pos/FloorplanController.php` (CREATE — endpoints state/assign/release/transfer)
- `app/Http/Requests/Admin/Pos/FloorplanTransferRequest.php` (CREATE — validation source_id + target_id)
- `routes/api.php` (EDIT minimal — bloc `pos/floorplan/*` sous middleware admin)
- `resources/js/store/modules/posFloorplan.js` (CREATE — state + actions fetch/assign/transfer)
- `resources/js/store/index.js` (EDIT minimal — register module `posFloorplan`)
- `resources/js/components/admin/pos/FloorplanComponent.vue` (CREATE — grille tables avec couleurs)
- `resources/js/components/admin/pos/PosComponent.vue` (EDIT minimal — 1 bouton "Plan de salle" + import lazy du composant)
- `resources/js/router/modules/admin.js` ou équivalent (EDIT minimal — route `/admin/pos/floorplan` si pattern actuel)
- `resources/js/languages/fr.json` / `en.json` / `ar.json` (EDIT — clés UI floorplan uniquement : `label.floorplan`, `label.occupied`, `label.free`, `label.reserved`, `label.cleaning`, `button.transfer_table`, `button.release_table`, `message.confirm_transfer`)
- `tests/Feature/Pos/FloorplanControllerTest.php` (CREATE — 8 cas backend)
- `tests/js/posFloorplan.spec.js` (CREATE — 4 cas store)

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/OrderService.php`, `FrontendOrderService.php` (LOCK_B frozen — transfer NE PASSE PAS par là)
- `app/Services/Pricing/PricingService.php` (frozen)
- `app/Services/PaymentService.php` (frozen)
- `app/Services/Fiscal/*` (frozen)
- `resources/js/components/admin/pos/PaymentComponent.vue` (frozen)
- `resources/js/components/admin/pos/ItemComponent.vue` (frozen)
- `resources/js/components/admin/pos/ReceiptComponent.vue` (territoire T21 en parallèle)
- `app/Services/Hardware/*` (territoire T15 en parallèle)
- `resources/js/services/posPrinter.js` (territoire T15)
- `app/Services/DiningTableService.php` méthodes existantes `list`/`store`/`update`/`destroy`/`show` : **étendre uniquement** (ajouter méthodes), ne pas réécrire
- `app/Http/Controllers/Admin/DiningTableController.php` : **NE PAS toucher** (c'est CRUD existant)

## INVARIANTS_AT_RISK

1. **Multi-tenant strict** : toutes les opérations passent par `BranchScope` actif sur `DiningTable` ; `FloorplanController` rejette toute action sur table d'une autre branche (404 implicite via global scope).
2. **Transfer N'OUVRE JAMAIS OrderService** : transfer modifie UNIQUEMENT `orders.dining_table_id` via une **mutation directe ciblée** (`Order::where('id', $orderId)->update(['dining_table_id' => $newTableId])`), sans déclencher d'événement, sans re-pricing, sans modification d'aucun autre champ. C'est un déplacement physique, pas un changement de commande.
3. **Audit log obligatoire** sur chaque transfer (qui, quand, table source, table cible, order_id, branch_id) — ligne ajoutée dans `dining_table_audit_logs`.
4. **Backward compat** : les colonnes `occupancy_status` / `occupied_at` / `occupied_order_id` sont **nullable avec défaut FREE/null** — toutes les requêtes existantes continuent de fonctionner. Migration backfill : `UPDATE dining_tables SET occupancy_status = 'free' WHERE occupancy_status IS NULL` (idempotent).
5. **Cohérence orders ↔ dining_tables** : si `orders.dining_table_id = X`, alors `dining_tables.id=X` doit avoir `occupied_order_id = orders.id` ET `occupancy_status = 'occupied'`. Helper de réconciliation `DiningTableService::reconcileOccupancy(int $branchId)` exposé pour usage manuel (commande artisan optionnelle).
6. **Idempotence occupy/release** :
   - `occupy(table, order)` quand déjà occupé par la **même** order → no-op succès
   - `occupy(table, order)` quand déjà occupé par une **autre** order → 409 Conflict
   - `release(table)` sur table déjà free → no-op succès
7. **Transfer race-safe** : utiliser `DB::transaction` + `lockForUpdate` sur les 2 tables source + cible AVANT toute modification.
8. **Permissions** : middleware admin POS sur tous les endpoints. Le user doit avoir accès à la branche (déjà filtré par BranchScope mais defense-in-depth dans le controller).
9. **Migration idempotente** + rollback propre (drop des colonnes + drop de la table audit). Utiliser `Schema::hasColumn` defensive.
10. **i18n** : 8 nouvelles clés exactement, identiques dans les 3 langues. Pas de touch sur les autres clés.

## TÂCHES À EXÉCUTER

### 1. Migration extension `dining_tables`

```php
return new class extends Migration {
    public function up(): void {
        Schema::table('dining_tables', function (Blueprint $table) {
            if (! Schema::hasColumn('dining_tables', 'occupancy_status')) {
                $table->string('occupancy_status', 16)->default('free')->after('status'); // free|occupied|reserved|cleaning
            }
            if (! Schema::hasColumn('dining_tables', 'occupied_order_id')) {
                $table->unsignedBigInteger('occupied_order_id')->nullable()->after('occupancy_status');
            }
            if (! Schema::hasColumn('dining_tables', 'occupied_at')) {
                $table->timestamp('occupied_at')->nullable()->after('occupied_order_id');
            }
        });

        // Backfill
        DB::table('dining_tables')->whereNull('occupancy_status')->update(['occupancy_status' => 'free']);

        // Index pour la grille floorplan
        if (! $this->indexExists('dining_tables', 'dining_tables_branch_occupancy_idx')) {
            try {
                Schema::table('dining_tables', function (Blueprint $table) {
                    $table->index(['branch_id', 'occupancy_status'], 'dining_tables_branch_occupancy_idx');
                });
            } catch (\Throwable $e) { /* defensive */ }
        }
    }
    public function down(): void {
        Schema::table('dining_tables', function (Blueprint $table) {
            if ($this->indexExists('dining_tables', 'dining_tables_branch_occupancy_idx')) {
                $table->dropIndex('dining_tables_branch_occupancy_idx');
            }
            if (Schema::hasColumn('dining_tables', 'occupied_at')) $table->dropColumn('occupied_at');
            if (Schema::hasColumn('dining_tables', 'occupied_order_id')) $table->dropColumn('occupied_order_id');
            if (Schema::hasColumn('dining_tables', 'occupancy_status')) $table->dropColumn('occupancy_status');
        });
    }
    private function indexExists(string $table, string $indexName): bool { /* idem T10 helper */ }
};
```

### 2. Migration `dining_table_audit_logs`

```php
Schema::create('dining_table_audit_logs', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('branch_id');
    $table->unsignedBigInteger('user_id'); // qui a fait l'action
    $table->string('action', 24); // 'occupy' | 'release' | 'transfer'
    $table->unsignedBigInteger('source_table_id')->nullable();
    $table->unsignedBigInteger('target_table_id')->nullable();
    $table->unsignedBigInteger('order_id')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamp('created_at')->useCurrent();

    $table->index(['branch_id', 'created_at']);
    $table->index(['order_id']);
});
```

### 3. Modèle `DiningTable` (EDIT)

Étendre `$fillable` + `$casts` :
```php
protected $fillable = ['name', 'slug', 'size', 'status', 'branch_id', 'qr_code',
    'occupancy_status', 'occupied_order_id', 'occupied_at']; // + 3
protected $casts = [
    /* existing */
    'occupancy_status'   => 'string',
    'occupied_order_id'  => 'integer',
    'occupied_at'        => 'datetime',
];

public function isFree(): bool     { return $this->occupancy_status === 'free' || $this->occupancy_status === null; }
public function isOccupied(): bool { return $this->occupancy_status === 'occupied'; }
```

### 4. Modèle `DiningTableAuditLog` (CREATE — minimal)

```php
class DiningTableAuditLog extends Model
{
    protected $table = 'dining_table_audit_logs';
    public $timestamps = false;
    protected $fillable = ['branch_id', 'user_id', 'action', 'source_table_id',
        'target_table_id', 'order_id', 'metadata', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
}
```

### 5. `DiningTableService` (EDIT — ajouter méthodes)

```php
public function floorplanState(int $branchId): array
{
    $tables = DiningTable::where('branch_id', $branchId)
        ->orderBy('name')
        ->get(['id', 'name', 'size', 'occupancy_status', 'occupied_order_id', 'occupied_at']);
    return $tables->toArray();
}

public function occupy(int $userId, int $branchId, int $tableId, int $orderId): DiningTable
{
    return DB::transaction(function () use ($userId, $branchId, $tableId, $orderId) {
        $table = DiningTable::where('id', $tableId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()->firstOrFail();

        if ($table->occupancy_status === 'occupied' && (int) $table->occupied_order_id !== $orderId) {
            abort(409, 'table_already_occupied');
        }

        $table->update([
            'occupancy_status'   => 'occupied',
            'occupied_order_id'  => $orderId,
            'occupied_at'        => now(),
        ]);

        DiningTableAuditLog::create([
            'branch_id' => $branchId, 'user_id' => $userId, 'action' => 'occupy',
            'source_table_id' => null, 'target_table_id' => $tableId,
            'order_id' => $orderId, 'created_at' => now(),
        ]);
        return $table->fresh();
    });
}

public function release(int $userId, int $branchId, int $tableId): DiningTable
{
    return DB::transaction(function () use ($userId, $branchId, $tableId) {
        $table = DiningTable::where('id', $tableId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()->firstOrFail();

        $previousOrderId = $table->occupied_order_id;
        $table->update([
            'occupancy_status'  => 'free',
            'occupied_order_id' => null,
            'occupied_at'       => null,
        ]);

        DiningTableAuditLog::create([
            'branch_id' => $branchId, 'user_id' => $userId, 'action' => 'release',
            'source_table_id' => $tableId, 'target_table_id' => null,
            'order_id' => $previousOrderId, 'created_at' => now(),
        ]);
        return $table->fresh();
    });
}

public function transfer(int $userId, int $branchId, int $sourceTableId, int $targetTableId): DiningTable
{
    if ($sourceTableId === $targetTableId) {
        abort(422, 'same_table');
    }
    return DB::transaction(function () use ($userId, $branchId, $sourceTableId, $targetTableId) {
        $source = DiningTable::where('id', $sourceTableId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()->firstOrFail();
        $target = DiningTable::where('id', $targetTableId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()->firstOrFail();

        if ($source->occupancy_status !== 'occupied') abort(422, 'source_not_occupied');
        if ($target->occupancy_status === 'occupied')  abort(409, 'target_already_occupied');

        $orderId = (int) $source->occupied_order_id;
        $occupiedAt = $source->occupied_at;

        // Move occupancy
        $target->update([
            'occupancy_status'  => 'occupied',
            'occupied_order_id' => $orderId,
            'occupied_at'       => $occupiedAt,
        ]);
        $source->update([
            'occupancy_status'  => 'free',
            'occupied_order_id' => null,
            'occupied_at'       => null,
        ]);

        // Update order's table — ciblé, pas via OrderService (LOCK_B)
        if ($orderId > 0) {
            \App\Models\Order::where('id', $orderId)
                ->where('branch_id', $branchId)
                ->update(['dining_table_id' => $targetTableId]);
        }

        DiningTableAuditLog::create([
            'branch_id' => $branchId, 'user_id' => $userId, 'action' => 'transfer',
            'source_table_id' => $sourceTableId, 'target_table_id' => $targetTableId,
            'order_id' => $orderId, 'created_at' => now(),
        ]);

        return $target->fresh();
    });
}
```

### 6. Controller + routes

```php
class FloorplanController extends Controller
{
    public function __construct(private readonly DiningTableService $service) {}

    public function state(Request $request)
    {
        $branchId = (int) ($request->user()->branch_id ?? 0);
        return ['data' => $this->service->floorplanState($branchId)];
    }

    public function assign(Request $request, int $tableId)
    {
        $data = $request->validate(['order_id' => 'required|integer|min:1']);
        $table = $this->service->occupy(
            (int) auth()->id(),
            (int) $request->user()->branch_id,
            $tableId,
            (int) $data['order_id']
        );
        return response()->json(['data' => $table]);
    }

    public function release(Request $request, int $tableId)
    {
        $table = $this->service->release(
            (int) auth()->id(),
            (int) $request->user()->branch_id,
            $tableId
        );
        return response()->json(['data' => $table]);
    }

    public function transfer(FloorplanTransferRequest $request)
    {
        $table = $this->service->transfer(
            (int) auth()->id(),
            (int) $request->user()->branch_id,
            (int) $request->validated('source_table_id'),
            (int) $request->validated('target_table_id')
        );
        return response()->json(['data' => $table]);
    }
}
```

Routes :
```php
Route::prefix('pos/floorplan')->group(function () {
    Route::get('/state',                    [FloorplanController::class, 'state']);
    Route::post('/transfer',                [FloorplanController::class, 'transfer']);
    Route::post('/{tableId}/assign',        [FloorplanController::class, 'assign']);
    Route::post('/{tableId}/release',       [FloorplanController::class, 'release']);
});
```

### 7. Store Vuex `posFloorplan.js`

State : `tables: []`. Actions :
- `fetchState` → GET → mutation `SET_TABLES`
- `assign({tableId, orderId})` → POST → reload state
- `release(tableId)` → POST → reload state
- `transfer({sourceId, targetId})` → POST → reload state
Getters : `byStatus(status)` filtre liste.

### 8. Composant `FloorplanComponent.vue`

UI : grille responsive (CSS grid auto-fill min 110px). Chaque table = carte cliquable avec :
- Nom + size
- Couleur fond selon `occupancy_status` (vert=free, rouge=occupied, orange=reserved, gris=cleaning)
- Si occupied : badge order_id + temps écoulé
- Click table libre : si commande active dans store → assign | sinon ouvrir prompt order_id
- Click table occupée : menu (Release, Transfer to..., Open order)
- Refresh auto toutes 15s + manuel button

### 9. Hook `PosComponent.vue` (minimal)

Ajouter UN seul bouton "Plan de salle" dans la barre POS qui :
- Soit navigue vers `/admin/pos/floorplan`
- Soit ouvre le composant en modale

**Ne PAS toucher** aux blocs existants (catalogue, panier, parked, search, payment) — ZONE strictement isolée d'une nouvelle ligne dans la barre top.

### 10. Tests Feature

`tests/Feature/Pos/FloorplanControllerTest.php` 8 cas :
1. GET state retourne la liste des tables de la branche courante uniquement (multi-tenant)
2. POST assign sur table libre → 200 + DB occupied + audit log row
3. POST assign sur table occupée par AUTRE order → 409 conflict
4. POST assign sur table occupée par MÊME order → 200 idempotent
5. POST release → DB free + audit log row + Order.dining_table_id NON modifié (release n'efface pas l'historique commande)
6. POST transfer source occupied → target free : succès + DB swapped + Order.dining_table_id mis à jour + 1 audit log
7. POST transfer source ≠ target dans MÊME branche : OK
8. POST transfer cross-branch → 404 (BranchScope global)

### 11. Tests Vitest store

`tests/js/posFloorplan.spec.js` 4 cas :
1. `fetchState` GET et SET_TABLES
2. `assign` POST avec bons params puis reload
3. `transfer` POST avec body { source_table_id, target_table_id }
4. Getter `byStatus('occupied')` filtre correctement

### 12. Régression

```bash
php artisan migrate
php artisan test --filter='Floorplan|DiningTable|Pos|Order'
npx vitest run tests/js/posFloorplan.spec.js tests/js/PosComponent.spec.js
```
→ Tous verts. Les 3 échecs préexistants documentés (DispatchAfterCommit, AllergenSnapshot) tolérés.

## ACCEPTANCE

- [ ] 2 migrations exécutées + rollback OK
- [ ] 3 colonnes `dining_tables` + 1 table audit + 1 index branch+occupancy
- [ ] Backfill `occupancy_status='free'` exécuté
- [ ] 8/8 Feature `FloorplanControllerTest`
- [ ] 4/4 Vitest `posFloorplan.spec.js`
- [ ] **AUCUN appel à OrderService** (grep `OrderService` dans diff = 0 dans `app/Services/DiningTableService.php` + `FloorplanController.php`)
- [ ] Audit log écrit pour chaque action (occupy, release, transfer)
- [ ] BranchScope actif → impossible de transfer cross-branch (404 testé)
- [ ] Hook `PosComponent.vue` minimal (≤ 15 lignes diff, dans la barre top uniquement, hors zones T08/T10/T11)
- [ ] i18n : 8 clés exactes ajoutées dans fr/en/ar
- [ ] 0 régression sur `php artisan test --filter='Pos|Order|Pricing'` (les 3 préexistants tolérés)

## NON-GOALS (explicite)

- **PAS** de modification de `OrderService`, `FrontendOrderService`, `PaymentService`, `PricingService`, `Fiscal*`
- **PAS** de réécriture du `DiningTableController` existant (CRUD admin) — uniquement extension du Service
- **PAS** de changement du modèle Order (juste UPDATE ciblé du `dining_table_id` lors du transfer)
- **PAS** de système de "réservation client" (le statut `reserved` est juste exposé visuellement, pas de logique de booking)
- **PAS** de Pusher broadcast pour la grille en temps réel (refresh polling 15s suffisant pour V1 ; broadcast sera un cycle ultérieur)
- **PAS** de touch sur `PaymentComponent.vue`, `ItemComponent.vue`, `ReceiptComponent.vue`, `kioskHardware.js`

## REPORT_FILE

`reports/execution/RUN_V14_T19_POS_TABLE_FLOORPLAN_2026-04-20.md`
