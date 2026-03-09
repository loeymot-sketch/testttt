<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Enums\Ask;
use App\Models\KioskMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

class KioskLoginApiTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_key' => '123456']);
        $this->withHeaders([
            'x-api-key' => '123456',
            'Accept' => 'application/json',
        ]);
    }

    public function test_kiosk_login_and_logout_flow()
    {
        $branch = Branch::forceCreate([
            'name' => 'Kiosk Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue Test',
            'status' => 1
        ]);

        $user = User::forceCreate([
            'name' => 'Kiosk API User',
            'email' => 'kiosk_api@example.com',
            'username' => 'kiosk_api',
            'password' => bcrypt('password'),
            'status' => 5
        ]);

        $machine = KioskMachine::forceCreate([
            'machine_id' => '123456',
            'username' => 'kiosk1',
            'password' => bcrypt('123456'),
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'is_login' => Ask::NO,
            'status' => 1
        ]);

        // 1. Initial Login
        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk1',
            'password' => '123456'
        ]);

        $response->assertStatus(201);
        $token = $response->json('token');
        $this->assertDatabaseHas('kiosk_machines', ['machine_id' => '123456', 'is_login' => Ask::YES]);

        // 2. Prevent Double Login
        $response2 = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk1',
            'password' => '123456'
        ]);
        $response2->assertStatus(400);

        // 3. Logout
        $responseLogout = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/auth/kiosk-logout');

        $responseLogout->assertStatus(200);
        $this->assertDatabaseHas('kiosk_machines', ['machine_id' => '123456', 'is_login' => Ask::NO]);
    }
}
