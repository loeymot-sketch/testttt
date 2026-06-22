# V14 #3 — T07 — `P14_ORDER_ITEM_SNAPSHOT_HARDENING`

## Header

```
TASK_ID: V14_03_T07_ORDER_ITEM_SNAPSHOT_HARDENING
WAVE: A — Backend Foundation
GATE_REFERENCE: docs/gates/GATE_G14A_VARIATION_MULTI_QTY_CONSOLIDATED_2026-04-20.md (G14-A)
PRIMARY_MODEL: GPT-5.4 (foodking-complex-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_01_T01_*, V14_02_T05_*  (3 subagents simultanés)
DEPENDS_ON: T01 (item_attributes columns) recommended but not strict-blocking
BLOCKS: T08 (NF525 reprint integrity), T11 (KDS rendering), T12 (export comptable)
SEVERITY: P0 (NF525 immutabilité)
EFFORT_EST: 2.5h
```

## SUBSYSTEMS_TOUCHED

- `database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php` (CREATE)
- `app/Models/OrderItem.php` (EDIT — fillable + cast `composition_snapshot` array)
- `app/Services/OrderService.php` (EDIT — 3 sites : enrichir `$itemsArray[$i]` avec `composition_snapshot`)
- `app/Services/FrontendOrderService.php` (EDIT — 1 site : idem)
- `app/Http/Resources/OrderItemResource.php` (EDIT — fallback prio `composition_snapshot` puis `item_variations`)
- `tests/Feature/OrderItemCompositionSnapshotTest.php` (CREATE)

## SUBSYSTEMS_OFF_LIMITS (interdits)

- `app/Models/ItemAttribute.php` (T01)
- `app/Services/Pricing/**` (T05)
- Toute boucle de pricing (T05) — T07 enrichit l'array final, pas le calcul
- `resources/js/**` (vague B)
- Toute migration sur autres tables que `order_items`
- `app/Services/FiscalSequenceService.php`, `Z*Service.php` (NF525 cœur — restriction LOCK)

## INVARIANTS_AT_RISK

1. **NF525 immutabilité absolue** : snapshot écrit DANS la même `DB::transaction` que `OrderItem::insert(...)`. Aucune ré-écriture jamais.
2. **Backward-compat lecture** : un `OrderItem` legacy (`composition_snapshot=NULL`) doit rester sérialisable identiquement par `OrderItemResource` via fallback `item_variations`.
3. **Schéma additif** : nouvelle colonne nullable, rollback safe.
4. **Aucune mutation post-insert** : pas d'observer, pas de mutator, pas de listener qui modifie `composition_snapshot` après insert.
5. **OrderService LOCK_B** : modifications limitées à enrichissement array `$itemsArray` ; aucune nouvelle règle métier.
6. **Pas de double-pricing** : le snapshot enregistre le **résultat** du pricing, jamais ne le recalcule.

---

## PLAN

### Format snapshot v1

```json
{
  "schema_version": 1,
  "captured_at": "2026-04-22T13:42:01+02:00",
  "lines": [
    {
      "variation_id": 42,
      "attribute_id": 3,
      "attribute_name": "Viande",
      "variation_name": "Steak",
      "quantity": 3,
      "unit_price": 1.50,
      "line_total": 4.50
    },
    {
      "variation_id": 43,
      "attribute_id": 3,
      "attribute_name": "Viande",
      "variation_name": "Poulet",
      "quantity": 1,
      "unit_price": 1.20,
      "line_total": 1.20
    }
  ],
  "extras": [
    {
      "extra_id": 7,
      "extra_name": "Sauce piquante",
      "quantity": 2,
      "unit_price": 0.50,
      "line_total": 1.00
    }
  ]
}
```

**Justification champs** :
- `schema_version` : prépare évolution future (v2 = ajout `tax_rate_per_line`, etc.)
- `captured_at` : audit fiscal NF525, traçabilité historique
- `attribute_name` + `variation_name` : reprint NF525 doit être identique même si admin renomme la variation après commande
- `unit_price` + `quantity` + `line_total` : auto-vérifiable comptablement (sum `line_total` == `item_variation_total` à la persistence)

