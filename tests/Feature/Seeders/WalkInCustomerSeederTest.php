<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\RoleTableSeeder;
use Database\Seeders\WalkInCustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [POS-3] Walking customer mitigation — regression guard.
 *
 * Vérifie que :
 *   1. Le seeder crée bien un walking customer avec les conventions exactes
 *      attendues par PosComponent.vue (email exact + name 'Client passage').
 *   2. Le seeder est idempotent (ré-exécutable sans duplicat ni erreur).
 *   3. La commande boot retourne SUCCESS quand le row existe.
 *   4. La commande boot retourne FAILURE quand le row manque (sans --seed).
 *   5. La commande boot --seed crée le row et retourne SUCCESS.
 */
class WalkInCustomerSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RoleTableSeeder doit tourner pour que le rôle Customer existe.
        $this->seed(RoleTableSeeder::class);
    }

    public function test_seeder_creates_walking_customer(): void
    {
        $this->seed(WalkInCustomerSeeder::class);

        $customer = User::withoutGlobalScopes()
            ->where('email', WalkInCustomerSeeder::WALKING_EMAIL)
            ->first();

        $this->assertNotNull($customer, 'Walking customer doit être créé.');
        $this->assertSame(WalkInCustomerSeeder::WALKING_NAME, $customer->name);
        $this->assertSame(0, (int) $customer->branch_id, 'Walking customer doit être global (branch_id=0).');
        $this->assertTrue(
            $customer->hasRole('Customer'),
            'Walking customer doit avoir le rôle Spatie Customer (role_id=2 côté frontend).'
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(WalkInCustomerSeeder::class);
        $countAfterFirst = User::withoutGlobalScopes()
            ->where('email', WalkInCustomerSeeder::WALKING_EMAIL)
            ->count();

        // Re-run twice more — must not duplicate or fail.
        $this->seed(WalkInCustomerSeeder::class);
        $this->seed(WalkInCustomerSeeder::class);

        $countAfterThird = User::withoutGlobalScopes()
            ->where('email', WalkInCustomerSeeder::WALKING_EMAIL)
            ->count();

        $this->assertSame(1, $countAfterFirst, 'Premier run = 1 seul customer.');
        $this->assertSame(1, $countAfterThird, 'Triple run = toujours 1 seul customer (idempotent).');
    }

    public function test_seeder_reassigns_role_when_missing(): void
    {
        // Simule un walking customer pré-existant SANS rôle Customer (ex: migration partielle).
        $customer = User::create([
            'name'              => WalkInCustomerSeeder::WALKING_NAME,
            'email'             => WalkInCustomerSeeder::WALKING_EMAIL,
            'username'          => 'default-customer',
            'phone'             => '0600000001',
            'password'          => bcrypt('placeholder'),
            'branch_id'         => 0,
            'status'            => \App\Enums\Status::ACTIVE,
            'country_code'      => '+33',
            'is_guest'          => \App\Enums\Ask::NO,
            'email_verified_at' => now(),
        ]);
        $this->assertFalse($customer->hasRole('Customer'));

        $this->seed(WalkInCustomerSeeder::class);

        $this->assertTrue($customer->fresh()->hasRole('Customer'));
    }

    public function test_command_returns_success_when_walking_exists(): void
    {
        $this->seed(WalkInCustomerSeeder::class);

        $this->artisan('foodking:walk-in:ensure')
            ->expectsOutputToContain('[OK]')
            ->assertSuccessful();
    }

    public function test_command_returns_failure_when_walking_missing(): void
    {
        // Aucun walking customer en base.
        $this->assertSame(
            0,
            User::withoutGlobalScopes()
                ->where('email', WalkInCustomerSeeder::WALKING_EMAIL)
                ->count()
        );

        $this->artisan('foodking:walk-in:ensure')
            ->expectsOutputToContain('[CRITICAL]')
            ->assertFailed();
    }

    public function test_command_seeds_when_seed_option_passed(): void
    {
        $this->assertSame(
            0,
            User::withoutGlobalScopes()
                ->where('email', WalkInCustomerSeeder::WALKING_EMAIL)
                ->count()
        );

        $this->artisan('foodking:walk-in:ensure', ['--seed' => true])
            ->expectsOutputToContain('[FIXED]')
            ->assertSuccessful();

        $this->assertSame(
            1,
            User::withoutGlobalScopes()
                ->where('email', WalkInCustomerSeeder::WALKING_EMAIL)
                ->count()
        );
    }

    public function test_command_detects_walking_by_name_keyword(): void
    {
        // [POS-3] Si un walking est créé manuellement avec un email custom mais
        // un name contenant 'walking', le boot ne doit pas fausser une alerte.
        // (Roles déjà seedés dans setUp().)
        User::create([
            'name'              => 'Walking Customer Manuel',
            'email'             => 'manual-walking@example.com',
            'username'          => 'manual-walking',
            'phone'             => '0600000099',
            'password'          => bcrypt('x'),
            'branch_id'         => 0,
            'status'            => \App\Enums\Status::ACTIVE,
            'country_code'      => '+33',
            'is_guest'          => \App\Enums\Ask::NO,
            'email_verified_at' => now(),
        ]);

        $this->artisan('foodking:walk-in:ensure')->assertSuccessful();
    }
}
