<?php

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Services\Delivery\DeliveryFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Sprint H3 DEL-5 2026-05-17] DeliveryFeeService::fromDistanceKm must
 * read branch-level configuration (delivery_fee_base / delivery_fee_per_km /
 * delivery_fee_minimum) when the optional Branch argument is provided AND
 * all three columns are set. NULL columns OR null branch fall back to the
 * legacy `max(5, ceil(d/5)*5)` formula. SaaS multi-tenant invariant.
 * CLAUDE.md §9.
 */
class DeliveryFeeConfigurableTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_no_branch_uses_legacy_formula(): void
    {
        $service = app(DeliveryFeeService::class);

        // distance=0 → max(5, 0) = 5
        $this->assertSame(5.0, $service->fromDistanceKm(0));
        // distance=3 → max(5, 5) = 5
        $this->assertSame(5.0, $service->fromDistanceKm(3));
        // distance=7 → max(5, 10) = 10
        $this->assertSame(10.0, $service->fromDistanceKm(7));
        // distance=12 → max(5, 15) = 15
        $this->assertSame(15.0, $service->fromDistanceKm(12));
    }

    /** @test */
    public function test_legacy_branch_with_null_settings_uses_legacy_formula(): void
    {
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => null,
            'delivery_fee_per_km'  => null,
            'delivery_fee_minimum' => null,
        ]);
        $service = app(DeliveryFeeService::class);

        $this->assertSame(5.0, $service->fromDistanceKm(0, $branch));
        $this->assertSame(5.0, $service->fromDistanceKm(3, $branch));
        $this->assertSame(10.0, $service->fromDistanceKm(7, $branch));
        $this->assertSame(15.0, $service->fromDistanceKm(12, $branch));
    }

    /** @test */
    public function test_configured_branch_uses_base_plus_per_km(): void
    {
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => 3.00,
            'delivery_fee_per_km'  => 1.50,
            'delivery_fee_minimum' => 3.00,
        ]);
        $service = app(DeliveryFeeService::class);

        // 3 + 1.5*0 = 3 → max(3, 3) = 3
        $this->assertSame(3.0, $service->fromDistanceKm(0, $branch));
        // 3 + 1.5*4 = 9 → max(3, 9) = 9
        $this->assertSame(9.0, $service->fromDistanceKm(4, $branch));
        // 3 + 1.5*10 = 18 → max(3, 18) = 18
        $this->assertSame(18.0, $service->fromDistanceKm(10, $branch));
    }

    /** @test */
    public function test_minimum_floor_enforced(): void
    {
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => 1.00,
            'delivery_fee_per_km'  => 0.50,
            'delivery_fee_minimum' => 8.00,
        ]);
        $service = app(DeliveryFeeService::class);

        // 1 + 0.5*0 = 1, clamped up to 8
        $this->assertSame(8.0, $service->fromDistanceKm(0, $branch));
        // 1 + 0.5*5 = 3.5, clamped up to 8
        $this->assertSame(8.0, $service->fromDistanceKm(5, $branch));
        // 1 + 0.5*20 = 11, no clamp
        $this->assertSame(11.0, $service->fromDistanceKm(20, $branch));
    }

    /** @test */
    public function test_partial_config_falls_back_to_legacy(): void
    {
        // Half-configured rows treated as legacy (all-or-nothing semantic)
        // to avoid corrupt-data billing surprises.
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => 3.00,
            'delivery_fee_per_km'  => null,
            'delivery_fee_minimum' => null,
        ]);
        $service = app(DeliveryFeeService::class);

        // Legacy: distance=7 → 10
        $this->assertSame(10.0, $service->fromDistanceKm(7, $branch));
    }

    /** @test */
    public function test_invalid_distance_returns_zero_regardless_of_branch(): void
    {
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => 3.00,
            'delivery_fee_per_km'  => 1.50,
            'delivery_fee_minimum' => 3.00,
        ]);
        $service = app(DeliveryFeeService::class);

        // Pre-existing behavior preserved: negative + non-numeric → 0
        $this->assertSame(0.0, $service->fromDistanceKm(-1, $branch));
        $this->assertSame(0.0, $service->fromDistanceKm('not-a-number', $branch));
        $this->assertSame(0.0, $service->fromDistanceKm(-1));
        $this->assertSame(0.0, $service->fromDistanceKm('not-a-number'));
    }
}
