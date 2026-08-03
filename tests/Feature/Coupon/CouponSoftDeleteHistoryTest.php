<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderCoupon;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [P3 heal 2026-07-07] Coupon hard-delete → coupon_id orphelin dans order_coupons.
 *
 * Le modèle Coupon utilise désormais SoftDeletes : supprimer un coupon référencé
 * par l'historique (order_coupons, sans FK) le masque des listes / de l'ordering
 * mais garde la référence résolvable (withTrashed) — invariant NF525 : une
 * commande passée ne perd jamais son coupon.
 */
class CouponSoftDeleteHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);
    }

    private function makeCoupon(string $code = 'HISTO'): Coupon
    {
        return Coupon::create([
            'name'             => 'Histo ' . $code,
            'code'             => $code,
            'discount'         => 5,
            'discount_type'    => DiscountType::FIXED,
            'start_date'       => Carbon::now()->subDay(),
            'end_date'         => Carbon::now()->addMonth(),
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'status'           => Status::ACTIVE,
            'usage_count'      => 0,
        ]);
    }

    public function test_deleting_referenced_coupon_soft_deletes_and_keeps_history_resolvable(): void
    {
        $this->actingAsAdmin();

        $coupon = $this->makeCoupon('HISTO');
        $order = Order::factory()->create();
        $orderCoupon = OrderCoupon::create([
            'order_id'  => $order->id,
            'coupon_id' => $coupon->id,
            'user_id'   => $order->user_id,
            'discount'  => 5,
        ]);

        $resp = $this->delete('/api/admin/coupon/' . $coupon->id);
        $resp->assertStatus(202);

        // Soft-delete : disparu des requêtes normales, mais la ligne existe encore.
        $this->assertNull(Coupon::find($coupon->id));
        $trashed = Coupon::withTrashed()->find($coupon->id);
        $this->assertNotNull($trashed);
        $this->assertTrue($trashed->trashed());

        // L'historique n'est PAS orphelin : coupon_id reste résolvable.
        $orderCoupon->refresh();
        $this->assertSame($coupon->id, (int) $orderCoupon->coupon_id);
        $resolved = Coupon::withTrashed()->find($orderCoupon->coupon_id);
        $this->assertNotNull($resolved, 'order_coupons.coupon_id doit rester résolvable après suppression');
        $this->assertSame('HISTO', $resolved->code);
    }

    public function test_deleted_coupon_hidden_from_admin_list(): void
    {
        $this->actingAsAdmin();

        $coupon = $this->makeCoupon('HIDDEN');
        $this->delete('/api/admin/coupon/' . $coupon->id)->assertStatus(202);

        $resp = $this->getJson('/api/admin/coupon');
        $resp->assertStatus(200);
        $resp->assertJsonMissing(['code' => 'HIDDEN']);
    }

    public function test_can_recreate_coupon_with_same_code_after_delete(): void
    {
        $this->actingAsAdmin();

        $coupon = $this->makeCoupon('REUSE');
        $this->delete('/api/admin/coupon/' . $coupon->id)->assertStatus(202);

        $resp = $this->postJson('/api/admin/coupon', [
            'name'             => 'Reuse fresh',
            'description'      => 'x',
            'code'             => 'REUSE',
            'discount'         => 5,
            'discount_type'    => DiscountType::FIXED,
            'start_date'       => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            'end_date'         => Carbon::now()->addMonth()->format('Y-m-d H:i:s'),
            'minimum_order'    => 10,
            'maximum_discount' => 50,
            'limit_per_user'   => 3,
            'status'           => Status::ACTIVE,
        ]);

        $resp->assertStatus(201);
        // Un nouveau coupon vivant existe, distinct de l'ancien soft-deleted.
        $this->assertSame(1, Coupon::where('code', 'REUSE')->count());
    }
}
