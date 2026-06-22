# V14 #2 — T05 — `P14_PRICING_SSOT_MULTI_QTY`

## Header

```
TASK_ID: V14_02_T05_PRICING_SSOT_MULTI_QTY
WAVE: A — Backend Foundation
GATE_REFERENCE: docs/gates/GATE_G14A_VARIATION_MULTI_QTY_CONSOLIDATED_2026-04-20.md (G14-A)
PRIMARY_MODEL: GPT-5.4 (foodking-complex-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_01_T01_*, V14_03_T07_*  (3 subagents simultanés)
DEPENDS_ON: T01 columns existent (mais T05 reste exécutable même si T01 pas merged — utilise defaults via Schema::hasColumn guards)
BLOCKS: T03, T04, T06 (UI consumers), T08, T16 (KDS multi-qty rendering)
SEVERITY: P0
EFFORT_EST: 3h
```

## SUBSYSTEMS_TOUCHED

- `app/Services/Pricing/PricingService.php` (EDIT — boucles variations/extras + validation min/max/allow_repeat)
- `app/Services/OrderService.php` (EDIT — 3 sites de boucle pricing dupliqués : L378-401, L683-730, L1085-1125)
- `app/Services/FrontendOrderService.php` (EDIT — 1 site : L293-338)
- `tests/Feature/Services/Pricing/PricingServiceMultiQtyTest.php` (CREATE)
- `tests/Feature/PosOrderMultiQtyVariationTest.php` (CREATE)

## SUBSYSTEMS_OFF_LIMITS (interdits)

- `app/Models/ItemAttribute.php` (T01 territoire)
- `app/Models/OrderItem.php` (T07 territoire)
- `database/migrations/**` (T01 + T07 territoires)
- `app/Http/Resources/OrderItemResource.php` (T07 territoire)
- `resources/js/**` (vague B)
- `app/Services/Payment*.php`, `OrderStateMachine.php` (hors scope)
- Tout fichier `tasks/phase9-sync/LOCK*.md` (read-only)

## INVARIANTS_AT_RISK

1. **Backward-compat strict** — un payload sans `quantity` (legacy POS) doit produire EXACTEMENT le même `total_price` qu'avant (bit-identique).
2. **SSOT prix** — le prix unitaire reste systématiquement `$dbVar->price` depuis DB, jamais payload.
3. **Cross-item guard** préservé — variation appartient bien à l'item parent.
4. **OrderService LOCK_B** — modifications limitées à "wire-in / refacto" du calcul existant ; aucune nouvelle règle métier.
5. **NF525** — aucun changement de comportement de calcul fiscal/TVA pour payloads legacy.
6. **Idempotency** — `X-Idempotency-Key` header reste honoré (aucun changement de hash).
7. **Dispatch-after-commit** — aucun nouvel `event(...)` ou `::dispatch(...)` introduit.

---

## PLAN

### Diff sémantique global (à appliquer aux 5 sites)

```diff
- $variationTotal += (float) $dbVar->price;
+ $varQuantity = max(1, (int) ($variation->quantity ?? 1));
+ $variationTotal += (float) $dbVar->price * $varQuantity;
```

Idem extras :

```diff
- $extraTotal += (float) $dbExt->price;
+ $extraQuantity = max(1, (int) ($extra->quantity ?? 1));
+ $extraTotal += (float) $dbExt->price * $extraQuantity;
```

### Validation multi-qty (uniquement dans `PricingService::calculateOrder`)

À ajouter APRÈS la boucle variations de chaque item, AVANT le calcul `$verifiedTotalPrice` :

```php
// [T05] Multi-quantity validation per attribute (T01 columns)
$this->assertVariationConstraints($item, $dbVariations);
```

Méthode privée nouvelle dans `PricingService` :

