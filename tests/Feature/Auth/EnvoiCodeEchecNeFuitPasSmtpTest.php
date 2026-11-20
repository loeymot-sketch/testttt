<?php

namespace Tests\Feature\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Models\User;
use App\Services\OtpManagerService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M1-003] Quand l'envoi du code échoue, l'API renvoyait au CLIENT anonyme le
 * message brut du transport : « Connection could not be established with host
 * "smtp.example.com:587" … », et, sur un échec d'authentification SMTP, Symfony écrit
 * « Failed to authenticate on SMTP server with username "…" » — l'identifiant du compte d'envoi.
 *
 * Contrat : message générique au client, détail au journal seulement. Les messages MÉTIER
 * (code invalide, code expiré) restent dits tels quels.
 */
class EnvoiCodeEchecNeFuitPasSmtpTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';
    private const SECRET_SMTP = 'Failed to authenticate on SMTP server with username "brevo-compte-secret@smtp-relay.example" using 2 possible authenticators.';

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        DB::table('settings')->insert([
            ['key' => 'site_guest_login',        'payload' => json_encode(Activity::ENABLE),  'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_default_branch',     'payload' => json_encode(1),                 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'otp_expire_time',         'payload' => json_encode(5),                 'group' => 'otp',  'created_at' => now(), 'updated_at' => now()],
        ]);
        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function smtpEnPanne(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException(self::SECRET_SMTP));
    }

    private function assertGeneriqueSansFuite($res): void
    {
        $res->assertStatus(422);
        $corps = $res->getContent();
        $this->assertStringNotContainsStringIgnoringCase('smtp', $corps, 'aucun détail SMTP au client');
        $this->assertStringNotContainsString('brevo-compte-secret', $corps, 'jamais l\'identifiant SMTP');
        $this->assertStringNotContainsString('authenticate', $corps);
        $this->assertSame(false, $res->json('status'));
        $this->assertNotEmpty($res->json('message'), 'un message lisible reste dit au client');
    }

    /** @test */
    public function email_login_compte_connu_ne_renvoie_pas_le_message_smtp(): void
    {
        $u = User::create([
            'name' => 'Sarah Dupont', 'username' => 'sarah-'.uniqid(), 'phone' => '0699555002',
            'country_code' => '+33', 'email' => 'sarah.dupont@example.com', 'branch_id' => 0,
            'is_guest' => Ask::YES, 'status' => \App\Enums\Status::ACTIVE, 'password' => bcrypt('x'),
        ]);
        $u->assignRole(\App\Enums\Role::CUSTOMER);
        $this->smtpEnPanne();
        Log::spy();

        $res = $this->postJson('/api/auth/guest-signup/email-login', ['email' => 'sarah.dupont@example.com']);

        $this->assertGeneriqueSansFuite($res);
        // Le détail n'est pas perdu : il part au journal.
        Log::shouldHaveReceived('warning')->withArgs(function ($msg, $ctx = []) {
            return str_contains(json_encode($ctx), 'brevo-compte-secret');
        })->atLeast()->once();
    }

    /** @test */
    public function email_login_inscription_ne_renvoie_pas_le_message_smtp(): void
    {
        $this->smtpEnPanne();
        $res = $this->postJson('/api/auth/guest-signup/email-login', [
            'email' => 'nouveau@example.com', 'first_name' => 'Nina', 'phone' => '0699555077',
        ]);
        $this->assertGeneriqueSansFuite($res);
    }

    /** @test */
    public function email_otp_ne_renvoie_pas_le_message_smtp(): void
    {
        $this->smtpEnPanne();
        $res = $this->postJson('/api/auth/guest-signup/email-otp', [
            'phone' => '0699555088', 'email' => 'autre@example.com', 'first_name' => 'Léo', 'last_name' => 'Martin',
        ]);
        $this->assertGeneriqueSansFuite($res);
    }

    /** @test */
    public function otp_par_telephone_ne_renvoie_pas_l_erreur_brute_du_fournisseur(): void
    {
        // OtpManagerService enveloppe toute erreur dans une Exception simple portant le message brut.
        $this->mock(OtpManagerService::class, function ($m) {
            $m->shouldReceive('otp')->andThrow(new Exception(self::SECRET_SMTP, 422));
        });
        $res = $this->postJson('/api/auth/guest-signup/otp', ['phone' => '0699555099', 'code' => '+33']);
        $this->assertGeneriqueSansFuite($res);
    }

    /** @test */
    public function verify_garde_le_message_metier_code_invalide(): void
    {
        $res = $this->postJson('/api/auth/guest-signup/verify', [
            'phone' => '0699555011', 'code' => '+33', 'token' => '1234',
        ]);
        $res->assertStatus(422);
        $this->assertSame(trans('all.message.code_is_invalid'), $res->json('message'));
    }
}
