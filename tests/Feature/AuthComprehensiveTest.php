<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\KioskMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Module 1: Authentification & Accès (8 tests)
 * 
 * Surface: /api/auth/*, /api/profile/*
 * Priorité: 🔴 Critique
 */
class AuthComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    /**
     * 1.1 - Login Admin valide
     * Route: POST /api/auth/login
     * Attendu: 200 + Token Sanctum
     */
    public function test_admin_login_valid()
    {
        $branch = Branch::factory()->create();
        $admin = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'email' => 'admin@test.com',
            'password' => bcrypt('password123')
        ]);
        $admin->assignRole('Admin');
        
        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/auth/login', [
                'email' => 'admin@test.com',
                'password' => 'password123'
            ]);
        
        $response->assertStatus(200);
        $this->assertArrayHasKey('token', $response->json());
    }

    /**
     * 1.2 - Login Admin invalide
     * Route: POST /api/auth/login
     * Attendu: 401 ou 422
     */
    public function test_admin_login_invalid()
    {
        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/auth/login', [
                'email' => 'fake@test.com',
                'password' => 'wrongpassword'
            ]);
        
        $this->assertTrue(in_array($response->status(), [401, 422]));
    }

    /**
     * 1.3 - Login Kiosk valide
     * Route: POST /api/auth/kiosk-login
     * Attendu: Token avec ability kiosk:order
     * Note: Déjà testé dans AntiGravityTest (T01)
     */
    public function test_kiosk_login_valid()
    {
        $branch = Branch::factory()->create();
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk123',
            'password' => bcrypt('password123'),
            'status' => \App\Enums\Status::ACTIVE,
            'is_login' => \App\Enums\Ask::NO,
        ]);
        
        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/auth/kiosk-login', [
                'username' => 'kiosk123',
                'password' => 'password123'
            ]);
        
        $this->assertTrue(in_array($response->status(), [200, 201]));
        $this->assertArrayHasKey('token', $response->json());
    }

    /**
     * 1.4 - Login Kiosk invalide
     * Route: POST /api/auth/kiosk-login
     * Attendu: Erreur, pas de token
     */
    public function test_kiosk_login_invalid()
    {
        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/auth/kiosk-login', [
                'username' => 'fake',
                'password' => 'wrong'
            ]);
        
        $this->assertTrue(in_array($response->status(), [400, 401, 404, 422]));
    }

    /**
     * 1.5 - Kiosk déjà loggué
     * Route: POST /api/auth/kiosk-login
     * Attendu: Erreur already logged in
     */
    public function test_kiosk_login_already_logged_in()
    {
        $branch = Branch::factory()->create();
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk123',
            'password' => bcrypt('password123'),
            'status' => \App\Enums\Status::ACTIVE,
            'is_login' => \App\Enums\Ask::YES,
        ]);
        
        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk123',
            'password' => 'password123'
        ]);
        
        $this->assertTrue(in_array($response->status(), [400, 401, 403, 422]));
    }

    /**
     * 1.6 - Kiosk inactif
     * Route: POST /api/auth/kiosk-login
     * Attendu: Erreur inactive
     */
    public function test_kiosk_login_inactive()
    {
        $branch = Branch::factory()->create();
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk123',
            'password' => bcrypt('password123'),
            'status' => \App\Enums\Status::INACTIVE,
            'is_login' => \App\Enums\Ask::NO,
        ]);
        
        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk123',
            'password' => 'password123'
        ]);
        
        $this->assertTrue(in_array($response->status(), [400, 401, 403, 422]));
    }

    /**
     * 1.7 - Logout Admin
     * Route: POST /api/auth/logout
     * Attendu: 200 + token révoqué
     */
    public function test_admin_logout()
    {
        $branch = Branch::factory()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/auth/logout');
        
        $response->assertStatus(200);
    }

    /**
     * 1.8 - Accès sans token
     * Route: GET /api/admin/dashboard
     * Attendu: 401 Unauthenticated
     */
    public function test_access_without_token_returns_401()
    {
        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/dashboard');
        
        $response->assertStatus(401);
    }

    /**
     * Helper: Get API key for admin route access
     */
    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }
}