```php
private function assertVariationConstraints(object $item, $dbVariations): void
{
    if (! isset($item->item_variations) || ! is_array($item->item_variations)) {
        return;
    }

    // Group payload variations by attribute_id (resolved from DB).
    $byAttribute = [];          // [attrId => total_qty]
    $occurrenceByVar = [];      // [attrId => [varId => occurrence_count]]
    $attributeIds = [];

    foreach ($item->item_variations as $variation) {
        $varId = $variation->id ?? null;
        if (! $varId) continue;
        $dbVar = $dbVariations[$varId] ?? null;
        if (! $dbVar) continue;
        $attrId = (int) $dbVar->item_attribute_id;
        $qty = max(1, (int) ($variation->quantity ?? 1));
        $byAttribute[$attrId] = ($byAttribute[$attrId] ?? 0) + $qty;
        $occurrenceByVar[$attrId][$varId] = ($occurrenceByVar[$attrId][$varId] ?? 0) + 1;
        $attributeIds[] = $attrId;
    }

    if ($byAttribute === []) return;

    // Bulk-load attributes (T01 columns may or may not exist depending on migration order).
    $attrs = \App\Models\ItemAttribute::query()
        ->whereIn('id', array_unique($attributeIds))
        ->get()
        ->keyBy('id');

    foreach ($byAttribute as $attrId => $totalQty) {
        $attr = $attrs[$attrId] ?? null;
        if (! $attr) continue; // unknown attribute, do not block (defensive)

        // Defaults : min=0, max=1, allow_repeat=false  ⇒ legacy single-select behaviour.
        $min = (int) ($attr->min_select ?? 0);
        $max = (int) ($attr->max_select ?? 1);
        $allowRepeat = (bool) ($attr->allow_repeat ?? false);

        if ($max > 0 && $totalQty > $max) {
            throw new \InvalidArgumentException(
                "Attribut {$attr->name} : maximum {$max} sélection(s), reçu {$totalQty}.",
                422
            );
        }
        if ($min > 0 && $totalQty < $min) {
            throw new \InvalidArgumentException(
                "Attribut {$attr->name} : minimum {$min} sélection(s) requise(s), reçu {$totalQty}.",
                422
            );
        }
        if (! $allowRepeat) {
            // Disallow same variation_id appearing more than once with quantity > 1
            // OR appearing as 2 separate entries — both forms must be rejected.
            foreach (($occurrenceByVar[$attrId] ?? []) as $varId => $occur) {
                $sameVarTotalQty = 0;
                foreach ($item->item_variations as $vEntry) {
                    if ((int) ($vEntry->id ?? 0) === (int) $varId) {
                        $sameVarTotalQty += max(1, (int) ($vEntry->quantity ?? 1));
                    }
                }
                if ($sameVarTotalQty > 1) {
                    throw new \InvalidArgumentException(
                        "Attribut {$attr->name} : la variation #{$varId} ne peut être sélectionnée qu'une seule fois (allow_repeat=false).",
                        422
                    );
                }
            }
        }
    }
}
```

### Anti-régression : Schema::hasColumn fallback

Si T01 n'est pas encore mergé (peu probable car parallèle vague A), `($attr->min_select ?? 0)` retombe sur defaults legacy via `??` PHP — comportement single-select préservé. AUCUN guard explicite Schema::hasColumn nécessaire (Eloquent retourne `null` pour colonne inexistante).

---

## EXECUTE

### Étape 1 — `PricingService::calculateOrder` (cœur SSOT)

Fichier : `app/Services/Pricing/PricingService.php`

1. **Modifier la boucle variations** (autour des lignes 97-118) :
   - Remplacer `$variationTotal += (float) $dbVar->price;` par le pattern multi-qty.
2. **Modifier la boucle extras** (autour des lignes 120-142) — même pattern.
3. **Ajouter `$this->assertVariationConstraints($item, $dbVariations);`** APRÈS la boucle variations, AVANT le calcul `$verifiedTotalPrice`.
4. **Ajouter la méthode privée** `assertVariationConstraints` (cf. PLAN ci-dessus).
5. **Préserver le format JSON stocké** : `'item_variations' => json_encode($item->item_variations ?? []),` reste tel quel — le `quantity` éventuel est conservé naturellement dans le JSON.
6. **Ajouter test inline annotation** :
   ```php
   // [T05] Multi-quantity support: variations and extras may carry an optional
   // `quantity` field (default 1). Constraints (min_select / max_select /
   // allow_repeat) are validated per attribute via assertVariationConstraints.
   ```

### Étape 2 — `OrderService.php` — 3 sites symétriques

Fichier : `app/Services/OrderService.php`

Pour CHACUN des 3 sites (lignes ~378-401, ~683-730, ~1085-1125) :
- Appliquer le diff sémantique sur `$variationTotal` (ou `$calcVariationTotal`).
- Appliquer le diff sémantique sur `$extraTotal` (ou `$calcExtraTotal`).
- **NE PAS dupliquer** `assertVariationConstraints` dans OrderService — la validation reste centralisée dans PricingService quand le service est appelé. Pour les 3 chemins legacy (feature flag `pricing.use_ssot_service=false` ou autres), ajouter en tête de boucle :
  ```php
  // [T05] Backward-compat: legacy code paths multiply by quantity. Validation
  // min_select/max_select/allow_repeat is enforced upstream by PricingService
  // when the SSOT path is active. Legacy paths trust upstream form requests.
  ```
- **OFF-LIMITS** : ne RIEN modifier hors de ces 3 boucles. Pas de refactoring opportuniste.

