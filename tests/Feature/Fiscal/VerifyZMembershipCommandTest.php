<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * [P0 #1 — AUTHORITATIVE C33 re-aggregation detector 2026-07-07]
 *
 * `fiscal:verify-z-membership` proves the NF525 gap-free invariant: every
 * numbered receipt is in exactly one signed Z (or pending in the current open
 * window). The detector was rewritten from a created_at+updated_at HEURISTIC
 * (which over-signalled ~2507 false positives after C33) to an AUTHORITATIVE
 * re-aggregation using the REAL C33 continuous-partition window:
 *     Z_1 : (−∞, c_1]        Z_n : (c_{n-1}, c_n]      open : (c_k, +∞)
 * A numbered order is a REAL orphan only if its created_at is in NO signed
 * window AND there is no open Z to seal it.
 *
 * These tests lock: (1) a sale in the old "dead window" but covered by the next
 * signed Z is NOT flagged (false-positive elimination), and (2) a genuine
 * orphan (numbered, after the last close, no open Z) IS flagged.
 */
class VerifyZMembershipCommandTest extends TestCase
{
    use RefreshDatabase;

    private const D1_OPEN  = '2026-05-01 08:00:00';
    private const D1_CLOSE = '2026-05-01 20:00:00';
    private const D2_OPEN  = '2026-05-02 08:00:00';
    private const D2_CLOSE = '2026-05-02 20:00:00';

    private function zReport(int $branchId, int $seq, string $status, ?string $openedAt, ?string $closedAt): void
    {
        ZReport::create([
            'branch_id'   => $branchId,
            'sequence_no' => $seq,
            'opened_at'   => $openedAt ? Carbon::parse($openedAt) : null,
            'closed_at'   => $closedAt ? Carbon::parse($closedAt) : null,
            'status'      => $status,
        ]);
    }

    private function numberedOrder(int $branchId, string $serial, string $createdAt, ?string $updatedAt = null): Order
    {
        $order = Order::factory()->create([
            'branch_id'       => $branchId,
            'status'          => OrderStatus::ACCEPT,   // non-terminal
            'payment_status'  => PaymentStatus::PAID,
            'total'           => 50.00,
            'order_serial_no' => $serial,
            'fiscal_sequence_no' => 42,
            'parent_order_id' => null,
            'created_at'      => Carbon::parse($createdAt),
        ]);

        if ($updatedAt) {
            // Set updated_at without bumping timestamps — proves the detector
            // no longer keys on updated_at (the old heuristic's false-positive source).
            Order::withoutGlobalScopes()->where('id', $order->id)
                ->update(['updated_at' => Carbon::parse($updatedAt)]);
        }

        return $order;
    }

    // ── Covered cases (exit 0) ────────────────────────────────────────────────

    public function test_order_inside_a_signed_Z_window_is_not_flagged(): void
    {
        $branch = Branch::factory()->create();
        $this->zReport($branch->id, 1, ZReport::STATUS_CLOSED, self::D1_OPEN, self::D1_CLOSE);

        // Created 12:00, inside (−∞, 20:00] → sealed in Z_1.
        $this->numberedOrder($branch->id, 'IN0001', '2026-05-01 12:00:00');

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->assertExitCode(0);
    }

    /**
     * The KEY false-positive-elimination case. A sale created in the "dead
     * window" between Z_1's close (day1 20:00) and Z_2's open (day2 08:00) was
     * flagged as a "TROU" by the old heuristic (which bounded windows by
     * opened_at). Under C33 the next Z aggregates from the PREVIOUS closed_at,
     * so Z_2's real window (day1 20:00, day2 20:00] COVERS it → NOT an orphan.
     */
    public function test_order_in_dead_window_but_covered_by_next_signed_Z_is_not_flagged(): void
    {
        $branch = Branch::factory()->create();
        $this->zReport($branch->id, 1, ZReport::STATUS_CLOSED, self::D1_OPEN, self::D1_CLOSE);
        $this->zReport($branch->id, 2, ZReport::STATUS_CLOSED, self::D2_OPEN, self::D2_CLOSE);

        // Created day1 22:00 — in the old dead window, covered by Z_2's C33 window.
        $this->numberedOrder($branch->id, 'DEAD01', '2026-05-01 22:00:00');

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->assertExitCode(0);
    }

    /**
     * Documents the DELIBERATE model change: an order created inside a signed
     * window but whose sequence was allocated AFTER that Z closed (updated_at in
     * the future) is treated as COVERED by created_at — the old heuristic
     * false-flagged it via updated_at > closed_at. That residual class is caught
     * by warnOnOrphanedPaidOrders() at close + the retry cron, not here.
     */
    public function test_order_created_in_window_but_numbered_after_close_is_covered(): void
    {
        $branch = Branch::factory()->create();
        $this->zReport($branch->id, 1, ZReport::STATUS_CLOSED, self::D1_OPEN, self::D1_CLOSE);

        // Created 10:00 (in window) but touched the next day (after the Z closed).
        $this->numberedOrder($branch->id, 'LATE01', '2026-05-01 10:00:00', '2026-05-02 09:00:00');

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->assertExitCode(0);
    }

    public function test_order_pending_in_current_open_window_is_not_flagged(): void
    {
        $branch = Branch::factory()->create();
        $this->zReport($branch->id, 1, ZReport::STATUS_CLOSED, self::D1_OPEN, self::D1_CLOSE);
        // An OPEN Z is pending → the next close (from = day1 20:00) will seal day2 sales.
        $this->zReport($branch->id, 2, ZReport::STATUS_OPEN, self::D2_OPEN, null);

        // Created day2 10:00 — after the last close, but an open Z will seal it.
        $this->numberedOrder($branch->id, 'PEND01', '2026-05-02 10:00:00');

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->assertExitCode(0);
    }

    // ── Real orphan (exit 1) ──────────────────────────────────────────────────

    /**
     * A genuine gap-free orphan: numbered, settled, created AFTER the last
     * closed Z, on a branch with NO open Z to seal it → in zero Z with nothing
     * queued. MUST be flagged (exit 1).
     */
    public function test_true_orphan_after_last_close_with_no_open_Z_is_flagged(): void
    {
        $branch = Branch::factory()->create();
        $this->zReport($branch->id, 1, ZReport::STATUS_CLOSED, self::D1_OPEN, self::D1_CLOSE);
        // No open Z.

        // Created day2 10:00 — after the last close, nothing pending to seal it.
        $this->numberedOrder($branch->id, 'ORPH01', '2026-05-02 10:00:00');

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->expectsOutputToContain('ORPH01')
            ->assertExitCode(1);
    }

    public function test_numbered_order_on_branch_with_no_Z_at_all_is_flagged(): void
    {
        $branch = Branch::factory()->create();
        // No Z reports whatsoever on this branch.

        $this->numberedOrder($branch->id, 'NOZ001', '2026-05-01 10:00:00');

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->expectsOutputToContain('NOZ001')
            ->assertExitCode(1);
    }
}
