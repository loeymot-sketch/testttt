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

    /**
     * [GAP-ORPHAN 2026-06-25] Point aveugle : une vente numérotée créée dans le TROU
     * entre un Z fermé et le PROCHAIN Z ouvert n'est dans AUCUN Z (le Z suivant
     * n'agrège que depuis SON opened_at, fenêtre (opened_at, closed_at]). Le
     * détecteur la ratait (`continue` → faux-vert). Il doit la flaguer.
     */
    public function test_order_in_gap_between_closed_Z_and_next_open_Z_is_flagged(): void
    {
        $branch = Branch::factory()->create();
        // Z fermé jour 1 : 08:00 → 20:00
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => Carbon::parse('2026-05-01 08:00:00'),
            'closed_at'   => Carbon::parse('2026-05-01 20:00:00'),
            'status'      => ZReport::STATUS_CLOSED,
        ]);
        // Z ouvert jour 2 08:00 → TROU entre jour1 20:00 et jour2 08:00.
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 2,
            'opened_at'   => Carbon::parse('2026-05-02 08:00:00'),
            'closed_at'   => null,
            'status'      => ZReport::STATUS_OPEN,
        ]);
        // Vente payée+numérotée créée dans le TROU (jour1 22:00) → dans aucun Z.
        $order = Order::factory()->create([
            'branch_id'       => $branch->id,
            'status'          => OrderStatus::ACCEPT,
            'payment_status'  => PaymentStatus::PAID,
            'total'           => 13.00,
            'order_serial_no' => 'GAP777',
            'created_at'      => Carbon::parse('2026-05-01 22:00:00'),
        ]);
        $this->numberOrder($order->id, 7, Carbon::parse('2026-05-01 22:01:00'));

        $this->artisan('fiscal:verify-z-membership', ['--branch' => $branch->id])
            ->expectsOutputToContain('GAP777')
            ->assertExitCode(1);
    }
}
