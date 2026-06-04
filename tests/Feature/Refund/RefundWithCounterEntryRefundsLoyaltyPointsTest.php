<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * [WG-1-WF6-P1-2] Loyalty point reversal hole on post-Z refund path.
 *
 * Pre-heal: PosOrderController::refundWithCounterEntry (sole production caller
 * of RefundWithCounterEntryService::execute) did NOT companion-call
 * LoyaltyService::refundPoints — so a customer who redeemed loyalty points
 * on a sale, after Z closure, lost BOTH cash AND points when the refund went
 * through the counter-entry path. By contrast, the 3 cashBack() callers in
 * OrderService / FrontendOrderService correctly call refundPoints right
 * after cashBack (verified by reading app/Services/OrderService.php:1702
 * and :1805).
 *
 * Heal strategy: call LoyaltyService::refundPoints($parent, 'pos') inside
 * RefundWithCounterEntryService::execute() — same DB::transaction as mirror
 * creation. refundPoints is no-op-safe when `loyalty_customer_code` is null
 * (verified in app/Services/LoyaltyService.php:23-25 early-return), so the
 * call is unconditional and safe.
 */
class RefundWithCounterEntryRefundsLoyaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct()
            {
            }

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });

        $this->app->instance(FiscalSequenceService::class, new class extends FiscalSequenceService {
            public function __construct()
            {
            }

            public function next(int $branchId): int
            {
                return 9001;
            }
        });
    }

    private function sealZ(Branch $branch, Carbon $opened, Carbon $closed): void
    {
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    /**
     * P1-2 case 1: counter-entry refund on an order with loyalty redemption
     * MUST trigger LoyaltyService::refundPoints — customer's loyalty_points
     * balance is re-credited AND a `manual_add` ledger row is created.
     */
    public function test_counter_entry_refund_credits_back_redeemed_loyalty_points(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        // Customer with loyalty profile (15-char code per MySQL VARCHAR(15)).
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => \App\Enums\Status::ACTIVE,
            'loyalty_code'   => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => 50,
        ]);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);

        $parent = Order::factory()->create([
            'branch_id'             => $branch->id,
            'order_type'            => OrderType::POS,
            'status'                => OrderStatus::ACCEPT,
            'payment_status'        => PaymentStatus::PAID,
            'subtotal'              => 20.00,
            'total'                 => 15.00,
            'discount'              => 5.00,
            'total_tax'             => 0,
            'created_at'            => $opened->copy()->addHours(2),
            'loyalty_customer_code' => $customer->loyalty_code,
        ]);
        $parent->fiscal_sequence_no = 500;
        $parent->save();

        // Simulate the original redeem ledger row (100 points debited).
        LoyaltyTransaction::create([
            'user_id'        => $customer->id,
            'loyalty_code'   => $customer->loyalty_code,
            'order_id'       => $parent->id,
            'type'           => 'redeem',
            'points'         => -100,
            'balance_after'  => 50,
            'source_surface' => 'pos',
            'description'    => 'Test redeem on parent',
        ]);

        // Sanity check pre-refund state.
        $this->assertSame(50, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $parent->id)->where('type', 'redeem')->count()
        );

        app(RefundWithCounterEntryService::class)->execute($parent, 'sentinel-loyalty-refund');

        // Customer balance re-credited by abs(redeemed points) = 100.
        $this->assertSame(
            150,
            (int) $customer->fresh()->loyalty_points,
            'Customer must receive their 100 redeemed points back on counter-entry refund.'
        );

        // Reversal ledger row written (type = manual_add).
        $reversal = LoyaltyTransaction::where('order_id', $parent->id)
            ->where('type', 'manual_add')
            ->first();
        $this->assertNotNull($reversal, 'Reversal LoyaltyTransaction row must exist.');
        $this->assertSame(100, (int) $reversal->points);
        $this->assertStringContainsString('Remboursement', (string) $reversal->description);
    }

    /**
     * P1-2 case 2: counter-entry refund on an order WITHOUT a loyalty code
     * must be a NO-OP for loyalty (no exception, no stray ledger row).
     * Verified via app/Services/LoyaltyService.php:23-25 early-return.
     */
    public function test_counter_entry_refund_without_loyalty_code_is_noop_safe(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);

        $parent = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 18.00,
            'total'          => 18.00,
            'total_tax'      => 0,
            'created_at'     => $opened->copy()->addHours(2),
            // Explicitly NULL: no loyalty redemption.
            'loyalty_customer_code' => null,
        ]);
        $parent->fiscal_sequence_no = 600;
        $parent->save();

        // Should NOT throw — refundPoints early-returns when loyalty_customer_code is null.
        $mirror = app(RefundWithCounterEntryService::class)->execute($parent, 'sentinel-no-loyalty');

        $this->assertNotNull($mirror, 'Mirror order must still be created when loyalty code is absent.');
        $this->assertSame(
            0,
            LoyaltyTransaction::where('order_id', $parent->id)->where('type', 'manual_add')->count(),
            'No reversal LoyaltyTransaction should be written for orders without loyalty redemption.'
        );
    }
}
