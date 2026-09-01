<?php

namespace Tests\Feature\Grok;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Avant : Permissions d'un rôle caissier vidables en un PUT vide.
 * L'écran disait OK, la caisse 403.
 */
class ProtectedRolePermissionsCannotBeWipedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_empty_permissions_on_pos_operator_is_422_and_keeps_pos(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $role = Role::query()->where('name', 'POS Operator')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($role);
        $pos = Permission::query()->where('name', 'pos')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($pos);
        $role->givePermissionTo($pos);

        $this->putJson('/api/admin/setting/permission/'.$role->id, [
            'permissions' => [],
        ])->assertStatus(422);

        $this->assertTrue($role->fresh()->hasPermissionTo('pos'));
    }

    public function test_removing_pos_from_pos_operator_is_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $role = Role::query()->where('name', 'POS Operator')->where('guard_name', 'sanctum')->first();
        $pos = Permission::query()->where('name', 'pos')->where('guard_name', 'sanctum')->first();
        $items = Permission::query()->where('name', 'items')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($items);
        $role->syncPermissions([$pos, $items]);

        $this->putJson('/api/admin/setting/permission/'.$role->id, [
            'permissions' => [$items->id],
        ])->assertStatus(422);

        $this->assertTrue($role->fresh()->hasPermissionTo('pos'));
    }

    public function test_dummy_permission_ids_do_not_wipe_chef(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $role = Role::query()->where('name', 'Chef')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($role);
        $kds = Permission::query()->where('name', 'kitchen-display-system')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($kds);
        $role->givePermissionTo($kds);

        $this->putJson('/api/admin/setting/permission/'.$role->id, [
            'permissions' => [999999],
        ])->assertStatus(422);

        $this->assertTrue($role->fresh()->hasPermissionTo('kitchen-display-system'));
    }

    public function test_removing_kds_from_chef_is_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $role = Role::query()->where('name', 'Chef')->where('guard_name', 'sanctum')->first();
        $kds = Permission::query()->where('name', 'kitchen-display-system')->where('guard_name', 'sanctum')->first();
        $dashboard = Permission::query()->where('name', 'dashboard')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($dashboard);
        $role->syncPermissions([$kds, $dashboard]);

        $this->putJson('/api/admin/setting/permission/'.$role->id, [
            'permissions' => [$dashboard->id],
        ])->assertStatus(422);

        $this->assertTrue($role->fresh()->hasPermissionTo('kitchen-display-system'));
    }

    public function test_removing_table_orders_from_waiter_is_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $role = Role::query()->firstOrCreate(['name' => 'Waiter', 'guard_name' => 'sanctum']);
        $tables = Permission::query()->firstOrCreate([
            'name' => 'table-orders',
            'guard_name' => 'sanctum',
        ], ['url' => 'table-orders']);
        $dashboard = Permission::query()->where('name', 'dashboard')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($tables);
        $role->syncPermissions([$tables, $dashboard]);

        $this->putJson('/api/admin/setting/permission/'.$role->id, [
            'permissions' => [$dashboard->id],
        ])->assertStatus(422);

        $this->assertTrue($role->fresh()->hasPermissionTo('table-orders'));
    }

    public function test_vue_save_aborts_when_permission_form_is_empty(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/settings/Role/RoleShowComponent.vue')
        );
        $this->assertStringContainsString('message.role.permissions_required', $src);
        $this->assertStringContainsString('this.form.length === 0', $src);
    }

    private function admin(): \App\Models\User
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }
}
