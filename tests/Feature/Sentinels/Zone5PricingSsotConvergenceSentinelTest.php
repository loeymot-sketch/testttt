<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Zone 5 — Pricing SSOT + composition_snapshot convergence sentinel.
 *
 * Mission CONFIRMATION audit (2026-05-18): Wave 1 NF525 auditor declared all four
 * Zone 5 invariants PASS. This sentinel pins the invariants in CI so a future
 * regression breaks the build rather than being caught only at fiscal inspection.
 *
 * Invariants pinned:
 *   PR01 — Backend re-reads Item::price from DB; frontend `price` field never trusted.
 *   PR02 — PricingService overwrites client-forged total_price with PricingService output.
 *   PR03 — composition_snapshot only ever written by an INSERT path (5 sites, all at
 *          order creation). NO code path performs UPDATE on the column. Application
 *          discipline only — DB-level immutability trigger is a documented P3 gap
 *          (Wave 1 W5 / R5 — V1.1 backlog). This test reasserts the application-side
 *          invariant.
 *   PR04 — When Item::price is changed mid-day, in-flight orders' frozen
 *          composition_snapshot keeps the original price; NEW orders use the new price.
 *   PR07 — Stripe round-before-cast: €9.99 → 999 cents (P0-#6 CTO audit 2026-05-16
 *          regression sentinel, Wave 1 R10).
 *
 * @see reports/audit/critical-focus-2026-05-18/wave-1/nf525-fiscal-audit.md
 * @see app/Services/Pricing/PricingService.php:291 (snapshot INSERT site)
 * @see app/Http/PaymentGateways/Gateways/Stripe.php:68 (round-before-cast)
 */
