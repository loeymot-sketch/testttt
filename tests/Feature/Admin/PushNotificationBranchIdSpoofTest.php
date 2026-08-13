<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] NC-03 re-verify (originally
 * flagged plans/GOAL_MGMT_TESTPLAN_2026-06-01_APPENDIX_full-map.md:537-539).
 *
 * [Foundation F-8-RED-001 2026-05-18] hardened the FAN-OUT side (a stored
 * branch_id=0 push is fanned out globally, a stored branch_id=N push stays
 * scoped to N) but never hardened the INPUT side: PushNotificationService::
 * store() persisted `$request->branch_id` verbatim. A branch-scoped
 * (branch_id>0) staff member holding only push-notifications_create could
 * submit branch_id=0 and force a global broadcast to every branch's staff —
 * exactly the tenant-isolation break F-8-RED-001 was meant to close.
 */
class PushNotificationBranchIdSpoofTest extends TestCase
{
    use RefreshDatabase;

    private function branchScopedActor(): User
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        foreach (['push-notifications', 'push-notifications_create'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }
        $role = Role::firstOrCreate(['name' => 'BranchPushSender', 'guard_name' => 'sanctum']);
        $role->syncPermissions(['push-notifications', 'push-notifications_create']);

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $actor->assignRole($role);

        return $actor;
    }

    public function test_branch_scoped_staff_cannot_spoof_branch_id_zero_for_global_broadcast(): void
    {
        $actor = $this->branchScopedActor();

        $resp = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/admin/push-notification', [
                'title' => 'Fermeture exceptionnelle',
                'description' => 'Test',
                'branch_id' => 0,
            ]);

        $resp->assertStatus(201);
        $this->assertDatabaseHas('push_notifications', [
            'title' => 'Fermeture exceptionnelle',
            // Pre-fix: branch_id persisted as the spoofed 0 (global).
            // Post-fix: server forces it back to the caller's own branch.
            'branch_id' => $actor->branch_id,
        ]);
        $this->assertDatabaseMissing('push_notifications', [
            'title' => 'Fermeture exceptionnelle',
            'branch_id' => 0,
        ]);
    }

    public function test_admin_can_still_choose_branch_id_zero_for_a_real_global_broadcast(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        foreach (['push-notifications', 'push-notifications_create'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }
        $adminRole = Role::where('name', 'Admin')->where('guard_name', 'sanctum')->first();
        $adminRole->givePermissionTo(['push-notifications', 'push-notifications_create']);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $resp = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/push-notification', [
                'title' => 'Annonce globale',
                'description' => 'Test',
                'branch_id' => 0,
            ]);

        $resp->assertStatus(201);
        $this->assertDatabaseHas('push_notifications', [
            'title' => 'Annonce globale',
            'branch_id' => 0,
        ]);
    }
}
