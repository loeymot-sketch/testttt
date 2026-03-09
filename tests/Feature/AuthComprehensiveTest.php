<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class AuthComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Forcer la variable d'env pour que le ApiKeyMiddleware ne jette pas un 400
        putenv('MIX_API_KEY=123456');

        // Clé API standard requise par le middleware
        $this->withHeaders([
            'x-api-key' => '123456',
            'Accept' => 'application/json',
        ]);

        // Configuration de base pour satisfaire les Foreign Keys
        $this->branch = Branch::forceCreate([
            'name' => 'Main Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue test',
            'status' => 1
        ]);
    }

    public function test_login_admin_valide()
    {
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

        $user = User::forceCreate([
            'name' => 'Admin API',
            'email' => 'admin@test.com',
            'username' => 'admin_api_user',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 5,
            'email_verified_at' => now()
        ]);
        $user->assignRole($role);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password123'
        ]);

        $this->assertContains($response->status(), [201, 200]);
        $this->assertArrayHasKey('token', $response->json());
    }

    public function test_login_admin_invalide()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword'
        ]);

        $this->assertContains($response->status(), [401, 400, 422]);
    }

    public function test_login_kiosk_valide()
    {
        $user = User::forceCreate([
            'name' => 'Kiosk Test API',
            'email' => 'kiosk@test.com',
            'username' => 'kiosk_api_user',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 5,
            'email_verified_at' => now()
        ]);

        $kiosk = KioskMachine::forceCreate([
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'machine_id' => 'MAC-12345',
            'username' => 'kiosk1',
            'password' => bcrypt('password123'),
            'status' => 5
        ]);

        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk1',
            'password' => 'password123'
        ]);

        $this->assertContains($response->status(), [200, 201]);
        $this->assertArrayHasKey('token', $response->json('data') ?? $response->json());
    }

    public function test_login_kiosk_invalide()
    {
        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk1',
            'password' => 'wrongpassword'
        ]);

        $this->assertContains($response->status(), [401, 400, 422, 404]);
    }

    public function test_logout_admin()
    {
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $user = User::forceCreate([
            'name' => 'Admin API',
            'email' => 'adminout@test.com',
            'username' => 'admin_out_user',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 5,
            'email_verified_at' => now()
        ]);
        $user->assignRole($role);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/auth/logout');

        // It can be 200 or 201
        $this->assertContains($response->status(), [200, 201]);
    }

    public function test_logout_kiosk()
    {
        $user = User::forceCreate([
            'name' => 'Kiosk API',
            'email' => 'kioskout@test.com',
            'username' => 'kiosk_out_user',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 5,
            'email_verified_at' => now()
        ]);

        $token = $user->createToken('kioskToken', ['kiosk:order'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/auth/kiosk-logout');

        $this->assertContains($response->status(), [200, 201]);
    }

    public function test_acces_sans_token_rejete()
    {
        $response = $this->getJson('/api/admin/dashboard/total-sales');

        $this->assertContains($response->status(), [401, 403]);
    }
}