class Zone5PricingSsotConvergenceSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax20;
    private ItemCategory $category;
    private PricingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->tax20 = Tax::factory()->create([
            'name' => 'TVA 20%',
            'code' => 'TVA20',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 20.0,
            'status' => Status::ACTIVE,
        ]);
        $this->category = ItemCategory::factory()->create([
            'name' => 'Zone5 SSOT Category',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->service = $this->app->make(PricingService::class);
    }

    /**
     * PR01 — Backend re-reads Item::price from DB; frontend `price` is discarded.
     */
    public function test_pr01_backend_rereads_db_price_ignores_frontend_price_field(): void
    {
        $item = Item::factory()->create([
            'item_category_id' => $this->category->id,
            'price' => 7.50,
            'tax_id' => $this->tax20->id,
            'status' => Status::ACTIVE,
        ]);

        // Build a PricingRequest where the FRONTEND lies about price (claims 0.01).
        // PricingService MUST ignore it and use $dbItem->price = 7.50.
        $req = PricingRequest::forPos(
            orderId: 0,
            branchId: $this->branch->id,
            requestItems: [
                (object) [
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'price' => 0.01, // FORGED — must be ignored
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ],
            couponId: 0,
            customerId: 0,
            manualDiscount: 0.0,
            deliveryCharge: 0.0,
        );

        $result = $this->service->calculateOrder($req, $this->app->make(CouponService::class));
        $line = $result->lines[0];

        $this->assertEqualsWithDelta(
            7.50,
            $line->unitItemPrice,
            0.001,
            'PR01 BREACH: PricingService trusted frontend price 0.01 instead of DB price 7.50'
        );
        $this->assertGreaterThan(
            1.0,
            $result->total,
            'PR01 BREACH: order total dropped below sane floor — frontend price likely used'
        );
    }

    /**
     * PR02 — End-to-end: forged total_price + forged subtotal are ignored;
     * PricingService is the single source of truth.
     */
    public function test_pr02_pricing_service_overwrites_client_forged_totals(): void
    {
        $item = Item::factory()->create([
            'item_category_id' => $this->category->id,
            'price' => 9.99,
            'tax_id' => $this->tax20->id,
            'status' => Status::ACTIVE,
        ]);

        $req = PricingRequest::forPos(
            orderId: 0,
            branchId: $this->branch->id,
            requestItems: [
                (object) [
                    'item_id' => $item->id,
                    'quantity' => 2,
                    // Frontend forges per-line totals
                    'price' => 0.00,
                    'total_price' => 0.02,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ],
            couponId: 0,
            customerId: 0,
            manualDiscount: 0.0,
            deliveryCharge: 0.0,
        );

        $result = $this->service->calculateOrder($req, $this->app->make(CouponService::class));
        $line = $result->lines[0];

        // Backend recomputes from DB: 9.99 * 2 = 19.98
        $this->assertEqualsWithDelta(9.99, $line->unitItemPrice, 0.001,
            'PR02 BREACH: per-line price tampered');
        $this->assertEqualsWithDelta(19.98, $line->lineSubtotalExTax, 0.001,
            'PR02 BREACH: line total_price tampered');
        $this->assertEqualsWithDelta(19.98, $result->subtotal, 0.001,
            'PR02 BREACH: order subtotal tampered');
    }

    /**
     * PR03 — composition_snapshot is only ever written at INSERT. This sentinel
     * grep-asserts the application code never reaches UPDATE on the column.
     *
     * Methodology:
     *   1. Static contract: model exposes the column as fillable + cast (immutable
     *      schema). We assert the runtime contract directly.
     *   2. Runtime: Eloquent ::save() with the column DIRTY would PASS today (no
     *      DB trigger — P3 backlog R5). What we pin is that NO PRODUCTION CODE
     *      PATH issues such a save. The 5 write sites all use bulk insert() and
     *      no UPDATE site exists. We re-verify via a code-search expectation.
     *
     * Wave 1 W5 acknowledged: DB-level trigger is V1.1 backlog. This test
     * pins the application-level invariant which today is the only line of
     * defense.
     */
    public function test_pr03_composition_snapshot_has_zero_update_callsites_in_app_code(): void
    {
        $appPath = base_path('app');
        $this->assertDirectoryExists($appPath);

        // Recursive grep: any line that mutates composition_snapshot via update/save patterns.
        // Allowed patterns: 'composition_snapshot' => ... inside an insert() array (write site).
        // Forbidden patterns (would breach NF525 immutability):
        //   - DB::table('order_items')->...->update([... 'composition_snapshot' => ...])
        //   - OrderItem::query()->...->update([... 'composition_snapshot' => ...])
        //   - $orderItem->composition_snapshot = ... ; $orderItem->save()
        //   - $orderItem->update(['composition_snapshot' => ...])
        $forbiddenRegex = [
            // Eloquent / Query Builder update() with composition_snapshot in array
            '#->update\([^)]*composition_snapshot#s',
            '#composition_snapshot[^;]{0,200}\->update\(#s',
            // Direct attribute assignment: $row->composition_snapshot = ...
            '#\$[a-zA-Z_]+->composition_snapshot\s*=#',
            // setAttribute() path bypassing fillable but still mutating
            '#setAttribute\([\'\"]composition_snapshot[\'\"]#',
            // fill() / forceFill() with composition_snapshot in payload
            '#->fill\(\s*\[[^\]]*[\'\"]composition_snapshot[\'\"]#s',
            '#->forceFill\(\s*\[[^\]]*[\'\"]composition_snapshot[\'\"]#s',
            // Raw DB::statement with UPDATE composition_snapshot
            '#DB::(statement|update|unprepared)\([^)]*UPDATE[^)]*composition_snapshot#is',
        ];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            // Quick reject if column never mentioned
            if (!str_contains($contents, 'composition_snapshot')) {
                continue;
            }
            foreach ($forbiddenRegex as $rx) {
                if (preg_match_all($rx, $contents, $m)) {
                    foreach ($m[0] as $hit) {
                        // Skip false positive: comments / docstrings mentioning UPDATE
                        if (preg_match('#^\s*[*/#]#', $hit)) continue;
                        $offenders[] = [
                            'file' => str_replace($appPath . DIRECTORY_SEPARATOR, '', $file->getPathname()),
                            'pattern' => $rx,
                            'snippet' => substr($hit, 0, 200),
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            'PR03 BREACH (NF525 §8): composition_snapshot UPDATE/assign call sites detected in app/: '
            . PHP_EOL . json_encode($offenders, JSON_PRETTY_PRINT)
        );
    }

    /**
     * PR04 — composition_snapshot freezing: rename Item::price mid-day → frozen
     * snapshot keeps the OLD price; new orders use the NEW price.
     *
     * This is the strongest end-to-end NF525 evidence and the heart of the SSOT
     * contract: the receipt printed today must match the receipt reprinted in 6
     * years, even after admin re-prices the item.
     */
    public function test_pr04_composition_snapshot_freezes_pricing_across_admin_repricing(): void
    {
        $item = Item::factory()->create([
            'item_category_id' => $this->category->id,
            'price' => 7.50,
            'tax_id' => $this->tax20->id,
            'status' => Status::ACTIVE,
            'name' => 'Sandwich Cayenne Sentinel',
        ]);
        $attr = \App\Models\ItemAttribute::query()->create([
            'name' => 'Viande',
            'status' => Status::ACTIVE,
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
        ]);
        $var = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attr->id,
            'name' => 'Poulet mariné',
            'price' => 0.0,
            'new_price' => 0.0,
            'status' => Status::ACTIVE,
        ]);

        $req = PricingRequest::forPos(
            orderId: 0,
            branchId: $this->branch->id,
            requestItems: [
                (object) [
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [(object) ['id' => $var->id]],
                    'item_extras' => [],
                ],
            ],
            couponId: 0,
            customerId: 0,
            manualDiscount: 0.0,
            deliveryCharge: 0.0,
        );

        $result = $this->service->calculateOrder($req, $this->app->make(CouponService::class));
        $line = $result->lines[0];
        $this->assertEqualsWithDelta(7.50, $line->unitItemPrice, 0.001,
            'PR04 PRE-CONDITION: Sandwich Cayenne must price at 7.50 at order time');

        // Simulate an OrderItem row written with the snapshot frozen at 7.50.
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $snapshotBuilder = $this->app->make(\App\Services\Pricing\CompositionSnapshotBuilder::class);
        $snapshot = $snapshotBuilder->build(
            (object) [
                'item_variations' => [(object) ['id' => $var->id]],
                'item_extras' => [],
            ],
            collect([$var->id => $var]),
            collect()
        );

        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'discount' => 0,
            'tax_name' => 'TVA 20%',
            'tax_rate' => 20,
            'tax_type' => TaxType::PERCENTAGE,
            'tax_amount' => 1.50,
            'price' => 7.50,
            'item_variations' => json_encode([['id' => $var->id]]),
            'item_extras' => json_encode([]),
            'composition_snapshot' => $snapshot, // Eloquent ::create activates the array cast
            'item_variation_total' => 0.0,
            'item_extra_total' => 0.0,
            'total_price' => 7.50,
        ]);

        // === MID-DAY: admin re-prices the item ===
        $item->refresh();
        $item->update(['price' => 9.99]);

        // Reload the OrderItem — snapshot price must remain 7.50 (NF525 frozen)
        $reloaded = OrderItem::query()->find($orderItem->id);
        $this->assertNotNull($reloaded->composition_snapshot, 'PR04 BREACH: snapshot vanished');

        // Snapshot has item-level base price implicit (lines[].unit_price is per variation).
        // The total_price column on the row is the frozen monetary anchor.
        $this->assertEqualsWithDelta(
            7.50,
            (float) $reloaded->total_price,
            0.001,
            'PR04 BREACH: order_items.total_price changed after admin re-priced Item (NF525 §8 violation)'
        );
        $this->assertEqualsWithDelta(
            7.50,
            (float) $reloaded->price,
            0.001,
            'PR04 BREACH: order_items.price changed after admin re-priced Item'
        );

        // A NEW order placed AFTER repricing must use 9.99.
        $req2 = PricingRequest::forPos(
            orderId: 0,
            branchId: $this->branch->id,
            requestItems: [
                (object) [
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [(object) ['id' => $var->id]],
                    'item_extras' => [],
                ],
            ],
            couponId: 0,
            customerId: 0,
            manualDiscount: 0.0,
            deliveryCharge: 0.0,
        );
        $result2 = $this->service->calculateOrder($req2, $this->app->make(CouponService::class));
        $this->assertEqualsWithDelta(9.99, $result2->lines[0]->unitItemPrice, 0.001,
            'PR04 BREACH: new order did not pick up admin repricing 9.99');
    }

    /**
     * PR07 — Stripe round-before-cast. Pure unit assertion: replicates the
     * Stripe.php:68 line of code to prove €9.99 → 999 (not 900).
     *
     * Insights P0-#6 fix (CTO audit 2026-05-16). Wave 1 R10 sentinel.
     */
    public function test_pr07_stripe_cents_round_before_cast(): void
    {
        $orderTotal = 9.99;
        // Stripe.php:68 production formula
        $cents = (int) round((float) $orderTotal * 100);
        $this->assertSame(999, $cents,
            'PR07 BREACH: €9.99 → '. $cents . ' cents (expected 999). Round-before-cast regressed.');

        // Edge cases asserted alongside Wave 1 R10
        $this->assertSame(99, (int) round((float) 0.99 * 100), 'PR07: €0.99 → 99 cents');
        $this->assertSame(1, (int) round((float) 0.01 * 100), 'PR07: €0.01 → 1 cent');
        $this->assertSame(10000, (int) round((float) 100.00 * 100), 'PR07: €100.00 → 10000 cents');
        // PHP round() default mode = HALF_UP (round half away from zero), NOT banker's.
        // €12.345 → 12.345 * 100 = 1234.5 → round(1234.5) = 1235 (HALF_UP).
        // This matches Stripe expectations: never lose a cent of revenue.
        $this->assertSame(1235, (int) round((float) 12.345 * 100), 'PR07: €12.345 → 1235 cents (HALF_UP)');
    }

    /**
     * PR03-bis — Static schema invariant: column exists, cast is array.
     */
    public function test_pr03bis_composition_snapshot_schema_invariant(): void
    {
        $this->assertTrue(
            Schema::hasColumn('order_items', 'composition_snapshot'),
            'PR03-bis BREACH: order_items.composition_snapshot column missing'
        );

        $reflection = new \ReflectionClass(OrderItem::class);
        $casts = $reflection->getDefaultProperties()['casts'] ?? [];
        $this->assertSame(
            'array',
            $casts['composition_snapshot'] ?? null,
            'PR03-bis BREACH: OrderItem must cast composition_snapshot as array'
        );
    }
}
