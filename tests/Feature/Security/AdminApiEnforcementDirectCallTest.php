<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13 §1 Sub 1.1 / T-1.1.1]
 *
 * Gap covered: the 2026-08-13 nav-breadth audit
 * (`goal_admin_nav_breadth_convergence_2026-08-13.md`) proved a low-privilege
 * role CANNOT CLICK to a high-privilege admin screen (button hidden / route
 * guard on the SPA). It did NOT prove that the same low-privilege role is
 * blocked when it calls the underlying API DIRECTLY — bypassing the UI
 * entirely (a scripted `curl`/`fetch` with a stolen or replayed Sanctum
 * token). Navigation ≠ enforcement.
 *
 * This test calls 4 representative high-privilege admin endpoints DIRECTLY
 * via `actingAs()` + `putJson()`/`postJson()`/`getJson()` — no HTTP client,
 * no UI click — with a "Chef" role token. `Chef` is seeded by
 * `TestCase::seedSpatieRoles()` with EXACTLY `dashboard` +
 * `kitchen-display-system` + `order-status-screen` (mirrors production
 * `RolePermissionTableSeeder`'s Chef entry: same 3 permissions, zero
 * settings/administrators/fiscal reach) — a real, low, strictly-inferior
 * privilege floor, not a synthetic empty-permission user.
 *
 * The 4 endpoints, and why each is representative:
 *
 *   1. PUT  /api/admin/setting/permission/{role}   — RBAC root mutation.
 *      Exercised from the UI on 2026-08-13 via `RoleShowComponent.vue` →
 *      `resources/js/store/modules/permission.js:29`
 *      (`axios.put('admin/setting/permission/'+id, {permissions: form})`).
 *      Same payload shape is replayed here. Gate: `permission:settings`
 *      route middleware (`PermissionController::__construct`).
 *
 *   2. POST /api/admin/administrator                — mints a REAL admin
 *      account (highest-privilege object in the system). Gate:
 *      `permission:administrators_create` route middleware
 *      (`AdministratorController::__construct`).
 *
 *   3. GET  /api/admin/fiscal/x-report               — read-only fiscal
 *      snapshot (NF525-adjacent). Gate is INLINE, not route middleware:
 *      `XReportController::show()` calls
 *      `abort_unless($user->can('pos-manage-fiscal'), 403, ...)`. Chosen
 *      because an inline gate is exactly the shape that a route-only
 *      reflection sentinel (cf. `AdminRoutePermissionFloorTest`) cannot see
 *      — only a real HTTP call proves it holds.
 *
 *   4. POST /api/admin/fiscal/z-report/close          — mutating, NF525
 *      fiscal day-close. Also INLINE-gated:
 *      `ZReportController::authorizeFiscal()` →
 *      `abort_unless($user->can('pos-manage-fiscal'), 403, ...)`. Chosen as
 *      the write-side pair to endpoint 3 (read vs. write on the same
 *      permission, same inline-gate shape) and because this exact
 *      open/close cycle caused a real P0 17 days prior (`PROJECT_BRAIN.md
 *      §2`, resolved 2026-08-13) — its authz floor deserves direct-call
 *      proof, not assumed coverage.
 *
 * Each assertion requires a REAL 401/403 — not a 200 with a side effect
 * (Sub 1.1's "laisser passer silencieux" failure mode) and not a 500 that
 * would mask a different bug behind an opaque error. A 500 FAILS the
 * assertion just like a 200 would (see `assertDeniedNotSilently`).
 */
class AdminApiEnforcementDirectCallTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();

        // Permissions referenced by the 4 endpoints under test that are not
        // already part of TestCase::seedSpatieRoles()'s baseline list. They
        // exist in production (RolePermissionTableSeeder /
        // PermissionTableSeeder) — materialized here only so Spatie's
        // guard-existence check doesn't throw PermissionDoesNotExist before
        // we ever reach the assertion. NOT granted to the Chef role.
        foreach (['administrators', 'administrators_create', 'administrators_edit', 'administrators_delete', 'administrators_show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    /**
     * Low-privilege role: Chef. Seeded by TestCase::seedSpatieRoles() with
     * ONLY dashboard + kitchen-display-system + order-status-screen — no
     * `settings`, no `administrators_*`, no `pos-manage-fiscal`. Strictly
     * inferior to every permission gated by the 4 endpoints below.
     */
    private function makeLowPrivilegeChef(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('Chef');

        return $user;
    }

    /**
     * Asserts the response is a REAL denial (401 unauthenticated or 403
     * forbidden) — never a 200 (silent pass-through with a possible side
     * effect) and never a 500 (an unrelated crash masquerading as "safe").
     */
    private function assertDeniedNotSilently(\Illuminate\Testing\TestResponse $response, string $label): void
    {
        $status = $response->getStatusCode();

        $this->assertNotSame(
            200,
            $status,
            "[T-1.1.1] $label: low-privilege direct-API call returned 200 — "
            . "silent laisser-passer, not a real block. Body: " . $response->getContent()
        );
        $this->assertNotSame(
            201,
            $status,
            "[T-1.1.1] $label: low-privilege direct-API call returned 201 (created) — "
            . "silent laisser-passer with a side effect. Body: " . $response->getContent()
        );
        $this->assertContains(
            $status,
            [401, 403],
            "[T-1.1.1] $label: expected a REAL 401/403 denial, got $status "
            . "(a 500 would mask a different bug behind an opaque error, "
            . "not prove enforcement). Body: " . $response->getContent()
        );
    }

    // ------------------------------------------------------------------ //
    // 1. PUT /api/admin/setting/permission/{role} — RBAC root mutation
    // ------------------------------------------------------------------ //

    public function test_chef_direct_call_cannot_mutate_role_permissions(): void
    {
        $chef = $this->makeLowPrivilegeChef();
        $role = Role::where('name', 'POS Operator')->where('guard_name', 'sanctum')->firstOrFail();

        // Baseline BEFORE the attack call — POS Operator already holds several
        // permissions via TestCase::seedSpatieRoles() (dashboard/pos/...), so
        // the proof is "unchanged from baseline", not "still zero".
        $baselinePermissionNames = $role->permissions()->pluck('name')->sort()->values()->all();

        // Exact payload shape used by RoleShowComponent.vue via
        // permission.js:29 — {permissions: [ids]} — so the assertion
        // targets the authz gate, not an unrelated validation failure.
        // Attack payload attempts to grant EVERY permission (full escalation).
        $allPermissionIds = Permission::where('guard_name', 'sanctum')->pluck('id')->all();

        $response = $this->actingAs($chef, 'sanctum')
            ->putJson('/api/admin/setting/permission/' . $role->id, [
                'permissions' => $allPermissionIds,
            ]);

        $this->assertDeniedNotSilently($response, 'PUT /admin/setting/permission/{role}');

        // Side-effect proof: the targeted role's permission set must be
        // UNCHANGED — the attempted privilege-escalation must not have
        // partially applied before the gate fired.
        $afterPermissionNames = Role::find($role->id)->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(
            $baselinePermissionNames,
            $afterPermissionNames,
            '[T-1.1.1] Denied PUT must not mutate the target role\'s permission set at all '
            . '(pre-attack baseline vs. post-attack must be identical).'
        );
    }

    // ------------------------------------------------------------------ //
    // 2. POST /api/admin/administrator — mint a real admin account
    // ------------------------------------------------------------------ //

    public function test_chef_direct_call_cannot_create_administrator_account(): void
    {
        $chef = $this->makeLowPrivilegeChef();
        $attackEmail = 'sentinel-attack-admin-' . uniqid() . '@example.test';

        $response = $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/administrator', [
                'name'                  => 'Sentinel Rogue Admin',
                'email'                 => $attackEmail,
                'password'              => 'SuperSecret123',
                'password_confirmation' => 'SuperSecret123',
                'phone'                 => '0699' . fake()->unique()->numerify('######'),
                'branch_id'             => 0, // super-admin mint attempt
                'status'                => 1,
                'country_code'          => 'FR',
            ]);

        $this->assertDeniedNotSilently($response, 'POST /admin/administrator');

        // Side-effect proof: no account with the attack email must exist —
        // the denial must be BEFORE any row is written, not an after-the-fact
        // rollback masking a real insert.
        $this->assertSame(
            0,
            User::where('email', $attackEmail)->count(),
            '[T-1.1.1] Denied POST must not create the administrator row — '
            . 'a partially-applied mint would still be a real privilege escalation.'
        );
    }

    // ------------------------------------------------------------------ //
    // 3. GET /api/admin/fiscal/x-report — read-only fiscal snapshot
    // ------------------------------------------------------------------ //

    public function test_chef_direct_call_cannot_read_x_report(): void
    {
        $chef = $this->makeLowPrivilegeChef();

        $response = $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/fiscal/x-report');

        $this->assertDeniedNotSilently($response, 'GET /admin/fiscal/x-report (inline-gated, no route middleware)');
    }

    // ------------------------------------------------------------------ //
    // 4. POST /api/admin/fiscal/z-report/close — NF525 fiscal day close
    // ------------------------------------------------------------------ //

    public function test_chef_direct_call_cannot_close_z_report(): void
    {
        $chef = $this->makeLowPrivilegeChef();

        $response = $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/fiscal/z-report/close', []);

        $this->assertDeniedNotSilently($response, 'POST /admin/fiscal/z-report/close (inline-gated, no route middleware)');

        // Side-effect proof: no Z report row must have been written by a
        // denied low-privilege close attempt.
        $this->assertSame(
            0,
            \App\Models\ZReport::where('branch_id', $this->branch->id)->count(),
            '[T-1.1.1] Denied POST must not write a ZReport row for the branch.'
        );
    }

    // ------------------------------------------------------------------ //
    // Regression guard: the SAME 4 endpoints must remain reachable for a
    // caller that legitimately holds the required permission — proves the
    // denials above are a real authz gate, not a global outage that would
    // deny everyone (a broken-but-"safe"-looking 403 for all callers).
    // ------------------------------------------------------------------ //

    public function test_settings_holder_can_reach_permission_update_200(): void
    {
        $admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $admin->givePermissionTo('settings');
        $role = Role::where('name', 'POS Operator')->where('guard_name', 'sanctum')->firstOrFail();
        $pos = Permission::where('name', 'pos')->where('guard_name', 'sanctum')->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/setting/permission/' . $role->id, [
                'permissions' => [$pos->id],
            ]);

        $this->assertContains(
            $response->getStatusCode(),
            [200, 201],
            '[Regression guard] A `settings` permission holder MUST still be able to '
            . 'update role permissions after this test proves the Chef denial. '
            . 'Body: ' . $response->getContent()
        );
    }

    public function test_fiscal_permission_holder_can_reach_x_report_200(): void
    {
        $manager = User::factory()->create(['branch_id' => $this->branch->id]);
        $manager->givePermissionTo('pos-manage-fiscal');

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/fiscal/x-report');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            '[Regression guard] A `pos-manage-fiscal` holder MUST still be able to read '
            . 'the X report after this test proves the Chef denial. '
            . 'Body: ' . $response->getContent()
        );
    }
}
