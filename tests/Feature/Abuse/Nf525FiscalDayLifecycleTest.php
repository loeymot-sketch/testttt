<?php

namespace Tests\Feature\Abuse;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [VECTOR nf525_lifecycle]
 *
 * Tests the NF525 fiscal-day as a SINGLE legal artifact, end-to-end:
 *
 *   open() -> N PAID orders (mix POS cash-at-close + kiosk-paid) ->
 *   one refund (counter-entry mirror) -> close() -> assert:
 *     - Z aggregate total_ttc == exact sum of in-window PAID order
 *       totals, NETTED of the refund;
 *     - fiscal_sequence_no allocated gap-free per branch;
 *     - every PAID in-window order carries a fiscal_sequence_no and
 *       lands in the aggregate (whereNotNull membership);
 *     - audit_logs HMAC chain links (prev_hash -> current_hash) intact
 *       across the day, including the Z-close cross-chain anchor row;
 *     - z_reports HMAC chain links across consecutive closes;
 *     - second close on the same day blocked;
 *     - close while one is already open blocked (open-while-open);
 *     - the Z-close anchor binds the audit chain to the Z signature.
 *
 * ZReportService / FiscalSequenceService / AuditLogService are FROZEN
 * (CLAUDE.md §7) — this test exercises them, it does not modify them.
 *
 * Setup pattern mirrors ZReportCloseTest + ZReportCloseAuditAnchorSentinelTest.
 */
