<?php

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — LOY-SEM-02 heal]
 *
 * DiscountCalculator::kioskLoyaltyRedemption (kiosk/web redeem path) accepted a
 * FRACTIONAL-euro requested discount and charged ceil(discount*rate) points while
 * granting the raw fractional discount — so the customer was debited MORE points
 * than the euro value granted, and order.discount persisted a sub-cent value.
 * The "never fractional" guard existed only on the POS path (PosRedemptionService).
 *
 * Heal: snap the redeemed discount to WHOLE points — points = floor(discount*rate),
 * granted discount = points/rate rounded to 2 decimals. Points charged == euro
 * value granted, and the discount is always cent-clean.
 *
 * @group sentinel
 * @group loyalty
 */

namespace Tests\Feature\Loyalty;

use App\Models\User;
use App\Services\Pricing\DiscountCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskRedeemWholePointSnapSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 50,
        ]);
    }

    public function test_fractional_request_is_snapped_to_whole_points(): void
    {
        $user = User::factory()->create(['loyalty_points' => 1000]);
        $calc = new DiscountCalculator();

        // requested 1.005€ at 100 pts/€: must snap to 100 pts (floor) → 1.00€ granted,
        // NOT ceil(100.5)=101 pts for a 1.005€ sub-cent discount.
        $result = $calc->kioskLoyaltyRedemption(null, 'CODE123', 1.005, 50.0, $user);

        $this->assertSame(100, $result['points'], 'Points must be floor(discount*rate), not ceil.');
        $this->assertSame(1.00, $result['discount'], 'Granted discount must equal the whole-point value, cent-clean.');
        // discount must be exactly the euro value of the points charged
        $this->assertSame((float) $result['points'] / 100.0, $result['discount']);
    }

    public function test_clean_multiple_request_is_unchanged(): void
    {
        $user = User::factory()->create(['loyalty_points' => 1000]);
        $calc = new DiscountCalculator();

        $result = $calc->kioskLoyaltyRedemption(null, 'CODE123', 2.00, 50.0, $user);

        $this->assertSame(200, $result['points']);
        $this->assertSame(2.00, $result['discount']);
    }

    public function test_discount_never_exceeds_subtotal(): void
    {
        $user = User::factory()->create(['loyalty_points' => 100000]);
        $calc = new DiscountCalculator();

        // requested 80€ but subtotal only 12.50 → capped to 12 whole points-worth (12.50→1250 pts).
        $result = $calc->kioskLoyaltyRedemption(null, 'CODE123', 80.0, 12.50, $user);

        $this->assertLessThanOrEqual(12.50, $result['discount']);
        $this->assertSame((float) $result['points'] / 100.0, $result['discount']);
    }
}
