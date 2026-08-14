<?php

namespace Tests\Feature\Pos;

use App\Mail\CustomerWelcomeMail;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * LE MAIL DE BIENVENUE — « le client va recevoir le mail et enregistrer ses données »
 * [propriétaire 2026-08-14].
 *
 * Ne doit JAMAIS faire tomber la création du compte, même si l'envoi échoue — la garde est
 * vérifiée en s'assurant que la réponse HTTP reste 201 quel que soit l'état du mailer.
 */
class PosCustomerCreateWelcomeMailTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/pos-loyalty/customers';

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('loyalty.qr.secret', 'test-qr-secret-'.str_repeat('f', 40));
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 1000,
        ]);

        $branche = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $branche->id, 'phone' => '0100000004']);
        $this->caissier->assignRole('POS Operator');

        RateLimiter::clear('pos-loyalty-lookup');
    }

    private function creer(array $corps)
    {
        return $this->actingAs($this->caissier, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'test-'.bin2hex(random_bytes(8)))
            ->postJson(self::URL, $corps);
    }

    public function test_creation_avec_email_envoie_le_mail_de_bienvenue(): void
    {
        Mail::fake();

        $this->creer(['phone' => '06 22 33 44 55', 'name' => 'Julie', 'email' => 'julie@example.test'])
            ->assertStatus(201);

        Mail::assertSent(CustomerWelcomeMail::class, function (CustomerWelcomeMail $mail) {
            return $mail->hasTo('julie@example.test');
        });
    }

    public function test_creation_sans_email_n_envoie_rien(): void
    {
        Mail::fake();

        $this->creer(['phone' => '06 22 33 44 66'])->assertStatus(201);

        Mail::assertNothingSent();
    }

    /** Le compte existe déjà SANS e-mail : on complète, et on prévient — un seul envoi, jamais deux. */
    public function test_completer_un_compte_existant_avec_email_envoie_une_seule_fois(): void
    {
        Mail::fake();

        $this->creer(['phone' => '06 22 33 44 77'])->assertStatus(201);
        Mail::assertNothingSent();

        $this->creer(['phone' => '06 22 33 44 77', 'email' => 'retard@example.test'])->assertStatus(200);
        Mail::assertSent(CustomerWelcomeMail::class, 1);
    }
}
