<?php

namespace Tests\Feature\Admin;

use App\Enums\Role as EnumRole;
use App\Http\Requests\UserChangePasswordRequest;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [F-EMPLOYEE-PEER-GUARD 2026-07-15 / P2] EmployeeService::store/update enforçaient
 * callerMayGrantRole (sous-ensemble strict — bloque escalade ET clonage de pair), MAIS
 * changePassword/destroy/changeImage ne testaient que blockRoles (rôles 1-5). Un Branch
 * Manager (rôle 6, hors blockRoles) pouvait donc réinitialiser le mot de passe / supprimer
 * un PAIR Branch Manager = account takeover / destruction horizontale, alors même que
 * store() refuse de cloner un pair. Ce test verrouille la symétrie sur les écritures.
 */
class EmployeePeerManagementGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function service(): EmployeeService
    {
        return $this->app->make(EmployeeService::class);
    }

    private function userWithRole(int $roleId): User
    {
        $user = User::factory()->create(['branch_id' => 1, 'password' => Hash::make('OldPass123!')]);
        $user->assignRole(\Spatie\Permission\Models\Role::findById($roleId, 'sanctum'));
        return $user;
    }

    private function changePasswordRequest(string $password): UserChangePasswordRequest
    {
        $req = new UserChangePasswordRequest;
        $req->merge(['password' => $password, 'password_confirmation' => $password]);
        return $req;
    }

    public function test_manager_cannot_change_password_of_peer_manager(): void
    {
        $caller = $this->userWithRole(EnumRole::BRANCH_MANAGER);
        $peer   = $this->userWithRole(EnumRole::BRANCH_MANAGER);
        $this->actingAs($caller);

        $threw = false;
        try {
            $this->service()->changePassword($this->changePasswordRequest('NewPass123!'), $peer);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Un Branch Manager ne doit PAS pouvoir réinitialiser le mot de passe d’un pair.');
        $this->assertTrue(Hash::check('OldPass123!', $peer->fresh()->password),
            'Le mot de passe du pair doit rester INCHANGÉ (pas d’account takeover).');
    }

    public function test_manager_cannot_destroy_peer_manager(): void
    {
        $caller = $this->userWithRole(EnumRole::BRANCH_MANAGER);
        $peer   = $this->userWithRole(EnumRole::BRANCH_MANAGER);
        $this->actingAs($caller);

        try {
            $this->service()->destroy($peer);
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertDatabaseHas('users', ['id' => $peer->id, 'deleted_at' => null]);
    }

    public function test_admin_can_change_password_of_manager(): void
    {
        // Admin détient `settings` → callerMayGrantRole toujours vrai → pas de sur-blocage.
        $admin   = $this->userWithRole(EnumRole::ADMIN);
        $manager = $this->userWithRole(EnumRole::BRANCH_MANAGER);
        $this->actingAs($admin);

        $this->service()->changePassword($this->changePasswordRequest('AdminSet123!'), $manager);

        $this->assertTrue(Hash::check('AdminSet123!', $manager->fresh()->password),
            'Un Admin (settings) doit pouvoir réinitialiser le mot de passe d’un manager (pas de sur-blocage).');
    }
}