class Nf525FiscalDayLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ZReportService $z;
    private FiscalSequenceService $seq;
    private AuditLogService $audit;
    private Branch $branch;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // Both fiscal secrets must be set: the Z signature chain and the
        // audit_logs HMAC chain (the latter feeds the Z-close anchor hook).
        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');
        Config::set('fiscal.audit_secret', 'unit-test-audit-lifecycle-secret-48-chars-padding');

        $this->z     = app(ZReportService::class);
        $this->seq   = app(FiscalSequenceService::class);
        $this->audit = app(AuditLogService::class);
        $this->branch = Branch::factory()->create();
        $this->item   = Item::factory()->create();
    }

    /**
     * Create a fiscally-sequenced PAID order with one taxed line so the
     * Z tax decomposition (order_items.tax_rate / tax_amount) is exercised.
     * Returns the persisted Order.
     */
    private function paidOrder(float $total, string $method, float $taxRate, float $taxAmount, array $extra = []): Order
    {
        $fiscalNo = $this->seq->next($this->branch->id);

        $order = Order::factory()->create(array_merge([
            'branch_id'          => $this->branch->id,
            'subtotal'           => $total,
            'total'              => $total,
            'discount'           => 0,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'pos_payment_method' => $method,
            'fiscal_sequence_no' => $fiscalNo,
        ], $extra));

        DB::table('order_items')->insert([
            'order_id'    => $order->id,
            'branch_id'   => $this->branch->id,
            'item_id'     => $this->item->id,
            'quantity'    => 1,
            'discount'    => 0,
            'tax_rate'    => $taxRate,
            'tax_amount'  => $taxAmount,
            'price'       => $total,
            'total_price' => $total,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

        return $order;
    }

    public function test_full_fiscal_day_lifecycle_is_a_consistent_signed_artifact(): void
    {
        $dayStart = Carbon::create(2026, 6, 17, 8, 0, 0);
        Carbon::setTestNow($dayStart);

        // --- OPEN ----------------------------------------------------------
        $open = $this->z->open($this->branch->id);
        $this->assertSame(ZReport::STATUS_OPEN, $open->status);
        $this->assertSame(1, $open->sequence_no, 'First Z of the branch is sequence 1.');

        // open-while-open must be blocked.
        try {
            $this->z->open($this->branch->id);
            $this->fail('A second open() while one Z is OPEN must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has an OPEN', $e->getMessage());
        }

        // --- SALES ---------------------------------------------------------
        // Order A: POS cash-at-close, 24.00 TTC, TVA 10% on a 2.18 base part.
        Carbon::setTestNow($dayStart->copy()->addMinutes(30));
        $a = $this->paidOrder(24.00, 'cash', 10.0, 2.18);

        // Order B: kiosk-paid (card), 12.50 TTC, TVA 5.5%.
        Carbon::setTestNow($dayStart->copy()->addHour());
        $b = $this->paidOrder(12.50, 'card', 5.5, 0.65, ['payment_method' => 2]);

        // Order C: POS cash-at-close, 8.00 TTC, TVA 10%.
        Carbon::setTestNow($dayStart->copy()->addHours(2));
        $c = $this->paidOrder(8.00, 'cash', 10.0, 0.73);

        // UNPAID order in-window — must NOT count, must NOT get a sequence.
        Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'total'          => 99.00,
            'payment_status' => PaymentStatus::UNPAID,
            'status'         => OrderStatus::PENDING,
            // fiscal_sequence_no left null on purpose.
        ]);

        // Gap-free sequence check: A=1, B=2, C=3 (UNPAID got none).
        $this->assertSame(1, (int) $a->fiscal_sequence_no);
        $this->assertSame(2, (int) $b->fiscal_sequence_no);
        $this->assertSame(3, (int) $c->fiscal_sequence_no);

        // --- REFUND --------------------------------------------------------
        // Refund order C in full via the counter-entry mirror pattern that
        // ZReportService::aggregate() recognises: a RETURNED row with a
        // non-null parent_order_id and an already-NEGATED total, created
        // inside the current window. (RefundWithCounterEntryService model.)
        Carbon::setTestNow($dayStart->copy()->addHours(3));
        $refundFiscalNo = $this->seq->next($this->branch->id); // mirror is itself a fiscal event (seq 4)
        $mirror = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'parent_order_id'    => $c->id,
            'subtotal'           => -8.00,
            'total'              => -8.00,
            'discount'           => 0,
            'payment_status'     => PaymentStatus::REFUNDED,
            'status'             => OrderStatus::RETURNED,
            'pos_payment_method' => 'cash',
            'fiscal_sequence_no' => $refundFiscalNo,
        ]);
        DB::table('order_items')->insert([
            'order_id'    => $mirror->id,
            'branch_id'   => $this->branch->id,
            'item_id'     => $this->item->id,
            'quantity'    => 1,
            'discount'    => 0,
            'tax_rate'    => 10.0,
            'tax_amount'  => -0.73,
            'price'       => -8.00,
            'total_price' => -8.00,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

        $this->assertSame(4, (int) $mirror->fiscal_sequence_no, 'Refund mirror is fiscal seq 4 — gap-free.');

        // --- CLOSE ---------------------------------------------------------
        Carbon::setTestNow($dayStart->copy()->addHours(10)); // 18:00 close
        $auditBefore = AuditLog::query()->count();
        $closed = $this->z->close($this->branch->id);

        $this->assertSame(ZReport::STATUS_CLOSED, $closed->status);
        $this->assertNotEmpty($closed->signature);

        // ---- ASSERT 1: aggregate total == sum of PAID, refund-netted -----
        // Positive sales A+B+C = 24.00 + 12.50 + 8.00 = 44.50
        // Refund mirror nets -8.00  => expected signed total_ttc = 36.50
        $expectedTtc = (24.00 + 12.50 + 8.00) + (-8.00);
        $this->assertEqualsWithDelta(
            $expectedTtc,
            (float) $closed->total_ttc,
            0.001,
            'Z total_ttc must equal sum of in-window PAID order totals, netted of the refund.'
        );

        // order_count counts the 3 positive non-terminal PAID orders.
        $this->assertSame(3, (int) $closed->order_count, 'order_count = the 3 positive PAID orders (mirror is RETURNED).');
        $this->assertGreaterThanOrEqual(1, (int) $closed->refund_count, 'The refund mirror must be counted as a refund.');

        // ---- ASSERT 2: NF525 tax identity holds in the signed payload ----
        $byTaxRate = (array) $closed->total_by_tax_rate;
        $this->assertEqualsWithDelta(
            (float) $closed->total_tva,
            array_sum($byTaxRate),
            0.001,
            'total_tva must equal the sum of the per-rate breakdown (NF525 identity).'
        );
        // total_ttc == total_ht + total_tva by construction.
        $this->assertEqualsWithDelta(
            (float) $closed->total_ttc,
            (float) $closed->total_ht + (float) $closed->total_tva,
            0.001,
            'total_ttc == total_ht + total_tva.'
        );
        // The refund nets the 10% bucket: 2.18 + 0.73 - 0.73 = 2.18 ; 5.5% bucket = 0.65.
        $this->assertEqualsWithDelta(2.18, (float) ($byTaxRate['10'] ?? 0), 0.001, '10% bucket nets the refunded line.');
        $this->assertEqualsWithDelta(0.65, (float) ($byTaxRate['5.5'] ?? 0), 0.001, '5.5% bucket = order B TVA.');

        // ---- ASSERT 3: every in-window PAID order is in the Z (membership)
        // Re-derive membership exactly as aggregate() does (whereNotNull +
        // window + non-terminal) and confirm A,B,C are present and the
        // UNPAID order is absent.
        $memberIds = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->withTrashed()
            ->where('branch_id', $this->branch->id)
            ->whereNotNull('fiscal_sequence_no')
            ->where('payment_status', '!=', PaymentStatus::UNPAID)
            ->where('created_at', '>', $open->opened_at)
            ->where('created_at', '<=', $closed->closed_at)
            ->whereNotIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])
            ->pluck('id')->all();
        $this->assertContains($a->id, $memberIds);
        $this->assertContains($b->id, $memberIds);
        $this->assertContains($c->id, $memberIds);

        // No PAID order with a fiscal_sequence_no escaped the window: the
        // only fiscally-sequenced rows are A,B,C (positive) + mirror (RETURNED).
        $sequenced = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->withTrashed()
            ->where('branch_id', $this->branch->id)
            ->whereNotNull('fiscal_sequence_no')
            ->pluck('fiscal_sequence_no')->map(fn ($n) => (int) $n)->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4], $sequenced, 'Fiscal sequence is gap-free 1..4 across the day.');

        // ---- ASSERT 4: z_reports HMAC chain self-verifies -----------------
        $this->assertTrue($this->z->verifySignature($closed), 'Closed Z signature must self-verify.');
        $chain = $this->z->verifyChain($this->branch->id, true);
        $this->assertTrue($chain['valid'], 'Z chain must validate after the close.');
        $this->assertSame(1, $chain['count']);

        // ---- ASSERT 5: audit_logs HMAC chain intact + Z-close anchor ------
        // The Z-close hook (AppServiceProvider) wrote exactly one anchor row.
        $this->assertSame(
            $auditBefore + 1,
            AuditLog::query()->count(),
            'Z close must append exactly one audit_logs anchor row.'
        );
        $anchor = AuditLog::query()->orderByDesc('id')->first();
        $this->assertSame('z_report.closed', $anchor->action);
        $this->assertSame((int) $closed->id, (int) $anchor->resource_id);
        $payload = (array) $anchor->payload;
        $this->assertSame((string) $closed->signature, (string) $payload['signature'], 'Anchor binds the audit chain to the Z signature.');

        // The full audit chain must verify (prev_hash -> current_hash links).
        $this->assertNull(
            $this->audit->verifyChain($this->branch->id),
            'audit_logs HMAC chain must be intact (verifyChain returns null).'
        );

        // ---- ASSERT 6: second close same day blocked ----------------------
        try {
            $this->z->close($this->branch->id);
            $this->fail('A second close() with no open Z must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no open Z report', $e->getMessage());
        }

        Carbon::setTestNow(null);
    }

    /**
     * Second fiscal day: prove the z_reports chain links day-2 onto day-1
     * (prev_hash of Z#2 == signature of Z#1) and the sequence advances
     * gap-free to 2 for the branch.
     */
    public function test_consecutive_fiscal_days_chain_and_sequence_advances(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 17, 8, 0, 0));
        $this->z->open($this->branch->id);
        $this->paidOrder(10.00, 'cash', 10.0, 0.91);
        Carbon::setTestNow(Carbon::create(2026, 6, 17, 18, 0, 0));
        $day1 = $this->z->close($this->branch->id);

        Carbon::setTestNow(Carbon::create(2026, 6, 18, 8, 0, 0));
        $open2 = $this->z->open($this->branch->id);
        $this->assertSame(2, $open2->sequence_no, 'Day-2 Z is sequence 2 — gap-free per branch.');
        // Advance past opened_at: the aggregate window lower bound is STRICT
        // (created_at > opened_at), so a sale stamped at the open instant
        // would (correctly) fall outside the window.
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0));
        $this->paidOrder(20.00, 'card', 10.0, 1.82, ['payment_method' => 2]);
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 18, 0, 0));
        $day2 = $this->z->close($this->branch->id);

        $this->assertNotNull($day1->signature);
        $this->assertSame($day1->signature, $day2->prev_hash, 'Z#2 must chain on Z#1 signature.');

        // Day-2 must only contain day-2 sales (10% window discipline).
        $this->assertEqualsWithDelta(20.00, (float) $day2->total_ttc, 0.001, 'Day-2 Z must NOT re-count day-1 sales.');

        $chain = $this->z->verifyChain($this->branch->id, true);
        $this->assertTrue($chain['valid']);
        $this->assertSame(2, $chain['count']);

        Carbon::setTestNow(null);
    }
}
