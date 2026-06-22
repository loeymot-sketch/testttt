<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * [Sprint H2 F-11 2026-05-17] Manager-gate for routine cash drawer close.
 *
 * When config('cash.manager_gate_routine_close') is true, ANY close
 * (variance OR no-variance) requires the actor to hold the same
 * `cash.reconcile.variance.override` permission used by the Sprint 1D
 * variance gate. Single-cashier deploys keep the flag false (default)
 * so behavior is unchanged — a POS Operator can self-close their drawer
 * at end of shift.
 *
 * Multi-cashier / SaaS deploys set CASH_MANAGER_GATE_ROUTINE_CLOSE=true
 * to enforce a second-pair-of-eyes audit for ALL closes.
 *
 * Roles wired via tests/TestCase::seedSpatieRoles():
 *   - POS Operator → no variance permission (cashier scenario)
 *   - Branch Manager → variance permission (manager scenario)
 *
 * Pairs with Sprint H2 F-10 actor columns to track WHO closed via
 * BOTH cash_drawer_sessions.closed_by_user_id AND audit_logs HMAC chain.
 *
 * CLAUDE.md §9 (multi-tenant + auth invariants).
 */
class ManagerGateRoutineCloseTest extends TestCase
{
    use RefreshDatabase;

    private CashDrawerService $service;
    private Branch $branch;
    private User $cashier;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        $this->service = new CashDrawerService();
        $this->branch = Branch::factory()->create();

        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');

        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager->assignRole('Branch Manager');

        // Default permission name aligned with config + seeder.
        Config::set('cash.variance_override_permission', 'cash.reconcile.variance.override');
    }

    /**
     * Gate OFF (default): cashier self-closes own drawer at end of shift.
     * Single-cashier deploys must keep this path open — no permission required.
     */
    public function test_routine_close_allowed_when_gate_off_default(): void
    {
        Config::set('cash.manager_gate_routine_close', false);

        $this->actingAs($this->cashier);
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 50.00);

        $closed = $this->service->closeSession($session->id, 50.00);

        $this->assertSame(
            CashDrawerSession::STATUS_CLOSED,
            $closed->fresh()->status,
            'Cashier must be able to close own drawer when gate is off (default).'
        );
    }

    /**
     * Gate ON + actor lacks permission → 403.
     * POS Operator (cashier) does not hold cash.reconcile.variance.override.
     */
    public function test_routine_close_blocked_when_gate_on_and_actor_lacks_permission(): void
    {
        Config::set('cash.manager_gate_routine_close', true);

        $this->actingAs($this->cashier);
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 50.00);

        try {
            $this->service->closeSession($session->id, 50.00);
            $this->fail('Expected HttpException 403 — manager gate must block cashier.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString(
                'cash.reconcile.variance.override',
                $e->getMessage(),
                'Error message must surface the missing permission for operator clarity.'
            );
        }

        // Session must stay OPEN — close was refused, no partial transition.
        $this->assertSame(
            CashDrawerSession::STATUS_OPEN,
            $session->fresh()->status,
            'Refused close must NOT mutate session state.'
        );
    }

    /**
     * Gate ON + manager holds permission → close succeeds.
     * Branch Manager carries cash.reconcile.variance.override via seedSpatieRoles().
     */
    public function test_routine_close_allowed_when_gate_on_and_actor_has_permission(): void
    {
        Config::set('cash.manager_gate_routine_close', true);

        // Cashier opens.
        $this->actingAs($this->cashier);
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 50.00);

        // Manager closes.
        $this->actingAs($this->manager);
        $closed = $this->service->closeSession($session->id, 50.00);

        $this->assertSame(
            CashDrawerSession::STATUS_CLOSED,
            $closed->fresh()->status,
            'Manager-permission holder must be allowed to close even when gate is on.'
        );
        // [Sprint H2 F-10 cross-check] closed_by_user_id reflects the manager,
        // not the cashier-opener — proves the gate runs WITH the actor context.
        $this->assertSame(
            $this->manager->id,
            $closed->fresh()->closed_by_user_id,
            'closed_by_user_id must reflect manager who actually pressed close.'
        );
    }
}
