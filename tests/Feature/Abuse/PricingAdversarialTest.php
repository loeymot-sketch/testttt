<?php

namespace Tests\Feature\Abuse;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VECTOR pricing_adversarial — abuse of PricingService::calculateOrder().
 *
 * Each test posts an adversarial payload directly to the SSOT service and
 * asserts the backend NEVER trusts the client: it recomputes from DB prices,
 * rejects (422 / InvalidArgumentException) impossible inputs, and NEVER
 * produces a negative discount or negative total.
 *
 * These are logic-isolatable (no FOR UPDATE row lock involved) so they run
 * deterministically under SQLite :memory:. True lock-races are deferred to
 * RECIPES against the shared :8766 MySQL DB.
 *
 * @see app/Services/Pricing/PricingService.php
 * @see app/Services/Pricing/DiscountCalculator.php
 * @see app/Services/CouponService.php
 */
class PricingAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax20;
    private ItemCategory $category;
    private PricingService $service;
    private CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pricing.tax_inclusive_prices' => false]);

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->tax20 = Tax::factory()->create([
            'name' => 'TVA 20%',
            'code' => 'TVA20-ABUSE',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 20.0,
            'status' => Status::ACTIVE,
        ]);
        $this->category = ItemCategory::factory()->create([
            'name' => 'Abuse Category',
            'wizard_template' => 'simple',
            'has_menu' => false,
        ]);

        $this->service = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    /* ============================================================
     * A. Client-sent price/total fields are IGNORED (SSOT recompute)
     * ============================================================ */

    public function test_client_forged_line_price_is_ignored_server_recomputes_from_db(): void
    {
        $item = $this->makeItem(10.00);

        // Adversary smuggles price=0.01 and total_price=0.01 on the line.
        $line = (object) [
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 0.01,           // forged
            'total_price' => 0.01,     // forged
            'item_variations' => [],
            'item_extras' => [],
            'instruction' => null,
        ];

        $req = PricingRequest::forKiosk(1, $this->branch->id, [$line], 0, 0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // Server uses DB price 10.00, NOT the forged 0.01.
        $this->assertSame(10.00, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(10.00, $out->orderItemInsertRows[0]['price']);
        $this->assertSame(10.00, $out->orderItemInsertRows[0]['total_price']);
        $this->assertSame(12.00, $out->total); // 10 + 20% tax
    }

    /* ============================================================
     * B. Quantity abuse
     * ============================================================ */

    public function test_negative_quantity_clamps_to_one_never_negative_line(): void
    {
        $item = $this->makeItem(8.00);

        $line = (object) [
            'item_id' => $item->id,
            'quantity' => -5,            // adversarial negative
            'item_variations' => [],
            'item_extras' => [],
            'instruction' => null,
        ];

        $req = PricingRequest::forKiosk(1, $this->branch->id, [$line], 0, 0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        $this->assertSame(1, $out->lines[0]->quantity);
        $this->assertGreaterThanOrEqual(0.0, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(8.00, $out->lines[0]->lineSubtotalExTax);
        $this->assertGreaterThanOrEqual(0.0, $out->total);
    }

    public function test_huge_quantity_does_not_produce_negative_or_nan_total(): void
    {
        $item = $this->makeItem(5.00);

        $line = (object) [
            'item_id' => $item->id,
            'quantity' => 1000000,       // 1M units
            'item_variations' => [],
            'item_extras' => [],
            'instruction' => null,
        ];

        $req = PricingRequest::forKiosk(1, $this->branch->id, [$line], 0, 0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // No overflow to negative / NaN: 5 * 1,000,000 = 5,000,000.
        $this->assertSame(1000000, $out->lines[0]->quantity);
        $this->assertSame(5000000.0, $out->lines[0]->lineSubtotalExTax);
        $this->assertGreaterThanOrEqual(0.0, $out->total);
        $this->assertTrue(is_finite($out->total));
    }

    /* ============================================================
     * C. Non-existent / soft-deleted item rejected (422)
     * ============================================================ */

    public function test_nonexistent_item_id_rejected(): void
    {
        $line = (object) [
            'item_id' => 999999,
            'quantity' => 1,
            'item_variations' => [],
            'item_extras' => [],
            'instruction' => null,
        ];

        $req = PricingRequest::forKiosk(1, $this->branch->id, [$line], 0, 0, 0.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_soft_deleted_item_rejected(): void
    {
        $item = $this->makeItem(10.00);
        $item->delete(); // soft delete

        $line = (object) [
            'item_id' => $item->id,
            'quantity' => 1,
            'item_variations' => [],
            'item_extras' => [],
            'instruction' => null,
        ];

        $req = PricingRequest::forKiosk(1, $this->branch->id, [$line], 0, 0, 0.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service->calculateOrder($req, $this->couponService);
    }

    /* ============================================================
     * D. Coupon abuse
     * ============================================================ */

    public function test_expired_coupon_rejected(): void
    {
        $item = $this->makeItem(50.00);
        $coupon = Coupon::factory()->create([
            'code' => 'EXPIRED-ABUSE',
            'discount' => 50.0,
            'discount_type' => DiscountType::PERCENTAGE,
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'start_date' => now()->subDays(30),
            'end_date' => now()->subDays(1), // expired yesterday
        ]);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], $coupon->id, 0, 0.0, 0.0);

        // Coupon validation throws \Exception(422) — must NOT silently apply.
        $this->expectException(\Exception::class);
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_coupon_below_minimum_order_rejected(): void
    {
        $item = $this->makeItem(10.00);
        $coupon = Coupon::factory()->create([
            'code' => 'MIN100-ABUSE',
            'discount' => 50.0,
            'discount_type' => DiscountType::PERCENTAGE,
            'minimum_order' => 100, // subtotal 10 < 100
            'maximum_discount' => 0,
            'start_date' => null,
            'end_date' => null,
        ]);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], $coupon->id, 0, 0.0, 0.0);

        $this->expectException(\Exception::class);
        $this->service->calculateOrder($req, $this->couponService);
    }

    public function test_coupon_exceeding_subtotal_clamps_to_subtotal_never_negative(): void
    {
        // Fixed-amount coupon worth 9999 on a 10.00 subtotal.
        $item = $this->makeItem(10.00);
        $coupon = Coupon::factory()->create([
            'code' => 'HUGE-FIXED-ABUSE',
            'discount' => 9999.0,
            'discount_type' => DiscountType::FIXED,
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'start_date' => null,
            'end_date' => null,
        ]);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], $coupon->id, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // Discount clamped to subtotal (10.00), NOT 9999. Total never negative.
        $this->assertLessThanOrEqual(10.00, $out->discount);
        $this->assertGreaterThanOrEqual(0.0, $out->discount);
        $this->assertGreaterThanOrEqual(0.0, $out->total);
    }

    public function test_negative_coupon_discount_never_yields_negative_discount(): void
    {
        // Adversarial / corrupt coupon row with a negative discount value.
        $item = $this->makeItem(20.00);
        $coupon = Coupon::factory()->create([
            'code' => 'NEG-ABUSE',
            'discount' => -50.0,         // negative
            'discount_type' => DiscountType::FIXED,
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'start_date' => null,
            'end_date' => null,
        ]);

        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], $coupon->id, 0, 0.0, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // A negative discount would INCREASE the total (overcharge) — must be clamped to >= 0.
        $this->assertGreaterThanOrEqual(0.0, $out->discount);
        // Total = subtotal(20) + tax(4) - discount(0) = 24, never more than the honest 24.
        $this->assertLessThanOrEqual(24.00, $out->total);
    }

    /* ============================================================
     * E. Coupon + manual discount cannot STACK (no double discount)
     * ============================================================ */

    public function test_coupon_and_manual_discount_do_not_stack(): void
    {
        $item = $this->makeItem(100.00);
        $coupon = Coupon::factory()->create([
            'code' => 'STACK-ABUSE',
            'discount' => 10.0,
            'discount_type' => DiscountType::PERCENTAGE,
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'start_date' => null,
            'end_date' => null,
        ]);

        // Adversary sends BOTH a coupon AND a 50.00 manual discount.
        $req = PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, 1)], $coupon->id, 0, 50.00, 0.0);
        $out = $this->service->calculateOrder($req, $this->couponService);

        // Only the coupon (10% of 100 = 10.00) applies. Manual 50 ignored. NOT 60 stacked.
        $this->assertSame(10.00, $out->discount);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function makeItem(float $price): Item
    {
        return Item::factory()->create([
            'item_category_id' => $this->category->id,
            'tax_id' => $this->tax20->id,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    private function line(int $itemId, int $quantity): object
    {
        return (object) [
            'item_id' => $itemId,
            'quantity' => $quantity,
            'item_variations' => [],
            'item_extras' => [],
            'instruction' => null,
        ];
    }
}
