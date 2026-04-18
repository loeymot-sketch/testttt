<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_admin_mutation_rate_limit_returns_429(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('Admin');

        for ($i = 0; $i < 31; $i++) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/admin/item', []);
        }

        $this->assertEquals(429, $response->status());
    }

    public function test_login_rate_limit(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'fake@test.com',
                'password' => 'wrongpassword',
            ]);
        }

        $this->assertEquals(429, $response->status());
    }
}
