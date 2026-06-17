<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [Wave 1 P1 — POS-RED-04 2026-05-18] Cash drawer session ownership tightening.
 *
 * Defends against the cross-cashier vulnerability where cashier B (same branch
 * as cashier A) could close cashier A's open drawer with closing_amount=0,
 * mis-attributing variance + corrupting NF525 audit trail (wrong actor_user_id).
 *
 * Pre-fix behaviour: assertSessionVisibleToUser checked branch only -> 200 OK.
 * Post-fix behaviour: same-branch non-owner cashier -> 403.
 *
 * Manager override: a Branch Manager (or Admin) with
 * `cash.reconcile.variance.override` permission can still close any drawer
 * within their branch (legitimate use-case: cashier walked out without
 * closing). Audit trail captures the manager as `closed_by_user_id`.
 */
class CashDrawerSessionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashierA;
    protected User $cashierB;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->cashierA = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030410',
        ]);
        $this->cashierA->assignRole('POS Operator');

        $this->cashierB = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030411',
        ]);
        $this->cashierB->assignRole('POS Operator');

        $this->manager = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030412',
        ]);
        $this->manager->assignRole('Branch Manager');
    }

    private function openSessionForCashierA(float $opening = 100.00): CashDrawerSession
    {
        return app(CashDrawerService::class)
            ->openSession((int) $this->branch->id, (int) $this->cashierA->id, $opening);
    }

    /**
     * Test A — RED on current main: cashier B (same branch, non-owner)
     * closes cashier A's drawer. MUST be rejected (403) post-fix.
     */
    public function test_same_branch_non_owner_cashier_cannot_close_other_drawer(): void
    {
        $session = $this->openSessionForCashierA();

        $this->actingAs($this->cashierB, 'sanctum');

        // [prod-finale 2026-06-17] the cash-drawer session close route (routes/api.php:944-947) carries the
        // same frozen idempotency middleware and requires X-Idempotency-Key (live UI sends it).
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson(
                "/api/admin/pos/cash-drawer/sessions/{$session->id}/close",
                ['closing_amount' => 0]
            );

        $response->assertStatus(403);

        $this->assertSame(
            CashDrawerSession::STATUS_OPEN,
            $session->fresh()->status,
            'Cashier B must NOT be able to close cashier A\'s drawer.'
        );
    }

    /**
     * Test B — happy path: owner cashier A closes own drawer. 200 OK.
     */
    public function test_owner_cashier_can_close_own_drawer(): void
    {
        $session = $this->openSessionForCashierA(50.00);

        $this->actingAs($this->cashierA, 'sanctum');

        // [prod-finale 2026-06-17] the cash-drawer session close route (routes/api.php:944-947) carries the
        // same frozen idempotency middleware and requires X-Idempotency-Key (live UI sends it).
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson(
                "/api/admin/pos/cash-drawer/sessions/{$session->id}/close",
                ['closing_amount' => 50.00]
            );

        $response->assertStatus(200);
        $this->assertSame(
            CashDrawerSession::STATUS_CLOSED,
            $session->fresh()->status,
        );
    }

    /**
     * Test C — manager override: Branch Manager with
     * cash.reconcile.variance.override can close any drawer on same branch
     * (legitimate use-case: cashier left without closing).
     */
    public function test_branch_manager_can_close_any_drawer_on_branch(): void
    {
        $session = $this->openSessionForCashierA(75.00);

        $this->actingAs($this->manager, 'sanctum');

        // [prod-finale 2026-06-17] the cash-drawer session close route (routes/api.php:944-947) carries the
        // same frozen idempotency middleware and requires X-Idempotency-Key (live UI sends it).
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson(
                "/api/admin/pos/cash-drawer/sessions/{$session->id}/close",
                ['closing_amount' => 75.00]
            );

        $response->assertStatus(200);
        $this->assertSame(
            CashDrawerSession::STATUS_CLOSED,
            $session->fresh()->status,
        );
    }
}
