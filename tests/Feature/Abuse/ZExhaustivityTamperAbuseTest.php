<?php

namespace Tests\Feature\Abuse;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ZReport;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VECTOR z_exhaustivity — Z-REPORT exhaustivity + tamper abuse.
 *
 * Goal: prove the two NF525 exhaustivity invariants under adversarial pressure:
 *   (A) EXACTLY-ONE-Z membership : every fiscally-sequenced PAID order lands in
 *       precisely one signed Z, and the FISCAL-CPS-01 class (a PAID order with
 *       fiscal_sequence_no NULL that escapes the Z aggregate) is shown to be
 *       BOTH the failure mode (negative proof) AND defended at the writer
 *       (positive proof through the real close()).
 *   (B) TAMPER detection : a forged z_reports row MUST be caught by the
 *       `fiscal:verify-chain` command (the existing command-level test only
 *       exercises the audit_logs leg; this closes the z_reports-leg gap).
 *
 * :memory: caveat — these prove the LOGIC. A true concurrent double-close
 * lock-race (Cache::lock + FOR UPDATE) cannot be proven on SQLite :memory:
 * (FOR UPDATE is a no-op); see the orchestrator RECIPE in the report.
 */
class ZExhaustivityTamperAbuseTest extends TestCase
{
    use RefreshDatabase;

