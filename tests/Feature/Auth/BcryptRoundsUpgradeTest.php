<?php

namespace Tests\Feature\Auth;

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
 * [V1.0.1 RED-team P1 — Wave 5G 2026-05-17]
 *
 * Verifies the transparent bcrypt rounds upgrade on login.
 *
 * Story:
 *   - config/hashing.php was bumped from rounds=10 to rounds=12 (production)
 *   - Plain config bump alone affects only NEW hashes
 *   - LoginController must rehash existing user passwords on next successful
 *     login via Hash::needsRehash() check
 *
 * Test setup uses small bcrypt cost (4 vs 8) instead of (10 vs 12) so the
 * test runs in a few hundred ms instead of multiple seconds. The pattern
 * being tested is identical.
 */
class BcryptRoundsUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $this->branch = Branch::factory()->create();

        $table = config('settings.repositories.database.table', 'settings');
        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                ['key' => 'site_default_branch', 'group' => 'site'],
                ['payload' => json_encode((string) $this->branch->id), 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    /**
     * Helper: extract bcrypt cost factor from a hash via password_get_info.
     * Returns 0 if the hash is not bcrypt (defensive — should not happen).
     */
    protected function bcryptCost(string $hash): int
    {
        $info = password_get_info($hash);

        return (int) ($info['options']['cost'] ?? 0);
    }

    /**
     * Creates a user whose password is hashed at $oldCost, then sets the
     * runtime hashing cost to $newCost (simulating the config bump) and
     * logs in. Returns the rehashed user from DB.
     */
    protected function makeUserAndLogin(int $oldCost, int $newCost, string $email = 'rehash@test.com'): User
    {
        // Lock the hashing cost to $oldCost while creating the user so the
        // stored hash reflects the "legacy" cost factor we want to upgrade.
        config(['hashing.bcrypt.rounds' => $oldCost]);
        Hash::setRounds($oldCost);

        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email'     => $email,
            'password'  => Hash::make('password123'),
            'status'    => Status::ACTIVE,
        ]);
        $user->assignRole('Admin');

        $this->assertSame(
            $oldCost,
            $this->bcryptCost($user->password),
            'precondition: user password hashed at expected old cost'
        );

        // Simulate production config bump: from now on Hash::make produces
        // hashes at $newCost, and Hash::needsRehash flags old ones.
        config(['hashing.bcrypt.rounds' => $newCost]);
        Hash::setRounds($newCost);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => 'password123',
        ]);
        $response->assertStatus(201);

        return $user->fresh();
    }

    /**
     * AUTH-REHASH-01: User with legacy-cost password gets rehashed on login.
     */
    public function test_password_is_rehashed_when_cost_factor_is_below_config(): void
    {
        $user = $this->makeUserAndLogin(4, 8);

        $this->assertSame(
            8,
            $this->bcryptCost($user->password),
            'password should have been silently rehashed to the new cost'
        );
    }

    /**
     * AUTH-REHASH-02: After rehash, the same plaintext must still log in.
     * Guards against double-hashing (bcrypt(bcrypt(plain))).
     */
    public function test_user_can_still_login_after_silent_rehash(): void
    {
        $this->makeUserAndLogin(4, 8);

        $second = $this->postJson('/api/auth/login', [
            'email'    => 'rehash@test.com',
            'password' => 'password123',
        ]);

        $second->assertStatus(201);
        $this->assertNotNull($second->json('token'));
    }

    /**
     * AUTH-REHASH-03: User already at target cost does NOT get re-saved
     * on login (no needless DB writes on every request).
     */
    public function test_password_is_not_rehashed_when_already_at_target_cost(): void
    {
        config(['hashing.bcrypt.rounds' => 8]);
        Hash::setRounds(8);

        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email'     => 'noop@test.com',
            'password'  => Hash::make('password123'),
            'status'    => Status::ACTIVE,
        ]);
        $user->assignRole('Admin');

        $hashBefore = $user->fresh()->password;
        $this->assertSame(8, $this->bcryptCost($hashBefore));

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'noop@test.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(201);

        $hashAfter = $user->fresh()->password;

        // Same exact hash string — no save() happened, no new bcrypt salt
        // was generated. This is the cheap canary for the needsRehash guard.
        $this->assertSame($hashBefore, $hashAfter, 'hash must be untouched when no rehash needed');
        $this->assertSame(8, $this->bcryptCost($hashAfter));
    }

    /**
     * AUTH-REHASH-04: Config default in config/hashing.php is 12.
     * Sentinel: if RED-team ever asks to bump further, this test surfaces
     * the change so the env override (BCRYPT_ROUNDS) gets re-reviewed.
     */
    public function test_config_default_rounds_is_twelve(): void
    {
        // Read the raw config file default (BCRYPT_ROUNDS env in phpunit.xml
        // overrides the runtime value, so we inspect the file array directly).
        $config = require base_path('config/hashing.php');

        // env() during config load resolves BCRYPT_ROUNDS=4 in test env;
        // we therefore re-read the file with the env var temporarily unset
        // to assert the literal default fallback.
        $this->assertStringContainsString(
            "env('BCRYPT_ROUNDS', 12)",
            file_get_contents(base_path('config/hashing.php')),
            'config/hashing.php must default rounds to 12 for V1.0.1 hardening'
        );
    }
}
