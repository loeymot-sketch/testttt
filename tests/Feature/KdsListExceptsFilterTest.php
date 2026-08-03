<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ultrareview remediation — #3.
 * The KDS list `excepts` filter did `explode('|', $request)`; an array input
 * (?excepts[]=x) raised a TypeError (an \Error, NOT caught by catch(Exception))
 * → uncaught 500. The fix guards `is_string($request)` and skips empty tokens.
 */
class KdsListExceptsFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function chef(): User
    {
        $branch = Branch::factory()->create(['id' => 1]);
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARING,
        ]);

        return $chef;
    }

    /** Array input must NOT 500 (was a TypeError → uncaught 500). */
    public function test_excepts_array_input_does_not_500(): void
    {
        $chef = $this->chef();

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/kds-order?excepts[]=5')
            ->assertOk();
    }

    /** A normal pipe-joined string still filters (excludes the given order_types). */
    public function test_excepts_string_filters_normally(): void
    {
        $chef = $this->chef();

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/kds-order?excepts=5|2')
            ->assertOk();
    }

    /** Empty excepts is a clean no-op (no spurious where order_type != ''). */
    public function test_empty_excepts_is_noop(): void
    {
        $chef = $this->chef();

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/kds-order?excepts=')
            ->assertOk();
    }
}
