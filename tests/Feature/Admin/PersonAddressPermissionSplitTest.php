<?php

namespace Tests\Feature\Admin;

use App\Models\Address;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] Mirrors
 * DeliveryBoyAddressPermissionSplitTest for the 5 sibling person-type Address
 * controllers. DeliveryBoyAddressController got the read/write permission
 * split in [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR-Z4-SEC-01 P1] — every
 * other *AddressController (Administrator/Employee/Chef/Waiter/Customer)
 * still gated ALL methods (including store/update/destroy) behind the single
 * `{type}_show` permission, so a role holding only read access could mutate
 * addresses. Forgotten twin, same shape as the .env-injection gap fixed
 * earlier in this GOAL for Mail/License siblings of CompanyRequest.
 */
class PersonAddressPermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    public static function personTypeProvider(): array
    {
        return [
            'administrator' => ['administrator', 'administrators'],
            'employee' => ['employee', 'employees'],
            'chef' => ['chef', 'chefs'],
            'waiter' => ['waiter', 'waiters'],
            'customer' => ['customer', 'customers'],
        ];
    }

    private function seedPermissions(string $permBase): void
    {
        foreach (["{$permBase}_show", "{$permBase}_create", "{$permBase}_edit", "{$permBase}_delete"] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }
    }

    private function readOnlyActor(string $permBase): User
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->seedPermissions($permBase);

        $role = Role::firstOrCreate(['name' => "ReadOnly_{$permBase}", 'guard_name' => 'sanctum']);
        $role->syncPermissions(["{$permBase}_show"]);

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $actor->assignRole($role);

        return $actor;
    }

    /**
     * @dataProvider personTypeProvider
     */
    public function test_read_only_role_cannot_create_address(string $routeSegment, string $permBase): void
    {
        $actor = $this->readOnlyActor($permBase);
        $target = User::factory()->create(['branch_id' => $actor->branch_id]);

        $resp = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/admin/{$routeSegment}/address/{$target->id}", [
                'label' => 'Home',
                'address' => '10 Rue Test',
                'latitude' => '48.8566',
                'longitude' => '2.3522',
            ]);

        // Pre-fix: 200/201 (silent privilege escalation, same gap as
        // DeliveryBoyAddressController before its 2026-05-18 heal).
        $resp->assertStatus(403);
    }

    /**
     * @dataProvider personTypeProvider
     */
    public function test_read_only_role_cannot_update_address(string $routeSegment, string $permBase): void
    {
        $actor = $this->readOnlyActor($permBase);
        $target = User::factory()->create(['branch_id' => $actor->branch_id]);
        $address = Address::query()->create([
            'user_id' => $target->id,
            'label' => 'OldLabel',
            'address' => '10 Rue Old',
            'latitude' => '48.0',
            'longitude' => '2.0',
        ]);

        $resp = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/admin/{$routeSegment}/address/{$target->id}/{$address->id}", [
                'label' => 'NewLabel',
                'address' => '20 Rue New',
                'latitude' => '49.0',
                'longitude' => '3.0',
            ]);

        $resp->assertStatus(403);
        $this->assertDatabaseHas('addresses', ['id' => $address->id, 'label' => 'OldLabel']);
    }

    /**
     * @dataProvider personTypeProvider
     */
    public function test_read_only_role_cannot_delete_address(string $routeSegment, string $permBase): void
    {
        $actor = $this->readOnlyActor($permBase);
        $target = User::factory()->create(['branch_id' => $actor->branch_id]);
        $address = Address::query()->create([
            'user_id' => $target->id,
            'label' => 'Home',
            'address' => '10 Rue Test',
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);

        $resp = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/admin/{$routeSegment}/address/{$target->id}/{$address->id}");

        $resp->assertStatus(403);
        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
    }

    /**
     * @dataProvider personTypeProvider
     */
    public function test_read_only_role_can_still_read(string $routeSegment, string $permBase): void
    {
        $actor = $this->readOnlyActor($permBase);
        $target = User::factory()->create(['branch_id' => $actor->branch_id]);

        $resp = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/admin/{$routeSegment}/address/{$target->id}");

        $resp->assertOk();
    }
}
