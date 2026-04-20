<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
        // PHPUnit env raises the global cap for the suite; pin prod-like limits for this assertion.
        Config::set('auth.login_lockout.max_attempts', 10);
        Config::set('auth.login_lockout.decay_minutes', 10);

        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'fake@test.com',
                'password' => 'wrongpassword',
            ]);
        }

        $this->assertEquals(429, $response->status());
    }

    public function test_login_lockout_limiter_respects_auth_config_override(): void
    {
        Config::set('auth.login_lockout.max_attempts', 3);
        Config::set('auth.login_lockout.decay_minutes', 10);

        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'lockout-override@example.com',
                'password' => 'wrong',
            ]);
        }

        $this->assertEquals(429, $response->status());
    }

    public function test_login_lockout_env_example_documents_prod_safe_defaults(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('LOGIN_LOCKOUT_MAX_ATTEMPTS=10', $example);
        $this->assertStringContainsString('LOGIN_LOCKOUT_DECAY_MINUTES=10', $example);
    }
}
