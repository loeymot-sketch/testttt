<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [PROMO-DASH-2026-05-06] CRUD admin /api/admin/coupon : create / list /
 * update / delete / toggle-status — avec gates Spatie.
 */
class CouponCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);
        return $admin;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'             => 'Promo cycle 6',
            'description'      => 'Test desc',
            'code'             => 'CY6PROMO',
            'discount'         => 5,
            'discount_type'    => DiscountType::FIXED,
            'start_date'       => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            'end_date'         => Carbon::now()->addMonth()->format('Y-m-d H:i:s'),
            'minimum_order'    => 10,
            'maximum_discount' => 50,
            'limit_per_user'   => 3,
            'valid_days_of_week' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'valid_hours_start' => '12:00',
            'valid_hours_end'   => '14:00',
            'branch_scope'      => [1, 2],
            'surfaces'          => ['pos', 'kiosk'],
            'max_uses_global'   => 1000,
            'status'            => Status::ACTIVE,
        ], $overrides);
    }

    public function test_admin_can_list_coupons(): void
    {
        $this->actingAsAdmin();

        Coupon::create([
            'name'           => 'Existing',
            'code'           => 'EXIST',
            'discount'       => 5,
            'discount_type'  => DiscountType::FIXED,
            'start_date'     => Carbon::now()->subDay(),
            'end_date'       => Carbon::now()->addMonth(),
            'minimum_order'  => 0,
            'maximum_discount' => 0,
            'status'         => Status::ACTIVE,
            'usage_count'    => 0,
        ]);

        $resp = $this->getJson('/api/admin/coupon');
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['code' => 'EXIST']);
    }

    public function test_admin_can_create_coupon_with_advanced_fields(): void
    {
        $this->actingAsAdmin();

        $resp = $this->postJson('/api/admin/coupon', $this->validPayload());
        $resp->assertStatus(201);
        $resp->assertJsonFragment(['code' => 'CY6PROMO']);

        $this->assertDatabaseHas('coupons', [
            'code'   => 'CY6PROMO',
            'status' => Status::ACTIVE,
        ]);

        $coupon = Coupon::where('code', 'CY6PROMO')->first();
        $this->assertNotNull($coupon);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], $coupon->valid_days_of_week);
        $this->assertSame([1, 2], $coupon->branch_scope);
        $this->assertSame(['pos', 'kiosk'], $coupon->surfaces);
        $this->assertSame(1000, (int) $coupon->max_uses_global);
        $this->assertSame('12:00:00', (string) $coupon->valid_hours_start);
        $this->assertSame('14:00:00', (string) $coupon->valid_hours_end);
    }

    public function test_create_rejects_without_create_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 0]);
        // assign POS Operator (no coupons_create)
        $user->assignRole('POS Operator');
        Sanctum::actingAs($user, ['*']);

        $resp = $this->postJson('/api/admin/coupon', $this->validPayload());
        // 403 when permission middleware blocks; spatie returns 403
        $this->assertContains($resp->status(), [401, 403]);
    }

    public function test_admin_can_update_coupon(): void
    {
        $this->actingAsAdmin();

        $coupon = Coupon::create([
            'name'           => 'Initial',
            'code'           => 'UPDME',
            'discount'       => 5,
            'discount_type'  => DiscountType::FIXED,
            'start_date'     => Carbon::now()->subDay(),
            'end_date'       => Carbon::now()->addMonth(),
            'minimum_order'  => 10,
            'maximum_discount' => 0,
            'status'         => Status::ACTIVE,
            'usage_count'    => 0,
        ]);

        $resp = $this->postJson('/api/admin/coupon/' . $coupon->id, $this->validPayload([
            'code'           => 'UPDME',
            'name'           => 'Updated name',
            'discount'       => 7,
            'minimum_order'  => 100,
            'surfaces'       => ['web'],
        ]));
        $resp->assertStatus(200);
        $coupon->refresh();
        $this->assertSame('Updated name', $coupon->name);
        $this->assertSame(['web'], $coupon->surfaces);
    }

    public function test_admin_can_delete_coupon(): void
    {
        $this->actingAsAdmin();

        $coupon = Coupon::create([
            'name'           => 'ToDelete',
            'code'           => 'DELME',
            'discount'       => 5,
            'discount_type'  => DiscountType::FIXED,
            'start_date'     => Carbon::now()->subDay(),
            'end_date'       => Carbon::now()->addMonth(),
            'minimum_order'  => 0,
            'maximum_discount' => 0,
            'status'         => Status::ACTIVE,
            'usage_count'    => 0,
        ]);

        $resp = $this->delete('/api/admin/coupon/' . $coupon->id);
        $resp->assertStatus(202);
        $this->assertNull(Coupon::find($coupon->id));
    }

    public function test_admin_can_toggle_status(): void
    {
        $this->actingAsAdmin();

        $coupon = Coupon::create([
            'name'           => 'ToggleMe',
            'code'           => 'TOGGLE',
            'discount'       => 5,
            'discount_type'  => DiscountType::FIXED,
            'start_date'     => Carbon::now()->subDay(),
            'end_date'       => Carbon::now()->addMonth(),
            'minimum_order'  => 0,
            'maximum_discount' => 0,
            'status'         => Status::ACTIVE,
            'usage_count'    => 0,
        ]);

        $resp = $this->postJson('/api/admin/coupon/toggle-status/' . $coupon->id);
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['status' => Status::INACTIVE]);

        $resp2 = $this->postJson('/api/admin/coupon/toggle-status/' . $coupon->id);
        $resp2->assertStatus(200);
        $resp2->assertJsonFragment(['status' => Status::ACTIVE]);
    }
}