### Étape 3 — `FrontendOrderService.php` — 1 site

Fichier : `app/Services/FrontendOrderService.php`

- Site lignes 293-338 — appliquer même diff sémantique. Variables `$calcVariationTotal` / `$calcExtraTotal` → multiplier par `quantity ?? 1`.

### Étape 4 — Tests Feature obligatoires

#### 4a) `tests/Feature/Services/Pricing/PricingServiceMultiQtyTest.php` (CREATE)

8 cas de test :

```php
<?php

namespace Tests\Feature\Services\Pricing;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\Tax;
use App\Enums\TaxType;
use App\Enums\Status;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceMultiQtyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax;
    private ItemCategory $category;
    private Item $tacos;
    private ItemAttribute $viandeAttr;
    private ItemVariation $varSteak;
    private ItemVariation $varPoulet;
    private ItemVariation $varMerguez;
    private PricingService $service;
    private CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->tax = Tax::factory()->create(['type' => TaxType::PERCENTAGE, 'tax_rate' => 10.0, 'status' => Status::ACTIVE]);
        $this->category = ItemCategory::factory()->create(['wizard_template' => 'tacos', 'has_menu' => true]);
        $this->tacos = Item::factory()->create([
            'price' => 10.00,
            'tax_id' => $this->tax->id,
            'item_category_id' => $this->category->id,
            'status' => Status::ACTIVE,
        ]);
        // T01 columns: viande attribut "4 max, allow_repeat true"
        $this->viandeAttr = ItemAttribute::create([
            'name' => 'Viande',
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 4,
            'allow_repeat' => true,
        ]);
        $this->varSteak = ItemVariation::factory()->create([
            'item_id' => $this->tacos->id,
            'item_attribute_id' => $this->viandeAttr->id,
            'name' => 'Steak',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]);
        $this->varPoulet = ItemVariation::factory()->create([
            'item_id' => $this->tacos->id,
            'item_attribute_id' => $this->viandeAttr->id,
            'name' => 'Poulet',
            'price' => 1.20,
            'status' => Status::ACTIVE,
        ]);
        $this->varMerguez = ItemVariation::factory()->create([
            'item_id' => $this->tacos->id,
            'item_attribute_id' => $this->viandeAttr->id,
            'name' => 'Merguez',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);
        $this->service = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    private function callPricing(array $variations): \App\Services\Pricing\PricingResult
    {
        $payload = (object) [
            'item_id' => $this->tacos->id,
            'quantity' => 1,
            'item_variations' => array_map(fn($v) => (object) $v, $variations),
            'item_extras' => [],
            'instruction' => null,
        ];
        $req = PricingRequest::forPos(1, $this->branch->id, [$payload], 0, 0, 0.0, 0.0);
        return $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_legacy_single_variation_no_quantity_yields_baseline_price(): void
    {
        $out = $this->callPricing([['id' => $this->varSteak->id]]);
        // 10.00 + 1.50 = 11.50 (rounded for POS)
        $this->assertEqualsWithDelta(11.50, $out->lines[0]->lineSubtotalExTax, 0.001);
    }

    public function test_legacy_single_variation_with_explicit_quantity_one_is_identical(): void
    {
        $out = $this->callPricing([['id' => $this->varSteak->id, 'quantity' => 1]]);
        $this->assertEqualsWithDelta(11.50, $out->lines[0]->lineSubtotalExTax, 0.001);
    }

    public function test_four_same_meats_allow_repeat_true(): void
    {
        // 10 + (1.50 * 4) = 16.00
        $out = $this->callPricing([['id' => $this->varSteak->id, 'quantity' => 4]]);
        $this->assertEqualsWithDelta(16.00, $out->lines[0]->lineSubtotalExTax, 0.001);
    }

    public function test_two_plus_two_mixed_meats(): void
    {
        // 10 + (1.50*2) + (1.20*2) = 15.40
        $out = $this->callPricing([
            ['id' => $this->varSteak->id, 'quantity' => 2],
            ['id' => $this->varPoulet->id, 'quantity' => 2],
        ]);
        $this->assertEqualsWithDelta(15.40, $out->lines[0]->lineSubtotalExTax, 0.001);
    }

    public function test_three_plus_one_mixed(): void
    {
        // 10 + (2.00*3) + (1.20*1) = 17.20
        $out = $this->callPricing([
            ['id' => $this->varMerguez->id, 'quantity' => 3],
            ['id' => $this->varPoulet->id, 'quantity' => 1],
        ]);
        $this->assertEqualsWithDelta(17.20, $out->lines[0]->lineSubtotalExTax, 0.001);
    }

    public function test_violation_max_select_returns_422(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum 4');
        // 5 viandes alors que max=4
        $this->callPricing([['id' => $this->varSteak->id, 'quantity' => 5]]);
    }

    public function test_violation_min_select_returns_422(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minimum 1');
        // viande attribut min=1, on envoie 0
        $this->callPricing([]); // empty variations on tacos that requires min=1
    }

    public function test_violation_allow_repeat_false(): void
    {
        // Recreate attribute with allow_repeat=false
        $this->viandeAttr->update(['allow_repeat' => false, 'max_select' => 4]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_repeat=false');
        $this->callPricing([['id' => $this->varSteak->id, 'quantity' => 2]]);
    }

    public function test_extras_with_quantity_field_multiplies_price(): void
    {
        $extra = \App\Models\ItemExtra::factory()->create([
            'item_id' => $this->tacos->id,
            'price' => 0.50,
            'status' => Status::ACTIVE,
        ]);
        $payload = (object) [
            'item_id' => $this->tacos->id,
            'quantity' => 1,
            'item_variations' => [(object) ['id' => $this->varSteak->id, 'quantity' => 1]],
            'item_extras' => [(object) ['id' => $extra->id, 'quantity' => 3]],
            'instruction' => null,
        ];
        $req = PricingRequest::forPos(1, $this->branch->id, [$payload], 0, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);
        // 10 + 1.50 + (0.50 * 3) = 13.00
        $this->assertEqualsWithDelta(13.00, $out->lines[0]->lineSubtotalExTax, 0.001);
    }
}
```

