<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [AUDIT-F-003 / Sub-task 3] HTTP endpoints — cash drawer SESSIONS.
 *
 * URL prefix: /api/admin/pos/cash-drawer/sessions/...
 * (Distinct du hardware /cash-drawer/open existant.)
 */
class CashDrawerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $cashierA;
    private User $cashierB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->cashierA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->cashierA->assignRole('POS Operator');
        $this->cashierA->givePermissionTo('pos');

        $this->cashierB = User::factory()->create(['branch_id' => $this->branchB->id]);
        $this->cashierB->assignRole('POS Operator');
        $this->cashierB->givePermissionTo('pos');

        // [Sprint 1D / F-4] Endpoint suite covers happy-path reconcile with
        // a +5€ variance designed to exercise the I3 arithmetic invariant.
        // Neutralise the variance gate here — dedicated coverage is in
        // tests/Feature/Cash/CashVarianceGateTest.php.
        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
    }

    public function test_open_session_endpoint_creates_session(): void
    {
        $response = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 100.50,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('data.opening_amount', 100.50);
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.branch_id', $this->branchA->id);

        $this->assertSame(1, CashDrawerSession::query()->count());
    }

    public function test_open_session_endpoint_rejects_unauthenticated(): void
    {
        $this->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 50])
            ->assertStatus(401);
    }

    public function test_open_session_endpoint_rejects_user_without_pos_permission(): void
    {
        $noPerm = User::factory()->create(['branch_id' => $this->branchA->id]);
        $noPerm->assignRole('Stuff');

        $this->actingAs($noPerm, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 50])
            ->assertStatus(403);
    }

    public function test_open_session_endpoint_validates_opening_amount(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => -1])
            ->assertStatus(422);

        $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [])
            ->assertStatus(422);
    }

    public function test_open_session_endpoint_returns_409_if_already_open(): void
    {
        // [prod-finale 2026-06-17] DISTINCT idempotency keys (fresh Str::uuid() per call): the 2nd open must
        // reach the controller's already-open guard to return 409. A reused key would replay the cached 201
        // of the 1st open and mask the conflict. Frozen middleware; live UI sends a fresh key per open.
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 100])
            ->assertCreated();

        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 50])
            ->assertStatus(409);
    }

    public function test_close_session_endpoint_marks_closed(): void
    {
        $session = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 100])
            ->json('data');

        $response = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$session['id']}/close", [
                'closing_amount' => 250.75,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'closed');
        $response->assertJsonPath('data.closing_amount', 250.75);
    }

    public function test_close_session_endpoint_404_unknown_session(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/99999/close', ['closing_amount' => 100])
            ->assertStatus(404);
    }

    public function test_reconcile_session_endpoint_computes_variance(): void
    {
        $session = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 100])
            ->json('data');

        // Inject one movement directly via DB (no auth flow needed for this aspect)
        CashMovement::query()->create([
            'cash_drawer_session_id' => $session['id'],
            'branch_id'              => $this->branchA->id,
            'type'                   => CashMovement::TYPE_ORDER_PAYMENT,
            'amount'                 => 25.00,
            'direction'              => CashMovement::DIRECTION_IN,
        ]);

        $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$session['id']}/close", ['closing_amount' => 130])
            ->assertOk();

        $reconcileResp = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$session['id']}/reconcile");

        $reconcileResp->assertOk();
        $reconcileResp->assertJsonPath('data.status', 'reconciled');
        $this->assertSame(125.0, (float) $reconcileResp->json('data.expected'));
        $this->assertSame(5.0, (float) $reconcileResp->json('data.variance'));
    }

    public function test_current_endpoint_returns_open_session(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 100])
            ->assertCreated();

        $current = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/admin/pos/cash-drawer/sessions/current');

        $current->assertOk();
        $current->assertJsonPath('data.status', 'open');
        $current->assertJsonPath('data.branch_id', $this->branchA->id);
    }

    public function test_current_endpoint_returns_null_when_no_open_session(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/admin/pos/cash-drawer/sessions/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_movements_endpoint_lists_session_movements(): void
    {
        $session = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 50])
            ->json('data');

        CashMovement::query()->create([
            'cash_drawer_session_id' => $session['id'],
            'branch_id'              => $this->branchA->id,
            'order_id'               => 42,
            'type'                   => CashMovement::TYPE_ORDER_PAYMENT,
            'amount'                 => 12.34,
            'direction'              => CashMovement::DIRECTION_IN,
            'notes'                  => 'sale receipt #42',
        ]);

        $resp = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/admin/pos/cash-drawer/sessions/{$session['id']}/movements");

        $resp->assertOk();
        $resp->assertJsonCount(1, 'data');
        $resp->assertJsonPath('data.0.amount', 12.34);
        $resp->assertJsonPath('data.0.direction', 'in');
        $resp->assertJsonPath('data.0.order_id', 42);
        $resp->assertJsonPath('data.0.notes', 'sale receipt #42');
    }

    public function test_branch_isolation_close_hides_other_branch_session(): void
    {
        // Cashier B opens a session on branch B
        $sessionB = $this->actingAs($this->cashierB, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 100])
            ->json('data');

        // Cashier A tries to close it — BranchScope makes the session invisible to A,
        // so they get 404 (info-leak protection: ne pas révéler l'existence du record
        // cross-branch). Cohérent avec le pattern existant dans BranchScopeTest.
        $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionB['id']}/close", ['closing_amount' => 100])
            ->assertStatus(404);
    }

    public function test_branch_isolation_movements_hides_other_branch_session(): void
    {
        $sessionB = $this->actingAs($this->cashierB, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 100])
            ->json('data');

        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson("/api/admin/pos/cash-drawer/sessions/{$sessionB['id']}/movements")
            ->assertStatus(404);
    }

    public function test_branch_isolation_current_returns_only_callers_branch_session(): void
    {
        // Cashier B has an open session on branch B
        $this->actingAs($this->cashierB, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 100])
            ->assertCreated();

        // Cashier A asking for /current should NOT see B's session
        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/admin/pos/cash-drawer/sessions/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    // =====================================================================
    // [Wave O / O-1 2026-05-20] Admin branch context heal — root-cause fix
    // =====================================================================
    //
    // Owner reported: admin (branch_id=0) clicks "Filiale Le Cayenne" in the
    // header dropdown, then "Caisse → Ouvrir la caisse" → backend rejects
    // with "Cannot open a cash drawer session without a branch context".
    //
    // Root cause: open() + current() read Auth::user()->branch_id directly,
    // never honoring the dropdown-selected branch sent via body/query.
    //
    // Heal: accept branch_id from request payload for global admin while
    // preserving cross-branch isolation for branch-bound staff.
    // =====================================================================

    public function test_admin_can_open_session_with_body_branch_id(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos');

        $response = $this->actingAs($admin, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 75.5,
                'branch_id'      => $this->branchA->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('data.branch_id', $this->branchA->id);
        $response->assertJsonPath('data.opening_amount', 75.5);
        $response->assertJsonPath('data.opened_by_user_id', $admin->id);

        // NF525: the session row carries the operated branch_id, not zero.
        $this->assertDatabaseHas('cash_drawer_sessions', [
            'branch_id'         => $this->branchA->id,
            'opened_by_user_id' => $admin->id,
            'opening_amount'    => 75.5,
            'status'            => 'open',
        ]);
    }

    public function test_admin_open_session_without_branch_id_returns_422(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos');

        // Regression guard for the original bug: admin with no body branch_id
        // still cannot open (must explicitly choose a branch).
        $response = $this->actingAs($admin, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 50.00,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', false);
    }

    public function test_admin_open_session_with_nonexistent_branch_returns_422(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos');

        $response = $this->actingAs($admin, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 50.00,
                'branch_id'      => 99999, // does not exist
            ]);

        $response->assertStatus(422);
        // Assert the `exists:branches,id` rule fired (advisor catch — guards
        // against a future change that silently bypasses the exists check).
        $response->assertJsonValidationErrors(['branch_id']);
    }

    public function test_cashier_cannot_open_session_against_other_branch(): void
    {
        // Cross-branch leak gate: cashier A (branch_id=A) sending body.branch_id=B
        // must be rejected. Existing behavior for body-less calls is preserved
        // separately by test_open_session_endpoint_creates_session above.
        $response = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 50.00,
                'branch_id'      => $this->branchB->id,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', false);
    }

    public function test_cashier_open_session_with_matching_body_branch_id_succeeds(): void
    {
        // Defensive equality path: staff sending their own branch_id explicitly
        // should still succeed (back-compat for any future UI that forwards it).
        $response = $this->actingAs($this->cashierA, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 50.00,
                'branch_id'      => $this->branchA->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.branch_id', $this->branchA->id);
    }

    public function test_admin_current_endpoint_with_branch_id_returns_session(): void
    {
        // The advisor's blind-spot guard: after admin opens with branch_id=A,
        // the very next thing the UI does is GET /current — must echo back the
        // just-opened session, not null. Without the heal on current(), the
        // dialog loops (sees null, re-prompts, hits 409 on next open).
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos');

        // Open against branch A.
        $opened = $this->actingAs($admin, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 50.00,
                'branch_id'      => $this->branchA->id,
            ])
            ->json('data');

        // Without the query, admin still gets null (no implicit branch).
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pos/cash-drawer/sessions/current')
            ->assertOk()
            ->assertJsonPath('data', null);

        // With ?branch_id=A, admin sees the open session.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pos/cash-drawer/sessions/current?branch_id='.$this->branchA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $opened['id'])
            ->assertJsonPath('data.branch_id', $this->branchA->id);
    }

    public function test_cashier_current_endpoint_ignores_cross_branch_query(): void
    {
        // Branch-bound staff cannot leverage ?branch_id to peek at another
        // branch's drawer — the scope is hard-pinned to their auth branch.
        $this->actingAs($this->cashierB, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => 100.00,
            ])
            ->assertCreated();

        // Cashier A tries to query branch B — must not see it.
        $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/admin/pos/cash-drawer/sessions/current?branch_id='.$this->branchB->id)
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
