<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        
        $this->seedSpatieRoles();
        
        $this->branch = Branch::factory()->create();
        
        // Admin
        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'email' => 'admin@test.com',
            'password' => Hash::make('password123'),
        ]);
        $this->admin->assignRole('Admin');
        
        // POS Operator
        $this->posOperator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'pos@test.com',
            'password' => Hash::make('password123'),
        ]);
        $this->posOperator->assignRole('POS Operator');
        
        // Chef
        $this->chef = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'chef@test.com',
            'password' => Hash::make('password123'),
        ]);
        $this->chef->assignRole('Chef');
        
        // Customer
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'customer@test.com',
            'password' => Hash::make('password123'),
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
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'token',
                ],
            ]);
        
        $this->assertNotNull($response->json('data.token'));
    }

    /**
     * AUTH-02: Login with invalid credentials returns 401
     */
    public function test_login_with_invalid_credentials_returns_401(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword',
        ]);
        
        $response->assertStatus(401);
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
        
        $response->assertStatus(200);
        
        // Vérifier defaultPermission.url = 'pos'
        $this->assertEquals('pos', $response->json('data.defaultPermission.url'));
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
        
        $response->assertStatus(200);
        
        // Vérifier defaultPermission.url = 'kitchen-display-system'
        $this->assertEquals('kitchen-display-system', $response->json('data.defaultPermission.url'));
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
        
        $response->assertStatus(200);
        
        // Customer n'a pas de defaultPermission spécifique
        $this->assertNull($response->json('data.defaultPermission'));
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
        
        $token = $loginResponse->json('data.token');
        
        // Logout
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
        $response = $this->getJson('/api/admin/dashboard');
        
        $response->assertStatus(401);
    }

    /**
     * AUTH-08: Admin can access admin routes
     */
    public function test_admin_can_access_admin_routes(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard');
        
        $response->assertStatus(200);
    }

    /**
     * AUTH-09: Customer cannot access admin routes
     */
    public function test_customer_cannot_access_admin_routes(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/admin/dashboard');
        
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
        
        $token = $response->json('data.token');
        
        // Vérifier que le token existe en base
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->admin->id,
            'tokenable_type' => 'App\\Models\\User',
            'name' => 'authToken',
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
        
        $response->assertStatus(401);
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
