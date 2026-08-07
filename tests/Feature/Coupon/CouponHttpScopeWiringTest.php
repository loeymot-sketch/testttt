<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * @FK-ID FK-CV6-COUPON-WIRE
 * @source docs/audit/INTEGRATION_AUDIT_GLOBAL_2026-05-06.md §8 risque P0
 *
 * Verifies that the new advanced promo scopes (status, days, hours, branch_scope,
 * surfaces, max_uses_global) are ENFORCED on the HTTP path
 * `POST /api/frontend/coupon/coupon-checking` — i.e. that the model's
 * `Coupon::isUsableNow()` is actually invoked from `CouponService::validateCouponForOrder()`.
 *
 * Without this wiring, all unit tests of `isUsableNow()` would pass while the runtime
 * silently accepts coupons that should be refused.
 */
class CouponHttpScopeWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * [TICKET PROMO 2026-08-07] Le commit `9b2f3a0a3` a introduit un interrupteur DÉDIÉ
     * `POS_COUPON_CODES_ENABLED`, éteint par défaut. Cette classe s'est alors mise à
     * tomber sur le coupe-circuit (« Les codes promo sont désactivés pour le moment. »)
     * AVANT d'atteindre la logique de portée qu'elle est justement là pour vérifier :
     * 6 tests rouges, sans que rien du câblage testé n'ait changé.
     *
     * On allume donc l'interrupteur ici. Ce n'est pas contourner une garde : le
     * coupe-circuit a SA propre suite (`CouponCodesDedicatedSwitchTest`, qui vérifie
     * les deux positions). Chaque test doit exercer ce qu'il prétend exercer.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.coupon_codes_enabled' => true]);
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => 'Test Coupon',
            'code' => 'TEST' . substr(bin2hex(random_bytes(3)), 0, 6),
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => Carbon::now()->subDays(1)->toDateTimeString(),
            'end_date' => Carbon::now()->addDays(7)->toDateTimeString(),
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'limit_per_user' => 0,
            'status' => Status::ACTIVE,
        ], $overrides));
    }

    public function test_inactive_coupon_is_refused_at_http_path(): void
    {
        $user = User::factory()->create();
        $coupon = $this->makeCoupon(['status' => Status::INACTIVE]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $coupon->code,
            'total' => 50,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => trans('all.message.coupon_not_applicable_now')]);
    }

    public function test_branch_scoped_coupon_refused_when_branch_id_does_not_match(): void
    {
        $user = User::factory()->create();
        $coupon = $this->makeCoupon(['branch_scope' => [42, 43]]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $coupon->code,
            'total' => 50,
            'branch_id' => 1, // not in scope
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => trans('all.message.coupon_not_applicable_now')]);
    }

    public function test_branch_scoped_coupon_accepted_when_branch_id_matches(): void
    {
        $user = User::factory()->create();
        $coupon = $this->makeCoupon(['branch_scope' => [42, 43]]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $coupon->code,
            'total' => 50,
            'branch_id' => 42, // in scope
        ]);

        $response->assertStatus(200);
    }

    public function test_surface_scoped_coupon_refused_when_surface_does_not_match(): void
    {
        $user = User::factory()->create();
        $coupon = $this->makeCoupon(['surfaces' => ['kiosk']]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $coupon->code,
            'total' => 50,
            'surface' => 'pos', // not in surfaces
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => trans('all.message.coupon_not_applicable_now')]);
    }

    public function test_surface_scoped_coupon_accepted_when_surface_matches(): void
    {
        $user = User::factory()->create();
        $coupon = $this->makeCoupon(['surfaces' => ['kiosk', 'pos']]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $coupon->code,
            'total' => 50,
            'surface' => 'pos',
        ]);

        $response->assertStatus(200);
    }

    public function test_legacy_call_without_branch_or_surface_still_works(): void
    {
        // Backward compat: when caller doesn't provide branch_id / surface,
        // isUsableNow should NOT block on those scopes (null treated as "no filter").
        $user = User::factory()->create();
        $coupon = $this->makeCoupon();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $coupon->code,
            'total' => 50,
        ]);

        $response->assertStatus(200);
    }

    public function test_invalid_surface_value_is_rejected_by_validation(): void
    {
        $user = User::factory()->create();
        $coupon = $this->makeCoupon();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/coupon/coupon-checking', [
            'code' => $coupon->code,
            'total' => 50,
            'surface' => 'INVALID_SURFACE',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['surface']);
    }
}
