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
 * [P0 #1 detect-only — owner decision 2026-05-29] Read-only Z-membership detector.
 * Flags numbered, settled, non-terminal orders whose created_at is in an already-
 * CLOSED Z window but which were sealed/modified AFTER that Z closed — the
 * cross-Z-window settlement orphan class (a numbered receipt in no signed Z).
 */
class VerifyZMembershipCommandTest extends TestCase
{
    use RefreshDatabase;

    private function sealedZ(int $branchId): array
    {
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        ZReport::create([
            'branch_id'   => $branchId,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
        return [$opened, $closed];
    }

    // Set seq + updated_at via query builder (bypasses mass-assignment guard + the
    // timestamp bump a normal ->save() would cause).
    private function numberOrder(int $id, int $seq, Carbon $updatedAt): void
    {
        Order::withoutGlobalScopes()->where('id', $id)
            ->update(['fiscal_sequence_no' => $seq, 'updated_at' => $updatedAt]);
    }

    public function test_order_sealed_inside_its_open_window_is_not_flagged(): void
    {
        $branch = Branch::factory()->create();
        [$opened, $closed] = $this->sealedZ($branch->id);

        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 50.00,
            'created_at'     => $opened->copy()->addHours(2),  // 10:00, in window
        ]);
        // Sealed at 10:05 — BEFORE the Z closed → legitimately in that Z.
        $this->numberOrder($order->id, 5, $opened->copy()->addHours(2)->addMinutes(5));

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->assertExitCode(0);
    }

    public function test_cross_window_settled_order_is_flagged(): void
    {
        $branch = Branch::factory()->create();
        [$opened, $closed] = $this->sealedZ($branch->id);

        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 50.00,
            'order_serial_no' => 'A9999',
            'created_at'     => $opened->copy()->addHours(2),  // 10:00, in window
        ]);
        // Numbered the NEXT day — AFTER the Z closed → orphan (no Z contains it).
        $this->numberOrder($order->id, 5, $closed->copy()->addDay());

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->expectsOutputToContain('A9999')
            ->assertExitCode(1);
    }
}
