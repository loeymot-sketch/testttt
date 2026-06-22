<?php

namespace Tests\Feature\Delivery;

use App\Models\Address;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR — Sub 5.3 sentinel]
 *
 * RBAC privilege escalation guard. Pre-heal, DeliveryBoyAddressController
 * applied `permission:delivery-boys_show` to ALL methods (index/store/update/
 * destroy/show). A role with read-only delivery-boy access could therefore
 * CREATE/UPDATE/DELETE addresses — privilege escalation.
 *
 * Heal: split permissions per HTTP verb mirroring DeliveryBoyController:
 *   - index/show → delivery-boys_show
 *   - store      → delivery-boys_create
 *   - update     → delivery-boys_edit
 *   - destroy    → delivery-boys_delete
 *
 * Permissions are seeded in PermissionTableSeeder.php:405-432.
 */
class DeliveryBoyAddressPermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    private function seedDeliveryBoyPermissions(): void
    {
        foreach (['delivery-boys_show', 'delivery-boys_create', 'delivery-boys_edit', 'delivery-boys_delete'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }
    }

    private function readOnlyRole(): Role
    {
        $this->seedDeliveryBoyPermissions();
        $role = Role::firstOrCreate(['name' => 'DeliveryReadOnly', 'guard_name' => 'sanctum']);
        $role->syncPermissions(['delivery-boys_show']);
        return $role;
    }

    public function test_read_only_role_cannot_create_address(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $role = $this->readOnlyRole();

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $actor->assignRole($role);

        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $driver = User::factory()->create(['branch_id' => $branch->id]);
        $driver->assignRole('Delivery Boy');

        $resp = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/admin/delivery-boy/address/' . $driver->id, [
                'label' => 'Home',
                'address' => '10 Rue Test',
                'latitude' => '48.8566',
                'longitude' => '2.3522',
            ]);

        // Without the heal: 200/201 (silent privilege escalation).
        // With the heal: 403 because delivery-boys_create is missing.
        $resp->assertStatus(403);
    }

    public function test_read_only_role_cannot_update_address(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $role = $this->readOnlyRole();

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $actor->assignRole($role);

        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $driver = User::factory()->create(['branch_id' => $branch->id]);
        $driver->assignRole('Delivery Boy');
        $address = Address::query()->create([
            'user_id' => $driver->id,
            'label' => 'OldLabel',
            'address' => '10 Rue Old',
            'latitude' => '48.0',
            'longitude' => '2.0',
        ]);

        $resp = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/admin/delivery-boy/address/' . $driver->id . '/' . $address->id, [
                'label' => 'NewLabel',
                'address' => '20 Rue New',
                'latitude' => '49.0',
                'longitude' => '3.0',
            ]);

        $resp->assertStatus(403);
        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'label' => 'OldLabel',
        ]);
    }

    public function test_read_only_role_cannot_delete_address(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $role = $this->readOnlyRole();

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $actor->assignRole($role);

        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $driver = User::factory()->create(['branch_id' => $branch->id]);
        $driver->assignRole('Delivery Boy');
        $address = Address::query()->create([
            'user_id' => $driver->id,
            'label' => 'Home',
            'address' => '10 Rue Test',
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);

        $resp = $this->actingAs($actor, 'sanctum')
            ->deleteJson('/api/admin/delivery-boy/address/' . $driver->id . '/' . $address->id);

        $resp->assertStatus(403);
        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
    }

    public function test_read_only_role_can_still_read(): void
    {
        // Symmetric assertion: the permission split must NOT break the
        // legitimate read-only access. delivery-boys_show alone permits
        // index() + show() — those still respond OK.
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $role = $this->readOnlyRole();

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $actor->assignRole($role);

        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $driver = User::factory()->create(['branch_id' => $branch->id]);
        $driver->assignRole('Delivery Boy');

        $resp = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/admin/delivery-boy/address/' . $driver->id);

        $resp->assertOk();
    }
}
