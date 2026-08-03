<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use App\Services\OrderStatusScreenOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [KDS-SCHEDULED-NO-UPPER-BOUND 2026-07-22] A scheduled order that is a no-show
 * (never bumped / delivered / canceled) used to squat the KDS + OSS board FOR
 * LIFE: applyScheduledBoardFilter admitted `scheduled_at <= now + lead` with no
 * lower bound, so a past target time stayed admitted forever.
 *
 * Fix (SSOT KitchenReleaseRule::applyScheduledBoardFilter): add a grace floor
 * `scheduled_at >= now - kds.scheduled_grace_hours`. Beyond that the order leaves
 * the ACTIVE board (KDS + OSS + sync, all sharing the SSOT).
 *
 * We deliberately do NOT floor the bump guard (orderIsWithinScheduledWindow): the
 * load-bearing "visible ⟹ bumpable" half of the doctrine still holds (everything
 * the board shows is inside the guard window); the only new divergence is benign
 * (invisible-but-technically-bumpable abandoned order), never the dangerous
 * "visible-but-not-bumpable".
 */
class KdsScheduledUpperBoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        // Pin lead + grace so the contract does not depend on a local .env.
        config(['kds.scheduled_lead_minutes' => 20]);
        config(['kds.scheduled_grace_hours' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freezeAt(string $parisTime): void
    {
        $now = CarbonImmutable::parse($parisTime, 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }

    private function makeBoardOrder(Branch $branch, ?Carbon $scheduledAt, int $status = OrderStatus::ACCEPT, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => $status,
            'payment_status'   => PaymentStatus::PAID,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at'     => $scheduledAt,
        ], $overrides));
    }

    private function kdsListIds(int $branchId): array
    {
        return app(KitchenDisplaySystemOrderService::class)
            ->list(new Request(['branch_id' => $branchId]))
            ->pluck('id')
            ->all();
    }

    private function ossListIds(int $branchId): array
    {
        return app(OrderStatusScreenOrderService::class)
            ->listForBranch($branchId)
            ->pluck('id')
            ->all();
    }

    /** @test */
    public function a_no_show_scheduled_order_leaves_the_board_past_the_grace_window(): void
    {
        // now 15:00, grace 2h → floor 13:00. A 12:00 target is a no-show (3h stale).
        $this->freezeAt('2026-03-10 15:00:00');
        $branch = Branch::factory()->create();
        $this->actingAs($this->admin());

        $noShow = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 12:00:00', 'Europe/Paris'));
        $asap   = $this->makeBoardOrder($branch, null);

        $ids = $this->kdsListIds($branch->id);

        $this->assertNotContains($noShow->id, $ids,
            'KDS : une programmée no-show (cible 12:00, now 15:00, grâce 2h) DOIT quitter le board actif.');
        $this->assertContains($asap->id, $ids,
            'KDS : l\'ASAP (scheduled_at NULL) reste toujours visible — borne inapplicable.');
    }

    /** @test */
    public function a_late_but_within_grace_scheduled_order_stays_on_the_board(): void
    {
        // now 15:00, grace 2h → floor 13:00. A 14:00 target is 1h late but WITHIN grace.
        $this->freezeAt('2026-03-10 15:00:00');
        $branch = Branch::factory()->create();
        $this->actingAs($this->admin());

        $late = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 14:00:00', 'Europe/Paris'));

        $this->assertContains($late->id, $this->kdsListIds($branch->id),
            'KDS : une programmée en retard mais DANS la grâce (cible 14:00, floor 13:00) reste sur le board.');
    }

    /** @test */
    public function grace_floor_boundary_is_inclusive(): void
    {
        // Target EXACTLY at the floor (now - grace) → still admitted (>= inclusive).
        $this->freezeAt('2026-03-10 15:00:00');
        $branch = Branch::factory()->create();
        $this->actingAs($this->admin());

        $atFloor = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'));

        $this->assertContains($atFloor->id, $this->kdsListIds($branch->id),
            'KDS : cible pile au plancher (13:00 = now-grâce) DOIT rester visible (>= inclus).');
    }

    /** @test */
    public function the_upper_bound_also_applies_to_the_oss_board_ssot_parity(): void
    {
        // The floor lives in the shared KitchenReleaseRule SSOT → OSS gets it too.
        // OSS shows PREPARING/PREPARED only (ACCEPT never reaches the customer
        // wall), so seed both in a wall-visible status to isolate the floor.
        $this->freezeAt('2026-03-10 15:00:00');
        $branch = Branch::factory()->create();
        $this->actingAs($this->admin());

        // queue_number set — the OSS wall's "zombie" guard shows only orders with
        // a visible identifier (queue_number OR token).
        $noShow = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 12:00:00', 'Europe/Paris'), OrderStatus::PREPARING, ['queue_number' => 'W101']);
        $late   = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 14:00:00', 'Europe/Paris'), OrderStatus::PREPARING, ['queue_number' => 'W102']);

        $ids = $this->ossListIds($branch->id);

        $this->assertNotContains($noShow->id, $ids,
            'OSS : le no-show past-grâce quitte aussi le mur (parité SSOT board).');
        $this->assertContains($late->id, $ids,
            'OSS : la programmée en retard dans la grâce reste sur le mur.');
    }
}
