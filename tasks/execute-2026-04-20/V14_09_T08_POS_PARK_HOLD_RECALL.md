# V14 #9 — T08 — `P14_POS_PARK_HOLD_RECALL`

## Header

```
TASK_ID: V14_09_T08_POS_PARK_HOLD_RECALL
WAVE: C-α — Finalisation caisse opérateur (sub-vague α)
GATE_REFERENCE: aucun (nouvelle surface, pas de zone gelée NF525 ni OrderService)
PRIMARY_MODEL: GPT-5.4 (foodking-complex-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_07_T11_POS_AVAILABILITY_LIVE_GUARD, V14_08_T10_POS_SEARCH_BARCODE
DEPENDS_ON: aucun
SEVERITY: P1
EFFORT_EST: 1 j
```

## Contexte

Une caisse pro DOIT pouvoir **mettre une commande de côté sans la finaliser** (ex : client paie pas tout de suite, change d'avis, va chercher quelque chose). Aujourd'hui le panier POS est mono-session, mono-tab, et un refresh / logout / inactivity efface tout.

T08 ajoute une couche **"parked orders"** : snapshot du panier en DB, restore à la demande, multi-commandes en parallèle (sales floor multi-clients).

**Important** : c'est une **surface NEUVE** — pas de zone gelée touchée, pas d'OrderService, pas de PaymentService. Le snapshot vit dans `pos_parked_orders` séparée, indépendante des `orders` réelles.

## SUBSYSTEMS_TOUCHED

- `database/migrations/2026_04_20_xxxxxx_create_pos_parked_orders_table.php` (CREATE)
- `app/Models/PosParkedOrder.php` (CREATE)
- `app/Services/PosParkedOrderService.php` (CREATE — `park`, `recall`, `discard`, `listForOperator`)
- `app/Http/Controllers/Admin/Pos/ParkedOrderController.php` (CREATE — `index`, `store`, `show`, `destroy`)
- `routes/api.php` ou route admin POS (EDIT — 4 endpoints CRUD scoped par operator + branch)
- `resources/js/components/admin/pos/ParkedOrdersComponent.vue` (CREATE — liste latérale + boutons restore/discard)
- `resources/js/store/modules/posParked.js` (CREATE — actions + state)
- `resources/js/components/admin/pos/PosComponent.vue` (EDIT minimal — bouton "Park" dans la barre + intégration `<ParkedOrdersComponent>`)
- `tests/Feature/PosParkedOrderTest.php` (CREATE — 6 cas backend)
- `tests/js/posParked.spec.js` (CREATE — 4 cas frontend store)
- `resources/js/store/index.js` (EDIT minimal — register module `posParked` ; aucune autre modif)
- `app/Console/Commands/PosPurgeParkedOrders.php` (CREATE — auto-purge command, NON inscrite au scheduler)
- `resources/js/languages/fr.json` / `en.json` / `ar.json` (EDIT — clés i18n nouvelles UI park/restore/discard uniquement)

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/OrderService.php`, `FrontendOrderService.php` (frozen)
- `app/Services/Pricing/PricingService.php` (frozen)
- `app/Services/PaymentService.php` (frozen — gate C9)
- `resources/js/components/admin/pos/PaymentComponent.vue` (frozen)
- `resources/js/components/admin/pos/ItemComponent.vue` (T11 territoire en parallèle)
- `resources/js/helpers/posBarcode.js` ou `PosComponent.vue` zones recherche/F-keys (T10 territoire)

## INVARIANTS_AT_RISK

1. **Isolation par operator + branch** : un opérateur ne voit QUE ses commandes parkées, sur SA branche active. Multi-tenant strict.
2. **Pas d'effet sur les `orders` réelles** : park n'écrit JAMAIS dans `orders` ni `order_items`. Le payload est sérialisé en JSON dans `pos_parked_orders.payload_json` uniquement.
3. **Recall idempotent** : recall = read + delete dans transaction (lock pessimiste pour éviter qu'un autre poste recall la même).
4. **Auto-purge** : ajouter une commande artisan `pos:purge-parked-orders --older-than-hours=24` (mais ne PAS l'inscrire au scheduler dans cette tâche — juste créer la commande pour usage manuel).
5. **Idempotency** : 2 clicks rapides sur "Park" → 1 seule entrée DB (debounce frontend OU UNIQUE constraint sur `(operator_id, idempotency_token)`).
6. **Sécurité** : middleware auth admin POS sur toutes les routes ; check explicite `operator_id === auth()->id()` dans le controller (defense-in-depth).
7. **Migration idempotente** + rollback propre.
8. **Sérialisation safe** : `payload_json` doit pouvoir contenir le `lists` panier complet (variations multi-qty, extras, customer_id, table_id, instructions, etc.). Cap à `JSON LONGTEXT`.

## TÂCHES À EXÉCUTER

### 1. Migration `pos_parked_orders`

```php
Schema::create('pos_parked_orders', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('branch_id');
    $table->unsignedBigInteger('user_id'); // operator
    $table->string('label', 80)->nullable(); // ex: nom client / table
    $table->longText('payload_json'); // snapshot complet du panier
    $table->decimal('preview_total', 12, 2)->default(0); // pour affichage liste sans parser JSON
    $table->unsignedSmallInteger('items_count')->default(0); // idem affichage
    $table->string('idempotency_token', 64)->nullable();
    $table->timestamps();

    $table->index(['branch_id', 'user_id', 'created_at'], 'pos_parked_branch_user_idx');
    $table->unique(['user_id', 'idempotency_token'], 'pos_parked_user_idem_uniq');

    $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
```

`down()` : `Schema::dropIfExists('pos_parked_orders');`

### 2. Modèle `PosParkedOrder`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosParkedOrder extends Model
{
    protected $fillable = [
        'branch_id', 'user_id', 'label', 'payload_json',
        'preview_total', 'items_count', 'idempotency_token',
    ];
    protected $casts = [
        'payload_json'   => 'array',
        'preview_total'  => 'decimal:2',
        'items_count'    => 'integer',
    ];
}
```

### 3. Service `PosParkedOrderService`

```php
namespace App\Services;

use App\Models\PosParkedOrder;
use Illuminate\Support\Facades\DB;

final class PosParkedOrderService
{
    public function park(int $userId, int $branchId, array $payload, ?string $label = null, ?string $idempotencyToken = null): PosParkedOrder
    {
        // Si token fourni, upsert (returning existing if present)
        if ($idempotencyToken !== null) {
            $existing = PosParkedOrder::where('user_id', $userId)
                ->where('idempotency_token', $idempotencyToken)->first();
            if ($existing) return $existing;
        }

        $items = $payload['lists'] ?? $payload['items'] ?? [];
        $previewTotal = (float) ($payload['total'] ?? $payload['subtotal'] ?? 0);
        $itemsCount = is_array($items) ? count($items) : 0;

        return PosParkedOrder::create([
            'branch_id'         => $branchId,
            'user_id'           => $userId,
            'label'             => $label,
            'payload_json'      => $payload,
            'preview_total'     => $previewTotal,
            'items_count'       => $itemsCount,
            'idempotency_token' => $idempotencyToken,
        ]);
    }

    public function listForOperator(int $userId, int $branchId): \Illuminate\Support\Collection
    {
        return PosParkedOrder::where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public function recall(int $userId, int $parkedId): ?PosParkedOrder
    {
        return DB::transaction(function () use ($userId, $parkedId) {
            $parked = PosParkedOrder::where('id', $parkedId)
                ->where('user_id', $userId)
                ->lockForUpdate()->first();
            if (! $parked) return null;
            $clone = clone $parked;
            $parked->delete();
            return $clone;
        });
    }

    public function discard(int $userId, int $parkedId): bool
    {
        return PosParkedOrder::where('id', $parkedId)
            ->where('user_id', $userId)
            ->delete() > 0;
    }
}
```

### 4. Controller + routes

```php
class ParkedOrderController extends Controller
{
    public function __construct(private readonly PosParkedOrderService $service) {}

    public function index(Request $request) {
        return $this->service->listForOperator(auth()->id(), $request->user()->branch_id);
    }
    public function store(Request $request) {
        $data = $request->validate([
            'payload' => 'required|array',
            'label' => 'nullable|string|max:80',
            'idempotency_token' => 'nullable|string|max:64',
        ]);
        return $this->service->park(
            auth()->id(), $request->user()->branch_id,
            $data['payload'], $data['label'] ?? null, $data['idempotency_token'] ?? null
        );
    }
    public function show(int $id) {
        $parked = $this->service->recall(auth()->id(), $id);
        return $parked ?: response()->json(['error' => 'not_found'], 404);
    }
    public function destroy(int $id) {
        return $this->service->discard(auth()->id(), $id)
            ? response()->noContent()
            : response()->json(['error' => 'not_found'], 404);
    }
}
```

Routes (sous middleware POS auth) :
```php
Route::prefix('pos/parked-orders')->group(function() {
    Route::get('/', [ParkedOrderController::class, 'index']);
    Route::post('/', [ParkedOrderController::class, 'store']);
    Route::get('/{id}', [ParkedOrderController::class, 'show']);   // recall
    Route::delete('/{id}', [ParkedOrderController::class, 'destroy']);
});
```

### 5. Store Vuex `posParked.js`

State : `list: []`. Actions :
- `fetchList` → GET → mutation `SET_LIST`.
- `park({label, idempotencyToken})` → POST avec payload = snapshot du panier courant (lire depuis `posCart`).
- `recall(id)` → GET `{id}` → restore dans `posCart` via mutation existante.
- `discard(id)` → DELETE.

### 6. Composant `ParkedOrdersComponent.vue`

UI : drawer / sidebar à droite avec liste (label, total, items_count, time-ago) + bouton "Restore" + bouton "Discard". Refresh sur ouverture.

### 7. Intégration `PosComponent.vue`

- Ajouter bouton "Park" dans la barre POS qui ouvre prompt label optionnel puis dispatch `posParked/park`.
- Ajouter bouton "Parked (3)" qui ouvre `<ParkedOrdersComponent>` en drawer.

### 8. Tests Feature

CREATE `tests/Feature/PosParkedOrderTest.php` 6 cas :
1. Park crée une entrée DB.
2. List retourne uniquement les commandes du même opérateur + branche.
3. Recall supprime l'entrée et retourne le payload.
4. Recall sur ID inexistant → 404.
5. Discard supprime.
6. Park 2× avec même `idempotency_token` → 1 seule entrée.

### 9. Tests Vitest store

CREATE `tests/js/posParked.spec.js` 4 cas :
1. `park` POST le payload avec idempotency_token généré.
2. `recall` met à jour `posCart` via mutation.
3. `discard` retire l'entrée du state.
4. Multi-park : 3 entrées coexistent.

### 10. Régression

```bash
php artisan test --filter='Pos|Order|Pricing'
npx vitest run tests/js/posParked.spec.js tests/js/posCart.spec.js tests/js/PosComponent.spec.js
```
→ Tous verts.

## ACCEPTANCE

- [ ] Migration `pos_parked_orders` créée + idempotente
- [ ] Modèle + service + controller + 4 routes
- [ ] Store Vuex `posParked` + composant `ParkedOrdersComponent.vue`
- [ ] Intégration bouton Park + drawer dans `PosComponent.vue`
- [ ] 6/6 tests Feature backend verts
- [ ] 4/4 tests Vitest frontend verts
- [ ] Régression POS + Order + Pricing : 0
- [ ] Auto-purge command créée (pas inscrite au scheduler)
- [ ] Multi-tenant isolation strictement enforced (operator + branch)

## RUN_REPORT

`reports/execution/RUN_V14_T08_POS_PARK_HOLD_RECALL_2026-04-20.md`

Doit contenir : schéma DB final, diff endpoints, store actions, captures mentales du flow park/recall, tests results.

## NOTES AUDITEUR

- Si Laravel utilise un middleware `branch.scope` global, vérifier qu'il s'applique aussi à ces nouvelles routes.
- L'auto-park sur logout est OUT_OF_SCOPE de cette tâche (TODO doc dans le RUN_REPORT pour T08-bis).
- Le schéma `pos_parked_orders` est volontairement déconnecté d'`orders` : pas de FK vers `orders.id` (pas encore de commande créée).
- Si Laravel 11 utilise `\Illuminate\Foundation\Configuration\Middleware`, brancher dans `bootstrap/app.php` au lieu de `app/Http/Kernel.php`.
- Toute UI ajoutée doit avoir des clés i18n (FR/EN/AR) pour les labels de boutons et messages d'erreur.
