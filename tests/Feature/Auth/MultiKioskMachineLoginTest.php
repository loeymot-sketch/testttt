<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [MULTI-DEVICE 2026-08-07] Jumeau du défaut corrigé dans `LoginController`.
 *
 * `KioskMachineLoginController` révoquait TOUS les jetons `kiosk-token` du
 * compte lié à la borne. Comme plusieurs bornes peuvent être rattachées au
 * même compte utilisateur, démarrer la borne 2 invalidait la session de la
 * borne 1 — qui tombait en 401 en plein parcours client.
 *
 * Second défaut de la même famille : `kiosk-logout` remettait `is_login=NO`
 * sur TOUTES les bornes du compte, donc éteindre une borne affichait les
 * autres comme déconnectées dans l'administration alors qu'elles servaient.
 *
 * La leçon de projet appliquée ici : un correctif n'est complet que sur ses
 * JUMEAUX, pas seulement sur le chemin qu'on regardait.
 */
class MultiKioskMachineLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $support;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->branch  = Branch::factory()->create();
        $this->support = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => Status::ACTIVE,
        ]);
    }

    private function headers(): array
    {
        return ['x-api-key' => config('app.api_key'), 'Accept' => 'application/json'];
    }

    private function makeKiosk(string $username): KioskMachine
    {
        return KioskMachine::factory()->create([
            'user_id'   => $this->support->id,
            'branch_id' => $this->branch->id,
            'username'  => $username,
            'password'  => Hash::make('kiosk123'),
            'status'    => Status::ACTIVE,
            'is_login'  => Ask::NO,
        ]);
    }

    private function loginKiosk(string $username): string
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/auth/kiosk-login', [
                'username' => $username,
                'password' => 'kiosk123',
            ]);

        $response->assertStatus(201);

        return (string) $response->json('token');
    }

    private function tokenAccepted(string $bearer): bool
    {
        // Un jeton machine est refusé sur /api/profile par conception
        // (KioskMachineTokenProfileBlockTest) : on interroge donc la validité
        // du jeton lui-même, pas une route applicative.
        return \Laravel\Sanctum\PersonalAccessToken::findToken($bearer) !== null;
    }

    /** @test */
    public function test_second_kiosk_login_does_not_revoke_first_kiosk_token(): void
    {
        $borne1 = $this->makeKiosk('borne-01');
        $borne2 = $this->makeKiosk('borne-02');

        $token1 = $this->loginKiosk('borne-01');
        $token2 = $this->loginKiosk('borne-02');

        $this->assertTrue(
            $this->tokenAccepted($token1),
            'La borne 1 a été éjectée par le démarrage de la borne 2.'
        );
        $this->assertTrue($this->tokenAccepted($token2));

        $this->assertSame(
            2,
            $this->support->tokens()->where('name', 'kiosk-token')->count()
        );
    }

    /** @test */
    public function test_same_kiosk_relogin_replaces_only_its_own_token(): void
    {
        $this->makeKiosk('borne-01');
        $this->makeKiosk('borne-02');

        $autre  = $this->loginKiosk('borne-02');
        $ancien = $this->loginKiosk('borne-01');
        $frais  = $this->loginKiosk('borne-01');

        $this->assertFalse($this->tokenAccepted($ancien), 'Le jeton précédent de CETTE borne doit tomber.');
        $this->assertTrue($this->tokenAccepted($frais));
        $this->assertTrue($this->tokenAccepted($autre));
    }

    /** @test */
    public function test_kiosk_logout_only_marks_its_own_machine_offline(): void
    {
        $borne1 = $this->makeKiosk('borne-01');
        $borne2 = $this->makeKiosk('borne-02');

        $token1 = $this->loginKiosk('borne-01');
        $this->loginKiosk('borne-02');

        $this->withHeaders($this->headers() + ['Authorization' => "Bearer {$token1}"])
            ->postJson('/api/auth/kiosk-logout')
            ->assertStatus(200);

        $this->assertSame((int) Ask::NO, (int) $borne1->fresh()->is_login);
        $this->assertSame(
            (int) Ask::YES,
            (int) $borne2->fresh()->is_login,
            'Éteindre une borne ne doit pas afficher les autres comme déconnectées.'
        );
    }
}