#### 4b) Régression — `tests/Feature/Services/Pricing/PricingServiceTest.php`

NE PAS modifier ce fichier. La suite doit rester verte 100% inchangée (tous les payloads existants n'ont pas de `quantity` ⇒ comportement legacy via `?? 1`).

#### 4c) Optionnel mais recommandé — `tests/Feature/PosOrderMultiQtyVariationTest.php` (CREATE, lite)

1 test end-to-end : POST `/api/admin/pos/orders` avec payload multi-qty, vérifier `Order::find($id)->orderItems[0]->total_price` matches expected.

---

## VALIDATE

```bash
php artisan test tests/Feature/Services/Pricing/PricingServiceMultiQtyTest.php  # 9/9
php artisan test tests/Feature/Services/Pricing/PricingServiceTest.php          # ALL GREEN unchanged
php artisan test tests/Feature/PricingIntegrityTest.php                          # ALL GREEN unchanged
php artisan test tests/Feature/DispatchAfterCommitTest.php                       # GREEN sentinel
php artisan test --filter=PosOrder                                               # smoke test POS
php artisan test --filter=FrontendOrder                                          # smoke test Kiosk

bash scripts/check-invariants.sh                                                 # 6/6 GREEN
```

**Régression bit-identique critique** : exécuter PricingServiceTest AVANT et APRÈS — diff `phpunit --teamcity` sur le 2 runs, doit être identique.

---

## AUDIT

- ☐ 5 sites de boucle modifiés (pas un de plus, pas un de moins) — vérifier `git diff --stat | wc -l`
- ☐ `assertVariationConstraints` UNIQUEMENT dans `PricingService` (grep tout le repo)
- ☐ Aucun nouvel `event(`, `dispatch(`, `Event::dispatch` ajouté (regression dispatch-after-commit)
- ☐ Aucun appel `branch_id` extrait de `$request` ajouté
- ☐ JSON stocké `item_variations` conserve `quantity` field naturellement
- ☐ Suite régression PricingServiceTest 100% inchangée (zéro test modifié, tous verts)
- ☐ Backward-compat fixture POS V1 produit total_price bit-identique

---

## CLOSE

`reports/execution/RUN_V14_02_T05_PRICING_SSOT_MULTI_QTY_2026-04-20.md` :

```markdown
# RUN_V14_02_T05 — Final report
- task_id: V14_02_T05_PRICING_SSOT_MULTI_QTY
- status: CLOSED
- attempts: <N>
- artifacts: PricingService.php (boucle×2 + assertVariationConstraints), OrderService.php (3 sites), FrontendOrderService.php (1 site), PricingServiceMultiQtyTest.php (9 tests), PosOrderMultiQtyVariationTest.php (1 e2e)
- regression: PricingServiceTest 100% green unchanged (proof: phpunit --teamcity diff)
- invariants: 6/6 + dispatch-after-commit sentinel
- bit_identical_legacy: ✓ (legacy POS payload bit-identique total_price)
- next_dependent: T03 (POS UI multi-qty), T04 (Kiosk UI), T07 (snapshot)
```

Update `.cursor/ACTIVE_CYCLE.md`.
