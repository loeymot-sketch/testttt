<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [Root cause 2026-09-19, propriétaire : « je peux créer 2 compte avec meme email ! c trop
 * reducul ! corrige deep, et meme compte fidelite »]
 *
 * SignupController::register() (POST /api/auth/signup/register, le parcours « compte complet »
 * téléphone + mot de passe) ne cherchait un compte existant QUE par téléphone :
 *
 *     $existing = User::where('phone', $phone)->first();
 *
 * SignupRequest valide l'unicité de l'e-mail mais SEULEMENT parmi les comptes `is_guest = NO` :
 *
 *     Rule::unique("users","email")->whereNull('deleted_at')->where('is_guest', Ask::NO)
 *
 * Cette exemption est volontaire — elle sert le parcours « même téléphone, upgrade invité vers
 * compte complet » (le contrôleur retrouve alors le compte invité PAR TÉLÉPHONE et le met à
 * jour en place, e-mail compris, sans jamais passer par la règle unique). Mais rien ne relie
 * cette exemption à une preuve de téléphone : un compte invité créé au comptoir, à la borne, ou
 * via l'e-mail-OTP du site (téléphone A, e-mail X) laisse un client libre de revenir sur ce
 * même formulaire avec un AUTRE téléphone (B) et le MÊME e-mail (X) — la recherche par
 * téléphone ne trouve rien, la règle unique laisse passer (l'ancien compte est invité), et un
 * SECOND compte complet est créé avec l'e-mail déjà porté par le premier. Deux comptes, deux
 * soldes de points fidélité, un seul humain — exactement le signalement.
 *
 * `users.email` n'a par ailleurs AUCUNE contrainte unique en base (vérifié : index MUL, pas
 * UNIQUE) — rien n'aurait arrêté l'écriture même si l'application avait un trou différent.
 */
class SignupDuplicateEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function register(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/signup/register', array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'country_code' => '33',
            'password' => 'password123',
        ], $body));
    }

    /**
     * Reproduit exactement le signalement : un compte INVITÉ existe déjà (téléphone A,
     * e-mail X, créé par exemple au comptoir ou à la borne) ; un client revient sur le
     * formulaire d'inscription complète avec un téléphone DIFFÉRENT (B) et le MÊME e-mail (X).
     */
    public function test_signup_with_a_different_phone_but_an_email_already_used_by_a_guest_account_is_refused(): void
    {
        $guest = User::create([
            'name' => 'Client Comptoir',
            'username' => 'client-comptoir',
            'email' => 'client.duplique@example.com',
            'phone' => '0611110000',
            'country_code' => '33',
            'branch_id' => 0,
            'is_guest' => Ask::YES,
            'password' => bcrypt('whatever'),
        ]);

        Cache::put('phone_verified:0622220000', true, 600);

        $response = $this->register([
            'phone' => '0622220000',
            'email' => 'client.duplique@example.com',
        ]);

        $response->assertStatus(422);

        $matchingEmail = User::withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', ['client.duplique@example.com'])
            ->get();

        $this->assertCount(
            1,
            $matchingEmail,
            'un seul compte doit exister avec cet e-mail — pas de doublon créé sous un autre téléphone'
        );
        $this->assertSame($guest->id, $matchingEmail->first()->id);
    }

    /** Le parcours légitime reste intact : même TÉLÉPHONE que le compte invité → upgrade en place. */
    public function test_signup_with_the_same_phone_as_the_guest_account_still_upgrades_it_in_place(): void
    {
        $guest = User::create([
            'name' => 'Client Comptoir',
            'username' => 'client-comptoir-2',
            'email' => null,
            'phone' => '0611110001',
            'country_code' => '33',
            'branch_id' => 0,
            'is_guest' => Ask::YES,
            'password' => bcrypt('whatever'),
        ]);

        Cache::put('phone_verified:0611110001', true, 600);

        $response = $this->register([
            'phone' => '0611110001',
            'email' => 'meme.client@example.com',
        ]);

        $response->assertStatus(201);

        $guest->refresh();
        $this->assertSame(Ask::NO, (int) $guest->is_guest);
        $this->assertSame('meme.client@example.com', $guest->email);
    }

    /** Un e-mail réellement neuf continue de créer un compte normalement. */
    public function test_signup_with_a_brand_new_email_and_phone_still_creates_an_account(): void
    {
        Cache::put('phone_verified:0633330000', true, 600);

        $response = $this->register([
            'phone' => '0633330000',
            'email' => 'tout.nouveau@example.com',
        ]);

        $response->assertStatus(201);
        $this->assertNotNull(User::withoutGlobalScopes()->where('phone', '0633330000')->first());
    }
}