---

## EXECUTE

### Étape 1 — Migration additive

Fichier : `database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'composition_snapshot')) {
                // JSON nullable for engines that support JSON (MySQL 5.7+, Postgres, SQLite).
                $table->json('composition_snapshot')->nullable()->after('item_extras');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'composition_snapshot')) {
                $table->dropColumn('composition_snapshot');
            }
        });
    }
};
```

### Étape 2 — Modèle Eloquent

Fichier : `app/Models/OrderItem.php`

Ajouter à `$fillable` (juste après `'item_extras'`) :
```php
'composition_snapshot',
```

Ajouter à `$casts` :
```php
'composition_snapshot' => 'array',
```

**Aucun observer, aucun mutator, aucun listener** — l'immutabilité est garantie par la discipline d'écriture (insert only, jamais update sur ce champ).

### Étape 3 — Helper de construction du snapshot

Créer un service simple pour centraliser la construction et éviter duplication entre OrderService (3 sites) + FrontendOrderService (1 site).

Fichier : `app/Services/Pricing/CompositionSnapshotBuilder.php` (CREATE)

```php
<?php

namespace App\Services\Pricing;

use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use Illuminate\Support\Collection;

/**
 * Builds the immutable composition_snapshot persisted alongside each OrderItem.
 * Used by OrderService and FrontendOrderService at order creation time.
 *
 * NF525 immutability contract: this snapshot must NEVER be re-written after the
 * initial insert. Reprint flows MUST read from this snapshot, never recompute.
 */
final class CompositionSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param object $item Decoded payload line (stdClass) with item_variations / item_extras.
     * @param Collection $dbVariations Keyed by id (ItemVariation models).
     * @param Collection $dbExtras Keyed by id (ItemExtra models).
     * @param Collection|null $dbAttributes Keyed by id (ItemAttribute models). If null, will be loaded.
     * @return array snapshot ready to be JSON-cast by Eloquent
     */
    public function build(object $item, Collection $dbVariations, Collection $dbExtras, ?Collection $dbAttributes = null): array
    {
        $lines = [];
        $extras = [];

        if (isset($item->item_variations) && is_array($item->item_variations)) {
            $attrIds = [];
            foreach ($item->item_variations as $v) {
                $varId = $v->id ?? null;
                if (! $varId) continue;
                $dbVar = $dbVariations[$varId] ?? null;
                if (! $dbVar) continue;
                $attrIds[] = (int) $dbVar->item_attribute_id;
            }
            $attrIds = array_unique(array_filter($attrIds));
            $attrs = $dbAttributes ?? ($attrIds !== []
                ? ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id')
                : collect());

            foreach ($item->item_variations as $v) {
                $varId = $v->id ?? null;
                if (! $varId) continue;
                $dbVar = $dbVariations[$varId] ?? null;
                if (! $dbVar) continue;
                $qty = max(1, (int) ($v->quantity ?? 1));
                $unitPrice = (float) $dbVar->price;
                $attr = $attrs[(int) $dbVar->item_attribute_id] ?? null;
                $lines[] = [
                    'variation_id'   => (int) $dbVar->id,
                    'attribute_id'   => (int) $dbVar->item_attribute_id,
                    'attribute_name' => $attr?->name,
                    'variation_name' => (string) $dbVar->name,
                    'quantity'       => $qty,
                    'unit_price'     => round($unitPrice, 6),
                    'line_total'     => round($unitPrice * $qty, 6),
                ];
            }
        }

        if (isset($item->item_extras) && is_array($item->item_extras)) {
            foreach ($item->item_extras as $e) {
                $extId = $e->id ?? null;
                if (! $extId) continue;
                $dbExt = $dbExtras[$extId] ?? null;
                if (! $dbExt) continue;
                $qty = max(1, (int) ($e->quantity ?? 1));
                $unitPrice = (float) $dbExt->price;
                $extras[] = [
                    'extra_id'   => (int) $dbExt->id,
                    'extra_name' => (string) $dbExt->name,
                    'quantity'   => $qty,
                    'unit_price' => round($unitPrice, 6),
                    'line_total' => round($unitPrice * $qty, 6),
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at'    => now()->toIso8601String(),
            'lines'          => $lines,
            'extras'         => $extras,
        ];
    }
}
```

