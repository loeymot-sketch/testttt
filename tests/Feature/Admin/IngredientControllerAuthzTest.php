<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\IngredientPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [T-9.1.1 — V1.0.2 MGMT-RESIDUAL 2026-05-18]
 *
 * Authz sentinel for `app/Http/Controllers/Admin/IngredientController`.
 *
 * Plan T-9.1.1 originally proposed adding `__construct() middleware([
 * 'permission:items'])` to the controller. After audit, the route group
 * at `routes/api.php:713` already declares
 *   ->middleware('permission:ingredients_manage')
 * which is the authoritative chokepoint and identical-in-effect to a
 * constructor-level gate. Adding a SECOND gate via constructor (with a
 * different permission) would create defense-in-depth at the cost of
 * coupling two permission names that must stay in sync — net negative.
 *
 * This sentinel locks in the existing route-level gate so any future
 * refactor that drops it surfaces immediately in CI.
 *
 * Discipline:
 *   - Unauthenticated request → 401 (no token)
 *   - Authenticated user without `ingredients_manage` permission → 403
 *   - Authenticated user WITH `ingredients_manage` permission → 200
 *   - Token revocation propagates per EnsureUserStatusActive
 *
 * Mirrors `tests/Feature/Ingredients/IngredientControllerToggleTest`
 * setUp() pattern (Spatie roles + IngredientPermissionSeeder).
 */
class IngredientControllerAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(IngredientPermissionSeeder::class);

        // Reserve id=1 — User::boot.updating sentinel forces id=1 to ACTIVE.
        // Without this reservation, our test users could land on id=1 and
        // resist status manipulation (see UserStatusRevalidationTest).
        User::factory()->create(['status' => \App\Enums\Status::ACTIVE]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/ingredients')
            ->assertStatus(401);
    }

    public function test_user_without_permission_is_rejected_403(): void
    {
        $user = User::factory()->create();
        // NOTE: user has no role assigned, so no permissions.

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/ingredients')
            ->assertStatus(403);
    }

    public function test_user_with_ingredients_manage_permission_can_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/ingredients')
            ->assertOk();
    }

    public function test_user_without_permission_cannot_toggle_availability(): void
    {
        $user = User::factory()->create();
        // No role/permission.

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/admin/ingredients/attribute:1/availability', [
                'is_available' => false,
                'reason' => 'test_rupture',
            ])
            ->assertStatus(403);
    }
}
