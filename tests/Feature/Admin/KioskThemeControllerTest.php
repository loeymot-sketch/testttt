<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [Wave Gamma G3 / V2-5] KioskThemeController — admin theme switch surface.
 *
 * Garantit :
 *  - GET public retourne 'standard' quand `active_kiosk_theme` est NULL.
 *  - GET public retourne le slug stocké quand il est valide.
 *  - PATCH admin (head-office, branch_id=0) accepte un slug valide.
 *  - PATCH par un staff sans permission `settings` → 403.
 *  - PATCH avec un slug hors liste blanche → 422.
 *  - PATCH par un Admin scoped (branch_id=X) sur branch Y → 403
 *    (isolation de branche enforced dans le controller).
 *
 * Aligné sur le pattern `DeliveryPlatformAdminTest` : head-office Admin
 * = `branch_id = 0`, role `Admin`, permission `settings` pré-seedée par
 * `seedSpatieRoles()` du parent `TestCase`.
 */
class KioskThemeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_default_theme_is_standard_when_branch_unset(): void
    {
        $branch = Branch::factory()->create([
            'active_kiosk_theme' => null,
        ]);

        $response = $this->getJson("/api/admin/kiosk-theme/{$branch->id}");

        $response->assertOk()
            ->assertJson(['active_theme' => 'standard']);
    }

    public function test_show_returns_standard_for_unknown_branch_no_404(): void
    {
        // Volontaire : un kiosk avec un branch_id obsolète ne doit jamais
        // recevoir un 404 qui bloquerait son boot — fallback graceful.
        $response = $this->getJson('/api/admin/kiosk-theme/999999');

        $response->assertOk()
            ->assertJson(['active_theme' => 'standard']);
    }

    public function test_show_returns_stored_theme_when_valid(): void
    {
        $branch = Branch::factory()->create([
            'active_kiosk_theme' => 'halloween',
        ]);

        $response = $this->getJson("/api/admin/kiosk-theme/{$branch->id}");

        $response->assertOk()
            ->assertJson(['active_theme' => 'halloween']);
    }

    public function test_show_falls_back_to_standard_when_stored_slug_unknown(): void
    {
        // Garde-fou : un slug stale (thème supprimé) ne doit pas faire crasher
        // les kiosks — on retombe sur 'standard' sans 422 ni warning.
        $branch = Branch::factory()->create([
            'active_kiosk_theme' => 'fifa-2026-deprecated',
        ]);

        $response = $this->getJson("/api/admin/kiosk-theme/{$branch->id}");

        $response->assertOk()
            ->assertJson(['active_theme' => 'standard']);
    }

    public function test_admin_can_set_active_theme(): void
    {
        $branch = Branch::factory()->create([
            'active_kiosk_theme' => null,
        ]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson("/api/admin/kiosk-theme/{$branch->id}", [
            'theme' => 'christmas',
        ]);

        $response->assertOk()
            ->assertJson([
                'status'       => true,
                'active_theme' => 'christmas',
            ]);

        $this->assertDatabaseHas('branches', [
            'id'                 => $branch->id,
            'active_kiosk_theme' => 'christmas',
        ]);
    }

    public function test_staff_without_permission_cannot_set_theme_403(): void
    {
        $branch = Branch::factory()->create();

        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->assignRole('Stuff'); // role sans 'settings' permission
        Sanctum::actingAs($staff, ['*']);

        $response = $this->patchJson("/api/admin/kiosk-theme/{$branch->id}", [
            'theme' => 'halloween',
        ]);

        $response->assertStatus(403);
    }

    public function test_invalid_theme_rejected_422(): void
    {
        $branch = Branch::factory()->create();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson("/api/admin/kiosk-theme/{$branch->id}", [
            'theme' => 'mardi-gras-2027',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('branches', [
            'id'                 => $branch->id,
            'active_kiosk_theme' => 'mardi-gras-2027',
        ]);
    }

    public function test_branch_isolation_admin_branch_a_cannot_set_branch_b_via_param(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create([
            'active_kiosk_theme' => 'standard',
        ]);

        // Admin local scoped à branch A (branch_id non-zéro).
        $admin = User::factory()->create(['branch_id' => $branchA->id]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson("/api/admin/kiosk-theme/{$branchB->id}", [
            'theme' => 'halloween',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('branches', [
            'id'                 => $branchB->id,
            'active_kiosk_theme' => 'standard',
        ]);
    }

    public function test_branch_scoped_admin_can_set_own_branch(): void
    {
        $branch = Branch::factory()->create();

        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson("/api/admin/kiosk-theme/{$branch->id}", [
            'theme' => 'halloween',
        ]);

        $response->assertOk()
            ->assertJson([
                'status'       => true,
                'active_theme' => 'halloween',
            ]);

        $this->assertDatabaseHas('branches', [
            'id'                 => $branch->id,
            'active_kiosk_theme' => 'halloween',
        ]);
    }
}
