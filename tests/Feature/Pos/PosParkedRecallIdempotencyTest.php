<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\User;
use App\Services\PosParkedOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CAISSE-02] Parked-order recall must be idempotent: recall hard-deletes the row, so a lost
 * success response + a retried GET must STILL return the snapshot (served from the short-lived
 * cache) instead of a 404 that would permanently lose the parked ticket.
 */
class PosParkedRecallIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeOperator(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return [$branch, $user];
    }

    public function test_a_retried_recall_returns_the_snapshot_not_404(): void
    {
        [$branch, $user] = $this->makeOperator();
        $service = app(PosParkedOrderService::class);

        $parked = $service->park($user->id, $branch->id, ['items' => [], 'total' => 7.50], 'Table 4');
        $this->assertNotNull($parked->id);

        // First recall: returns the snapshot and hard-deletes the row.
        $first = $service->recall($user->id, $branch->id, $parked->id);
        $this->assertNotNull($first, 'first recall returns the snapshot');
        $this->assertSame($parked->id, $first->id);
        $this->assertDatabaseMissing('pos_parked_orders', ['id' => $parked->id]);

        // Retried GET (lost-ACK scenario) — MUST return the cached snapshot, not null/404.
        $second = $service->recall($user->id, $branch->id, $parked->id);
        $this->assertNotNull($second, 'CAISSE-02: retried recall must return the cached snapshot');
        $this->assertSame($parked->id, $second->id);
    }

    public function test_recall_of_a_never_parked_order_still_returns_null(): void
    {
        [$branch, $user] = $this->makeOperator();
        $service = app(PosParkedOrderService::class);

        $this->assertNull(
            $service->recall($user->id, $branch->id, 999999),
            'a never-parked id has no cached snapshot → null (404), as before'
        );
    }
}