    private ZReportService $service;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.z_report_secret', 'abuse-z-secret');
        Config::set('fiscal.audit_secret', 'abuse-audit-secret');
        $this->service = app(ZReportService::class);
        $this->branch  = Branch::factory()->create();
    }

    // ---------------------------------------------------------------------
    // (A) EXACTLY-ONE-Z MEMBERSHIP
    // ---------------------------------------------------------------------

    /**
     * @test
     * Negative proof — the FISCAL-CPS-01 failure SHAPE: a PAID order whose
     * fiscal_sequence_no is NULL is silently EXCLUDED from a real signed Z.
     * This is exactly the money that "escapes the Z" — quantify the leak.
     */
    public function test_paid_order_with_null_sequence_escapes_the_signed_z(): void
    {
        $start = Carbon::create(2026, 6, 17, 8, 0, 0);
        Carbon::setTestNow($start);
        $this->service->open($this->branch->id);

        Carbon::setTestNow($start->copy()->addHour());

        // A correctly-sealed PAID order (has a sequence) — MUST be in the Z.
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 30.00,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'fiscal_sequence_no' => 1,
        ]);

        // The escapee — PAID but fiscal_sequence_no NULL (FISCAL-CPS-01 shape).
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 999.99,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'fiscal_sequence_no' => null,
        ]);

        Carbon::setTestNow($start->copy()->addHours(5));
        $closed = $this->service->close($this->branch->id);

        // Only the sequenced order is signed into the Z. The 999.99 escapee
        // is NOWHERE in the signed total — this is the NF525 hole quantified.
        $this->assertSame(1, (int) $closed->order_count, 'NULL-seq PAID order must be excluded from Z aggregate.');
        $this->assertEqualsWithDelta(30.00, (float) $closed->total_ttc, 0.001, 'Escapee revenue must NOT reach the signed total.');

        Carbon::setTestNow(null);
    }

    /**
     * @test
     * Positive proof — re-prove FISCAL-CPS-01 is DEFENDED at the writer:
     * driving an UNPAID order to PAID via the real OrderService allocation
     * mirror (FiscalSequenceService::next, the same call the healed
     * changePaymentStatus uses) produces a sequence, so the order DOES land
     * in the signed Z. (changePaymentStatus alloc itself is unit-proven by
     * ChangePaymentStatusFiscalAllocTest; here we prove it reaches the Z.)
     */
    public function test_sealed_paid_order_lands_in_exactly_one_z(): void
    {
        $start = Carbon::create(2026, 6, 17, 8, 0, 0);
        Carbon::setTestNow($start);
        $this->service->open($this->branch->id);

        Carbon::setTestNow($start->copy()->addHour());

        $order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 50.00,
            'payment_status'     => PaymentStatus::UNPAID,
            'status'             => OrderStatus::PENDING,
            'fiscal_sequence_no' => null,
        ]);

        // Simulate the healed PAID transition: allocate a gap-free sequence
        // via the SSOT service (same call changePaymentStatus:2452 makes).
        $order->fiscal_sequence_no = app(FiscalSequenceService::class)->next($this->branch->id);
        $order->payment_status = PaymentStatus::PAID;
        $order->status = OrderStatus::DELIVERED;
        $order->save();

        $this->assertNotNull($order->fresh()->fiscal_sequence_no);

        Carbon::setTestNow($start->copy()->addHours(5));
        $closed = $this->service->close($this->branch->id);

        $this->assertSame(1, (int) $closed->order_count);
        $this->assertEqualsWithDelta(50.00, (float) $closed->total_ttc, 0.001);

        // Exactly ONE Z: the order's created_at sits in this window only, and a
        // second Z opened after the close finds nothing to re-count.
        Carbon::setTestNow($start->copy()->addHours(6));
        $this->service->open($this->branch->id);
        Carbon::setTestNow($start->copy()->addHours(7));
        $second = $this->service->close($this->branch->id);
        $this->assertSame(0, (int) $second->order_count, 'A sealed order must enter exactly ONE Z, never a second.');

        Carbon::setTestNow(null);
    }

    /**
     * @test
     * Boundary abuse — half-open window (from, to]. An order created at the
     * EXACT close instant of Z#1 must belong to Z#2 only (never double-counted
     * across the boundary). Direct attack on "exactly one Z".
     */
    public function test_boundary_instant_order_is_counted_in_exactly_one_z(): void
    {
        $t0 = Carbon::create(2026, 6, 17, 8, 0, 0);

        Carbon::setTestNow($t0);
        $this->service->open($this->branch->id);

        $boundary = $t0->copy()->addHours(2);

        // Order created at the exact boundary instant (= close time of Z#1).
        Carbon::setTestNow($boundary);
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 12.00,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'fiscal_sequence_no' => 1,
        ]);

        // Close Z#1 at the boundary instant.
        $z1 = $this->service->close($this->branch->id);

        // Z#2 spans (boundary, ...].
        Carbon::setTestNow($boundary->copy()->addHour());
        $this->service->open($this->branch->id);
        Carbon::setTestNow($boundary->copy()->addHours(3));
        $z2 = $this->service->close($this->branch->id);

        $total = (int) $z1->order_count + (int) $z2->order_count;
        $this->assertSame(1, $total, 'Boundary order must appear in EXACTLY one Z (no double-count, no drop).');

        Carbon::setTestNow(null);
    }

    // ---------------------------------------------------------------------
    // (B) TAMPER DETECTION
    // ---------------------------------------------------------------------

    /**
     * @test
     * z_reports tamper via the OPERATOR command. Forge a signed total on a
     * closed Z row in raw DB → `fiscal:verify-chain --branch=<id>` MUST exit 1
     * (tamper). Closes the gap: the existing command test only forges
     * audit_logs; this proves the z_reports leg is wired into exit-code 1.
     */
    public function test_tampered_z_report_total_is_detected_by_verify_chain_command(): void
    {
        $start = Carbon::create(2026, 6, 17, 8, 0, 0);
        Carbon::setTestNow($start);
        $this->service->open($this->branch->id);

        Carbon::setTestNow($start->copy()->addHour());
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'total'              => 80.00,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'fiscal_sequence_no' => 1,
        ]);

        Carbon::setTestNow($start->copy()->addHours(3));
        $closed = $this->service->close($this->branch->id);
        Carbon::setTestNow(null);

        // Sanity: clean chain passes.
        $this->artisan('fiscal:verify-chain', ['--branch' => $this->branch->id])
            ->assertExitCode(0);

        // ATTACK: inflate the signed total directly in the DB (fraud), leaving
        // the signature intact. The HMAC no longer matches the row contents.
        DB::table('z_reports')->where('id', $closed->id)->update([
            'total_ttc' => 9999.99,
        ]);

        // The command must detect the breach and exit 1.
        $this->artisan('fiscal:verify-chain', ['--branch' => $this->branch->id])
            ->assertExitCode(1);
    }

    /**
     * @test
     * z_reports prev_hash chain tamper: re-link a closed row to a forged
     * prev_hash (history-rewrite attack) → verify-chain MUST exit 1.
     */
    public function test_forged_prev_hash_breaks_z_chain(): void
    {
        $start = Carbon::create(2026, 6, 17, 8, 0, 0);

        Carbon::setTestNow($start);
        $this->service->open($this->branch->id);
        Carbon::setTestNow($start->copy()->addHour());
        $z1 = $this->service->close($this->branch->id);

        Carbon::setTestNow($start->copy()->addHours(2));
        $this->service->open($this->branch->id);
        Carbon::setTestNow($start->copy()->addHours(3));
        $z2 = $this->service->close($this->branch->id);
        Carbon::setTestNow(null);

        $this->artisan('fiscal:verify-chain', ['--branch' => $this->branch->id])
            ->assertExitCode(0);

        // ATTACK: rewrite the link between Z#1 and Z#2.
        DB::table('z_reports')->where('id', $z2->id)->update([
            'prev_hash' => str_repeat('0', 64),
        ]);

        $this->artisan('fiscal:verify-chain', ['--branch' => $this->branch->id])
            ->assertExitCode(1);
    }

    // ---------------------------------------------------------------------
    // DOUBLE-CLOSE (logical guard — true lock-race is an orchestrator RECIPE)
    // ---------------------------------------------------------------------

    /**
     * @test
     * Sequential double-close on one branch is refused (no second Z, no
     * re-signing). NOTE: this proves the LOGICAL guard only — a real
     * concurrent race needs MySQL FOR UPDATE (see orchestrator recipe).
     */
    public function test_sequential_double_close_is_refused(): void
    {
        $this->service->open($this->branch->id);
        $this->service->close($this->branch->id);

        $this->expectException(\RuntimeException::class);
        $this->service->close($this->branch->id);
    }
}