### Étape 4 — Wire-in dans OrderService (3 sites) + FrontendOrderService (1 site)

Pour CHAQUE site (`OrderService.php` L378-433, L683-762, L1085-1155 ; `FrontendOrderService.php` L293-366) :

1. Au début du fichier (use clause) ajouter SI absent :
   ```php
   use App\Services\Pricing\CompositionSnapshotBuilder;
   ```

2. **Juste avant** la ligne `$itemsArray[$i] = [ ... 'item_variations' => json_encode(...) ... ]`, après que `$dbVar` / `$dbExt` soient connus, ajouter :

   ```php
   // [T07] NF525 immutable composition snapshot — written in same transaction as insert.
   $compositionSnapshot = (new CompositionSnapshotBuilder())->build($item, $dbVariations, $dbExtras);
   ```

3. Ajouter dans le tableau `$itemsArray[$i]` (juste après la clé `'item_extras'`) :
   ```php
   'composition_snapshot' => json_encode($compositionSnapshot),
   ```

   **Note** : `json_encode` est OBLIGATOIRE car `OrderItem::insert(...)` (mass insert) ne déclenche pas le cast Eloquent `'array'`. Pour les chemins utilisant `OrderItem::create(...)` (1 par 1), `$compositionSnapshot` brut suffirait — mais ces 4 sites utilisent `insert(...)` mass, donc `json_encode` partout pour cohérence.

### Étape 5 — `OrderItemResource` — fallback prioritaire

Fichier : `app/Http/Resources/OrderItemResource.php`

Modifier UNIQUEMENT la clé `'item_variations'` et ajouter une clé `'composition_snapshot'` exposée :

```diff
-            'item_variations'                  => $this->safeJsonDecode($this->item_variations),
-            'item_extras'                      => $this->safeJsonDecode($this->item_extras),
+            // [T07] Prefer immutable composition_snapshot when present (T07+);
+            // legacy rows fall back to raw item_variations / item_extras JSON.
+            'item_variations'                  => $this->resolveVariationsForApi(),
+            'item_extras'                      => $this->resolveExtrasForApi(),
+            'composition_snapshot'             => $this->safeJsonDecode($this->composition_snapshot),
```

Ajouter méthodes privées dans la même classe :

```php
private function resolveVariationsForApi(): array
{
    $snapshot = $this->safeJsonDecode($this->composition_snapshot);
    if (is_array($snapshot) && isset($snapshot['lines']) && is_array($snapshot['lines'])) {
        return array_map(static function (array $line): array {
            return [
                'id'                => $line['variation_id'] ?? null,
                'item_attribute_id' => $line['attribute_id'] ?? null,
                'name'              => $line['variation_name'] ?? null,
                'attribute_name'    => $line['attribute_name'] ?? null,
                'quantity'          => $line['quantity'] ?? 1,
                'unit_price'        => $line['unit_price'] ?? null,
            ];
        }, $snapshot['lines']);
    }
    return $this->safeJsonDecode($this->item_variations);
}

private function resolveExtrasForApi(): array
{
    $snapshot = $this->safeJsonDecode($this->composition_snapshot);
    if (is_array($snapshot) && isset($snapshot['extras']) && is_array($snapshot['extras'])) {
        return array_map(static function (array $line): array {
            return [
                'id'         => $line['extra_id'] ?? null,
                'name'       => $line['extra_name'] ?? null,
                'quantity'   => $line['quantity'] ?? 1,
                'unit_price' => $line['unit_price'] ?? null,
            ];
        }, $snapshot['extras']);
    }
    return $this->safeJsonDecode($this->item_extras);
}
```

### Étape 6 — Tests Feature

