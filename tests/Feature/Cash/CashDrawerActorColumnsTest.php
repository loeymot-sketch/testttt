<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [Sprint H2 F-10 2026-05-17] cash_drawer_sessions must record actor IDs
 * (closed_by_user_id, reconciled_by_user_id) for forensic clarity.
 *
 * Audit HMAC chain (Sprint 1D / F-8) already proves WHO acted; these columns
 * make Z-report dispute investigations faster (direct SQL join vs HMAC chain
 * replay). CLAUDE.md NF525 §8.
 */
class CashDrawerActorColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutralise variance gate for these tests — we're exercising actor
        // column writes, not the gate logic (covered in CashVarianceGateTest).
        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
    }

    /** @test */
    public function test_close_session_populates_closed_by_user_id(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $service = app(CashDrawerService::class);
        $session = $service->openSession($branch->id, $cashier->id, 50.00);

        // Different user closes (manager scenario) — actor = manager, not opener.
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($manager);

        $closed = $service->closeSession($session->id, 80.00);

        $this->assertEquals(
            $manager->id,
            $closed->fresh()->closed_by_user_id,
            'closed_by_user_id must reflect the authenticated actor at close time'
        );
        $this->assertNotEquals(
            $cashier->id,
            $closed->fresh()->closed_by_user_id,
            'closed_by_user_id is the closer, not the opener'
        );
    }

    /** @test */
    public function test_reconcile_session_populates_reconciled_by_user_id(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $service = app(CashDrawerService::class);
        $session = $service->openSession($branch->id, $cashier->id, 50.00);
        $service->closeSession($session->id, 80.00);

        // Manager performs reconciliation.
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($manager);

        // Service signature: reconcileSession(int $sessionId, ?string $varianceReason = null, ?User $actor = null)
        // returns array{session, expected, variance}.
        $result = $service->reconcileSession($session->id);

        $this->assertEquals(
            $manager->id,
            $result['session']->fresh()->reconciled_by_user_id,
            'reconciled_by_user_id must reflect actor at reconciliation time'
        );
    }

    /** @test */
    public function test_columns_nullable_for_legacy_rows(): void
    {
        // Insert a row directly bypassing the service — legacy data must
        // remain compatible (column nullable, FK ON DELETE SET NULL).
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);

        $legacy = CashDrawerSession::create([
            'branch_id'         => $branch->id,
            'opened_by_user_id' => $cashier->id,
            'opened_at'         => now(),
            'opening_amount'    => 50.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);

        $this->assertNull($legacy->closed_by_user_id);
        $this->assertNull($legacy->reconciled_by_user_id);
    }
}
