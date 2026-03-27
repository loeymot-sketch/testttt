<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests authentification complets (Module 1 MASSIVE_TEST_PLAN)
 * Couvre login, logout, token, redirections par rôle
 */
class AuthComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $posOperator;
    protected User $chef;
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        Role::where('name', 'POS Operator')->where('guard_name', 'sanctum')->update(['landing_url' => 'pos']);
        Role::where('name', 'Chef')->where('guard_name', 'sanctum')->update(['landing_url' => 'kitchen-display-system']);

        $this->branch = Branch::factory()->create();

        $table = config('settings.repositories.database.table', 'settings');
        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                ['key' => 'site_default_branch', 'group' => 'site'],
                ['payload' => json_encode((string) $this->branch->id), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Admin (branch réelle : évite LoginController + site_default_branch fragile)
        $this->admin = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'admin@test.com',
            'password' => Hash::make('password123'),
            'status' => Status::ACTIVE,
        ]);
        $this->admin->assignRole('Admin');
        
        // POS Operator
        $this->posOperator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'pos@test.com',
            'password' => Hash::make('password123'),
            'status' => Status::ACTIVE,
        ]);
        $this->posOperator->assignRole('POS Operator');
        
        // Chef
        $this->chef = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'chef@test.com',
            'password' => Hash::make('password123'),
            'status' => Status::ACTIVE,
        ]);
        $this->chef->assignRole('Chef');
        
        // Customer
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'customer@test.com',
            'password' => Hash::make('password123'),
            'status' => Status::ACTIVE,
        ]);
        $this->customer->assignRole('Customer');
    }

    /**
     * AUTH-01: Admin login with valid credentials
     */
    public function test_admin_can_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ]);
        
        $response->assertStatus(201)
            ->assertJsonStructure([
                'token',
                'user',
                'defaultPermission',
                'permission',
            ]);

        $this->assertNotNull($response->json('token'));
    }

    /**
     * AUTH-02: Login with invalid credentials (LoginController renvoie 400 + errors.validation)
     */
    public function test_login_with_invalid_credentials_returns_400(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(400);
    }

    /**
     * AUTH-03: POS Operator receives correct defaultPermission
     */
    public function test_pos_operator_receives_correct_landing_permission(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'pos@test.com',
            'password' => 'password123',
        ]);
        
        $response->assertStatus(201);

        $this->assertEquals('pos', $response->json('defaultPermission.url'));
    }

    /**
     * AUTH-04: Chef receives correct defaultPermission (KDS)
     */
    public function test_chef_receives_correct_landing_permission(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'chef@test.com',
            'password' => 'password123',
        ]);
        
        $response->assertStatus(201);

        $this->assertEquals('kitchen-display-system', $response->json('defaultPermission.url'));
    }

    /**
     * AUTH-05: Customer receives home landing
     */
    public function test_customer_receives_home_landing(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'customer@test.com',
            'password' => 'password123',
        ]);
        
        $response->assertStatus(201);

        $this->assertNotNull($response->json('token'));
        $this->assertNotEquals('pos', $response->json('defaultPermission.url'));
        $this->assertNotEquals('kitchen-display-system', $response->json('defaultPermission.url'));
    }

    /**
     * AUTH-06: Logout invalidates token
     */
    public function test_logout_invalidates_token(): void
    {
        // Login
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ]);
        
        $token = $loginResponse->json('token');

        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/logout');
        
        $logoutResponse->assertStatus(200);
        
        // Vérifier que le token est invalide
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->admin->id,
        ]);
    }

    /**
     * AUTH-07: Access without token returns 401
     */
    public function test_access_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/admin/dashboard/total-orders');

        $response->assertStatus(401);
    }

    /**
     * AUTH-08: Admin can access admin routes
     */
    public function test_admin_can_access_admin_routes(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders');

        $response->assertStatus(200);
    }

    /**
     * AUTH-09: Customer cannot access admin routes
     */
    public function test_customer_cannot_access_admin_routes(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders');

        // Customer n'a pas les permissions admin
        $response->assertStatus(403);
    }

    /**
     * AUTH-10: Token is generated with Sanctum
     */
    public function test_token_is_generated_with_sanctum(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ]);
        
        $this->assertNotNull($response->json('token'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->admin->id,
            'tokenable_type' => 'App\\Models\\User',
            'name' => 'auth_token',
        ]);
    }

    /**
     * AUTH-11: POS Operator can access POS routes
     */
    public function test_pos_operator_can_access_pos_routes(): void
    {
        $response = $this->actingAs($this->posOperator, 'sanctum')
            ->getJson('/api/admin/pos-order');
        
        $response->assertStatus(200);
    }

    /**
     * AUTH-12: Chef can access KDS routes
     */
    public function test_chef_can_access_kds_routes(): void
    {
        $response = $this->actingAs($this->chef, 'sanctum')
            ->getJson('/api/admin/kds-order');
        
        $response->assertStatus(200);
    }

    /**
     * AUTH-13: Non-existent user cannot login
     */
    public function test_nonexistent_user_cannot_login(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@test.com',
            'password' => 'password123',
        ]);
        
        $response->assertStatus(400);
    }

    /**
     * AUTH-14: Login with empty credentials returns 422
     */
    public function test_login_with_empty_credentials_returns_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => '',
            'password' => '',
        ]);
        
        $response->assertStatus(422);
    }
}
