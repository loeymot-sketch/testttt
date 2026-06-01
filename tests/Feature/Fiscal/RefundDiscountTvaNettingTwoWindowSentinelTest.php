<?php

/**
 * [LOCK_ZREPORT_REFUND_DISCOUNT_TVA_NETTING 2026-06-01 — ZRPT-SEM-01] NF525.
 *
 * When a DISCOUNTED order is refunded AFTER its Z-close (cross-window), the signed
 * Z understated per-rate TVA: the discounted parent contributed its POST-discount
 * TVA (tax × ratio) in window 1, but the refund mirror — whose negative subtotal
 * forces ZReportService::orderDiscountRatio to 1.0 — reversed the FULL pre-discount
 * TVA in window 2. Net per-rate TVA across the two windows ≠ 0.
 *
 * Heal (RefundWithCounterEntryService, non-frozen): pre-scale the mirror's per-line
 * tax_amount by the parent's discount ratio before negating, so the mirror reverses
 * exactly the net TVA the parent contributed → Σ per-rate total_by_tax_rate = 0.
 *
 * This test drives the REAL service (not a hand-modeled mirror) and is the
 * regression lock — it MUST fail before the fix (window2 = -10, sum = -2) and pass
 * after (window2 = -8, sum = 0).
 *
 * @group sentinel
 * @group fiscal
 */

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Item;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RefundDiscountTvaNettingTwoWindowSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_discounted_post_z_refund_nets_per_rate_tva_to_zero(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        // Window 1 — sealed (closed Z). Discounted parent sold + sealed inside it.
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        ZReport::create([
            'branch_id' => $branch->id, 'sequence_no' => 1,
            'opened_at' => $opened, 'closed_at' => $closed, 'status' => ZReport::STATUS_CLOSED,
        ]);

        // Discounted order: subtotal 100, discount 20 → ratio 0.8. One 10%-VAT line
        // carrying 10.00 of PRE-discount tax. Net (post-discount) TVA = 10 × 0.8 = 8.00.
        $parent = Order::factory()->create([
            'branch_id' => $branch->id, 'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 100.00, 'discount' => 20.00, 'total' => 88.00, 'total_tax' => 10.00,
            'created_at' => $opened->copy()->addHours(2),
        ]);
        $parent->fiscal_sequence_no = 10;
        $parent->save();
        $item = Item::factory()->create();
        OrderItem::create([
            'order_id' => $parent->id, 'branch_id' => $branch->id, 'item_id' => $item->id,
            'quantity' => 1, 'released_qty' => 0, 'discount' => 0, 'price' => 100,
            'tax_rate' => '10', 'tax_amount' => 10.00,
        ]);

        // Window 1 aggregate: parent contributes its NET per-rate TVA (10 × 0.8 = 8.00).
        $w1 = app(ZReportService::class)->aggregate($branch->id, $opened, $closed);
        $this->assertEqualsWithDelta(8.00, (float) ($w1['total_by_tax_rate']['10'] ?? 0), 0.01,
            'Discounted parent must contribute POST-discount TVA (10 × 0.8 = 8.00) in window 1.');

        // The REAL service mints the cross-window refund mirror.
        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'discounted post-Z refund TVA netting');
        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);

        // Window 2 aggregate: from window-1 close to just after the mirror.
        $w2 = app(ZReportService::class)->aggregate($branch->id, $closed, Carbon::now()->addMinute());

        // The fix: mirror reverses the NET TVA (-8.00), NOT the full -10.00.
        $this->assertEqualsWithDelta(-8.00, (float) ($w2['total_by_tax_rate']['10'] ?? 0), 0.01,
            'Mirror must reverse the discount-netted TVA (-8.00), not the full pre-discount -10.00.');

        // NF525 cross-window invariant: signed per-rate TVA nets to exactly 0.00.
        $netRate10 = round((float) ($w1['total_by_tax_rate']['10'] ?? 0)
            + (float) ($w2['total_by_tax_rate']['10'] ?? 0), 2);
        $this->assertSame(0.0, $netRate10,
            'Σ per-rate total_by_tax_rate across the close + refund windows MUST be 0.00 (ZRPT-SEM-01).');
    }

    public function test_non_discounted_refund_is_byte_identical_full_reversal(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Auth::setUser($user);

        $opened = Carbon::parse('2026-05-02 08:00:00');
        $closed = Carbon::parse('2026-05-02 20:00:00');
        ZReport::create([
            'branch_id' => $branch->id, 'sequence_no' => 1,
            'opened_at' => $opened, 'closed_at' => $closed, 'status' => ZReport::STATUS_CLOSED,
        ]);

        // No discount → ratio 1.0 → mirror reverses the FULL TVA (unchanged behavior).
        $parent = Order::factory()->create([
            'branch_id' => $branch->id, 'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 100.00, 'discount' => 0, 'total' => 110.00, 'total_tax' => 10.00,
            'created_at' => $opened->copy()->addHours(2),
        ]);
        $parent->fiscal_sequence_no = 20;
        $parent->save();
        $item = Item::factory()->create();
        OrderItem::create([
            'order_id' => $parent->id, 'branch_id' => $branch->id, 'item_id' => $item->id,
            'quantity' => 1, 'released_qty' => 0, 'discount' => 0, 'price' => 100,
            'tax_rate' => '10', 'tax_amount' => 10.00,
        ]);

        app(RefundWithCounterEntryService::class)->execute($parent, 'non-discounted refund');
        $w2 = app(ZReportService::class)->aggregate($branch->id, $closed, Carbon::now()->addMinute());

        $this->assertEqualsWithDelta(-10.00, (float) ($w2['total_by_tax_rate']['10'] ?? 0), 0.01,
            'Non-discounted refund mirror reverses the full TVA (-10.00) — byte-identical to prior behavior.');
    }
}
