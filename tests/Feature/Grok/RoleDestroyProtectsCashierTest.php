<?php

namespace Tests\Feature\Grok;

use App\Enums\Role as RoleEnum;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Gestes commerçant : supprimer un rôle depuis Réglages → Rôles.
 * Avant : seuls les ids 1–5 étaient protégés. « POS Operator » (id 7)
 * pouvait disparaître — le caissier n'ouvrait plus la caisse.
 */
class RoleDestroyProtectsCashierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_pos_operator_role_cannot_be_deleted(): void
    {
        $role = Role::query()->where('name', 'POS Operator')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($role);

        $this->expectException(\Exception::class);

        app(RoleService::class)->destroy($role);

        $this->assertNotNull(Role::query()->find($role->id));
    }

    public function test_custom_role_can_be_deleted(): void
    {
        $role = Role::create(['name' => 'Stagiaire midi', 'guard_name' => 'sanctum']);

        app(RoleService::class)->destroy($role);

        $this->assertNull(Role::query()->find($role->id));
    }
}
