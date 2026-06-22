<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty\PosRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [H.5 EDGE-3 P1 2026-05-24] Phase H2-HEAL-04 sentinel.
 *
 * Catches the TTC-mode tax double-count bug in
 * PosRedemptionService::applyToOrder. Before the fix, the formula was
 *
 *   $newTotal = $subtotal - $newDiscount + $currentTax + $currentDelivery;
 *
 * which is correct in HT mode (where `subtotal` is HT and tax is SEPARATE)
 * but DOUBLE-COUNTS tax in TTC mode (V1 default per config/pricing.php) —
 * in TTC mode the subtotal already contains tax inside.
 *
 * Concrete repro (TTC, 10% VAT extracted):
 *   subtotal = 25.00  (TTC, already includes 2.27€ VAT)
 *   total_tax = 2.27  (VAT portion EXTRACTED from TTC)
 *   discount = 1.00 redeemed
 *   delivery = 0
 *
 *   Buggy: 25 - 1 + 2.27 + 0 = 26.27   ← OVERCHARGE (customer pays MORE
 *                                          after using loyalty points)
 *   Correct: 25 - 1 + 0 = 24.00         (TTC: total = subtotal − discount)
 *
 * Coverage:
 *   1. TTC + partial redeem  → total = subtotal − discount (no tax added)
 *   2. TTC + 100% redeem     → total clamps to 0 (free order)
 *   3. HT + partial redeem   → total = subtotal − discount + tax (HT keeps adding)
 *
 * Mirror baseline: PricingService::computePricing lines 350-354 branch
 * on `pricing.tax_inclusive_prices` for the EXACT same reason. The POS
 * post-create recompute MUST mirror that branch.
 */
class PosRedemptionTtcTaxDoubleCountSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Default redemption rate: 100 pts = 1€.
        DB::table('settings')->insert([
            'key'        => 'loyalty_points_for_1_euro_discount',
            'payload'    => json_encode(100),
            'group'      => 'loyalty_setup',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));
        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Redeem is gated OFF in V1;
        // this sentinel tests redeem TTC tax math (preserved code) → enable it.
        Config::set('pos.manual_discount_enabled', true);

        $this->branch = Branch::factory()->create();

        $this->cashier = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030407',
        ]);

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030408',
        ]);
        DB::table('users')->where('id', $this->customer->id)->update([
            'loyalty_code'   => 'SENTCUST',
            'loyalty_points' => 10000,
        ]);
        $this->customer->refresh();
    }

    private function makeOrder(float $subtotal, float $tax, float $delivery = 0.0): Order
    {
        $total = $subtotal + $delivery; // TTC convention (or HT before tax)
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'user_id'            => $this->customer->id,
            'subtotal'           => $subtotal,
            'discount'           => 0.00,
            'total_tax'          => $tax,
            'delivery_charge'    => $delivery,
            'total'              => $total,
            'status'             => OrderStatus::PENDING,
            'payment_status'     => PaymentStatus::UNPAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
        ]);
    }

    /**
     * Path 1 — TTC + partial redeem: tax must NOT be re-added.
     *
     * subtotal=25 (TTC, tax=2.27 INSIDE), redeem 100pts=1€
     * → total must equal 24 (25 − 1), NOT 26.27 (would mean tax was re-added).
     */
    public function test_ttc_partial_redeem_does_not_double_count_tax(): void
    {
        Config::set('pricing.tax_inclusive_prices', true);

        $order = $this->makeOrder(subtotal: 25.00, tax: 2.27);

        /** @var PosRedemptionService $svc */
        $svc = app(PosRedemptionService::class);
        $svc->applyToOrder($order, 100, 'SENTCUST', $this->cashier->id);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);

        $this->assertEqualsWithDelta(
            1.00,
            (float) $fresh->discount,
            0.01,
            'Discount must equal 1.00€ for 100 pts redeemed at rate 100'
        );
        $this->assertEqualsWithDelta(
            24.00,
            (float) $fresh->total,
            0.01,
            'TTC mode: total = subtotal − discount = 25 − 1 = 24. '
            . 'If total=26.27 the tax was double-counted (H.5 EDGE-3 P1 bug).'
        );
        // total_tax field is NOT mutated by the service (still the extracted VAT
        // from the original TTC subtotal — independent of discount).
        $this->assertEqualsWithDelta(2.27, (float) $fresh->total_tax, 0.01);
    }

    /**
     * Path 2 — TTC + 100% redeem: free order, total clamps to 0.
     *
     * subtotal=50 (TTC, tax=4.55 inside), redeem 5000pts=50€
     * → total must equal 0 (free order), NOT 4.55 (would mean tax was re-added).
     */
    public function test_ttc_full_redeem_clamps_to_zero(): void
    {
        Config::set('pricing.tax_inclusive_prices', true);

        $order = $this->makeOrder(subtotal: 50.00, tax: 4.55);

        /** @var PosRedemptionService $svc */
        $svc = app(PosRedemptionService::class);
        $svc->applyToOrder($order, 5000, 'SENTCUST', $this->cashier->id);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);

        $this->assertEqualsWithDelta(
            50.00,
            (float) $fresh->discount,
            0.01,
            'Discount must equal 50€ for 5000 pts redeemed'
        );
        $this->assertEqualsWithDelta(
            0.00,
            (float) $fresh->total,
            0.01,
            'TTC + 100% redeem must yield total=0 (free order). '
            . 'If total>0 the tax was double-counted (H.5 EDGE-3 P1 bug).'
        );
    }

    /**
     * Path 3 — HT mode + partial redeem: tax MUST be added separately.
     *
     * In HT mode, subtotal is tax-EXCLUSIVE, so the recompute legitimately
     * adds tax back. This guards against an over-eager fix that strips
     * the `+ tax` for both modes.
     *
     * subtotal=20 (HT), tax=2 (separate), redeem 500pts=5€
     * → total must equal 17 (20 − 5 + 2), NOT 15 (would mean tax was dropped).
     */
    public function test_ht_partial_redeem_keeps_adding_tax(): void
    {
        Config::set('pricing.tax_inclusive_prices', false);

        $order = $this->makeOrder(subtotal: 20.00, tax: 2.00);

        /** @var PosRedemptionService $svc */
        $svc = app(PosRedemptionService::class);
        $svc->applyToOrder($order, 500, 'SENTCUST', $this->cashier->id);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);

        $this->assertEqualsWithDelta(
            5.00,
            (float) $fresh->discount,
            0.01,
            'Discount must equal 5€ for 500 pts redeemed'
        );
        $this->assertEqualsWithDelta(
            17.00,
            (float) $fresh->total,
            0.01,
            'HT mode: total = subtotal − discount + tax = 20 − 5 + 2 = 17. '
            . 'If total=15 the fix over-zealously stripped the +tax for HT mode too.'
        );
    }
}
