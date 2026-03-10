<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\KioskMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KioskAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    private function setupDb()
    {
        $branch = Branch::updateOrCreate(['id' => 1], [
            'name' => 'HQ',
            'email' => 'hq2@foodking.com',
            'phone' => '123',
            'latitude' => '0',
            'longitude' => '0',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75',
            'address' => '1 rue'
        ]);
        $user = User::factory()->create(['username' => 'usr_' . uniqid(), 'branch_id' => $branch->id]);
        return [$branch, $user];
    }

    public function test_kiosk_login_with_valid_credentials()
    {
        [$branch, $user] = $this->setupDb();
        KioskMachine::create([
            'machine_id' => '123',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_valid',
            'password' => bcrypt('123456'),
            'is_login' => \App\Enums\Ask::NO,
            'status' => \App\Enums\Status::ACTIVE
        ]);

        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => 'kiosk_valid',
            'password' => '123456',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['data' => ['token']]);
    }

    public function test_kiosk_cannot_access_admin_flows()
    {
        [$branch, $user] = $this->setupDb();

        // Simuler un accès en tant qu'utilisateur standard non admin vers un endpoint Admin
        $response = $this->actingAs($user)->getJson('/api/admin/dashboard');

        // Strict assertion: unauthorized or forbidden
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }
}
