<?php

namespace Tests\Feature\Coupon;

use App\Enums\Ask;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\OrderCoupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * @FK-ID FK-S3-COUPON-SURFACE-COMMIT
 * @source reports/goal-parite-sync-2026-07-18/REGISTRE_PARITE_SYNC.md finding S3
 *
 * Finding S3 claimed a surface BYPASS: that at commit resolveCouponById() is called
 * with only 3 args → surface=null → isUsableNow() "skips" the surface/branch filters,
 * making a surfaces=["kiosk"] coupon redeemable from ANY surface.
 *
 * VERIFIED REALITY (see class @note below): the model FAILS CLOSED, not open. In
 * Coupon::isUsableNow() a non-empty `surfaces` with $surface===null returns FALSE
 * (reject), so a restricted coupon is REFUSED everywhere at commit — including on its
 * own surface. There is NO bypass. The security property (mismatched-surface/branch
 * coupon is refused at commit) is asserted GREEN below in the default SSOT mode.
 *
 * The fix threads the REAL surface + branch of the order into resolveCouponById() at
 * every non-frozen commit site (FrontendOrderService + OrderService), so a restricted
 * coupon is ACCEPTED on a matching surface/branch and REJECTED on a mismatch — proven
 * GREEN on the legacy (non-SSOT) path here.
 *
 * @note The DEFAULT SSOT path validates the coupon FIRST inside the FROZEN
 * PricingService::calculateOrder() → DiscountCalculator::couponDiscount(), which still
 * passes only 3 args. Making accept-on-match work in SSOT mode requires threading
 * surface+branch through those two FROZEN files (human gate) — see the skipped test.
 */
class CouponSurfaceEnforcedAtCommitTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $webUser;
    private User $kioskUser;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config(['app.api_key' => '123456']);
        // Discretionary discounts ON so an ACCEPTED coupon (which yields a small
        // discount) does not trip the unrelated F1/VAT dormancy gate. The surface/
        // branch rejection under test happens BEFORE that gate anyway.
        config(['pos.manual_discount_enabled' => true]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'S3 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '20 rue remise',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'S3 Menus',
            'slug' => 's3-menus',
            'status' => Status::ACTIVE,
        ]);

        // No tax → keep totals simple and avoid VAT noise on the accepted paths.
        $this->item = Item::forceCreate([
            'name' => 'S3 Menu',
            'slug' => 's3-menu',
            'price' => 20.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        $this->webUser = User::forceCreate([
            'name' => 'S3 Web User',
            'email' => 's3-web@test.local',
            'username' => 's3_web',
            'phone' => '0600009910',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);

        // Kiosk-machine-backed user → FrontendOrderService derives source_surface='kiosk'.
        $this->kioskUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'username' => 's3_kiosk',
            'status' => Status::ACTIVE,
        ]);
        KioskMachine::create([
            'machine_id' => 'machine-s3',
            'branch_id' => $this->branch->id,
            'user_id' => $this->kioskUser->id,
            'username' => 'kiosk-s3',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::forceCreate(array_merge([
            'name' => 'S3 Coupon',
            'code' => 'S3' . substr(bin2hex(random_bytes(4)), 0, 8),
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 0,
            'maximum_discount' => 5,
            'limit_per_user' => 0,
            'status' => Status::ACTIVE,
        ], $overrides));
    }

    private function webPayload(int $couponId): array
    {
        return [
            'branch_id' => $this->branch->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 20.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => $couponId,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];
    }

    private function postWebOrder(int $couponId)
    {
        return $this
            ->actingAs($this->webUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->webPayload($couponId));
    }

    private function postKioskOrder(int $couponId, string $idempotencyKey)
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $payload = [
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => $couponId,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        $quote = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        return $this
            ->withHeader('x-api-key', '123456')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);
    }

    // ---------------------------------------------------------------------
    // SSOT (default) — SECURITY property: a mismatched-surface/branch coupon
    // is REFUSED at commit even when coupon_id is forced (no bypass).
    // ---------------------------------------------------------------------

    /**
     * (a) A coupon restricted to surfaces=["kiosk"] MUST be refused on a WEB order at
     * commit even when coupon_id is forced. Proves finding S3's "bypass" does not
     * exist: the commit path fails closed.
     */
    public function test_kiosk_surface_coupon_is_refused_on_web_order_at_commit(): void
    {
        $coupon = $this->makeCoupon(['surfaces' => ['kiosk']]);

        $response = $this->postWebOrder($coupon->id);

        $response->assertStatus(422);
        $this->assertSame(0, OrderCoupon::where('coupon_id', $coupon->id)->count(), 'Refused coupon must not persist a link.');
        $this->assertSame(0, FrontendOrder::count(), 'Refused surface coupon must roll the whole order back.');
    }

    /**
     * (d) A coupon whose branch_scope excludes the order's branch MUST be refused at
     * commit on a web order — forcing coupon_id does not bypass the branch scope.
     */
    public function test_off_branch_coupon_is_refused_on_web_order_at_commit(): void
    {
        $coupon = $this->makeCoupon(['branch_scope' => [$this->branch->id + 999]]);

        $response = $this->postWebOrder($coupon->id);

        $response->assertStatus(422);
        $this->assertSame(0, OrderCoupon::where('coupon_id', $coupon->id)->count(), 'Off-branch coupon must not persist a link.');
        $this->assertSame(0, FrontendOrder::count(), 'Off-branch coupon must roll the whole order back.');
    }

    /**
     * (c) An UNrestricted coupon (surfaces=null, branch_scope=null) MUST keep working
     * everywhere — web AND kiosk. Guards against over-rejection by the fix.
     */
    public function test_unrestricted_coupon_is_accepted_on_web_and_kiosk(): void
    {
        $this->postWebOrder($this->makeCoupon()->id)->assertStatus(201);

        $kioskResponse = $this->postKioskOrder($this->makeCoupon()->id, 's3-unrestricted-kiosk');
        $this->assertContains($kioskResponse->status(), [200, 201], 'Unrestricted coupon must be accepted on kiosk. Body: ' . $kioskResponse->getContent());
    }

    // ---------------------------------------------------------------------
    // Legacy (non-SSOT) path — proves the FIX threads the REAL surface + branch
    // into resolveCouponById() at the non-frozen commit sites: ACCEPT on match,
    // REJECT on mismatch. (RED before the fix — resolveCouponById got 3 args →
    // surface=null → a matching restricted coupon was wrongly refused.)
    // ---------------------------------------------------------------------

    public function test_legacy_path_matching_surface_coupon_is_accepted_on_web_order_at_commit(): void
    {
        config(['pricing.use_ssot_service' => false]);
        $coupon = $this->makeCoupon(['surfaces' => ['web']]);

        $response = $this->postWebOrder($coupon->id);

        $response->assertStatus(201);
        $this->assertSame(1, OrderCoupon::where('coupon_id', $coupon->id)->count(), 'Matching-surface coupon must be linked.');
    }

    public function test_legacy_path_mismatched_surface_coupon_is_refused_on_web_order_at_commit(): void
    {
        config(['pricing.use_ssot_service' => false]);
        $coupon = $this->makeCoupon(['surfaces' => ['kiosk']]);

        $response = $this->postWebOrder($coupon->id);

        $response->assertStatus(422);
        $this->assertSame(0, FrontendOrder::count());
    }

    public function test_legacy_path_on_branch_coupon_is_accepted_on_web_order_at_commit(): void
    {
        config(['pricing.use_ssot_service' => false]);
        $coupon = $this->makeCoupon(['branch_scope' => [$this->branch->id]]);

        $response = $this->postWebOrder($coupon->id);

        $response->assertStatus(201);
        $this->assertSame(1, OrderCoupon::where('coupon_id', $coupon->id)->count());
    }

    public function test_legacy_path_unrestricted_coupon_still_accepted_on_web_order_at_commit(): void
    {
        config(['pricing.use_ssot_service' => false]);
        $coupon = $this->makeCoupon(); // no surface, no branch restriction

        $response = $this->postWebOrder($coupon->id);

        $response->assertStatus(201);
        $this->assertSame(1, OrderCoupon::where('coupon_id', $coupon->id)->count());
    }

    // ---------------------------------------------------------------------
    // SSOT accept-on-match — PENDING a FROZEN-zone gate.
    // ---------------------------------------------------------------------

    /**
     * (b) The SAME surfaces=["kiosk"] coupon SHOULD be accepted on a real KIOSK order.
     *
     * In the DEFAULT SSOT mode this is BLOCKED by the FROZEN pricing chain: the coupon
     * is validated first by PricingService::calculateOrder()
     * → DiscountCalculator::couponDiscount(), which still calls resolveCouponById()
     * with only 3 args (surface=null) and therefore refuses the coupon BEFORE any
     * non-frozen commit site runs. Making this GREEN requires threading surface+branch
     * through those two FROZEN files (PricingService.php:~331 + DiscountCalculator.php:12)
     * — a human-gated change. Skipped until that gate lands.
     */
    public function test_kiosk_surface_coupon_accepted_on_kiosk_order_pending_frozen_pricing_gate(): void
    {
        $this->markTestSkipped(
            'BLOCKED by FROZEN PricingService/DiscountCalculator: in SSOT mode the coupon is '
            . 'validated via DiscountCalculator::couponDiscount() with surface=null (3 args), '
            . 'refusing a matching restricted coupon before non-frozen sites run. Requires a '
            . 'human-gated frozen change to thread surface+branch. See escalation.'
        );
    }
}
