<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [POS-9.4.7 / POS-GA-F-01]
 *
 * Covers the invariants of ZReportService:
 *  - open() allocates monotonic sequence_no per branch (5 opens = 1..5);
 *  - close() freezes aggregates and signs with HMAC chained on prev_hash;
 *  - a second close() on the same open fails;
 *  - only PAID orders within [opened_at; closed_at] are aggregated;
 *  - verifySignature() detects tampering.
 */
class ZReportCloseTest extends TestCase
{
    use RefreshDatabase;

    private ZReportService $service;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');
        $this->service = app(ZReportService::class);
        $this->branch  = Branch::factory()->create();
    }

    public function test_sequential_open_close_five_times_no_gap(): void
    {
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $open = $this->service->open($this->branch->id);
            $this->assertSame($i, $open->sequence_no, "Open #{$i} must carry sequence {$i}.");
            $closed = $this->service->close($this->branch->id);
            $this->assertSame(ZReport::STATUS_CLOSED, $closed->status);
            $ids[] = $closed->sequence_no;
        }
        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_close_aggregates_only_paid_orders_within_window(): void
    {
        $start = Carbon::create(2026, 4, 22, 8, 0, 0);
        Carbon::setTestNow($start);
        $open = $this->service->open($this->branch->id);

        // PAID within window
        Carbon::setTestNow($start->copy()->addHour());
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 42.50,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'fiscal_sequence_no' => 1,
        ]);

        // UNPAID within window — must NOT count
        Carbon::setTestNow($start->copy()->addHour()->addMinutes(30));
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 100.00,
            'payment_status'     => PaymentStatus::UNPAID,
            'status'             => OrderStatus::PENDING,
            'fiscal_sequence_no' => 2,
        ]);

        // [C33 / LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT — 2026-07-07] PAID before open().
        // This is the FIRST Z for the branch → the aggregation lower bound is null (no
        // previous close), so it absorbs the WHOLE history up to close, INCLUDING sales
        // made before open. Pre-C33 the window was (opened_at, closed_at] and this sale
        // fell into NO signed Z (a dead-window orphan). Post-C33 the continuous partition
        // captures it here — it must count. (A payment-status UNPAID row is still excluded.)
        Carbon::setTestNow($start->copy()->subHour());
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 999.00,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'fiscal_sequence_no' => 3,
        ]);

        Carbon::setTestNow($start->copy()->addHours(5));
        $closed = $this->service->close($this->branch->id);

        $this->assertSame(ZReport::STATUS_CLOSED, $closed->status);
        // 42,50 (in-window PAID) + 999,00 (pre-open PAID, now absorbed by the first Z);
        // UNPAID excluded by payment_status.
        $this->assertSame(2,     $closed->order_count);
        $this->assertEqualsWithDelta(1041.50, (float) $closed->total_ttc, 0.001);

        Carbon::setTestNow(null);
    }

    public function test_second_close_blocked(): void
    {
        $this->service->open($this->branch->id);
        $this->service->close($this->branch->id);

        $this->expectException(\RuntimeException::class);
        $this->service->close($this->branch->id);
    }

    public function test_open_blocked_while_one_is_already_open(): void
    {
        $this->service->open($this->branch->id);

        $this->expectException(\RuntimeException::class);
        $this->service->open($this->branch->id);
    }

    public function test_signature_verifies_and_detects_tampering(): void
    {
        $this->service->open($this->branch->id);
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 10.00,
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => 1,
        ]);
        $closed = $this->service->close($this->branch->id);

        $this->assertTrue($this->service->verifySignature($closed));

        // Simulate an attacker with raw DB access (trigger does NOT cover z_reports
        // in this vague — application-layer detection is the backstop).
        $closed->total_ttc = 999999.99;
        $closed->saveQuietly();

        $this->assertFalse(
            $this->service->verifySignature($closed->refresh()),
            'verifySignature must detect tampered aggregates.'
        );
    }

    public function test_signature_chains_between_consecutive_closes(): void
    {
        $this->service->open($this->branch->id);
        $first = $this->service->close($this->branch->id);

        $this->service->open($this->branch->id);
        $second = $this->service->close($this->branch->id);

        $this->assertNotNull($first->signature);
        $this->assertNotNull($second->signature);
        $this->assertSame($first->signature, $second->prev_hash,
            'Second Z must chain on first Z signature.');
    }
}