Fichier : `tests/Feature/OrderItemCompositionSnapshotTest.php` (CREATE)

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Services\Pricing\CompositionSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderItemCompositionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_composition_snapshot_exists_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumn('order_items', 'composition_snapshot'));
    }

    public function test_orderitem_cast_to_array(): void
    {
        $item = OrderItem::factory()->create([
            'composition_snapshot' => json_encode(['schema_version' => 1, 'lines' => [], 'extras' => []]),
        ]);
        $item->refresh();
        $this->assertIsArray($item->composition_snapshot);
        $this->assertSame(1, $item->composition_snapshot['schema_version']);
    }

    public function test_legacy_orderitem_with_null_snapshot_works_in_resource_fallback(): void
    {
        $item = OrderItem::factory()->create([
            'composition_snapshot' => null,
            'item_variations' => json_encode([['id' => 1, 'name' => 'Steak']]),
            'item_extras' => json_encode([]),
        ]);
        $array = (new \App\Http\Resources\OrderItemResource($item))->resolve();
        $this->assertArrayHasKey('item_variations', $array);
        $this->assertCount(1, $array['item_variations']);
        $this->assertSame('Steak', $array['item_variations'][0]['name']);
    }

    public function test_resource_prefers_snapshot_over_legacy_field(): void
    {
        $snapshot = [
            'schema_version' => 1,
            'captured_at'    => '2026-04-22T13:42:01+02:00',
            'lines'          => [[
                'variation_id'   => 42,
                'attribute_id'   => 3,
                'attribute_name' => 'Viande',
                'variation_name' => 'Steak (snapshot value)',
                'quantity'       => 3,
                'unit_price'     => 1.50,
                'line_total'     => 4.50,
            ]],
            'extras' => [],
        ];
        $item = OrderItem::factory()->create([
            'composition_snapshot' => json_encode($snapshot),
            'item_variations' => json_encode([['id' => 42, 'name' => 'Steak (legacy mutated)']]),
        ]);
        $array = (new \App\Http\Resources\OrderItemResource($item))->resolve();
        $this->assertSame('Steak (snapshot value)', $array['item_variations'][0]['name']);
        $this->assertSame(3, $array['item_variations'][0]['quantity']);
    }

    public function test_snapshot_is_immutable_after_variation_rename(): void
    {
        $branch = Branch::factory()->create();
        $tax = Tax::factory()->create(['type' => TaxType::PERCENTAGE, 'tax_rate' => 10.0, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['has_menu' => true]);
        $tacos = Item::factory()->create([
            'price' => 10.00, 'tax_id' => $tax->id, 'item_category_id' => $cat->id, 'status' => Status::ACTIVE,
        ]);
        $attr = ItemAttribute::create(['name' => 'Viande', 'status' => Status::ACTIVE, 'min_select' => 1, 'max_select' => 4, 'allow_repeat' => true]);
        $var = ItemVariation::factory()->create([
            'item_id' => $tacos->id, 'item_attribute_id' => $attr->id, 'name' => 'Steak ORIGINAL', 'price' => 1.50, 'status' => Status::ACTIVE,
        ]);

        $payload = (object) [
            'item_id' => $tacos->id,
            'item_variations' => [(object) ['id' => $var->id, 'quantity' => 2]],
            'item_extras' => [],
        ];
        $snapshot = (new CompositionSnapshotBuilder())->build(
            $payload,
            collect([$var->id => $var]),
            collect()
        );

        // Persist as raw snapshot
        $oi = OrderItem::factory()->create([
            'composition_snapshot' => json_encode($snapshot),
            'item_variations' => json_encode([['id' => $var->id, 'name' => 'Steak ORIGINAL']]),
        ]);

        // Admin renames the variation later
        $var->update(['name' => 'Steak NEW NAME']);

        $oi->refresh();
        $this->assertSame('Steak ORIGINAL', $oi->composition_snapshot['lines'][0]['variation_name']);

        // Resource reflects historical name (NF525 reprint immutability)
        $array = (new \App\Http\Resources\OrderItemResource($oi))->resolve();
        $this->assertSame('Steak ORIGINAL', $array['item_variations'][0]['name']);
    }

    public function test_builder_supports_quantity_field(): void
    {
        $branch = Branch::factory()->create();
        $tax = Tax::factory()->create(['type' => TaxType::PERCENTAGE, 'tax_rate' => 10.0, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['has_menu' => true]);
        $tacos = Item::factory()->create(['price' => 10.00, 'tax_id' => $tax->id, 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        $attr = ItemAttribute::create(['name' => 'Viande', 'status' => Status::ACTIVE, 'min_select' => 1, 'max_select' => 4, 'allow_repeat' => true]);
        $varA = ItemVariation::factory()->create(['item_id' => $tacos->id, 'item_attribute_id' => $attr->id, 'name' => 'Steak', 'price' => 1.50, 'status' => Status::ACTIVE]);
        $varB = ItemVariation::factory()->create(['item_id' => $tacos->id, 'item_attribute_id' => $attr->id, 'name' => 'Poulet', 'price' => 1.20, 'status' => Status::ACTIVE]);

        $payload = (object) [
            'item_id' => $tacos->id,
            'item_variations' => [
                (object) ['id' => $varA->id, 'quantity' => 3],
                (object) ['id' => $varB->id, 'quantity' => 1],
            ],
            'item_extras' => [],
        ];
        $snapshot = (new CompositionSnapshotBuilder())->build(
            $payload,
            collect([$varA->id => $varA, $varB->id => $varB]),
            collect()
        );

        $this->assertCount(2, $snapshot['lines']);
        $this->assertSame(3, $snapshot['lines'][0]['quantity']);
        $this->assertEqualsWithDelta(4.50, $snapshot['lines'][0]['line_total'], 0.001);
        $this->assertSame(1, $snapshot['lines'][1]['quantity']);
        $this->assertEqualsWithDelta(1.20, $snapshot['lines'][1]['line_total'], 0.001);
    }
}
```

---

## VALIDATE

```bash
php artisan migrate
php artisan test tests/Feature/OrderItemCompositionSnapshotTest.php
php artisan test tests/Feature/PricingIntegrityTest.php           # régression
php artisan test tests/Feature/Services/Pricing/PricingServiceTest.php  # régression
php artisan test tests/Feature/DispatchAfterCommitTest.php        # sentinel

bash scripts/check-invariants.sh

# Régression OrderItemResource tests existants
php artisan test --filter=OrderItem
```

---

## AUDIT

- ☐ Migration `2026_04_22_000020_*` up/down idempotente
- ☐ `OrderItem` cast `composition_snapshot` => 'array' (test refresh OK)
- ☐ `CompositionSnapshotBuilder` créé, **réutilisé** sur 4 sites (3 OrderService + 1 FrontendOrderService) — pas de duplication
- ☐ Fallback `OrderItemResource` legacy → `item_variations` testé
- ☐ Snapshot immuable validé : rename variation ⇒ snapshot inchangé
- ☐ Aucun `update`, observer, ou listener qui modifie `composition_snapshot` ailleurs (grep `composition_snapshot` repo entier)
- ☐ `json_encode($compositionSnapshot)` utilisé partout (mass insert)
- ☐ Régression : `PricingServiceTest`, `PricingIntegrityTest` 100% verts inchangés
- ☐ Aucun nouveau dispatch d'event introduit

---

## CLOSE

`reports/execution/RUN_V14_03_T07_ORDER_ITEM_SNAPSHOT_HARDENING_2026-04-20.md` :

```markdown
# RUN_V14_03_T07 — Final report
- task_id: V14_03_T07_ORDER_ITEM_SNAPSHOT_HARDENING
- status: CLOSED
- attempts: <N>
- artifacts: migration, OrderItem.php (fillable+casts), CompositionSnapshotBuilder.php, OrderService.php (3 sites enrichis), FrontendOrderService.php (1 site enrichi), OrderItemResource.php (fallback), OrderItemCompositionSnapshotTest.php (6 tests)
- nf525_immutability_proof: ✓ test_snapshot_is_immutable_after_variation_rename
- regression: PricingServiceTest 100% green unchanged, OrderItem* existing tests green
- invariants: 6/6
- next_dependent: T11 (KDS rendering snapshot), T12 (export comptable depuis snapshot)
```

Update `.cursor/ACTIVE_CYCLE.md`.
