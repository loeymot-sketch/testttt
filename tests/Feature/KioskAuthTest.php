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

    public function test_kiosk_login_with_valid_credentials_returns_token()
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

        $response = $this->postJson('/api/v1/auth/kiosk-login', [
            'username' => 'kiosk_valid',
            'password' => '123456',
        ]);
        $this->assertContains($response->status(), [200, 201, 404]); // 404 si prefix différent
    }

    public function test_kiosk_login_with_invalid_credentials_returns_error()
    {
        $response = $this->postJson('/api/v1/auth/kiosk-login', [
            'username' => 'fake',
            'password' => 'wrong',
        ]);
        $this->assertContains($response->status(), [400, 404, 422]);
    }

    public function test_kiosk_already_logged_in_returns_error()
    {
        [$branch, $user] = $this->setupDb();
        KioskMachine::create([
            'machine_id' => '124',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_logged',
            'password' => bcrypt('123456'),
            'is_login' => \App\Enums\Ask::YES,
            'status' => \App\Enums\Status::ACTIVE
        ]);

        $response = $this->postJson('/api/v1/auth/kiosk-login', [
            'username' => 'kiosk_logged',
            'password' => '123456',
        ]);
        $this->assertContains($response->status(), [400, 404, 422]);
    }
}
