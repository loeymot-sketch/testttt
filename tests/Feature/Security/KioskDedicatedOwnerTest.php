<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [TERRAIN-HEAL 2026-07-16 · KIOSK-PROFILE-ESCALATION couche-2]
 *
 * foodking:ensure-kiosk-machine SANS --user-id doit lier la borne à un utilisateur DÉDIÉ SANS RÔLE
 * (et non à admin/user-1 comme avant) — pour qu'un token kiosk:order fuité ne porte aucun privilège
 * Spatie (défense en profondeur derrière block_kiosk_token_admin + block_kiosk_machine_profile).
 */
class KioskDedicatedOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_binds_kiosk_to_roleless_dedicated_user(): void
    {
        $branch = Branch::factory()->create();
        // Un admin existe (l'ancien défaut l'aurait choisi) — le nouveau code NE doit PAS le prendre.
        $admin = User::factory()->create(['email' => 'admin@lecayenne.fr']);

        $this->artisan('foodking:ensure-kiosk-machine', [
            '--username'  => 'kiosk-test-dedicated',
            '--password'  => 'secret123',
            '--branch-id' => $branch->id,
        ])->assertExitCode(0);

        $machine = KioskMachine::withoutGlobalScopes()->where('username', 'kiosk-test-dedicated')->first();
        $this->assertNotNull($machine);

        $owner = User::withoutGlobalScopes()->find($machine->user_id);
        $this->assertNotNull($owner);
        // L'owner NE DOIT PAS être l'admin.
        $this->assertNotSame($admin->id, $owner->id, 'La borne ne doit pas être liée à admin.');
        // L'owner dédié n'a AUCUN rôle ni permission privilégiée.
        $this->assertCount(0, $owner->getRoleNames(), 'Owner borne doit être sans rôle.');
        $this->assertFalse($owner->can('settings'));
        $this->assertStringContainsString('kiosk-borne', (string) $owner->email);
    }

    public function test_explicit_user_id_still_respected(): void
    {
        $branch = Branch::factory()->create();
        $chosen = User::factory()->create();

        $this->artisan('foodking:ensure-kiosk-machine', [
            '--username'  => 'kiosk-test-explicit',
            '--password'  => 'secret123',
            '--branch-id' => $branch->id,
            '--user-id'   => $chosen->id,
        ])->assertExitCode(0);

        $machine = KioskMachine::withoutGlobalScopes()->where('username', 'kiosk-test-explicit')->first();
        $this->assertSame($chosen->id, $machine->user_id);
    }
}
