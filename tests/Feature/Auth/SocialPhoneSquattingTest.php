<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [APPS 2026-08-19] Peut-on s'approprier le compte d'un autre en DÉCLARANT son numéro ?
 *
 * LE SCÉNARIO EXAMINÉ
 * -------------------
 * Une connexion Apple ou Google n'apporte aucun téléphone. L'application demande donc le
 * numéro juste après, et celui-ci est enregistré SANS preuve de possession : ce projet
 * n'envoie pas de SMS, et redemander un code à une adresse que le fournisseur vient
 * d'attester ne prouverait rien sur le TÉLÉPHONE. Le numéro est traité comme une donnée de
 * contact, pas comme un identifiant.
 *
 * Sauf que dans ce système, le téléphone EST un identifiant : le parcours historique
 * (`GuestSignupController::register`) retrouve un compte invité PAR SON NUMÉRO, et y
 * connecte quiconque prouve la possession du code. D'où la question qu'il faut poser
 * franchement : si quelqu'un déclare le numéro d'un autre, que se passe-t-il quand le vrai
 * propriétaire se connecte ensuite ?
 *
 * Ces tests répondent par l'expérience, pas par la lecture.
 */
class SocialPhoneSquattingTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';
    private const AUDIENCE = 'fr.lecayenne.app';
    private const KID = 'cle-de-test-1';
    private const TEL_VICTIME = '0612345678';

    private static $paire = null;
    private static ?array $details = null;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        config(['app.api_key' => self::API_KEY]);
        config(['services.apple.audiences' => [self::AUDIENCE]]);

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

        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);

        $this->trousseau();
    }

    // ------------------------------------------------------------- outillage

    private static function paire()
    {
        if (self::$paire === null) {
            self::$paire = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::$details = openssl_pkey_get_details(self::$paire);
        }

        return self::$paire;
    }

    private static function b64url(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    private function trousseau(): void
    {
        self::paire();
        Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [[
            'kty' => 'RSA', 'kid' => self::KID, 'use' => 'sig', 'alg' => 'RS256',
            'n' => self::b64url(self::$details['rsa']['n']),
            'e' => self::b64url(self::$details['rsa']['e']),
        ]]], 200)]);
    }

    private function jetonApple(string $sub, string $email): string
    {
        $entete = ['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'];
        $charge = [
            'iss' => 'https://appleid.apple.com', 'aud' => self::AUDIENCE, 'sub' => $sub,
            'email' => $email, 'email_verified' => true,
            'iat' => time() - 10, 'exp' => time() + 600,
        ];
        $signe = self::b64url(json_encode($entete)) . '.' . self::b64url(json_encode($charge));
        openssl_sign($signe, $sig, self::paire(), OPENSSL_ALGO_SHA256);

        return $signe . '.' . self::b64url($sig);
    }

    /** Joue le parcours téléphone historique : code posé en base, puis vérification. */
    private function connexionParTelephone(string $telephone, string $email)
    {
        // La table `otps` n'a pas de `updated_at` — on n'écrit que ses vraies colonnes.
        DB::table('otps')->insert([
            'phone' => $telephone,
            'code' => '+33',
            'token' => '1234',
            'created_at' => now(),
        ]);

        return $this->postJson('/api/auth/guest-signup/verify', [
            'phone' => $telephone,
            'code'  => '+33',
            'token' => '1234',
            'email' => $email,
            'first_name' => 'Vraie',
            'last_name'  => 'Propriétaire',
        ]);
    }

    // ------------------------------------------------------------- l'attaque

    /** @test */
    public function celui_qui_prouve_le_telephone_ne_doit_pas_atterrir_dans_le_compte_d_un_autre(): void
    {
        // 1. L'attaquant ouvre un compte par connexion Apple (identité bien à lui).
        $jetonAttaquant = $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->jetonApple('sub-attaquant', 'attaquant@example.test'),
        ])->assertStatus(201)->json('token');

        // 2. Il déclare le numéro de QUELQU'UN D'AUTRE. Rien ne le lui interdit : ce numéro
        //    n'appartient encore à aucun compte, et aucune preuve de possession n'est exigée.
        $this->withHeader('Authorization', 'Bearer ' . $jetonAttaquant)
            ->postJson('/api/auth/social/phone', ['phone' => self::TEL_VICTIME, 'code' => '+33'])
            ->assertStatus(200);

        $compteAttaquant = User::where('apple_sub', 'sub-attaquant')->first();
        // Prérequis du scénario : le numéro est bien enregistré sur son compte — le
        // restaurant doit pouvoir l'appeler. Ce qui compte est OÙ il est enregistré.
        $this->assertSame(self::TEL_VICTIME, $compteAttaquant->numeroJoignable(),
            'Prérequis : le numéro déclaré doit rester joignable pour la caisse.');

        // 3. La VRAIE propriétaire du numéro se connecte par le parcours historique. Elle
        //    prouve la possession du téléphone avec le code reçu.
        $reponse = $this->connexionParTelephone(self::TEL_VICTIME, 'victime@example.test');
        $reponse->assertStatus(201);

        $idConnecte = (int) $reponse->json('user.id');

        // LA QUESTION : dans QUEL compte vient-elle d'entrer ?
        $this->assertNotSame(
            (int) $compteAttaquant->id,
            $idConnecte,
            "La personne qui PROUVE le numéro est entrée dans le compte de celui qui l'avait "
            . "seulement DÉCLARÉ. Les deux partagent désormais commandes et fidélité, et "
            . "l'attaquant garde son accès par « Se connecter avec Apple »."
        );
    }

    /** @test */
    public function l_identite_sociale_non_prouvee_ne_survit_pas_a_une_preuve_de_telephone(): void
    {
        // Corollaire du test précédent : même si le compte est réutilisé, l'accès de celui
        // qui n'a PAS prouvé le numéro doit disparaître. Sinon il lui suffit de se
        // reconnecter avec Apple pour retrouver le compte de sa victime.
        $jetonAttaquant = $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->jetonApple('sub-attaquant', 'attaquant@example.test'),
        ])->assertStatus(201)->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $jetonAttaquant)
            ->postJson('/api/auth/social/phone', ['phone' => self::TEL_VICTIME, 'code' => '+33'])
            ->assertStatus(200);

        $this->connexionParTelephone(self::TEL_VICTIME, 'victime@example.test')->assertStatus(201);

        $restant = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()
            ->where('apple_sub', 'sub-attaquant')
            ->where('phone', self::TEL_VICTIME)
            ->first();

        $this->assertNull(
            $restant,
            "L'identité Apple de celui qui n'a jamais prouvé le numéro est attachée au compte "
            . "qui PORTE ce numéro comme identité : il peut y revenir quand il veut."
        );
    }

    /** @test */
    public function un_numero_declare_ne_detourne_pas_le_code_de_connexion(): void
    {
        // L'IMPACT RÉEL du squattage, et le plus insidieux. La garde anti-confusion de canal
        // (GuestSignupController::emailOtp) n'envoie le code qu'à l'adresse du compte qui
        // PORTE le numéro — protection légitime contre celui qui connaîtrait un numéro.
        // Mais si un numéro simplement DÉCLARÉ suffisait à « porter » le numéro, cette
        // protection se retournait : le squatteur recevait les codes de sa victime, et
        // celle-ci ne pouvait plus jamais créer de compte avec son propre numéro.
        \Illuminate\Support\Facades\Mail::fake();

        $jetonAttaquant = $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->jetonApple('sub-attaquant', 'attaquant@example.test'),
        ])->assertStatus(201)->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $jetonAttaquant)
            ->postJson('/api/auth/social/phone', ['phone' => self::TEL_VICTIME, 'code' => '+33'])
            ->assertStatus(200);

        // La victime demande son code, avec SA propre adresse.
        $this->postJson('/api/auth/guest-signup/email-otp', [
            'phone' => self::TEL_VICTIME,
            'code'  => '+33',
            'email' => 'victime@example.test',
            'first_name' => 'Vraie',
            'last_name'  => 'Propriétaire',
        ])->assertStatus(200);

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\SignupOtpMail::class,
            function ($mail) {
                return $mail->hasTo('victime@example.test');
            }
        );

        \Illuminate\Support\Facades\Mail::assertNotSent(
            \App\Mail\SignupOtpMail::class,
            function ($mail) {
                return $mail->hasTo('attaquant@example.test');
            }
        );
    }

    /** @test */
    public function un_numero_reellement_prouve_reste_intouchable(): void
    {
        // La contrepartie : la protection ne doit pas casser le cas normal. Un client qui a
        // ouvert son compte par téléphone garde son compte, et personne ne le lui prend.
        $legitime = User::factory()->create([
            'branch_id' => 0, 'phone' => self::TEL_VICTIME,
            'email' => 'legitime@example.test', 'email_verified_at' => now()->timestamp,
            'status' => Status::ACTIVE, 'is_guest' => Ask::YES,
        ]);
        $legitime->assignRole('Customer');

        $jetonAttaquant = $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->jetonApple('sub-attaquant', 'attaquant@example.test'),
        ])->assertStatus(201)->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $jetonAttaquant)
            ->postJson('/api/auth/social/phone', ['phone' => self::TEL_VICTIME, 'code' => '+33'])
            ->assertStatus(422)
            ->assertJson(['code' => 'PHONE_EXISTS']);

        $legitime->refresh();
        $this->assertNull($legitime->apple_sub, 'Le compte légitime ne doit pas avoir été rattaché.');
    }
}
