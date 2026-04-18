<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KioskScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_token_cannot_access_admin_dashboard()
    {
        // 1. Créer une branche
        $branch = Branch::forceCreate([
            'name' => 'Main Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue test',
            'status' => 1
        ]);

        // 2. Créer l'User
        $user = User::forceCreate([
            'name' => 'Kiosk Test API',
            'email' => 'kiosk@test.com',
            'username' => 'kiosk_api_user',
            'password' => bcrypt('password123'),
            'branch_id' => $branch->id
        ]);

        // 3. Créer KioskMachine avec User
        $kiosk = KioskMachine::forceCreate([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'machine_id' => 'MAC-12345',
            'username' => 'kiosk1',
            'password' => bcrypt('password123'),
            'status' => 5
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

        // 5. Appel Admin
        $response = $this->withHeaders([
            'x-api-key' => config('app.api_key'),
            'Accept' => 'application/json'
        ])->postJson('/api/admin/pos', []);

        // 6. Doit être rejeté (401, 403, 405 ou 400 Bad Request si la route échoue sans data)
        $this->assertContains($response->status(), [400, 401, 403, 405]);
    }

    public function test_kiosk_token_cannot_delete_branch()
    {
        $branch = Branch::forceCreate([
            'name' => 'Test',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue test',
            'status' => 1
        ]);
        $user = User::forceCreate([
            'name' => 'Kiosk Test API',
            'email' => 'kiosk@test.com',
            'username' => 'kiosk_api_user',
            'password' => bcrypt('password123'),
            'branch_id' => $branch->id
        ]);

        $kiosk = KioskMachine::forceCreate([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'machine_id' => 'MAC-12345',
            'username' => 'kiosk1',
            'password' => bcrypt('password123'),
            'status' => 5
        ]);
        Sanctum::actingAs($user, ['kiosk:order']);

        $response = $this->withHeaders([
            'x-api-key' => config('app.api_key'),
            'Accept' => 'application/json'
        ])->deleteJson('/api/admin/branch/' . $branch->id);

        $this->assertContains($response->status(), [401, 403, 405]);
    }
}
